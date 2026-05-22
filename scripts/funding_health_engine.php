<?php
require_once __DIR__ . '/forecast_utils.php';
require_once __DIR__ . '/cash_planner.php';
require_once __DIR__ . '/solvency_engine.php';

function fh_money(float $value): string
{
    return '£' . number_format($value, 2);
}

function fh_sort_events(array &$events): void
{
    usort($events, function ($a, $b) {
        if (($a['event_date'] ?? '') !== ($b['event_date'] ?? '')) {
            return strcmp((string)$a['event_date'], (string)$b['event_date']);
        }

        $rank = function (array $event): int {
            $type = (string)($event['event_type'] ?? '');
            $source = (string)($event['source'] ?? '');

            if ($source === 'actual') {
                return 0;
            }
            if ($type === 'required_support_transfer') {
                return 1; // conservative: support leaves savings before same-day future inflows
            }
            if (in_array($type, ['planned_expense', 'planned_transfer_out'], true)) {
                return 2;
            }
            if (in_array($type, ['planned_income_window', 'planned_income', 'planned_transfer_in'], true)) {
                return 3;
            }
            return 4;
        };

        $ra = $rank($a);
        $rb = $rank($b);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        return ((int)($a['source_id'] ?? 0)) <=> ((int)($b['source_id'] ?? 0));
    });
}

function fh_get_required_support_issues(PDO $pdo, int $windowDays = 31): array
{
    // Pass 0 so forecast_utils does not annotate against the monthly reserve model.
    return get_forecast_shortfalls($pdo, $windowDays, $windowDays, 0);
}

