<?php
require_once __DIR__ . '/solvency_engine.php';
require_once __DIR__ . '/cash_planner.php';

/**
 * Forecast utility functions
 *
 * BKL-006:
 * - Exclude fulfilled predicted instances from forecast calcs.
 *
 * BKL-027:
 * - Respect the shortfall window parameter for dashboard transfer prompts.
 * - Annotate each required transfer with reserve-aware affordability from the
 *   default reserve account (main SAVINGS account, then fallback first savings).
 *
 * BKL-028:
 * - Rebuild current-account shortfall forecasting on top of the canonical
 *   account-dated cash event pipeline in cash_planner.php.
 */

function get_forecast_shortfalls($db, $forecast_days = 90, $shortfall_window = 31, $reserve_account_id = null) {
    $today = new DateTimeImmutable('today');
    $end_date = $today->modify("+{$forecast_days} days");
    $window_end = $today->modify("+{$shortfall_window} days");

    $accounts = cp_get_active_accounts($db, ['current']);

    $reserveAccountId = $reserve_account_id ?? se_get_default_reserve_account_id($db);
    $reserveAccountName = $reserveAccountId ? se_get_account_name($db, $reserveAccountId) : null;
    $reserveBalanceNow = 0.0;
    $reserveRequiredNow = 0.0;
    $reserveAvailableNow = 0.0;
    $earmarksTotal = 0.0;

    if ($reserveAccountId) {
        $timeline = se_build_reserve_timeline($db, $reserveAccountId, null, $today);
        $reserveBalanceNow = (float)($timeline['current_reserve_balance'] ?? 0.0);
        $reserveRequiredNow = (float)($timeline['current_required_reserve'] ?? 0.0);
        $reserveAvailableNow = max(0.0, (float)($timeline['current_available_above_reserve'] ?? 0.0));
        $earmarksTotal = (float)($timeline['earmarks_total'] ?? 0.0);
    }

    $output = [];

    foreach ($accounts as $acct) {
        $acct_id = (int)$acct['id'];
        $acct_name = $acct['name'];

        $today_balance = se_get_account_balance_as_of($db, $acct_id, $today->format('Y-m-d'));
        $stream = cp_get_account_event_stream(
            $db,
            $acct_id,
            $today->modify('+1 day')->format('Y-m-d'),
            $end_date->format('Y-m-d')
        );

        $in_deficit = false;
        $dip_start = null;
        $lowest_point = ['date' => null, 'balance' => INF];
        $dip_events = [];

        foreach ($stream['events'] as $event) {
            $eventDate = new DateTimeImmutable($event['event_date']);
            $balanceAfter = (float)$event['balance_after'];

            if ($balanceAfter < 0 && !$in_deficit) {
                $in_deficit = true;
                $dip_start = $event['event_date'];
            }

            if ($in_deficit) {
                $dip_events[] = [
                    'date' => $event['event_date'],
                    'amount' => (float)$event['amount'],
                    'desc' => $event['description'],
                    'balance' => $balanceAfter,
                    'source_label' => $event['source_label'],
                    'event_type' => $event['event_type'],
                ];

                if ($balanceAfter < $lowest_point['balance']) {
                    $lowest_point = [
                        'date' => $event['event_date'],
                        'balance' => $balanceAfter,
                    ];
                }
            }

            if ($in_deficit && $balanceAfter >= 0) {
                break;
            }
        }

        if ($lowest_point['balance'] < 0 && $dip_start !== null && $dip_start <= $window_end->format('Y-m-d')) {
            $output[] = [
                'account_name' => $acct_name,
                'today_balance' => $today_balance,
                'min_day' => $lowest_point['date'],
                'min_balance' => $lowest_point['balance'],
                'top_up' => abs($lowest_point['balance']),
                'start_day' => $dip_start,
                'events' => $dip_events,
            ];
        }
    }

    usort($output, function ($a, $b) {
        if ($a['start_day'] === $b['start_day']) {
            return strcmp($a['account_name'], $b['account_name']);
        }
        return strcmp($a['start_day'], $b['start_day']);
    });

    $remainingSafeReserve = $reserveAvailableNow;

    foreach ($output as &$issue) {
        $safeBefore = $remainingSafeReserve;
        $safeFromReserve = min($safeBefore, (float)$issue['top_up']);
        $breachAmount = max(0.0, (float)$issue['top_up'] - $safeFromReserve);
        $remainingSafeReserve = max(0.0, $safeBefore - $safeFromReserve);

        $issue['reserve_account_id'] = $reserveAccountId;
        $issue['reserve_account_name'] = $reserveAccountName;
        $issue['reserve_balance_now'] = $reserveBalanceNow;
        $issue['reserve_required_now'] = $reserveRequiredNow;
        $issue['earmarks_total'] = $earmarksTotal;
        $issue['safe_available_before_this'] = $safeBefore;
        $issue['safe_from_reserve'] = $safeFromReserve;
        $issue['breach_amount'] = $breachAmount;
        $issue['remaining_safe_after_this'] = $remainingSafeReserve;
        $issue['can_fully_fund_from_reserve'] = ($breachAmount < 0.005);
        $issue['funding_status'] = $reserveAccountId
            ? (($breachAmount < 0.005) ? 'safe' : 'breach')
            : 'no_reserve_account';
    }
    unset($issue);

    return $output;
}
