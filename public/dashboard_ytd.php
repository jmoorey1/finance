<?php
require_once '../config/db.php';
include '../layout/header.php';

// Get selected month (e.g. 2025-04) or use current month
if (isset($_GET['month']) && DateTime::createFromFormat('Y-m', $_GET['month']) !== false) {
    $inputMonth = DateTime::createFromFormat('Y-m', $_GET['month']);
} else {
    $today = new DateTime();
    $monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
    $inputMonth = (clone $today)->modify("$monthOffset month");
}

$year = $inputMonth->format('Y');
$start_date = new DateTime("$year-01-13");
$end_date = new DateTime($inputMonth->format('Y-m-13'));
$end_date->modify('+1 month')->modify('-1 day');

// Account query string
$acct_stmt = $pdo->query("SELECT id FROM accounts WHERE type IN ('current','credit','savings') and active=1");
$account_ids = array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
$account_query = implode('&', array_map(fn($id) => "accounts[]=$id", $account_ids));

// Category sections
$sections = [
    'Fixed Income' => ['type' => 'income', 'fixedness' => 'fixed'],
    'Variable Income' => ['type' => 'income', 'fixedness' => 'variable'],
    'Fixed & Essential Expenses' => ['type' => 'expense', 'fixedness' => 'fixed', 'priority' => 'essential'],
    'Variable & Essential Expenses' => ['type' => 'expense', 'fixedness' => 'variable', 'priority' => 'essential'],
    'Variable & Discretionary Expenses' => ['type' => 'expense', 'fixedness' => 'variable', 'priority' => 'discretionary'],
];

// Load categories
$categories = [];
$stmt = $pdo->query("
    SELECT id, name, type, fixedness, priority
    FROM categories
    WHERE parent_id IS NULL AND type IN ('income', 'expense')
    ORDER BY FIELD(type, 'income', 'expense'), budget_order
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = $row;
}

