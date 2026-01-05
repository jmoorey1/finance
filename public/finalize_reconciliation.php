<?php
require_once '../config/db.php';

function adjust_to_next_business_day(DateTime $dt): DateTime {
    // Weekend-only (Sat/Sun).
    while (in_array((int)$dt->format('N'), [6, 7], true)) {
        $dt->modify('+1 day');
    }
    return $dt;
}

function compute_payment_due_date(DateTime $statementDate, int $statementDay, int $paymentDay): DateTime {
    $year = (int)$statementDate->format('Y');
    $month = (int)$statementDate->format('m');

    if ($paymentDay > $statementDay) {
        $due = new DateTime();
        $due->setDate($year, $month, $paymentDay);
    } else {
        $next = (clone $statementDate)->modify('first day of next month');
        $due = new DateTime();
        $due->setDate((int)$next->format('Y'), (int)$next->format('m'), $paymentDay);
    }

    return adjust_to_next_business_day($due);
}

function calc_min_payment(float $statementBalance, ?float $floor, ?float $percent): float {
    $bal = max(0.0, $statementBalance);
    $floorVal = max(0.0, (float)($floor ?? 0.0));
    $pctVal = max(0.0, (float)($percent ?? 0.0));

    $pctAmount = ($pctVal / 100.0) * $bal;
    $min = max($floorVal, $pctAmount);
    $min = min($min, $bal);

    return round($min, 2);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['statement_id'])) {
    header('Location: statements.php');
    exit;
}

$statement_id = (int) $_POST['statement_id'];
$transaction_ids = $_POST['transaction_ids'] ?? [];

if (empty($transaction_ids)) {
    header('Location: statements.php?error=no_transactions');
    exit;
}

// 1️⃣ Load statement details
$stmt = $pdo->prepare("
    SELECT
        s.*,
        a.name AS account_name,
        a.type AS account_type,
        a.statement_day,
        a.payment_day,
        a.id AS account_id,
        a.paid_from,
        a.repayment_method,
        a.fixed_payment_amount,
        a.min_payment_floor,
        a.min_payment_percent,
        a.min_payment_calc
    FROM statements s
    JOIN accounts a ON s.account_id = a.id
    WHERE s.id = ?
");
$stmt->execute([$statement_id]);
$statement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$statement) {
    header('Location: statements.php?error=invalid_statement');
    exit;
}

$pdo->beginTransaction();

