<?php
require_once '../config/db.php';
auth_session_start();
require_once '../scripts/run_predict_instances.php';
require_once 'prediction_rule_helpers.php';
require_once '../scripts/lib/prediction_transaction_links.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: predicted.php');
    exit;
}

$defaults = prediction_rule_defaults();
$form = array_merge($defaults, $_POST);
$errors = [];

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : 0;
$sourceTransactionId = isset($_POST['source_transaction_id']) && $_POST['source_transaction_id'] !== ''
    ? (int)$_POST['source_transaction_id']
    : 0;
$returnUrl = ptl_safe_return_url($_POST['redirect'] ?? null, 'predicted.php');
$description = trim((string)($_POST['description'] ?? ''));
$fromAccountId = isset($_POST['from_account_id']) && $_POST['from_account_id'] !== '' ? (int)$_POST['from_account_id'] : 0;
$toAccountId = isset($_POST['to_account_id']) && $_POST['to_account_id'] !== '' ? (int)$_POST['to_account_id'] : null;
$predictionType = trim((string)($_POST['prediction_type'] ?? 'expense'));
$categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;
$amountRaw = trim((string)($_POST['amount'] ?? ''));
$variable = isset($_POST['variable']) ? 1 : 0;
$averageOverLast = isset($_POST['average_over_last']) && $_POST['average_over_last'] !== '' ? (int)$_POST['average_over_last'] : null;
$active = isset($_POST['active']) ? 1 : 0;
$recurrenceUnit = trim((string)($_POST['recurrence_unit'] ?? 'month'));
$recurrenceInterval = isset($_POST['recurrence_interval']) && $_POST['recurrence_interval'] !== '' ? (int)$_POST['recurrence_interval'] : 1;
$anchorDate = trim((string)($_POST['anchor_date'] ?? ''));
$schedulePattern = trim((string)($_POST['schedule_pattern'] ?? 'anchor_date'));
$dayOfMonth = isset($_POST['day_of_month']) && $_POST['day_of_month'] !== '' ? (int)$_POST['day_of_month'] : null;
$weekday = isset($_POST['weekday']) && $_POST['weekday'] !== '' ? (int)$_POST['weekday'] : null;
$nthWeekday = isset($_POST['nth_weekday']) && $_POST['nth_weekday'] !== '' ? (int)$_POST['nth_weekday'] : null;
$businessDayAdjustment = trim((string)($_POST['business_day_adjustment'] ?? 'none'));

$validPredictionTypes = array_keys(prediction_rule_type_options());
$validRecurrenceUnits = array_keys(prediction_rule_recurrence_unit_options());
$validSchedulePatterns = array_keys(prediction_rule_schedule_pattern_options());
$validAdjustments = array_keys(prediction_rule_business_day_adjustment_options());
$validWeekdays = array_keys(prediction_rule_weekday_options());

if ($description === '') {
    $errors[] = 'Description is required.';
}

if ($fromAccountId <= 0) {
    $errors[] = 'From account is required.';
}

if (!in_array($predictionType, $validPredictionTypes, true)) {
    $errors[] = 'Invalid rule type selected.';
}

if ($predictionType !== 'transfer' && $categoryId <= 0) {
    $errors[] = 'Category is required for income and expense rules.';
}

if ($amountRaw === '' || !is_numeric($amountRaw)) {
    $errors[] = 'Fallback amount must be a valid number.';
    $amount = '0.00';
} else {
    $amount = number_format((float)$amountRaw, 2, '.', '');
}

if ($variable && ($averageOverLast === null || $averageOverLast < 1)) {
    $errors[] = 'Average Over Last must be at least 1 when Variable Amount is enabled.';
}

if (!in_array($recurrenceUnit, $validRecurrenceUnits, true)) {
    $errors[] = 'Invalid recurrence unit selected.';
}

if ($recurrenceInterval < 1) {
    $errors[] = 'Repeat Every must be at least 1.';
}

$anchorDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate);
$anchorDateErrors = DateTimeImmutable::getLastErrors();
if (
    $anchorDate === ''
    || !$anchorDateObj
    || ($anchorDateErrors !== false && (($anchorDateErrors['warning_count'] ?? 0) > 0 || ($anchorDateErrors['error_count'] ?? 0) > 0))
    || $anchorDateObj->format('Y-m-d') !== $anchorDate
) {
    $errors[] = 'Anchor Date must be a valid date.';
}

if (!in_array($businessDayAdjustment, $validAdjustments, true)) {
    $errors[] = 'Invalid business-day adjustment selected.';
}

$sourceContext = null;
if ($sourceTransactionId > 0) {
    if ($id > 0) {
        $errors[] = 'A source transaction can only be used when creating a new prediction rule.';
    } else {
        $sourceContext = ptl_load_transaction_context($pdo, $sourceTransactionId);
        if (!$sourceContext) {
            $errors[] = 'Source transaction not found.';
        } else {
            [$canCreateFromSource, $sourceReason] = ptl_can_create_rule_from_transaction($pdo, $sourceContext);
            if (!$canCreateFromSource) {
                $errors[] = $sourceReason;
            }
        }
    }
}

$catType = null;
if ($categoryId > 0) {
    $stmt = $pdo->prepare("SELECT type FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $catType = $stmt->fetchColumn();

    if ($catType === false) {
        $errors[] = 'Selected category does not exist.';
        $catType = null;
    } elseif ($predictionType !== 'transfer' && $catType !== $predictionType) {
        $errors[] = 'Selected category type does not match the selected rule type.';
    } elseif ($predictionType === 'transfer') {
        $errors[] = 'Transfer rules should not be assigned a category manually.';
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

if ($predictionType === 'transfer') {
    if ($toAccountId === null || $toAccountId <= 0) {
        $errors[] = 'Transfer rules require a To Account.';
    }
    if ($toAccountId !== null && $toAccountId === $fromAccountId) {
        $errors[] = 'Transfer rules cannot use the same account for both From and To.';
    }

    $categoryId = null;
} else {
    $toAccountId = null;
}

if ($recurrenceUnit === 'month') {
    if (!in_array($schedulePattern, $validSchedulePatterns, true)) {
        $errors[] = 'Invalid monthly schedule pattern selected.';
    }

    if ($schedulePattern === 'day_of_month') {
        if ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 31) {
            $errors[] = 'Day of month must be between 1 and 31.';
        }
        $weekday = null;
        $nthWeekday = null;
    } elseif ($schedulePattern === 'nth_weekday') {
        if ($weekday === null || !in_array($weekday, $validWeekdays, true)) {
            $errors[] = 'Weekday is required for nth weekday rules.';
        }
        if ($nthWeekday === null || $nthWeekday < 1 || $nthWeekday > 5) {
            $errors[] = 'Nth weekday must be between 1 and 5.';
        }
        $dayOfMonth = null;
    } elseif ($schedulePattern === 'month_end' || $schedulePattern === 'anchor_date') {
        $dayOfMonth = null;
        $weekday = null;
        $nthWeekday = null;
    }
} else {
    $schedulePattern = 'anchor_date';
    $dayOfMonth = null;
    $weekday = null;
    $nthWeekday = null;
}

$form['id'] = $id ?: '';
$form['description'] = $description;
$form['from_account_id'] = $fromAccountId ?: '';
$form['to_account_id'] = $toAccountId ?: '';
$form['prediction_type'] = $predictionType;
$form['category_id'] = $categoryId ?: '';
$form['amount'] = $amountRaw;
$form['variable'] = $variable;
$form['average_over_last'] = $averageOverLast ?? '';
$form['active'] = $active;
$form['recurrence_unit'] = $recurrenceUnit;
$form['recurrence_interval'] = $recurrenceInterval;
$form['anchor_date'] = $anchorDate;
$form['schedule_pattern'] = $schedulePattern;
$form['day_of_month'] = $dayOfMonth ?? '';
$form['weekday'] = $weekday ?? '';
$form['nth_weekday'] = $nthWeekday ?? '';
$form['business_day_adjustment'] = $businessDayAdjustment;
$form['source_transaction_id'] = $sourceTransactionId > 0 ? $sourceTransactionId : '';
$form['redirect'] = $returnUrl;

if (!empty($errors)) {
    $_SESSION['prediction_rule_errors'] = $errors;
    $_SESSION['prediction_rule_form'] = $form;
    if ($id > 0) {
        $target = 'predicted_rule_edit.php?id=' . $id;
    } elseif ($sourceTransactionId > 0) {
        $target = 'predicted_rule_edit.php?' . http_build_query([
            'from_transaction_id' => $sourceTransactionId,
            'redirect' => $returnUrl,
        ]);
    } else {
        $target = 'predicted_rule_edit.php';
    }
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
                prediction_type = ?,
                amount = ?,
                variable = ?,
                average_over_last = ?,
                active = ?,
                recurrence_unit = ?,
                recurrence_interval = ?,
                anchor_date = ?,
                schedule_pattern = ?,
                day_of_month = ?,
                weekday = ?,
                nth_weekday = ?,
                business_day_adjustment = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $description,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $predictionType,
            $amount,
            $variable,
            $variable ? $averageOverLast : null,
            $active,
            $recurrenceUnit,
            $recurrenceInterval,
            $anchorDate,
            $schedulePattern,
            $dayOfMonth,
            $weekday,
            $nthWeekday,
            $businessDayAdjustment,
            $id,
        ]);
        $ruleId = $id;
        $actionLabel = 'updated';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO predicted_transactions (
                description, from_account_id, to_account_id, category_id, prediction_type, amount,
                variable, average_over_last, active,
                recurrence_unit, recurrence_interval, anchor_date, schedule_pattern,
                day_of_month, weekday, nth_weekday, business_day_adjustment
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $description,
            $fromAccountId,
            $toAccountId,
            $categoryId,
            $predictionType,
            $amount,
            $variable,
            $variable ? $averageOverLast : null,
            $active,
            $recurrenceUnit,
            $recurrenceInterval,
            $anchorDate,
            $schedulePattern,
            $dayOfMonth,
            $weekday,
            $nthWeekday,
            $businessDayAdjustment,
        ]);
        $ruleId = (int)$pdo->lastInsertId();
        $actionLabel = 'created';
    }

    if ($sourceTransactionId > 0 && $id === 0) {
        $lockedSourceContext = ptl_lock_source_transaction($pdo, $sourceTransactionId);
        [$stillEligible, $sourceReason] = ptl_can_create_rule_from_transaction($pdo, $lockedSourceContext);
        if (!$stillEligible) {
            throw new RuntimeException($sourceReason);
        }
        ptl_assert_rule_compatible($pdo, $lockedSourceContext, $ruleId);
        ptl_apply_rule_link($pdo, $lockedSourceContext, $ruleId);
    }

    $pruned = prediction_rule_prune_future_open_instances($pdo, $ruleId);

    $pdo->commit();

    $job = run_predict_instances_job(true, 'prediction_rule_save');
    $jobMessage = $job['message'] ?? 'Reforecast attempted.';

    $_SESSION['prediction_rule_flash'] = "✅ Prediction rule {$actionLabel}. Refreshed {$pruned} future open instance(s). {$jobMessage}";

    if ($sourceTransactionId > 0 && $id === 0) {
        $historyTarget = 'predicted_rule_history.php?' . http_build_query([
            'id' => $ruleId,
            'created' => 1,
            'redirect' => $returnUrl,
        ]);
        header('Location: ' . $historyTarget);
    } else {
        header('Location: predicted.php');
    }
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['prediction_rule_errors'] = ['Save failed: ' . $e->getMessage()];
    $_SESSION['prediction_rule_form'] = $form;
    if ($id > 0) {
        $target = 'predicted_rule_edit.php?id=' . $id;
    } elseif ($sourceTransactionId > 0) {
        $target = 'predicted_rule_edit.php?' . http_build_query([
            'from_transaction_id' => $sourceTransactionId,
            'redirect' => $returnUrl,
        ]);
    } else {
        $target = 'predicted_rule_edit.php';
    }
    header('Location: ' . $target);
    exit;
}
