<?php
require_once '../config/db.php';
include '../layout/header.php';

// Default year = the year of the *current financial month start* (13th boundary)
if (isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year'])) {
    $year = (int)$_GET['year'];
} else {
    $today = new DateTime();
    if ((int)$today->format('d') < 13) {
        $today->modify('-1 month');
    }
    $year = (int)$today->format('Y');
}

// Build month_start list (financial months start on the 13th)
$months = [];      // YYYY-mm-13
$headers = [];     // "Jan–Feb 2025" style
for ($i = 1; $i <= 12; $i++) {
    $start_ts = mktime(0, 0, 0, $i, 13, $year);
    $next_month_ts = mktime(0, 0, 0, $i + 1, 12, $year);
    $headers[] = date('M', $start_ts) . '–' . date('M Y', $next_month_ts);
    $months[] = date('Y-m-d', $start_ts);
}

$year_start = new DateTime("$year-01-13");
$last_month_start = new DateTime("$year-12-13");
$year_end = (clone $last_month_start)->modify('+1 month')->modify('-1 day'); // Jan 12 next year

// Categories to exclude from budgeting/performance view
$excluded = [
    'Split/Multiple Categories',
    'Transfers',
    'Cash Withdrawal',
    'Job Expense',
    'Not an Expense',
    'Property Sale/Remortgage'
];

// Groupings (same as dashboard)
$sections = [
    'Fixed Income' => ['type' => 'income', 'fixedness' => 'fixed'],
    'Variable Income' => ['type' => 'income', 'fixedness' => 'variable'],
    'Fixed & Essential Expenses' => ['type' => 'expense', 'fixedness' => 'fixed', 'priority' => 'essential'],
    'Variable & Essential Expenses' => ['type' => 'expense', 'fixedness' => 'variable', 'priority' => 'essential'],
    'Variable & Discretionary Expenses' => ['type' => 'expense', 'fixedness' => 'variable', 'priority' => 'discretionary'],
];

// Load top-level categories
$categories = []; // keyed by id
$stmt = $pdo->query("
    SELECT id, name, type, fixedness, priority
    FROM categories
    WHERE parent_id IS NULL
      AND type IN ('income','expense')
    ORDER BY FIELD(type, 'income','expense'), budget_order, name
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (in_array($row['name'], $excluded, true)) continue;
    $categories[(int)$row['id']] = $row;
}

// Load account IDs (for ledger links)
$acct_stmt = $pdo->query("SELECT id FROM accounts WHERE type IN ('current','credit','savings') AND active=1");
$account_ids = array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
$account_query = implode('&', array_map(fn($id) => 'accounts[]=' . (int)$id, $account_ids));

