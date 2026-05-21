<?php

function pie_defaults(): array
{
    return [
        'id' => '',
        'description' => '',
        'category_id' => '',
        'account_id' => '',
        'amount' => '',
        'budget_month' => '',
        'budget_month_start' => '',
        'window_start' => '',
        'window_end' => '',
        'timing_strategy' => 'latest',
        'manual_date' => '',
        'active' => 1,
        'notes' => '',
    ];
}

function pie_timing_options(): array
{
    return [
        'earliest' => 'Earliest date in window',
        'midpoint' => 'Midpoint of window',
        'latest' => 'Latest date in window (conservative)',
        'manual' => 'Specific manual date',
    ];
}

function pie_timing_label(string $strategy): string
{
    return match ($strategy) {
        'earliest' => 'Earliest',
        'midpoint' => 'Midpoint',
        'latest' => 'Latest',
        'manual' => 'Manual',
        default => ucfirst($strategy),
    };
}

function pie_financial_month_from_date(string $date): string
{
    try {
        $dt = new DateTimeImmutable($date);
    } catch (Throwable $e) {
        return '';
    }

    if ((int)$dt->format('d') < 13) {
        $dt = $dt->modify('-1 month');
    }

    return $dt->format('Y-m');
}

function pie_month_input_to_start_date(string $monthInput): ?string
{
    $monthInput = trim($monthInput);

    if ($monthInput === '' || !preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
        return null;
    }

    return $monthInput . '-13';
}

function pie_resolve_assumed_date(array $row): ?string
{
    $windowStart = trim((string)($row['window_start'] ?? ''));
    $windowEnd = trim((string)($row['window_end'] ?? ''));

    if ($windowStart === '' || $windowEnd === '') {
        return null;
    }

    try {
        $start = new DateTimeImmutable($windowStart);
        $end = new DateTimeImmutable($windowEnd);
    } catch (Throwable $e) {
        return null;
    }

    if ($end < $start) {
        return null;
    }

    $strategy = trim((string)($row['timing_strategy'] ?? 'latest'));

    if ($strategy === 'manual') {
        $manualDate = trim((string)($row['manual_date'] ?? ''));
        if ($manualDate === '') {
            return null;
        }

        try {
            $manual = new DateTimeImmutable($manualDate);
        } catch (Throwable $e) {
            return null;
        }

        if ($manual < $start || $manual > $end) {
            return null;
        }

        return $manual->format('Y-m-d');
    }

    if ($strategy === 'earliest') {
        return $start->format('Y-m-d');
    }

    if ($strategy === 'midpoint') {
        $days = (int)$start->diff($end)->days;
        $mid = $start->modify('+' . intdiv($days, 2) . ' days');
        return $mid->format('Y-m-d');
    }

    return $end->format('Y-m-d');
}
