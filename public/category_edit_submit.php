<?php
require_once '../config/db.php';

function ces_post_enum(string $key, array $allowed, string $default): string
{
    $value = (string)($_POST[$key] ?? $default);
    return in_array($value, $allowed, true) ? $value : $default;
}

$id = $_POST['id'] ?? null;
$action = $_POST['action'] ?? 'update';

if (!$id) {
    die('Missing category ID');
}

$typeStmt = $pdo->prepare("SELECT type FROM categories WHERE id = ?");
$typeStmt->execute([(int)$id]);
$existingType = $typeStmt->fetchColumn();

if ($existingType === false) {
    die('Category not found');
}

if ($existingType === 'transfer') {
    die('Transfer categories are deprecated and read-only. They are retained for legacy audit only.');
}

// Handle deletion
if ($action === 'delete') {
    $replacementId = $_POST['replacement_id'] ?? null;
    if (!$replacementId) {
        die('Replacement category is required for deletion');
    }

    $tables = [
        'transactions' => 'category_id',
        'transaction_splits' => 'category_id',
        'predicted_transactions' => 'category_id',
        'predicted_instances' => 'category_id',
    ];

    foreach ($tables as $table => $col) {
        $stmt = $pdo->prepare("UPDATE $table SET $col = ? WHERE $col = ?");
        $stmt->execute([$replacementId, $id]);
    }

    $deleteStmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $deleteStmt->execute([$id]);

    header('Location: categories.php?success=deleted');
    exit;
}

// Otherwise, handle updates
$type = (string)($_POST['type'] ?? '');
$fixedness = ($_POST['fixedness'] ?? '') !== '' ? (string)$_POST['fixedness'] : null;
$priority = ($_POST['priority'] ?? '') !== '' ? (string)$_POST['priority'] : null;
$parent_id = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;

$watcherBudgetMode = $type === 'expense'
    ? ces_post_enum('watcher_budget_mode', ['normal', 'reimbursable', 'ignore'], 'normal')
    : 'normal';

$watcherTimingMode = $type === 'expense'
    ? ces_post_enum('watcher_timing_mode', ['operational', 'flexible', 'ignore'], 'operational')
    : 'operational';

if ($watcherBudgetMode !== 'normal') {
    $watcherTimingMode = 'operational';
}

// Determine new name
if ($type === 'transfer') {
    $direction = (string)($_POST['direction'] ?? 'TO');
    $linked_account_id = (int)($_POST['linked_account_id'] ?? 0);

    // Get account name
    $stmt = $pdo->prepare("SELECT name FROM accounts WHERE id = ?");
    $stmt->execute([$linked_account_id]);
    $account_name = $stmt->fetchColumn();

    if (!$account_name) {
        die('Linked account not found');
    }

    $name = "TRANSFER $direction : $account_name";

    $update = $pdo->prepare("
        UPDATE categories
        SET name = ?,
            linked_account_id = ?,
            parent_id = 275,
            watcher_budget_mode = 'normal',
            watcher_timing_mode = 'operational'
        WHERE id = ?
    ");
    $update->execute([$name, $linked_account_id, $id]);

} elseif ($parent_id) {
    // It's a subcategory, use parent name + suffix
    $suffix = $_POST['suffix'] ?? $_POST['name'] ?? '';

    // Get parent name
    $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$parent_id]);
    $parent_name = $stmt->fetchColumn();

    if (!$parent_name) {
        die('Parent category not found');
    }

    $name = "$parent_name : $suffix";

    $update = $pdo->prepare("
        UPDATE categories
        SET name = ?,
            parent_id = ?,
            fixedness = NULL,
            priority = NULL,
            watcher_budget_mode = 'normal',
            watcher_timing_mode = 'operational'
        WHERE id = ?
    ");
    $update->execute([$name, $parent_id, $id]);

} else {
    // Promoted to parent or is an existing parent
    $name = $_POST['name'] ?? null;
    if (!$name) {
        // For promoted subcategories, rebuild name from suffix
        $suffix = $_POST['suffix'] ?? null;
        if (!$suffix) {
            die("Category name or suffix is required");
        }
        $name = $suffix;
    }

    if ($type !== 'expense') {
        $watcherBudgetMode = 'normal';
        $watcherTimingMode = 'operational';
    }

    $update = $pdo->prepare("
        UPDATE categories
        SET name = ?,
            parent_id = NULL,
            fixedness = ?,
            priority = ?,
            watcher_budget_mode = ?,
            watcher_timing_mode = ?
        WHERE id = ?
    ");
    $update->execute([$name, $fixedness, $priority, $watcherBudgetMode, $watcherTimingMode, $id]);
}

header("Location: categories.php?success=updated");
exit;
?>
