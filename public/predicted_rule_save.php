<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../scripts/run_predict_instances.php';
require_once 'prediction_rule_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: predicted.php');
    exit;
}

$defaults = prediction_rule_defaults();
$form = array_merge($defaults, $_POST);
$errors = [];

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : 0;
$description = trim((string)($_POST['description'] ?? ''));
$fromAccountId = isset($_POST['from_account_id']) && $_POST['from_account_id'] !== '' ? (int)$_POST['from_account_id'] : 0;
$toAccountId = isset($_POST['to_account_id']) && $_POST['to_account_id'] !== '' ? (int)$_POST['to_account_id'] : null;
$categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;
$amountRaw = trim((string)($_POST['amount'] ?? ''));
$variable = isset($_POST['variable']) ? 1 : 0;
$averageOverLast = isset($_POST['average_over_last']) && $_POST['average_over_last'] !== '' ? (int)$_POST['average_over_last'] : null;
$active = isset($_POST['active']) ? 1 : 0;
$frequency = trim((string)($_POST['frequency'] ?? 'monthly'));
$repeatInterval = isset($_POST['repeat_interval']) && $_POST['repeat_interval'] !== '' ? (int)$_POST['repeat_interval'] : 1;
$monthlyAnchorType = trim((string)($_POST['monthly_anchor_type'] ?? 'day_of_month'));
$dayOfMonth = isset($_POST['day_of_month']) && $_POST['day_of_month'] !== '' ? (int)$_POST['day_of_month'] : null;
$weekday = isset($_POST['weekday']) && $_POST['weekday'] !== '' ? (int)$_POST['weekday'] : null;
$nthWeekday = isset($_POST['nth_weekday']) && $_POST['nth_weekday'] !== '' ? (int)$_POST['nth_weekday'] : null;
$adjustForWeekend = trim((string)($_POST['adjust_for_weekend'] ?? 'none'));
$isBusinessDay = isset($_POST['is_business_day']) ? 1 : 0;

$validFrequencies = array_keys(prediction_rule_frequency_options());
$validMonthlyAnchors = array_keys(prediction_rule_monthly_anchor_options());
$validAdjustments = array_keys(prediction_rule_adjust_options());
$validWeekdays = array_keys(prediction_rule_weekday_options());

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
    $errors[] = 'Fallback amount must be a valid number.';
} else {
    $amount = number_format((float)$amountRaw, 2, '.', '');
}

if ($variable && ($averageOverLast === null || $averageOverLast < 1)) {
    $errors[] = 'Average Over Last must be at least 1 when Variable Amount is enabled.';
}

if (!in_array($frequency, $validFrequencies, true)) {
    $errors[] = 'Invalid frequency selected.';
}

if ($repeatInterval < 1) {
    $errors[] = 'Repeat interval must be at least 1.';
}

if (!in_array($adjustForWeekend, $validAdjustments, true)) {
    $errors[] = 'Invalid weekend adjustment selected.';
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

if ($catType === 'transfer') {
    if ($toAccountId === null || $toAccountId <= 0) {
        $errors[] = 'Transfer rules require a To Account.';
    }
    if ($toAccountId !== null && $toAccountId === $fromAccountId) {
        $errors[] = 'Transfer rules cannot use the same account for both From and To.';
    }
} else {
    $toAccountId = null;
}

if ($frequency === 'monthly') {
    if (!in_array($monthlyAnchorType, $validMonthlyAnchors, true)) {
        $errors[] = 'Invalid monthly anchor selected.';
    }

    $anchorType = $monthlyAnchorType;

    if ($monthlyAnchorType === 'day_of_month') {
        if ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 31) {
            $errors[] = 'Day of month must be between 1 and 31.';
        }
        $weekday = null;
        $nthWeekday = null;
        $isBusinessDay = 0;
    } elseif ($monthlyAnchorType === 'nth_weekday') {
        if ($weekday === null || !in_array($weekday, $validWeekdays, true)) {
            $errors[] = 'Weekday is required for nth weekday rules.';
        }
        if ($nthWeekday === null || $nthWeekday < 1 || $nthWeekday > 5) {
            $errors[] = 'Nth weekday must be between 1 and 5.';
        }
        $dayOfMonth = null;
        $isBusinessDay = 0;
    } elseif ($monthlyAnchorType === 'last_business_day') {
        $dayOfMonth = null;
        $weekday = null;
        $nthWeekday = null;
    }
} elseif ($frequency === 'weekly' || $frequency === 'fortnightly') {
    $anchorType = 'weekly';

    if ($weekday === null || !in_array($weekday, $validWeekdays, true)) {
        $errors[] = 'Weekday is required for weekly and fortnightly rules.';
    }

    $dayOfMonth = null;
    $nthWeekday = null;
    $isBusinessDay = 0;
    $monthlyAnchorType = 'day_of_month';
} else { // custom
    $anchorType = 'weekly';
    $dayOfMonth = null;
    $weekday = null;
    $nthWeekday = null;
    $isBusinessDay = 0;
    $monthlyAnchorType = 'day_of_month';
}

