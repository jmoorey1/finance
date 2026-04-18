<?php
require_once __DIR__ . '/solvency_engine.php';

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
 */

function get_forecast_shortfalls($db, $forecast_days = 90, $shortfall_window = 31, $reserve_account_id = null) {
    $today = new DateTimeImmutable('today');
    $end_date = $today->modify("+{$forecast_days} days");
    $window_end = $today->modify("+{$shortfall_window} days");

    $stmt = $db->prepare("SELECT id, name, starting_balance FROM accounts WHERE active = 1 AND type = 'current'");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT account_id, date, amount, description FROM transactions WHERE date < ?");
    $stmt->execute([$today->format('Y-m-d')]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT
            p.scheduled_date AS date,
            p.amount,
            p.description,
            c.type AS category_type,
            p.from_account_id,
            p.to_account_id
        FROM predicted_instances p
        INNER JOIN categories c ON p.category_id = c.id
        WHERE p.scheduled_date >= ?
          AND COALESCE(p.fulfilled, 0) = 0
          AND COALESCE(p.resolution_status, 'open') = 'open'
    ");
    $stmt->execute([$today->format('Y-m-d')]);
    $predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        $start_balance = (float) $acct['starting_balance'];

        $entries = [];

        foreach ($transactions as $tx) {
            if ((int)$tx['account_id'] === $acct_id) {
                $entries[$tx['date']][] = [
                    'amount' => (float)$tx['amount'],
                    'desc' => $tx['description']
                ];
            }
        }

        foreach ($predictions as $p) {
            $date = $p['date'];
            $amt = (float)($p['amount'] ?? 0);
            $desc = $p['description'];

            if ($p['category_type'] === 'income' || $p['category_type'] === 'expense') {
                if ((int)$p['from_account_id'] === $acct_id) {
                    $entries[$date][] = ['amount' => $amt, 'desc' => $desc];
                }
            } elseif ($p['category_type'] === 'transfer') {
                if ((int)$p['from_account_id'] === $acct_id) {
                    $entries[$date][] = ['amount' => -$amt, 'desc' => $desc];
                }
                if ((int)$p['to_account_id'] === $acct_id) {
                    $entries[$date][] = ['amount' => $amt, 'desc' => $desc];
                }
            }
        }

        ksort($entries);

        $today_str = $today->format('Y-m-d');
        $balance = $start_balance;
        foreach ($entries as $date => $items) {
            if ($date <= $today_str) {
                foreach ($items as $item) {
                    $balance += $item['amount'];
                }
            }
        }

        $running_balance = $balance;
        $dip_events = [];
        $in_deficit = false;
        $dip_start = null;
        $lowest_point = ['date' => null, 'balance' => INF];

        foreach (new DatePeriod($today->modify('+1 day'), new DateInterval('P1D'), $end_date->modify('+1 day')) as $day) {
            $date_str = $day->format('Y-m-d');

            if (isset($entries[$date_str])) {
                foreach ($entries[$date_str] as $item) {
                    $running_balance += $item['amount'];

                    if ($running_balance < 0 && !$in_deficit) {
                        $in_deficit = true;
                        $dip_start = $date_str;
                    }

                    if ($in_deficit) {
                        $dip_events[] = [
                            'date' => $date_str,
                            'amount' => $item['amount'],
                            'desc' => $item['desc'],
                            'balance' => $running_balance
                        ];

                        if ($running_balance < $lowest_point['balance']) {
                            $lowest_point = ['date' => $date_str, 'balance' => $running_balance];
                        }
                    }
                }
            }

            if ($in_deficit && $running_balance >= 0) {
                break;
            }
        }

        if ($lowest_point['balance'] < 0 && $dip_start !== null && $dip_start <= $window_end->format('Y-m-d')) {
            $output[] = [
                'account_name' => $acct_name,
                'today_balance' => $balance,
                'min_day' => $lowest_point['date'],
                'min_balance' => $lowest_point['balance'],
                'top_up' => abs($lowest_point['balance']),
                'start_day' => $dip_start,
                'events' => $dip_events
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
