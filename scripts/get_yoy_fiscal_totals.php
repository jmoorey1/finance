<?php
require_once __DIR__ . '/../config/db.php';

$pdo = get_db_connection();

// Step 1: Determine fiscal year boundaries
$range_stmt = $pdo->query("SELECT MIN(date) AS min_date, MAX(date) AS max_date FROM transactions");
$range = $range_stmt->fetch();

$min_date = new DateTime($range['min_date']);
$max_date = new DateTime($range['max_date']);

// Always begin fiscal years on January 13th
$fiscal_years = [];
$start = new DateTime($min_date->format('Y') . '-01-13');
if ($min_date > $start) $start->modify('+1 year');

while ($start < $max_date) {
    $label = 'FY' . $start->format('Y');
    $end = (clone $start)->modify('+1 year')->modify('-1 day');
    $fiscal_years[$label] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
    $start->modify('+1 year');
}

// Step 2: Load parent categories for both income and expense
$cat_stmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE parent_id IS NULL AND type IN ('income', 'expense')");
$cat_stmt->execute();
$all_cats = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$income_cats = [];
$expense_cats = [];

foreach ($all_cats as $cat) {
    if ($cat['type'] === 'income') {
        $income_cats[$cat['id']] = $cat['name'];
    } else {
        $expense_cats[$cat['id']] = $cat['name'];
    }
}

$results = ['income' => [], 'expense' => []];

// Step 3: Run the query per FY
foreach ($fiscal_years as $fy => [$start_date, $end_date]) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(top.id, c.id) AS cat_id, COALESCE(top.type, c.type) AS cat_type, SUM(amount) AS total
        FROM (
            SELECT s.amount, s.category_id
            FROM transaction_splits s
            JOIN transactions t ON t.id = s.transaction_id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND a.type IN ('current','credit','savings')

            UNION ALL

            SELECT t.amount, t.category_id
            FROM transactions t
            JOIN accounts a ON t.account_id = a.id
            LEFT JOIN transaction_splits s ON s.transaction_id = t.id
            WHERE t.date BETWEEN ? AND ?
              AND s.id IS NULL
              AND a.type IN ('current','credit','savings')
        ) all_txn
        JOIN categories c ON all_txn.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        GROUP BY cat_id, cat_type
    ");
    $stmt->execute([$start_date, $end_date, $start_date, $end_date]);

    foreach ($stmt->fetchAll() as $row) {
        $type = $row['cat_type'];
        $cat_id = $row['cat_id'];
        $amount = round($row['total'], 2);

		if ($type === 'income') {
			$name = $income_cats[$cat_id] ?? "Unknown";
			if (!isset($results['income'][$cat_id])) {
				$results['income'][$cat_id] = ['category_id' => $cat_id, 'name' => $name];
			}
			$results['income'][$cat_id][$fy] = $amount;
		} elseif ($type === 'expense') {
			$name = $expense_cats[$cat_id] ?? "Unknown";
			if (!isset($results['expense'][$cat_id])) {
				$results['expense'][$cat_id] = ['category_id' => $cat_id, 'name' => $name];
			}
			$results['expense'][$cat_id][$fy] = -$amount; // flip to positive
		}
    }
}

return [
    'income' => array_values($results['income']),
    'expense' => array_values($results['expense'])
];
