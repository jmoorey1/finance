<?php
require_once __DIR__ . '/finance_periods.php';

function wqb_config(string $key, $default = null)
{
    return app_config('watcher.budget_quality.' . $key, $default);
}

function wqb_round_money(float $value): float
{
    return round($value, 2);
}

function wqb_action(string $label, string $url, string $headline, array $details = [], array $suggestedValues = []): array
{
    return [
        'label' => $label,
        'url' => $url,
        'headline' => $headline,
        'details' => array_values($details),
        'suggested_values' => $suggestedValues,
    ];
}

function wqb_dashboard_url(): string
{
    return '/finance/public/dashboard.php';
}

function wqb_parent_categories(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, name, priority, watcher_budget_mode
        FROM categories
        WHERE type = 'expense'
          AND fixedness = 'variable'
          AND parent_id IS NULL
        ORDER BY budget_order, name
    ");

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'priority' => (string)($row['priority'] ?? ''),
            'watcher_budget_mode' => (string)($row['watcher_budget_mode'] ?? 'normal'),
        ];
    }

    return $out;
}

function wqb_budget_for_month(PDO $pdo, DateTimeInterface $monthStart): array
{
    $stmt = $pdo->prepare("
        SELECT category_id, SUM(amount) AS total
        FROM budgets
        WHERE month_start = ?
        GROUP BY category_id
    ");
    $stmt->execute([$monthStart->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['category_id']] = (float)$row['total'];
    }

    return $out;
}

function wqb_future_budget(PDO $pdo, DateTimeInterface $fromExclusive, DateTimeInterface $toInclusive): array
{
    $stmt = $pdo->prepare("
        SELECT category_id, SUM(amount) AS total
        FROM budgets
        WHERE month_start > ?
          AND month_start <= ?
        GROUP BY category_id
    ");
    $stmt->execute([
        $fromExclusive->format('Y-m-d'),
        $toInclusive->format('Y-m-d'),
    ]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['category_id']] = (float)$row['total'];
    }

    return $out;
}

function wqb_actual_to_date(PDO $pdo, DateTimeInterface $start, DateTimeInterface $today): array
{
    $stmt = $pdo->prepare("
        SELECT
            topcat.id AS top_id,
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
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.is_prediction = 0
          AND ll.line_date BETWEEN ? AND ?
        GROUP BY topcat.id
    ");
    $stmt->execute([
        $start->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['top_id']] = (float)$row['total'];
    }

    return $out;
}

function wqb_projected_full_month(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today): array
{
    $stmt = $pdo->prepare("
        SELECT
            topcat.id AS top_id,
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
          AND a.type IN ('current', 'credit', 'savings')
          AND ll.line_date BETWEEN ? AND ?
          AND (ll.is_prediction = 0 OR ll.line_date >= ?)
        GROUP BY topcat.id
    ");
    $stmt->execute([
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['top_id']] = (float)$row['total'];
    }

    return $out;
}

function wqb_month_progress(DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $today): float
{
    $totalDays = max(1, (int)$start->diff($end)->format('%a') + 1);
    $elapsedDays = (int)$start->diff($today)->format('%r%a') + 1;
    $elapsedDays = max(0, min($totalDays, $elapsedDays));

    return $elapsedDays / $totalDays;
}

function wqb_context(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $range = get_financial_month_range($today);
    $today = $range['today'];
    $start = $range['start'];
    $end = $range['end'];

    $futureMonths = (int)wqb_config('timing_future_budget_months', 3);
    $futureBudgetEnd = $start->modify('+' . $futureMonths . ' months');

    $categories = wqb_parent_categories($pdo);
    $budget = wqb_budget_for_month($pdo, $start);
    $actualToDate = wqb_actual_to_date($pdo, $start, $today);
    $projectedFull = wqb_projected_full_month($pdo, $start, $end, $today);
    $futureBudget = wqb_future_budget($pdo, $start, $futureBudgetEnd);

    $rows = [];
    foreach ($categories as $catId => $meta) {
        $rows[$catId] = [
            'category_id' => $catId,
            'name' => $meta['name'],
            'priority' => $meta['priority'],
            'watcher_budget_mode' => $meta['watcher_budget_mode'],
            'budget' => (float)($budget[$catId] ?? 0.0),
            'actual_to_date' => (float)($actualToDate[$catId] ?? 0.0),
            'projected_full_month' => (float)($projectedFull[$catId] ?? 0.0),
            'future_budget' => (float)($futureBudget[$catId] ?? 0.0),
        ];
    }

    return [
        'today' => $today,
        'start' => $start,
        'end' => $end,
        'month_progress' => wqb_month_progress($start, $end, $today),
        'rows' => $rows,
        'future_budget_months' => $futureMonths,
    ];
}

function wqb_burn_action(array $row, float $burnRatio, float $monthProgress): array
{
    $headline = 'Watch spending in ' . $row['name'] . ' for the rest of this month.';
    $details = [
        'Actual spend-to-date is £' . number_format($row['actual_to_date'], 2) . ' against a budget of £' . number_format($row['budget'], 2) . '.',
        'That is ' . number_format($burnRatio * 100, 0) . '% of budget with only ' . number_format($monthProgress * 100, 0) . '% of the month elapsed.',
    ];

    if ($row['priority'] === 'discretionary') {
        $details[] = 'This is discretionary spend, so the cleanest response is usually to slow further spend until next month.';
    } else {
        $details[] = 'Because this is not discretionary, review whether the budget itself now needs increasing.';
    }

    return wqb_action(
        'Open monthly dashboard',
        wqb_dashboard_url(),
        $headline,
        $details,
        [
            'category_id' => $row['category_id'],
            'category_name' => $row['name'],
            'budget' => wqb_round_money($row['budget']),
            'actual_to_date' => wqb_round_money($row['actual_to_date']),
            'burn_ratio' => round($burnRatio, 4),
            'month_progress' => round($monthProgress, 4),
        ]
    );
}

function wqb_unrealistic_action(array $row, float $overrun): array
{
    $headline = 'Review the monthly budget for ' . $row['name'] . ' or reduce planned spend this month.';
    $details = [
        'Projected spend this month is £' . number_format($row['projected_full_month'], 2) . ' against a budget of £' . number_format($row['budget'], 2) . '.',
        'Projected overrun: £' . number_format($overrun, 2) . '.',
        'If this pattern is genuine, update the budget rather than carrying a known distortion.',
    ];

    return wqb_action(
        'Open monthly dashboard',
        wqb_dashboard_url(),
        $headline,
        $details,
        [
            'category_id' => $row['category_id'],
            'category_name' => $row['name'],
            'budget' => wqb_round_money($row['budget']),
            'projected_full_month' => wqb_round_money($row['projected_full_month']),
            'projected_overrun' => wqb_round_money($overrun),
        ]
    );
}

function wqb_timing_action(array $row, int $futureMonths, float $currentGap): array
{
    $headline = 'Consider moving some future budget for ' . $row['name'] . ' into the current month.';
    $details = [
        'Actual spend-to-date is already £' . number_format($row['actual_to_date'], 2) . ' against a current-month budget of £' . number_format($row['budget'], 2) . '.',
        'There is still £' . number_format($row['future_budget'], 2) . ' budgeted in the next ' . $futureMonths . ' month' . ($futureMonths === 1 ? '' : 's') . '.',
        'This looks more like timing mismatch than uncontrolled overspend.',
    ];

    return wqb_action(
        'Open monthly dashboard',
        wqb_dashboard_url(),
        $headline,
        $details,
        [
            'category_id' => $row['category_id'],
            'category_name' => $row['name'],
            'current_month_budget' => wqb_round_money($row['budget']),
            'actual_to_date' => wqb_round_money($row['actual_to_date']),
            'future_budget_next_months' => wqb_round_money($row['future_budget']),
            'current_gap' => wqb_round_money($currentGap),
        ]
    );
}

function watcher_budget_detect_burn_risk(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $context = wqb_context($pdo, $today);
    $monthProgress = (float)$context['month_progress'];
    $burnRatioThreshold = (float)wqb_config('burn_ratio_threshold', 0.85);
    $monthProgressCap = (float)wqb_config('burn_month_progress_cap', 0.80);
    $minBudget = (float)wqb_config('burn_min_budget_amount', 50.0);

    $alerts = [];

    foreach ($context['rows'] as $row) {
        if (($row['watcher_budget_mode'] ?? 'normal') !== 'normal') {
            continue;
        }

        $budget = (float)$row['budget'];
        $actual = (float)$row['actual_to_date'];

        if ($budget < $minBudget || $budget <= 0.0 || $actual <= 0.0) {
            continue;
        }

        $burnRatio = $actual / $budget;
        if ($burnRatio < $burnRatioThreshold) {
            continue;
        }

        if ($monthProgress > $monthProgressCap) {
            continue;
        }

        $severity = $burnRatio >= 1.0 ? 'critical' : 'warning';

        $alerts[] = [
            'dedupe_key' => 'budget_burn_risk:' . (int)$row['category_id'],
            'alert_type' => 'budget_burn_risk',
            'severity' => $severity,
            'title' => $row['name'] . ' budget burn is running hot',
            'summary' => $row['name'] . ' has used '
                . number_format($burnRatio * 100, 0)
                . '% of its current-month budget with '
                . number_format($monthProgress * 100, 0)
                . '% of the month elapsed.',
            'evidence_json' => [
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['name'],
                'priority' => $row['priority'],
                'budget' => $budget,
                'actual_to_date' => $actual,
                'burn_ratio' => $burnRatio,
                'month_progress' => $monthProgress,
                'period_start' => $context['start']->format('Y-m-d'),
                'period_end' => $context['end']->format('Y-m-d'),
            ],
            'recommended_action_json' => wqb_burn_action($row, $burnRatio, $monthProgress),
            'related_account_id' => null,
            'related_category_id' => (int)$row['category_id'],
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_budget_detect_unrealistic(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $context = wqb_context($pdo, $today);
    $pctThreshold = (float)wqb_config('unrealistic_overrun_pct', 0.20);
    $absThreshold = (float)wqb_config('unrealistic_overrun_abs', 50.0);

    $alerts = [];

    foreach ($context['rows'] as $row) {
        if (($row['watcher_budget_mode'] ?? 'normal') !== 'normal') {
            continue;
        }

        $budget = (float)$row['budget'];
        $projected = (float)$row['projected_full_month'];
        if ($projected <= 0.0) {
            continue;
        }

        $requiredDelta = max($absThreshold, $budget * $pctThreshold);
        $overrun = $projected - $budget;

        if ($overrun < $requiredDelta) {
            continue;
        }

        $severity = $overrun >= max($absThreshold * 2, max($budget, 1.0) * 0.50) ? 'critical' : 'warning';

        $alerts[] = [
            'dedupe_key' => 'budget_unrealistic:' . (int)$row['category_id'],
            'alert_type' => 'budget_unrealistic',
            'severity' => $severity,
            'title' => $row['name'] . ' budget looks unrealistic this month',
            'summary' => 'Projected current-month spend is £'
                . number_format($projected, 2)
                . ' against a budget of £'
                . number_format($budget, 2)
                . ', a likely overrun of £'
                . number_format($overrun, 2) . '.',
            'evidence_json' => [
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['name'],
                'priority' => $row['priority'],
                'budget' => $budget,
                'actual_to_date' => (float)$row['actual_to_date'],
                'projected_full_month' => $projected,
                'projected_overrun' => $overrun,
                'period_start' => $context['start']->format('Y-m-d'),
                'period_end' => $context['end']->format('Y-m-d'),
            ],
            'recommended_action_json' => wqb_unrealistic_action($row, $overrun),
            'related_account_id' => null,
            'related_category_id' => (int)$row['category_id'],
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_budget_detect_timing_mismatch(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $context = wqb_context($pdo, $today);
    $futureMonths = (int)$context['future_budget_months'];
    $minFutureBudget = (float)wqb_config('timing_future_budget_min', 100.0);
    $currentGapAbs = (float)wqb_config('timing_current_gap_abs', 75.0);

    $alerts = [];

    foreach ($context['rows'] as $row) {
        if (($row['watcher_budget_mode'] ?? 'normal') !== 'normal') {
            continue;
        }

        $budget = (float)$row['budget'];
        $actual = (float)$row['actual_to_date'];
        $futureBudget = (float)$row['future_budget'];

        $currentGap = $actual - $budget;

        if ($currentGap < $currentGapAbs) {
            continue;
        }

        if ($futureBudget < $minFutureBudget) {
            continue;
        }

        $severity = $currentGap >= ($currentGapAbs * 2) ? 'warning' : 'info';

        $alerts[] = [
            'dedupe_key' => 'budget_timing_mismatch:' . (int)$row['category_id'],
            'alert_type' => 'budget_timing_mismatch',
            'severity' => $severity,
            'title' => $row['name'] . ' spend may be arriving earlier than budget timing',
            'summary' => 'Actual spend-to-date is already £'
                . number_format($actual, 2)
                . ' against a current-month budget of £'
                . number_format($budget, 2)
                . ', while £'
                . number_format($futureBudget, 2)
                . ' remains budgeted in the next '
                . $futureMonths . ' month' . ($futureMonths === 1 ? '' : 's') . '.',
            'evidence_json' => [
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['name'],
                'priority' => $row['priority'],
                'current_month_budget' => $budget,
                'actual_to_date' => $actual,
                'current_gap' => $currentGap,
                'future_budget' => $futureBudget,
                'future_budget_months' => $futureMonths,
                'period_start' => $context['start']->format('Y-m-d'),
                'period_end' => $context['end']->format('Y-m-d'),
            ],
            'recommended_action_json' => wqb_timing_action($row, $futureMonths, $currentGap),
            'related_account_id' => null,
            'related_category_id' => (int)$row['category_id'],
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_detect_budget_quality_alerts(PDO $pdo): array
{
    return array_merge(
        watcher_budget_detect_burn_risk($pdo),
        watcher_budget_detect_unrealistic($pdo),
        watcher_budget_detect_timing_mismatch($pdo)
    );
}
