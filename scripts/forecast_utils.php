<?php

/**
 * Forecast utility functions
 *
 * Deliverable 6/8 (BKL-006):
 * - Exclude fulfilled predicted instances from forecast calcs.
 *   - fulfilled = 1 (completed) should not appear
 *   - fulfilled = 2 (partial transfer) should not appear (placeholder + real txns already exist)
 */

function get_forecast_shortfalls($db, $forecast_days = 90, $shortfall_window = 31) {
    $today = new DateTimeImmutable();
    $end_date = $today->modify("+{$forecast_days} days");

    // Get active current accounts
    $stmt = $db->prepare("SELECT id, name, starting_balance FROM accounts WHERE active = 1 AND type = 'current'");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Transactions to date
    $stmt = $db->prepare("SELECT account_id, date, amount, description FROM transactions WHERE date < ?");
    $stmt->execute([$today->format('Y-m-d')]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Predicted future events (exclude fulfilled)
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
    ");
    $stmt->execute([$today->format('Y-m-d')]);
    $predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $output = [];

    foreach ($accounts as $acct) {
        $acct_id = (int)$acct['id'];
        $acct_name = $acct['name'];
        $start_balance = (float) $acct['starting_balance'];

        $entries = [];

        // Add actual transactions
        foreach ($transactions as $tx) {
            if ((int)$tx['account_id'] === $acct_id) {
                $entries[$tx['date']][] = [
                    'amount' => (float)$tx['amount'],
                    'desc' => $tx['description']
                ];
            }
        }

        // Add future predictions (unfulfilled only)
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

        // Sort entries by date
        ksort($entries);

        // Calculate balance at start of today
        $today_str = $today->format('Y-m-d');
        $balance = $start_balance;
        foreach ($entries as $date => $items) {
            if ($date <= $today_str) {
                foreach ($items as $item) {
                    $balance += $item['amount'];
                }
            }
        }

        // Begin forecast from tomorrow onward
        $running_balance = $balance;
        $dip_events = [];
        $in_deficit = false;
        $dip_start = null;
        $lowest_point = ['date' => null, 'balance' => INF];

        foreach (new DatePeriod($today->modify('+1 day'), new DateInterval('P1D'), $end_date) as $day) {
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

        if ($lowest_point['balance'] < 0) {
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

    return $output;
}
