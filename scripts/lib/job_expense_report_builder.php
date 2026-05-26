<?php
require_once __DIR__ . '/finance_periods.php';

function jer_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

function jer_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function jer_people_config(): array
{
    return [
        'india' => [
            'label' => 'India',
            'category_id' => 297,
        ],
        'john' => [
            'label' => 'John',
            'category_id' => 296,
        ],
    ];
}

function jer_normalize_person(?string $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['india', 'john', 'both'], true) ? $value : 'both';
}

function jer_selected_people(string $personKey): array
{
    $all = jer_people_config();

    if ($personKey === 'india') {
        return ['india' => $all['india']];
    }

    if ($personKey === 'john') {
        return ['john' => $all['john']];
    }

    return $all;
}

function jer_selection_label(string $personKey): string
{
    return match ($personKey) {
        'india' => 'India',
        'john' => 'John',
        default => 'India and John',
    };
}

function jer_summary_label(string $personKey): string
{
    return $personKey === 'both'
        ? 'Combined Summary'
        : jer_selection_label($personKey) . ' Summary';
}

function jer_parse_range(PDO $pdo, array $input): array
{
    $today = new DateTimeImmutable('today');
    $preset = isset($input['preset']) ? (string)$input['preset'] : '12m';
    $fromRaw = isset($input['from']) ? trim((string)$input['from']) : '';
    $toRaw = isset($input['to']) ? trim((string)$input['to']) : '';

    if ($preset === 'custom') {
        try {
            $from = $fromRaw !== '' ? new DateTimeImmutable($fromRaw) : new DateTimeImmutable('2000-01-01');
            $to = $toRaw !== '' ? new DateTimeImmutable($toRaw) : $today;
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid custom date range.');
        }

        if ($from > $to) {
            throw new RuntimeException('From date must be on or before To date.');
        }

        return [
            'preset' => 'custom',
            'from' => $from,
            'to' => $to,
            'label' => $from->format('j M Y') . ' – ' . $to->format('j M Y'),
        ];
    }

    if ($preset === 'fy') {
        $monthRange = get_financial_month_range($today);
        $fyStart = get_financial_ytd_start($monthRange['start']);
        return [
            'preset' => 'fy',
            'from' => $fyStart,
            'to' => $today,
            'label' => 'Current financial year (' . $fyStart->format('j M Y') . ' – ' . $today->format('j M Y') . ')',
        ];
    }

    if ($preset === 'all') {
        $people = jer_people_config();
        $ids = array_map(fn($p) => (int)$p['category_id'], array_values($people));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare("
            SELECT
                MIN(ll.line_date) AS min_date,
                MAX(ll.line_date) AS max_date
            FROM ledger_lines ll
            WHERE ll.is_prediction = 0
              AND ll.category_id IN ($placeholders)
        ");
        $stmt->execute($ids);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $minDate = !empty($row['min_date']) ? new DateTimeImmutable((string)$row['min_date']) : $today;
        $maxDate = !empty($row['max_date']) ? new DateTimeImmutable((string)$row['max_date']) : $today;

        return [
            'preset' => 'all',
            'from' => $minDate,
            'to' => $maxDate,
            'label' => 'All time (' . $minDate->format('j M Y') . ' – ' . $maxDate->format('j M Y') . ')',
        ];
    }

    $from = $today->modify('-12 months');
    return [
        'preset' => '12m',
        'from' => $from,
        'to' => $today,
        'label' => 'Last 12 months (' . $from->format('j M Y') . ' – ' . $today->format('j M Y') . ')',
    ];
}

function jer_fetch_rows(PDO $pdo, int $categoryId, DateTimeInterface $from, DateTimeInterface $to): array
{
    $stmt = $pdo->prepare("
        SELECT
            ll.source,
            ll.transaction_id,
            ll.transaction_split_id,
            ll.predicted_instance_id,
            ll.line_date,
            ll.amount,
            ll.account_name,
            ll.description,
            ll.category_name
        FROM ledger_lines ll
        WHERE ll.is_prediction = 0
          AND ll.category_id = ?
          AND ll.line_date BETWEEN ? AND ?
        ORDER BY
            ll.line_date ASC,
            COALESCE(ll.transaction_id, 0) ASC,
            COALESCE(ll.transaction_split_id, 0) ASC
    ");
    $stmt->execute([
        $categoryId,
        $from->format('Y-m-d'),
        $to->format('Y-m-d'),
    ]);

    $rows = [];
    $runningNet = 0.0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = (float)$row['amount'];
        $runningNet += $amount;

        $rows[] = [
            'source' => (string)$row['source'],
            'transaction_id' => $row['transaction_id'] !== null ? (int)$row['transaction_id'] : null,
            'transaction_split_id' => $row['transaction_split_id'] !== null ? (int)$row['transaction_split_id'] : null,
            'predicted_instance_id' => $row['predicted_instance_id'] !== null ? (int)$row['predicted_instance_id'] : null,
            'line_date' => (string)$row['line_date'],
            'amount' => $amount,
            'account_name' => (string)$row['account_name'],
            'description' => (string)$row['description'],
            'category_name' => (string)$row['category_name'],
            'running_net' => round($runningNet, 2),
        ];
    }

    return array_reverse($rows);
}

function jer_build_person_report(PDO $pdo, string $key, array $person, DateTimeInterface $from, DateTimeInterface $to): array
{
    $rows = jer_fetch_rows($pdo, (int)$person['category_id'], $from, $to);

    $outgoing = 0.0;
    $incoming = 0.0;

    foreach ($rows as $row) {
        $amount = (float)$row['amount'];
        if ($amount < 0) {
            $outgoing += abs($amount);
        } elseif ($amount > 0) {
            $incoming += $amount;
        }
    }

    return [
        'key' => $key,
        'label' => (string)$person['label'],
        'category_id' => (int)$person['category_id'],
        'transaction_count' => count($rows),
        'total_outgoing' => round($outgoing, 2),
        'total_incoming' => round($incoming, 2),
        'net_position' => round($incoming - $outgoing, 2),
        'rows' => $rows,
    ];
}

function jer_build_report(PDO $pdo, array $input = []): array
{
    $range = jer_parse_range($pdo, $input);
    $personKey = jer_normalize_person($input['person'] ?? 'both');
    $people = jer_selected_people($personKey);

    $sections = [];
    foreach ($people as $key => $person) {
        $sections[$key] = jer_build_person_report($pdo, $key, $person, $range['from'], $range['to']);
    }

    $combined = [
        'total_outgoing' => 0.0,
        'total_incoming' => 0.0,
        'net_position' => 0.0,
        'transaction_count' => 0,
    ];

    foreach ($sections as $section) {
        $combined['total_outgoing'] += (float)$section['total_outgoing'];
        $combined['total_incoming'] += (float)$section['total_incoming'];
        $combined['net_position'] += (float)$section['net_position'];
        $combined['transaction_count'] += (int)$section['transaction_count'];
    }

    return [
        'selection' => [
            'key' => $personKey,
            'label' => jer_selection_label($personKey),
        ],
        'summary_label' => jer_summary_label($personKey),
        'range' => $range,
        'combined' => [
            'total_outgoing' => round($combined['total_outgoing'], 2),
            'total_incoming' => round($combined['total_incoming'], 2),
            'net_position' => round($combined['net_position'], 2),
            'transaction_count' => $combined['transaction_count'],
        ],
        'sections' => $sections,
    ];
}
