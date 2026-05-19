<?php
require_once '../config/db.php';
include '../layout/header.php';

$pdo = get_db_connection();

// Get selected subcategory or fallback to a default
$selected_subcategory = isset($_GET['subcategory_id']) ? intval($_GET['subcategory_id']) : 69;

// Load all subcategories, sorted by parent type, parent name, then subcategory name
$stmt = $pdo->query("
    SELECT c.id, c.name AS sub_name, p.id as parent_id, p.name AS parent_name, p.type AS parent_type
    FROM categories c
    JOIN categories p ON c.parent_id = p.id
    WHERE c.parent_id IS NOT NULL
      AND c.type IN ('expense', 'income')
    ORDER BY p.type, p.name, c.name
");
$subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get parent info for the selected subcategory
$selected_meta = null;
foreach ($subcategories as $s) {
    if ((int)$s['id'] === $selected_subcategory) {
        $selected_meta = $s;
        break;
    }
}

// Fiscal year totals from canonical ledger lines (actuals only)
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
      AND ll.category_id = ?
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
if ((int)$now->format('d') < 13) {
    $start->modify('-1 month');
}
$end = (clone $start)->modify('+1 month')->modify('-1 day');
$start->modify('-6 months');

// Month breakdown from canonical ledger lines
$month_totals = [];
$months = [];
$sub_stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(
            CASE
                WHEN DAY(ll.line_date) >= 13 THEN ll.line_date
                ELSE DATE_SUB(ll.line_date, INTERVAL 1 MONTH)
            END,
            '%Y-%m'
        ) AS fin_month,
        SUM(CASE WHEN ll.category_type = 'expense' THEN -ll.amount ELSE ll.amount END) AS total
    FROM ledger_lines ll
    WHERE ll.category_id = ?
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= CURDATE())
    GROUP BY fin_month
    ORDER BY fin_month
");
$sub_stmt->execute([$selected_subcategory, $start->format('Y-m-d'), $end->format('Y-m-d')]);
while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
    $month = $row['fin_month'];
    $month_totals[$month] = round($row['total'], 2);
    if (!in_array($month, $months, true)) {
        $months[] = $month;
    }
}

// Transaction detail list from canonical ledger lines
$tx_stmt = $pdo->prepare("
    SELECT
        ll.source,
        ll.transaction_id AS id,
        ll.line_date AS date,
        ll.amount,
        ll.account_name AS account,
        ll.description
    FROM ledger_lines ll
    WHERE ll.category_id = ?
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= CURDATE())
    ORDER BY
        ll.line_date DESC,
        COALESCE(ll.transaction_id, 0) DESC,
        COALESCE(ll.transaction_split_id, 0) DESC,
        COALESCE(ll.predicted_instance_id, 0) DESC
");
$tx_stmt->execute([
    $selected_subcategory,
    $start->format('Y-m-d'),
    $end->format('Y-m-d'),
]);
$transactions = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Subcategory Report: <?= htmlspecialchars($selected_meta['sub_name'] ?? 'Unknown') ?></h2>
<?php if ($selected_meta): ?>
    <p><a href="category_report.php?category_id=<?= (int)$selected_meta['parent_id'] ?>">Go to <?= htmlspecialchars($selected_meta['parent_name']) ?> →</a></p>
    <p><a href="category_edit.php?id=<?= (int)$selected_meta['id'] ?>">Edit <?= htmlspecialchars($selected_meta['sub_name']) ?> →</a></p>
<?php endif; ?>

<form method="get">
  <label for="subcategory_id">Select Subcategory:</label>
  <select name="subcategory_id" id="subcategory_id" onchange="this.form.submit()">
    <?php
    $last_type = null;
    $last_parent = null;
    foreach ($subcategories as $s):
      if ($s['parent_type'] !== $last_type || $s['parent_name'] !== $last_parent):
        if (isset($last_type)) echo "</optgroup>";
        $title_type = ucfirst($s['parent_type']);
        echo "<optgroup label='{$title_type} → {$s['parent_name']}'>";
        $last_type = $s['parent_type'];
        $last_parent = $s['parent_name'];
      endif;
    ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $selected_subcategory ? 'selected' : '' ?>>
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
  <tr><th>Date</th><th>Amount</th><th>Account</th><th>Description</th><th>Source</th><th></th></tr>
  <?php foreach ($transactions as $tx): ?>
    <tr>
      <td><?= htmlspecialchars($tx['date']) ?></td>
      <td>£<?= number_format((float)$tx['amount'], 2) ?></td>
      <td><?= htmlspecialchars($tx['account']) ?></td>
      <td><?= htmlspecialchars($tx['description']) ?></td>
      <td><?= htmlspecialchars($tx['source']) ?></td>
      <td>
          <?= !empty($tx['id']) ? '<a href="transaction_edit.php?id=' . (int)$tx['id'] . '&redirect=' . urlencode($_SERVER['REQUEST_URI']) . '" title="Edit Transaction">✏️</a>' : '' ?>
      </td>
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
