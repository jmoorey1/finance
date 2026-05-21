<?php
require_once __DIR__ . '/forecast_utils.php';
require_once __DIR__ . '/cash_planner.php';
require_once __DIR__ . '/planned_income_engine.php';

function sti_get_current_account_ids(PDO $pdo): array
{
    $accounts = cp_get_active_accounts($pdo, ['current']);
    return array_map(fn($a) => (int)$a['id'], $accounts);
}

function sti_get_upcoming_flexible_income_events(PDO $pdo, int $days = 45, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $end = $today->modify("+{$days} days");

    $accountIds = sti_get_current_account_ids($pdo);
    if (empty($accountIds)) {
        return [];
    }

    $placeholders = cp_placeholder_list($accountIds);
    $sql = "
        SELECT
            pie.*,
            a.name AS account_name
        FROM planned_income_events pie
        JOIN accounts a ON a.id = pie.account_id
        JOIN categories c ON c.id = pie.category_id
        WHERE pie.active = 1
          AND a.active = 1
          AND a.type = 'current'
          AND c.type = 'income'
          AND pie.window_end >= ?
          AND pie.window_start <= ?
          AND pie.account_id IN ($placeholders)
        ORDER BY pie.window_start ASC, pie.id ASC
    ";

    $params = array_merge(
        [$today->format('Y-m-d'), $end->format('Y-m-d')],
        $accountIds
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assumedDate = pie_resolve_assumed_date($row);
        if ($assumedDate === null) {
            continue;
        }
        if ($assumedDate < $today->format('Y-m-d') || $assumedDate > $end->format('Y-m-d')) {
            continue;
        }

        $budgetMonthStart = !empty($row['budget_month_start'])
            ? se_financial_month_key((string)$row['budget_month_start'])
            : null;
        $assumedFinancialMonth = se_financial_month_key($assumedDate);

        $rows[] = [
            'id' => (int)$row['id'],
            'description' => (string)$row['description'],
            'account_name' => (string)$row['account_name'],
            'amount' => (float)$row['amount'],
            'budget_month_start' => $budgetMonthStart,
            'window_start' => (string)$row['window_start'],
            'window_end' => (string)$row['window_end'],
            'timing_strategy' => (string)$row['timing_strategy'],
            'manual_date' => $row['manual_date'],
            'assumed_date' => $assumedDate,
            'assumed_financial_month' => $assumedFinancialMonth,
            'month_shift' => ($budgetMonthStart !== null && $budgetMonthStart !== $assumedFinancialMonth),
        ];
    }

    usort($rows, function ($a, $b) {
        if ($a['assumed_date'] === $b['assumed_date']) {
            return strcmp($a['description'], $b['description']);
        }
        return strcmp($a['assumed_date'], $b['assumed_date']);
    });

    return $rows;
}

function sti_find_support_events(array $issue, int $max = 3): array
{
    $support = [];

    foreach (($issue['events'] ?? []) as $event) {
        if ((float)($event['amount'] ?? 0) <= 0) {
            continue;
        }
        if (($event['event_date'] ?? '') < ($issue['start_day'] ?? '')) {
            continue;
        }

        $support[] = $event;
        if (count($support) >= $max) {
            break;
        }
    }

    return $support;
}

function sti_build_timing_overlay(PDO $pdo, int $reserveAccountId, int $days = 45, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');

    $issues = get_forecast_shortfalls($pdo, $days, $days, $reserveAccountId);
    $totalTopUp = 0.0;
    $totalBreach = 0.0;

    foreach ($issues as &$issue) {
        $issue['support_events'] = sti_find_support_events($issue, 3);
        $totalTopUp += (float)($issue['top_up'] ?? 0.0);
        $totalBreach += (float)($issue['breach_amount'] ?? 0.0);
    }
    unset($issue);

    return [
        'window_days' => $days,
        'today' => $today->format('Y-m-d'),
        'issue_count' => count($issues),
        'total_top_up' => $totalTopUp,
        'total_breach' => $totalBreach,
        'earliest_issue' => $issues[0] ?? null,
        'issues' => $issues,
        'flexible_income_events' => sti_get_upcoming_flexible_income_events($pdo, $days, $today),
    ];
}
