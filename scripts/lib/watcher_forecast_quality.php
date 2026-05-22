<?php

function wq_round_money(float $value): float
{
    return round($value, 2);
}

function wq_rule_review_url(?int $ruleId = null): string
{
    return '/finance/public/predicted.php' . ($ruleId ? ('#rule-' . $ruleId) : '');
}

function wq_action(string $label, string $url, string $headline, array $details = [], array $suggestedValues = []): array
{
    return [
        'label' => $label,
        'url' => $url,
        'headline' => $headline,
        'details' => array_values($details),
        'suggested_values' => $suggestedValues,
    ];
}

function wq_build_date_drift_action(int $ruleId, string $direction, int $days, float $ratio, ?string $description): array
{
    $headline = 'Review the rule timing. Recent fulfilments are consistently landing ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ' . $direction . ' than scheduled.';
    $details = [
        'Check whether the rule cadence or anchor date needs moving.',
        'If the pattern is intentional, update the rule rather than letting misses accumulate.',
        'Confidence: ' . number_format($ratio * 100, 0) . '% of the sampled fulfilments show the same day drift.',
    ];

    return wq_action(
        'Review recurring rule',
        wq_rule_review_url($ruleId),
        $headline,
        $details,
        [
            'rule_id' => $ruleId,
            'suggested_day_shift' => $direction === 'later' ? $days : -$days,
            'rule_description' => $description,
        ]
    );
}

function wq_build_amount_drift_action(int $ruleId, float $expected, float $medianActual, ?string $description): array
{
    $headline = 'Review the rule amount. Recent fulfilments cluster around £' . number_format($medianActual, 2) . ' rather than the configured £' . number_format($expected, 2) . '.';
    $details = [
        'If this is the new normal, update the rule amount to reduce future forecast distortion.',
        'If the amount varies intentionally, consider whether the rule should be marked variable instead.',
    ];

    return wq_action(
        'Review recurring rule',
        wq_rule_review_url($ruleId),
        $headline,
        $details,
        [
            'rule_id' => $ruleId,
            'current_amount' => wq_round_money($expected),
            'suggested_amount' => wq_round_money($medianActual),
            'rule_description' => $description,
        ]
    );
}

function wq_build_missing_pattern_action(string $cadence, float $medianAmountAbs, string $payeeName, string $accountName, string $categoryName): array
{
    $headline = 'Consider creating a new ' . $cadence . ' recurring rule for ' . ($payeeName !== '' ? $payeeName : 'this payee') . '.';
    $details = [
        'Recent transactions look consistent enough to model as a recurring pattern.',
        'Suggested starting amount: about £' . number_format($medianAmountAbs, 2) . '.',
        'Check account/category mapping before creating the rule: ' . $accountName . ' / ' . $categoryName . '.',
    ];

    return wq_action(
        'Open recurring rules',
        '/finance/public/predicted.php',
        $headline,
        $details,
        [
            'suggested_cadence' => $cadence,
            'suggested_amount_abs' => wq_round_money($medianAmountAbs),
            'payee_name' => $payeeName,
            'account_name' => $accountName,
            'category_name' => $categoryName,
        ]
    );
}

function wq_build_prediction_miss_action(int $ruleId, int $count, string $oldestOpenMiss, ?string $description): array
{
    $headline = 'Review this rule and reconcile or skip the missed instances before they distort the forecast further.';
    $details = [
        'There are ' . $count . ' open missed instances.',
        'Oldest unresolved miss: ' . $oldestOpenMiss . '.',
        'If the pattern has changed, update the rule. If the events already happened, reconcile them.',
    ];

    return wq_action(
        'Review predicted instances',
        '/finance/public/predicted.php',
        $headline,
        $details,
        [
            'rule_id' => $ruleId,
            'open_missed_count' => $count,
            'oldest_open_missed' => $oldestOpenMiss,
            'rule_description' => $description,
        ]
    );
}

function wq_build_review_backlog_action(string $accountName, int $count, int $age): array
{
    $headline = 'Clear the review queue for ' . $accountName . ' before prediction matching quality degrades.';
    $details = [
        $count . ' unresolved staging item' . ($count === 1 ? '' : 's') . ' remain.',
        'Oldest unresolved item is ' . $age . ' day' . ($age === 1 ? '' : 's') . ' old.',
        'Start with fulfils-prediction and potential-duplicate rows first.',
    ];

    return wq_action(
        'Open review queue',
        '/finance/public/review.php',
        $headline,
        $details,
        [
            'account_name' => $accountName,
            'backlog_count' => $count,
            'age_days' => $age,
            'recommended_priority' => ['fulfills_prediction', 'potential_duplicate', 'new'],
        ]
    );
}

