<?php
require_once '../config/db.php';
$conn = get_db_connection();

$action = $_POST['action'] ?? '';
$staging_id = (int) ($_POST['staging_transaction_id'] ?? 0);

if (!$staging_id && !in_array($action, ['categorise', 'delete_staging'])) {
    die("❌ No staging transaction provided.");
}

switch ($action) {
    // ----------------------------------------
    // ✅ CONFIRM: This transaction fulfills a predicted instance
    // ----------------------------------------
    case 'fulfill_prediction':
        $predicted_id_post = (int) ($_POST['predicted_instance_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT
                s.*,
                p.id AS predicted_instance_id,
                p.predicted_transaction_id,
                p.category_id AS instance_category_id,
                p.from_account_id,
                p.to_account_id,
                COALESCE(p.fulfilled, 0) AS predicted_fulfilled,
                p.fulfilled_by_transfer_group_id AS saved_transfer_group_id
            FROM staging_transactions s
            JOIN predicted_instances p ON s.predicted_instance_id = p.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$staging_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            die('❌ Unable to load staging + predicted instance for fulfillment.');
        }

        $predicted_id = (int) $row['predicted_instance_id'];

        if ($predicted_id_post && $predicted_id_post !== $predicted_id) {
            die('❌ Predicted instance mismatch.');
        }

        $cat_stmt = $conn->prepare("SELECT type FROM categories WHERE id = ? LIMIT 1");
        $cat_stmt->execute([(int) $row['instance_category_id']]);
        $cat_row = $cat_stmt->fetch(PDO::FETCH_ASSOC);
        $category_type = $cat_row['type'] ?? '';

        $resolve_transfer_category = function (int $linked_account_id, string $directionPrefix) use ($conn): ?int {
            $stmt = $conn->prepare("
                SELECT id
                FROM categories
                WHERE type = 'transfer'
                  AND linked_account_id = ?
                  AND name LIKE ?
                LIMIT 1
            ");
            $stmt->execute([$linked_account_id, $directionPrefix . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['id'] ?? null;
        };

        $transfer_category_for_account = function (int $txn_account_id, int $from_account, int $to_account) use ($resolve_transfer_category): ?int {
            if ($txn_account_id === $from_account) {
                return $resolve_transfer_category($to_account, 'Transfer To :');
            }
            if ($txn_account_id === $to_account) {
                return $resolve_transfer_category($from_account, 'Transfer From :');
            }
            return null;
        };

        $mark_prediction_partial = function (int $prediction_id, int $transfer_group_id) use ($conn): void {
            $conn->prepare("
                UPDATE predicted_instances
                SET fulfilled = 2,
                    confirmed = 1,
                    fulfilled_at = NOW(),
                    fulfilled_by_transaction_id = NULL,
                    fulfilled_by_transfer_group_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$transfer_group_id, $prediction_id]);
        };

        $mark_prediction_fulfilled_transfer = function (int $prediction_id, int $transfer_group_id) use ($conn): void {
            $conn->prepare("
                UPDATE predicted_instances
                SET fulfilled = 1,
                    confirmed = 1,
                    fulfilled_at = NOW(),
                    fulfilled_by_transaction_id = NULL,
                    fulfilled_by_transfer_group_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$transfer_group_id, $prediction_id]);
        };

        $mark_prediction_fulfilled_regular = function (int $prediction_id, int $transaction_id) use ($conn): void {
            $conn->prepare("
                UPDATE predicted_instances
                SET fulfilled = 1,
                    confirmed = 1,
                    fulfilled_at = NOW(),
                    fulfilled_by_transaction_id = ?,
                    fulfilled_by_transfer_group_id = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$transaction_id, $prediction_id]);
        };

        try {
            if ($category_type === 'transfer') {
                $conn->beginTransaction();

                $from_account = (int) $row['from_account_id'];
                $to_account = (int) $row['to_account_id'];
                $uploaded_account = (int) $row['account_id'];
                $predicted_fulfilled = (int) ($row['predicted_fulfilled'] ?? 0);

                if ($uploaded_account !== $from_account && $uploaded_account !== $to_account) {
                    throw new RuntimeException('Uploaded account does not match predicted transfer.');
                }

                if ($predicted_fulfilled === 1) {
                    throw new RuntimeException('Predicted transfer is already fully fulfilled.');
                }

                // Scenario C: completing a previously partial transfer
                if ($predicted_fulfilled === 2) {
                    $transfer_group_id = (int) ($row['saved_transfer_group_id'] ?? 0);
                    if (!$transfer_group_id) {
                        throw new RuntimeException('Missing saved transfer_group_id for partial predicted transfer.');
                    }

                    $cat = $transfer_category_for_account($uploaded_account, $from_account, $to_account);

                    $ins = $conn->prepare("
                        INSERT INTO transactions
                            (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                        VALUES
                            (?, ?, ?, ?, 'transfer', ?, ?, ?)
                    ");
                    $ins->execute([
                        $uploaded_account,
                        $row['date'],
                        $row['description'],
                        $row['amount'],
                        $cat,
                        $transfer_group_id,
                        $row['predicted_transaction_id']
                    ]);

                    $conn->prepare("
                        DELETE FROM transactions
                        WHERE transfer_group_id = ?
                          AND description = 'PLACEHOLDER'
                        LIMIT 1
                    ")->execute([$transfer_group_id]);

                    $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
                    $mark_prediction_fulfilled_transfer($predicted_id, $transfer_group_id);

                    $conn->commit();
                    break;
                }

                // Check whether the other side is already in staging
                $other_stmt = $conn->prepare("
                    SELECT *
                    FROM staging_transactions
                    WHERE predicted_instance_id = ?
                      AND id != ?
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $other_stmt->execute([$predicted_id, $staging_id]);
                $other = $other_stmt->fetch(PDO::FETCH_ASSOC);

                // Scenario A: both sides are present in staging now
                if ($other) {
                    $conn->prepare("INSERT INTO transfer_groups (description) VALUES ('Predicted transfer match')")->execute();
                    $transfer_group_id = (int) $conn->lastInsertId();

                    $other_account = (int) $other['account_id'];
                    if ($other_account !== $from_account && $other_account !== $to_account) {
                        throw new RuntimeException('Other staging row account does not match predicted transfer.');
                    }

                    $cat1 = $transfer_category_for_account($uploaded_account, $from_account, $to_account);
                    $conn->prepare("
                        INSERT INTO transactions
                            (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                        VALUES
                            (?, ?, ?, ?, 'transfer', ?, ?, ?)
                    ")->execute([
                        $uploaded_account,
                        $row['date'],
                        $row['description'],
                        $row['amount'],
                        $cat1,
                        $transfer_group_id,
                        $row['predicted_transaction_id']
                    ]);

                    $cat2 = $transfer_category_for_account($other_account, $from_account, $to_account);
                    $conn->prepare("
                        INSERT INTO transactions
                            (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                        VALUES
                            (?, ?, ?, ?, 'transfer', ?, ?, ?)
                    ")->execute([
                        $other_account,
                        $other['date'],
                        $other['description'],
                        $other['amount'],
                        $cat2,
                        $transfer_group_id,
                        $row['predicted_transaction_id']
                    ]);

                    $conn->prepare("DELETE FROM staging_transactions WHERE id IN (?, ?)")->execute([$staging_id, (int) $other['id']]);
                    $mark_prediction_fulfilled_transfer($predicted_id, $transfer_group_id);

                    $conn->commit();
                    break;
                }

                // Scenario B: only one side present so create placeholder and mark partial
                $conn->prepare("INSERT INTO transfer_groups (description) VALUES ('Predicted transfer (partial)')")->execute();
                $transfer_group_id = (int) $conn->lastInsertId();

                $counterparty_account = ($uploaded_account === $from_account) ? $to_account : $from_account;

                $real_cat = $transfer_category_for_account($uploaded_account, $from_account, $to_account);
                $conn->prepare("
                    INSERT INTO transactions
                        (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                    VALUES
                        (?, ?, ?, ?, 'transfer', ?, ?, ?)
                ")->execute([
                    $uploaded_account,
                    $row['date'],
                    $row['description'],
                    $row['amount'],
                    $real_cat,
                    $transfer_group_id,
                    $row['predicted_transaction_id']
                ]);

                $placeholder_amt = -1 * (float) $row['amount'];
                $placeholder_cat = $transfer_category_for_account($counterparty_account, $from_account, $to_account);

                $conn->prepare("
                    INSERT INTO transactions
                        (account_id, date, description, amount, type, category_id, transfer_group_id)
                    VALUES
                        (?, ?, 'PLACEHOLDER', ?, 'transfer', ?, ?)
                ")->execute([
                    $counterparty_account,
                    $row['date'],
                    $placeholder_amt,
                    $placeholder_cat,
                    $transfer_group_id
                ]);

                $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
                $mark_prediction_partial($predicted_id, $transfer_group_id);

                $conn->commit();
            } else {
                // Regular income/expense prediction match
                $conn->beginTransaction();

                if ((int) ($row['predicted_fulfilled'] ?? 0) === 1) {
                    throw new RuntimeException('Predicted instance is already fully fulfilled.');
                }

                $insert = $conn->prepare("
                    INSERT INTO transactions
                        (account_id, date, description, amount, original_ref, category_id, predicted_transaction_id)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([
                    $row['account_id'],
                    $row['date'],
                    $row['description'],
                    $row['amount'],
                    substr((string) ($row['original_memo'] ?? ''), 0, 100),
                    $row['instance_category_id'] ?? null,
                    $row['predicted_transaction_id']
                ]);
                $transaction_id = (int) $conn->lastInsertId();

                $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
                $mark_prediction_fulfilled_regular($predicted_id, $transaction_id);

                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            die('❌ Fulfill failed: ' . $e->getMessage());
        }

        break;

    // ----------------------------------------
    // ❌ REJECT: It's not the predicted transaction
    // ----------------------------------------
    case 'reject_prediction':
        $conn->prepare("UPDATE staging_transactions SET status = 'new', predicted_instance_id = NULL WHERE id = ?")
            ->execute([$staging_id]);
        break;

    // ----------------------------------------
    // ✅ CONFIRM: This staging entry is a duplicate
    // ----------------------------------------
    case 'confirm_duplicate':
        $matched_id = (int) ($_POST['matched_transaction_id'] ?? 0);
        $conn->prepare("UPDATE transactions set date=(select date from staging_transactions WHERE id = ? limit 1), description=(select description from staging_transactions where id = ? limit 1) WHERE id = ?")->execute([$staging_id, $staging_id, $matched_id]);
        $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
        break;

    // ----------------------------------------
    // ❌ REJECT: Not actually a duplicate
    // ----------------------------------------
    case 'reject_duplicate':
        $conn->prepare("UPDATE staging_transactions SET status = 'new', matched_transaction_id = NULL WHERE id = ?")
            ->execute([$staging_id]);
        break;

    // ----------------------------------------
    // 🗑 DELETE: Manual user deletion
    // ----------------------------------------
    case 'delete_staging':
        $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
        break;

    // ----------------------------------------
    // ✳️ APPROVE: Categorised or split/transfer transaction (TBD)
    // ----------------------------------------
    case 'categorise':
        $category_id = (int) ($_POST['category_id'] ?? 0);

        // Get the full staging transaction row
        $stmt = $conn->prepare("SELECT * FROM staging_transactions WHERE id = ?");
        $stmt->execute([$staging_id]);
        $staging = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staging) {
            die("❌ Staging transaction not found.");
        }
        // Transfer Pairing or Placeholder
        if ($category_id === -1) {
            $transfer_target = $_POST['transfer_target'] ?? '';
            if (!$transfer_target) {
                die("❌ Missing transfer target.");
            }

            // Get original staging transaction
            $stmt = $conn->prepare("SELECT * FROM staging_transactions WHERE id = ?");
            $stmt->execute([$staging_id]);
            $staging = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$staging) die("❌ Staging transaction not found.");

            $conn->beginTransaction();

            // 1️⃣ Create transfer group
            $conn->prepare("INSERT INTO transfer_groups (description) VALUES ('Manual transfer match')")->execute();
            $transfer_group_id = $conn->lastInsertId();

            // Helper to determine type and category
            function resolve_transfer_category(PDO $conn, int $from_account, float $amount, int $linked_account): ?int {
                $direction = $amount < 0 ? 'Transfer To :' : 'Transfer From :';
                $stmt = $conn->prepare("
                    SELECT id FROM categories
                    WHERE type = 'transfer'
                      AND linked_account_id = ?
                      AND name LIKE ?
                    LIMIT 1
                ");
                $stmt->execute([$linked_account, "$direction%"]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result['id'] ?? null;
            }

            // 2️⃣ Insert the first (real) transaction
            $first_category = resolve_transfer_category($conn, $staging['account_id'], $staging['amount'], 0); // 0 = temp
            $first_txn = $conn->prepare("
                INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                VALUES (?, ?, ?, ?, 'transfer', ?, ?)
            ");
            $first_txn->execute([
                $staging['account_id'],
                $staging['date'],
                $staging['description'],
                $staging['amount'],
                null, // We'll update category below once we know counterparty
                $transfer_group_id
            ]);
            $first_txn_id = $conn->lastInsertId();

            // If it's a pair match
            if (str_starts_with($transfer_target, 'staging_')) {
                $counter_id = (int) str_replace('staging_', '', $transfer_target);

                $stmt = $conn->prepare("SELECT * FROM staging_transactions WHERE id = ?");
                $stmt->execute([$counter_id]);
                $counter = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$counter) die("❌ Counterparty staging row not found.");

                // Insert second transaction
                $second_category = resolve_transfer_category($conn, $counter['account_id'], $counter['amount'], $staging['account_id']);
                $conn->prepare("
                    INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                    VALUES (?, ?, ?, ?, 'transfer', ?, ?)
                ")->execute([
                    $counter['account_id'],
                    $counter['date'],
                    $counter['description'],
                    $counter['amount'],
                    $second_category,
                    $transfer_group_id
                ]);

                // Clean up both staging rows
                $conn->prepare("DELETE FROM staging_transactions WHERE id IN (?, ?)")->execute([$staging_id, $counter_id]);

                // Now update category of the first transaction
                $first_cat = resolve_transfer_category($conn, $staging['account_id'], $staging['amount'], $counter['account_id']);
                $conn->prepare("UPDATE transactions SET category_id = ? WHERE id = ?")->execute([$first_cat, $first_txn_id]);

            } elseif (str_starts_with($transfer_target, 'existing_')) {
                $existing_id = (int) str_replace('existing_', '', $transfer_target);

                $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
                $stmt->execute([$existing_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) die("❌ Placeholder transaction not found.");

                // Link existing to group
                $conn->prepare("UPDATE transactions SET transfer_group_id = ? WHERE id = ?")
                     ->execute([$transfer_group_id, $existing_id]);

                // Delete the staging row
                $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);

                // Update first category now that we know the target account
                $first_cat = resolve_transfer_category($conn, $staging['account_id'], $staging['amount'], $existing['account_id']);
                $conn->prepare("UPDATE transactions SET category_id = ? WHERE id = ?")->execute([$first_cat, $first_txn_id]);

            } elseif ($transfer_target === 'one_sided') {
                // Get the opposite account from user
                $linked_account_id = (int) ($_POST['linked_account_id'] ?? 0);
                if (!$linked_account_id) die("❌ Missing linked account for one-sided transfer.");

                $placeholder_amt = -1 * $staging['amount'];
                $placeholder_cat = resolve_transfer_category($conn, $linked_account_id, $placeholder_amt, $staging['account_id']);

                $conn->prepare("
                    INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                    VALUES (?, ?, ?, ?, 'transfer', ?, ?)
                ")->execute([
                    $linked_account_id,
                    $staging['date'],
                    'PLACEHOLDER',
                    $placeholder_amt,
                    $placeholder_cat,
                    $transfer_group_id
                ]);

                // Delete staging
                $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);

                // Update real txn category now that we know target
                $first_cat = resolve_transfer_category($conn, $staging['account_id'], $staging['amount'], $linked_account_id);
                $conn->prepare("UPDATE transactions SET category_id = ? WHERE id = ?")->execute([$first_cat, $first_txn_id]);
            }

            $conn->commit();
            break;
        }

        // REGULAR categorisation
        if ($category_id !== 197) {
            $insert = $conn->prepare("
                INSERT INTO transactions (account_id, date, description, amount, original_ref, category_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $staging['account_id'],
                $staging['date'],
                $staging['description'],
                $staging['amount'],
                substr($staging['original_memo'], 0, 100),
                $category_id
            ]);
            $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
            break;
        }

        // SPLIT categorisation
        $split_categories = $_POST['split_categories'] ?? [];
        $split_amounts = $_POST['split_amounts'] ?? [];

        if (count($split_categories) !== count($split_amounts)) {
            die("❌ Mismatch between split categories and amounts.");
        }

        $total = 0;
        $splits = [];
        for ($i = 0; $i < count($split_categories); $i++) {
            $cat = (int) $split_categories[$i];
            $amt = (float) $split_amounts[$i];
            $total += $amt;
            $splits[] = ['category_id' => $cat, 'amount' => $amt];
        }

        if (abs($total - $staging['amount']) > 0.01) {
            die("❌ Split total ($total) does not match transaction amount ({$staging['amount']}).");
        }

        // Insert parent transaction (with category_id = 197 for split)
        $conn->beginTransaction();

        $insert = $conn->prepare("
            INSERT INTO transactions (account_id, date, description, amount, original_ref, category_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $staging['account_id'],
            $staging['date'],
            $staging['description'],
            $staging['amount'],
            substr($staging['original_memo'], 0, 100),
            197
        ]);
        $transaction_id = $conn->lastInsertId();

        // Insert split components
        $split_stmt = $conn->prepare("
            INSERT INTO transaction_splits (transaction_id, category_id, amount)
            VALUES (?, ?, ?)
        ");

        foreach ($splits as $s) {
            $split_stmt->execute([
                $transaction_id,
                $s['category_id'],
                $s['amount']
            ]);
        }

        // Cleanup staging
        $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
        $conn->commit();
        break;

    // ----------------------------------------
    // ✏️ UPDATE: Manual field edits (optional UI)
    // ----------------------------------------
    case 'update_staging':
        // Future logic to update fields on staging_transactions
        // e.g., date, amount, description, status
        break;

    default:
        die("❌ Invalid action: $action");
}

header("Location: review.php?success=1");
exit;