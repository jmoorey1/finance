<?php
require_once __DIR__ . '/../config/db.php';

$pdo = get_db_connection();

$months = [];
$today = new DateTime();
$start = (new DateTime())->modify('-11 months');
$start->setDate((int)$start->format('Y'), (int)$start->format('m'), 13);

// Build 12 financial months
for ($i = 0; $i < 12; $i++) {
    $month_start = (clone $start)->modify("+$i months");
    $month_end = (clone $month_start)->modify('+1 month')->modify('-1 day');

    $month_label = $month_start->format('Y-m-13');

    // Inflows
    $stmt = $pdo->prepare("
        SELECT SUM(amount) FROM (
            SELECT SUM(s.amount) AS amount
            FROM transaction_splits s
            JOIN transactions t ON t.id = s.transaction_id
            JOIN categories c ON s.category_id = c.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND c.type = 'income'
              AND a.type IN ('current','savings','credit')
            UNION ALL
            SELECT SUM(t.amount) AS amount
            FROM transactions t
            LEFT JOIN transaction_splits s ON s.transaction_id = t.id
            JOIN categories c ON t.category_id = c.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND s.id IS NULL
              AND c.type = 'income'
              AND a.type IN ('current','savings','credit')
        ) as inflows
    ");
    $stmt->execute([
        $month_start->format('Y-m-d'),
        $month_end->format('Y-m-d'),
        $month_start->format('Y-m-d'),
        $month_end->format('Y-m-d'),
    ]);
    $inflow = (float) $stmt->fetchColumn();

    // Outflows (All)
    $stmt = $pdo->prepare("
        SELECT SUM(-amount) FROM (
            SELECT SUM(s.amount) AS amount
            FROM transaction_splits s
            JOIN transactions t ON t.id = s.transaction_id
            JOIN categories c ON s.category_id = c.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND c.type = 'expense'
              AND a.type IN ('current','savings','credit')
            UNION ALL
            SELECT SUM(t.amount) AS amount
            FROM transactions t
            LEFT JOIN transaction_splits s ON s.transaction_id = t.id
            JOIN categories c ON t.category_id = c.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND s.id IS NULL
              AND c.type = 'expense'
              AND a.type IN ('current','savings','credit')
        ) as outflows
    ");
    $stmt->execute([
        $month_start->format('Y-m-d'),
        $month_end->format('Y-m-d'),
        $month_start->format('Y-m-d'),
        $month_end->format('Y-m-d'),
    ]);
    $outflow = (float) $stmt->fetchColumn();

    // Discretionary portion
    $stmt = $pdo->prepare("
        SELECT SUM(-amount) FROM (
            SELECT SUM(s.amount) AS amount
            FROM transaction_splits s
            JOIN transactions t ON t.id = s.transaction_id
            JOIN categories c ON s.category_id = c.id
            LEFT JOIN categories top ON c.parent_id = top.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND COALESCE(top.priority, c.priority) = 'discretionary'
              AND a.type IN ('current','savings','credit')
            UNION ALL
            SELECT SUM(t.amount) AS amount
            FROM transactions t
            LEFT JOIN transaction_splits s ON s.transaction_id = t.id
            JOIN categories c ON t.category_id = c.id
            LEFT JOIN categories top ON c.parent_id = top.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND s.id IS NULL
              AND COALESCE(top.priority, c.priority) = 'discretionary'
              AND a.type IN ('current','savings','credit')
        ) as discretionary
    ");
    $stmt->execute([
        $month_start->format('Y-m-d'),
        $month_end->format('Y-m-d'),
        $month_start->format('Y-m-d'),
        $month_end->format('Y-m-d'),
    ]);
    $discretionary = (float) $stmt->fetchColumn();

    $disc_pct = $outflow > 0 ? round(100 * $discretionary / $outflow, 1) : 0;
    $fixed_pct = 100 - $disc_pct;

    // Budgeted amount
    $budget_stmt = $pdo->prepare("
        SELECT SUM(amount) FROM budgets WHERE month_start = ?
    ");
    $budget_stmt->execute([$month_start->format('Y-m-d')]);
    $budget = (float) $budget_stmt->fetchColumn();
    $budget_util = ($budget > 0) ? round(100 * $outflow / $budget, 1) : null;

    // Assemble data
    $months[] = [
        'month_start' => $month_label,
        'inflow' => $inflow,
        'outflow' => $outflow,
        'net' => $inflow - $outflow,
        'discretionary_pct' => $disc_pct,
        'fixed_pct' => $fixed_pct,
        'budget_utilization_pct' => $budget_util,
    ];
}

header('Content-Type: application/json');
return $months;
