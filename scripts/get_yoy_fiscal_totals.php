<?php
require_once __DIR__ . '/../config/db.php';

$pdo = get_db_connection();

// Get the date range of available transactions
$range_stmt = $pdo->query("SELECT MIN(date) AS min_date, MAX(date) AS max_date FROM transactions");
$range = $range_stmt->fetch();

$min_date = new DateTime($range['min_date']);
$max_date = new DateTime($range['max_date']);

// Align to fiscal boundaries (Jan 13 – Jan 12)
// If the min date is before Jan 13 of that year, FY starts Jan 13 of that year
// Otherwise, first FY starts Jan 13 of the *next* year
if ((int)$min_date->format('m') < 1 || ((int)$min_date->format('m') === 1 && (int)$min_date->format('d') < 13)) {
    $first_fy_start = new DateTime($min_date->format('Y') . '-01-13');
} else {
    $first_fy_start = (new DateTime($min_date->format('Y') . '-01-13'))->modify('+1 year');
}

// Extend until at least the FY that includes the max date
$last_fy_end = new DateTime($max_date->format('Y-m-d'));
if ($last_fy_end->format('m-d') > '01-12') {
    $last_fy_end = new DateTime($last_fy_end->format('Y') . '-01-12');
    $last_fy_end->modify('+1 year');
} else {
    $last_fy_end = new DateTime($last_fy_end->format('Y') . '-01-12');
}

// Build fiscal year boundaries
$years = [];
$current = clone $first_fy_start;
while ($current < $last_fy_end) {
    $start = clone $current;
    $end = (clone $start)->modify('+1 year')->modify('-1 day');
    $label = 'FY' . $start->format('Y');
    $years[$label] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
    $current->modify('+1 year');
}

// Load parent categories
$cat_stmt = $pdo->prepare("SELECT id, name FROM categories WHERE parent_id IS NULL AND type = 'expense'");
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

$results = [];

// Query totals per category for each FY
foreach ($years as $label => [$start_date, $end_date]) {
    $stmt = $pdo->prepare("
        SELECT IFNULL(top.id, c.id) AS cat_id, SUM(amount) AS total
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
        WHERE COALESCE(top.type, c.type) = 'expense'
        GROUP BY cat_id
    ");
    $stmt->execute([$start_date, $end_date, $start_date, $end_date]);

    foreach ($stmt->fetchAll() as $row) {
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