function wq_config(string $key, $default = null)
{
    return app_config('watcher.forecast_quality.' . $key, $default);
}

function wq_median(array $values): ?float
{
    $values = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
    if (empty($values)) {
        return null;
    }

    sort($values, SORT_NUMERIC);
    $count = count($values);
    $mid = intdiv($count, 2);

    if ($count % 2 === 0) {
        return (((float)$values[$mid - 1]) + ((float)$values[$mid])) / 2;
    }

    return (float)$values[$mid];
}

function wq_amount_tolerance(float $reference, float $percent, float $minAbs): float
{
    return max($minAbs, abs($reference) * $percent);
}

function wq_ratio_within_tolerance(array $values, float $reference, float $percent, float $minAbs): float
{
    if (empty($values)) {
        return 0.0;
    }

    $tol = wq_amount_tolerance($reference, $percent, $minAbs);
    $within = 0;

    foreach ($values as $value) {
        if (abs((float)$value - $reference) <= $tol) {
            $within++;
        }
    }

    return $within / count($values);
}

function wq_days_diff(string $scheduledDate, string $actualDate): ?int
{
    try {
        $scheduled = new DateTimeImmutable($scheduledDate);
        $actual = new DateTimeImmutable($actualDate);
    } catch (Throwable $e) {
        return null;
    }

    return (int)$scheduled->diff($actual)->format('%r%a');
}

