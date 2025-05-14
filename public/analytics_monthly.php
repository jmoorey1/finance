<?php
require_once __DIR__ . '/../config/db.php';
include '../layout/header.php';

$data = require_once '../scripts/get_monthly_summary.php';

if ($data === null || !is_array($data)) {
    echo "<div class='alert alert-danger'>Unable to load monthly summary data. Please check the script at <code>scripts/get_monthly_summary.php</code>.</div>";
    include '../layout/footer.php';
    exit;
}
?>

<h1 class="mb-4">📊 Monthly Financial Summary</h1>

<!-- Chart Container -->
<canvas id="monthlyChart" height="120" class="mb-5"></canvas>

<!-- Table -->
<table class="table table-bordered table-sm">
    <thead class="table-light">
        <tr>
            <th>Month Start</th>
            <th class="text-end">Inflow (£)</th>
            <th class="text-end">Outflow (£)</th>
            <th class="text-end">Net (£)</th>
            <th class="text-end">Discretionary (%)</th>
            <th class="text-end">Fixed (%)</th>
            <th class="text-end">Budget Utilisation (%)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['month_start']) ?></td>
                <td class="text-end"><?= number_format($row['inflow'], 2) ?></td>
                <td class="text-end"><?= number_format($row['outflow'], 2) ?></td>
                <td class="text-end <?= $row['net'] < 0 ? 'text-danger' : 'text-success' ?>">
                    <?= number_format($row['net'], 2) ?>
                </td>
                <td class="text-end"><?= number_format($row['discretionary_pct'], 1) ?>%</td>
                <td class="text-end"><?= number_format($row['fixed_pct'], 1) ?>%</td>
                <td class="text-end"><?= $row['budget_utilization_pct'] !== null ? number_format($row['budget_utilization_pct'], 1) . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Chart.js Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartData = <?= json_encode($data) ?>;

const ctx = document.getElementById('monthlyChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartData.map(row => row.month_start),
        datasets: [
            {
                label: 'Inflow',
                data: chartData.map(row => row.inflow),
                borderColor: 'green',
                fill: false
            },
            {
                label: 'Outflow',
                data: chartData.map(row => row.outflow),
                borderColor: 'red',
                fill: false
            },
            {
                label: 'Net',
                data: chartData.map(row => row.net),
                borderColor: 'blue',
                fill: false
            },
            {
                label: 'Budget %',
                data: chartData.map(row => row.budget_utilization_pct),
                borderColor: 'orange',
                yAxisID: 'y1',
                borderDash: [5, 5],
                fill: false
            }
        ]
    },
    options: {
        responsive: true,
        interaction: {
            mode: 'index',
            intersect: false
        },
        scales: {
            y: {
                beginAtZero: true,
                position: 'left',
                title: { display: true, text: '£' }
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                title: { display: true, text: 'Budget Util %' },
                grid: { drawOnChartArea: false }
            }
        }
    }
});
</script>

<?php include '../layout/footer.php'; ?>
