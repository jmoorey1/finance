<?php
require_once '../config/db.php';
require_once '../scripts/lib/finance_periods.php';

$pdo = get_db_connection();

function insights_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

function insights_budget_pct(?float $actual, ?float $budget): ?int
{
    $actual = (float)($actual ?? 0);
    $budget = (float)($budget ?? 0);

    if ($budget <= 0) {
        return null;
    }

    return (int)round(($actual / $budget) * 100);
}

$period = get_financial_month_range();
$today = $period['today'];
$start_date = $period['start'];
$end_date = $period['end'];

$total_days = (int)$start_date->diff($end_date)->days + 1;
$effective_today = $today > $end_date ? $end_date : $today;
$elapsed_days = max(1, (int)$start_date->diff($effective_today)->days + 1);
$month_elapsed_fac = min(1, round($elapsed_days / $total_days, 4));
$month_elapsed_pct = min(100, (int)round(($elapsed_days / $total_days) * 100));

// Load Expense Category Metadata
$categories = [];
$stmt = $pdo->query("
    SELECT id, name, parent_id, type, fixedness, priority
    FROM categories
    WHERE type = 'expense'
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[(int)$row['id']] = $row;
}

// Load Budgets for Current Financial Month
$budgets = [];
$stmt = $pdo->prepare("
    SELECT category_id, amount
    FROM budgets
    WHERE month_start = ?
");
$stmt->execute([$start_date->format('Y-m-d')]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $budgets[(int)$row['category_id']] = (float)$row['amount'];
}

// Load IDs of all current/credit/savings accounts for linking
$acct_stmt = $pdo->query("
    SELECT id
    FROM accounts
    WHERE type IN ('current','credit','savings')
      AND active = 1
");
$account_ids = array_map('intval', array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
$account_query = implode('&', array_map(fn($id) => "accounts[]={$id}", $account_ids));

// Load Actual + Future Open Predicted Spend by top category using ledger_lines
$actuals = [];
$stmt = $pdo->prepare("
    SELECT
        COALESCE(ll.parent_category_id, ll.category_id) AS category_id,
        SUM(-ll.amount) AS total
    FROM ledger_lines ll
    JOIN accounts a
      ON a.id = ll.account_id
    WHERE ll.category_type = 'expense'
      AND a.type IN ('current','credit','savings')
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= ?)
    GROUP BY COALESCE(ll.parent_category_id, ll.category_id)
");
$stmt->execute([
    $start_date->format('Y-m-d'),
    $end_date->format('Y-m-d'),
    $today->format('Y-m-d'),
]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $actuals[(int)$row['category_id']] = (float)$row['total'];
}

// Analyse Spending vs Budget
$unbudgeted = [];
$overspent = [];
$underspent = [];
$highspent = [];
$totals = [];

foreach ($actuals as $cat_id => $actual) {
    $meta = $categories[$cat_id] ?? null;

    if (!$meta || $meta['type'] !== 'expense') {
        continue;
    }

    $budget = (float)($budgets[$cat_id] ?? 0);
    $fixed = ($meta['fixedness'] ?? '') === 'fixed';

    if (!$fixed) {
        if ($budget <= 0) {
            if ($actual > 0) {
                $unbudgeted[] = [
                    'name' => (string)$meta['name'],
                    'actual' => $actual,
                    'id' => $cat_id,
                ];
            }
        } elseif ($actual > $budget) {
            $overspent[] = [
                'name' => (string)$meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $actual - $budget,
                'id' => $cat_id,
            ];
        } elseif ($actual < $month_elapsed_fac * $budget) {
            $underspent[] = [
                'name' => (string)$meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget - $actual,
                'id' => $cat_id,
            ];
        } else {
            $highspent[] = [
                'name' => (string)$meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget - $actual,
                'id' => $cat_id,
            ];
        }
    }

    $parent_id = $meta['parent_id'] ?? null;
    $name = $parent_id && isset($categories[(int)$parent_id])
        ? $categories[(int)$parent_id]['name']
        : $meta['name'];

    $totals[$name] = ($totals[$name] ?? 0) + $actual;
}

// Identify Top Spending Categories (Discretionary Only)
$discretionary_totals = [];

foreach ($actuals as $cat_id => $actual) {
    $meta = $categories[$cat_id] ?? null;

    if (!$meta || ($meta['priority'] ?? '') !== 'discretionary') {
        continue;
    }

    $parent_id = isset($meta['parent_id']) ? (int)$meta['parent_id'] : null;
    $canonical_id = $parent_id ?: $cat_id;
    $canonical_name = $parent_id && isset($categories[$parent_id])
        ? $categories[$parent_id]['name']
        : $meta['name'];

    if (!isset($discretionary_totals[$canonical_id])) {
        $discretionary_totals[$canonical_id] = [
            'id' => $canonical_id,
            'name' => $canonical_name,
            'total' => 0,
        ];
    }

    $discretionary_totals[$canonical_id]['total'] += $actual;
}

$discretionary_totals = array_values($discretionary_totals);
usort($discretionary_totals, fn($a, $b) => $b['total'] <=> $a['total']);
$top_categories = array_slice($discretionary_totals, 0, 5);

// Identify Top Vendors by Description using canonical ledger lines
$stmt = $pdo->prepare("
    SELECT
        ll.description,
        SUM(-ll.amount) AS total
    FROM ledger_lines ll
    JOIN accounts a
      ON a.id = ll.account_id
    JOIN categories topcat
      ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
    WHERE ll.category_type = 'expense'
      AND topcat.priority = 'discretionary'
      AND a.type IN ('current','credit','savings')
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= ?)
    GROUP BY ll.description
    ORDER BY total DESC, ll.description ASC
    LIMIT 5
");
$stmt->execute([
    $start_date->format('Y-m-d'),
    $end_date->format('Y-m-d'),
    $today->format('Y-m-d'),
]);
$top_vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals for discretionary proportion
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(-ll.amount), 0) AS total
    FROM ledger_lines ll
    JOIN accounts a
      ON a.id = ll.account_id
    JOIN categories topcat
      ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
    WHERE ll.category_type = 'expense'
      AND topcat.priority = 'discretionary'
      AND a.type IN ('current','credit','savings')
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= ?)
");
$stmt->execute([
    $start_date->format('Y-m-d'),
    $end_date->format('Y-m-d'),
    $today->format('Y-m-d'),
]);
$discretionaryTotal = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(-ll.amount), 0) AS total
    FROM ledger_lines ll
    JOIN accounts a
      ON a.id = ll.account_id
    WHERE ll.category_type = 'expense'
      AND a.type IN ('current','credit','savings')
      AND ll.line_date BETWEEN ? AND ?
      AND (ll.is_prediction = 0 OR ll.line_date >= ?)
