<?php

function fp_to_immutable(?DateTimeInterface $value = null): DateTimeImmutable
{
    if ($value instanceof DateTimeImmutable) {
        return $value;
    }

    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }

    return new DateTimeImmutable('now');
}

function get_financial_month_range(?DateTimeInterface $today = null): array
{
    $today = fp_to_immutable($today);

    $monthOffset = ((int)$today->format('d') < 13) ? -1 : 0;
    $inputMonth = $today->modify("{$monthOffset} month");

    $start = new DateTimeImmutable($inputMonth->format('Y-m-13'), $today->getTimezone());
    $end = $start->modify('+1 month')->modify('-1 day');

    return [
        'today' => $today,
        'start' => $start,
        'end'   => $end,
    ];
}

function get_weekly_digest_reporting_range(?DateTimeInterface $today = null): array
{
    $today = fp_to_immutable($today);
    $range = get_financial_month_range($today);

    $dayOfMonth = (int)$today->format('d');

    if ($dayOfMonth >= 13 && $dayOfMonth < 18) {
        $range['start'] = $range['start']->modify('-1 month');
        $range['end']   = $range['end']->modify('-1 month');
    }

    return $range;
}

function get_financial_ytd_start(DateTimeInterface $periodStart): DateTimeImmutable
{
    $periodStart = fp_to_immutable($periodStart);
    return new DateTimeImmutable($periodStart->format('Y-01-13'), $periodStart->getTimezone());
}
