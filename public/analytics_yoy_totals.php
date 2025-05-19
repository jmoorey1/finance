<?php
require_once '../config/db.php';
include '../layout/header.php';

$all_data = require_once '../scripts/get_yoy_fiscal_totals.php';
$income_data = $all_data['income'];
$expense_data = $all_data['expense'];

// Determine FY labels
$fy_labels = [];
foreach (array_merge($income_data, $expense_data) as $row) {
    foreach ($row as $fy => $amt) {
        if (!in_array($fy, ['category_id', 'name'])) {
            $fy_labels[$fy] = true;
        }
    }
}
ksort($fy_labels);
$fy_labels = array_keys($fy_labels);

// Bar chart: total income and expense by FY
$fy_income_totals = array_fill_keys($fy_labels, 0);
$fy_expense_totals = array_fill_keys($fy_labels, 0);

foreach ($income_data as $row) {
    foreach ($fy_labels as $fy) {
        $fy_income_totals[$fy] += $row[$fy] ?? 0;
    }
}
foreach ($expense_data as $row) {
    foreach ($fy_labels as $fy) {
        $fy_expense_totals[$fy] += $row[$fy] ?? 0;
    }
}
?>

<h1 class="mb-4">📊 Year-on-Year Category Spend</h1>

<canvas id="fyBarChart" height="100"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('fyBarChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($fy_labels) ?>,
        datasets: [
            {
                label: 'Income (£)',
                data: <?= json_encode(array_values($fy_income_totals)) ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.6)',
            },
            {
                label: 'Expenses (£)',
                data: <?= json_encode(array_values($fy_expense_totals)) ?>,
                backgroundColor: 'rgba(255, 99, 132, 0.6)',
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: value => '£' + value.toLocaleString()
                }
            }
        }
    }
});
</script>

<!-- 📈 Income Categories Table -->
<h3 class="mt-5">Income Categories</h3>
<table class="table table-sm table-bordered">
    <thead class="table-light">
        <tr>
            <th>Category</th>
            <?php foreach ($fy_labels as $fy): ?>
                <th class="text-end"><?= $fy ?></th>
            <?php endforeach; ?>
            <th class="text-end">Total</th>
            <th class="text-end">% Change</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($income_data as $row): ?>
            <tr>
                <td>
                    <a href="category_report.php?category_id=<?= $row['category_id'] ?>">
                        <?= htmlspecialchars($row['name']) ?>
                    </a>
                </td>
                <?php
                    $row_total = 0;
                    foreach ($fy_labels as $fy):
                        $val = $row[$fy] ?? 0;
                        $row_total += $val;
                ?>
                    <td class="text-end"><?= number_format($val, 2) ?></td>
                <?php endforeach; ?>
                <td class="text-end fw-bold"><?= number_format($row_total, 2) ?></td>
                <td class="text-end text-muted">
                    <?php
                        $fy_count = count($fy_labels);
                        if ($fy_count >= 2) {
                            $latest = $row[$fy_labels[$fy_count - 1]] ?? 0;
                            $previous = $row[$fy_labels[$fy_count - 2]] ?? 0;
                            if ($previous > 0) {
                                $change = round((($latest - $previous) / $previous) * 100);
                                echo ($change > 0 ? '+' : '') . $change . '%';
                            } else {
                                echo 'n/a';
                            }
                        }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 📉 Expense Categories Table -->
<h3 class="mt-5">Expense Categories</h3>
<table class="table table-sm table-bordered">
    <thead class="table-light">
        <tr>
            <th>Category</th>
            <?php foreach ($fy_labels as $fy): ?>
                <th class="text-end"><?= $fy ?></th>
            <?php endforeach; ?>
            <th class="text-end">Total</th>
            <th class="text-end">% Change</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($expense_data as $row): ?>
            <tr>
                <td>
                    <a href="category_report.php?category_id=<?= $row['category_id'] ?>">
                        <?= htmlspecialchars($row['name']) ?>
                    </a>
                </td>
                <?php
                    $row_total = 0;
                    foreach ($fy_labels as $fy):
                        $val = $row[$fy] ?? 0;
                        $row_total += $val;
                ?>
                    <td class="text-end"><?= number_format($val, 2) ?></td>
                <?php endforeach; ?>
                <td class="text-end fw-bold"><?= number_format($row_total, 2) ?></td>
                <td class="text-end text-muted">
                    <?php
                        $fy_count = count($fy_labels);
                        if ($fy_count >= 2) {
                            $latest = $row[$fy_labels[$fy_count - 1]] ?? 0;
                            $previous = $row[$fy_labels[$fy_count - 2]] ?? 0;
                            if ($previous > 0) {
                                $change = round((($latest - $previous) / $previous) * 100);
                                echo ($change > 0 ? '+' : '') . $change . '%';
                            } else {
                                echo 'n/a';
                            }
                        }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../layout/footer.php'; ?>
