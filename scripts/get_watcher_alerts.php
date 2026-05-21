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
