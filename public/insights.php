<?php
// Include DB connection and common page layout
require_once '../config/db.php';
include '../layout/header.php';

// ----------------------------
// Determine Financial Month (13th to 12th of following month)
// ----------------------------

// Get today's date
$today = new DateTime();

// If we're before the 13th, use previous month as the "financial" month
$monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
$inputMonth = (clone $today)->modify("$monthOffset month");

// Start date is always 13th of the chosen month
$start_date = new DateTime($inputMonth->format('Y-m-13'));

// End date is 12th of the next month
$end_date = (clone $start_date)->modify('+1 month')->modify('-1 day');


$total_days = $start_date->diff($end_date)->days + 1;
$elapsed_days = $start_date->diff($today)->days + 1;
$month_elapsed_fac = min(100, round($elapsed_days / $total_days, 2));
$month_elapsed_pct = min(100, round(($elapsed_days / $total_days) * 100));

// ----------------------------
// Load Expense Category Metadata
// ----------------------------

$categories = [];
$stmt = $pdo->query("SELECT id, name, parent_id, type, fixedness, priority FROM categories WHERE type='expense'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = $row;
}

// ----------------------------
// Load Budgets for Current Financial Month
// ----------------------------

$budgets = [];
$stmt = $pdo->prepare("SELECT category_id, amount FROM budgets WHERE month_start = ?");
$stmt->execute([$start_date->format('Y-m-d')]);
foreach ($stmt as $row) {
    $budgets[$row['category_id']] = floatval($row['amount']);
}

// Load IDs of all current/credit/savings accounts for linking
$acct_stmt = $pdo->query("SELECT id FROM accounts WHERE type IN ('current','credit','savings') and active=1");
$account_ids = array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
$account_query = implode('&', array_map(fn($id) => "accounts[]=$id", $account_ids));

// ----------------------------
// Load Actual Spend Data (Transactions + Splits + Predicted Instances)
// Grouped by Parent Category ID if exists
// Only include variable expenses
// ----------------------------

$actuals = [];
$stmt = $pdo->prepare("
    SELECT category_id, -SUM(amount) AS total FROM (
        -- Unsplitted transactions
        SELECT IFNULL(c.parent_id, c.id) AS category_id, t.amount
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND t.date BETWEEN ? AND ?

        UNION ALL

        -- Split transactions
        SELECT IFNULL(c.parent_id, c.id) AS category_id, sp.amount
        FROM transaction_splits sp
        JOIN categories c ON sp.category_id = c.id
        JOIN transactions tr ON tr.id = sp.transaction_id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND tr.date BETWEEN ? AND ?

        UNION ALL

        -- Predicted (upcoming or missed) expenses
        SELECT IFNULL(c.parent_id, c.id) AS category_id, pi.amount
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND pi.scheduled_date BETWEEN ? AND ?
    ) raw_data
    GROUP BY category_id
");
$stmt->execute([
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d'), // transactions
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d'), // splits
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d')  // predictions
]);

foreach ($stmt as $row) {
    $actuals[$row['category_id']] = floatval($row['total']); // Force float, negative already applied in SQL
}

// ----------------------------
// Analyse Spending vs Budget
// ----------------------------

$unbudgeted = [];
$overspent = [];
$underspent = [];
$highspent = [];
$totals = [];

foreach ($actuals as $cat_id => $actual) {
    $meta = $categories[$cat_id] ?? null;

    // Skip if no metadata or not an expense category
    if (!$meta || $meta['type'] !== 'expense') continue;

    $budget = $budgets[$cat_id] ?? 0;
    $fixed = $meta['fixedness'] === 'fixed';

    // Focus only on variable categories with defined budget
    if (!$fixed) {
        if ($actual > 0 && $budget == 0) {
            // Unbudgeted: budget = 0 and actual > 0
            $unbudgeted[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'id' => $cat_id
            ];
        } elseif ($actual > $budget) {
            // Overspent: actual > budget
            $overspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $actual - $budget,
                'id' => $cat_id
            ];
        } elseif ($actual < $month_elapsed_fac * $budget) {
            // Underspent compared to elapsed time of month
            $underspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget - $actual,
                'id' => $cat_id
            ];
        } elseif ($actual >= $month_elapsed_fac * $budget) {
            // Overspent compared to elapsed time of month
            $highspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget - $actual,
                'id' => $cat_id
            ];
        }
    }

    // Group totals under parent category name (for ranking)
    $parent_id = $meta['parent_id'] ?? null;
    $name = $parent_id ? $categories[$parent_id]['name'] : $meta['name'];
    $totals[$name] = ($totals[$name] ?? 0) + $actual;
}

// ----------------------------
// Identify Top Spending Categories (Discretionary Only)
// ----------------------------

$discretionary_totals = [];

foreach ($actuals as $cat_id => $actual) {
    $meta = $categories[$cat_id] ?? null;

    // Skip if no metadata or not discretionary
    if (!$meta || $meta['priority'] !== 'discretionary') continue;

    $parent_id = $meta['parent_id'] ?? null;
    $canonical_id = $parent_id ?: $cat_id;
    $canonical_name = $parent_id ? $categories[$parent_id]['name'] : $meta['name'];

	if (!isset($discretionary_totals[$canonical_id])) {
		$discretionary_totals[$canonical_id] = ['id' => $canonical_id, 'name' => $canonical_name, 'total' => 0];
	}

    $discretionary_totals[$canonical_id]['total'] += $actual;
}

// Sort and take top 5
usort($discretionary_totals, fn($a, $b) => $b['total'] <=> $a['total']);
$top_categories = array_slice($discretionary_totals, 0, 5);

