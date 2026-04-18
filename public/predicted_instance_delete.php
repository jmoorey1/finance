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
    $_SESSION['prediction_action_flash'] = '⚠️ Invalid one-off item.';
    header('Location: predicted.php?future_days=' . $futureDays);
    exit;
}

try {
    $stmt = $pdo->prepare("
        DELETE FROM predicted_instances
        WHERE id = ?
          AND predicted_transaction_id IS NULL
          AND COALESCE(fulfilled, 0) = 0
    ");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['prediction_action_flash'] = '🗑️ One-off planned item deleted.';
    } else {
        $_SESSION['prediction_action_flash'] = '⚠️ One-off planned item not found or can no longer be deleted.';
    }
} catch (Throwable $e) {
    $_SESSION['prediction_action_flash'] = '❌ Delete failed: ' . $e->getMessage();
}

header('Location: predicted.php?future_days=' . $futureDays);
exit;
