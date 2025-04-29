<?php
require_once '../config/db.php';
include '../layout/header.php';

$statement_id = (int) ($_GET['id'] ?? 0);

// Load statement details
$stmt = $pdo->prepare("
    SELECT s.*, a.name AS account_name, a.type as account_type
    FROM statements s
    JOIN accounts a ON s.account_id = a.id
    WHERE s.id = ?
");
$stmt->execute([$statement_id]);
$statement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$statement) {
    echo "<p>❌ Invalid statement ID.</p>";
    include '../layout/footer.php';
    exit;
}

// Load associated transactions
$is_credit = ($statement['account_type'] === 'credit');
$is_current = ($statement['account_type'] === 'current');
$is_savings = ($statement['account_type'] === 'savings');
$txns = $pdo->prepare("
    SELECT t.date, t.description, (CASE WHEN a.type = 'credit' THEN -t.amount ELSE t.amount END) AS amount
    FROM transactions t
	join accounts a on t.account_id = a.id
    WHERE t.statement_id = ?
    ORDER BY t.date ASC
");
$txns->execute([$statement_id]);
$transactions = $txns->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_transactions = 0;
foreach ($transactions as $t) {
    $total_transactions += (float) $t['amount'];
}

$calculated_end_balance = (float) $statement['start_balance'] + $total_transactions;
$difference = $calculated_end_balance - (float) $statement['end_balance'];

?>

<h1 class="mb-4">📄 View Statement</h1>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title"><?= htmlspecialchars($statement['account_name']) ?></h5>
        <p class="mb-1">
            Statement Date: 
            <strong>
                <?php
                $stmt_date = new DateTime($statement['statement_date']);
                echo htmlspecialchars($stmt_date->format('F Y'));
                ?>
            </strong>
        </p>
		<?php if ($is_credit): ?>
			<p class="mb-1 text-danger" >Account Type: <strong>Credit Card</strong></p>
		<?php elseif ($is_current): ?>
			<p class="mb-1 text-success" >Account Type: <strong>Current Account</strong></p>
		<?php elseif ($is_savings): ?>
			<p class="mb-1 text-success" >Account Type: <strong>Savings Account</strong></p>
		<?php endif; ?>
        <p class="mb-1">Start Balance: <strong>£<?= number_format($statement['start_balance'], 2) ?></strong></p>
        <p class="mb-1">End Balance: <strong>£<?= number_format($statement['end_balance'], 2) ?></strong></p>
        <p class="mb-1">Total Transactions: <strong>£<?= number_format($total_transactions, 2) ?></strong></p>
        <p class="mb-0 <?= abs($difference) < 0.01 ? 'text-success' : 'text-danger' ?>">
            Difference: <strong><?= ($difference >= 0 ? '+' : '') . '£' . number_format($difference, 2) ?></strong>
        </p>
    </div>
</div>


<h5>📋 Transactions</h5>

<?php if (count($transactions) > 0): ?>
<div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['date']) ?></td>
                    <td><?= htmlspecialchars($t['description']) ?></td>
                    <td class="text-end <?= $t['amount'] < 0 ? 'text-danger' : '' ?>">
                        £<?= number_format($t['amount'], 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <p class="text-muted">No transactions found for this statement.</p>
<?php endif; ?>

<a href="statements.php" class="btn btn-secondary mt-3">⬅️ Back to Statements</a>

<?php include '../layout/footer.php'; ?>
