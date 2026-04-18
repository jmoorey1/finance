<?php
require_once __DIR__ . '/solvency_engine.php';

function cp_placeholder_list(array $items): string
{
    return implode(',', array_fill(0, count($items), '?'));
}

function cp_get_active_accounts(PDO $pdo, array $types = ['current', 'savings', 'credit']): array
{
    if (empty($types)) {
        return [];
    }

    $placeholders = cp_placeholder_list($types);
    $sql = "
        SELECT id, name, type, starting_balance
        FROM accounts
        WHERE active = 1
          AND type IN ($placeholders)
        ORDER BY FIELD(type, 'current', 'savings', 'credit'), name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($types));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cp_get_default_cash_planner_account_id(PDO $pdo): ?int
{
    $accounts = cp_get_active_accounts($pdo, ['current', 'savings', 'credit']);
    if (empty($accounts)) {
        return null;
    }

    foreach ($accounts as $a) {
        if (($a['type'] ?? '') === 'current') {
            return (int)$a['id'];
        }
    }

    return (int)$accounts[0]['id'];
}

function cp_fetch_actual_account_events(PDO $pdo, string $startDate, string $endDate, ?array $accountIds = null): array
{
    $params = [$startDate, $endDate];
    $accountFilterSql = '';

    if ($accountIds !== null && !empty($accountIds)) {
        $placeholders = cp_placeholder_list($accountIds);
        $accountFilterSql = " AND t.account_id IN ($placeholders)";
        $params = array_merge($params, array_values($accountIds));
    }

    $sql = "
        SELECT
            t.id AS source_id,
            t.date AS event_date,
            t.account_id,
            a.name AS account_name,
            a.type AS account_type,
            t.amount,
            t.description,
            t.type AS transaction_type,
            c.type AS category_type
        FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.date BETWEEN ? AND ?
          AND a.active = 1
          AND a.type IN ('current', 'savings', 'credit')
          $accountFilterSql
        ORDER BY t.date ASC, t.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = [
            'event_date' => $row['event_date'],
            'account_id' => (int)$row['account_id'],
            'account_name' => $row['account_name'],
            'account_type' => $row['account_type'],
            'amount' => (float)$row['amount'],
            'description' => $row['description'] ?? '',
            'source' => 'actual',
            'source_label' => 'Actual',
            'event_type' => $row['transaction_type'] ?: 'transaction',
            'source_id' => (int)$row['source_id'],
        ];
    }

    return $events;
}

