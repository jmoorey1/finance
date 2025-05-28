<?php
require_once '../config/db.php';

$pdo = get_db_connection();

// Determine financial month
if (isset($_GET['month']) && DateTime::createFromFormat('Y-m', $_GET['month']) !== false) {
    $inputMonth = DateTime::createFromFormat('Y-m', $_GET['month']);
} else {
    $today = new DateTime();
    $monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
    $inputMonth = (clone $today)->modify("$monthOffset month");
}
$start_month = new DateTime($inputMonth->format('Y-m-13'));
$end_month = (clone $start_month)->modify('+1 month')->modify('-1 day');

$headlines = [];

// === Rule 1: Over Budget ===

$essential_unbudgeted = 0;
$discretionary_unbudgeted = 0;

$stmt = $pdo->prepare("
    SELECT top.name AS category, SUM(total) as actual
    FROM (
        SELECT IFNULL(top.id, c.id) AS top_id, SUM(s.amount) AS total
        FROM transaction_splits s
        JOIN transactions t ON t.id = s.transaction_id
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND c.type = 'expense'
        GROUP BY top_id

        UNION ALL

        SELECT IFNULL(top.id, c.id) AS top_id, SUM(t.amount) AS total
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND c.type = 'expense'
        GROUP BY top_id
    ) actuals
    JOIN categories top ON top.id = actuals.top_id
    GROUP BY top.name
");
$stmt->execute([
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d'),
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d')
]);

$rows = $stmt->fetchAll();
$overBudgetHeadlines = [];

foreach ($rows as $row) {
    $category = $row['category'];
    $actual = round($row['actual'], 2);

    $idStmt = $pdo->prepare("SELECT id, priority FROM categories WHERE name = :name AND parent_id IS NULL");
    $idStmt->execute([':name' => $category]);
    $catMeta = $idStmt->fetch(PDO::FETCH_ASSOC);

    if ($catMeta && isset($catMeta['id'])) {
        $catId = $catMeta['id'];
        $priority = $catMeta['priority'];

        $budgetStmt = $pdo->prepare("
            SELECT amount FROM budgets
            WHERE category_id = :cat_id AND month_start = :start
        ");
        $budgetStmt->execute([
            ':cat_id' => $catId,
            ':start' => $start_month->format('Y-m-d')
        ]);
        $budgeted = $budgetStmt->fetchColumn();

        if ($budgeted !== null && -$actual > $budgeted) {
            $overspend = -$actual - $budgeted;

            // Track unbudgeted overspend by priority
            if ($priority === 'essential') {
                $essential_unbudgeted += $overspend;
            } elseif ($priority === 'discretionary') {
                $discretionary_unbudgeted += $overspend;
            }

            $message = "You overspent your '{$category}' budget by £" . number_format($overspend, 2) . " this month.";
            $overBudgetHeadlines[] = [
                'amount' => $overspend,
                'message' => $message
            ];
        }
    }
}

// === ⚠️ Rule 1a: Unbudgeted Essential/Discretionary Summary Headline
if ($essential_unbudgeted > 0 && $discretionary_unbudgeted > 0) {
    $headlines[] = "⚠️ There was £" . number_format($essential_unbudgeted, 2) .
        " in unbudgeted essentials this month, and £" .
        number_format($discretionary_unbudgeted, 2) .
        " in unbudgeted discretionary spending.";
} elseif ($essential_unbudgeted > 0) {
    $headlines[] = "⚠️ There was £" . number_format($essential_unbudgeted, 2) .
        " in unbudgeted essentials this month.";
} elseif ($discretionary_unbudgeted > 0) {
    $headlines[] = "⚠️ There was £" . number_format($discretionary_unbudgeted, 2) .
        " in unbudgeted discretionary spending this month.";
}

// Sort by overspend descending and take top 3
usort($overBudgetHeadlines, function ($a, $b) {
    return $b['amount'] <=> $a['amount'];
});
$topOverBudget = array_slice($overBudgetHeadlines, 0, 3);

// Merge top 3 into headline output
foreach ($topOverBudget as $item) {
    $headlines[] = $item['message'];
}

// === Rule 2: Category Drift (Top 2 Up + Top 2 Down) ===
$catNames = $pdo->query("
    SELECT name FROM categories
    WHERE parent_id IS NULL AND type = 'expense' and priority = 'discretionary'
")->fetchAll(PDO::FETCH_COLUMN);

$driftQuery = "
    SELECT SUM(amount) FROM (
        SELECT SUM(s.amount) AS amount
        FROM transaction_splits s
        JOIN transactions t ON t.id = s.transaction_id
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND c.type = 'expense'
          AND (top.name = ? OR c.name = ?)
        UNION ALL
        SELECT SUM(t.amount) AS amount
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND c.type = 'expense'
          AND (top.name = ? OR c.name = ?)
    ) AS combined
";

$avgStart = (clone $start_month)->modify('-3 months');
$avgEnd = (clone $start_month)->modify('-1 day');

$driftIncreases = [];
$driftDecreases = [];

foreach ($catNames as $cat) {
    $avgStmt = $pdo->prepare($driftQuery);
    $avgStmt->execute([
        $avgStart->format('Y-m-d'),
        $avgEnd->format('Y-m-d'),
        $cat, $cat,
        $avgStart->format('Y-m-d'),
        $avgEnd->format('Y-m-d'),
        $cat, $cat
    ]);
    $avg = $avgStmt->fetchColumn();
    $avgMonthly = $avg ? -$avg / 3 : 0;  // flip sign to positive

    $currentStmt = $pdo->prepare($driftQuery);
    $currentStmt->execute([
        $start_month->format('Y-m-d'),
        $end_month->format('Y-m-d'),
        $cat, $cat,
        $start_month->format('Y-m-d'),
        $end_month->format('Y-m-d'),
        $cat, $cat
    ]);
    $current = $currentStmt->fetchColumn();
    $current = -$current;

    if ($avgMonthly > 0 && $current > 2 * $avgMonthly) {
        $percent = round(100 * ($current / $avgMonthly));
        $diff = $current - $avgMonthly;
        $driftIncreases[] = [
            'delta' => $percent - 100,
            'message' => "Spending on '{$cat}' (£" . number_format($current, 2) . ") is up £" . number_format($diff, 2) . " — " . $percent . "% of your 3-month average (£" . number_format($avgMonthly, 2) . ")."
        ];
    } elseif ($avgMonthly > 0 && $current < 0.5 * $avgMonthly) {
        $percent = round(100 * ($current / $avgMonthly));
        $diff = $avgMonthly - $current;
        $driftDecreases[] = [
            'delta' => 100 - $percent,
            'message' => "Spending on '{$cat}' (£" . number_format($current, 2) . ") is down £" . number_format($diff, 2) . " — only " . $percent . "% of your 3-month average (£" . number_format($avgMonthly, 2) . ")."
        ];
    }
}

// Sort each list descending by magnitude of change
usort($driftIncreases, fn($a, $b) => $b['delta'] <=> $a['delta']);
usort($driftDecreases, fn($a, $b) => $b['delta'] <=> $a['delta']);

// Take top 2 from each
$topIncreases = array_slice($driftIncreases, 0, 2);
$topDecreases = array_slice($driftDecreases, 0, 2);

// Merge messages into headlines
foreach (array_merge($topIncreases, $topDecreases) as $item) {
    $headlines[] = $item['message'];
}


// === Rule 3: Top Discretionary Payees ===
$topPayeesStmt = $pdo->prepare("
    SELECT COALESCE(p.name, t.description) AS payee, SUM(-t.amount) AS total
    FROM transactions t
    JOIN accounts a ON t.account_id = a.id
    LEFT JOIN payees p ON t.payee_id = p.id
    JOIN categories c ON t.category_id = c.id
    LEFT JOIN categories top ON c.parent_id = top.id
    WHERE t.date BETWEEN :start AND :end
      AND a.type IN ('current','credit','savings')
      AND COALESCE(top.type, c.type) = 'expense'
      AND COALESCE(top.priority, c.priority) = 'discretionary'
    GROUP BY payee
    HAVING total > 0
    ORDER BY total DESC
    LIMIT 10
");
$topPayeesStmt->execute([
    ':start' => $start_month->format('Y-m-d'),
    ':end' => $end_month->format('Y-m-d')
]);
$topPayees = $topPayeesStmt->fetchAll();

$disctotalStmt = $pdo->prepare("
    SELECT SUM(amount) AS total FROM (
        SELECT SUM(-s.amount) AS amount
        FROM transaction_splits s
        JOIN transactions t ON t.id = s.transaction_id
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND COALESCE(top.type, c.type) = 'expense'
          AND COALESCE(top.priority, c.priority) = 'discretionary'
        UNION ALL
        SELECT SUM(-t.amount) AS amount
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND COALESCE(top.type, c.type) = 'expense'
          AND COALESCE(top.priority, c.priority) = 'discretionary'
    ) AS combined
");
$disctotalStmt->execute([
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d'),
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d')
]);
$discgrandTotal = $disctotalStmt->fetchColumn();

$mainHeadlineShown = false;
$secondaryHeadlines = [];

if ($discgrandTotal > 0 && count($topPayees) > 0) {
    foreach ($topPayees as $index => $row) {
        $payee = $row['payee'];
        $amount = $row['total'];
        $percent = round(100 * $amount / $discgrandTotal);

        if (!$mainHeadlineShown && $percent > 10) {
            $headlines[] = "'{$payee}' accounted for £" . number_format($amount, 2) . " — {$percent}% of your discretionary spending this month.";
            $mainHeadlineShown = true;
        } elseif ($percent >= 5 && count($secondaryHeadlines) < 3) {
            $secondaryHeadlines[] = "- {$payee}: £" . number_format($amount, 2) . " ({$percent}%)";
        }
    }

    if (!$mainHeadlineShown) {
        $headlines[] = "No single payee accounted for more than 10% of your discretionary spending this month.";
    }

    if (count($secondaryHeadlines) > 0) {
        $headlines[] = "Other top discretionary spenders this month:\n" . implode("\n", $secondaryHeadlines);
    }
}

// === Rule 4: Discretionary Spending > 40% ===
$discretionaryStmt = $pdo->prepare("
    SELECT SUM(amount) AS total FROM (
        SELECT SUM(-s.amount) AS amount
        FROM transaction_splits s
        JOIN transactions t ON t.id = s.transaction_id
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND COALESCE(top.type, c.type) = 'expense'
          AND COALESCE(top.priority, c.priority) = 'discretionary'

        UNION ALL

        SELECT SUM(-t.amount) AS amount
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        LEFT JOIN transaction_splits s ON s.transaction_id = t.id
        WHERE t.date BETWEEN ? AND ?
          AND s.id IS NULL
          AND a.type IN ('current','credit','savings')
          AND COALESCE(top.type, c.type) = 'expense'
          AND COALESCE(top.priority, c.priority) = 'discretionary'
    ) AS discretionary
");
$discretionaryStmt->execute([
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d'),
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d')
]);
$discretionaryTotal = $discretionaryStmt->fetchColumn();

$totalStmt = $pdo->prepare("
    SELECT SUM(amount) AS total FROM (
        SELECT SUM(-s.amount) AS amount
        FROM transaction_splits s
        JOIN transactions t ON t.id = s.transaction_id
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND COALESCE(top.type, c.type) = 'expense'

        UNION ALL

        SELECT SUM(-t.amount) AS amount
        FROM transactions t
        JOIN accounts a ON t.account_id = a.id
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE t.date BETWEEN ? AND ?
          AND a.type IN ('current','credit','savings')
          AND COALESCE(top.type, c.type) = 'expense'
    ) AS total_expenses
");
$totalStmt->execute([
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d'),
    $start_month->format('Y-m-d'),
    $end_month->format('Y-m-d')
]);
$expenseTotal = $totalStmt->fetchColumn();

if ($expenseTotal > 0 && $discretionaryTotal > 0) {
    $percent = round(100 * $discretionaryTotal / $expenseTotal);
//    if ($percent > 40) {
        $headlines[] = "Discretionary spending (£$discretionaryTotal) made up {$percent}% of your total expenses (£$expenseTotal) this month.";
//    }
}


// === Output ===
header('Content-Type: application/json');
echo json_encode($headlines);
