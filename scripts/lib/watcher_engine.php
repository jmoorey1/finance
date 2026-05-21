<?php
require_once __DIR__ . '/../forecast_utils.php';
require_once __DIR__ . '/../get_account_import_status.php';

function watcher_is_enabled(): bool
{
    return (bool)app_config('watcher.enabled', true);
}

function watcher_managed_alert_types(): array
{
    return [
        'current_account_shortfall',
        'reserve_breach',
        'stale_import',
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

function watcher_detect_shortfall_alerts(PDO $pdo): array
{
    $forecastDays = (int)app_config('watcher.forecast_days', 90);
    $shortfallWindow = (int)app_config('watcher.shortfall_window_days', 31);

    $issues = get_forecast_shortfalls($pdo, $forecastDays, $shortfallWindow);
    $accounts = watcher_active_accounts($pdo);
    $today = new DateTimeImmutable('today');

    $alerts = [];

    foreach ($issues as $issue) {
        $accountName = (string)($issue['account_name'] ?? '');
        $accountMeta = $accounts['by_name'][$accountName] ?? null;
        $accountId = $accountMeta ? (int)$accountMeta['id'] : null;

        $startDay = new DateTimeImmutable((string)$issue['start_day']);
        $daysUntilStart = (int)$today->diff($startDay)->format('%r%a');

        $topUp = (float)($issue['top_up'] ?? 0);
        $minBalance = (float)($issue['min_balance'] ?? 0);
        $breachAmount = (float)($issue['breach_amount'] ?? 0);
        $safeFromReserve = (float)($issue['safe_from_reserve'] ?? 0);

        $severity = ($daysUntilStart <= 3) ? 'critical' : 'warning';

        $alerts[] = [
            'dedupe_key' => 'current_account_shortfall:' . ($accountId ?? $accountName),
            'alert_type' => 'current_account_shortfall',
            'severity' => $severity,
            'title' => $accountName . ' projected shortfall within ' . $shortfallWindow . ' days',
            'summary' => 'A transfer of £' . number_format($topUp, 2) . ' is currently needed by ' . $issue['start_day']
                . '. The account is projected to reach £' . number_format($minBalance, 2)
                . ' on ' . $issue['min_day'] . '.',
            'evidence_json' => [
                'account_name' => $accountName,
                'start_day' => $issue['start_day'],
                'min_day' => $issue['min_day'],
                'top_up' => $topUp,
                'min_balance' => $minBalance,
                'safe_from_reserve' => $safeFromReserve,
                'breach_amount' => $breachAmount,
            ],
            'recommended_action_json' => [
                'label' => 'Review required transfers',
                'url' => '/finance/public/index.php',
            ],
            'related_account_id' => $accountId,
            'related_category_id' => null,
            'related_predicted_transaction_id' => null,
        ];

        if ($breachAmount > 0.005) {
            $alerts[] = [
                'dedupe_key' => 'reserve_breach:' . ($accountId ?? $accountName),
                'alert_type' => 'reserve_breach',
                'severity' => 'critical',
                'title' => $accountName . ' shortfall would breach the solvency reserve',
                'summary' => 'The projected transfer requirement is £' . number_format($topUp, 2)
                    . ', but only £' . number_format($safeFromReserve, 2)
                    . ' can currently be taken safely from reserve. The remaining gap is £'
                    . number_format($breachAmount, 2) . '.',
                'evidence_json' => [
                    'account_name' => $accountName,
                    'start_day' => $issue['start_day'],
                    'min_day' => $issue['min_day'],
                    'top_up' => $topUp,
                    'safe_from_reserve' => $safeFromReserve,
                    'breach_amount' => $breachAmount,
                    'reserve_account_name' => $issue['reserve_account_name'] ?? null,
                ],
                'recommended_action_json' => [
                    'label' => 'Open dashboard',
                    'url' => '/finance/public/index.php',
                ],
                'related_account_id' => $accountId,
                'related_category_id' => null,
                'related_predicted_transaction_id' => null,
            ];
        }
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
        watcher_detect_shortfall_alerts($pdo),
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
    $stats['alerts'] = $alerts;
    return $stats;
}
