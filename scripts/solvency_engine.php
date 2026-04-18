<?php

function se_current_financial_year(?DateTimeImmutable $today = null): int
{
    $today = $today ?? new DateTimeImmutable('today');
    if ((int)$today->format('d') < 13) {
        $today = $today->modify('-1 month');
    }
    return (int)$today->format('Y');
}

function se_financial_month_key($date): string
{
    if (!$date instanceof DateTimeInterface) {
        $date = new DateTimeImmutable((string)$date);
    } elseif (!$date instanceof DateTimeImmutable) {
        $date = DateTimeImmutable::createFromInterface($date);
    }

    if ((int)$date->format('d') < 13) {
        $date = $date->modify('-1 month');
    }

    return $date->format('Y-m-13');
}

function se_build_financial_months(int $year): array
{
    $months = [];
    $start = new DateTimeImmutable("{$year}-01-13");

    for ($i = 0; $i < 12; $i++) {
        $monthStart = $start->modify("+{$i} months");
        $monthEnd = $monthStart->modify('+1 month')->modify('-1 day');

        $months[] = [
            'key' => $monthStart->format('Y-m-d'),
            'start' => $monthStart,
            'end' => $monthEnd,
            'label' => $monthStart->format('13 M') . ' – ' . $monthEnd->format('12 M Y'),
        ];
    }

    return $months;
}

function se_get_total_earmarks(PDO $pdo): float
{
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(remaining), 0) AS total_remaining
        FROM (
            SELECT SUM(t.amount) AS remaining
            FROM transactions t
            WHERE t.earmark_id IS NOT NULL
            GROUP BY t.earmark_id
            HAVING SUM(t.amount) > 0
        ) x
    ");
    return (float)$stmt->fetchColumn();
}

function se_get_account_balance_as_of(PDO $pdo, int $accountId, string $asOfDate): float
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(a.starting_balance, 0) + COALESCE(SUM(t.amount), 0) AS balance
        FROM accounts a
        LEFT JOIN transactions t
          ON t.account_id = a.id
         AND t.date <= ?
        WHERE a.id = ?
        GROUP BY a.id, a.starting_balance
    ");
    $stmt->execute([$asOfDate, $accountId]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (float)$value : 0.0;
}

function se_get_household_net_between(PDO $pdo, string $startDate, string $endDate): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS net
        FROM (
            SELECT s.amount AS amount
            FROM transaction_splits s
            JOIN transactions t ON t.id = s.transaction_id
            JOIN categories c ON s.category_id = c.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND c.type IN ('income', 'expense')
              AND a.type IN ('current', 'credit', 'savings')

            UNION ALL

            SELECT t.amount AS amount
            FROM transactions t
            LEFT JOIN transaction_splits s ON s.transaction_id = t.id
            JOIN categories c ON t.category_id = c.id
            JOIN accounts a ON t.account_id = a.id
            WHERE t.date BETWEEN ? AND ?
              AND s.id IS NULL
              AND c.type IN ('income', 'expense')
              AND a.type IN ('current', 'credit', 'savings')
        ) x
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    return (float)$stmt->fetchColumn();
}

function se_get_budget_net_by_month(PDO $pdo, int $year): array
{
    $start = "{$year}-01-13";
    $end = (new DateTimeImmutable("{$year}-01-13"))->modify('+12 months')->modify('-1 day')->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT
            b.month_start,
            SUM(
                CASE
                    WHEN c.type = 'income' THEN b.amount
                    WHEN c.type = 'expense' THEN -b.amount
                    ELSE 0
                END
            ) AS net
        FROM budgets b
        JOIN categories c ON b.category_id = c.id
        WHERE b.month_start BETWEEN ? AND ?
        GROUP BY b.month_start
    ");
    $stmt->execute([$start, $end]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['month_start']] = (float)$row['net'];
    }

    return $out;
}

function se_get_manual_adjustments_by_month(PDO $pdo, int $year, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $rangeStart = max($today->format('Y-m-d'), "{$year}-01-13");
    $rangeEnd = (new DateTimeImmutable("{$year}-01-13"))->modify('+12 months')->modify('-1 day')->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT
            pi.scheduled_date,
            pi.amount,
            pi.description
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        JOIN accounts a ON pi.from_account_id = a.id
        WHERE pi.scheduled_date BETWEEN ? AND ?
          AND pi.predicted_transaction_id IS NULL
          AND c.type IN ('income', 'expense')
          AND a.type IN ('current', 'credit', 'savings')
          AND COALESCE(pi.fulfilled, 0) = 0
          AND COALESCE(pi.resolution_status, 'open') = 'open'
        ORDER BY pi.scheduled_date ASC, pi.id ASC
    ");
    $stmt->execute([$rangeStart, $rangeEnd]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = se_financial_month_key($row['scheduled_date']);

        if (!isset($out[$key])) {
            $out[$key] = [
                'net' => 0.0,
                'items' => [],
            ];
        }

        $amt = (float)$row['amount'];
        $out[$key]['net'] += $amt;
        $out[$key]['items'][] = [
            'date' => $row['scheduled_date'],
            'description' => $row['description'],
            'amount' => $amt,
        ];
    }

    return $out;
}

