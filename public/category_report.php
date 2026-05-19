<?php
require_once '../config/db.php';
include '../layout/header.php';

$pdo = get_db_connection();

// 1. Determine selected category
$selected_category = isset($_GET['category_id']) ? intval($_GET['category_id']) : 277;

// 2. Load all categories
$stmt = $pdo->query("
    SELECT id, name, type
    FROM categories
    WHERE parent_id IS NULL
      AND type IN ('expense','income')
    ORDER BY type, name ASC
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Load fiscal year totals from canonical ledger lines (actuals only)
$fiscal_totals = [];
$fy_stmt = $pdo->prepare("
    SELECT
        YEAR(
            CASE
                WHEN MONTH(ll.line_date) = 1 AND DAY(ll.line_date) < 13
                    THEN DATE_SUB(ll.line_date, INTERVAL 1 YEAR)
                ELSE ll.line_date
            END
        ) AS fiscal_year,
        SUM(CASE WHEN ll.category_type = 'expense' THEN -ll.amount ELSE ll.amount END) AS total
    FROM ledger_lines ll
    WHERE ll.is_prediction = 0
      AND (ll.category_id = ? OR ll.parent_category_id = ?)
    GROUP BY fiscal_year
    ORDER BY fiscal_year
");
$fy_stmt->execute([$selected_category, $selected_category]);
while ($row = $fy_stmt->fetch(PDO::FETCH_ASSOC)) {
    $fiscal_totals[$row['fiscal_year']] = round($row['total'], 2);
}

// 4. Load recent 6 months of subcategory breakdowns from canonical ledger lines
$now = new DateTime();
$start = new DateTime($now->format('Y-m-13'));
if ((int)$now->format('d') < 13) {
    $start->modify('-1 month');
}
$end = (clone $start)->modify('+1 month')->modify('-1 day');
$start->modify('-6 months');

$month_totals = [];
$months = [];
$sub_stmt = $pdo->prepare("
    SELECT
        ll.category_id,
        ll.category_name,
        DATE_FORMAT(
            CASE
                WHEN DAY(ll.line_date) >= 13 THEN ll.line_date
                ELSE DATE_SUB(ll.line_date, INTERVAL 1 MONTH)
            END,
            '%Y-%m'
        ) AS fin_month,
        SUM(CASE WHEN ll.category_type = 'expense' THEN -ll.amount ELSE ll.amount END) AS total
    FROM ledger_lines ll
    WHERE (ll.category_id = ? OR ll.parent_category_id = ?)
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= CURDATE())
    GROUP BY ll.category_id, ll.category_name, fin_month
    ORDER BY fin_month ASC, ll.category_name ASC
");
$sub_stmt->execute([$selected_category, $selected_category, $start->format('Y-m-d'), $end->format('Y-m-d')]);
while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
    $month = $row['fin_month'];
    $cat = $row['category_name'];
    if (!isset($month_totals[$month])) {
        $month_totals[$month] = [];
    }
    $month_totals[$month][$cat] = round($row['total'], 2);
    if (!in_array($month, $months, true)) {
        $months[] = $month;
    }
}

// 5. Load individual ledger lines for current month
$stmt = $pdo->prepare("
    SELECT
        ll.source,
        ll.transaction_id AS id,
        ll.line_date AS date,
        ll.amount,
        ll.account_name AS account,
        ll.description,
        ll.category_name AS subcategory,
        ll.category_id AS cat_id,
        ll.sub_flag
    FROM ledger_lines ll
    WHERE (ll.category_id = ? OR ll.parent_category_id = ?)
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= CURDATE())
    ORDER BY
        ll.line_date DESC,
        COALESCE(ll.transaction_id, 0) DESC,
        COALESCE(ll.transaction_split_id, 0) DESC,
        COALESCE(ll.predicted_instance_id, 0) DESC
");
$stmt->execute([
    $selected_category,
    $selected_category,
    $start->format('Y-m-d'),
    $end->format('Y-m-d'),
]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat_name = array_column($categories, 'name', 'id')[$selected_category] ?? 'Unknown';
echo "<h2>Category Report: " . htmlspecialchars($cat_name) . "</h2>";
echo "<p><a href='category_edit.php?id=" . (int)$selected_category . "'>Edit " . htmlspecialchars($cat_name) . " →</a><p>";
?>

<form method="get">
  <label for="category_id">Select Category:</label>
  <select name="category_id" id="category_id" onchange="this.form.submit()">
    <?php $incomeHeader = false; $expenseHeader = false; ?>
    <?php foreach ($categories as $cat): ?>
      <?php if ($cat['type'] === 'income' && !$incomeHeader): $incomeHeader = true; echo "<optgroup label='Income Categories'>"; endif; ?>
      <?php if ($cat['type'] === 'expense' && !$expenseHeader): $expenseHeader = true; echo "<optgroup label='Expense Categories'>"; endif; ?>
      <option value="<?= (int)$cat['id'] ?>" <?= (int)$cat['id'] === $selected_category ? 'selected' : '' ?>>
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
      <td><?= htmlspecialchars($tx['date']) ?></td>
      <td>£<?= number_format((float)$tx['amount'], 2) ?></td>
      <td><?= htmlspecialchars($tx['account']) ?></td>
      <td><?= htmlspecialchars($tx['description']) ?></td>
      <td>
        <?php if (!empty($tx['sub_flag']) && (int)$tx['sub_flag'] === 1): ?>
          <a href="subcategory_report.php?subcategory_id=<?= (int)$tx['cat_id'] ?>">
            <?= htmlspecialchars($tx['subcategory']) ?>
          </a>
        <?php else: ?>
          <a href="category_report.php?category_id=<?= (int)$tx['cat_id'] ?>">
            <?= htmlspecialchars($tx['subcategory']) ?>
          </a>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars($tx['source']) ?></td>
      <td>
          <?= !empty($tx['id']) ? '<a href="transaction_edit.php?id=' . (int)$tx['id'] . '&redirect=' . urlencode($_SERVER['REQUEST_URI']) . '" title="Edit Transaction">✏️</a>' : '' ?>
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
