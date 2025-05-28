<?php
require_once '../config/db.php';
include '../layout/header.php';

$pdo = get_db_connection();

// 1. Determine selected category
$selected_category = isset($_GET['category_id']) ? intval($_GET['category_id']) : 277;

// 2. Load all categories
$stmt = $pdo->query("SELECT id, name, type FROM categories where parent_id is null and type in ('expense','income') ORDER BY type, name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Load fiscal year totals
$fiscal_totals = [];
$fy_stmt = $pdo->prepare("
    SELECT
        YEAR(CASE WHEN MONTH(t.date) = 1 AND DAY(t.date) < 13 THEN DATE_SUB(t.date, INTERVAL 1 YEAR) ELSE t.date END) AS fiscal_year,
        SUM(CASE WHEN coalesce(top.type, c.type) = 'expense' or coalesce(tops.type, cs.type) = 'expense' THEN -t.amount else t.amount end) AS total
    FROM transactions t
    left join transaction_splits ts on ts.transaction_id = t.id
    join categories c on t.category_id=c.id
    left join categories top on c.parent_id=top.id
    left join categories cs on ts.category_id=cs.id
    left join categories tops on cs.parent_id=tops.id
    WHERE coalesce(top.id, c.id) = ? or coalesce(tops.id, cs.id) = ?
    GROUP BY fiscal_year
    ORDER BY fiscal_year

");
$fy_stmt->execute([$selected_category, $selected_category]);
while ($row = $fy_stmt->fetch(PDO::FETCH_ASSOC)) {
    $fiscal_totals[$row['fiscal_year']] = round($row['total'], 2);
}

// 4. Load recent 6 months of subcategory breakdowns

$now = new DateTime();
$start = new DateTime($now->format('Y-m-13'));
if ((int)$now->format('d') < 13) $start->modify('-1 month');
$end = (clone $start)->modify('+1 month')->modify('-1 day');
$start->modify('-6 months');

$month_totals = [];
$months = [];
$sub_stmt = $pdo->prepare("
    SELECT
        COALESCE(c.parent_id, c.id) AS top_category,
        IF(c.parent_id IS NULL, c.name, (SELECT name FROM categories WHERE id = c.parent_id)) AS parent_name,
        c.id AS category_id,
        c.name AS category_name,
        DATE_FORMAT(CASE WHEN DAY(t.date) >= 13 THEN t.date ELSE DATE_SUB(t.date, INTERVAL 1 MONTH) END, '%Y-%m') AS fin_month,
        SUM(CASE WHEN coalesce(top.type, c.type) = 'expense' THEN -t.amount else t.amount end) AS total
    FROM (
        SELECT date, amount, category_id FROM transactions
        UNION ALL
        SELECT scheduled_date AS date, amount, category_id FROM predicted_instances
		UNION all
		SELECT t.date, ts.amount, ts.category_id from transaction_splits ts join transactions t on t.id=ts.transaction_id
    ) t
    JOIN categories c ON c.id = t.category_id
	left join categories top on top.id = c.parent_id
    WHERE (c.id = ? OR c.parent_id = ?)
      AND t.date between ? and ?
    GROUP BY category_id, fin_month
    ORDER BY fin_month ASC, category_name ASC
");
$sub_stmt->execute([$selected_category, $selected_category, $start->format('Y-m-d'), $end->format('Y-m-d')]);
while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
    $month = $row['fin_month'];
    $cat = $row['category_name'];
    if (!isset($month_totals[$month])) $month_totals[$month] = [];
    $month_totals[$month][$cat] = round($row['total'], 2);
    if (!in_array($month, $months)) $months[] = $month;
}

// 5. Load individual transactions and predicted instances for current month


$stmt = $pdo->prepare("
	SELECT 'Actual' AS source, t.id, t.date, t.amount, a.name as account, COALESCE(p.name, t.description) as description, c.name as subcategory, c.id as cat_id, (case when c.parent_id is not null then 1 else 0 end) as sub_flag
    FROM transactions t
    join categories c on t.category_id=c.id
    join accounts a on t.account_id=a.id
    left join categories top on c.parent_id=top.id
	LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id
	left join payees p on p.id = t.payee_id
    WHERE coalesce(top.id, c.id) = ?
     AND t.date between ? and ?
	 AND ts.transaction_id IS NULL
	
	UNION ALL
	
	Select 'Split' AS source, t.id, t.date, ts.amount, a.name as account, COALESCE(p.name, t.description) as description, c.name as subcategory, c.id as cat_id, (case when c.parent_id is not null then 1 else 0 end) as sub_flag
	from transaction_splits ts
	join transactions t on t.id=ts.transaction_id
    join categories c on ts.category_id=c.id
    join accounts a on t.account_id=a.id
    left join categories top on c.parent_id=top.id
	left join payees p on p.id = t.payee_id
    WHERE coalesce(top.id, c.id) = ?
     AND t.date between ? and ?
	
    
    UNION ALL
    SELECT 'Predicted' AS source, '' as id, pi.scheduled_date, pi.amount, a.name as account, COALESCE(p.name, pi.description) as description, c.name as subcategory, c.id as cat_id, (case when c.parent_id is not null then 1 else 0 end) as sub_flag
    FROM predicted_instances pi
    join categories c on pi.category_id=c.id
    join accounts a on pi.from_account_id=a.id
    left join categories top on c.parent_id=top.id
	left join payee_patterns pp on pi.description like pp.match_pattern
	left join payees p on pp.payee_id = p.id
    WHERE coalesce(top.id, c.id) = ?
    AND pi.scheduled_date between ? and ?
    
    ORDER BY date DESC
");
$stmt->execute([$selected_category, $start->format('Y-m-d'), $end->format('Y-m-d'),$selected_category, $start->format('Y-m-d'), $end->format('Y-m-d'), $selected_category, $start->format('Y-m-d'), $end->format('Y-m-d')]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
$cat_name = array_column($categories, 'name', 'id')[$selected_category] ?? 'Unknown';
echo "<h2>Category Report: " . htmlspecialchars($cat_name) . "</h2>";
?>

<form method="get">
  <label for="category_id">Select Category:</label>
  <select name="category_id" id="category_id" onchange="this.form.submit()">
    <?php foreach ($categories as $cat): ?>
      <?php if ($cat['type'] === 'income' && !$incomeHeader): $incomeHeader = true; echo "<optgroup label='Income Categories'>"; endif; ?>
      <?php if ($cat['type'] === 'expense' && !$expenseHeader): $expenseHeader = true; echo "<optgroup label='Expense Categories'>"; endif; ?>
      <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $selected_category ? 'selected' : '' ?>>
        <?= htmlspecialchars($cat['name']) ?>
      </option>
    <?php endforeach; ?>
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
  <tr><th>Date</th><th>Amount</th><th>Account</th><th>Description</th><th>Category</th><th>Source</th><th></th></tr>
  <?php foreach ($transactions as $tx): ?>
    <tr>
      <td><?= $tx['date'] ?></td>
      <td>£<?= number_format($tx['amount'], 2) ?></td>
      <td><?= htmlspecialchars($tx['account']) ?></td>
      <td><?= htmlspecialchars($tx['description']) ?></td>
      <td>
        <?php if (!empty($tx['sub_flag']) && $tx['sub_flag'] == 1): ?>
          <a href="subcategory_report.php?subcategory_id=<?= $tx['cat_id'] ?>">
            <?= htmlspecialchars($tx['subcategory']) ?>
          </a>
        <?php else: ?>
          <a href="category_report.php?category_id=<?= $tx['cat_id'] ?>">
            <?= htmlspecialchars($tx['subcategory']) ?>
          </a>
        <?php endif; ?>
      </td>
      <td><?= $tx['source'] ?></td>
      <td>
	  <?= $tx['id'] != '' ? '<a href="transaction_edit.php?id=' . $tx['id'] . '&redirect=' . urlencode($_SERVER['REQUEST_URI']) .'" title="Edit Transaction">✏️</a>' : '' ?>
	  </td>
    </tr>
  <?php endforeach; ?>
</table>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const fiscalData = {
  labels: <?= json_encode(array_keys($fiscal_totals)) ?>,
  datasets: [{
    label: "Total",
    data: <?= json_encode(array_values($fiscal_totals)) ?>,
    backgroundColor: 'rgba(54, 162, 235, 0.6)'
  }]
};

const monthlyLabels = <?= json_encode($months) ?>;
const monthlyDatasets = [
<?php
$all_subcats = [];
foreach ($month_totals as $month => $cats) {
  foreach ($cats as $cat => $_) $all_subcats[$cat] = true;
}
foreach (array_keys($all_subcats) as $cat) {
  echo "{ label: " . json_encode($cat) . ", data: [";
  foreach ($months as $m) {
    echo isset($month_totals[$m][$cat]) ? $month_totals[$m][$cat] . "," : "0,";
  }
  echo "], backgroundColor: 'rgba(" . rand(0,255) . "," . rand(0,255) . "," . rand(0,255) . ",0.5)' },\n";
}
?>
];

new Chart(document.getElementById('fiscalChart'), {
  type: 'bar',
  data: fiscalData,
  options: { responsive: true, plugins: { legend: { display: false }}}
});
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: monthlyLabels,
    datasets: monthlyDatasets
  },
  options: {
    responsive: true,
    scales: { x: { stacked: true }, y: { stacked: true } }
  }
});
</script>

<?php include '../layout/footer.php'; ?>
