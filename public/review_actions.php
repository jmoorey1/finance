<?php
file_put_contents('/tmp/post_debug.txt', print_r($_POST, true));

require_once '../config/db.php';

$txn_id = $_POST['txn_id'] ?? null;
$action = $_POST['action'] ?? null;
$category_id = $_POST['category_id'] ?? null;
$split_mode = isset($_POST['split_mode']);
$transfer_mode = $_POST['transfer_mode'] ?? null;
$counter_account_id = $_POST['counter_account_id'] ?? null;
$link_txn_id = $_POST['link_txn_id'] ?? null;

if (!$txn_id || !$action) {
    exit("Missing txn_id or action");
}



// Fetch staging transaction
$stmt = $pdo->prepare("SELECT * FROM staging_transactions WHERE id = ?");
$stmt->execute([$txn_id]);
$txn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$txn) exit("Transaction not found");

if ($action === 'delete') {
    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    header("Location: review.php");
    exit;
}

if ($action === 'confirm_duplicate') {
    $pdo->prepare("UPDATE staging_transactions SET status = 'duplicate' WHERE id = ?")->execute([$txn_id]);
    header("Location: review.php");
    exit;
}

if ($action === 'not_duplicate') {
    $pdo->prepare("UPDATE staging_transactions SET matched_transaction_id = NULL, status = 'new' WHERE id = ?")->execute([$txn_id]);
    header("Location: review.php");
    exit;
}

if ($action === 'approve') {
		if (!$counter_account_id && $transfer_mode === 'link_existing' && $link_txn_id) {
			$stmt = $pdo->prepare("SELECT account_id FROM transactions WHERE id = ?");
			$stmt->execute([$link_txn_id]);
			$counter_account_id = $stmt->fetchColumn();
		}

if (!$category_id) {
    if ($transfer_mode && $counter_account_id) {
        $direction = $txn['amount'] < 0 ? 'Transfer To :' : 'Transfer From :';
        $stmt = $pdo->prepare("
            SELECT id FROM categories
            WHERE type = 'transfer'
              AND linked_account_id = ?
              AND name LIKE ?
            LIMIT 1
        ");
        $stmt->execute([$counter_account_id, "$direction%"]);
        $category_id = $stmt->fetchColumn();

        if (!$category_id) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            exit("No matching transfer category found for account ID $counter_account_id and direction $direction");
        }
	} elseif ($split_mode) {
		$category_id = 197;
    } else {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exit("No category selected");
    }
}



    $pdo->beginTransaction();

    // Determine transaction type
	if ($transfer_mode) {
		$type = 'transfer';
	} else {
    $acctType = $pdo->query("SELECT type FROM accounts WHERE id = {$txn['account_id']}")->fetchColumn();
    $type = match ($acctType) {
        'credit' => ($txn['amount'] > 0 ? 'credit' : 'charge'),
        default  => ($txn['amount'] > 0 ? 'deposit' : 'withdrawal')
    };
	}

    // Insert main transaction
    $insert = $pdo->prepare("
        INSERT INTO transactions (account_id, date, description, amount, type, category_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $txn['account_id'],
        $txn['date'],
        $txn['description'],
        $txn['amount'],
        $type,
        $category_id
    ]);
    $txn_id_final = $pdo->lastInsertId();

    // Handle split logic
    if ($category_id == 197 && $split_mode) {
        $split_cats = $_POST['split_category_id'] ?? [];
        $split_amts = $_POST['split_amount'] ?? [];

        if (count($split_cats) !== count($split_amts)) {
if ($pdo->inTransaction()) $pdo->rollBack();
            exit("Mismatch in split entries.");
        }

        $sum = 0;
        for ($i = 0; $i < count($split_cats); $i++) {
            $cat = (int)$split_cats[$i];
            $amt = round((float)$split_amts[$i], 2);
            $sum += $amt;

            $pdo->prepare("
                INSERT INTO transaction_splits (transaction_id, category_id, amount)
                VALUES (?, ?, ?)
            ")->execute([$txn_id_final, $cat, $amt]);
        }

        if (round($sum, 2) !== round($txn['amount'], 2)) {
if ($pdo->inTransaction()) $pdo->rollBack();
            exit("Split total ($sum) doesn't match transaction amount ({$txn['amount']})");
        }
    }

    // Transfer handling
    if ($transfer_mode === 'create_opposite') {
        if (!$counter_account_id) {
if ($pdo->inTransaction()) $pdo->rollBack();
            exit("Missing counterparty account");
        }

        $pdo->exec("INSERT INTO transfer_groups () VALUES ()");
        $group_id = $pdo->lastInsertId();

        // Update first
        $pdo->prepare("UPDATE transactions SET transfer_group_id = ? WHERE id = ?")->execute([$group_id, $txn_id_final]);

        // Opposite type
        $opposite_type = match ($type) {
            'withdrawal' => 'deposit',
            'deposit' => 'withdrawal',
            'charge' => 'credit',
            'credit' => 'charge',
            default => 'transfer'
        };

        // Opposite insert
        $pdo->prepare("
            INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $counter_account_id,
            $txn['date'],
            $txn['description'],
            -$txn['amount'],
            $opposite_type,
            ($category_id == 197 ? null : $category_id),
            $group_id
        ]);

} elseif ($transfer_mode === 'link_existing') {
    if (!$link_txn_id) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exit("Missing other transaction ID");
    }

    // Get the other staging transaction
    $stmt = $pdo->prepare("SELECT * FROM staging_transactions WHERE id = ?");
    $stmt->execute([$link_txn_id]);
    $link_txn = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$link_txn) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exit("Linked transaction not found");
    }

    // Create the transfer group
    $pdo->exec("INSERT INTO transfer_groups () VALUES ()");
    $group_id = $pdo->lastInsertId();

    // Update the approved transaction with the group
    $pdo->prepare("UPDATE transactions SET transfer_group_id = ? WHERE id = ?")
        ->execute([$group_id, $txn_id_final]);

    // Determine opposite type
    $opposite_type = 'transfer';

    // Insert the linked transaction
    $stmt = $pdo->prepare("
        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $link_txn['account_id'],
        $link_txn['date'],
        $link_txn['description'],
        $link_txn['amount'],
        $opposite_type,
        ($category_id == 197 ? null : $category_id),
        $group_id
    ]);

    // Delete the linked transaction from staging
    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$link_txn_id]);
}


    $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    $pdo->commit();

    header("Location: review.php");
    exit;
}

exit("Unhandled action.");
