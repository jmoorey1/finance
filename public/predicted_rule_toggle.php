<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../scripts/run_predict_instances.php';
require_once 'prediction_rule_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'], $_POST['action'])) {
    header('Location: predicted.php');
    exit;
}

$id = (int)$_POST['id'];
$action = trim((string)$_POST['action']);

if ($id <= 0 || !in_array($action, ['activate', 'deactivate'], true)) {
    $_SESSION['prediction_rule_flash'] = '⚠️ Invalid rule action.';
    header('Location: predicted.php');
    exit;
}

$newActive = $action === 'activate' ? 1 : 0;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE predicted_transactions SET active = ? WHERE id = ?");
    $stmt->execute([$newActive, $id]);

    $pruned = 0;
    if ($newActive === 0) {
        $pruned = prediction_rule_prune_future_open_instances($pdo, $id);
    }

    $pdo->commit();

    $job = run_predict_instances_job(true, 'prediction_rule_toggle');
    $jobMessage = $job['message'] ?? 'Reforecast attempted.';

    $verb = $newActive ? 'activated' : 'deactivated';
    $_SESSION['prediction_rule_flash'] = "✅ Prediction rule {$verb}. Refreshed {$pruned} future open instance(s). {$jobMessage}";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['prediction_rule_flash'] = '❌ Rule toggle failed: ' . $e->getMessage();
}

header('Location: predicted.php');
exit;