// ----------------------------
// Identify Top Vendors by Description
// Across transaction, split, and predicted descriptions
// ----------------------------

$stmt = $pdo->prepare("
    SELECT description, -SUM(amount) AS total FROM (
        -- Direct transactions
        SELECT COALESCE(pay.name, t.description) as description, t.amount
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
		LEFT JOIN payees pay on t.payee_id = pay.id
        WHERE c.type = 'expense'
          AND par_cat.priority = 'discretionary'
          AND t.date BETWEEN ? AND ?

        UNION ALL

        -- Split transactions
        SELECT COALESCE(pay.name, tr.description) as description, sp.amount
        FROM transaction_splits sp
        JOIN categories c ON sp.category_id = c.id
        JOIN transactions tr ON tr.id = sp.transaction_id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
		LEFT JOIN payees pay on tr.payee_id = pay.id
        WHERE c.type = 'expense'
          AND par_cat.priority = 'discretionary'
          AND tr.date BETWEEN ? AND ?

        UNION ALL

        -- Predicted entries
        SELECT COALESCE(pay.name, pi.description) as description, pi.amount
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
		LEFT JOIN payee_patterns pp on pi.description like pp.match_pattern
		LEFT JOIN payees pay on pp.payee_id = pay.id
        WHERE c.type = 'expense'
          AND par_cat.priority = 'discretionary'
          AND pi.scheduled_date BETWEEN ? AND ?
    ) raw_data
    GROUP BY description
    ORDER BY total DESC
    LIMIT 5
");

$stmt->execute([
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d'), // transactions
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d'), // splits
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d')  // predictions
]);

$top_vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!-- HTML Rendering -->
<h1 class="mb-4">📊 Spending Insights</h1>
<h5><?= $start_date->format('j M Y') ?> – <?= $end_date->format('j M Y') ?></h5>

<!-- Overspent -->
<?php if (!empty($overspent) || !empty($unbudgeted)): ?>
    <div class="mb-4">
        <h4>🚨 Overspent Categories</h4>
        <ul class="list-group">
            <?php if (!empty($unbudgeted)): ?>
                <?php foreach ($unbudgeted as $c): ?>
                    <?php
                        $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
                        $unbudgeted_link = "<a href=\"$link_base\" class=\"text-decoration-none\">£" . number_format($c['actual'], 2) . "</a>";
                    ?>
                    <li class="list-group-item">
                        <a href="category_report.php?category_id=<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></a> – Overspent by <?= $unbudgeted_link ?> (Budget: £0.00)
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($overspent)): ?>
                <?php foreach ($overspent as $c): ?>
                    <?php
                        $link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
                        $variance_link = "<a href=\"$link_base\" class=\"text-decoration-none\">£" . number_format($c['variance'], 2) . "</a>";
                    ?>
                    <li class="list-group-item">
                        <a href="category_report.php?category_id=<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></a> – Overspent by <?= $variance_link ?> (Budget: £<?= number_format($c['budget'], 2) ?>)
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>


<!-- Underspent -->
<?php if (count($underspent)): ?>
    <div class="mb-4">
        <h4>📉 Category Utilisation (<?= $month_elapsed_pct ?>% of month elapsed)</h4>
        <ul class="list-group">
            <?php foreach ($highspent as $c): ?>
				<?php
					$link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
					$actual_link = "<a href=\"$link_base\" class=\"text-decoration-none\">" . "£" . number_format($c['actual'], 2) . "</a>";
				?>
                <li class="list-group-item">
                    <?php
					$actual_pct = round(($c['actual'] / $c['budget']) * 100);
					?>
					<a href="category_report.php?category_id=<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></a> – <?= $actual_link ?> (<?= $actual_pct ?>%) spent from £<?= number_format($c['budget'], 2) ?> budget
                </li>
            <?php endforeach; ?>
            <?php foreach ($underspent as $c): ?>
				<?php
					$link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$c['id']}";
					$actual_link = "<a href=\"$link_base\" class=\"text-decoration-none\">" . "£" . number_format($c['actual'], 2) . "</a>";
				?>
                <li class="list-group-item">
                    <?php
					$actual_pct = round(($c['actual'] / $c['budget']) * 100);
					?>
					<a href="category_report.php?category_id=<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></a> – Only <?= $actual_link ?> (<?= $actual_pct ?>%) spent from £<?= number_format($c['budget'], 2) ?> budget
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Top Spending Categories -->
<div class="mb-4">
    <h4>💰 Top 5 Discretionary Categories</h4>
    <ul class="list-group">
        <?php foreach ($top_categories as $cat): ?>
				<?php
					$link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&parent_id={$cat['id']}";
					$total_link = "<a href=\"$link_base\" class=\"text-decoration-none\">" . "£" . number_format($cat['total'], 2) . "</a>";
				?>
            <li class="list-group-item"><a href="category_report.php?category_id=<?= htmlspecialchars($cat['id']) ?>"><?= htmlspecialchars($cat['name']) ?></a> – <?= $total_link ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- Top Vendors -->
<div class="mb-4">
    <h4>🧾 Top 5 Vendors in Discretionary Categories</h4>
    <ul class="list-group">
        <?php foreach ($top_vendors as $v): ?>
				<?php
					$link_base = "ledger.php?$account_query&start={$start_date->format('Y-m-d')}&end={$end_date->format('Y-m-d')}&description=" . urlencode($v['description']);
					$total_link = "<a href=\"$link_base\" class=\"text-decoration-none\">" . "£" . number_format($v['total'], 2) . "</a>";
				?>
            <li class="list-group-item"><?= htmlspecialchars($v['description']) ?> – <?= $total_link ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<?php include '../layout/footer.php'; ?>