// Load budgets for the year (month_start rows)
$budgets = []; // [category_id][month_start] => amount
$stmt = $pdo->prepare("
    SELECT category_id, month_start, amount
    FROM budgets
    WHERE month_start BETWEEN ? AND ?
");
$stmt->execute([$year_start->format('Y-m-d'), $last_month_start->format('Y-m-d')]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cid = (int)$row['category_id'];
    $m = $row['month_start'];
    $budgets[$cid][$m] = (float)$row['amount'];
}

// Load actuals for the year, bucketed into financial months (13th boundary) and rolled up to top-level category
$actuals_raw = []; // [top_id][month_start] => signed total (db sign)
$stmt = $pdo->prepare("
    SELECT top_id, fin_month_start, SUM(total) AS total
    FROM (
        SELECT
            IFNULL(top.id, c.id) AS top_id,
            CASE
                WHEN DAY(t.date) >= 13 THEN DATE_FORMAT(t.date, '%Y-%m-13')
                ELSE DATE_FORMAT(DATE_SUB(t.date, INTERVAL 1 MONTH), '%Y-%m-13')
            END AS fin_month_start,
            SUM(s.amount) AS total
        FROM transaction_splits s
        JOIN transactions t ON t.id = s.transaction_id
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND c.type IN ('income','expense')
        GROUP BY top_id, fin_month_start

        UNION ALL

        SELECT
            IFNULL(top.id, c.id) AS top_id,
            CASE
                WHEN DAY(t.date) >= 13 THEN DATE_FORMAT(t.date, '%Y-%m-13')
                ELSE DATE_FORMAT(DATE_SUB(t.date, INTERVAL 1 MONTH), '%Y-%m-13')
            END AS fin_month_start,
            SUM(t.amount) AS total
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        LEFT JOIN transaction_splits s ON s.transaction_id = t.id
        WHERE t.date BETWEEN ? AND ?
          AND s.id IS NULL
          AND a.type IN ('current','credit','savings')
          AND c.type IN ('income','expense')
        GROUP BY top_id, fin_month_start
    ) combined
    GROUP BY top_id, fin_month_start
");
$stmt->execute([
    $year_start->format('Y-m-d'), $year_end->format('Y-m-d'),
    $year_start->format('Y-m-d'), $year_end->format('Y-m-d')
]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cid = (int)$row['top_id'];
    $m = $row['fin_month_start'];
    $actuals_raw[$cid][$m] = (float)$row['total'];
}

// Helpers
function fmt_money(float $v): string {
    return '£' . number_format($v, 2);
}

function variance(float $actual, float $budget, string $type): float {
    // For income:  actual - budget (positive = good)
    // For expense: budget - actual (positive = good, i.e. under budget)
    return ($type === 'expense') ? ($budget - $actual) : ($actual - $budget);
}

// Totals collectors
$overall = [
    'income' => ['actual' => array_fill(0, 12, 0.0), 'budget' => array_fill(0, 12, 0.0)],
    'expense' => ['actual' => array_fill(0, 12, 0.0), 'budget' => array_fill(0, 12, 0.0)],
];
$section_totals = []; // [label][actual/budget][i]
foreach ($sections as $label => $_) {
    $section_totals[$label] = ['actual' => array_fill(0, 12, 0.0), 'budget' => array_fill(0, 12, 0.0)];
}

?>

<style>
  /* Full width (the header wraps pages in .container by default) */
  .container { max-width: 100% !important; }

  .perf-table { font-size: 0.9rem; }
  .perf-table th, .perf-table td { vertical-align: middle; }
  .perf-table th.month-col, .perf-table td.month-col { min-width: 140px; }
  .perf-table td.month-col { line-height: 1.15; }

  .cell-main { white-space: nowrap; }
  .cell-sub { font-size: 0.8em; }

  .print-controls { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }

  @media print {
    @page { size: A4 landscape; margin: 10mm; }
    body { padding-top: 0 !important; background: #fff !important; }
    nav.navbar, footer, .print-controls { display: none !important; }
    .container { max-width: 100% !important; }
    a { color: #000 !important; text-decoration: none !important; }

    /* Sticky can behave oddly in print */
    .sticky-col, table.dash-table thead th { position: static !important; }

    .perf-table { font-size: 9pt; }
    .perf-table th.month-col, .perf-table td.month-col { min-width: 110px; }
  }
</style>

<h1 class="mb-3">📅 Budget Performance by Category (<?= htmlspecialchars((string)$year) ?>)</h1>

<div class="print-controls mb-3">
  <form method="GET" class="d-flex gap-2 align-items-end">
    <div>
      <label for="year" class="form-label">Year</label>
      <select name="year" id="year" class="form-select" onchange="this.form.submit()">
        <?php for ($y = $year - 2; $y <= $year + 2; $y++): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </form>

  <button class="btn btn-outline-secondary" onclick="window.print()">🖨️ Print</button>
</div>

<p class="text-muted mb-4">
  Financial months run from the <strong>13th</strong> to the <strong>12th</strong>. This report covers
  <strong><?= $year_start->format('j M Y') ?></strong> to <strong><?= $year_end->format('j M Y') ?></strong>.
</p>

<table class="table table-sm table-striped table-bordered align-middle dash-table perf-table">
  <thead class="table-dark">
    <tr>
      <th class="sticky-col">Category</th>
      <?php foreach ($headers as $h): ?>
        <th class="month-col text-center"><?= htmlspecialchars($h) ?></th>
      <?php endforeach; ?>
      <th class="text-end">YTD Actual</th>
      <th class="text-end">YTD Budget</th>
      <th class="text-end">YTD Var</th>
      <th class="text-end">Var %</th>
    </tr>
  </thead>
  <tbody>

<?php
$colspan = 1 + count($months) + 4;

foreach ($sections as $section_label => $filter):
    echo "<tr class='table-dark fw-bold'><td class='sticky-col' colspan='" . $colspan . "'>" . htmlspecialchars($section_label) . "</td></tr>";

    $had_rows = false;

    foreach ($categories as $cid => $cat):
        if ($cat['type'] !== $filter['type']) continue;
        if (isset($filter['fixedness']) && $cat['fixedness'] !== $filter['fixedness']) continue;
        if (isset($filter['priority']) && $cat['priority'] !== $filter['priority']) continue;

        // Compute YTD and decide whether to show row
        $ytd_budget = 0.0;
        $ytd_actual = 0.0;

        // pre-check: any budget or actual across year?
        for ($i = 0; $i < 12; $i++) {
            $m = $months[$i];
            $b = (float)($budgets[$cid][$m] ?? 0);
            $raw = (float)($actuals_raw[$cid][$m] ?? 0);
            $a = ($cat['type'] === 'expense') ? (-1 * $raw) : $raw;

            $ytd_budget += $b;
            $ytd_actual += $a;
        }
        if (abs($ytd_budget) < 0.005 && abs($ytd_actual) < 0.005) {
            continue;
        }

        $had_rows = true;

        $ytd_var = variance($ytd_actual, $ytd_budget, $cat['type']);
        $ytd_class = $ytd_var >= 0 ? 'text-success' : 'text-danger';
        $ytd_sign = $ytd_var > 0 ? '+' : '';
        $ytd_pct = ($ytd_budget != 0) ? ($ytd_var / $ytd_budget) : null;

        echo "<tr>";
        echo "<td class='sticky-col'><a href='category_report.php?category_id=" . (int)$cid . "'>" . htmlspecialchars($cat['name']) . "</a></td>";

        for ($i = 0; $i < 12; $i++):
            $m = $months[$i];
            $b = (float)($budgets[$cid][$m] ?? 0);
            $raw = (float)($actuals_raw[$cid][$m] ?? 0);
            $a = ($cat['type'] === 'expense') ? (-1 * $raw) : $raw;
            $v = variance($a, $b, $cat['type']);
            $v_class = $v >= 0 ? 'text-success' : 'text-danger';
            $v_sign = $v > 0 ? '+' : '';

            // Accumulate totals
            $overall[$cat['type']]['actual'][$i] += $a;
            $overall[$cat['type']]['budget'][$i] += $b;
            $section_totals[$section_label]['actual'][$i] += $a;
            $section_totals[$section_label]['budget'][$i] += $b;

            $link_start = $m;
            $link_end = (new DateTime($m))->modify('+1 month')->modify('-1 day')->format('Y-m-d');
            $link = "ledger.php?$account_query&start=$link_start&end=$link_end&parent_id=$cid";

            if (abs($a) < 0.005 && abs($b) < 0.005) {
                echo "<td class='month-col text-center text-muted'>—</td>";
            } else {
                echo "<td class='month-col text-center'>";
                echo "<div class='cell-main'><a class='text-decoration-none fw-semibold' href='" . htmlspecialchars($link) . "'>" . fmt_money($a) . "</a><span class='text-muted'> / " . fmt_money($b) . "</span></div>";
                echo "<div class='cell-sub $v_class'>" . $v_sign . fmt_money($v) . "</div>";
                echo "</td>";
            }
        endfor;

        echo "<td class='text-end'>" . fmt_money($ytd_actual) . "</td>";
        echo "<td class='text-end'>" . fmt_money($ytd_budget) . "</td>";
        echo "<td class='text-end $ytd_class'>" . $ytd_sign . fmt_money($ytd_var) . "</td>";
        echo "<td class='text-end'>" . ($ytd_pct === null ? '—' : sprintf('%.1f%%', $ytd_pct * 100)) . "</td>";
        echo "</tr>";

    endforeach;

    // Section totals row
    if ($had_rows) {
        $sec_ytd_actual = array_sum($section_totals[$section_label]['actual']);
        $sec_ytd_budget = array_sum($section_totals[$section_label]['budget']);
        $sec_type = $filter['type'];
        $sec_ytd_var = variance($sec_ytd_actual, $sec_ytd_budget, $sec_type);
        $sec_class = $sec_ytd_var >= 0 ? 'text-success' : 'text-danger';
        $sec_sign = $sec_ytd_var > 0 ? '+' : '';
        $sec_pct = ($sec_ytd_budget != 0) ? ($sec_ytd_var / $sec_ytd_budget) : null;

        echo "<tr class='fw-bold'>";
        echo "<td class='sticky-col'>Total: " . htmlspecialchars($section_label) . "</td>";
        for ($i = 0; $i < 12; $i++) {
            $a = $section_totals[$section_label]['actual'][$i];
            $b = $section_totals[$section_label]['budget'][$i];
            $v = variance($a, $b, $sec_type);
            $v_class = $v >= 0 ? 'text-success' : 'text-danger';
            $v_sign = $v > 0 ? '+' : '';

            if (abs($a) < 0.005 && abs($b) < 0.005) {
                echo "<td class='month-col text-center text-muted'>—</td>";
            } else {
                echo "<td class='month-col text-center'>";
                echo "<div class='cell-main fw-semibold'>" . fmt_money($a) . "<span class='text-muted'> / " . fmt_money($b) . "</span></div>";
                echo "<div class='cell-sub $v_class'>" . $v_sign . fmt_money($v) . "</div>";
                echo "</td>";
            }
        }
        echo "<td class='text-end'>" . fmt_money($sec_ytd_actual) . "</td>";
        echo "<td class='text-end'>" . fmt_money($sec_ytd_budget) . "</td>";
        echo "<td class='text-end $sec_class'>" . $sec_sign . fmt_money($sec_ytd_var) . "</td>";
        echo "<td class='text-end'>" . ($sec_pct === null ? '—' : sprintf('%.1f%%', $sec_pct * 100)) . "</td>";
        echo "</tr>";
    }

endforeach;

// Overall totals (Income, Expenses, Net)
$inc_ytd_actual = array_sum($overall['income']['actual']);
$inc_ytd_budget = array_sum($overall['income']['budget']);
$inc_ytd_var = variance($inc_ytd_actual, $inc_ytd_budget, 'income');
$inc_class = $inc_ytd_var >= 0 ? 'text-success' : 'text-danger';
$inc_sign = $inc_ytd_var > 0 ? '+' : '';
$inc_pct = ($inc_ytd_budget != 0) ? ($inc_ytd_var / $inc_ytd_budget) : null;

$exp_ytd_actual = array_sum($overall['expense']['actual']);
$exp_ytd_budget = array_sum($overall['expense']['budget']);
$exp_ytd_var = variance($exp_ytd_actual, $exp_ytd_budget, 'expense');
$exp_class = $exp_ytd_var >= 0 ? 'text-success' : 'text-danger';
$exp_sign = $exp_ytd_var > 0 ? '+' : '';
$exp_pct = ($exp_ytd_budget != 0) ? ($exp_ytd_var / $exp_ytd_budget) : null;

$net_actual = [];
$net_budget = [];
for ($i = 0; $i < 12; $i++) {
    $net_actual[$i] = $overall['income']['actual'][$i] - $overall['expense']['actual'][$i];
    $net_budget[$i] = $overall['income']['budget'][$i] - $overall['expense']['budget'][$i];
}
$net_ytd_actual = $inc_ytd_actual - $exp_ytd_actual;
$net_ytd_budget = $inc_ytd_budget - $exp_ytd_budget;
$net_ytd_var = $net_ytd_actual - $net_ytd_budget;
$net_class = $net_ytd_var >= 0 ? 'text-success' : 'text-danger';
$net_sign = $net_ytd_var > 0 ? '+' : '';
$net_pct = ($net_ytd_budget != 0) ? ($net_ytd_var / $net_ytd_budget) : null;

?>

    <tr class="table-dark fw-bold">
      <td class="sticky-col">Total Income</td>
      <?php for ($i = 0; $i < 12; $i++):
        $a = $overall['income']['actual'][$i];
        $b = $overall['income']['budget'][$i];
        $v = variance($a, $b, 'income');
        $v_class = $v >= 0 ? 'text-success' : 'text-danger';
        $v_sign = $v > 0 ? '+' : '';
      ?>
        <td class="month-col text-center">
          <div class="cell-main fw-semibold"><?= fmt_money($a) ?><span class="text-muted"> / <?= fmt_money($b) ?></span></div>
          <div class="cell-sub <?= $v_class ?>"><?= $v_sign . fmt_money($v) ?></div>
        </td>
      <?php endfor; ?>
      <td class="text-end"><?= fmt_money($inc_ytd_actual) ?></td>
      <td class="text-end"><?= fmt_money($inc_ytd_budget) ?></td>
      <td class="text-end <?= $inc_class ?>"><?= $inc_sign . fmt_money($inc_ytd_var) ?></td>
      <td class="text-end"><?= $inc_pct === null ? '—' : sprintf('%.1f%%', $inc_pct * 100) ?></td>
    </tr>

    <tr class="table-dark fw-bold">
      <td class="sticky-col">Total Expenses</td>
      <?php for ($i = 0; $i < 12; $i++):
        $a = $overall['expense']['actual'][$i];
        $b = $overall['expense']['budget'][$i];
        $v = variance($a, $b, 'expense');
        $v_class = $v >= 0 ? 'text-success' : 'text-danger';
        $v_sign = $v > 0 ? '+' : '';
      ?>
        <td class="month-col text-center">
          <div class="cell-main fw-semibold"><?= fmt_money($a) ?><span class="text-muted"> / <?= fmt_money($b) ?></span></div>
          <div class="cell-sub <?= $v_class ?>"><?= $v_sign . fmt_money($v) ?></div>
        </td>
      <?php endfor; ?>
      <td class="text-end"><?= fmt_money($exp_ytd_actual) ?></td>
      <td class="text-end"><?= fmt_money($exp_ytd_budget) ?></td>
      <td class="text-end <?= $exp_class ?>"><?= $exp_sign . fmt_money($exp_ytd_var) ?></td>
      <td class="text-end"><?= $exp_pct === null ? '—' : sprintf('%.1f%%', $exp_pct * 100) ?></td>
    </tr>

    <tr class="table-dark fw-bold">
      <td class="sticky-col">Net</td>
      <?php for ($i = 0; $i < 12; $i++):
        $a = $net_actual[$i];
        $b = $net_budget[$i];
        $v = $a - $b;
        $v_class = $v >= 0 ? 'text-success' : 'text-danger';
        $v_sign = $v > 0 ? '+' : '';
      ?>
        <td class="month-col text-center">
          <div class="cell-main fw-semibold"><?= fmt_money($a) ?><span class="text-muted"> / <?= fmt_money($b) ?></span></div>
          <div class="cell-sub <?= $v_class ?>"><?= $v_sign . fmt_money($v) ?></div>
        </td>
      <?php endfor; ?>
      <td class="text-end"><?= fmt_money($net_ytd_actual) ?></td>
      <td class="text-end"><?= fmt_money($net_ytd_budget) ?></td>
      <td class="text-end <?= $net_class ?>"><?= $net_sign . fmt_money($net_ytd_var) ?></td>
      <td class="text-end"><?= $net_pct === null ? '—' : sprintf('%.1f%%', $net_pct * 100) ?></td>
    </tr>

  </tbody>
</table>

<?php include '../layout/footer.php'; ?>
