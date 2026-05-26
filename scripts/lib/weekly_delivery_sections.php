<?php
require_once __DIR__ . '/../funding_health_engine.php';
require_once __DIR__ . '/../get_watcher_alerts.php';

function wds_config(string $key, $default = null)
{
    return app_config('weekly_email.delivery.' . $key, $default);
}

function wds_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

function wds_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function wds_load_funding_snapshot(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $windowDays = max(1, (int)wds_config('funding_window_days', 31));
    $issueLimit = max(1, (int)wds_config('funding_issue_limit', 3));

    $funding = fh_build_primary_funding_health($pdo, $windowDays);

    return [
        'window_days' => $windowDays,
        'status' => (string)($funding['status'] ?? 'ok'),
        'headline' => (string)($funding['headline'] ?? ''),
        'summary' => (string)($funding['summary'] ?? ''),
        'reserve_account_name' => (string)($funding['reserve_account_name'] ?? 'SAVINGS'),
        'current_balance' => (float)($funding['current_balance'] ?? 0.0),
        'projected_balance_after_today_events' => (float)($funding['projected_balance_after_today_events'] ?? ($funding['current_balance'] ?? 0.0)),
        'total_required_support' => (float)($funding['total_required_support'] ?? 0.0),
        'total_funding_gap' => (float)($funding['total_funding_gap'] ?? 0.0),
        'lowest_projected_balance' => (float)($funding['lowest_projected_balance'] ?? 0.0),
        'lowest_projected_balance_date' => (string)($funding['lowest_projected_balance_date'] ?? ''),
        'issues' => array_slice($funding['issues'] ?? [], 0, $issueLimit),
    ];
}

function wds_load_watcher_snapshot(PDO $pdo, ?DateTimeInterface $today = null): array
{
    $openLimit = max(1, (int)wds_config('watcher_open_alert_limit', 5));
    $recentDays = max(1, (int)wds_config('watcher_recent_days', 7));
    $recentLimit = max(1, (int)wds_config('watcher_recent_limit', 3));

    $openAlerts = get_open_watcher_alerts_for_index($pdo, $openLimit);
    $cutoff = (new DateTimeImmutable('now'))->modify('-' . $recentDays . ' days')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'open' AND severity = 'critical' THEN 1 ELSE 0 END), 0) AS open_critical,
            COALESCE(SUM(CASE WHEN status = 'open' AND severity = 'warning' THEN 1 ELSE 0 END), 0) AS open_warning,
            COALESCE(SUM(CASE WHEN status = 'open' AND severity NOT IN ('critical', 'warning') THEN 1 ELSE 0 END), 0) AS open_info,
            COALESCE(SUM(CASE WHEN first_detected_at >= ? THEN 1 ELSE 0 END), 0) AS detected_recently,
            COALESCE(SUM(CASE WHEN resolved_at IS NOT NULL AND resolved_at >= ? THEN 1 ELSE 0 END), 0) AS resolved_recently
        FROM watcher_alerts
    ");
    $stmt->execute([$cutoff, $cutoff]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT
            title,
            summary,
            severity,
            status,
            first_detected_at,
            resolved_at
        FROM watcher_alerts
        WHERE first_detected_at >= ?
           OR (resolved_at IS NOT NULL AND resolved_at >= ?)
        ORDER BY
            GREATEST(
                COALESCE(first_detected_at, '1000-01-01 00:00:00'),
                COALESCE(resolved_at, '1000-01-01 00:00:00')
            ) DESC,
            CASE severity
                WHEN 'critical' THEN 0
                WHEN 'warning' THEN 1
                ELSE 2
            END,
            id DESC
        LIMIT {$recentLimit}
    ");
    $stmt->execute([$cutoff, $cutoff]);
    $recentChanges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'recent_days' => $recentDays,
        'open_alerts' => $openAlerts,
        'open_critical' => (int)($counts['open_critical'] ?? 0),
        'open_warning' => (int)($counts['open_warning'] ?? 0),
        'open_info' => (int)($counts['open_info'] ?? 0),
        'detected_recently' => (int)($counts['detected_recently'] ?? 0),
        'resolved_recently' => (int)($counts['resolved_recently'] ?? 0),
        'recent_changes' => $recentChanges,
    ];
}

