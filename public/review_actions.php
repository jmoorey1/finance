<?php
require_once('../config/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$txn_id = (int)($_POST['txn_id'] ?? 0);
$action = $_POST['action'] ?? '';
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;

$stmt = $pdo->prepare("SELECT * FROM staging_transactions WHERE id = ?");
$stmt->execute([$txn_id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['error' => 'Transaction not found']);
    exit;
}

if ($action === 'delete') {
    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    echo json_encode(['status' => 'deleted']);
    exit;
}

if ($action === 'approve' && $category_id) {
    // Lookup category
    $catStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $catStmt->execute([$category_id]);
    $cat = $catStmt->fetch();

    $isTransfer = ($cat && $cat['type'] === 'transfer' && $cat['linked_account_id']);
    $transferTo = str_starts_with($cat['name'], 'Transfer To');
    $transferFrom = str_starts_with($cat['name'], 'Transfer From');
    $type = $isTransfer ? 'transfer' : ($row['amount'] >= 0 ? 'deposit' : 'withdrawal');

    $transferGroupId = null;
    if ($isTransfer) {
        $pdo->prepare("INSERT INTO transfer_groups (description) VALUES (?)")
            ->execute(["Auto transfer for staging txn ID " . $row['id']]);
        $transferGroupId = $pdo->lastInsertId();
    }

    // Insert primary transaction
    $pdo->prepare("INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $row['account_id'],
            $row['date'],
            $row['description'],
            $row['amount'],
            $type,
            $category_id,
            $transferGroupId
        ]);

    // Handle opposite transfer
    if ($isTransfer) {
        $oppositeAmount = $row['amount'] * -1;
        $oppositeAccountId = $cat['linked_account_id'];

        $srcAccountName = $pdo->prepare("SELECT name FROM accounts WHERE id = ?");
        $srcAccountName->execute([$row['account_id']]);
        $srcName = $srcAccountName->fetchColumn();

        $oppositeCatName = $transferTo ? "Transfer From : $srcName" : "Transfer To : $srcName";
        $lookup = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND linked_account_id = ?");
        $lookup->execute([$oppositeCatName, $row['account_id']]);
        $oppositeCategoryId = $lookup->fetchColumn();

        $pdo->prepare("INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $oppositeAccountId,
                $row['date'],
                $row['description'],
                $oppositeAmount,
                'transfer',
                $oppositeCategoryId ?: null,
                $transferGroupId
            ]);
    }

    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    echo json_encode(['status' => 'approved']);
    exit;
}

echo json_encode(['error' => 'Unhandled action']);
