<?php
require_once __DIR__ . '/../cash_planner.php';
require_once __DIR__ . '/finance_periods.php';

function fe_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fe_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

function fe_get_explainer_accounts(PDO $pdo): array
{
    return cp_get_active_accounts($pdo, ['current']);
}

function fe_get_default_account_id(PDO $pdo): ?int
{
    $accounts = fe_get_explainer_accounts($pdo);
    if (empty($accounts)) {
        return null;
    }
    return (int)$accounts[0]['id'];
}

function fe_get_month_options(?DateTimeInterface $today = null, int $monthsBack = 2, int $monthsForward = 12): array
{
    $range = get_financial_month_range($today);
    $base = $range['start'];

    $options = [];
    for ($offset = -1 * $monthsBack; $offset <= $monthsForward; $offset++) {
        $start = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' month');
        $end = $start->modify('+1 month')->modify('-1 day');

        $options[] = [
            'value' => $start->format('Y-m-d'),
            'label' => $start->format('j M Y') . ' – ' . $end->format('j M Y'),
            'start' => $start,
            'end' => $end,
            'is_current' => ($offset === 0),
        ];
    }

    return $options;
}

function fe_resolve_month_option(array $monthOptions, ?string $requestedValue = null): array
{
    foreach ($monthOptions as $option) {
        if ((string)$option['value'] === (string)$requestedValue) {
            return $option;
        }
    }

    foreach ($monthOptions as $option) {
        if (!empty($option['is_current'])) {
            return $option;
        }
    }

    return $monthOptions[0];
}

function fe_source_bucket(array $event): string
{
    $source = (string)($event['source'] ?? '');
    $eventType = (string)($event['event_type'] ?? '');

    if ($source === 'actual') {
        return ((float)($event['amount'] ?? 0) >= 0) ? 'actual_in' : 'actual_out';
    }

    if ($eventType === 'planned_income_window') {
        return 'flexible_income';
    }

    return ((float)($event['amount'] ?? 0) >= 0) ? 'predicted_in' : 'predicted_out';
}

function fe_driver_rows(array $events, bool $incoming, int $limit = 5): array
{
    $rows = array_values(array_filter($events, function (array $event) use ($incoming): bool {
        $amount = (float)($event['amount'] ?? 0.0);
        return $incoming ? ($amount > 0) : ($amount < 0);
    }));

    usort($rows, function (array $a, array $b): int {
        return abs((float)$b['amount']) <=> abs((float)$a['amount']);
    });

    return array_slice($rows, 0, $limit);
}

function fe_build_summary_lines(array $report): array
{
    $lines = [];

    $lines[] = 'Opening cleared balance on '
        . $report['month']['start']->format('j M')
        . ' is '
        . fe_money((float)$report['opening_balance']) . '.';

    $lines[] = 'Total dated inflows this month are '
        . fe_money((float)$report['totals']['all_in'])
        . ' and total dated outflows are '
        . fe_money((float)$report['totals']['all_out']) . '.';

    if ((float)$report['required_support'] > 0) {
        $lines[] = 'Without support, this account bottoms at '
            . fe_money((float)$report['lowest_balance'])
            . ' on ' . (string)$report['lowest_balance_date']
            . ', so it needs about '
            . fe_money((float)$report['required_support'])
            . ' to stay non-negative.';
    } else {
        $lines[] = 'This account stays non-negative through the month; the lowest projected point is '
            . fe_money((float)$report['lowest_balance'])
            . ' on ' . (string)$report['lowest_balance_date'] . '.';
    }

    if (!empty($report['top_outgoing'])) {
        $top = array_slice($report['top_outgoing'], 0, 3);
        $parts = [];
        foreach ($top as $row) {
            $parts[] = (string)$row['description'] . ' (' . fe_money(abs((float)$row['amount'])) . ')';
        }
        $lines[] = 'Largest outgoing drivers: ' . implode(', ', $parts) . '.';
    }

    if (!empty($report['top_incoming'])) {
        $top = array_slice($report['top_incoming'], 0, 3);
        $parts = [];
        foreach ($top as $row) {
            $parts[] = (string)$row['description'] . ' (' . fe_money((float)$row['amount']) . ')';
        }
        $lines[] = 'Largest incoming offsets: ' . implode(', ', $parts) . '.';
    }

    return $lines;
}

function fe_build_report(PDO $pdo, int $accountId, string $monthStartYmd): array
{
    $accounts = fe_get_explainer_accounts($pdo);
    $account = null;
    foreach ($accounts as $row) {
        if ((int)$row['id'] === $accountId) {
            $account = $row;
            break;
        }
    }

    if ($account === null) {
        throw new RuntimeException('Selected account is not a valid active current account.');
    }

    $monthStart = new DateTimeImmutable($monthStartYmd);
    $monthEnd = $monthStart->modify('+1 month')->modify('-1 day');

    $stream = cp_get_account_event_stream(
        $pdo,
        $accountId,
        $monthStart->format('Y-m-d'),
        $monthEnd->format('Y-m-d')
    );

    $events = $stream['events'] ?? [];
    $openingBalance = (float)($stream['balance_before_start'] ?? 0.0);
    $closingBalance = $openingBalance;
    $lowestBalance = $openingBalance;
    $lowestBalanceDate = $monthStart->format('Y-m-d');

    $totals = [
        'actual_in' => 0.0,
        'actual_out' => 0.0,
        'predicted_in' => 0.0,
        'predicted_out' => 0.0,
        'flexible_income' => 0.0,
        'all_in' => 0.0,
        'all_out' => 0.0,
    ];

    foreach ($events as $event) {
        $amount = (float)($event['amount'] ?? 0.0);
        $bucket = fe_source_bucket($event);

        if ($amount > 0) {
            $totals['all_in'] += $amount;
        } elseif ($amount < 0) {
            $totals['all_out'] += abs($amount);
        }

        if (isset($totals[$bucket])) {
            $totals[$bucket] += ($bucket === 'actual_out' || $bucket === 'predicted_out')
                ? abs($amount)
                : $amount;
        }

        $balanceAfter = (float)($event['balance_after'] ?? $closingBalance);
        $closingBalance = $balanceAfter;

        if ($balanceAfter < $lowestBalance) {
            $lowestBalance = $balanceAfter;
            $lowestBalanceDate = (string)($event['event_date'] ?? $lowestBalanceDate);
        }
    }

    $requiredSupport = max(0.0, -1 * $lowestBalance);

    $report = [
        'account' => [
            'id' => (int)$account['id'],
            'name' => (string)$account['name'],
            'type' => (string)$account['type'],
        ],
        'month' => [
            'start' => $monthStart,
            'end' => $monthEnd,
            'label' => $monthStart->format('j M Y') . ' – ' . $monthEnd->format('j M Y'),
        ],
        'opening_balance' => round($openingBalance, 2),
        'closing_balance' => round($closingBalance, 2),
        'lowest_balance' => round($lowestBalance, 2),
        'lowest_balance_date' => $lowestBalanceDate,
        'required_support' => round($requiredSupport, 2),
        'totals' => array_map(fn($v) => round((float)$v, 2), $totals),
        'top_outgoing' => fe_driver_rows($events, false, 5),
        'top_incoming' => fe_driver_rows($events, true, 5),
        'events' => $events,
    ];

    $report['summary_lines'] = fe_build_summary_lines($report);

    return $report;
}
