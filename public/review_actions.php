<?php
require_once '../config/db.php';
require_once '../scripts/payee_matching.php';
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
                p.amount AS predicted_amount,
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

        $expected_transfer_amount_for_account = function (int $txn_account_id, int $from_account, int $to_account, float $predicted_amount): ?float {
            $amount = abs($predicted_amount);
            if ($txn_account_id === $from_account) {
                return -$amount;
            }
            if ($txn_account_id === $to_account) {
                return $amount;
            }
            return null;
        };

        $assert_predicted_transfer_row = function (array $transferRow, int $from_account, int $to_account, float $predicted_amount, string $label) use ($expected_transfer_amount_for_account): void {
            $account_id = (int)($transferRow['account_id'] ?? 0);
            $expected_amount = $expected_transfer_amount_for_account($account_id, $from_account, $to_account, $predicted_amount);

            if ($expected_amount === null) {
                throw new RuntimeException($label . ' account does not match predicted transfer.');
            }

            if (abs((float)($transferRow['amount'] ?? 0) - $expected_amount) >= 0.01) {
                throw new RuntimeException($label . ' amount does not match predicted transfer.');
            }
        };

        $resolve_transfer_category_or_fail = function (int $txn_account_id, int $from_account, int $to_account) use ($transfer_category_for_account): int {
            $category_id = $transfer_category_for_account($txn_account_id, $from_account, $to_account);
            if ($category_id === null) {
                throw new RuntimeException('Transfer category not found for predicted transfer side.');
            }
            return (int)$category_id;
        };

        $mark_prediction_partial = function (int $prediction_id, int $transfer_group_id) use ($conn): void {
            $conn->prepare("
                UPDATE predicted_instances
                SET fulfilled = 2,
                    confirmed = 1,
                    resolution_status = 'open',
                    resolved_at = NULL,
                    resolution_note = NULL,
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
                    resolution_status = 'open,
                    resolved_at = NULL,
                    resolution_note = NULL,
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
                    resolution_status = 'open',
                    resolved_at = NULL,
                    resolution_note = NULL,
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
                $predicted_amount = abs((float)($row['predicted_amount'] ?? 0));
                $predicted_fulfilled = (int) ($row['predicted_fulfilled'] ?? 0);

                if ($from_account <= 0 || $to_account <= 0 || $predicted_amount <= 0) {
                    throw new RuntimeException('Predicted transfer is missing account or amount details.');
                }

                if ($uploaded_account !== $from_account && $uploaded_account !== $to_account) {
                    throw new RuntimeException('Uploaded account does not match predicted transfer.');
                }

                if ($predicted_fulfilled === 1) {
                    throw new RuntimeException('Predicted transfer is already fully fulfilled.');
                }

                $assert_predicted_transfer_row($row, $from_account, $to_account, $predicted_amount, 'Uploaded transfer row');

                // Scenario C: completing a previously partial transfer
                if ($predicted_fulfilled === 2) {
                    $transfer_group_id = (int) ($row['saved_transfer_group_id'] ?? 0);
                    if (!$transfer_group_id) {
                        throw new RuntimeException('Missing saved transfer_group_id for partial predicted transfer.');
                    }

                    $placeholder_stmt = $conn->prepare("
                        SELECT id, account_id, amount
                        FROM transactions
                        WHERE transfer_group_id = ?
                          AND description = 'PLACEHOLDER'
                        FOR UPDATE
                    ");
                    $placeholder_stmt->execute([$transfer_group_id]);
                    $placeholders = $placeholder_stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($placeholders) !== 1) {
                        throw new RuntimeException('Partial predicted transfer must contain exactly one placeholder.');
                    }

                    $placeholder = $placeholders[0];
                    if ((int)$placeholder['account_id'] !== $uploaded_account) {
                        throw new RuntimeException('Uploaded transfer row does not match the partial transfer placeholder account.');
                    }
                    if (abs((float)$placeholder['amount'] - (float)$row['amount']) >= 0.01) {
                        throw new RuntimeException('Uploaded transfer row does not match the partial transfer placeholder amount.');
                    }

                    $cat = $resolve_transfer_category_or_fail($uploaded_account, $from_account, $to_account);

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

                    $conn->prepare("DELETE FROM transactions WHERE id = ?")
                        ->execute([(int)$placeholder['id']]);

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
                    $assert_predicted_transfer_row($other, $from_account, $to_account, $predicted_amount, 'Other staging row');

                    if ($other_account === $uploaded_account) {
                        throw new RuntimeException('Both staging rows are for the same side of the predicted transfer.');
                    }

                    $cat1 = $resolve_transfer_category_or_fail($uploaded_account, $from_account, $to_account);
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

                    $cat2 = $resolve_transfer_category_or_fail($other_account, $from_account, $to_account);
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

                $real_cat = $resolve_transfer_category_or_fail($uploaded_account, $from_account, $to_account);
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
                $placeholder_cat = $resolve_transfer_category_or_fail($counterparty_account, $from_account, $to_account);

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

                $resolved_payee_id = resolve_payee_id_for_description($conn, (string)($row['description'] ?? ''));

                $insert = $conn->prepare("
                    INSERT INTO transactions
                        (account_id, date, description, amount, original_ref, category_id, predicted_transaction_id, payee_id)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([
                    $row['account_id'],
                    $row['date'],
                    $row['description'],
                    $row['amount'],
                    substr((string) ($row['original_memo'] ?? ''), 0, 100),
                    $row['instance_category_id'] ?? null,
                    $row['predicted_transaction_id'],
                    $resolved_payee_id
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

        $dupStmt = $conn->prepare("SELECT date, description FROM staging_transactions WHERE id = ? LIMIT 1");
        $dupStmt->execute([$staging_id]);
        $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);

        if (!$dupRow) {
            die("❌ Staging transaction not found.");
        }

        $resolved_payee_id = resolve_payee_id_for_description($conn, (string)($dupRow['description'] ?? ''));

        $conn->prepare("
            UPDATE transactions
            SET date = ?, description = ?, payee_id = ?
            WHERE id = ?
        ")->execute([
            $dupRow['date'],
            $dupRow['description'],
            $resolved_payee_id,
            $matched_id
        ]);

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
            $transfer_target = (string) ($_POST['transfer_target'] ?? '');
            if ($transfer_target === '') {
                die("❌ Missing transfer target.");
            }

            try {
                $conn->beginTransaction();

                $resolve_transfer_category = function (int $txn_account_id, float $amount, int $linked_account_id) use ($conn): ?int {
                    $direction = $amount < 0 ? 'Transfer To :' : 'Transfer From :';
                    $stmt = $conn->prepare("
                        SELECT id
                        FROM categories
                        WHERE type = 'transfer'
                          AND linked_account_id = ?
                          AND name LIKE ?
                        LIMIT 1
                    ");
                    $stmt->execute([$linked_account_id, $direction . '%']);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return isset($result['id']) ? (int)$result['id'] : null;
                };

                $resolve_transfer_category_or_fail = function (int $txn_account_id, float $amount, int $linked_account_id) use ($resolve_transfer_category): int {
                    $category_id = $resolve_transfer_category($txn_account_id, $amount, $linked_account_id);
                    if ($category_id === null) {
                        throw new RuntimeException('Unable to resolve transfer category.');
                    }
                    return $category_id;
                };

                $assert_transfer_pair = function (array $first, array $second): void {
                    if ((int)$first['account_id'] === (int)$second['account_id']) {
                        throw new RuntimeException('Transfer sides must use different accounts.');
                    }

                    if (abs((float)$first['amount'] + (float)$second['amount']) > 0.01) {
                        throw new RuntimeException('Transfer sides must balance to zero.');
                    }
                };

                if (str_starts_with($transfer_target, 'staging_')) {
                    $counter_id = (int) str_replace('staging_', '', $transfer_target);
                    if ($counter_id <= 0 || $counter_id === $staging_id) {
                        throw new RuntimeException('Invalid counterparty staging row.');
                    }

                    $stmt = $conn->prepare("SELECT * FROM staging_transactions WHERE id = ?");
                    $stmt->execute([$counter_id]);
                    $counter = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$counter) {
                        throw new RuntimeException('Counterparty staging row not found.');
                    }

                    $assert_transfer_pair($staging, $counter);
                    $first_cat = $resolve_transfer_category_or_fail(
                        (int)$staging['account_id'],
                        (float)$staging['amount'],
                        (int)$counter['account_id']
                    );
                    $second_cat = $resolve_transfer_category_or_fail(
                        (int)$counter['account_id'],
                        (float)$counter['amount'],
                        (int)$staging['account_id']
                    );

                    $conn->prepare("INSERT INTO transfer_groups (description) VALUES ('Manual transfer match')")->execute();
                    $transfer_group_id = (int)$conn->lastInsertId();

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', ?, ?)
                    ")->execute([
                        $staging['account_id'],
                        $staging['date'],
                        $staging['description'],
                        $staging['amount'],
                        $first_cat,
                        $transfer_group_id
                    ]);

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', ?, ?)
                    ")->execute([
                        $counter['account_id'],
                        $counter['date'],
                        $counter['description'],
                        $counter['amount'],
                        $second_cat,
                        $transfer_group_id
                    ]);

                    $conn->prepare("DELETE FROM staging_transactions WHERE id IN (?, ?)")->execute([$staging_id, $counter_id]);
                } elseif (str_starts_with($transfer_target, 'existing_')) {
                    $existing_id = (int) str_replace('existing_', '', $transfer_target);
                    if ($existing_id <= 0) {
                        throw new RuntimeException('Invalid placeholder transaction.');
                    }

                    $stmt = $conn->prepare("
                        SELECT *
                        FROM transactions
                        WHERE id = ?
                          AND description = 'PLACEHOLDER'
                          AND transfer_group_id IS NOT NULL
                        FOR UPDATE
                    ");
                    $stmt->execute([$existing_id]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) {
                        throw new RuntimeException('Placeholder transaction not found.');
                    }

                    if ((int)$existing['account_id'] !== (int)$staging['account_id']) {
                        throw new RuntimeException('Selected placeholder account does not match uploaded transaction account.');
                    }

                    if (abs((float)$existing['amount'] - (float)$staging['amount']) > 0.01) {
                        throw new RuntimeException('Selected placeholder amount does not match uploaded transaction amount.');
                    }

                    $existing_group_id = (int)$existing['transfer_group_id'];
                    $counter_stmt = $conn->prepare("
                        SELECT *
                        FROM transactions
                        WHERE transfer_group_id = ?
                          AND id != ?
                        ORDER BY id ASC
                    ");
                    $counter_stmt->execute([$existing_group_id, $existing_id]);
                    $counterparties = $counter_stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($counterparties) !== 1) {
                        throw new RuntimeException('Placeholder transfer group must contain exactly one counterparty transaction.');
                    }

                    $counterparty = $counterparties[0];
                    $assert_transfer_pair($staging, $counterparty);

                    $first_cat = $resolve_transfer_category_or_fail(
                        (int)$staging['account_id'],
                        (float)$staging['amount'],
                        (int)$counterparty['account_id']
                    );
                    $counterparty_cat = $resolve_transfer_category_or_fail(
                        (int)$counterparty['account_id'],
                        (float)$counterparty['amount'],
                        (int)$staging['account_id']
                    );

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', ?, ?)
                    ")->execute([
                        $staging['account_id'],
                        $staging['date'],
                        $staging['description'],
                        $staging['amount'],
                        $first_cat,
                        $existing_group_id
                    ]);

                    $conn->prepare("UPDATE transactions SET category_id = ? WHERE id = ?")
                        ->execute([$counterparty_cat, (int)$counterparty['id']]);

                    $conn->prepare("DELETE FROM transactions WHERE id = ? AND description = 'PLACEHOLDER'")
                        ->execute([$existing_id]);

                    $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
                } elseif ($transfer_target === 'one_sided') {
                    $linked_account_id = (int) ($_POST['linked_account_id'] ?? 0);
                    if ($linked_account_id <= 0) {
                        throw new RuntimeException('Missing linked account for one-sided transfer.');
                    }

                    if ($linked_account_id === (int)$staging['account_id']) {
                        throw new RuntimeException('One-sided transfer target must be a different account.');
                    }

                    $placeholder_amt = -1 * (float)$staging['amount'];
                    $first_cat = $resolve_transfer_category_or_fail(
                        (int)$staging['account_id'],
                        (float)$staging['amount'],
                        $linked_account_id
                    );
                    $placeholder_cat = $resolve_transfer_category_or_fail(
                        $linked_account_id,
                        $placeholder_amt,
                        (int)$staging['account_id']
                    );

                    $conn->prepare("INSERT INTO transfer_groups (description) VALUES ('Manual transfer match')")->execute();
                    $transfer_group_id = (int)$conn->lastInsertId();

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', ?, ?)
                    ")->execute([
                        $staging['account_id'],
                        $staging['date'],
                        $staging['description'],
                        $staging['amount'],
                        $first_cat,
                        $transfer_group_id
                    ]);

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, 'PLACEHOLDER', ?, 'transfer', ?, ?)
                    ")->execute([
                        $linked_account_id,
                        $staging['date'],
                        $placeholder_amt,
                        $placeholder_cat,
                        $transfer_group_id
                    ]);

                    $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
                } else {
                    throw new RuntimeException('Invalid transfer target.');
                }

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                die("❌ Transfer failed: " . $e->getMessage());
            }

            break;
        }

        // REGULAR categorisation
        if ($category_id !== 197) {
            $resolved_payee_id = resolve_payee_id_for_description($conn, (string)($staging['description'] ?? ''));

            $insert = $conn->prepare("
                INSERT INTO transactions (account_id, date, description, amount, original_ref, category_id, payee_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $staging['account_id'],
                $staging['date'],
                $staging['description'],
                $staging['amount'],
                substr($staging['original_memo'], 0, 100),
                $category_id,
                $resolved_payee_id
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

        $resolved_payee_id = resolve_payee_id_for_description($conn, (string)($staging['description'] ?? ''));

        $insert = $conn->prepare("
            INSERT INTO transactions (account_id, date, description, amount, original_ref, category_id, payee_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $staging['account_id'],
            $staging['date'],
            $staging['description'],
            $staging['amount'],
            substr($staging['original_memo'], 0, 100),
            197,
            $resolved_payee_id
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