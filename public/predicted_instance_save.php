<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once 'predicted_instance_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: predicted.php');
    exit;
}

$defaults = predicted_instance_defaults();
$form = array_merge($defaults, $_POST);
$errors = [];

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : 0;
$scheduledDate = trim((string)($_POST['scheduled_date'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$fromAccountId = isset($_POST['from_account_id']) && $_POST['from_account_id'] !== '' ? (int)$_POST['from_account_id'] : 0;
$toAccountId = isset($_POST['to_account_id']) && $_POST['to_account_id'] !== '' ? (int)$_POST['to_account_id'] : null;
$categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;
$amountRaw = trim((string)($_POST['amount'] ?? ''));
$futureDays = isset($_POST['future_days']) ? (int)$_POST['future_days'] : 180;
if (!in_array($futureDays, [90, 180, 365], true)) {
    $futureDays = 180;
}

if ($scheduledDate === '') {
    $errors[] = 'Scheduled date is required.';
} else {
    try {
        new DateTimeImmutable($scheduledDate);
    } catch (Throwable $e) {
        $errors[] = 'Scheduled date is invalid.';
    }
}

if ($description === '') {
    $errors[] = 'Description is required.';
}

if ($fromAccountId <= 0) {
    $errors[] = 'From account is required.';
}

if ($categoryId <= 0) {
    $errors[] = 'Category is required.';
}

if ($amountRaw === '' || !is_numeric($amountRaw)) {
    $errors[] = 'Amount must be a valid number.';
} else {
    $amount = number_format((float)$amountRaw, 2, '.', '');
}

$catType = null;
if ($categoryId > 0) {
    $stmt = $pdo->prepare("SELECT type FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $catType = $stmt->fetchColumn();

    if ($catType === false) {
        $errors[] = 'Selected category does not exist.';
        $catType = null;
    }
}

if ($fromAccountId > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = ? AND active = 1");
    $stmt->execute([$fromAccountId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $errors[] = 'Selected from account does not exist or is inactive.';
    }
}

if ($toAccountId !== null) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = ? AND active = 1");
    $stmt->execute([$toAccountId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $errors[] = 'Selected to account does not exist or is inactive.';
    }
}

if ($catType === 'income') {
    if ((float)$amount <= 0) {
        $errors[] = 'Income items must use a positive amount.';
    }
    $toAccountId = null;
} elseif ($catType === 'expense') {
    if ((float)$amount >= 0) {
        $errors[] = 'Expense items must use a negative amount.';
    }
    $toAccountId = null;
} elseif ($catType === 'transfer') {
    if ($toAccountId === null || $toAccountId <= 0) {
        $errors[] = 'Transfer items require a To Account.';
    }
    if ($toAccountId !== null && $toAccountId === $fromAccountId) {
        $errors[] = 'Transfer items cannot use the same account for both From and To.';
    }
    if ((float)$amount <= 0) {
        $errors[] = 'Transfer items must use a positive amount.';
    }
} else {
    $errors[] = 'Category type must be income, expense, or transfer.';
}

$form['id'] = $id ?: '';
$form['scheduled_date'] = $scheduledDate;
$form['description'] = $description;
$form['from_account_id'] = $fromAccountId ?: '';
$form['to_account_id'] = $toAccountId ?: '';
$form['category_id'] = $categoryId ?: '';
$form['amount'] = $amountRaw;

if (!empty($errors)) {
    $_SESSION['predicted_instance_errors'] = $errors;
    $_SESSION['predicted_instance_form'] = $form;
    $target = 'predicted_instance_edit.php?future_days=' . $futureDays . ($id > 0 ? '&id=' . $id : '');
    header('Location: ' . $target);
    exit;
}

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE predicted_instances
            SET scheduled_date = ?,
                amount = ?,
                from_account_id = ?,
                to_account_id = ?,
                category_id = ?,
                description = ?,
                resolution_status = 'open',
                resolved_at = NULL,
                resolution_note = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND predicted_transaction_id IS NULL
              AND COALESCE(fulfilled, 0) = 0
        ");
        $stmt->execute([
            $scheduledDate,
            $amount,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $description,
            $id,
        ]);
        $_SESSION['prediction_action_flash'] = '✅ One-off planned item updated.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO predicted_instances (
                predicted_transaction_id,
                scheduled_date,
                amount,
                from_account_id,
                to_account_id,
                category_id,
                description,
                confirmed,
                resolution_status
            ) VALUES (
                NULL, ?, ?, ?, ?, ?, ?, 0, 'open'
            )
        ");
        $stmt->execute([
            $scheduledDate,
            $amount,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $description,
        ]);
        $_SESSION['prediction_action_flash'] = '✅ One-off planned item created.';
    }
} catch (Throwable $e) {
    $_SESSION['predicted_instance_errors'] = ['Save failed: ' . $e->getMessage()];
    $_SESSION['predicted_instance_form'] = $form;
    $target = 'predicted_instance_edit.php?future_days=' . $futureDays . ($id > 0 ? '&id=' . $id : '');
    header('Location: ' . $target);
    exit;
}

header('Location: predicted.php?future_days=' . $futureDays);
exit;