$form['id'] = $id ?: '';
$form['description'] = $description;
$form['from_account_id'] = $fromAccountId ?: '';
$form['to_account_id'] = $toAccountId ?: '';
$form['category_id'] = $categoryId ?: '';
$form['amount'] = $amountRaw;
$form['variable'] = $variable;
$form['average_over_last'] = $averageOverLast ?? '';
$form['active'] = $active;
$form['frequency'] = $frequency;
$form['repeat_interval'] = $repeatInterval;
$form['monthly_anchor_type'] = $monthlyAnchorType;
$form['day_of_month'] = $dayOfMonth ?? '';
$form['weekday'] = $weekday ?? '';
$form['nth_weekday'] = $nthWeekday ?? '';
$form['adjust_for_weekend'] = $adjustForWeekend;
$form['is_business_day'] = $isBusinessDay;

if (!empty($errors)) {
    $_SESSION['prediction_rule_errors'] = $errors;
    $_SESSION['prediction_rule_form'] = $form;
    $target = 'predicted_rule_edit.php' . ($id > 0 ? ('?id=' . $id) : '');
    header('Location: ' . $target);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE predicted_transactions
            SET description = ?,
                from_account_id = ?,
                to_account_id = ?,
                category_id = ?,
                amount = ?,
                variable = ?,
                average_over_last = ?,
                day_of_month = ?,
                adjust_for_weekend = ?,
                active = ?,
                anchor_type = ?,
                frequency = ?,
                repeat_interval = ?,
                weekday = ?,
                nth_weekday = ?,
                is_business_day = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $description,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $amount,
            $variable,
            $variable ? $averageOverLast : null,
            $dayOfMonth,
            $adjustForWeekend,
            $active,
            $anchorType,
            $frequency,
            $repeatInterval,
            $weekday,
            $nthWeekday,
            $isBusinessDay,
            $id,
        ]);
        $ruleId = $id;
        $actionLabel = 'updated';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO predicted_transactions (
                description, from_account_id, to_account_id, category_id, amount,
                variable, average_over_last, day_of_month, adjust_for_weekend, active,
                anchor_type, frequency, repeat_interval, weekday, nth_weekday, is_business_day
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $description,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $amount,
            $variable,
            $variable ? $averageOverLast : null,
            $dayOfMonth,
            $adjustForWeekend,
            $active,
            $anchorType,
            $frequency,
            $repeatInterval,
            $weekday,
            $nthWeekday,
            $isBusinessDay,
        ]);
        $ruleId = (int)$pdo->lastInsertId();
        $actionLabel = 'created';
    }

    $pruned = prediction_rule_prune_future_open_instances($pdo, $ruleId);

    $pdo->commit();

    $job = run_predict_instances_job(true, 'prediction_rule_save');
    $jobMessage = $job['message'] ?? 'Reforecast attempted.';

    $_SESSION['prediction_rule_flash'] = "✅ Prediction rule {$actionLabel}. Refreshed {$pruned} future open instance(s). {$jobMessage}";
    header('Location: predicted.php');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['prediction_rule_errors'] = ['Save failed: ' . $e->getMessage()];
    $_SESSION['prediction_rule_form'] = $form;
    $target = 'predicted_rule_edit.php' . ($id > 0 ? ('?id=' . $id) : '');
    header('Location: ' . $target);
    exit;
}
