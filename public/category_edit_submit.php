<?php
require_once '../config/db.php';

$id = $_POST['id'] ?? null;
$action = $_POST['action'] ?? 'update';

if (!$id) {
    die('Missing category ID');
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
$type = $_POST['type'];
$fixedness = $_POST['fixedness'] ?? null;
$priority = $_POST['priority'] ?? null;
$parent_id = $_POST['parent_id'] !== '' ? $_POST['parent_id'] : null;

// Determine new name
if ($type === 'transfer') {
    $direction = $_POST['direction'];
    $linked_account_id = $_POST['linked_account_id'];
    
    // Get account name
    $stmt = $pdo->prepare("SELECT name FROM accounts WHERE id = ?");
    $stmt->execute([$linked_account_id]);
    $account_name = $stmt->fetchColumn();

    $name = "TRANSFER $direction : $account_name";

    $update = $pdo->prepare("
        UPDATE categories 
        SET name = ?, linked_account_id = ?, parent_id = 275 
        WHERE id = ?
    ");
    $update->execute([$name, $linked_account_id, $id]);

} elseif ($parent_id) {
    // It's a subcategory, use parent name + suffix
    $suffix = $_POST['suffix'] ?? $_POST['name'];

    // Get parent name
    $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$parent_id]);
    $parent_name = $stmt->fetchColumn();

    $name = "$parent_name : $suffix";

    $update = $pdo->prepare("
        UPDATE categories 
        SET name = ?, parent_id = ?, fixedness = NULL, priority = NULL 
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

    $update = $pdo->prepare("
        UPDATE categories 
        SET name = ?, parent_id = NULL, fixedness = ?, priority = ? 
        WHERE id = ?
    ");
    $update->execute([$name, $fixedness, $priority, $id]);
}

header("Location: categories.php?success=updated");
exit;
?>
