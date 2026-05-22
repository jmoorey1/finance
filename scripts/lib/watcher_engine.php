<?php
require_once __DIR__ . '/../funding_health_engine.php';
require_once __DIR__ . '/../get_account_import_status.php';
require_once __DIR__ . '/watcher_forecast_quality.php';

function watcher_is_enabled(): bool
{
    return (bool)app_config('watcher.enabled', true);
}

function watcher_managed_alert_types(): array
{
    return [
        'current_account_shortfall',
        'funding_gap',
        'stale_import',
        'forecast_rule_date_drift',
        'forecast_rule_amount_drift',
        'missing_recurring_pattern',
        'prediction_miss_accumulation',
        'review_backlog',
        // legacy type kept here so old open alerts resolve on the next sync
        'reserve_breach',
    ];
}

function watcher_encode_json($value): ?string
{
    if ($value === null) {
        return null;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function watcher_active_accounts(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, name, type
        FROM accounts
        WHERE active = 1
        ORDER BY id
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    $byName = [];

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $name = (string)$row['name'];

        $byId[$id] = $row;
        $byName[$name] = $row;
    }

    return [
        'by_id' => $byId,
        'by_name' => $byName,
    ];
}

function watcher_detect_funding_alerts(PDO $pdo): array
{
    $windowDays = (int)app_config('watcher.shortfall_window_days', 31);
    $funding = fh_build_primary_funding_health($pdo, $windowDays);
    $accounts = watcher_active_accounts($pdo);
    $today = new DateTimeImmutable('today');

    $alerts = [];

    if (($funding['status'] ?? '') === 'no_savings') {
        $alerts[] = [
            'dedupe_key' => 'funding_gap:no_savings',
            'alert_type' => 'funding_gap',
            'severity' => 'critical',
            'title' => 'No active savings account configured for funding health',
            'summary' => 'Funding Health cannot calculate whether current-account support transfers are coverable until an active savings account exists.',
            'evidence_json' => [
                'status' => $funding['status'] ?? null,
                'window_days' => $windowDays,
            ],
            'recommended_action_json' => [
                'label' => 'Open Funding Health',
                'url' => '/finance/public/funding_health.php?days=' . $windowDays,
            ],
            'related_account_id' => null,
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];

        return $alerts;
    }

    foreach (($funding['issues'] ?? []) as $issue) {
        $accountName = (string)($issue['account_name'] ?? '');
        $accountMeta = $accounts['by_name'][$accountName] ?? null;
        $accountId = $accountMeta ? (int)$accountMeta['id'] : null;

        $startDayStr = (string)($issue['start_day'] ?? $today->format('Y-m-d'));
        try {
            $startDay = new DateTimeImmutable($startDayStr);
        } catch (Throwable $e) {
            $startDay = $today;
        }

        $daysUntilStart = (int)$today->diff($startDay)->format('%r%a');
        $topUp = (float)($issue['top_up'] ?? 0.0);
        $gap = (float)($issue['funding_gap'] ?? 0.0);
        $fundable = (float)($issue['fundable_from_savings'] ?? 0.0);
        $minBalance = (float)($issue['min_balance'] ?? 0.0);
        $minDay = (string)($issue['min_day'] ?? '');
        $savingsName = (string)($funding['reserve_account_name'] ?? 'SAVINGS');

        $severity = ($gap > 0.005 || $daysUntilStart <= 3) ? 'critical' : 'warning';

        $title = ($gap > 0.005)
            ? $accountName . ' needs funding by ' . $startDayStr . ' and savings still leaves a gap'
            : 'Move £' . number_format($topUp, 2) . ' to ' . $accountName . ' by ' . $startDayStr;

        $summary = 'Projected to reach £' . number_format($minBalance, 2) . ' on ' . $minDay
            . '. Support needed: £' . number_format($topUp, 2)
            . '; fundable from ' . $savingsName . ': £' . number_format($fundable, 2)
            . '; remaining gap: £' . number_format($gap, 2) . '.';

        $alerts[] = [
            'dedupe_key' => 'current_account_shortfall:' . ($accountId ?? $accountName),
            'alert_type' => 'current_account_shortfall',
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'evidence_json' => [
                'account_name' => $accountName,
                'start_day' => $startDayStr,
                'min_day' => $minDay,
                'top_up' => $topUp,
                'min_balance' => $minBalance,
                'fundable_from_savings' => $fundable,
                'funding_gap' => $gap,
                'savings_balance_before_support' => $issue['savings_balance_before_support'] ?? null,
                'savings_balance_after_support' => $issue['savings_balance_after_support'] ?? null,
                'reserve_account_name' => $savingsName,
            ],
            'recommended_action_json' => [
                'label' => 'Open Funding Health',
                'url' => '/finance/public/funding_health.php?days=' . $windowDays,
            ],
            'related_account_id' => $accountId,
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];
    }

    if (((float)($funding['total_funding_gap'] ?? 0.0)) > 0.005) {
        $alerts[] = [
            'dedupe_key' => 'funding_gap:primary',
            'alert_type' => 'funding_gap',
            'severity' => 'critical',
            'title' => 'Funding gap of £' . number_format((float)$funding['total_funding_gap'], 2) . ' inside the next ' . $windowDays . ' days',
            'summary' => (string)($funding['summary'] ?? 'Known current-account support needs exceed projected savings cash inside the action window.'),
            'evidence_json' => [
                'window_days' => $windowDays,
                'headline' => $funding['headline'] ?? null,
                'summary' => $funding['summary'] ?? null,
                'current_balance' => $funding['current_balance'] ?? null,
                'total_required_support' => $funding['total_required_support'] ?? null,
                'total_funding_gap' => $funding['total_funding_gap'] ?? null,
                'lowest_projected_balance' => $funding['lowest_projected_balance'] ?? null,
                'lowest_projected_balance_date' => $funding['lowest_projected_balance_date'] ?? null,
                'reserve_account_name' => $funding['reserve_account_name'] ?? null,
            ],
            'recommended_action_json' => [
                'label' => 'Open Funding Health',
                'url' => '/finance/public/funding_health.php?days=' . $windowDays,
            ],
            'related_account_id' => $funding['reserve_account_id'] ?? null,
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_detect_stale_import_alerts(PDO $pdo): array
{
    $accounts = watcher_active_accounts($pdo);
    $importStatus = get_account_import_status($pdo);

    $alerts = [];

    foreach ($importStatus as $accountId => $meta) {
        if (($meta['freshness_status'] ?? '') !== 'stale') {
            continue;
        }

        $account = $accounts['by_id'][(int)$accountId] ?? null;
        if (!$account) {
            continue;
        }

        $accountName = (string)$account['name'];
        $accountType = (string)$account['type'];
        $days = $meta['days_since_last_successful_import'];
        $threshold = $meta['stale_after_days'];

        $severity = in_array($accountType, ['current', 'credit'], true) ? 'critical' : 'warning';

        $alerts[] = [
            'dedupe_key' => 'stale_import:' . (int)$accountId,
            'alert_type' => 'stale_import',
            'severity' => $severity,
            'title' => $accountName . ' import data is stale',
            'summary' => 'The last successful import was '
                . ($days === null ? 'never recorded' : ($days . ' day' . ($days === 1 ? '' : 's') . ' ago'))
                . '. This account is considered stale after ' . (int)$threshold . ' days.',
            'evidence_json' => [
                'account_name' => $accountName,
                'account_type' => $accountType,
                'days_since_last_successful_import' => $days,
                'stale_after_days' => $threshold,
                'last_successful_import_at' => $meta['last_successful_import_at'] ?? null,
            ],
            'recommended_action_json' => [
                'label' => 'Upload fresh account data',
                'url' => '/finance/public/upload.php',
            ],
            'related_account_id' => (int)$accountId,
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];
    }

    return $alerts;
}

function watcher_detect_all(PDO $pdo): array
{
    if (!watcher_is_enabled()) {
        return [];
    }

    return array_merge(
        watcher_detect_funding_alerts($pdo),
        watcher_detect_forecast_quality_alerts($pdo),
        watcher_detect_stale_import_alerts($pdo)
    );
}

function watcher_sync_alerts(PDO $pdo, array $alerts): array
{
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $stats = [
        'detected' => count($alerts),
        'created' => 0,
        'updated' => 0,
        'reopened' => 0,
        'resolved' => 0,
    ];

    $seenDedupeKeys = [];

    $selectStmt = $pdo->prepare("
        SELECT id, status
        FROM watcher_alerts
        WHERE dedupe_key = ?
        LIMIT 1
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO watcher_alerts (
            dedupe_key,
            alert_type,
            severity,
            status,
            title,
            summary,
            evidence_json,
            recommended_action_json,
            related_account_id,
            related_category_id,
            related_predicted_transaction_id,
            first_detected_at,
            last_detected_at
        ) VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $updateStmt = $pdo->prepare("
        UPDATE watcher_alerts
        SET alert_type = ?,
            severity = ?,
            status = ?,
            title = ?,
            summary = ?,
            evidence_json = ?,
            recommended_action_json = ?,
            related_account_id = ?,
            related_category_id = ?,
            related_predicted_transaction_id = ?,
            last_detected_at = ?,
            resolved_at = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    foreach ($alerts as $alert) {
        $dedupeKey = (string)$alert['dedupe_key'];
        $seenDedupeKeys[] = $dedupeKey;

        $selectStmt->execute([$dedupeKey]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        $evidenceJson = watcher_encode_json($alert['evidence_json'] ?? null);
        $actionJson = watcher_encode_json($alert['recommended_action_json'] ?? null);

        if (!$existing) {
            $insertStmt->execute([
                $dedupeKey,
                (string)$alert['alert_type'],
                (string)$alert['severity'],
                (string)$alert['title'],
                (string)$alert['summary'],
                $evidenceJson,
                $actionJson,
                $alert['related_account_id'] ?? null,
                $alert['related_category_id'] ?? null,
                $alert['related_predicted_transaction_id'] ?? null,
                $now,
                $now,
            ]);
            $stats['created']++;
            continue;
        }

        $existingStatus = (string)$existing['status'];
        $newStatus = ($existingStatus === 'resolved') ? 'open' : $existingStatus;
        $resolvedAt = ($newStatus === 'resolved') ? $now : null;

        $updateStmt->execute([
            (string)$alert['alert_type'],
            (string)$alert['severity'],
            $newStatus,
            (string)$alert['title'],
            (string)$alert['summary'],
            $evidenceJson,
            $actionJson,
            $alert['related_account_id'] ?? null,
            $alert['related_category_id'] ?? null,
            $alert['related_predicted_transaction_id'] ?? null,
            $now,
            $resolvedAt,
            (int)$existing['id'],
        ]);

        if ($existingStatus === 'resolved' && $newStatus === 'open') {
            $stats['reopened']++;
        } else {
            $stats['updated']++;
        }
    }

    $managedTypes = watcher_managed_alert_types();
    if (!empty($managedTypes)) {
        $typePlaceholders = implode(',', array_fill(0, count($managedTypes), '?'));

        if (!empty($seenDedupeKeys)) {
            $dedupePlaceholders = implode(',', array_fill(0, count($seenDedupeKeys), '?'));

            $sql = "
                UPDATE watcher_alerts
                SET status = 'resolved',
                    resolved_at = NOW(),
                    updated_at = NOW()
                WHERE status IN ('open', 'acknowledged', 'snoozed')
                  AND alert_type IN ($typePlaceholders)
                  AND dedupe_key NOT IN ($dedupePlaceholders)
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($managedTypes, $seenDedupeKeys));
            $stats['resolved'] = $stmt->rowCount();
        } else {
            $sql = "
                UPDATE watcher_alerts
                SET status = 'resolved',
                    resolved_at = NOW(),
                    updated_at = NOW()
                WHERE status IN ('open', 'acknowledged', 'snoozed')
                  AND alert_type IN ($typePlaceholders)
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($managedTypes);
            $stats['resolved'] = $stmt->rowCount();
        }
    }

    return $stats;
}

function watcher_run_analysis(PDO $pdo): array
{
    $alerts = watcher_detect_all($pdo);
    $stats = watcher_sync_alerts($pdo, $alerts);

    $byType = [];
    foreach ($alerts as $alert) {
        $type = (string)$alert['alert_type'];
        $byType[$type] = ($byType[$type] ?? 0) + 1;
    }

    $stats['alerts'] = $alerts;
    $stats['by_type'] = $byType;
    return $stats;
}