function cp_fetch_predicted_account_events(PDO $pdo, string $startDate, string $endDate, ?array $accountIds = null): array
{
    $sql = "
        SELECT
            pi.id AS predicted_instance_id,
            pi.predicted_transaction_id,
            pi.statement_id,
            pi.scheduled_date,
            pi.amount,
            pi.description,
            pi.from_account_id,
            pi.to_account_id,
            c.type AS category_type,
            fa.name AS from_account_name,
            fa.type AS from_account_type,
            ta.name AS to_account_name,
            ta.type AS to_account_type
        FROM predicted_instances pi
        JOIN categories c ON c.id = pi.category_id
        LEFT JOIN accounts fa ON fa.id = pi.from_account_id
        LEFT JOIN accounts ta ON ta.id = pi.to_account_id
        WHERE pi.scheduled_date BETWEEN ? AND ?
          AND COALESCE(pi.fulfilled, 0) = 0
          AND COALESCE(pi.resolution_status, 'open') = 'open'
        ORDER BY pi.scheduled_date ASC, pi.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);

    $accountIdFilter = null;
    if ($accountIds !== null && !empty($accountIds)) {
        $accountIdFilter = array_map('intval', $accountIds);
    }

    $events = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $predictedSourceLabel = empty($row['predicted_transaction_id']) ? 'Manual one-off' : 'Rule-generated';
        $categoryType = $row['category_type'];
        $amount = (float)$row['amount'];

        if ($categoryType === 'income' || $categoryType === 'expense') {
            $accountId = $row['from_account_id'] !== null ? (int)$row['from_account_id'] : null;

            if ($accountId === null) {
                continue;
            }
            if ($accountIdFilter !== null && !in_array($accountId, $accountIdFilter, true)) {
                continue;
            }
            if (!in_array($row['from_account_type'], ['current', 'savings', 'credit'], true)) {
                continue;
            }

            $events[] = [
                'event_date' => $row['scheduled_date'],
                'account_id' => $accountId,
                'account_name' => $row['from_account_name'],
                'account_type' => $row['from_account_type'],
                'amount' => $amount,
                'description' => $row['description'] ?? '',
                'source' => 'predicted',
                'source_label' => $predictedSourceLabel,
                'event_type' => $categoryType === 'income' ? 'planned_income' : 'planned_expense',
                'source_id' => (int)$row['predicted_instance_id'],
                'predicted_transaction_id' => $row['predicted_transaction_id'] !== null ? (int)$row['predicted_transaction_id'] : null,
                'statement_id' => $row['statement_id'] !== null ? (int)$row['statement_id'] : null,
            ];
        } elseif ($categoryType === 'transfer') {
            $transferAmount = abs($amount);

            if ($row['from_account_id'] !== null) {
                $accountId = (int)$row['from_account_id'];
                if (($accountIdFilter === null || in_array($accountId, $accountIdFilter, true))
                    && in_array($row['from_account_type'], ['current', 'savings', 'credit'], true)) {
                    $events[] = [
                        'event_date' => $row['scheduled_date'],
                        'account_id' => $accountId,
                        'account_name' => $row['from_account_name'],
                        'account_type' => $row['from_account_type'],
                        'amount' => -$transferAmount,
                        'description' => $row['description'] ?? '',
                        'source' => 'predicted',
                        'source_label' => $predictedSourceLabel,
                        'event_type' => 'planned_transfer_out',
                        'source_id' => (int)$row['predicted_instance_id'],
                        'predicted_transaction_id' => $row['predicted_transaction_id'] !== null ? (int)$row['predicted_transaction_id'] : null,
                        'statement_id' => $row['statement_id'] !== null ? (int)$row['statement_id'] : null,
                    ];
                }
            }

            if ($row['to_account_id'] !== null) {
                $accountId = (int)$row['to_account_id'];
                if (($accountIdFilter === null || in_array($accountId, $accountIdFilter, true))
                    && in_array($row['to_account_type'], ['current', 'savings', 'credit'], true)) {
                    $events[] = [
                        'event_date' => $row['scheduled_date'],
                        'account_id' => $accountId,
                        'account_name' => $row['to_account_name'],
                        'account_type' => $row['to_account_type'],
                        'amount' => $transferAmount,
                        'description' => $row['description'] ?? '',
                        'source' => 'predicted',
                        'source_label' => $predictedSourceLabel,
                        'event_type' => 'planned_transfer_in',
                        'source_id' => (int)$row['predicted_instance_id'],
                        'predicted_transaction_id' => $row['predicted_transaction_id'] !== null ? (int)$row['predicted_transaction_id'] : null,
                        'statement_id' => $row['statement_id'] !== null ? (int)$row['statement_id'] : null,
                    ];
                }
            }
        }
    }

    return $events;
}

function cp_sort_events(array &$events): void
{
    usort($events, function ($a, $b) {
        if ($a['event_date'] !== $b['event_date']) {
            return strcmp($a['event_date'], $b['event_date']);
        }

        $sourceRankA = ($a['source'] === 'actual') ? 0 : 1;
        $sourceRankB = ($b['source'] === 'actual') ? 0 : 1;
        if ($sourceRankA !== $sourceRankB) {
            return $sourceRankA <=> $sourceRankB;
        }

        return ($a['source_id'] ?? 0) <=> ($b['source_id'] ?? 0);
    });
}

function cp_get_account_event_stream(PDO $pdo, int $accountId, string $startDate, string $endDate): array
{
    $acctName = se_get_account_name($pdo, $accountId);
    $balanceBeforeStart = se_get_account_balance_as_of(
        $pdo,
        $accountId,
        (new DateTimeImmutable($startDate))->modify('-1 day')->format('Y-m-d')
    );

    $events = array_merge(
        cp_fetch_actual_account_events($pdo, $startDate, $endDate, [$accountId]),
        cp_fetch_predicted_account_events($pdo, $startDate, $endDate, [$accountId])
    );

    cp_sort_events($events);

    $runningBalance = $balanceBeforeStart;
    foreach ($events as $idx => $event) {
        $events[$idx]['balance_before'] = $runningBalance;
        $runningBalance += (float)$event['amount'];
        $events[$idx]['balance_after'] = $runningBalance;
    }

    return [
        'account_id' => $accountId,
        'account_name' => $acctName,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'balance_before_start' => $balanceBeforeStart,
        'events' => $events,
    ];
}