function fh_build_primary_funding_health(
    PDO $pdo,
    int $windowDays = 31,
    ?int $reserveAccountId = null,
    float $floor = 0.0,
    ?DateTimeImmutable $today = null
): array {
    $today = $today ?? new DateTimeImmutable('today');
    $reserveAccountId = $reserveAccountId ?: se_get_default_reserve_account_id($pdo);

    if (!$reserveAccountId) {
        return [
            'status' => 'no_savings',
            'headline' => 'No active savings account configured',
            'summary' => 'Funding health cannot be calculated until a reserve / savings account exists.',
            'reserve_account_id' => null,
            'reserve_account_name' => null,
            'window_days' => $windowDays,
            'current_balance' => 0.0,
            'projected_balance_after_today_events' => 0.0,
            'soft_earmarks_total' => se_get_total_earmarks($pdo),
            'total_required_support' => 0.0,
            'total_funding_gap' => 0.0,
            'lowest_projected_balance' => 0.0,
            'lowest_projected_balance_date' => $today->format('Y-m-d'),
            'issues' => [],
            'events' => [],
            'floor' => $floor,
        ];
    }

    $reserveAccountName = se_get_account_name($pdo, $reserveAccountId) ?? 'SAVINGS';
    $softEarmarksTotal = se_get_total_earmarks($pdo);

    $issues = fh_get_required_support_issues($pdo, $windowDays);

    $endDate = $today->modify('+' . $windowDays . ' days')->format('Y-m-d');

    $reserveStream = cp_get_account_event_stream(
        $pdo,
        $reserveAccountId,
        $today->format('Y-m-d'),
        $endDate
    );
    $events = $reserveStream['events'] ?? [];
    $currentBalance = (float)($reserveStream['balance_before_start'] ?? 0.0);
    $projectedBalanceAfterTodayEvents = $currentBalance;

    foreach ($events as $todayEvent) {
        if (($todayEvent['event_date'] ?? '') === $today->format('Y-m-d')) {
            $projectedBalanceAfterTodayEvents = (float)$todayEvent['balance_after'];
        }
    }

    foreach ($issues as $idx => $issue) {
        $events[] = [
            'event_date' => $issue['start_day'],
            'account_id' => $reserveAccountId,
            'account_name' => $reserveAccountName,
            'account_type' => 'savings',
            'amount' => -1 * (float)$issue['top_up'],
            'description' => 'Support transfer to ' . (string)$issue['account_name'],
            'source' => 'required_support',
            'source_label' => 'Required support',
            'event_type' => 'required_support_transfer',
            'source_id' => 1000000 + $idx,
            'issue_index' => $idx,
        ];
    }

    fh_sort_events($events);

    $runningBalance = $currentBalance;
    $lowestBalance = $currentBalance;
    $lowestBalanceDate = $today->format('Y-m-d');

    foreach ($events as $idx => $event) {
        $events[$idx]['balance_before'] = $runningBalance;

        if (($event['event_type'] ?? '') === 'required_support_transfer') {
            $required = abs((float)$event['amount']);
            $fundable = max(0.0, min($required, $runningBalance - $floor));
            $gap = max(0.0, $required - $fundable);

            $events[$idx]['fundable_from_savings'] = $fundable;
            $events[$idx]['funding_gap'] = $gap;
            $events[$idx]['coverage_status'] = ($gap > 0.005) ? 'gap' : 'funded';

            $issueIndex = (int)$event['issue_index'];
            $issues[$issueIndex]['fundable_from_savings'] = $fundable;
            $issues[$issueIndex]['funding_gap'] = $gap;
            $issues[$issueIndex]['savings_balance_before_support'] = $runningBalance;
        }

        $runningBalance += (float)$event['amount'];
        $events[$idx]['balance_after'] = $runningBalance;

        if (($event['event_type'] ?? '') === 'required_support_transfer') {
            $issueIndex = (int)$event['issue_index'];
            $issues[$issueIndex]['savings_balance_after_support'] = $runningBalance;
        }

        if ($runningBalance < $lowestBalance) {
            $lowestBalance = $runningBalance;
            $lowestBalanceDate = (string)$event['event_date'];
        }
    }

    $totalRequiredSupport = 0.0;
    $totalFundingGap = 0.0;

    foreach ($issues as &$issue) {
        $issue['fundable_from_savings'] = (float)($issue['fundable_from_savings'] ?? 0.0);
        $issue['funding_gap'] = (float)($issue['funding_gap'] ?? 0.0);
        $issue['savings_balance_before_support'] = (float)($issue['savings_balance_before_support'] ?? $currentBalance);
        $issue['savings_balance_after_support'] = (float)($issue['savings_balance_after_support'] ?? $currentBalance);
        $issue['coverage_status'] = ($issue['funding_gap'] > 0.005) ? 'gap' : 'funded';

        $totalRequiredSupport += (float)$issue['top_up'];
        $totalFundingGap += (float)$issue['funding_gap'];
    }
    unset($issue);

    if (count($issues) === 0) {
        $status = 'ok';
        $headline = 'No current-account funding action needed in the next ' . $windowDays . ' days';
        $summary = 'No current account is projected to go below zero inside the action window.';
    } elseif ($totalFundingGap > 0.005) {
        $status = 'gap';
        $firstGap = null;
        foreach ($issues as $issue) {
            if (($issue['funding_gap'] ?? 0) > 0.005) {
                $firstGap = $issue;
                break;
            }
        }
        $headline = 'Funding gap of ' . fh_money((float)$totalFundingGap) . ' inside the next ' . $windowDays . ' days';
        if ($firstGap) {
            $summary = 'The earliest uncovered requirement is ' . fh_money((float)$firstGap['funding_gap'])
                . ' for ' . $firstGap['account_name'] . ' by ' . $firstGap['start_day'] . '.';
        } else {
            $summary = 'Known current-account top-ups exceed projected savings cash once dated inflows and outflows are applied.';
        }
    } else {
        $status = 'action';
        $first = $issues[0];
        $headline = 'Move ' . fh_money((float)$first['top_up']) . ' to ' . $first['account_name'] . ' by ' . $first['start_day'];
        $summary = 'Savings can cover all known support transfers in the next ' . $windowDays
            . ' days. Lowest projected savings balance after support is '
            . fh_money((float)$lowestBalance) . ' on ' . $lowestBalanceDate . '.';
    }

    return [
        'status' => $status,
        'headline' => $headline,
        'summary' => $summary,
        'reserve_account_id' => $reserveAccountId,
        'reserve_account_name' => $reserveAccountName,
        'window_days' => $windowDays,
        'current_balance' => (float)$currentBalance,
        'projected_balance_after_today_events' => (float)$projectedBalanceAfterTodayEvents,
        'soft_earmarks_total' => (float)$softEarmarksTotal,
        'total_required_support' => (float)$totalRequiredSupport,
        'total_funding_gap' => (float)$totalFundingGap,
        'lowest_projected_balance' => (float)$lowestBalance,
        'lowest_projected_balance_date' => $lowestBalanceDate,
        'issues' => $issues,
        'events' => $events,
        'floor' => $floor,
    ];
}