function wds_severity_badge_text(string $severity): string
{
    return strtoupper($severity);
}

function wds_render_funding_html(array $funding): string
{
    $html = '<h3 style="font-family:sans-serif;">Funding Health Snapshot</h3>';
    $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%;">';
    $html .= '<tr style="background:#333; color:#fff;"><th align="left">Funding snapshot (next ' . (int)$funding['window_days'] . ' days)</th></tr>';
    $html .= '<tr><td style="border:1px solid #ccc;"><strong>' . wds_html((string)$funding['headline']) . '</strong><br>' . wds_html((string)$funding['summary']) . '</td></tr>';
    $html .= '<tr><td style="border:1px solid #ccc;">'
        . wds_html((string)$funding['reserve_account_name']) . ' cleared balance as of last night: <strong>' . wds_money((float)$funding['current_balance']) . '</strong><br>'
        . 'Projected after today\'s uncleared items: <strong>' . wds_money((float)$funding['projected_balance_after_today_events']) . '</strong><br>'
        . 'Required support: <strong>' . wds_money((float)$funding['total_required_support']) . '</strong> | '
        . 'Actual funding gap: <strong>' . wds_money((float)$funding['total_funding_gap']) . '</strong><br>'
        . 'Lowest projected savings balance: <strong>' . wds_money((float)$funding['lowest_projected_balance']) . '</strong> on '
        . wds_html((string)$funding['lowest_projected_balance_date'])
        . '</td></tr>';

    if (!empty($funding['issues'])) {
        $html .= '<tr><td style="border:1px solid #ccc;"><strong>Actionable funding moves</strong><ul style="margin:8px 0 0 18px; padding:0;">';
        foreach ($funding['issues'] as $issue) {
            $html .= '<li>Move <strong>' . wds_money((float)($issue['top_up'] ?? 0.0)) . '</strong> to '
                . wds_html((string)($issue['account_name'] ?? 'account'))
                . ' by ' . wds_html((string)($issue['start_day'] ?? ''))
                . ' (gap: ' . wds_money((float)($issue['funding_gap'] ?? 0.0)) . ')</li>';
        }
        $html .= '</ul></td></tr>';
    }

    $html .= '</table><br>';
    return $html;
}

function wds_render_watcher_html(array $watcher): string
{
    $html = '<h3 style="font-family:sans-serif;">Watcher Snapshot</h3>';
    $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%;">';
    $html .= '<tr style="background:#333; color:#fff;"><th align="left">Open alerts</th></tr>';
    $html .= '<tr><td style="border:1px solid #ccc;">'
        . 'Open critical: <strong>' . (int)$watcher['open_critical'] . '</strong> | '
        . 'Open warning: <strong>' . (int)$watcher['open_warning'] . '</strong> | '
        . 'Open info: <strong>' . (int)$watcher['open_info'] . '</strong><br>'
        . 'Detected in last ' . (int)$watcher['recent_days'] . ' days: <strong>' . (int)$watcher['detected_recently'] . '</strong> | '
        . 'Resolved in last ' . (int)$watcher['recent_days'] . ' days: <strong>' . (int)$watcher['resolved_recently'] . '</strong>'
        . '</td></tr>';

    if (!empty($watcher['open_alerts'])) {
        $html .= '<tr><td style="border:1px solid #ccc;"><strong>Top open alerts</strong><ul style="margin:8px 0 0 18px; padding:0;">';
        foreach ($watcher['open_alerts'] as $alert) {
            $html .= '<li><strong>[' . wds_html(wds_severity_badge_text((string)$alert['severity'])) . ']</strong> '
                . wds_html((string)$alert['title'])
                . ' — ' . wds_html((string)$alert['summary'])
                . '</li>';
        }
        $html .= '</ul></td></tr>';
    } else {
        $html .= '<tr><td style="border:1px solid #ccc;">No open watcher alerts.</td></tr>';
    }

    if (!empty($watcher['recent_changes'])) {
        $html .= '<tr><td style="border:1px solid #ccc;"><strong>What changed since last week?</strong><ul style="margin:8px 0 0 18px; padding:0;">';
        foreach ($watcher['recent_changes'] as $change) {
            $verb = !empty($change['resolved_at']) ? 'Resolved' : 'Detected';
            $html .= '<li>' . wds_html($verb) . ': <strong>[' . wds_html(wds_severity_badge_text((string)$change['severity'])) . ']</strong> '
                . wds_html((string)$change['title']) . '</li>';
        }
        $html .= '</ul></td></tr>';
    }

    $html .= '</table><br>';
    return $html;
}

