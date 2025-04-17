<?php
require_once '../config/db.php';
require_once '../scripts/get_accounts.php';
include '../layout/header.php';

$accounts = get_all_active_accounts($pdo);

$default_account = null;
foreach ($accounts as $acct) {
    if ($acct['name'] === 'JOINT BILLS') {
        $default_account = $acct['id'];
        break;
    }
}

$selected_accounts = $_GET['accounts'] ?? [$default_account];
$start_date = $_GET['start'] ?? (new DateTimeImmutable('-30 days'))->format('Y-m-d');
$end_date = $_GET['end'] ?? (new DateTimeImmutable('today'))->format('Y-m-d');

$placeholders = implode(',', array_fill(0, count($selected_accounts), '?'));
$params = array_merge(
    $selected_accounts, [$start_date, $end_date], // for actuals
    $selected_accounts, [$start_date, $end_date], // predicted income/expense
    $selected_accounts, [$start_date, $end_date], // predicted transfer (from)
    $selected_accounts, [$start_date, $end_date]  // predicted transfer (to)
);

$query = "
    SELECT 'Actual' AS source, date, account_id, amount, description
    FROM transactions
    WHERE account_id IN ($placeholders) AND date BETWEEN ? AND ?

    UNION ALL

    SELECT 'Predicted' AS source, p.scheduled_date AS date, p.from_account_id AS account_id,
           p.amount AS amount, p.description
    FROM predicted_instances p
    INNER JOIN categories c ON p.category_id = c.id
    WHERE c.type IN ('income', 'expense')
      AND p.from_account_id IN ($placeholders)
      AND p.scheduled_date BETWEEN ? AND ?

    UNION ALL

    SELECT 'Predicted' AS source, p.scheduled_date AS date, p.from_account_id AS account_id,
           -p.amount AS amount, p.description
    FROM predicted_instances p
    INNER JOIN categories c ON p.category_id = c.id
    WHERE c.type = 'transfer'
      AND p.from_account_id IN ($placeholders)
      AND p.scheduled_date BETWEEN ? AND ?

    UNION ALL

    SELECT 'Predicted' AS source, p.scheduled_date AS date, p.to_account_id AS account_id,
           p.amount AS amount, p.description
    FROM predicted_instances p
    WHERE p.to_account_id IN ($placeholders)
      AND p.scheduled_date BETWEEN ? AND ?

    ORDER BY date ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<h1 class="mb-4">📒 Ledger Viewer</h1>

<form method="GET" class="mb-4">
    <div class="row">
        <div class="col-md-4">
            <label class="form-label">Account(s)</label>
            <select name="accounts[]" class="form-select" multiple size="5">
                <?php foreach ($accounts as $acct): ?>
                    <option value="<?= $acct['id'] ?>"
                        <?= in_array($acct['id'], $selected_accounts) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($acct['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">From Date</label>
            <input type="date" name="start" class="form-control" value="<?= $start_date ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">To Date</label>
            <input type="date" name="end" class="form-control" value="<?= $end_date ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
    </div>
</form>

<?php if ($ledger): ?>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Date</th>
                <th>Account</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ledger as $entry): ?>
                <?php
                    $acct_name = '';
                    foreach ($accounts as $acct) {
                        if ($acct['id'] == $entry['account_id']) {
                            $acct_name = $acct['name'];
                            break;
                        }
                    }
                ?>
                <tr>
                    <td><?= $entry['date'] ?></td>
                    <td><?= htmlspecialchars($acct_name) ?></td>
                    <td><?= htmlspecialchars($entry['description']) ?></td>
                    <td class="text-end"><?= ($entry['amount'] < 0 ? "-" : "") . "£" . number_format(abs($entry['amount']), 2) ?></td>
                    <td><?= $entry['source'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No ledger entries found for the selected criteria.</p>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>
