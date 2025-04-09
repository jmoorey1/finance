<?php
require_once '../config/db.php';

// Get selected month (e.g. 2025-04) or use current month
if (isset($_GET['month']) && DateTime::createFromFormat('Y-m', $_GET['month']) !== false) {
    $inputMonth = DateTime::createFromFormat('Y-m', $_GET['month']);
} else {
    $inputMonth = new DateTime(date('Y-m'));
}
$year = $inputMonth->format('Y');
$start_date = new DateTime("$year-01-13");

$end_date = new DateTime($inputMonth->format('Y-m-13'));
$end_date->modify('+1 month')->modify('-1 day');

// Load top-level categories
$categories = [];
$stmt = $pdo->query("
    SELECT id, name, type FROM categories
    WHERE parent_id IS NULL AND type IN ('income', 'expense')
    ORDER BY FIELD(type, 'income', 'expense'), budget_order
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = $row;
}

// Load YTD budgets (all months from Jan–selected month)
$budgets = [];
$stmt = $pdo->prepare("SELECT category_id, SUM(amount) as total FROM budgets WHERE month_start >= ? AND month_start <= ? GROUP BY category_id");
$stmt->execute([$start_date->format('Y-m-d'), $end_date->format('Y-m-d')]);
foreach ($stmt as $row) {
    $budgets[$row['category_id']] = floatval($row['total']);
}

// Load actuals YTD (splits + top-level), rolled up to top-level
$stmt = $pdo->prepare("
    SELECT
        IFNULL(top.id, c.id) AS top_id,
        SUM(s.amount) AS total
    FROM transaction_splits s
    JOIN transactions t ON t.id = s.transaction_id
    JOIN accounts a ON t.account_id = a.id
    JOIN categories c ON s.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    WHERE t.date >= ? AND t.date <= ?
      AND a.type IN ('current','credit','savings')
      AND c.type IN ('income', 'expense')
    GROUP BY top_id

    UNION ALL

    SELECT
        IFNULL(top.id, c.id) AS top_id,
        SUM(t.amount) AS total
    FROM transactions t
    JOIN accounts a ON t.account_id = a.id
    JOIN categories c ON t.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    LEFT JOIN transaction_splits s ON s.transaction_id = t.id
    WHERE t.date >= ? AND t.date <= ?
      AND s.id IS NULL
      AND a.type IN ('current','credit','savings')
      AND c.type IN ('income', 'expense')
    GROUP BY top_id
");


$stmt->execute([
    $start_date->format('Y-m-d'),
    $end_date->format('Y-m-d'),
    $start_date->format('Y-m-d'),
    $end_date->format('Y-m-d')
]);

$actuals = [];
foreach ($stmt as $row) {
    if (!isset($actuals[$row['top_id']])) {
        $actuals[$row['top_id']] = 0;
    }
    $actuals[$row['top_id']] += floatval($row['total']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>YTD Budget vs Actuals</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        table { border-collapse: collapse; width: 60em; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: right; }
        th.sticky-col, td.sticky-col { position: sticky; left: 0; background: #fff; text-align: left; }
        thead th { background: #f8f8f8; position: sticky; top: 0; }
        .under { background-color: #e0ffe0; }
        .over { background-color: #ffe0e0; }
        .income { font-weight: bold; background: #f0f8ff; }
        .expense { font-weight: normal; }
    </style>
</head>
<body>

<h1>YTD Budget vs Actuals</h1>

<form method="GET">
    <label for="month">Show YTD to:</label>
    <input type="month" name="month" value="<?= $inputMonth->format('Y-m') ?>" onchange="this.form.submit()" />
</form>
<br>

<h2><?= $start_date->format('j M Y') ?> – <?= $end_date->format('j M Y') ?></h2>

<table>
    <thead>
        <tr>
            <th class="sticky-col">Category</th>
            <th>Budget</th>
            <th>Actual</th>
            <th>Variance</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $totals = ['income' => ['budget' => 0, 'actual' => 0], 'expense' => ['budget' => 0, 'actual' => 0]];

        foreach ($categories as $id => $cat):
            $type = $cat['type'];
            $budget = $budgets[$id] ?? 0;
            $actual_raw = $actuals[$id] ?? 0;
            $actual = $actual_raw;

            if ($type === 'expense') {
                $actual *= -1;
            }

            if ($budget == 0 && $actual == 0) continue;

            $variance = $actual - $budget;
            $totals[$type]['budget'] += $budget;
            $totals[$type]['actual'] += $actual;

            $class = '';
            if ($variance !== 0) {
                if ($type === 'income') {
                    $class = $variance > 0 ? 'under' : 'over';
                } else {
                    $class = $variance > 0 ? 'over' : 'under';
                }
            }
        ?>
        <tr class="<?= $type ?>">
            <td class="sticky-col"><?= htmlspecialchars($cat['name']) ?></td>
            <td><?= number_format($budget, 2) ?></td>
            <td><?= number_format($actual, 2) ?></td>
            <td class="<?= $class ?>"><?= number_format($variance, 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="font-weight: bold;">
            <td class="sticky-col">Total Income</td>
            <td><?= number_format($totals['income']['budget'], 2) ?></td>
            <td><?= number_format($totals['income']['actual'], 2) ?></td>
            <td><?= number_format($totals['income']['actual'] - $totals['income']['budget'], 2) ?></td>
        </tr>
        <tr style="font-weight: bold;">
            <td class="sticky-col">Total Expenses</td>
            <td><?= number_format($totals['expense']['budget'], 2) ?></td>
            <td><?= number_format($totals['expense']['actual'], 2) ?></td>
            <td><?= number_format($totals['expense']['actual'] - $totals['expense']['budget'], 2) ?></td>
        </tr>
        <tr style="font-weight: bold;">
            <td class="sticky-col">Net Total</td>
            <td><?= number_format($totals['income']['budget'] - $totals['expense']['budget'], 2) ?></td>
            <td><?= number_format($totals['income']['actual'] - $totals['expense']['actual'], 2) ?></td>
            <td><?= number_format(
                ($totals['income']['actual'] - $totals['expense']['actual']) -
                ($totals['income']['budget'] - $totals['expense']['budget']), 2
            ) ?></td>
        </tr>
    </tfoot>
</table>
</body>
</html>
