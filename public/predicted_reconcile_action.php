<?php
if (session_status() === PHP_SESSION_NONE) {
    auth_session_start();
}

require_once '../config/db.php';
require_once '../scripts/predicted_reconciliation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['prediction_id'], $_POST['action'])) {
    header('Location: predicted.php');
    exit;
}

$predictionId = (int)$_POST['prediction_id'];
$action = trim((string)$_POST['action']);
$redirectRaw = trim((string)($_POST['redirect'] ?? 'predicted.php'));

$allowedRedirects = ['index.php', 'predicted.php'];
$parsed = parse_url($redirectRaw);
$redirectPath = basename($parsed['path'] ?? 'predicted.php');
$redirectQuery = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
if (!in_array($redirectPath, $allowedRedirects, true)) {
    $redirectPath = 'predicted.php';
    $redirectQuery = '';
}
$redirect = $redirectPath . $redirectQuery;

$instance = $predictionId > 0 ? pr_load_predicted_instance($pdo, $predictionId) : null;

if (!$instance) {
    $_SESSION['prediction_action_flash'] = '⚠️ Predicted instance not found.';
    header('Location: ' . $redirect);
    exit;
}

if (!pr_validate_reconcilable($instance)) {
    $_SESSION['prediction_action_flash'] = '⚠️ This predicted instance is already fulfilled.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'reconcile_regular') {
        if (!pr_is_regular($instance)) {
            throw new RuntimeException('Selected predicted instance is not a regular income/expense item.');
        }

        $transactionId = (int)($_POST['transaction_id'] ?? 0);
        if ($transactionId <= 0) {
            throw new RuntimeException('Missing transaction selection.');
        }

        if (pr_is_txn_linked_elsewhere($pdo, $transactionId, $predictionId)) {
            throw new RuntimeException('That transaction is already linked to another predicted instance.');
        }

        $stmt = $pdo->prepare("
            SELECT id, account_id, date, amount, predicted_transaction_id
            FROM transactions
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$transactionId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            throw new RuntimeException('Selected transaction was not found.');
        }

        if ((int)$txn['account_id'] !== (int)$instance['from_account_id']) {
            throw new RuntimeException('Selected transaction is on the wrong account.');
        }

        if (abs((float)$txn['amount'] - (float)$instance['amount']) >= 0.01) {
            throw new RuntimeException('Selected transaction amount does not match the predicted instance.');
        }

        pr_mark_regular_fulfilled($pdo, $instance, $transactionId);
        $_SESSION['prediction_action_flash'] = '✅ Predicted instance reconciled to actual transaction.';
    }

    elseif ($action === 'reconcile_transfer_group') {
        if (!pr_is_transfer($instance)) {
            throw new RuntimeException('Selected predicted instance is not a transfer.');
        }

        $transferGroupId = (int)($_POST['transfer_group_id'] ?? 0);
        if ($transferGroupId <= 0) {
            throw new RuntimeException('Missing transfer group selection.');
        }

        if (pr_is_group_linked_elsewhere($pdo, $transferGroupId, $predictionId)) {
            throw new RuntimeException('That transfer group is already linked to another predicted instance.');
        }

        $predAmount = abs((float)$instance['amount']);
        $stmt = $pdo->prepare("
            SELECT account_id, amount
            FROM transactions
            WHERE transfer_group_id = ?
              AND type = 'transfer'
        ");
        $stmt->execute([$transferGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fromOk = false;
        $toOk = false;
        foreach ($rows as $row) {
            $acct = (int)$row['account_id'];
            $amt = (float)$row['amount'];

            if ($acct === (int)$instance['from_account_id'] && abs($amt + $predAmount) < 0.01) {
                $fromOk = true;
            }
            if ($acct === (int)$instance['to_account_id'] && abs($amt - $predAmount) < 0.01) {
                $toOk = true;
            }
        }

        if (!$fromOk || !$toOk) {
            throw new RuntimeException('Selected transfer group does not match the predicted transfer.');
        }

        pr_mark_transfer_fulfilled($pdo, $instance, $transferGroupId);
        $_SESSION['prediction_action_flash'] = '✅ Predicted transfer reconciled to actual transfer group.';
    }

    elseif ($action === 'reconcile_transfer_pair') {
        if (!pr_is_transfer($instance)) {
            throw new RuntimeException('Selected predicted instance is not a transfer.');
        }

        $fromTxnId = (int)($_POST['from_transaction_id'] ?? 0);
        $toTxnId = (int)($_POST['to_transaction_id'] ?? 0);

        if ($fromTxnId <= 0 || $toTxnId <= 0) {
            throw new RuntimeException('Both transfer rows must be selected.');
        }
        if ($fromTxnId === $toTxnId) {
            throw new RuntimeException('The two selected rows must be different transactions.');
        }

        if (pr_is_txn_linked_elsewhere($pdo, $fromTxnId, $predictionId) || pr_is_txn_linked_elsewhere($pdo, $toTxnId, $predictionId)) {
            throw new RuntimeException('One of the selected transactions is already linked to another predicted instance.');
        }

        $stmt = $pdo->prepare("
            SELECT id, account_id, amount, transfer_group_id, predicted_transaction_id
            FROM transactions
            WHERE id IN (?, ?)
            ORDER BY id ASC
        ");
        $stmt->execute([$fromTxnId, $toTxnId]);
        $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($txns) !== 2) {
            throw new RuntimeException('Could not load both selected transactions.');
        }

        $byId = [];
        foreach ($txns as $txn) {
            $byId[(int)$txn['id']] = $txn;
        }

        $fromTxn = $byId[$fromTxnId] ?? null;
        $toTxn = $byId[$toTxnId] ?? null;

        if (!$fromTxn || !$toTxn) {
            throw new RuntimeException('Could not load selected transaction pair.');
        }

        $predAmount = abs((float)$instance['amount']);

        if ((int)$fromTxn['account_id'] !== (int)$instance['from_account_id'] || abs((float)$fromTxn['amount'] + $predAmount) >= 0.01) {
            throw new RuntimeException('Outgoing transfer row does not match the predicted transfer.');
        }

        if ((int)$toTxn['account_id'] !== (int)$instance['to_account_id'] || abs((float)$toTxn['amount'] - $predAmount) >= 0.01) {
            throw new RuntimeException('Incoming transfer row does not match the predicted transfer.');
        }

        $fromGroup = $fromTxn['transfer_group_id'] !== null ? (int)$fromTxn['transfer_group_id'] : null;
        $toGroup = $toTxn['transfer_group_id'] !== null ? (int)$toTxn['transfer_group_id'] : null;

        if ($fromGroup && pr_is_group_linked_elsewhere($pdo, $fromGroup, $predictionId)) {
            throw new RuntimeException('The outgoing transfer row is already in a transfer group linked elsewhere.');
        }
        if ($toGroup && pr_is_group_linked_elsewhere($pdo, $toGroup, $predictionId)) {
            throw new RuntimeException('The incoming transfer row is already in a transfer group linked elsewhere.');
        }

        if ($fromGroup && $toGroup && $fromGroup !== $toGroup) {
            throw new RuntimeException('Selected transactions belong to different transfer groups.');
        }

        $transferGroupId = $fromGroup ?: $toGroup;

        if (!$transferGroupId) {
            $pdo->prepare("INSERT INTO transfer_groups (description) VALUES ('Retrospective predicted reconciliation')")->execute();
            $transferGroupId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("
            UPDATE transactions
            SET transfer_group_id = ?
            WHERE id IN (?, ?)
        ")->execute([$transferGroupId, $fromTxnId, $toTxnId]);

        if (!empty($instance['predicted_transaction_id'])) {
            $pdo->prepare("
                UPDATE transactions
                SET predicted_transaction_id = COALESCE(predicted_transaction_id, ?)
                WHERE id IN (?, ?)
            ")->execute([(int)$instance['predicted_transaction_id'], $fromTxnId, $toTxnId]);
        }

        pr_mark_transfer_fulfilled($pdo, $instance, $transferGroupId);
        $_SESSION['prediction_action_flash'] = '✅ Predicted transfer reconciled to actual transfer rows.';
    }

    else {
        throw new RuntimeException('Unknown reconciliation action.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['prediction_action_flash'] = '❌ Reconciliation failed: ' . $e->getMessage();
}

header('Location: ' . $redirect);
exit;