");
$stmt->execute([
    $start_date->format('Y-m-d'),
    $end_date->format('Y-m-d'),
    $today->format('Y-m-d'),
]);
$expenseTotal = (float)$stmt->fetchColumn();

include '../layout/header.php';
?>

<h1 class="mb-4">📊 Spending Insights</h1>
<h5><?= htmlspecialchars($start_date->format('j M Y')) ?> – <?= htmlspecialchars($end_date->format('j M Y')) ?></h5>

<?php if (!empty($overspent) || !empty($unbudgeted)): ?>
    <div class="mb-4">
        <h4>🚨 Overspent Categories</h4>
        <ul class="list-group">
            <?php foreach ($unbudgeted as $c): ?>
                <?php
                    $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
                    $amount_link = "<a href=\"" . htmlspecialchars($link_base) . "\" class=\"text-decoration-none\">" . insights_money((float)$c['actual']) . "</a>";
                ?>
                <li class="list-group-item">
                    <a href="category_report.php?category_id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                    – Overspent by <?= $amount_link ?> (Budget: £0.00)
                </li>
            <?php endforeach; ?>

            <?php foreach ($overspent as $c): ?>
                <?php
                    $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
                    $variance_link = "<a href=\"" . htmlspecialchars($link_base) . "\" class=\"text-decoration-none\">" . insights_money((float)$c['variance']) . "</a>";
                ?>
                <li class="list-group-item">
                    <a href="category_report.php?category_id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                    – Overspent by <?= $variance_link ?> (Budget: <?= insights_money((float)$c['budget']) ?>)
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($underspent) || !empty($highspent)): ?>
    <div class="mb-4">
        <h4>📉 Category Utilisation (<?= (int)$month_elapsed_pct ?>% of month elapsed)</h4>
        <ul class="list-group">
            <?php foreach ($highspent as $c): ?>
                <?php
                    $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
                    $actual_link = "<a href=\"" . htmlspecialchars($link_base) . "\" class=\"text-decoration-none\">" . insights_money((float)$c['actual']) . "</a>";
                    $actual_pct = insights_budget_pct((float)$c['actual'], (float)$c['budget']);
                ?>
                <li class="list-group-item">
                    <a href="category_report.php?category_id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                    – <?= $actual_link ?>
                    <?php if ($actual_pct !== null): ?>
                        (<?= $actual_pct ?>%) spent from <?= insights_money((float)$c['budget']) ?> budget
                    <?php else: ?>
                        with no budget set
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>

            <?php foreach ($underspent as $c): ?>
                <?php
                    $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
                    $actual_link = "<a href=\"" . htmlspecialchars($link_base) . "\" class=\"text-decoration-none\">" . insights_money((float)$c['actual']) . "</a>";
                    $actual_pct = insights_budget_pct((float)$c['actual'], (float)$c['budget']);
                ?>
                <li class="list-group-item">
                    <a href="category_report.php?category_id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                    – Only <?= $actual_link ?>
                    <?php if ($actual_pct !== null): ?>
                        (<?= $actual_pct ?>%) spent from <?= insights_money((float)$c['budget']) ?> budget
                    <?php else: ?>
                        with no budget set
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="mb-4">
    <h4>💰 Top 5 Discretionary Categories</h4>
    <ul class="list-group">
        <?php foreach ($top_categories as $cat): ?>
            <?php
                $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$cat['id']}";
                $total_link = "<a href=\"" . htmlspecialchars($link_base) . "\" class=\"text-decoration-none\">" . insights_money((float)$cat['total']) . "</a>";
            ?>
            <li class="list-group-item">
                <a href="category_report.php?category_id=<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></a>
                – <?= $total_link ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="mb-4">
    <h4>🧾 Top 5 Vendors in Discretionary Categories</h4>
    <ul class="list-group">
        <?php foreach ($top_vendors as $v): ?>
            <?php
                $desc = (string)$v['description'];
                $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&description=" . urlencode($desc);
                $total_link = "<a href=\"" . htmlspecialchars($link_base) . "\" class=\"text-decoration-none\">" . insights_money((float)$v['total']) . "</a>";
            ?>
            <li class="list-group-item">
                <?= htmlspecialchars($desc) ?> – <?= $total_link ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php if ($expenseTotal > 0 && $discretionaryTotal > 0): ?>
    <div class="mb-4">
        <h4>📌 Discretionary Share of Spend</h4>
        <p>
            Discretionary spending <?= insights_money($discretionaryTotal) ?>
            is <?= (int)round(100 * $discretionaryTotal / $expenseTotal) ?>%
            of total expenses <?= insights_money($expenseTotal) ?> this month.
        </p>
    </div>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>
