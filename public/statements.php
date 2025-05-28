<?php
require_once '../config/db.php';

// Get list of active accounts
$stmt = $pdo->query("SELECT id, name FROM accounts WHERE active = 1 ORDER BY name");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission to create a new statement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_id = (int) $_POST['account_id'];
    $statement_date = $_POST['statement_date'];
    $ending_balance = (float) $_POST['ending_balance'];
	
	$most_rec_sql = $pdo->prepare("
		SELECT 
			(CASE
				WHEN a.type = 'credit' THEN - SUM(unrec.amount)
				ELSE SUM(unrec.amount)
			END) AS total
		FROM
			(SELECT 
				account_id, amount
			FROM
				transactions
			WHERE
				account_id = ? AND reconciled = 1 UNION ALL SELECT 
				id AS account_id, starting_balance
			FROM
				accounts
			WHERE
				id = ?) AS unrec
				JOIN
			accounts a ON a.id = unrec.account_id
		GROUP BY unrec.account_id , a.type
	");
	$most_rec_sql->execute([$account_id, $account_id]);
	$most_rec_total = (float)($most_rec_sql->fetchColumn());

    $stmt = $pdo->prepare("INSERT INTO statements (account_id, start_balance, statement_date, end_balance) VALUES (?, ?, ?, ?)");
    $stmt->execute([$account_id, $most_rec_total, $statement_date, $ending_balance]);
    $new_id = $pdo->lastInsertId();

    // Redirect to reconcile page for the new statement
    header("Location: reconcile.php?id=$new_id");
    exit;
}
include '../layout/header.php';

// Load past statements
$past = $pdo->query("
    SELECT s.*, a.name as account_name
    FROM statements s
    JOIN accounts a ON s.account_id = a.id
    ORDER BY s.reconciled ASC, s.statement_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<h1 class="mb-4">🧾 Manage Statements</h1>

<form method="POST" class="mb-4">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Account</label>
            <select name="account_id" class="form-select" required>
                <?php foreach ($accounts as $acct): ?>
                    <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Statement Date</label>
            <input type="date" name="statement_date" class="form-control" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Ending Balance</label>
            <input type="number" step="0.01" name="ending_balance" class="form-control" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Create Statement</button>
        </div>
    </div>
</form>

<h2 class="mb-3">Past Statements</h2>
<table class="table table-sm table-striped align-middle">
    <thead class="table-light">
        <tr>
            <th>Account</th>
            <th>Statement Date</th>
            <th>Ending Balance</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($past as $stmt): ?>
            <tr>
                <td><?= htmlspecialchars($stmt['account_name']) ?></td>
                <td><?= htmlspecialchars($stmt['statement_date']) ?></td>
                <td>£<?= number_format($stmt['end_balance'], 2) ?></td>
				<td>
				<?php if ((int)($stmt['reconciled'] ?? 0) === 1): ?>
					<a href="view_statement.php?id=<?= $stmt['id'] ?>" class="btn btn-sm btn-secondary">View Statement</a>
				<?php else: ?>
					<a href="reconcile.php?id=<?= $stmt['id'] ?>" class="btn btn-sm btn-success">Reconcile</a>
				<?php endif; ?>
				</td>

            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../layout/footer.php'; ?>
