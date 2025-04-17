<?php
require_once '../config/db.php';
include '../layout/header.php';

if (isset($_GET['month']) && DateTime::createFromFormat('Y-m', $_GET['month']) !== false) {
    $inputMonth = DateTime::createFromFormat('Y-m', $_GET['month']);
} else {
    $today = new DateTime();
    $monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
    $inputMonth = (clone $today)->modify("$monthOffset month");
}
$start_month = new DateTime($inputMonth->format('Y-m-13'));
$end_month = (clone $start_month)->modify('+1 month')->modify('-1 day');

// Fetch top-level categories
$categories = [];
$stmt = $pdo->query("
    SELECT id, name, type FROM categories
    WHERE parent_id IS NULL AND type IN ('income', 'expense')
    ORDER BY FIELD(type, 'income', 'expense'), budget_order
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = $row;
}

// Load budget for this month
$budgets = [];
$stmt = $pdo->prepare("SELECT category_id, amount FROM budgets WHERE month_start = ?");
$stmt->execute([$start_month->format('Y-m-d')]);
foreach ($stmt as $row) {
    $budgets[$row['category_id']] = floatval($row['amount']);
}

// Load actuals
$stmt = $pdo->prepare("
    SELECT IFNULL(top.id, c.id) AS top_id, SUM(s.amount) AS total
    FROM transaction_splits s
    JOIN transactions t ON t.id = s.transaction_id
    JOIN accounts a ON t.account_id = a.id
    JOIN categories c ON s.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    WHERE t.date BETWEEN ? AND ?
      AND a.type IN ('current','credit','savings')
      AND c.type IN ('income', 'expense')
    GROUP BY top_id
    UNION ALL
    SELECT IFNULL(top.id, c.id) AS top_id, SUM(t.amount) AS total
    FROM transactions t
    JOIN accounts a ON t.account_id = a.id
    JOIN categories c ON t.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    LEFT JOIN transaction_splits s ON s.transaction_id = t.id
    WHERE t.date BETWEEN ? AND ?
      AND s.id IS NULL
      AND a.type IN ('current','credit','savings')
      AND c.type IN ('income', 'expense')
    GROUP BY top_id
");
$stmt->execute([
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d'),
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d')
]);

$actuals = [];
foreach ($stmt as $row) {
    $actuals[$row['top_id']] = ($actuals[$row['top_id']] ?? 0) + floatval($row['total']);
}
?>

<h1 class="mb-4">📆 Budget vs Actuals</h1>

<form method="GET" class="mb-3">
    <label for="month" class="form-label">Financial Month Starting:</label>
    <input type="month" name="month" class="form-control" value="<?= $start_month->format('Y-m') ?>" onchange="this.form.submit()" />
</form>

<h4><?= $start_month->format('j M Y') ?> – <?= $end_month->format('j M Y') ?></h4>

<div class="table-responsive mt-3">
    <table class="table table-sm table-striped table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Category</th>
                <th class="text-end">Budget</th>
                <th class="text-end">Actual</th>
                <th class="text-end">Variance</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $totals = ['income' => ['budget' => 0, 'actual' => 0], 'expense' => ['budget' => 0, 'actual' => 0]];

            foreach ($categories as $id => $cat):
                $type = $cat['type'];
                $budget = $budgets[$id] ?? 0;
                $actual_raw = $actuals[$id] ?? 0;
                $actual = ($type === 'expense') ? -$actual_raw : $actual_raw;

                if ($budget == 0 && $actual == 0) continue;

                $variance = $actual - $budget;
                $totals[$type]['budget'] += $budget;
                $totals[$type]['actual'] += $actual;

                $class = '';
                if ($variance !== 0) {
                    if ($type === 'income') {
                        $class = $variance > 0 ? 'text-success' : 'text-danger';
                    } else {
                        $class = $variance > 0 ? 'text-danger' : 'text-success';
                    }
                }
            ?>
            <tr>
                <td><?= htmlspecialchars($cat['name']) ?></td>
                <td class="text-end">£<?= number_format($budget, 2) ?></td>
                <td class="text-end">£<?= number_format($actual, 2) ?></td>
                <td class="text-end <?= $class ?>">£<?= number_format($variance, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
            <tr>
                <th>Total Income</th>
                <th class="text-end">£<?= number_format($totals['income']['budget'], 2) ?></th>
                <th class="text-end">£<?= number_format($totals['income']['actual'], 2) ?></th>
                <th class="text-end">£<?= number_format($totals['income']['actual'] - $totals['income']['budget'], 2) ?></th>
            </tr>
            <tr>
                <th>Total Expenses</th>
                <th class="text-end">£<?= number_format($totals['expense']['budget'], 2) ?></th>
                <th class="text-end">£<?= number_format($totals['expense']['actual'], 2) ?></th>
                <th class="text-end">£<?= number_format($totals['expense']['actual'] - $totals['expense']['budget'], 2) ?></th>
            </tr>
            <tr>
                <th>Net Total</th>
                <th class="text-end">£<?= number_format($totals['income']['budget'] - $totals['expense']['budget'], 2) ?></th>
                <th class="text-end">£<?= number_format($totals['income']['actual'] - $totals['expense']['actual'], 2) ?></th>
                <th class="text-end">£<?= number_format(
                    ($totals['income']['actual'] - $totals['expense']['actual']) -
                    ($totals['income']['budget'] - $totals['expense']['budget']), 2
                ) ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
