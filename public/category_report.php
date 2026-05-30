<?php
require_once '../config/db.php';
include '../layout/header.php';

$pdo = get_db_connection();

function cr_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cr_money($value): string
{
    return '£' . number_format((float)$value, 2);
}

function cr_average(array $values): float
{
    if (count($values) === 0) {
        return 0.0;
    }
    return round(array_sum($values) / count($values), 2);
}

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

// 3b. Load fiscal year totals by subcategory for figures table
$fiscal_subcategory_totals = [];
$fiscal_subcategories = [];
$fy_sub_stmt = $pdo->prepare("
    SELECT
        YEAR(
            CASE
                WHEN MONTH(ll.line_date) = 1 AND DAY(ll.line_date) < 13
                    THEN DATE_SUB(ll.line_date, INTERVAL 1 YEAR)
                ELSE ll.line_date
            END
        ) AS fiscal_year,
        ll.category_name,
        SUM(CASE WHEN ll.category_type = 'expense' THEN -ll.amount ELSE ll.amount END) AS total
    FROM ledger_lines ll
    WHERE ll.is_prediction = 0
      AND (ll.category_id = ? OR ll.parent_category_id = ?)
    GROUP BY fiscal_year, ll.category_name
    ORDER BY ll.category_name ASC, fiscal_year ASC
");
$fy_sub_stmt->execute([$selected_category, $selected_category]);
while ($row = $fy_sub_stmt->fetch(PDO::FETCH_ASSOC)) {
    $fy = (string)$row['fiscal_year'];
    $subcat = (string)$row['category_name'];

    if (!isset($fiscal_subcategory_totals[$subcat])) {
        $fiscal_subcategory_totals[$subcat] = [];
    }
    $fiscal_subcategory_totals[$subcat][$fy] = round($row['total'], 2);
    $fiscal_subcategories[$subcat] = true;
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

// Build table-friendly fiscal labels and rows
$fiscal_years = array_map('strval', array_keys($fiscal_totals));
sort($fiscal_years);

$all_subcats = [];
foreach ($month_totals as $month => $cats) {
    foreach ($cats as $cat => $_) {
        $all_subcats[$cat] = true;
    }
}
foreach (array_keys($fiscal_subcategories) as $subcat) {
    $all_subcats[$subcat] = true;
}
$all_subcats = array_keys($all_subcats);
sort($all_subcats);

$fiscal_total_row = [];
foreach ($fiscal_years as $fy) {
    $fiscal_total_row[$fy] = round((float)($fiscal_totals[$fy] ?? 0), 2);
}

$monthly_total_row = [];
foreach ($months as $m) {
    $monthly_total_row[$m] = 0.0;
    foreach ($all_subcats as $cat) {
        $monthly_total_row[$m] += (float)($month_totals[$m][$cat] ?? 0.0);
    }
    $monthly_total_row[$m] = round($monthly_total_row[$m], 2);
}

$cat_name = array_column($categories, 'name', 'id')[$selected_category] ?? 'Unknown';
echo "<h2>Category Report: " . cr_h($cat_name) . "</h2>";
echo "<p><a href='category_edit.php?id=" . (int)$selected_category . "'>Edit " . cr_h($cat_name) . " →</a><p>";
?>

<form method="get">
  <label for="category_id">Select Category:</label>
  <select name="category_id" id="category_id" onchange="this.form.submit()">
    <?php $incomeHeader = false; $expenseHeader = false; ?>
    <?php foreach ($categories as $cat): ?>
      <?php if ($cat['type'] === 'income' && !$incomeHeader): $incomeHeader = true; echo "<optgroup label='Income Categories'>"; endif; ?>
      <?php if ($cat['type'] === 'expense' && !$expenseHeader): $expenseHeader = true; echo "<optgroup label='Expense Categories'>"; endif; ?>
      <option value="<?= (int)$cat['id'] ?>" <?= (int)$cat['id'] === $selected_category ? 'selected' : '' ?>>
        <?= cr_h($cat['name']) ?>
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

<h3 class="mt-4">Annual Figures</h3>
<div class="table-responsive mb-4">
  <table class="table table-sm table-bordered align-middle">
    <thead class="table-light">
      <tr>
        <th>Subcategory</th>
        <?php foreach ($fiscal_years as $fy): ?>
          <th class="text-end"><?= cr_h($fy) ?></th>
        <?php endforeach; ?>
        <th class="text-end">Average</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($all_subcats as $subcat): ?>
        <?php
          $rowValues = [];
          foreach ($fiscal_years as $fy) {
              $rowValues[] = (float)($fiscal_subcategory_totals[$subcat][$fy] ?? 0.0);
          }
        ?>
        <tr>
          <td><?= cr_h($subcat) ?></td>
          <?php foreach ($fiscal_years as $idx => $fy): ?>
            <td class="text-end"><?= cr_money($rowValues[$idx]) ?></td>
          <?php endforeach; ?>
          <td class="text-end fw-semibold"><?= cr_money(cr_average($rowValues)) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="table-secondary fw-bold">
        <td>Total</td>
        <?php
          $totalValues = [];
          foreach ($fiscal_years as $fy) {
              $value = (float)($fiscal_total_row[$fy] ?? 0.0);
              $totalValues[] = $value;
              echo '<td class="text-end">' . cr_money($value) . '</td>';
          }
        ?>
        <td class="text-end"><?= cr_money(cr_average($totalValues)) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<h3>Past 6 Financial Months Figures</h3>
<div class="table-responsive mb-4">
  <table class="table table-sm table-bordered align-middle">
    <thead class="table-light">
      <tr>
        <th>Subcategory</th>
        <?php foreach ($months as $month): ?>
          <th class="text-end"><?= cr_h($month) ?></th>
        <?php endforeach; ?>
        <th class="text-end">Average</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($all_subcats as $subcat): ?>
        <?php
          $rowValues = [];
          foreach ($months as $month) {
              $rowValues[] = (float)($month_totals[$month][$subcat] ?? 0.0);
          }
        ?>
        <tr>
          <td><?= cr_h($subcat) ?></td>
          <?php foreach ($months as $idx => $month): ?>
            <td class="text-end"><?= cr_money($rowValues[$idx]) ?></td>
          <?php endforeach; ?>
          <td class="text-end fw-semibold"><?= cr_money(cr_average($rowValues)) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="table-secondary fw-bold">
        <td>Total</td>
        <?php
          $totalValues = [];
          foreach ($months as $month) {
              $value = (float)($monthly_total_row[$month] ?? 0.0);
              $totalValues[] = $value;
              echo '<td class="text-end">' . cr_money($value) . '</td>';
          }
        ?>
        <td class="text-end"><?= cr_money(cr_average($totalValues)) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<h3>Transactions (<?= $start->format('d M') ?>–<?= $end->format('d M Y') ?>)</h3>
<table class="table table-striped table-sm align-middle">
  <tr><th>Date</th><th>Amount</th><th>Account</th><th>Description</th><th>Category</th><th>Source</th><th></th></tr>
  <?php foreach ($transactions as $tx): ?>
    <tr>
      <td><?= cr_h($tx['date']) ?></td>
      <td><?= cr_money((float)$tx['amount']) ?></td>
      <td><?= cr_h($tx['account']) ?></td>
      <td><?= cr_h($tx['description']) ?></td>
      <td>
        <?php if (!empty($tx['sub_flag']) && (int)$tx['sub_flag'] === 1): ?>
          <a href="subcategory_report.php?subcategory_id=<?= (int)$tx['cat_id'] ?>">
            <?= cr_h($tx['subcategory']) ?>
          </a>
        <?php else: ?>
          <a href="category_report.php?category_id=<?= (int)$tx['cat_id'] ?>">
            <?= cr_h($tx['subcategory']) ?>
          </a>
        <?php endif; ?>
      </td>
      <td><?= cr_h($tx['source']) ?></td>
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
foreach ($all_subcats as $cat) {
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
