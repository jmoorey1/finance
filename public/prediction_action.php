<?php
session_start();
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'], $_POST['action'])) {
    header('Location: predicted.php');
    exit;
}

$id = (int) $_POST['id'];
$action = trim((string) $_POST['action']);
$redirectRaw = trim((string) ($_POST['redirect'] ?? 'predicted.php'));

$parsed = parse_url($redirectRaw);
$path = basename($parsed['path'] ?? 'predicted.php');
$query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
$allowedTargets = ['index.php', 'predicted.php'];

if (!in_array($path, $allowedTargets, true)) {
    $path = 'predicted.php';
    $query = '';
}

$redirect = $path . $query;

if ($id <= 0) {
    $_SESSION['prediction_action_flash'] = '⚠️ Invalid prediction ID.';
    header('Location: ' . $redirect);
    exit;
}

try {
    if ($action === 'skip') {
        $stmt = $pdo->prepare("
            UPDATE predicted_instances
            SET resolution_status = 'skipped',
                resolved_at = NOW(),
                resolution_note = 'Skipped via UI',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND COALESCE(fulfilled, 0) = 0
        ");
        $stmt->execute([$id]);
        $_SESSION['prediction_action_flash'] = '⏭️ Prediction skipped.';
    } elseif ($action === 'reopen') {
        $stmt = $pdo->prepare("
            UPDATE predicted_instances
            SET resolution_status = 'open',
                resolved_at = NULL,
                resolution_note = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND COALESCE(fulfilled, 0) = 0
        ");
        $stmt->execute([$id]);
        $_SESSION['prediction_action_flash'] = '↩️ Prediction reopened.';
    } else {
        $_SESSION['prediction_action_flash'] = '⚠️ Unknown prediction action.';
    }
} catch (Throwable $e) {
    $_SESSION['prediction_action_flash'] = '❌ Prediction action failed: ' . $e->getMessage();
}

header('Location: ' . $redirect);
exit;
