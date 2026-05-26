<?php
require_once __DIR__ . '/finance_periods.php';

function ins_format_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

function ins_load_variable_parent_categories(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, name, priority
        FROM categories
        WHERE type = 'expense'
          AND fixedness = 'variable'
          AND parent_id IS NULL
          AND COALESCE(watcher_budget_mode, 'normal') = 'normal'
        ORDER BY budget_order, name
    ");

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = [
            'name' => (string)$row['name'],
            'priority' => (string)($row['priority'] ?? ''),
        ];
    }

    return $out;
}

function ins_load_budget_totals(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end): array
{
    $stmt = $pdo->prepare("
        SELECT category_id, SUM(amount) AS total
        FROM budgets
        WHERE month_start BETWEEN ? AND ?
        GROUP BY category_id
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['category_id']] = (float)$row['total'];
    }

    return $out;
}

function ins_fetch_variable_expense_totals(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today): array
{
    $stmt = $pdo->prepare("
        SELECT
            topcat.id AS top_id,
            topcat.name AS category_name,
            topcat.priority,
            SUM(-ll.amount) AS total
        FROM ledger_lines ll
        JOIN accounts a
          ON a.id = ll.account_id
        JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type = 'expense'
          AND topcat.type = 'expense'
          AND topcat.fixedness = 'variable'
          AND topcat.parent_id IS NULL
          AND COALESCE(topcat.watcher_budget_mode, 'normal') = 'normal'
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.line_date BETWEEN ? AND ?
          AND (ll.is_prediction = 0 OR ll.line_date >= ?)
        GROUP BY topcat.id, topcat.name, topcat.priority
        ORDER BY total DESC, topcat.name ASC
    ");
    $stmt->execute([
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['top_id']] = [
            'name' => (string)$row['category_name'],
            'priority' => (string)($row['priority'] ?? ''),
            'total' => (float)$row['total'],
        ];
    }

    return $out;
}

function ins_fetch_actual_discretionary_totals(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end): array
{
    $stmt = $pdo->prepare("
        SELECT
            topcat.id AS top_id,
            topcat.name AS category_name,
            SUM(-ll.amount) AS total
        FROM ledger_lines ll
        JOIN accounts a
          ON a.id = ll.account_id
        JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type = 'expense'
          AND topcat.type = 'expense'
          AND topcat.parent_id IS NULL
          AND topcat.priority = 'discretionary'
          AND COALESCE(topcat.watcher_budget_mode, 'normal') = 'normal'
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.is_prediction = 0
          AND ll.line_date BETWEEN ? AND ?
        GROUP BY topcat.id, topcat.name
        ORDER BY total DESC, topcat.name ASC
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['top_id']] = [
            'name' => (string)$row['category_name'],
            'total' => (float)$row['total'],
        ];
    }

    return $out;
}