function wds_render_funding_text(array $funding): string
{
    $lines = [];
    $lines[] = 'Funding Health Snapshot';
    $lines[] = '-----------------------';
    $lines[] = (string)$funding['headline'];
    $lines[] = (string)$funding['summary'];
    $lines[] = (string)$funding['reserve_account_name'] . ' cleared balance as of last night: ' . wds_money((float)$funding['current_balance']);
    $lines[] = 'Projected after today\'s uncleared items: ' . wds_money((float)$funding['projected_balance_after_today_events']);
    $lines[] = 'Required support: ' . wds_money((float)$funding['total_required_support']) . ' | Actual funding gap: ' . wds_money((float)$funding['total_funding_gap']);
    $lines[] = 'Lowest projected savings balance: ' . wds_money((float)$funding['lowest_projected_balance']) . ' on ' . (string)$funding['lowest_projected_balance_date'];

    if (!empty($funding['issues'])) {
        $lines[] = 'Actionable funding moves:';
        foreach ($funding['issues'] as $issue) {
            $lines[] = '- Move ' . wds_money((float)($issue['top_up'] ?? 0.0))
                . ' to ' . (string)($issue['account_name'] ?? 'account')
                . ' by ' . (string)($issue['start_day'] ?? '')
                . ' (gap: ' . wds_money((float)($issue['funding_gap'] ?? 0.0)) . ')';
        }
    }

    $lines[] = '';
    return implode(PHP_EOL, $lines);
}

function wds_render_watcher_text(array $watcher): string
{
    $lines = [];
    $lines[] = 'Watcher Snapshot';
    $lines[] = '----------------';
    $lines[] = 'Open critical: ' . (int)$watcher['open_critical']
        . ' | Open warning: ' . (int)$watcher['open_warning']
        . ' | Open info: ' . (int)$watcher['open_info'];
    $lines[] = 'Detected in last ' . (int)$watcher['recent_days'] . ' days: ' . (int)$watcher['detected_recently']
        . ' | Resolved in last ' . (int)$watcher['recent_days'] . ' days: ' . (int)$watcher['resolved_recently'];

    if (!empty($watcher['open_alerts'])) {
        $lines[] = 'Top open alerts:';
        foreach ($watcher['open_alerts'] as $alert) {
            $lines[] = '- [' . wds_severity_badge_text((string)$alert['severity']) . '] '
                . (string)$alert['title']
                . ' — ' . (string)$alert['summary'];
        }
    } else {
        $lines[] = 'No open watcher alerts.';
    }

    if (!empty($watcher['recent_changes'])) {
        $lines[] = 'What changed since last week?';
        foreach ($watcher['recent_changes'] as $change) {
            $verb = !empty($change['resolved_at']) ? 'Resolved' : 'Detected';
            $lines[] = '- ' . $verb . ': [' . wds_severity_badge_text((string)$change['severity']) . '] ' . (string)$change['title'];
        }
    }

    $lines[] = '';
    return implode(PHP_EOL, $lines);
}
