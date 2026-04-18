<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../scripts/planned_income_engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: predicted.php');
    exit;
}

$defaults = pie_defaults();
$form = array_merge($defaults, $_POST);
$errors = [];

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : 0;
$description = trim((string)($_POST['description'] ?? ''));
$categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;
$accountId = isset($_POST['account_id']) && $_POST['account_id'] !== '' ? (int)$_POST['account_id'] : 0;
$amountRaw = trim((string)($_POST['amount'] ?? ''));
$windowStart = trim((string)($_POST['window_start'] ?? ''));
$windowEnd = trim((string)($_POST['window_end'] ?? ''));
$timingStrategy = trim((string)($_POST['timing_strategy'] ?? 'latest'));
$manualDate = trim((string)($_POST['manual_date'] ?? ''));
$active = isset($_POST['active']) ? 1 : 0;
$notes = trim((string)($_POST['notes'] ?? ''));
$futureDays = isset($_POST['future_days']) ? (int)$_POST['future_days'] : 180;
if (!in_array($futureDays, [90, 180, 365], true)) {
    $futureDays = 180;
}

if ($description === '') {
    $errors[] = 'Description is required.';
}

if ($categoryId <= 0) {
    $errors[] = 'Income category is required.';
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = ? AND type = 'income'");
    $stmt->execute([$categoryId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $errors[] = 'Selected category must be an income category.';
    }
}

if ($accountId <= 0) {
    $errors[] = 'Receiving account is required.';
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = ? AND active = 1 AND type IN ('current', 'savings')");
    $stmt->execute([$accountId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $errors[] = 'Receiving account must be an active current or savings account.';
    }
}

if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
    $errors[] = 'Amount must be a positive number.';
} else {
    $amount = number_format((float)$amountRaw, 2, '.', '');
}

try {
    $windowStartDt = new DateTimeImmutable($windowStart);
} catch (Throwable $e) {
    $windowStartDt = null;
    $errors[] = 'Window start is invalid.';
}

try {
    $windowEndDt = new DateTimeImmutable($windowEnd);
} catch (Throwable $e) {
    $windowEndDt = null;
    $errors[] = 'Window end is invalid.';
}

$validStrategies = array_keys(pie_timing_options());
if (!in_array($timingStrategy, $validStrategies, true)) {
    $errors[] = 'Timing strategy is invalid.';
}

if ($windowStartDt && $windowEndDt && $windowEndDt < $windowStartDt) {
    $errors[] = 'Window end must be on or after window start.';
}

if ($timingStrategy === 'manual') {
    if ($manualDate === '') {
        $errors[] = 'Manual assumed date is required when Timing Strategy = Manual.';
    } else {
        try {
            $manualDt = new DateTimeImmutable($manualDate);
            if ($windowStartDt && $windowEndDt && ($manualDt < $windowStartDt || $manualDt > $windowEndDt)) {
                $errors[] = 'Manual assumed date must fall inside the window.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Manual assumed date is invalid.';
        }
    }
} else {
    $manualDate = '';
}

$form['id'] = $id ?: '';
$form['description'] = $description;
$form['category_id'] = $categoryId ?: '';
$form['account_id'] = $accountId ?: '';
$form['amount'] = $amountRaw;
$form['window_start'] = $windowStart;
$form['window_end'] = $windowEnd;
$form['timing_strategy'] = $timingStrategy;
$form['manual_date'] = $manualDate;
$form['active'] = $active;
$form['notes'] = $notes;

if (!empty($errors)) {
    $_SESSION['planned_income_errors'] = $errors;
    $_SESSION['planned_income_form'] = $form;
    $target = 'planned_income_edit.php?future_days=' . $futureDays . ($id > 0 ? '&id=' . $id : '');
    header('Location: ' . $target);
    exit;
}

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE planned_income_events
            SET description = ?,
                category_id = ?,
                account_id = ?,
                amount = ?,
                window_start = ?,
                window_end = ?,
                timing_strategy = ?,
                manual_date = ?,
                active = ?,
                notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $description,
            $categoryId,
            $accountId,
            $amount,
            $windowStart,
            $windowEnd,
            $timingStrategy,
            $manualDate !== '' ? $manualDate : null,
            $active,
            $notes !== '' ? $notes : null,
            $id,
        ]);
        $_SESSION['planned_income_event_flash'] = '✅ Flexible planned income event updated.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO planned_income_events (
                description, category_id, account_id, amount,
                window_start, window_end, timing_strategy, manual_date, active, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $description,
            $categoryId,
            $accountId,
            $amount,
            $windowStart,
            $windowEnd,
            $timingStrategy,
            $manualDate !== '' ? $manualDate : null,
            $active,
            $notes !== '' ? $notes : null,
        ]);
        $_SESSION['planned_income_event_flash'] = '✅ Flexible planned income event created.';
    }
} catch (Throwable $e) {
    $_SESSION['planned_income_errors'] = ['Save failed: ' . $e->getMessage()];
    $_SESSION['planned_income_form'] = $form;
    $target = 'planned_income_edit.php?future_days=' . $futureDays . ($id > 0 ? '&id=' . $id : '');
    header('Location: ' . $target);
    exit;
}

header('Location: predicted.php?future_days=' . $futureDays);
exit;
