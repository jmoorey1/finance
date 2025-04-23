<?php
require_once '../config/db.php';

$today = new DateTime();
$monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
$inputMonth = (clone $today)->modify("$monthOffset month");
$start_month = new DateTime($inputMonth->format('Y-m-13'));
$end_month = (clone $start_month)->modify('+1 month')->modify('-1 day');

// Budgeted amounts
$budgets = [];
$stmt = $pdo->prepare("SELECT b.category_id, b.amount, c.name, c.fixedness, c.type FROM budgets b JOIN categories c ON b.category_id = c.id WHERE b.month_start = ? and c.type = 'expense' order by c.fixedness asc, c.priority desc");
$stmt->execute([$start_month->format('Y-m-d')]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $budgets[$row['category_id']] = $row;
}

// Actuals this month
$actuals = [];
$stmt = $pdo->prepare("
    SELECT IFNULL(top.id, c.id) AS category_id, SUM(COALESCE(s.amount, t.amount)) AS total
    FROM transactions t
    LEFT JOIN transaction_splits s ON t.id = s.transaction_id
    JOIN categories c ON COALESCE(s.category_id, t.category_id) = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    WHERE t.date BETWEEN ? AND ?
      AND c.type IN ('income', 'expense')
    GROUP BY category_id
");
$stmt->execute([$start_month->format('Y-m-d'), $end_month->format('Y-m-d')]);
foreach ($stmt as $row) {
    $actuals[$row['category_id']] = floatval($row['total']);
}

// Forecasts
$forecast = [];
$stmt = $pdo->prepare("
    SELECT IFNULL(top.id, c.id) AS category_id, SUM(pi.amount) AS total
    FROM predicted_instances pi
    JOIN categories c ON pi.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    WHERE pi.scheduled_date BETWEEN ? AND ?
    GROUP BY category_id
");
$stmt->execute([$start_month->format('Y-m-d'), $end_month->format('Y-m-d')]);
foreach ($stmt as $row) {
    $forecast[$row['category_id']] = floatval($row['total']);
}

// Analyze & generate insights
$insights = [];

foreach ($budgets as $id => $b) {
    $name = $b['name'];
    $budget = floatval($b['amount']);
    $actual = $actuals[$id] ?? 0;
    $future = $forecast[$id] ?? 0;
    $total = $actual + $future;

    // Expense sign reversal
    if ($b['type'] === 'expense') {
        $actual *= -1;
        $future *= -1;
        $total *= -1;
    }

    $percent = $budget > 0 ? round(($total / $budget) * 100) : 0;

    if ($b['fixedness'] === 'fixed') {
        if ($percent < 90) {
            $insights[] = "🧊 <strong>$name</strong> is fixed, but you've only used $percent% of its budget.";
        }
    } else {
        if ($percent > 100) {
            $insights[] = "⚠️ You're overspending in <strong>$name</strong> — $percent% of budget.";
        } elseif ($percent >= 90) {
            $insights[] = "⚠️ <strong>$name</strong> is nearing its budget ($percent%).";
        } elseif ($percent < 60 && $budget > 10) {
            $insights[] = "✅ <strong>$name</strong> is under control — just $percent% of budget.";
        }
    }
}

// Staleness Check
$accounts = $pdo->query("SELECT name, MAX(date) as last FROM staging_transactions JOIN accounts ON staging_transactions.account_id = accounts.id GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($accounts as $a) {
    $days = (new DateTime($a['last']))->diff($today)->days;
    if ($days > 10) {
        $insights[] = "📌 <strong>{$a['name']}</strong> hasn’t had any reviewed transactions in $days days.";
    }
}

// Output
return $insights;
