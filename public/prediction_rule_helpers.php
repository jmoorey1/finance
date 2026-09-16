<?php

if (!function_exists('prediction_rule_defaults')) {
    function prediction_rule_defaults(): array
    {
        return [
            'id' => '',
            'description' => '',
            'from_account_id' => '',
            'to_account_id' => '',
            'category_id' => '',
            'prediction_type' => 'expense',
            'amount' => '',
            'variable' => 0,
            'average_over_last' => 3,
            'active' => 1,
            'recurrence_unit' => 'month',
            'recurrence_interval' => 1,
            'anchor_date' => date('Y-m-d'),
            'schedule_pattern' => 'day_of_month',
            'day_of_month' => date('j'),
            'weekday' => '',
            'nth_weekday' => '',
            'business_day_adjustment' => 'none',
        ];
    }
}

if (!function_exists('prediction_rule_weekday_options')) {
    function prediction_rule_weekday_options(): array
    {
        return [
            0 => 'Monday',
            1 => 'Tuesday',
            2 => 'Wednesday',
            3 => 'Thursday',
            4 => 'Friday',
            5 => 'Saturday',
            6 => 'Sunday',
        ];
    }
}

if (!function_exists('prediction_rule_recurrence_unit_options')) {
    function prediction_rule_recurrence_unit_options(): array
    {
        return [
            'week' => 'Week(s)',
            'month' => 'Month(s)',
            'year' => 'Year(s)',
        ];
    }
}

if (!function_exists('prediction_rule_type_options')) {
    function prediction_rule_type_options(): array
    {
        return [
            'expense' => 'Expense',
            'income' => 'Income',
            'transfer' => 'Transfer',
        ];
    }
}

if (!function_exists('prediction_rule_schedule_pattern_options')) {
    function prediction_rule_schedule_pattern_options(): array
    {
        return [
            'anchor_date' => 'Anchor date day',
            'day_of_month' => 'Day of month',
            'nth_weekday' => 'Nth weekday',
            'month_end' => 'Month end',
        ];
    }
}

if (!function_exists('prediction_rule_business_day_adjustment_options')) {
    function prediction_rule_business_day_adjustment_options(): array
    {
        return [
            'none' => 'No business-day adjustment',
            'previous_business_day' => 'Move to previous business day',
            'next_business_day' => 'Move to next business day',
        ];
    }
}

if (!function_exists('prediction_rule_ordinal')) {
    function prediction_rule_ordinal($n): string
    {
        $n = (int)$n;
        if (!in_array($n % 100, [11, 12, 13], true)) {
            switch ($n % 10) {
                case 1: return $n . 'st';
                case 2: return $n . 'nd';
                case 3: return $n . 'rd';
            }
        }
        return $n . 'th';
    }
}

if (!function_exists('prediction_rule_format_anchor_date')) {
    function prediction_rule_format_anchor_date(?string $value): string
    {
        if (!$value) {
            return 'unknown date';
        }

        try {
            return (new DateTimeImmutable($value))->format('j M Y');
        } catch (Throwable $e) {
            return $value;
        }
    }
}

if (!function_exists('prediction_rule_format_schedule')) {
    function prediction_rule_format_schedule(array $r): string
    {
        $weekdayNames = prediction_rule_weekday_options();
        $unit = $r['recurrence_unit'] ?? 'month';
        $interval = max(1, (int)($r['recurrence_interval'] ?? 1));
        $pattern = $r['schedule_pattern'] ?? 'anchor_date';
        $anchorDate = isset($r['anchor_date']) ? (string)$r['anchor_date'] : '';
        $weekday = isset($r['weekday']) && $r['weekday'] !== '' ? (int)$r['weekday'] : null;
        $nthWeekday = isset($r['nth_weekday']) && $r['nth_weekday'] !== '' ? (int)$r['nth_weekday'] : null;
        $dayOfMonth = isset($r['day_of_month']) && $r['day_of_month'] !== '' ? (int)$r['day_of_month'] : null;
        $adjustment = $r['business_day_adjustment'] ?? 'none';

        if ($unit === 'week') {
            $base = $interval === 1 ? 'Weekly' : "Every {$interval} weeks";
            $text = $base . ' from ' . prediction_rule_format_anchor_date($anchorDate);
        } elseif ($unit === 'year') {
            $base = $interval === 1 ? 'Annually' : "Every {$interval} years";
            $text = $base . ' from ' . prediction_rule_format_anchor_date($anchorDate);
        } else {
            $base = $interval === 1 ? 'Monthly' : "Every {$interval} months";

            if ($pattern === 'day_of_month' && $dayOfMonth) {
                $text = $base . ' on the ' . prediction_rule_ordinal($dayOfMonth);
            } elseif ($pattern === 'nth_weekday' && $nthWeekday && $weekday !== null && isset($weekdayNames[$weekday])) {
                $text = $base . ' on the ' . prediction_rule_ordinal($nthWeekday) . ' ' . $weekdayNames[$weekday];
            } elseif ($pattern === 'month_end') {
                $text = $base . ' at month end';
            } else {
                $text = $base . ' from ' . prediction_rule_format_anchor_date($anchorDate);
            }

            if ($interval > 1 && $anchorDate !== '') {
                try {
                    $text .= ' (phase: ' . (new DateTimeImmutable($anchorDate))->format('M Y') . ')';
                } catch (Throwable $e) {
                    $text .= ' (phase anchored)';
                }
            }
        }

        if ($adjustment === 'previous_business_day') {
            $text .= '; previous business day if needed';
        } elseif ($adjustment === 'next_business_day') {
            $text .= '; next business day if needed';
        }

        return $text;
    }
}

if (!function_exists('prediction_rule_format_variable_label')) {
    function prediction_rule_format_variable_label(array $r): string
    {
        $amount = isset($r['amount']) && $r['amount'] !== null ? number_format((float)$r['amount'], 2) : null;

        if (!empty($r['variable'])) {
            $avg = max(1, (int)($r['average_over_last'] ?? 0));
            if ($amount !== null) {
                return "Avg last {$avg}, fallback £{$amount}";
            }
            return "Avg last {$avg}";
        }

        return $amount !== null ? "Fixed (£{$amount})" : "Fixed";
    }
}

if (!function_exists('prediction_rule_prune_future_open_instances')) {
    function prediction_rule_prune_future_open_instances(PDO $pdo, int $ruleId): int
    {
        $stmt = $pdo->prepare("
            DELETE FROM predicted_instances
            WHERE predicted_transaction_id = ?
              AND scheduled_date >= CURDATE()
              AND COALESCE(fulfilled, 0) = 0
              AND confirmed = 0
              AND COALESCE(resolution_status, 'open') = 'open'
        ");
        $stmt->execute([$ruleId]);
        return $stmt->rowCount();
    }
}
