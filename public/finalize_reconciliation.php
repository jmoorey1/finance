<?php
require_once '../config/db.php';

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
    SELECT s.*, a.type AS account_type, a.statement_day, a.payment_day, a.id as account_id, a.paid_from
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

// 2️⃣ Mark selected transactions as reconciled and linked to statement
$placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
$params = array_merge($transaction_ids, array_fill(0, count($transaction_ids), $statement_id));

$pdo->prepare("
    UPDATE transactions
    SET reconciled = 1, statement_id = ?
    WHERE id IN ($placeholders)
")->execute(array_merge([$statement_id], $transaction_ids));

// 3️⃣ Handle credit card repayment update if needed
$confirmed_payment_date = null;

if ($statement['account_type'] === 'credit') {
    $payment_day = (int) $statement['payment_day'];
    $statement_day = (int) $statement['statement_day'];
    $statement_date = new DateTime($statement['statement_date']);

    // Decide whether repayment falls this month or next
    if ($payment_day > $statement_day) {
        $payment_date = (clone $statement_date)->setDate(
            (int) $statement_date->format('Y'),
            (int) $statement_date->format('m'),
            $payment_day
        );
    } else {
        $payment_date = (clone $statement_date)->modify('+1 month')->setDate(
            (int) $statement_date->modify('+1 month')->format('Y'),
            (int) $statement_date->format('m'),
            $payment_day
        );
    }

    // Find the predicted_instance
    $find_payment = $pdo->prepare("
        SELECT id FROM predicted_instances
        WHERE from_account_id = ?
          AND to_account_id = ?
          AND ABS(DATEDIFF(scheduled_date, ?)) <= 3
          AND confirmed = 0
        LIMIT 1
    ");
    $find_payment->execute([
        $statement['paid_from'], // Paid from this account
        $statement['account_id'], // To this credit card account
        $payment_date->format('Y-m-d')
    ]);

    $predicted = $find_payment->fetch(PDO::FETCH_ASSOC);

    if ($predicted) {
        // Update the predicted instance
        $update_payment = $pdo->prepare("
            UPDATE predicted_instances
            SET amount = ?, confirmed = 1
            WHERE id = ?
        ");
        $update_payment->execute([
            abs($statement['end_balance']), // Use absolute value
            $predicted['id']
        ]);

        $confirmed_payment_date = $payment_date->format('Y-m-d');
    }
}

// 4️⃣ Update statement as reconciled

$pdo->prepare("
    UPDATE statements
    SET reconciled = 1
    WHERE id = ? 
")->execute([$statement_id]);

// 5️⃣ Redirect back to statements.php with success message
if ($confirmed_payment_date) {
    header('Location: statements.php?success=1&confirmed_payment_date=' . urlencode($confirmed_payment_date));
} else {
    header('Location: statements.php?success=1');
}
exit;
?>
