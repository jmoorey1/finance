<?php
require_once '../config/db.php';
require_once '../scripts/payee_matching.php';
require_once '../scripts/lib/split_transaction_helpers.php';
require_once '../scripts/lib/transfer_group_helpers.php';
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
                p.prediction_type,
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
        $category_type = (string)($row['prediction_type'] ?? ($cat_row['type'] ?? ''));

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

                    $ins = $conn->prepare("
                        INSERT INTO transactions
                            (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                        VALUES
                            (?, ?, ?, ?, 'transfer', NULL, ?, ?)
                    ");
                    $ins->execute([
                        $uploaded_account,
                        $row['date'],
                        $row['description'],
                        $row['amount'],
                        $transfer_group_id,
                        $row['predicted_transaction_id']
                    ]);

                    $conn->prepare("DELETE FROM transactions WHERE id = ?")
                        ->execute([(int)$placeholder['id']]);

                    finance_update_transfer_group_metadata(
                        $conn,
                        $transfer_group_id,
                        null,
                        $from_account,
                        $to_account,
                        $predicted_amount,
                        (string)$row['date'],
                        'complete'
                    );

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
                    $transfer_group_id = finance_create_transfer_group(
                        $conn,
                        'Predicted transfer match',
                        $from_account,
                        $to_account,
                        $predicted_amount,
                        ($uploaded_account === $from_account) ? (string)$row['date'] : (string)$other['date'],
                        'complete'
                    );

                    $other_account = (int) $other['account_id'];
                    $assert_predicted_transfer_row($other, $from_account, $to_account, $predicted_amount, 'Other staging row');

                    if ($other_account === $uploaded_account) {
                        throw new RuntimeException('Both staging rows are for the same side of the predicted transfer.');
                    }

                    $conn->prepare("
                        INSERT INTO transactions
                            (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                        VALUES
                            (?, ?, ?, ?, 'transfer', NULL, ?, ?)
                    ")->execute([
                        $uploaded_account,
                        $row['date'],
                        $row['description'],
                        $row['amount'],
                        $transfer_group_id,
                        $row['predicted_transaction_id']
                    ]);

                    $conn->prepare("
                        INSERT INTO transactions
                            (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                        VALUES
                            (?, ?, ?, ?, 'transfer', NULL, ?, ?)
                    ")->execute([
                        $other_account,
                        $other['date'],
                        $other['description'],
                        $other['amount'],
                        $transfer_group_id,
                        $row['predicted_transaction_id']
                    ]);

                    $conn->prepare("DELETE FROM staging_transactions WHERE id IN (?, ?)")->execute([$staging_id, (int) $other['id']]);
                    $mark_prediction_fulfilled_transfer($predicted_id, $transfer_group_id);

                    $conn->commit();
                    break;
                }

                // Scenario B: only one side present so create placeholder and mark partial
                $transfer_group_id = finance_create_transfer_group(
                    $conn,
                    'Predicted transfer (partial)',
                    $from_account,
                    $to_account,
                    $predicted_amount,
                    (string)$row['date'],
                    'partial'
                );

                $counterparty_account = ($uploaded_account === $from_account) ? $to_account : $from_account;

                $conn->prepare("
                    INSERT INTO transactions
                        (account_id, date, description, amount, type, category_id, transfer_group_id, predicted_transaction_id)
                    VALUES
                        (?, ?, ?, ?, 'transfer', NULL, ?, ?)
                ")->execute([
                    $uploaded_account,
                    $row['date'],
                    $row['description'],
                    $row['amount'],
                    $transfer_group_id,
                    $row['predicted_transaction_id']
                ]);

                $placeholder_amt = -1 * (float) $row['amount'];

                $conn->prepare("
                    INSERT INTO transactions
                        (account_id, date, description, amount, type, category_id, transfer_group_id)
                    VALUES
                        (?, ?, 'PLACEHOLDER', ?, 'transfer', NULL, ?)
                ")->execute([
                    $counterparty_account,
                    $row['date'],
                    $placeholder_amt,
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
        $posted_matched_id = (int) ($_POST['matched_transaction_id'] ?? 0);

        if ($posted_matched_id <= 0) {
            die("❌ Missing matched transaction.");
        }

        $canonical_text = function (?string $value): string {
            $text = strtolower(trim((string)$value));
            $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
            return trim(preg_replace('/\s+/', ' ', $text) ?? '');
        };

        $descriptions_similar = function (?string $first, ?string $second) use ($canonical_text): bool {
            $canonical_first = $canonical_text($first);
            $canonical_second = $canonical_text($second);

            if ($canonical_first === '' || $canonical_second === '') {
                return false;
            }

            if ($canonical_first === $canonical_second) {
                return true;
            }

            $minimum_prefix_length = 8;
            if (
                strlen($canonical_first) >= $minimum_prefix_length &&
                strlen($canonical_second) >= $minimum_prefix_length &&
                substr($canonical_first, 0, $minimum_prefix_length) === substr($canonical_second, 0, $minimum_prefix_length)
            ) {
                return true;
            }

            if (strlen($canonical_first) <= strlen($canonical_second)) {
                $shorter = $canonical_first;
                $longer = $canonical_second;
            } else {
                $shorter = $canonical_second;
                $longer = $canonical_first;
            }

            return strlen($shorter) >= 8 && str_contains($longer, $shorter);
        };

        try {
            $conn->beginTransaction();

            $dupStmt = $conn->prepare("
                SELECT id, account_id, date, description, amount, status, matched_transaction_id
                FROM staging_transactions
                WHERE id = ?
                FOR UPDATE
            ");
            $dupStmt->execute([$staging_id]);
            $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);

            if (!$dupRow) {
                throw new RuntimeException('Staging transaction not found.');
            }

            if (($dupRow['status'] ?? '') !== 'potential_duplicate') {
                throw new RuntimeException('Staging transaction is not marked as a potential duplicate.');
            }

            $stored_matched_id = (int)($dupRow['matched_transaction_id'] ?? 0);
            if ($stored_matched_id <= 0) {
                throw new RuntimeException('Staging transaction has no stored duplicate match.');
            }

            if ($posted_matched_id !== $stored_matched_id) {
                throw new RuntimeException('Posted duplicate match does not match the stored review candidate.');
            }

            $matchedStmt = $conn->prepare("
                SELECT id, account_id, date, description, amount
                FROM transactions
                WHERE id = ?
                FOR UPDATE
            ");
            $matchedStmt->execute([$stored_matched_id]);
            $matchedRow = $matchedStmt->fetch(PDO::FETCH_ASSOC);

            if (!$matchedRow) {
                throw new RuntimeException('Matched transaction not found.');
            }

            if ((int)$matchedRow['account_id'] !== (int)$dupRow['account_id']) {
                throw new RuntimeException('Matched transaction account does not match staged transaction account.');
            }

            if (abs((float)$matchedRow['amount'] - (float)$dupRow['amount']) >= 0.01) {
                throw new RuntimeException('Matched transaction amount does not match staged transaction amount.');
            }

            $staging_date = new DateTimeImmutable((string)$dupRow['date']);
            $matched_date = new DateTimeImmutable((string)$matchedRow['date']);
            $days_apart = $staging_date->diff($matched_date)->days;
            if ($days_apart === false || $days_apart > 3) {
                throw new RuntimeException('Matched transaction date is outside the duplicate review window.');
            }

            if (!$descriptions_similar((string)($dupRow['description'] ?? ''), (string)($matchedRow['description'] ?? ''))) {
                throw new RuntimeException('Matched transaction description is no longer similar to the staged transaction.');
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
                $stored_matched_id
            ]);

            $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            die("❌ Duplicate confirmation failed: " . $e->getMessage());
        }

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
        $categoryRaw = trim((string)($_POST['category_id'] ?? ''));
        $isSplitCategorySelection = finance_is_split_sentinel($categoryRaw);
        $isTransferCategorySelection = ($categoryRaw === '-1');
        $category_id = (!$isSplitCategorySelection && !$isTransferCategorySelection && $categoryRaw !== '')
            ? (int)$categoryRaw
            : 0;

        // Get the full staging transaction row
        $stmt = $conn->prepare("SELECT * FROM staging_transactions WHERE id = ?");
        $stmt->execute([$staging_id]);
        $staging = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staging) {
            die("❌ Staging transaction not found.");
        }

        $resolve_account_type_or_fail = function (int $account_id) use ($conn): string {
            $stmt = $conn->prepare("
                SELECT type
                FROM accounts
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$account_id]);
            $account_type = $stmt->fetchColumn();

            if ($account_type === false) {
                throw new RuntimeException('Staging transaction account does not exist.');
            }

            return (string)$account_type;
        };

        $transaction_type_for_amount = function (string $account_type, float $amount): string {
            if (abs($amount) < 0.01) {
                throw new RuntimeException('Transaction amount cannot be zero.');
            }

            return match ($account_type) {
                'credit' => ($amount > 0 ? 'credit' : 'charge'),
                default => ($amount > 0 ? 'deposit' : 'withdrawal'),
            };
        };

        $resolve_income_expense_category_or_fail = function (int $category_id) use ($conn): int {
            if ($category_id <= 0) {
                throw new RuntimeException('Category is required.');
            }

            $stmt = $conn->prepare("
                SELECT id
                FROM categories
                WHERE id = ?
                  AND type IN ('income', 'expense')
                LIMIT 1
            ");
            $stmt->execute([$category_id]);
            $resolved_category_id = $stmt->fetchColumn();

            if ($resolved_category_id === false) {
                throw new RuntimeException('Selected category must be an income or expense category.');
            }

            return (int)$resolved_category_id;
        };

        // Transfer Pairing or Placeholder
        if ($isTransferCategorySelection) {
            $transfer_target = (string) ($_POST['transfer_target'] ?? '');
            if ($transfer_target === '') {
                die("❌ Missing transfer target.");
            }

            try {
                $conn->beginTransaction();

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

                    $metadata_from_row = ((float)$staging['amount'] < 0) ? $staging : $counter;
                    $metadata_to_row = ((float)$staging['amount'] < 0) ? $counter : $staging;

                    $transfer_group_id = finance_create_transfer_group(
                        $conn,
                        'Manual transfer match',
                        (int)$metadata_from_row['account_id'],
                        (int)$metadata_to_row['account_id'],
                        abs((float)$metadata_from_row['amount']),
                        (string)$metadata_from_row['date'],
                        'complete'
                    );

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', NULL, ?)
                    ")->execute([
                        $staging['account_id'],
                        $staging['date'],
                        $staging['description'],
                        $staging['amount'],
                        $transfer_group_id
                    ]);

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', NULL, ?)
                    ")->execute([
                        $counter['account_id'],
                        $counter['date'],
                        $counter['description'],
                        $counter['amount'],
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

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', NULL, ?)
                    ")->execute([
                        $staging['account_id'],
                        $staging['date'],
                        $staging['description'],
                        $staging['amount'],
                        $existing_group_id
                    ]);

                    $conn->prepare("DELETE FROM transactions WHERE id = ? AND description = 'PLACEHOLDER'")
                        ->execute([$existing_id]);

                    $metadata_from_row = ((float)$staging['amount'] < 0) ? $staging : $counterparty;
                    $metadata_to_row = ((float)$staging['amount'] < 0) ? $counterparty : $staging;

                    finance_update_transfer_group_metadata(
                        $conn,
                        $existing_group_id,
                        null,
                        (int)$metadata_from_row['account_id'],
                        (int)$metadata_to_row['account_id'],
                        abs((float)$metadata_from_row['amount']),
                        (string)$metadata_from_row['date'],
                        'complete'
                    );

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

                    $metadata_from_account_id = ((float)$staging['amount'] < 0)
                        ? (int)$staging['account_id']
                        : $linked_account_id;
                    $metadata_to_account_id = ((float)$staging['amount'] < 0)
                        ? $linked_account_id
                        : (int)$staging['account_id'];

                    $transfer_group_id = finance_create_transfer_group(
                        $conn,
                        'Manual transfer match',
                        $metadata_from_account_id,
                        $metadata_to_account_id,
                        abs((float)$staging['amount']),
                        (string)$staging['date'],
                        'partial'
                    );

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, ?, ?, 'transfer', NULL, ?)
                    ")->execute([
                        $staging['account_id'],
                        $staging['date'],
                        $staging['description'],
                        $staging['amount'],
                        $transfer_group_id
                    ]);

                    $conn->prepare("
                        INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                        VALUES (?, ?, 'PLACEHOLDER', ?, 'transfer', NULL, ?)
                    ")->execute([
                        $linked_account_id,
                        $staging['date'],
                        $placeholder_amt,
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
        if (!$isSplitCategorySelection) {
            try {
                $account_type = $resolve_account_type_or_fail((int)$staging['account_id']);
                $resolved_category_id = $resolve_income_expense_category_or_fail($category_id);
                $amount = round((float)$staging['amount'], 2);
                $transaction_type = $transaction_type_for_amount($account_type, $amount);
                $resolved_payee_id = resolve_payee_id_for_description($conn, (string)($staging['description'] ?? ''));

                $conn->beginTransaction();

                $insert = $conn->prepare("
                    INSERT INTO transactions (account_id, date, description, amount, type, original_ref, category_id, payee_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([
                    $staging['account_id'],
                    $staging['date'],
                    $staging['description'],
                    $amount,
                    $transaction_type,
                    substr((string)($staging['original_memo'] ?? ''), 0, 100),
                    $resolved_category_id,
                    $resolved_payee_id
                ]);
                $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                die("❌ Categorisation failed: " . $e->getMessage());
            }

            break;
        }

        // SPLIT categorisation
        $split_categories = $_POST['split_categories'] ?? [];
        $split_amounts = $_POST['split_amounts'] ?? [];

        if (!is_array($split_categories) || !is_array($split_amounts)) {
            die("❌ Split categories and amounts are required.");
        }

        if (count($split_categories) !== count($split_amounts)) {
            die("❌ Mismatch between split categories and amounts.");
        }

        if (count($split_categories) === 0) {
            die("❌ At least one split line is required.");
        }

        try {
            $account_type = $resolve_account_type_or_fail((int)$staging['account_id']);
            $parent_amount = round((float)$staging['amount'], 2);
            $transaction_type = $transaction_type_for_amount($account_type, $parent_amount);
            $resolved_payee_id = resolve_payee_id_for_description($conn, (string)($staging['description'] ?? ''));

            $total = 0.0;
            $splits = [];
            for ($i = 0; $i < count($split_categories); $i++) {
                $cat = $resolve_income_expense_category_or_fail((int)$split_categories[$i]);
                $raw_amount = trim((string)$split_amounts[$i]);

                if ($raw_amount === '' || !is_numeric($raw_amount)) {
                    throw new RuntimeException('Each split amount must be numeric.');
                }

                $amt = round((float)$raw_amount, 2);
                if (abs($amt) < 0.01) {
                    throw new RuntimeException('Split amounts cannot be zero.');
                }

                $total += $amt;
                $splits[] = ['category_id' => $cat, 'amount' => $amt];
            }

            if (abs(round($total, 2) - $parent_amount) >= 0.01) {
                throw new RuntimeException("Split total ($total) does not match transaction amount ({$staging['amount']}).");
            }

            $conn->beginTransaction();

            $insert = $conn->prepare("
                INSERT INTO transactions (account_id, date, description, amount, type, original_ref, category_id, payee_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $staging['account_id'],
                $staging['date'],
                $staging['description'],
                $parent_amount,
                $transaction_type,
                substr((string)($staging['original_memo'] ?? ''), 0, 100),
                null,
                $resolved_payee_id
            ]);
            $transaction_id = (int)$conn->lastInsertId();

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

            $conn->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$staging_id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            die("❌ Split categorisation failed: " . $e->getMessage());
        }
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