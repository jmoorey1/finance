<?php
require_once '/var/www/html/finance/config/db.php';

// Email Recipients
$to = 'john@moorey.uk.com, india@moorey.uk.com, indiamo@amazon.co.uk';
$subject = 'Weekly Budget Summary – Variable Expenses';
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: Home Finaces <no-reply@moorey.uk.com>\r\n";

// Current financial month
$today = new DateTime();
$monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
$inputMonth = (clone $today)->modify("$monthOffset month");
$start_month = new DateTime($inputMonth->format('Y-m-13'));
$end_month = (clone $start_month)->modify('+1 month')->modify('-1 day');

// YTD range
$year = $inputMonth->format('Y');
$start_ytd = new DateTime("$year-01-13");

// Get variable expense categories
$categories = [];
$stmt = $pdo->query("
    SELECT id, name FROM categories
    WHERE type = 'expense' AND fixedness = 'variable' AND parent_id IS NULL
    ORDER BY budget_order
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[$row['id']] = $row['name'];
}

$priorities = [];
$stmt = $pdo->query("
    SELECT id, priority FROM categories
    WHERE type = 'expense' AND fixedness = 'variable' AND parent_id IS NULL
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $priorities[$row['id']] = $row['priority'];
}


// Load budgets
function load_budgets($pdo, $start, $end) {
    $budgets = [];
    $stmt = $pdo->prepare("
        SELECT category_id, SUM(amount) AS total
        FROM budgets
        WHERE month_start BETWEEN ? AND ?
        GROUP BY category_id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    foreach ($stmt as $row) {
        $budgets[$row['category_id']] = floatval($row['total']);
    }
    return $budgets;
}
$monthly_budget = load_budgets($pdo, $start_month, $end_month);
$ytd_budget = load_budgets($pdo, $start_ytd, $end_month);

// Load actuals
function load_actuals($pdo, $start, $end) {
    $actuals = [];
    $stmt = $pdo->prepare("
        SELECT IFNULL(top.id, raw.id) AS top_id, SUM(raw.amount) AS total
        FROM (
            SELECT s.amount, c.id FROM transaction_splits s
            JOIN transactions t ON t.id = s.transaction_id
            JOIN categories c ON s.category_id = c.id
            WHERE t.date BETWEEN ? AND ?
            UNION ALL
            SELECT t.amount, c.id FROM transactions t
            JOIN categories c ON t.category_id = c.id
            LEFT JOIN transaction_splits s ON s.transaction_id = t.id
            WHERE t.date BETWEEN ? AND ? AND s.id IS NULL
        ) raw
        LEFT JOIN categories c2 ON raw.id = c2.id
        LEFT JOIN categories top ON c2.parent_id = top.id
        GROUP BY top_id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d'), $start->format('Y-m-d'), $end->format('Y-m-d')]);
    foreach ($stmt as $row) {
        $actuals[$row['top_id']] = floatval($row['total']);
    }
    return $actuals;
}

$monthly_actual = load_actuals($pdo, $start_month, $end_month);
$ytd_actual = load_actuals($pdo, $start_ytd, $end_month);

// Load forecast
function load_forecast($pdo, $start, $end) {
    $forecast = [];
    $stmt = $pdo->prepare("
        SELECT IFNULL(top.id, c.id) AS top_id, SUM(pi.amount) AS total
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        LEFT JOIN categories top ON c.parent_id = top.id
        WHERE pi.scheduled_date BETWEEN ? AND ?
        GROUP BY top_id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    foreach ($stmt as $row) {
        $forecast[$row['top_id']] = floatval($row['total']);
    }
    return $forecast;
}
$monthly_forecast = load_forecast($pdo, $today, $end_month);
$ytd_forecast = load_forecast($pdo, $today, $end_month);

// Build HTML
function build_table($label, $categories, $budget, $actual, $forecast, $priorities) {
    $html = "<h3 style='margin-top:30px; font-family:sans-serif;'>$label</h3>";
    $html .= "<table cellpadding='6' cellspacing='0' style='border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%;'>";
    $html .= "<tr style='background:#333; color:#fff;'>
                <th align='left'>Category</th>
                <th align='right'>Budget</th>
                <th align='right'>Actual</th>
                <th align='right'>Forecast</th>
                <th align='right'>Variance</th>
              </tr>";

    foreach (['essential' => 'Essential Expenses', 'discretionary' => 'Discretionary Expenses'] as $priority => $labelText) {
        $section = '';

        foreach ($categories as $id => $name) {
            if (($priorities[$id] ?? '') !== $priority) continue;

            $b = $budget[$id] ?? 0;
            $a = -($actual[$id] ?? 0);   // reverse sign for expenses
            $f = -($forecast[$id] ?? 0);
            $v = $b - $a - $f;

            if ($b == 0 && $a == 0 && $f == 0) continue;

            $color = $v >= 0 ? 'green' : 'red';
            $section .= "<tr>
                <td style='border:1px solid #ccc;'>$name</td>
                <td align='right' style='border:1px solid #ccc;'>£" . number_format($b, 2) . "</td>
                <td align='right' style='border:1px solid #ccc;'>£" . number_format($a, 2) . "</td>
                <td align='right' style='border:1px solid #ccc;'>£" . number_format($f, 2) . "</td>
                <td align='right' style='border:1px solid #ccc; color:$color;'>£" . number_format($v, 2) . "</td>
            </tr>";
        }

        if ($section !== '') {
            $html .= "<tr>
                        <td colspan='5' style='background:#f5f5f5; font-weight:bold;'>$labelText</td>
                      </tr>";
            $html .= $section;
        }
    }

    $html .= "</table>";
    return $html;
}


$month_label = $start_month->format('j M') . " – " . $end_month->format('j M');
$ytd_label = $start_ytd->format('j M') . " – " . $end_month->format('j M Y');

$body = "<h2 style='font-family:sans-serif;'>Weekly Budget Summary</h2>";
$body .= "<p style='font-family:sans-serif;'>This email includes <strong>variable expense categories</strong> only.</p>";
$body .= build_table("This Month: $month_label", $categories, $monthly_budget, $monthly_actual, $monthly_forecast, $priorities);
$body .= build_table("Year to Date: $ytd_label", $categories, $ytd_budget, $ytd_actual, $ytd_forecast, $priorities);


// Send it
mail($to, $subject, $body, $headers);
?>
