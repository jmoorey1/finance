<?php
require_once '../config/db.php';
include '../layout/header.php';

$id = (int) ($_GET['id'] ?? 0);

// Load statement details
$stmt = $pdo->prepare("SELECT s.*, a.name as account_name, a.type as account_type FROM statements s JOIN accounts a ON s.account_id = a.id WHERE s.id = ?");
$stmt->execute([$id]);
$statement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$statement) {
    echo "<p>❌ Invalid statement ID.</p>";
    include '../layout/footer.php';
    exit;
}

// Load previous statement balance
$prev_stmt = $pdo->prepare("
    SELECT end_balance FROM statements
    WHERE account_id = ? AND statement_date < ?
    ORDER BY statement_date DESC LIMIT 1
");
$prev_stmt->execute([$statement['account_id'], $statement['statement_date']]);
$previous_balance = $prev_stmt->fetchColumn();

// If no previous statement, use account starting balance
if ($previous_balance === false) {
    $acct_stmt = $pdo->prepare("SELECT starting_balance FROM accounts WHERE id = ?");
    $acct_stmt->execute([$statement['account_id']]);
    $previous_balance = $acct_stmt->fetchColumn();
}

// Load unreconciled transactions
$is_credit = ($statement['account_type'] === 'credit');
$txns = $pdo->prepare("
    SELECT t.id, t.date, t.description, (CASE WHEN a.type = 'credit' THEN -t.amount ELSE t.amount END) AS amount
    FROM transactions t
	join accounts a on t.account_id = a.id
    WHERE t.account_id = ?
      AND (t.reconciled IS NULL OR t.reconciled = 0)
    ORDER BY t.date ASC
");
$txns->execute([$statement['account_id']]);
$transactions = $txns->fetchAll(PDO::FETCH_ASSOC);

// Find previous statement (if any)
$prev_stmt = $pdo->prepare("
    SELECT statement_date FROM statements
    WHERE account_id = ? AND statement_date < ?
    ORDER BY statement_date DESC LIMIT 1
");
$prev_stmt->execute([$statement['account_id'], $statement['statement_date']]);
$prev_end = $prev_stmt->fetchColumn();
?>

<h1 class="mb-4">🧮 Reconcile Statement for <?= htmlspecialchars($statement['account_name']) ?></h1>

<div class="row">
    <div class="col-md-8">
        <form id="reconcile-form" method="POST" action="finalize_reconciliation.php">
            <input type="hidden" name="statement_id" value="<?= $statement['id'] ?>">
            <table class="table table-sm table-striped">
                <thead class="table-light">
                    <tr>
                        <th scope="col">✔</th>
                        <th scope="col">Date</th>
                        <th scope="col">Description</th>
                        <th class="text-end" scope="col">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <?php
                        $should_check = true;
                        if ($t['date'] > $statement['statement_date']) {
                            $should_check = false;
                        }
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="txn-check" name="transaction_ids[]" value="<?= $t['id'] ?>"
                                    <?= $should_check ? 'checked' : '' ?>
                                    data-amount="<?= $t['amount'] ?>">
                            </td>
                            <td><?= htmlspecialchars($t['date']) ?></td>
                            <td><?= htmlspecialchars($t['description']) ?></td>
                            <td class="text-end">£<?= number_format($t['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button id="finalize-btn" type="submit" class="btn btn-success w-100 mt-2" style="display:none;">✅ Finalize Reconciliation</button>
        </form>
    </div>

    <div class="col-md-4">
        <div class="card sticky-top">
            <div class="card-body">
                <h5>Summary</h5>
                <p>Target Balance: <strong>£<?= number_format($statement['end_balance'], 2) ?></strong></p>
                <p>Current Selected Total: <strong id="current-total">£0.00</strong></p>
                <p>Variance to Target: <strong id="variance" class="text-danger">£0.00</strong></p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const previousBalance = <?= (float) $previous_balance ?>; // We will set this from PHP

    function updateVariance() {
        let runningTotal = previousBalance;


        document.querySelectorAll('.txn-check').forEach(cb => {
            let amt = parseFloat(cb.dataset.amount);

            if (cb.checked) {
                if (!isNaN(amt)) {
                    runningTotal += amt;
                } 
            }
        });


        document.getElementById('current-total').innerText = "£" + runningTotal.toFixed(2);

        let target = <?= $statement['end_balance'] ?>;
        let variance = runningTotal - target;
        document.getElementById('variance').innerText = (variance >= 0 ? "+" : "") + "£" + variance.toFixed(2);
        document.getElementById('variance').className = (Math.abs(variance) < 0.01) ? 'text-success' : 'text-danger';

        // Show/hide Finalize button
        document.getElementById('finalize-btn').style.display = (Math.abs(variance) < 0.01) ? 'block' : 'none';
    }

    document.querySelectorAll('.txn-check').forEach(cb => {
        cb.addEventListener('change', updateVariance);
    });

    updateVariance(); // Trigger once after page load
});
</script>

<?php include '../layout/footer.php'; ?>
