<?php
require_once '../config/db.php';

function adjust_to_next_business_day(DateTime $dt): DateTime {
    // Weekend-only (Sat/Sun). If you want UK bank holidays too, we can extend this later.
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

// Get list of active accounts
$stmt = $pdo->query("SELECT id, name FROM accounts WHERE active=1 ORDER BY name");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission to create a new statement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_id = (int)($_POST['account_id'] ?? 0);
    $statement_date = $_POST['statement_date'] ?? null;
    $ending_balance = (float)($_POST['ending_balance'] ?? 0);

    if ($account_id <= 0 || !$statement_date) {
        header("Location: statements.php?error=missing_fields");
        exit;
    }

    // Load account details (for credit card due date / minimum payment)
    $acctStmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
    $acctStmt->execute([$account_id]);
    $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);

    if (!$acct) {
        header("Location: statements.php?error=invalid_account");
        exit;
    }

    // Calculate the most recent reconciled balance (as the start balance for this statement)
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
                account_id = ? AND reconciled = 1
            UNION ALL
            SELECT 
                id AS account_id, starting_balance
            FROM
                accounts
            WHERE
                id = ?) AS unrec
            JOIN accounts a ON a.id = unrec.account_id
        GROUP BY unrec.account_id, a.type
    ");

    $most_rec_sql->execute([$account_id, $account_id]);
    $most_rec_total = (float)($most_rec_sql->fetchColumn());

    // Compute credit-card-only fields
    $payment_due_date = null;
    $minimum_payment_due = null;

    if (($acct['type'] ?? null) === 'credit' && !empty($acct['statement_day']) && !empty($acct['payment_day'])) {
        try {
            $stmtDt = new DateTime($statement_date);
            $payment_due_date = compute_payment_due_date($stmtDt, (int)$acct['statement_day'], (int)$acct['payment_day'])
                ->format('Y-m-d');
        } catch (Exception $e) {
            // Leave null; user can still reconcile and finalize
            $payment_due_date = null;
        }

        // Only store minimum_payment_due if this card is configured for minimum payment prediction
        if (($acct['repayment_method'] ?? 'full') === 'minimum') {
            $minimum_payment_due = calc_min_payment(abs((float)$ending_balance), $acct['min_payment_floor'] ?? null, $acct['min_payment_percent'] ?? null);
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO statements
            (account_id, start_balance, statement_date, end_balance, payment_due_date, minimum_payment_due)
        VALUES
            (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $account_id,
        $most_rec_total,
        $statement_date,
        $ending_balance,
        $payment_due_date,
        $minimum_payment_due
    ]);

    $new_id = $pdo->lastInsertId();

    // Redirect to reconcile page for the new statement
    header("Location: reconcile.php?id=$new_id");
    exit;
}

include '../layout/header.php';

// Load past statements
$past = $pdo->query("
    SELECT s.*, a.name as account_name, a.type as account_type
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
                    <option value="<?= (int)$acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
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
            <th>Payment Due</th>
            <th>Minimum Due</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($past as $stmt): ?>
            <tr>
                <td><?= htmlspecialchars($stmt['account_name']) ?></td>
                <td><?= htmlspecialchars($stmt['statement_date']) ?></td>
                <td>£<?= number_format((float)$stmt['end_balance'], 2) ?></td>
                <td><?= $stmt['payment_due_date'] ? htmlspecialchars($stmt['payment_due_date']) : '—' ?></td>
                <td><?= ($stmt['minimum_payment_due'] !== null ? '£' . number_format((float)$stmt['minimum_payment_due'], 2) : '—') ?></td>
                <td>
                    <?php if ((int)($stmt['reconciled'] ?? 0) === 1): ?>
                        <a href="view_statement.php?id=<?= (int)$stmt['id'] ?>" class="btn btn-sm btn-secondary">View Statement</a>
                    <?php else: ?>
                        <a href="reconcile.php?id=<?= (int)$stmt['id'] ?>" class="btn btn-sm btn-success">Reconcile</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../layout/footer.php'; ?>
