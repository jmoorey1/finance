<?php
require_once '../config/db.php';
include '../layout/header.php';

// Load data from script
$category_data = require_once '../scripts/get_yoy_category_summary.php';

// Collect all months across the dataset
$all_months = [];
foreach ($category_data as $row) {
    foreach ($row as $month => $_) {
        $all_months[$month] = true;
    }
}
ksort($all_months); // ensure months are ordered

// Optional export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="yoy_summary.csv"');

    $output = fopen('php://output', 'w');
    $headers = array_merge(['Category'], array_keys($all_months), ['Total']);
    fputcsv($output, $headers);

    foreach ($category_data as $cat => $months) {
        $row = [$cat];
        $total = 0;
        foreach ($all_months as $month => $_) {
            $amt = $months[$month] ?? 0;
            $total += $amt;
            $row[] = number_format($amt, 2);
        }
        $row[] = number_format($total, 2);
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}
?>

<h1 class="mb-4">📆 Year-on-Year Category Comparison</h1>
<p>
    Financial months are aligned from <strong>13th–12th</strong>. 
    <a href="?export=csv" class="btn btn-sm btn-outline-primary ms-2">⬇ Export as CSV</a>
</p>

<table class="table table-bordered table-sm">
    <thead class="table-light">
        <tr>
            <th>Category</th>
            <?php foreach (array_keys($all_months) as $month): ?>
                <th class="text-end"><?= htmlspecialchars($month) ?></th>
            <?php endforeach; ?>
            <th class="text-end">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($category_data as $category => $months): ?>
            <tr>
                <td><?= htmlspecialchars($category) ?></td>
                <?php 
                    $total = 0;
                    foreach ($all_months as $month => $_): 
                        $amount = $months[$month] ?? 0;
                        $total += $amount;
                ?>
                    <td class="text-end"><?= number_format($amount, 2) ?></td>
                <?php endforeach; ?>
                <td class="text-end fw-bold"><?= number_format($total, 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../layout/footer.php'; ?>
