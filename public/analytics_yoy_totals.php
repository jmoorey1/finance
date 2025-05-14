<?php
require_once '../config/db.php';
include '../layout/header.php';

$data = require_once '../scripts/get_yoy_fiscal_totals.php';

// Dynamically determine FY labels
$fy_labels = [];
foreach ($data as $row) {
    foreach ($row as $fy => $amount) {
        $fy_labels[$fy] = true;
    }
}
ksort($fy_labels);
$fy_labels = array_keys($fy_labels);

// Prepare totals per year (for chart)
$fy_totals = array_fill_keys($fy_labels, 0);
foreach ($data as $category => $row) {
    foreach ($fy_labels as $fy) {
        $fy_totals[$fy] += $row[$fy] ?? 0;
    }
}
?>

<h1 class="mb-4">📊 Year-on-Year Category Spend</h1>
<p>This view compares total spending by category across financial years (starting Jan 13 each year).</p>

<!-- Chart.js Bar Chart -->
<canvas id="fyChart" height="100"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('fyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_values($fy_labels)) ?>,
        datasets: [{
            label: 'Total Spend (£)',
            data: <?= json_encode(array_values($fy_totals)) ?>,
            borderWidth: 1,
            backgroundColor: 'rgba(54, 162, 235, 0.6)',
        }]
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

<!-- Table View -->
<table class="table table-sm table-bordered mt-4">
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
        <?php foreach ($data as $category => $row): ?>
            <tr>
                <td><?= htmlspecialchars($category) ?></td>
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
