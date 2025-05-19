<?php
require_once '../config/db.php';
include '../layout/header.php';

$pdo = get_db_connection();

// Get selected subcategory or fallback to a default
$selected_subcategory = isset($_GET['subcategory_id']) ? intval($_GET['subcategory_id']) : 69;

// Load all subcategories, sorted by parent type, parent name, then subcategory name
$stmt = $pdo->query("
    SELECT c.id, c.name AS sub_name, p.name AS parent_name, p.type AS parent_type
    FROM categories c
    JOIN categories p ON c.parent_id = p.id
    WHERE c.parent_id IS NOT NULL and c.type in ('expense', 'income')
    ORDER BY p.type, p.name, c.name
");
$subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get parent info for the selected subcategory
$selected_meta = null;
foreach ($subcategories as $s) {
    if ($s['id'] == $selected_subcategory) {
        $selected_meta = $s;
        break;
    }
}

// Fiscal year totals
$fiscal_totals = [];
$fy_stmt = $pdo->prepare("
    SELECT
        YEAR(CASE WHEN MONTH(date) = 1 AND DAY(date) < 13 THEN DATE_SUB(date, INTERVAL 1 YEAR) ELSE date END) AS fiscal_year,
        SUM(CASE WHEN p.type = 'expense' THEN -t.amount ELSE t.amount END) AS total
    FROM (
        SELECT date, amount, category_id FROM transactions
        UNION ALL
        SELECT t.date, ts.amount, ts.category_id FROM transaction_splits ts JOIN transactions t ON t.id = ts.transaction_id
    ) t
    JOIN categories c ON t.category_id = c.id
    JOIN categories p ON c.parent_id = p.id
    WHERE c.id = ?
    GROUP BY fiscal_year
    ORDER BY fiscal_year
");
$fy_stmt->execute([$selected_subcategory]);
while ($row = $fy_stmt->fetch(PDO::FETCH_ASSOC)) {
    $fiscal_totals[$row['fiscal_year']] = round($row['total'], 2);
}

// Last 6 financial months
$now = new DateTime();
$start = new DateTime($now->format('Y-m-13'));
if ((int)$now->format('d') < 13) $start->modify('-1 month');
$end = (clone $start)->modify('+1 month')->modify('-1 day');
$start->modify('-6 months');

// Month breakdown
$month_totals = [];
$months = [];
$sub_stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(CASE WHEN DAY(date) >= 13 THEN date ELSE DATE_SUB(date, INTERVAL 1 MONTH) END, '%Y-%m') AS fin_month,
        SUM(CASE WHEN p.type = 'expense' THEN -t.amount ELSE t.amount END) AS total
    FROM (
        SELECT date, amount, category_id FROM transactions
        UNION ALL
        SELECT scheduled_date AS date, amount, category_id FROM predicted_instances
        UNION ALL
        SELECT t.date, ts.amount, ts.category_id FROM transaction_splits ts JOIN transactions t ON t.id = ts.transaction_id
    ) t
    JOIN categories c ON t.category_id = c.id
    JOIN categories p ON c.parent_id = p.id
    WHERE c.id = ? AND date BETWEEN ? AND ?
    GROUP BY fin_month
    ORDER BY fin_month
");
$sub_stmt->execute([$selected_subcategory, $start->format('Y-m-d'), $end->format('Y-m-d')]);
while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
    $month = $row['fin_month'];
    $month_totals[$month] = round($row['total'], 2);
    if (!in_array($month, $months)) $months[] = $month;
}

// Transaction detail list
$tx_stmt = $pdo->prepare("
    SELECT 'Actual' AS source, t.date, t.amount, t.description FROM transactions t
    WHERE category_id = ? AND date BETWEEN ? AND ?
    UNION ALL
    SELECT 'Split' AS source, t.date, ts.amount, t.description
    FROM transaction_splits ts
    JOIN transactions t ON t.id = ts.transaction_id
    WHERE ts.category_id = ? AND t.date BETWEEN ? AND ?
    UNION ALL
    SELECT 'Predicted' AS source, scheduled_date, amount, description
    FROM predicted_instances
    WHERE category_id = ? AND scheduled_date BETWEEN ? AND ?
    ORDER BY date DESC
");
$tx_stmt->execute([
    $selected_subcategory, $start->format('Y-m-d'), $end->format('Y-m-d'),
    $selected_subcategory, $start->format('Y-m-d'), $end->format('Y-m-d'),
    $selected_subcategory, $start->format('Y-m-d'), $end->format('Y-m-d')
]);
$transactions = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Subcategory Report: <?= htmlspecialchars($selected_meta['sub_name']) ?></h2>

<form method="get">
  <label for="subcategory_id">Select Subcategory:</label>
  <select name="subcategory_id" id="subcategory_id" onchange="this.form.submit()">
    <?php
    $last_type = null;
    $last_parent = null;
	$title_type = null;
    foreach ($subcategories as $s):
      if ($s['parent_type'] !== $last_type || $s['parent_name'] !== $last_parent):
        if (isset($last_type)) echo "</optgroup>";
		$title_type = ucfirst($s['parent_type']);
        echo "<optgroup label='{$title_type} → {$s['parent_name']}'>";
        $last_type = $s['parent_type'];
        $last_parent = $s['parent_name'];
		$title_type = null;
      endif;
    ?>
      <option value="<?= $s['id'] ?>" <?= $s['id'] == $selected_subcategory ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['sub_name']) ?>
      </option>
    <?php endforeach; ?>
    </optgroup>
  </select>
</form>

<div style="display: flex; flex-wrap: wrap;">
  <div style="flex: 1; min-width: 300px;">
    <canvas id="fiscalChart"></canvas>
  </div>
  <div style="flex: 1; min-width: 300px;">
    <canvas id="monthlyChart"></canvas>
  </div>
</div>

<h3>Transactions (<?= $start->format('d M') ?>–<?= $end->format('d M Y') ?>)</h3>
<table class="table table-striped table-sm align-middle">
  <tr><th>Date</th><th>Amount</th><th>Description</th><th>Source</th></tr>
  <?php foreach ($transactions as $tx): ?>
    <tr>
      <td><?= $tx['date'] ?></td>
      <td>£<?= number_format($tx['amount'], 2) ?></td>
      <td><?= htmlspecialchars($tx['description']) ?></td>
      <td><?= $tx['source'] ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('fiscalChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_keys($fiscal_totals)) ?>,
    datasets: [{
      label: "Total",
      data: <?= json_encode(array_values($fiscal_totals)) ?>,
      backgroundColor: 'rgba(75, 192, 192, 0.6)'
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } }
  }
});

new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($months) ?>,
    datasets: [{
      label: "Monthly Total",
      data: <?= json_encode(array_values($month_totals)) ?>,
      backgroundColor: 'rgba(153, 102, 255, 0.6)'
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } }
  }
});
</script>

<?php include '../layout/footer.php'; ?>