function se_build_reserve_timeline(PDO $pdo, int $reserveAccountId, ?int $year = null, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $year = $year ?? se_current_financial_year($today);

    $months = se_build_financial_months($year);
    $budgetNetByMonth = se_get_budget_net_by_month($pdo, $year);
    $manualAdjustmentsByMonth = se_get_manual_adjustments_by_month($pdo, $year, $today);
    $earmarksTotal = se_get_total_earmarks($pdo);

    $todayStr = $today->format('Y-m-d');
    $currentMonthKey = se_financial_month_key($today);
    $currentReserveBalance = se_get_account_balance_as_of($pdo, $reserveAccountId, $todayStr);

    $rows = [];

    foreach ($months as $month) {
        $key = $month['key'];
        $startStr = $month['start']->format('Y-m-d');
        $endStr = $month['end']->format('Y-m-d');

        $budgetNet = (float)($budgetNetByMonth[$key] ?? 0.0);
        $manualAdjustmentNet = (float)($manualAdjustmentsByMonth[$key]['net'] ?? 0.0);
        $manualItems = $manualAdjustmentsByMonth[$key]['items'] ?? [];

        if ($today > $month['end']) {
            $phase = 'past';
        } elseif ($today >= $month['start'] && $today <= $month['end']) {
            $phase = 'current';
        } else {
            $phase = 'future';
        }

        $row = [
            'key' => $key,
            'label' => $month['label'],
            'phase' => $phase,
            'budget_net' => $budgetNet,
            'actual_full_month_net' => null,
            'actual_to_date_net' => null,
            'remaining_budget_net' => null,
            'manual_adjustment_net' => $manualAdjustmentNet,
            'manual_items' => $manualItems,
            'planning_net' => null,
            'reference_balance' => null,
            'closing_balance' => null,
            'required_reserve_from_here' => null,
            'available_above_reserve' => null,
        ];

        if ($phase === 'past') {
            $actualNet = se_get_household_net_between($pdo, $startStr, $endStr);
            $row['actual_full_month_net'] = $actualNet;
            $row['planning_net'] = $actualNet;
            $row['reference_balance'] = se_get_account_balance_as_of($pdo, $reserveAccountId, $endStr);
            $row['closing_balance'] = $row['reference_balance'];
        } elseif ($phase === 'current') {
            $actualToDate = se_get_household_net_between($pdo, $startStr, $todayStr);
            $remainingBudgetNet = $budgetNet - $actualToDate;
            $planningNet = $remainingBudgetNet + $manualAdjustmentNet;

            $row['actual_to_date_net'] = $actualToDate;
            $row['remaining_budget_net'] = $remainingBudgetNet;
            $row['planning_net'] = $planningNet;
            $row['reference_balance'] = $currentReserveBalance;
            $row['closing_balance'] = $currentReserveBalance + $planningNet;
        } else {
            $planningNet = $budgetNet + $manualAdjustmentNet;
            $row['planning_net'] = $planningNet;
        }

        $rows[] = $row;
    }

    // Project future balances forward from the current point.
    $runningBalance = null;
    foreach ($rows as $idx => $row) {
        if ($row['phase'] === 'current') {
            $runningBalance = (float)$row['closing_balance'];
            continue;
        }

        if ($row['phase'] === 'future') {
            if ($runningBalance === null) {
                $runningBalance = $currentReserveBalance;
            }

            $rows[$idx]['reference_balance'] = $runningBalance;
            $rows[$idx]['closing_balance'] = $runningBalance + (float)$row['planning_net'];
            $runningBalance = (float)$rows[$idx]['closing_balance'];
        }
    }

    // Calculate reserve required from each current/future point.
    $currentOrFutureIndexes = [];
    foreach ($rows as $idx => $row) {
        if (in_array($row['phase'], ['current', 'future'], true)) {
            $currentOrFutureIndexes[] = $idx;
        }
    }

    foreach ($currentOrFutureIndexes as $offset => $idx) {
        $cum = 0.0;
        $minCum = 0.0;

        for ($j = $offset; $j < count($currentOrFutureIndexes); $j++) {
            $rowIdx = $currentOrFutureIndexes[$j];
            $cum += (float)$rows[$rowIdx]['planning_net'];
            if ($cum < $minCum) {
                $minCum = $cum;
            }
        }

        $required = abs($minCum);
        $rows[$idx]['required_reserve_from_here'] = $required;
        $rows[$idx]['available_above_reserve'] = (float)$rows[$idx]['reference_balance'] - $earmarksTotal - $required;
    }

    $currentRow = null;
    foreach ($rows as $row) {
        if ($row['phase'] === 'current') {
            $currentRow = $row;
            break;
        }
    }

    $peakRequired = 0.0;
    $lowestAvailable = null;
    $lowestAvailableMonth = null;

    foreach ($rows as $row) {
        if ($row['required_reserve_from_here'] !== null) {
            $peakRequired = max($peakRequired, (float)$row['required_reserve_from_here']);
        }
        if ($row['available_above_reserve'] !== null) {
            if ($lowestAvailable === null || (float)$row['available_above_reserve'] < $lowestAvailable) {
                $lowestAvailable = (float)$row['available_above_reserve'];
                $lowestAvailableMonth = $row['label'];
            }
        }
    }

    return [
        'year' => $year,
        'today' => $todayStr,
        'current_month_key' => $currentMonthKey,
        'current_reserve_balance' => $currentReserveBalance,
        'earmarks_total' => $earmarksTotal,
        'current_required_reserve' => $currentRow['required_reserve_from_here'] ?? 0.0,
        'current_available_above_reserve' => $currentRow['available_above_reserve'] ?? ($currentReserveBalance - $earmarksTotal),
        'peak_required_reserve' => $peakRequired,
        'lowest_available_above_reserve' => $lowestAvailable,
        'lowest_available_month' => $lowestAvailableMonth,
        'rows' => $rows,
    ];
}
