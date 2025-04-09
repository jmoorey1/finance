<?php
require_once('../config/db.php');

$action = $_POST['action'] ?? '';
$txn_id = $_POST['txn_id'] ?? null;

if (!$txn_id) exit("Error: Missing txn_id");

$stmt = $pdo->prepare("SELECT * FROM staging_transactions WHERE id = ?");
$stmt->execute([$txn_id]);
$txn = $stmt->fetch();
if (!$txn) exit("Error: Transaction not found");

// DELETE
if ($action === 'delete') {
    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    header("Location: review.php?status=new");
    exit;
}

// APPROVE
if ($action === 'approve') {
    $category_id = $_POST['category_id'] ?? null;
    if (!$category_id) exit("Error: No category selected");

    $pdo->beginTransaction();

    // Lookup account type
    $acctStmt = $pdo->prepare("SELECT type FROM accounts WHERE id = ?");
    $acctStmt->execute([$txn['account_id']]);
    $acctType = $acctStmt->fetchColumn();

    $amount = floatval($txn['amount']);
    $type = match (true) {
        $acctType === 'credit' && $amount < 0 => 'charge',
        $acctType === 'credit' && $amount >= 0 => 'credit',
        $acctType !== 'credit' && $amount < 0 => 'withdrawal',
        default => 'deposit'
    };

    // Insert main transaction
    $insertTxn = $pdo->prepare("
        INSERT INTO transactions (account_id, date, description, amount, type, category_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertTxn->execute([
        $txn['account_id'],
        $txn['date'],
        $txn['description'],
        $amount,
        $type,
        (int)$category_id
    ]);

    $newTxnId = $pdo->lastInsertId();

    // Handle splits if split_mode and category_id = 197
    if ($category_id == 197 && isset($_POST['split_mode'])) {
        $splitCats = $_POST['split_category_id'] ?? [];
        $splitAmts = $_POST['split_amount'] ?? [];

        $splitTotal = 0;
        foreach ($splitAmts as $i => $amt) {
            $splitTotal += floatval($amt);
        }

        if (round($splitTotal, 2) !== round($amount, 2)) {
            $pdo->rollBack();
            exit("Error: Split amount mismatch: total=$splitTotal vs txn=$amount");
        }

        $insertSplit = $pdo->prepare("
            INSERT INTO transaction_splits (transaction_id, category_id, amount)
            VALUES (?, ?, ?)
        ");

        foreach ($splitCats as $i => $catId) {
            $insertSplit->execute([
                $newTxnId,
                (int)$catId,
                floatval($splitAmts[$i])
            ]);
        }
    }

    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    $pdo->commit();
    header("Location: review.php?status=new");
    exit;
}

exit("Error: Unhandled action");
