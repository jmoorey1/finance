<?php
require_once __DIR__ . '/../config/db.php';

$pdo = get_db_connection();

// Calculate the 24 months we care about, formatted as 'YYYY-MM'
$months = [];
$start = (new DateTime())->modify('-23 months');
$start->setDate((int)$start->format('Y'), (int)$start->format('m'), 13);

for ($i = 0; $i < 24; $i++) {
    $month_start = (clone $start)->modify("+$i months");
    $label = $month_start->format('Y-m');
    $months[$label] = [
        'start' => $month_start->format('Y-m-d'),
        'end' => $month_start->modify('+1 month')->modify('-1 day')->format('Y-m-d')
    ];
}

// Get all parent categories of type 'expense'
$cat_stmt = $pdo->prepare("SELECT id, name FROM categories WHERE parent_id IS NULL AND type = 'expense'");
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

$results = [];

// Loop through each month and fetch totals per category
foreach ($months as $label => $range) {
	$stmt = $pdo->prepare("
		SELECT
			IFNULL(top.id, c.id) AS cat_id,
			SUM(amount) AS total
		FROM (
			SELECT s.amount, s.category_id FROM transaction_splits s
			JOIN transactions t ON t.id = s.transaction_id
			JOIN accounts a ON t.account_id = a.id
			WHERE t.date BETWEEN ? AND ?
			  AND a.type IN ('current','credit','savings')

			UNION ALL

			SELECT t.amount, t.category_id FROM transactions t
			JOIN accounts a ON t.account_id = a.id
			LEFT JOIN transaction_splits s ON s.transaction_id = t.id
			WHERE t.date BETWEEN ? AND ?
			  AND s.id IS NULL
			  AND a.type IN ('current','credit','savings')
		) all_txn
		JOIN categories c ON all_txn.category_id = c.id
		LEFT JOIN categories top ON c.parent_id = top.id
		WHERE COALESCE(top.type, c.type) = 'expense'
		GROUP BY cat_id
	");

	$stmt->execute([
		$range['start'],
		$range['end'],
		$range['start'],
		$range['end'],
	]);


    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $cat_id = $row['cat_id'];
        $cat_name = $categories[$cat_id] ?? "Unknown ($cat_id)";
        $amount = round(-$row['total'], 2); // Flip sign to positive

        if (!isset($results[$cat_name])) {
            $results[$cat_name] = [];
        }

        $results[$cat_name][$label] = $amount;
    }
}

return $results;
