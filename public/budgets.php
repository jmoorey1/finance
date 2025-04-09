<?php
require_once '../config/db.php';

// Categories to exclude from budgeting
$excluded = [
    'Split/Multiple Categories',
    'Transfers',
    'Cash Withdrawal',
    'Job Expense',
    'Not an Expense',
    'Property Sale/Remortgage'
];

// Get year (via GET param or current year)
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Fetch top-level categories, ordered by type
$categories = [];
$stmt = $pdo->query("
    SELECT id, name, type FROM categories
    WHERE parent_id IS NULL
      AND type IN ('income', 'expense')
    ORDER BY FIELD(type, 'income', 'expense'), budget_order
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (in_array($row['name'], $excluded)) continue;
    $categories[] = $row;
}

// Handle form submission
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['budgets'] as $category_id => $months) {
        foreach ($months as $month_start => $amount) {
            if ($amount === '') continue;
            $amount = floatval($amount);
            $stmt = $pdo->prepare("
                INSERT INTO budgets (category_id, month_start, amount)
                VALUES (:category_id, :month_start, :amount)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount)
            ");
            $stmt->execute([
                ':category_id' => $category_id,
                ':month_start' => $month_start,
                ':amount' => $amount,
            ]);
        }
    }
    $success = true;
}

// Build month headers and 13th-of-month values
$months = [];
$headers = [];
for ($i = 1; $i <= 12; $i++) {
    $start_ts = mktime(0, 0, 0, $i, 13, $year);
    $next_month_ts = mktime(0, 0, 0, $i + 1, 12, $year);
    $label = date('M', $start_ts) . '–' . date('M Y', $next_month_ts);
    $headers[] = $label;
    $months[] = date('Y-m-d', $start_ts); // e.g. 2025-01-13
}

// Preload existing budget values
$existing = [];
$stmt = $pdo->query("SELECT * FROM budgets WHERE YEAR(month_start) = $year");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$row['category_id']][$row['month_start']] = $row['amount'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Annual Budgets</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: right; }
        th.sticky-col, td.sticky-col { position: sticky; left: 0; background: #fff; text-align: left; }
        input[type='number'] { width: 80px; }
        thead th { background: #f8f8f8; position: sticky; top: 0; }
        tfoot td { font-weight: bold; background: #f0f0f0; }
    </style>
</head>
<body>

    <h1>Annual Budget for <?= $year ?></h1>

    <!-- Year Selector -->
    <form method="GET">
        <label for="year">Select Year:</label>
        <select name="year" onchange="this.form.submit()">
            <?php for ($y = $year - 2; $y <= $year + 2; $y++): ?>
                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
    <br>

    <?php if ($success): ?>
        <p style="color: green;">Annual budget saved successfully.</p>
    <?php endif; ?>

    <form method="POST">
        <table>
            <thead>
                <tr>
                    <th class="sticky-col">Category</th>
                    <?php foreach ($headers as $header): ?>
                        <th><?= $header ?></th>
                    <?php endforeach; ?>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $type_totals = ['income' => array_fill(0, 12, 0), 'expense' => array_fill(0, 12, 0)];
                $type_grand = ['income' => 0, 'expense' => 0];
                $current_type = '';
                foreach ($categories as $cat):
                    if ($current_type !== $cat['type']) {
                        $current_type = $cat['type'];
                    }
                    $row_total = 0;
                ?>
                    <tr>
                        <td class="sticky-col"><?= htmlspecialchars($cat['name']) ?></td>
                        <?php foreach ($months as $i => $month): 
                            $val = $existing[$cat['id']][$month] ?? '';
                            $row_total += floatval($val);
                            $type_totals[$cat['type']][$i] += floatval($val);
                        ?>
                            <td>
                                <input type="number" step="0.01"
                                    name="budgets[<?= $cat['id'] ?>][<?= $month ?>]"
                                    value="<?= $val ?>" />
                            </td>
                        <?php endforeach; ?>
                        <td><?= number_format($row_total, 2) ?></td>
                        <?php $type_grand[$cat['type']] += $row_total; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="sticky-col">Total Income</td>
                    <?php foreach ($type_totals['income'] as $total): ?>
                        <td><?= number_format($total, 2) ?></td>
                    <?php endforeach; ?>
                    <td><?= number_format($type_grand['income'], 2) ?></td>
                </tr>
                <tr>
                    <td class="sticky-col">Total Expenses</td>
                    <?php foreach ($type_totals['expense'] as $total): ?>
                        <td><?= number_format($total, 2) ?></td>
                    <?php endforeach; ?>
                    <td><?= number_format($type_grand['expense'], 2) ?></td>
                </tr>
                <tr>
                    <td class="sticky-col">Net Total</td>
                    <?php for ($i = 0; $i < 12; $i++): ?>
                        <td><?= number_format($type_totals['income'][$i] - $type_totals['expense'][$i], 2) ?></td>
                    <?php endfor; ?>
                    <td><?= number_format($type_grand['income'] - $type_grand['expense'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
        <br>
        <button type="submit">Save Annual Budget</button>
    </form>
</body>
</html>