// Load budget totals YTD
$budgets = [];
$stmt = $pdo->prepare("
    SELECT category_id, SUM(amount) AS total
    FROM budgets
    WHERE month_start BETWEEN ? AND ?
    GROUP BY category_id
");
$stmt->execute([$start_date->format('Y-m-d'), $end_date->format('Y-m-d')]);
foreach ($stmt as $row) {
    $budgets[$row['category_id']] = floatval($row['total']);
}

// Load actuals YTD (rolled up)
$actuals = [];
$stmt = $pdo->prepare("
    SELECT top_id, SUM(total) as total FROM (
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
    ) combined
    GROUP BY top_id
");
$stmt->execute([
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d'),
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d')
]);
foreach ($stmt as $row) {
    $actuals[$row['top_id']] = floatval($row['total']);
}

// Load forecast (today to end)
$forecast = [];
$today = (new DateTimeImmutable())->format('Y-m-d');
$stmt = $pdo->prepare("
    SELECT IFNULL(top.id, c.id) AS top_id, c.type, SUM(pi.amount) AS total
    FROM predicted_instances pi
    JOIN categories c ON pi.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    WHERE pi.scheduled_date BETWEEN ? AND ?
      AND c.type IN ('income','expense')
    GROUP BY top_id, c.type
");
$stmt->execute([$today, $end_date->format('Y-m-d')]);
foreach ($stmt as $row) {
    $forecast[$row['top_id']] = floatval($row['total']);
}

// Totals
$totals = ['income' => ['budget' => 0, 'actual' => 0, 'forecast' => 0], 'expense' => ['budget' => 0, 'actual' => 0, 'forecast' => 0]];
?>

<h1 class="mb-4">📊 YTD Budget vs Actuals</h1>

<form method="GET" class="mb-3">
    <label for="month" class="form-label">Show YTD up to:</label>
    <input type="month" name="month" class="form-control" value="<?= $inputMonth->format('Y-m') ?>" onchange="this.form.submit()" />
</form>

<h4><?= $start_date->format('j M Y') ?> – <?= $end_date->format('j M Y') ?></h4>

<div class="table-responsive mt-3">
<table class="table table-sm table-striped table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th>Category</th>
            <th class="text-end">Budget</th>
            <th class="text-end">Actual</th>
            <th class="text-end">Forecast</th>
            <th class="text-end">Variance</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sections as $label => $filter): ?>
        <?php
        $section_rows = '';
        foreach ($categories as $id => $cat):
            if ($cat['type'] !== $filter['type']) continue;
            if (isset($filter['fixedness']) && $cat['fixedness'] !== $filter['fixedness']) continue;
            if (isset($filter['priority']) && $cat['priority'] !== $filter['priority']) continue;

            $budget = $budgets[$id] ?? 0;
            $actual = $actuals[$id] ?? 0;
            $future = $forecast[$id] ?? 0;

            if ($cat['type'] === 'expense') {
                $actual *= -1;
                $future *= -1;
            }

            if ($budget == 0 && $actual == 0 && $future == 0) continue;

            $variance = ($cat['type'] === 'income')
                ? ($actual + $future - $budget)
                : ($budget - $actual - $future);

            $class = $variance >= 0 ? 'text-success' : 'text-danger';
                $sign = $variance > 0 ? "+" : "";

            $totals[$cat['type']]['budget'] += $budget;
            $totals[$cat['type']]['actual'] += $actual;
            $totals[$cat['type']]['forecast'] += $future;

            $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$id}";
            $actual_link = "<a href=\"$link_base\" class=\"text-decoration-none\">£" . number_format($actual, 2) . "</a>";
            $forecast_link = "<a href=\"$link_base\" class=\"text-decoration-none\">£" . number_format($future, 2) . "</a>";

            $section_rows .= "<tr>
				<td><a href='category_report.php?category_id=" . htmlspecialchars($cat['id']) . "'>" . htmlspecialchars($cat['name']) . "</a></td>
                <td class='text-end'>£" . number_format($budget, 2) . "</td>
                <td class='text-end'>$actual_link</td>
                <td class='text-end'>$forecast_link</td>
                <td class='text-end $class'>" . $sign . "£" . number_format($variance, 2) . "</td>
            </tr>";
        endforeach;

        if ($section_rows !== ''):
            echo "<tr class='table-light fw-bold'><td colspan='5'>" . htmlspecialchars($label) . "</td></tr>";
            echo $section_rows;

            if ($filter['type'] === 'income' && $label === 'Variable Income'):
                $inc_var = $totals['income']['actual'] + $totals['income']['forecast'] - $totals['income']['budget'];
                $inc_class = $inc_var >= 0 ? 'text-success' : 'text-danger';
                $sign = $inc_var > 0 ? "+" : "";
                echo "<tr class='fw-bold table-light'>
                    <td>Total Income</td>
                    <td class='text-end'>£" . number_format($totals['income']['budget'], 2) . "</td>
                    <td class='text-end'>£" . number_format($totals['income']['actual'], 2) . "</td>
                    <td class='text-end'>£" . number_format($totals['income']['forecast'], 2) . "</td>
                    <td class='text-end $inc_class'>" . $sign . "£" . number_format($inc_var, 2) . "</td>
                </tr>";
            endif;
        endif;
    endforeach;
    ?>
    <tr class="fw-bold table-light">
        <td>Total Expenses</td>
        <td class="text-end">£<?= number_format($totals['expense']['budget'], 2) ?></td>
        <td class="text-end">£<?= number_format($totals['expense']['actual'], 2) ?></td>
        <td class="text-end">£<?= number_format($totals['expense']['forecast'], 2) ?></td>
        <?php
        $exp_var = $totals['expense']['budget'] - $totals['expense']['actual'] - $totals['expense']['forecast'];
        $exp_class = $exp_var >= 0 ? 'text-success' : 'text-danger';
                $sign = $exp_var > 0 ? "+" : "";
        ?>
        <td class="text-end <?= $exp_class ?>"><?= $sign ?>£<?= number_format($exp_var, 2) ?></td>
    </tr>
    <tr class="fw-bold table-dark">
        <td>Net Total</td>
        <td class="text-end">£<?= number_format($totals['income']['budget'] - $totals['expense']['budget'], 2) ?></td>
        <td class="text-end">£<?= number_format($totals['income']['actual'] - $totals['expense']['actual'], 2) ?></td>
        <td class="text-end">£<?= number_format($totals['income']['forecast'] - $totals['expense']['forecast'], 2) ?></td>
        <?php
        $net_var = ($totals['income']['actual'] + $totals['income']['forecast']) -
                   ($totals['expense']['actual'] + $totals['expense']['forecast']) -
                   ($totals['income']['budget'] - $totals['expense']['budget']);
        $net_class = $net_var >= 0 ? 'text-success' : 'text-danger';
                $sign = $net_var > 0 ? "+" : "";
        ?>
        <td class="text-end <?= $net_class ?>"><?= $sign ?>£<?= number_format($net_var, 2) ?></td>
    </tr>
    </tbody>
</table>
</div>

<?php include '../layout/footer.php'; ?>
