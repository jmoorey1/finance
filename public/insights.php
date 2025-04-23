<?php
require_once '../config/db.php';
include '../layout/header.php';

// Determine the current financial month
$today = new DateTime();
$monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
$start_date = (clone $today)->modify("$monthOffset month")->setDate((int)$today->format('Y'), (int)$today->format('m'), 13);
$end_date = (clone $start_date)->modify('+1 month')->modify('-1 day');

// Load category metadata
$categories = [];
$stmt = $pdo->query("SELECT id, name, parent_id, type, fixedness, priority FROM categories");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = $row;
}

// Load budgets for current month
$budgets = [];
$stmt = $pdo->prepare("SELECT category_id, amount FROM budgets WHERE month_start = ?");
$stmt->execute([$start_date->format('Y-m-d')]);
foreach ($stmt as $row) {
    $budgets[$row['category_id']] = floatval($row['amount']);
}

// Load actuals (splits + direct)
$actuals = [];
$stmt = $pdo->prepare("
    SELECT category_id, SUM(amount) AS total FROM (
        SELECT s.category_id, s.amount
        FROM transaction_splits s
        JOIN transactions t ON s.transaction_id = t.id
        WHERE t.date BETWEEN ? AND ?
        UNION ALL
        SELECT t.category_id, t.amount
        FROM transactions t
        LEFT JOIN transaction_splits s ON s.transaction_id = t.id
        WHERE t.date BETWEEN ? AND ? AND s.id IS NULL
    ) all_data
    GROUP BY category_id
");
$stmt->execute([
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d'),
    $start_date->format('Y-m-d'), $end_date->format('Y-m-d')
]);
foreach ($stmt as $row) {
    $actuals[$row['category_id']] = floatval($row['total']);
}

// Flagged categories
$overspent = [];
$underspent = [];
$totals = [];

foreach ($actuals as $cat_id => $actual) {
    $meta = $categories[$cat_id] ?? null;
    if (!$meta || $meta['type'] !== 'expense') continue;

    $budget = $budgets[$cat_id] ?? 0;
    $fixed = $meta['fixedness'] === 'fixed';

    if ($budget > 0 && !$fixed) {
        if ($actual > $budget) {
            $overspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $actual - $budget
            ];
        } elseif ($actual < 0.5 * $budget) {
            $underspent[] = [
                'name' => $meta['name'],
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget - $actual
            ];
        }
    }

    // Group totals by name
    $parent_id = $meta['parent_id'] ?? null;
    $name = $parent_id ? $categories[$parent_id]['name'] : $meta['name'];
    $totals[$name] = ($totals[$name] ?? 0) + $actual;
}

// Top spending categories
arsort($totals);
$top_categories = array_slice($totals, 0, 5, true);

// Top vendors by description
$stmt = $pdo->prepare("
    SELECT description, SUM(amount) AS total
    FROM transactions
    WHERE date BETWEEN ? AND ?
    GROUP BY description
    ORDER BY total DESC
    LIMIT 5
");
$stmt->execute([$start_date->format('Y-m-d'), $end_date->format('Y-m-d')]);
$top_vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

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
