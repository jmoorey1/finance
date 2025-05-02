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
          AND par_cat.fixedness = 'variable'
          AND t.date BETWEEN ? AND ?

        UNION ALL

        -- Split transactions
        SELECT IFNULL(c.parent_id, c.id) AS category_id, sp.amount
        FROM transaction_splits sp
        JOIN categories c ON sp.category_id = c.id
        JOIN transactions tr ON tr.id = sp.transaction_id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND par_cat.fixedness = 'variable'
          AND tr.date BETWEEN ? AND ?

        UNION ALL

        -- Predicted (upcoming or missed) expenses
        SELECT IFNULL(c.parent_id, c.id) AS category_id, pi.amount
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND par_cat.fixedness = 'variable'
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

$overspent = [];
$underspent = [];
$totals = [];

foreach ($actuals as $cat_id => $actual) {
    $meta = $categories[$cat_id] ?? null;

    // Skip if no metadata or not an expense category
    if (!$meta || $meta['type'] !== 'expense') continue;

    $budget = $budgets[$cat_id] ?? 0;
    $fixed = $meta['fixedness'] === 'fixed';

    // Focus only on variable categories with defined budget
    if ($budget > 0 && !$fixed) {
        if ($actual > $budget) {
            // Overspent: actual > budget
            $overspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $actual - $budget
            ];
        } elseif ($actual < 0.5 * $budget) {
            // Underspent significantly (less than 50%)
            $underspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget - $actual
            ];
        }
    }

    // Group totals under parent category name (for ranking)
    $parent_id = $meta['parent_id'] ?? null;
    $name = $parent_id ? $categories[$parent_id]['name'] : $meta['name'];
    $totals[$name] = ($totals[$name] ?? 0) + $actual;
}

// ----------------------------
// Identify Top Spending Categories
// ----------------------------

arsort($totals); // Descending by spend
$top_categories = array_slice($totals, 0, 5, true);

// ----------------------------
// Identify Top Vendors by Description
// Across transaction, split, and predicted descriptions
// ----------------------------

$stmt = $pdo->prepare("
    SELECT description, -SUM(amount) AS total FROM (
        -- Direct transactions
        SELECT t.description, t.amount
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND par_cat.fixedness = 'variable'
          AND t.date BETWEEN ? AND ?

        UNION ALL

        -- Split transactions
        SELECT tr.description, sp.amount
        FROM transaction_splits sp
        JOIN categories c ON sp.category_id = c.id
        JOIN transactions tr ON tr.id = sp.transaction_id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND par_cat.fixedness = 'variable'
          AND tr.date BETWEEN ? AND ?

        UNION ALL

        -- Predicted entries
        SELECT pi.description, pi.amount
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        LEFT JOIN categories par_cat ON par_cat.id = IFNULL(c.parent_id, c.id)
        WHERE c.type = 'expense'
          AND par_cat.fixedness = 'variable'
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
<?php if (count($overspent)): ?>
    <div class="mb-4">
        <h4>🚨 Overspent Categories</h4>
        <ul class="list-group">
            <?php foreach ($overspent as $c): ?>
                <li class="list-group-item">
                    <?= htmlspecialchars($c['name']) ?> – Overspent by £<?= number_format($c['variance'], 2) ?> (Budget: £<?= number_format($c['budget'], 2) ?>)
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Underspent -->
<?php if (count($underspent)): ?>
    <div class="mb-4">
        <h4>📉 Underutilised Categories</h4>
        <ul class="list-group">
            <?php foreach ($underspent as $c): ?>
                <li class="list-group-item">
                    <?= htmlspecialchars($c['name']) ?> – Only £<?= number_format($c['actual'], 2) ?> spent from £<?= number_format($c['budget'], 2) ?> budget
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Top Spending Categories -->
<div class="mb-4">
    <h4>💰 Top 5 Spending Categories</h4>
    <ul class="list-group">
        <?php foreach ($top_categories as $name => $amt): ?>
            <li class="list-group-item"><?= htmlspecialchars($name) ?> – £<?= number_format($amt, 2) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- Top Vendors -->
<div class="mb-4">
    <h4>🧾 Top 5 Vendors</h4>
    <ul class="list-group">
        <?php foreach ($top_vendors as $v): ?>
            <li class="list-group-item"><?= htmlspecialchars($v['description']) ?> – £<?= number_format($v['total'], 2) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<?php include '../layout/footer.php'; ?>