try {
    // 2️⃣ Mark selected transactions as reconciled and linked to statement
    $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));

    $sql = "
        UPDATE transactions
        SET reconciled = 1, statement_id = ?
        WHERE id IN ($placeholders)
    ";
    $params = array_merge([$statement_id], $transaction_ids);
    $pdo->prepare($sql)->execute($params);

    // 3️⃣ Handle credit card repayment update (full/min/fixed)
    $confirmed_payment_date = null;

    if ($statement['account_type'] === 'credit') {
        // Compute/ensure payment due date
        $payment_date_str = $statement['payment_due_date'];

        if (!$payment_date_str && !empty($statement['statement_day']) && !empty($statement['payment_day'])) {
            $stmtDt = new DateTime($statement['statement_date']);
            $payment_date = compute_payment_due_date($stmtDt, (int)$statement['statement_day'], (int)$statement['payment_day']);
            $payment_date_str = $payment_date->format('Y-m-d');

            $pdo->prepare("
                UPDATE statements
                SET payment_due_date = ?
                WHERE id = ?
            ")->execute([$payment_date_str, $statement_id]);
        } else {
            $payment_date = new DateTime($payment_date_str ?: $statement['statement_date']);
        }

        // Find the transfer category for "Transfer To : <Card>"
        $catStmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE type = 'transfer'
              AND parent_id = 275
              AND linked_account_id = ?
              AND name LIKE 'Transfer To : %'
            LIMIT 1
        ");
        $catStmt->execute([(int)$statement['account_id']]);
        $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
        $category_id = $catRow ? (int)$catRow['id'] : null;

        // Calculate required repayment amount based on method
        $statement_balance = abs((float)$statement['end_balance']);
        $repayment_method = $statement['repayment_method'] ?? 'full';

        $repayment_amount = null;
        $minimum_payment_due = null;

        if ($repayment_method === 'full') {
            $repayment_amount = round($statement_balance, 2);
        } elseif ($repayment_method === 'fixed') {
            $fixed = (float)($statement['fixed_payment_amount'] ?? 0.0);
            $repayment_amount = round(min($fixed, $statement_balance), 2);
        } elseif ($repayment_method === 'minimum') {
            $repayment_amount = calc_min_payment($statement_balance, $statement['min_payment_floor'] ?? null, $statement['min_payment_percent'] ?? null);
            $minimum_payment_due = $repayment_amount;
        } else {
            // Fallback
            $repayment_amount = round($statement_balance, 2);
        }

        // Persist minimum_payment_due if applicable
        if ($minimum_payment_due !== null) {
            $pdo->prepare("
                UPDATE statements
                SET minimum_payment_due = ?
                WHERE id = ?
            ")->execute([$minimum_payment_due, $statement_id]);
        }

        // If we don't have paid_from or category_id, we can't link a repayment prediction
        if (!empty($statement['paid_from']) && $category_id) {
            // Try to find an existing predicted instance (within ±3 days) for this repayment
            $find = $pdo->prepare("
                SELECT id, confirmed, scheduled_date, statement_id
                FROM predicted_instances
                WHERE from_account_id = ?
                  AND to_account_id = ?
                  AND category_id = ?
                  AND ABS(DATEDIFF(scheduled_date, ?)) <= 3
                ORDER BY ABS(DATEDIFF(scheduled_date, ?)) ASC
                LIMIT 1
            ");
            $find->execute([
                (int)$statement['paid_from'],
                (int)$statement['account_id'],
                (int)$category_id,
                $payment_date_str,
                $payment_date_str
            ]);
            $predicted = $find->fetch(PDO::FETCH_ASSOC);

            if ($predicted) {
                if ((int)$predicted['confirmed'] === 1) {
                    // Respect an already-confirmed prediction; just backfill statement_id if missing
                    if (empty($predicted['statement_id'])) {
                        $pdo->prepare("
                            UPDATE predicted_instances
                            SET statement_id = ?
                            WHERE id = ?
                        ")->execute([$statement_id, (int)$predicted['id']]);
                    }
                    $confirmed_payment_date = $predicted['scheduled_date'];
                } else {
                    // Update and confirm
                    $pdo->prepare("
                        UPDATE predicted_instances
                        SET amount = ?, confirmed = 1, statement_id = ?, scheduled_date = ?
                        WHERE id = ?
                    ")->execute([
                        $repayment_amount,
                        $statement_id,
                        $payment_date_str,
                        (int)$predicted['id']
                    ]);
                    $confirmed_payment_date = $payment_date_str;
                }
            } else {
                // No existing prediction found — insert & confirm one now
                $pdo->prepare("
                    INSERT INTO predicted_instances
                        (scheduled_date, from_account_id, to_account_id, category_id, amount, description, confirmed, statement_id)
                    VALUES
                        (?, ?, ?, ?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        amount = IF(confirmed = 1, amount, VALUES(amount)),
                        statement_id = COALESCE(statement_id, VALUES(statement_id)),
                        confirmed = IF(confirmed = 1, confirmed, 1)
                ")->execute([
                    $payment_date_str,
                    (int)$statement['paid_from'],
                    (int)$statement['account_id'],
                    (int)$category_id,
                    $repayment_amount,
                    $statement['account_name'],
                    $statement_id
                ]);
                $confirmed_payment_date = $payment_date_str;
            }
        }
    }

    // 4️⃣ Update statement as reconciled
    $pdo->prepare("
        UPDATE statements
        SET reconciled = 1
        WHERE id = ?
    ")->execute([$statement_id]);

    $pdo->commit();

    // 5️⃣ Redirect back to statements.php with success message
    if ($confirmed_payment_date) {
        header('Location: statements.php?success=1&confirmed_payment_date=' . urlencode($confirmed_payment_date));
    } else {
        header('Location: statements.php?success=1');
    }
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: statements.php?error=' . urlencode($e->getMessage()));
    exit;
}