function wq_active_rule_snapshot(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            pt.id,
            pt.description,
            pt.from_account_id,
            pt.category_id,
            pt.amount,
            pt.variable,
            pt.active
        FROM predicted_transactions pt
        WHERE pt.active = 1
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function wq_has_similar_active_rule(array $rules, int $accountId, int $categoryId, float $medianAmount, string $payeeName): bool
{
    $payeeNameLower = mb_strtolower(trim($payeeName));
    $amountTol = max(5.0, abs($medianAmount) * 0.20);

    foreach ($rules as $rule) {
        if ((int)$rule['from_account_id'] !== $accountId) {
            continue;
        }
        if ((int)$rule['category_id'] !== $categoryId) {
            continue;
        }

        $ruleAmount = $rule['amount'] !== null ? (float)$rule['amount'] : null;
        $desc = mb_strtolower((string)($rule['description'] ?? ''));

        if ($payeeNameLower !== '' && $desc !== '' && str_contains($desc, $payeeNameLower)) {
            return true;
        }

        if ($ruleAmount !== null && abs(abs($ruleAmount) - abs($medianAmount)) <= $amountTol) {
            return true;
        }
    }

    return false;
}

function watcher_fq_detect_rule_date_drift(PDO $pdo): array
{
    $historyLimit = (int)wq_config('rule_history_limit', 6);
    $minHistory = (int)wq_config('date_drift_min_fulfilled', 4);
    $consistencyRatio = (float)wq_config('date_drift_consistency_ratio', 0.75);

    $stmt = $pdo->query("
        SELECT
            pt.id AS rule_id,
            pt.description AS rule_description,
            pt.from_account_id,
            a.name AS account_name,
            c.name AS category_name,
            pi.id AS predicted_instance_id,
            pi.scheduled_date,
            COALESCE(tx.date, tg.actual_date) AS actual_date
        FROM predicted_transactions pt
        JOIN predicted_instances pi
          ON pi.predicted_transaction_id = pt.id
        LEFT JOIN accounts a
          ON a.id = pt.from_account_id
        LEFT JOIN categories c
          ON c.id = pt.category_id
        LEFT JOIN transactions tx
          ON tx.id = pi.fulfilled_by_transaction_id
        LEFT JOIN (
            SELECT transfer_group_id, MIN(date) AS actual_date
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
            GROUP BY transfer_group_id
        ) tg
          ON tg.transfer_group_id = pi.fulfilled_by_transfer_group_id
        WHERE pt.active = 1
          AND pi.fulfilled = 1
          AND COALESCE(tx.date, tg.actual_date) IS NOT NULL
        ORDER BY pt.id, pi.scheduled_date DESC, pi.id DESC
    ");

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ruleId = (int)$row['rule_id'];
        if (!isset($grouped[$ruleId])) {
            $grouped[$ruleId] = [];
        }
        if (count($grouped[$ruleId]) < $historyLimit) {
            $grouped[$ruleId][] = $row;
        }
    }

    $alerts = [];

    foreach ($grouped as $ruleId => $items) {
        if (count($items) < $minHistory) {
            continue;
        }

        $diffs = [];
        $evidenceItems = [];

        foreach ($items as $item) {
            $diff = wq_days_diff((string)$item['scheduled_date'], (string)$item['actual_date']);
            if ($diff === null) {
                continue;
            }
            $diffs[] = $diff;
            $evidenceItems[] = [
                'predicted_instance_id' => (int)$item['predicted_instance_id'],
                'scheduled_date' => (string)$item['scheduled_date'],
                'actual_date' => (string)$item['actual_date'],
                'day_diff' => $diff,
            ];
        }

        if (count($diffs) < $minHistory) {
            continue;
        }

        $medianDiff = (int)round((float)wq_median($diffs));
        if ($medianDiff === 0) {
            continue;
        }

        $matching = 0;
        foreach ($diffs as $diff) {
            if ((int)$diff === $medianDiff) {
                $matching++;
            }
        }

        $ratio = $matching / count($diffs);
        if ($ratio < $consistencyRatio) {
            continue;
        }

        $direction = $medianDiff > 0 ? 'later' : 'earlier';
        $days = abs($medianDiff);
        $sample = $items[0];

        $alerts[] = [
            'dedupe_key' => 'forecast_rule_date_drift:' . $ruleId,
            'alert_type' => 'forecast_rule_date_drift',
            'severity' => 'warning',
            'title' => 'Rule #' . $ruleId . ' date drift detected',
            'summary' => ($sample['rule_description'] ?: 'Recurring rule')
                . ' is landing about ' . $days . ' day' . ($days === 1 ? '' : 's')
                . ' ' . $direction . ' than scheduled. '
                . $matching . '/' . count($diffs) . ' recent fulfilled instances show the same drift.',
            'evidence_json' => [
                'rule_id' => $ruleId,
                'rule_description' => $sample['rule_description'],
                'account_name' => $sample['account_name'],
                'category_name' => $sample['category_name'],
                'median_day_diff' => $medianDiff,
                'matching_ratio' => $ratio,
                'recent_instances' => $evidenceItems,
            ],
            'recommended_action_json' => wq_build_date_drift_action(
                $ruleId,
                $direction,
                $days,
                $ratio,
                $sample['rule_description'] ?? null
            ),
            'related_account_id' => (int)$sample['from_account_id'],
            'related_category_id' => null,
            'related_predicted_transaction_id' => $ruleId,
        ];
    }

    return $alerts;
}

function watcher_fq_detect_rule_amount_drift(PDO $pdo): array
{
    $historyLimit = (int)wq_config('rule_history_limit', 6);
    $minHistory = (int)wq_config('amount_drift_min_fulfilled', 4);
    $pctThreshold = (float)wq_config('amount_drift_percent_threshold', 0.15);
    $absThreshold = (float)wq_config('amount_drift_abs_threshold', 10.0);
    $clusterRatioThreshold = (float)wq_config('amount_drift_cluster_ratio', 0.75);

    $stmt = $pdo->query("
        SELECT
            pt.id AS rule_id,
            pt.description AS rule_description,
            pt.from_account_id,
            pt.amount AS expected_amount,
            pt.variable,
            a.name AS account_name,
            c.name AS category_name,
            c.type AS category_type,
            pi.id AS predicted_instance_id,
            pi.scheduled_date,
            tx.date AS actual_date,
            tx.amount AS actual_amount
        FROM predicted_transactions pt
        JOIN predicted_instances pi
          ON pi.predicted_transaction_id = pt.id
        JOIN transactions tx
          ON tx.id = pi.fulfilled_by_transaction_id
        LEFT JOIN accounts a
          ON a.id = pt.from_account_id
        LEFT JOIN categories c
          ON c.id = pt.category_id
        WHERE pt.active = 1
          AND COALESCE(pt.variable, 0) = 0
          AND c.type IN ('income', 'expense')
          AND pi.fulfilled = 1
          AND pt.amount IS NOT NULL
        ORDER BY pt.id, pi.scheduled_date DESC, pi.id DESC
    ");

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ruleId = (int)$row['rule_id'];
        if (!isset($grouped[$ruleId])) {
            $grouped[$ruleId] = [];
        }
        if (count($grouped[$ruleId]) < $historyLimit) {
            $grouped[$ruleId][] = $row;
        }
    }

    $alerts = [];

    foreach ($grouped as $ruleId => $items) {
        if (count($items) < $minHistory) {
            continue;
        }

        $expected = (float)$items[0]['expected_amount'];
        if (abs($expected) < 0.005) {
            continue;
        }

        $actuals = [];
        $evidenceItems = [];

        foreach ($items as $item) {
            $actual = (float)$item['actual_amount'];
            $actuals[] = $actual;
            $evidenceItems[] = [
                'predicted_instance_id' => (int)$item['predicted_instance_id'],
                'scheduled_date' => (string)$item['scheduled_date'],
                'actual_date' => (string)$item['actual_date'],
                'actual_amount' => $actual,
            ];
        }

        $medianActual = (float)wq_median($actuals);
        $clusterRatio = wq_ratio_within_tolerance($actuals, $medianActual, 0.10, 5.0);
        if ($clusterRatio < $clusterRatioThreshold) {
            continue;
        }

        $drift = $medianActual - $expected;
        $requiredDelta = max($absThreshold, abs($expected) * $pctThreshold);

        if (abs($drift) < $requiredDelta) {
            continue;
        }

        $sample = $items[0];

        $alerts[] = [
            'dedupe_key' => 'forecast_rule_amount_drift:' . $ruleId,
            'alert_type' => 'forecast_rule_amount_drift',
            'severity' => 'warning',
            'title' => 'Rule #' . $ruleId . ' amount drift detected',
            'summary' => ($sample['rule_description'] ?: 'Recurring rule')
                . ' is configured at £' . number_format($expected, 2)
                . ' but recent fulfilled actuals cluster around £' . number_format($medianActual, 2) . '.',
            'evidence_json' => [
                'rule_id' => $ruleId,
                'rule_description' => $sample['rule_description'],
                'account_name' => $sample['account_name'],
                'category_name' => $sample['category_name'],
                'expected_amount' => $expected,
                'median_actual_amount' => $medianActual,
                'drift_amount' => $drift,
                'cluster_ratio' => $clusterRatio,
                'recent_instances' => $evidenceItems,
            ],
            'recommended_action_json' => wq_build_amount_drift_action(
                $ruleId,
                $expected,
                $medianActual,
                $sample['rule_description'] ?? null
            ),
            'related_account_id' => (int)$sample['from_account_id'],
            'related_category_id' => null,
            'related_predicted_transaction_id' => $ruleId,
        ];
    }

    return $alerts;
}

function wq_detect_cadence(array $dates): ?array
{
    if (count($dates) < 4) {
        return null;
    }

    usort($dates, fn($a, $b) => strcmp($a, $b));

    $gaps = [];
    for ($i = 1; $i < count($dates); $i++) {
        try {
            $prev = new DateTimeImmutable($dates[$i - 1]);
            $curr = new DateTimeImmutable($dates[$i]);
        } catch (Throwable $e) {
            continue;
        }

        $gaps[] = (int)$prev->diff($curr)->format('%a');
    }

    if (count($gaps) < 3) {
        return null;
    }

    $buckets = [
        'weekly' => [6, 8],
        'fortnightly' => [12, 16],
        'monthly' => [24, 35],
    ];

    foreach ($buckets as $label => [$minGap, $maxGap]) {
        $matches = 0;
        foreach ($gaps as $gap) {
            if ($gap >= $minGap && $gap <= $maxGap) {
                $matches++;
            }
        }

        $ratio = $matches / count($gaps);
        if ($ratio >= 0.75) {
            return [
                'label' => $label,
                'median_gap' => wq_median($gaps),
                'match_ratio' => $ratio,
                'gaps' => $gaps,
            ];
        }
    }

    return null;
}

function watcher_fq_detect_missing_recurring_patterns(PDO $pdo): array
{
    $lookbackDays = (int)wq_config('missing_pattern_lookback_days', 180);
    $minOccurrences = (int)wq_config('missing_pattern_min_occurrences', 4);
    $amountConsistencyPct = (float)wq_config('missing_pattern_amount_consistency_pct', 0.20);

    $rules = wq_active_rule_snapshot($pdo);

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.account_id,
            a.name AS account_name,
            t.date,
            t.amount,
            t.category_id,
            c.name AS category_name,
            t.payee_id,
            p.name AS payee_name
        FROM transactions t
        JOIN accounts a
          ON a.id = t.account_id
        JOIN categories c
          ON c.id = t.category_id
        LEFT JOIN payees p
          ON p.id = t.payee_id
        WHERE t.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
          AND c.type IN ('income', 'expense')
          AND a.type IN ('current', 'credit', 'savings')
          AND t.transfer_group_id IS NULL
          AND t.predicted_transaction_id IS NULL
          AND t.payee_id IS NOT NULL
        ORDER BY t.account_id, t.payee_id, t.category_id, t.date DESC, t.id DESC
    ");
    $stmt->execute([$lookbackDays]);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sign = ((float)$row['amount'] >= 0) ? 'pos' : 'neg';
        $key = implode(':', [
            (int)$row['account_id'],
            (int)$row['payee_id'],
            (int)$row['category_id'],
            $sign,
        ]);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $row;
    }

    $alerts = [];

    foreach ($grouped as $key => $items) {
        if (count($items) < $minOccurrences) {
            continue;
        }

        $dates = array_map(fn($i) => (string)$i['date'], $items);
        $cadence = wq_detect_cadence($dates);
        if ($cadence === null) {
            continue;
        }

        $amountsAbs = array_map(fn($i) => abs((float)$i['amount']), $items);
        $medianAmountAbs = (float)wq_median($amountsAbs);
        $amountRatio = wq_ratio_within_tolerance($amountsAbs, $medianAmountAbs, $amountConsistencyPct, 5.0);
        if ($amountRatio < 0.75) {
            continue;
        }

        $sample = $items[0];
        if (wq_has_similar_active_rule(
            $rules,
            (int)$sample['account_id'],
            (int)$sample['category_id'],
            (float)$sample['amount'],
            (string)($sample['payee_name'] ?? '')
        )) {
            continue;
        }

        $severity = count($items) >= 6 ? 'warning' : 'info';

        $alerts[] = [
            'dedupe_key' => 'missing_recurring_pattern:' . $key,
            'alert_type' => 'missing_recurring_pattern',
            'severity' => $severity,
            'title' => 'Likely missing ' . $cadence['label'] . ' recurring pattern',
            'summary' => ($sample['payee_name'] ?: 'Unlabelled payee')
                . ' on ' . $sample['account_name']
                . ' appears ' . $cadence['label']
                . ' with about £' . number_format($medianAmountAbs, 2)
                . ' and ' . count($items) . ' recent occurrences, but no similar active recurring rule was found.',
            'evidence_json' => [
                'account_name' => $sample['account_name'],
                'payee_name' => $sample['payee_name'],
                'category_name' => $sample['category_name'],
                'occurrence_count' => count($items),
                'cadence' => $cadence['label'],
                'median_gap_days' => $cadence['median_gap'],
                'gap_match_ratio' => $cadence['match_ratio'],
                'median_amount_abs' => $medianAmountAbs,
                'amount_consistency_ratio' => $amountRatio,
                'recent_transactions' => array_map(function ($i) {
                    return [
                        'transaction_id' => (int)$i['id'],
                        'date' => (string)$i['date'],
                        'amount' => (float)$i['amount'],
                    ];
                }, array_slice($items, 0, 8)),
            ],
            'recommended_action_json' => wq_build_missing_pattern_action(
                $cadence['label'],
                $medianAmountAbs,
                (string)($sample['payee_name'] ?? ''),
                (string)$sample['account_name'],
                (string)$sample['category_name']
            ),
            'related_account_id' => (int)$sample['account_id'],
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_fq_detect_prediction_miss_accumulation(PDO $pdo): array
{
    $threshold = (int)wq_config('prediction_miss_count_threshold', 3);
    $criticalThreshold = (int)wq_config('prediction_miss_count_critical', 5);

    $stmt = $pdo->prepare("
        SELECT
            pt.id AS rule_id,
            pt.description AS rule_description,
            pt.from_account_id,
            a.name AS account_name,
            COUNT(*) AS open_missed_count,
            MIN(pi.scheduled_date) AS oldest_open_missed,
            MAX(pi.scheduled_date) AS newest_open_missed
        FROM predicted_transactions pt
        JOIN predicted_instances pi
          ON pi.predicted_transaction_id = pt.id
        LEFT JOIN accounts a
          ON a.id = pt.from_account_id
        WHERE pt.active = 1
          AND COALESCE(pi.fulfilled, 0) = 0
          AND COALESCE(pi.resolution_status, 'open') = 'open'
          AND pi.scheduled_date < CURDATE()
        GROUP BY pt.id, pt.description, pt.from_account_id, a.name
        HAVING COUNT(*) >= ?
        ORDER BY COUNT(*) DESC, MIN(pi.scheduled_date) ASC
    ");
    $stmt->execute([$threshold]);

    $alerts = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $count = (int)$row['open_missed_count'];
        $severity = $count >= $criticalThreshold ? 'critical' : 'warning';

        $alerts[] = [
            'dedupe_key' => 'prediction_miss_accumulation:' . (int)$row['rule_id'],
            'alert_type' => 'prediction_miss_accumulation',
            'severity' => $severity,
            'title' => 'Rule #' . (int)$row['rule_id'] . ' has ' . $count . ' open missed instances',
            'summary' => ($row['rule_description'] ?: 'Recurring rule')
                . ' has accumulated ' . $count . ' missed open instances. '
                . 'Oldest open miss: ' . $row['oldest_open_missed']
                . '; newest: ' . $row['newest_open_missed'] . '.',
            'evidence_json' => [
                'rule_id' => (int)$row['rule_id'],
                'rule_description' => $row['rule_description'],
                'account_name' => $row['account_name'],
                'open_missed_count' => $count,
                'oldest_open_missed' => $row['oldest_open_missed'],
                'newest_open_missed' => $row['newest_open_missed'],
            ],
            'recommended_action_json' => wq_build_prediction_miss_action(
                (int)$row['rule_id'],
                $count,
                (string)$row['oldest_open_missed'],
                $row['rule_description'] ?? null
            ),
            'related_account_id' => (int)$row['from_account_id'],
            'related_category_id' => null,
            'related_predicted_transaction_id' => (int)$row['rule_id'],
        ];
    }

    return $alerts;
}

function watcher_fq_detect_review_backlog(PDO $pdo): array
{
    $minCount = (int)wq_config('review_backlog_min_count', 5);
    $ageDays = (int)wq_config('review_backlog_age_days', 3);
    $criticalCount = (int)wq_config('review_backlog_critical_count', 15);
    $criticalAgeDays = (int)wq_config('review_backlog_critical_age_days', 7);

    $stmt = $pdo->query("
        SELECT
            s.account_id,
            a.name AS account_name,
            COUNT(*) AS backlog_count,
            MIN(s.created_at) AS oldest_created_at
        FROM staging_transactions s
        JOIN accounts a
          ON a.id = s.account_id
        WHERE s.status IN ('new', 'potential_duplicate', 'fulfills_prediction')
        GROUP BY s.account_id, a.name
    ");

    $alerts = [];
    $today = new DateTimeImmutable('today');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $count = (int)$row['backlog_count'];
        if ($count < $minCount || empty($row['oldest_created_at'])) {
            continue;
        }

        try {
            $oldest = new DateTimeImmutable((string)$row['oldest_created_at']);
        } catch (Throwable $e) {
            continue;
        }

        $age = (int)$oldest->diff($today)->format('%a');
        if ($age < $ageDays) {
            continue;
        }

        $severity = ($count >= $criticalCount || $age >= $criticalAgeDays) ? 'critical' : 'warning';

        $alerts[] = [
            'dedupe_key' => 'review_backlog:' . (int)$row['account_id'],
            'alert_type' => 'review_backlog',
            'severity' => $severity,
            'title' => $row['account_name'] . ' review backlog needs attention',
            'summary' => $count . ' staging items are still unresolved for ' . $row['account_name']
                . '. Oldest unresolved item entered staging ' . $age . ' day'
                . ($age === 1 ? '' : 's') . ' ago.',
            'evidence_json' => [
                'account_name' => $row['account_name'],
                'backlog_count' => $count,
                'oldest_created_at' => $row['oldest_created_at'],
                'age_days' => $age,
            ],
            'recommended_action_json' => wq_build_review_backlog_action(
                (string)$row['account_name'],
                $count,
                $age
            ),
            'related_account_id' => (int)$row['account_id'],
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_detect_forecast_quality_alerts(PDO $pdo): array
{
    return array_merge(
        watcher_fq_detect_rule_date_drift($pdo),
        watcher_fq_detect_rule_amount_drift($pdo),
        watcher_fq_detect_missing_recurring_patterns($pdo),
        watcher_fq_detect_prediction_miss_accumulation($pdo),
        watcher_fq_detect_review_backlog($pdo)
    );
}
