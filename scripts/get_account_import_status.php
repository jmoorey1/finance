<?php

function default_import_stale_after_days(string $accountType): int
{
    return match ($accountType) {
        'current', 'credit' => 21,
        'savings' => 45,
        default => 90,
    };
}

function get_account_import_status(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            a.id AS account_id,
            a.type AS account_type,
            latest.last_successful_import_at
        FROM accounts a
        LEFT JOIN (
            SELECT
                ira.account_id,
                MAX(ir.finished_at) AS last_successful_import_at
            FROM import_run_accounts ira
            JOIN import_runs ir ON ir.id = ira.import_run_id
            WHERE ir.status = 'success'
            GROUP BY ira.account_id
        ) latest ON latest.account_id = a.id
        WHERE a.active = 1
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $now = new DateTimeImmutable('now');

    $out = [];
    foreach ($rows as $row) {
        $accountId = (int)$row['account_id'];
        $accountType = (string)$row['account_type'];
        $threshold = default_import_stale_after_days($accountType);
        $lastImport = $row['last_successful_import_at'];

        if ($lastImport === null) {
            $out[$accountId] = [
                'last_successful_import_at' => null,
                'days_since_last_successful_import' => null,
                'stale_after_days' => $threshold,
                'freshness_status' => 'untracked',
                'freshness_label' => 'No log yet',
                'badge_class' => 'bg-secondary',
            ];
            continue;
        }

        $dt = new DateTimeImmutable($lastImport);
        $days = max(0, (int)$dt->diff($now)->days);

        if ($days > $threshold) {
            $status = 'stale';
            $label = "Stale ({$days}d)";
            $badge = 'bg-danger';
        } elseif ($days >= (int)ceil($threshold * 0.75)) {
            $status = 'aging';
            $label = "Aging ({$days}d)";
            $badge = 'bg-warning text-dark';
        } else {
            $status = 'fresh';
            $label = "Fresh ({$days}d)";
            $badge = 'bg-success';
        }

        $out[$accountId] = [
            'last_successful_import_at' => $lastImport,
            'days_since_last_successful_import' => $days,
            'stale_after_days' => $threshold,
            'freshness_status' => $status,
            'freshness_label' => $label,
            'badge_class' => $badge,
        ];
    }

    return $out;
}
