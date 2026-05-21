<?php
require_once '../config/db.php';
auth_session_start();
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
$budgetTreatment = trim((string)($_POST['budget_treatment'] ?? 'additional'));
$budgetMonthRaw = trim((string)($_POST['budget_month'] ?? ''));
$budgetAmountRaw = trim((string)($_POST['budget_amount'] ?? ''));
$futureDays = isset($_POST['future_days']) ? (int)$_POST['future_days'] : 180;
if (!in_array($futureDays, [90, 180, 365], true)) {
    $futureDays = 180;
}

if (!in_array($budgetTreatment, ['additional', 'budget_backed'], true)) {
    $budgetTreatment = 'additional';
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

$amount = null;
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

$budgetMonthStart = null;
$budgetAmount = null;

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

    // Transfers do not participate in solvency budget offset logic.
    $budgetTreatment = 'additional';
    $budgetMonthRaw = '';
    $budgetAmountRaw = '';
} else {
    $errors[] = 'Category type must be income, expense, or transfer.';
}

if (in_array($catType, ['income', 'expense'], true) && $budgetTreatment === 'budget_backed') {
    if ($budgetMonthRaw === '' && $scheduledDate !== '') {
        $budgetMonthRaw = predicted_instance_financial_month_from_date($scheduledDate);
    }

    $budgetMonthStart = predicted_instance_month_input_to_start_date($budgetMonthRaw);
    if ($budgetMonthStart === null) {
        $errors[] = 'Budget-backed items require a valid financial month to offset.';
    }

    if ($budgetAmountRaw !== '') {
        if (!is_numeric($budgetAmountRaw) || (float)$budgetAmountRaw <= 0) {
            $errors[] = 'Budget-backed amount must be a positive number.';
        } else {
            $budgetAmount = number_format((float)$budgetAmountRaw, 2, '.', '');
        }
    } elseif ($budgetMonthStart !== null && $categoryId > 0) {
        $guessedAmount = predicted_instance_guess_budget_amount($pdo, $categoryId, $budgetMonthStart);
        if ($guessedAmount !== null && $guessedAmount > 0) {
            $budgetAmount = number_format((float)$guessedAmount, 2, '.', '');
            $budgetAmountRaw = $budgetAmount;
        } else {
            $errors[] = 'No exact budget row could be inferred for the selected category/month. Enter the budget amount to offset.';
        }
    }
}

$form['id'] = $id ?: '';
$form['scheduled_date'] = $scheduledDate;
$form['description'] = $description;
$form['from_account_id'] = $fromAccountId ?: '';
$form['to_account_id'] = $toAccountId ?: '';
$form['category_id'] = $categoryId ?: '';
$form['amount'] = $amountRaw;
$form['budget_treatment'] = $budgetTreatment;
$form['budget_month'] = $budgetMonthRaw;
$form['budget_amount'] = $budgetAmountRaw;

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
                budget_treatment = ?,
                budget_month_start = ?,
                budget_amount = ?,
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
            $budgetTreatment,
            $budgetMonthStart,
            $budgetAmount,
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
                budget_treatment,
                budget_month_start,
                budget_amount,
                confirmed,
                resolution_status
            ) VALUES (
                NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'open'
            )
        ");
        $stmt->execute([
            $scheduledDate,
            $amount,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $description,
            $budgetTreatment,
            $budgetMonthStart,
            $budgetAmount,
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