function ins_fetch_period_discretionary_payees(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today): array
{
    $stmt = $pdo->prepare("
        SELECT
            ll.description AS payee,
            SUM(-ll.amount) AS total
        FROM ledger_lines ll
        JOIN accounts a
          ON a.id = ll.account_id
        JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type = 'expense'
          AND topcat.type = 'expense'
          AND topcat.parent_id IS NULL
          AND topcat.priority = 'discretionary'
          AND COALESCE(topcat.watcher_budget_mode, 'normal') = 'normal'
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.line_date BETWEEN ? AND ?
          AND (ll.is_prediction = 0 OR ll.line_date >= ?)
        GROUP BY ll.description
        HAVING total > 0
        ORDER BY total DESC, ll.description ASC
        LIMIT 10
    ");
    $stmt->execute([
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function ins_fetch_period_income_transactions(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today, int $limit = 5): array
{
    $limit = max(1, min(10, $limit));

    $stmt = $pdo->prepare("
        SELECT
            ll.line_date,
            ll.description,
            ll.amount,
            ll.account_id,
            a.name AS account_name,
            COALESCE(topcat.name, ll.category_name) AS category_name,
            ll.category_type,
            ll.is_prediction
        FROM ledger_lines ll
        JOIN accounts a
          ON a.id = ll.account_id
        LEFT JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type IN ('income', 'expense')
          AND ll.amount > 0
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.line_date BETWEEN ? AND ?
          AND (ll.is_prediction = 0 OR ll.line_date >= ?)
        ORDER BY ll.amount DESC, ll.line_date ASC, ll.description ASC
        LIMIT {$limit}
    ");
    $stmt->execute([
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ins_build_income_headline(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today, int $limit = 3): ?string
{
    $rows = ins_fetch_period_income_transactions($pdo, $start, $end, $today, $limit);
    if (empty($rows)) {
        return null;
    }

    $parts = [];
    foreach ($rows as $row) {
        $label = date('j M', strtotime((string)$row['line_date']))
            . ' — '
            . (string)$row['description']
            . ' '
            . ins_format_money((float)$row['amount']);

        if (!empty($row['is_prediction'])) {
            $label .= ' (predicted)';
        }

        $parts[] = $label;
    }

    return 'Top income items this month: ' . implode('; ', $parts) . '.';
}

function ins_fetch_period_expense_total(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today, bool $discretionaryOnly): float
{
    $sql = "
        SELECT
            COALESCE(SUM(-ll.amount), 0) AS total
        FROM ledger_lines ll
        JOIN accounts a
          ON a.id = ll.account_id
        JOIN categories topcat
          ON topcat.id = COALESCE(ll.parent_category_id, ll.category_id)
        WHERE ll.category_type = 'expense'
          AND topcat.type = 'expense'
          AND topcat.parent_id IS NULL
          AND COALESCE(topcat.watcher_budget_mode, 'normal') = 'normal'
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.line_date BETWEEN ? AND ?
          AND (ll.is_prediction = 0 OR ll.line_date >= ?)
    ";

    if ($discretionaryOnly) {
        $sql .= " AND topcat.priority = 'discretionary'";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);

    return (float)$stmt->fetchColumn();
}

function build_budget_headlines(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $range = get_weekly_digest_reporting_range($today);
    $today = $range['today'];
    $startMonth = $range['start'];
    $endMonth = $range['end'];

    $categories = ins_load_variable_parent_categories($pdo);
    $monthlyBudget = ins_load_budget_totals($pdo, $startMonth, $endMonth);
    $monthlyActualAndForecast = ins_fetch_variable_expense_totals($pdo, $startMonth, $endMonth, $today);

    $headlines = [];
    $overBudgetHeadlines = [];

    foreach ($categories as $catId => $meta) {
        $budgeted = (float)($monthlyBudget[$catId] ?? 0);
        $actual = (float)($monthlyActualAndForecast[$catId]['total'] ?? 0);

        if ($actual <= 0 && $budgeted <= 0) {
            continue;
        }

        if ($budgeted <= 0 && $actual > 0) {
            $overspend = $actual;
            $message = "'" . $meta['name'] . "' has " . ins_format_money($actual) . " of variable spend this month with no budget set.";
        } elseif ($actual > $budgeted) {
            $overspend = $actual - $budgeted;
            $message = "'" . $meta['name'] . "' is " . ins_format_money($overspend) . " over budget this month.";
        } else {
            continue;
        }

        $overBudgetHeadlines[] = [
            'cat_id' => $catId,
            'cat_name' => $meta['name'],
            'cat_priority' => $meta['priority'],
            'amount' => $overspend,
            'message' => $message,
        ];
    }

    usort($overBudgetHeadlines, fn($a, $b) => $b['amount'] <=> $a['amount']);
    $topOverBudget = array_slice($overBudgetHeadlines, 0, 3);

    $essentialOverrun = 0.0;
    $discretionaryOverrun = 0.0;

    foreach ($overBudgetHeadlines as $item) {
        if ($item['cat_priority'] === 'essential') {
            $essentialOverrun += (float)$item['amount'];
        } elseif ($item['cat_priority'] === 'discretionary') {
            $discretionaryOverrun += (float)$item['amount'];
        }
    }

    if ($essentialOverrun > 0 && $discretionaryOverrun > 0) {
        $headlines[] = "Current and planned variable spending is " . ins_format_money($essentialOverrun) . " over budget in essential categories and " . ins_format_money($discretionaryOverrun) . " over budget in discretionary categories this month.";
    } elseif ($essentialOverrun > 0) {
        $headlines[] = "Current and planned variable spending is " . ins_format_money($essentialOverrun) . " over budget in essential categories this month.";
    } elseif ($discretionaryOverrun > 0) {
        $headlines[] = "Current and planned variable spending is " . ins_format_money($discretionaryOverrun) . " over budget in discretionary categories this month.";
    } else {
        $headlines[] = "Current and planned variable spending is within budget this month.";
    }

    foreach ($topOverBudget as $item) {
        $headlines[] = $item['message'];
    }

    $priorStart = $startMonth->modify('-3 months');
    $priorEnd = $startMonth->modify('-1 day');

    $currentDiscretionary = ins_fetch_actual_discretionary_totals($pdo, $startMonth, $endMonth);
    $priorDiscretionary = ins_fetch_actual_discretionary_totals($pdo, $priorStart, $priorEnd);

    $driftIncreases = [];
    $driftDecreases = [];

    foreach ($currentDiscretionary as $catId => $currentRow) {
        $avgMonthly = ((float)($priorDiscretionary[$catId]['total'] ?? 0)) / 3;
        $current = (float)$currentRow['total'];

        if ($avgMonthly > 0 && $current > 2 * $avgMonthly) {
            $percent = round(100 * ($current / $avgMonthly));
            $diff = $current - $avgMonthly;

            $driftIncreases[] = [
                'delta' => $percent - 100,
                'message' => "Spending on '" . $currentRow['name'] . "' (" . ins_format_money($current) . ") is up " . ins_format_money($diff) . " — {$percent}% of your 3-month average (" . ins_format_money($avgMonthly) . ").",
            ];
        } elseif ($avgMonthly > 0 && $current < 0.5 * $avgMonthly) {
            $percent = round(100 * ($current / $avgMonthly));
            $diff = $avgMonthly - $current;

            $driftDecreases[] = [
                'delta' => 100 - $percent,
                'message' => "Spending on '" . $currentRow['name'] . "' (" . ins_format_money($current) . ") is down " . ins_format_money($diff) . " — only {$percent}% of your 3-month average (" . ins_format_money($avgMonthly) . ").",
            ];
        }
    }

    usort($driftIncreases, fn($a, $b) => $b['delta'] <=> $a['delta']);
    usort($driftDecreases, fn($a, $b) => $b['delta'] <=> $a['delta']);

    foreach (array_slice($driftIncreases, 0, 2) as $item) {
        $headlines[] = $item['message'];
    }
    foreach (array_slice($driftDecreases, 0, 2) as $item) {
        $headlines[] = $item['message'];
    }

    $topPayees = ins_fetch_period_discretionary_payees($pdo, $startMonth, $endMonth, $today);
    $discretionaryTotal = ins_fetch_period_expense_total($pdo, $startMonth, $endMonth, $today, true);
    $expenseTotal = ins_fetch_period_expense_total($pdo, $startMonth, $endMonth, $today, false);

    $mainHeadlineShown = false;
    $secondaryHeadlines = [];

    if ($discretionaryTotal > 0 && count($topPayees) > 0) {
        foreach ($topPayees as $row) {
            $payee = (string)$row['payee'];
            $amount = (float)$row['total'];
            $percent = (int)round(100 * $amount / $discretionaryTotal);

            if (!$mainHeadlineShown && $percent > 10) {
                $headlines[] = "'" . $payee . "' accounted for " . ins_format_money($amount) . " — {$percent}% of discretionary spending this month.";
                $mainHeadlineShown = true;
            } elseif ($percent >= 5 && count($secondaryHeadlines) < 3) {
                $secondaryHeadlines[] = "- {$payee}: " . ins_format_money($amount) . " ({$percent}%)";
            }
        }

        if (!$mainHeadlineShown) {
            $headlines[] = "No single payee accounted for more than 10% of discretionary spending this month.";
        }

        if (!empty($secondaryHeadlines)) {
            $headlines[] = "Other notable discretionary payees this month:\n" . implode("\n", $secondaryHeadlines);
        }
    }

    if ($expenseTotal > 0 && $discretionaryTotal > 0) {
        $percent = (int)round(100 * $discretionaryTotal / $expenseTotal);
        $headlines[] = "Discretionary spending (" . ins_format_money($discretionaryTotal) . ") makes up {$percent}% of total expenses (" . ins_format_money($expenseTotal) . ") this month.";
    }

    $incomeHeadline = ins_build_income_headline($pdo, $startMonth, $endMonth, $today, 3);
    if ($incomeHeadline !== null) {
        $headlines[] = $incomeHeadline;
    }

    $headlines = array_values(array_filter(array_map(
        fn($line) => trim((string)$line),
        $headlines
    )));

    return $headlines;
}
