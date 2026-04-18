<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    header('Location: predicted.php');
    exit;
}

$id = (int)$_POST['id'];
$futureDays = isset($_POST['future_days']) ? (int)$_POST['future_days'] : 180;
if (!in_array($futureDays, [90, 180, 365], true)) {
    $futureDays = 180;
}

if ($id <= 0) {
    $_SESSION['planned_income_event_flash'] = '⚠️ Invalid flexible planned income event.';
    header('Location: predicted.php?future_days=' . $futureDays);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM planned_income_events WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['planned_income_event_flash'] = '🗑️ Flexible planned income event deleted.';
    } else {
        $_SESSION['planned_income_event_flash'] = '⚠️ Flexible planned income event not found.';
    }
} catch (Throwable $e) {
    $_SESSION['planned_income_event_flash'] = '❌ Delete failed: ' . $e->getMessage();
}

header('Location: predicted.php?future_days=' . $futureDays);
exit;
