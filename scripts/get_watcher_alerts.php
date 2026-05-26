<?php

function get_watcher_alerts(PDO $pdo, string $status = 'open', int $limit = 50): array
{
    $allowedStatuses = ['open', 'acknowledged', 'dismissed', 'snoozed', 'resolved', 'all'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'open';
    }

    $limit = max(1, min(200, $limit));

    $sql = "
        SELECT
            wa.*,
            a.name AS account_name,
            c.name AS category_name
        FROM watcher_alerts wa
        LEFT JOIN accounts a ON a.id = wa.related_account_id
        LEFT JOIN categories c ON c.id = wa.related_category_id
    ";

    $params = [];
    if ($status !== 'all') {
        $sql .= " WHERE wa.status = ? ";
        $params[] = $status;
    }

    $sql .= "
        ORDER BY
            CASE wa.status
                WHEN 'open' THEN 0
                WHEN 'acknowledged' THEN 1
                WHEN 'snoozed' THEN 2
                WHEN 'dismissed' THEN 3
                ELSE 4
            END,
            CASE wa.severity
                WHEN 'critical' THEN 0
                WHEN 'warning' THEN 1
                ELSE 2
            END,
            wa.last_detected_at DESC,
            wa.id DESC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_open_watcher_alerts(PDO $pdo, int $limit = 10): array
{
    return get_watcher_alerts($pdo, 'open', $limit);
}

function watcher_alert_severity_rank(string $severity): int
{
    return match ($severity) {
        'critical' => 0,
        'warning' => 1,
        'info' => 2,
        default => 3,
    };
}

function watcher_alert_type_rank(string $type): int
{
    return match ($type) {
        'funding_gap' => 0,
        'current_account_shortfall' => 1,
        'budget_timing_mismatch' => 2,
        'budget_unrealistic' => 3,
        'budget_burn_risk' => 4,
        'review_backlog' => 5,
        'prediction_miss_accumulation' => 6,
        'forecast_rule_amount_drift' => 7,
        'forecast_rule_date_drift' => 8,
        'missing_recurring_pattern' => 9,
        'stale_import' => 10,
        default => 50,
    };
}

function watcher_alert_group_key(array $alert): string
{
    $categoryId = isset($alert['related_category_id']) ? (int)$alert['related_category_id'] : 0;
    if ($categoryId > 0) {
        return 'category:' . $categoryId;
    }

    $accountId = isset($alert['related_account_id']) ? (int)$alert['related_account_id'] : 0;
    if ($accountId > 0) {
        return 'account:' . $accountId;
    }

    $dedupeKey = trim((string)($alert['dedupe_key'] ?? ''));
    if ($dedupeKey !== '') {
        return 'dedupe:' . $dedupeKey;
    }

    return 'row:' . (string)($alert['id'] ?? uniqid('alert_', true));
}

function watcher_alert_compare(array $a, array $b): int
{
    $sevA = watcher_alert_severity_rank((string)($a['severity'] ?? ''));
    $sevB = watcher_alert_severity_rank((string)($b['severity'] ?? ''));
    if ($sevA !== $sevB) {
        return $sevA <=> $sevB;
    }

    $typeA = watcher_alert_type_rank((string)($a['alert_type'] ?? ''));
    $typeB = watcher_alert_type_rank((string)($b['alert_type'] ?? ''));
    if ($typeA !== $typeB) {
        return $typeA <=> $typeB;
    }

    $detA = (string)($a['last_detected_at'] ?? '');
    $detB = (string)($b['last_detected_at'] ?? '');
    if ($detA !== $detB) {
        return strcmp($detB, $detA);
    }

    $idA = (int)($a['id'] ?? 0);
    $idB = (int)($b['id'] ?? 0);
    return $idB <=> $idA;
}

function watcher_dedupe_for_dashboard(array $alerts): array
{
    $bestByGroup = [];

    foreach ($alerts as $alert) {
        $key = watcher_alert_group_key($alert);

        if (!isset($bestByGroup[$key])) {
            $bestByGroup[$key] = $alert;
            continue;
        }

        if (watcher_alert_compare($alert, $bestByGroup[$key]) < 0) {
            $bestByGroup[$key] = $alert;
        }
    }

    $deduped = array_values($bestByGroup);
    usort($deduped, 'watcher_alert_compare');

    return $deduped;
}

function get_open_watcher_alerts_for_index(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));

    // Pull a wider pool first so dedupe does not accidentally starve the dashboard.
    $raw = get_watcher_alerts($pdo, 'open', max(50, min(200, $limit * 10)));
    $deduped = watcher_dedupe_for_dashboard($raw);

    return array_slice($deduped, 0, $limit);
}
