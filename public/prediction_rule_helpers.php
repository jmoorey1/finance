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
            'amount' => '',
            'variable' => 0,
            'average_over_last' => 3,
            'day_of_month' => '',
            'adjust_for_weekend' => 'none',
            'active' => 1,
            'anchor_type' => 'day_of_month',
            'frequency' => 'monthly',
            'repeat_interval' => 1,
            'weekday' => '',
            'nth_weekday' => '',
            'is_business_day' => 1,
            'monthly_anchor_type' => 'day_of_month',
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

if (!function_exists('prediction_rule_frequency_options')) {
    function prediction_rule_frequency_options(): array
    {
        return [
            'monthly' => 'Monthly',
            'weekly' => 'Weekly',
            'fortnightly' => 'Fortnightly',
            'custom' => 'Custom (every N weeks from last actual)',
        ];
    }
}

if (!function_exists('prediction_rule_monthly_anchor_options')) {
    function prediction_rule_monthly_anchor_options(): array
    {
        return [
            'day_of_month' => 'Day of month',
            'nth_weekday' => 'Nth weekday',
            'last_business_day' => 'Last business day',
        ];
    }
}

if (!function_exists('prediction_rule_adjust_options')) {
    function prediction_rule_adjust_options(): array
    {
        return [
            'none' => 'No weekend adjustment',
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

if (!function_exists('prediction_rule_format_schedule')) {
    function prediction_rule_format_schedule(array $r): string
    {
        $weekdayNames = prediction_rule_weekday_options();
        $frequency = $r['frequency'] ?? 'monthly';
        $anchor = $r['anchor_type'] ?? 'day_of_month';
        $repeatInterval = max(1, (int)($r['repeat_interval'] ?? 1));
        $weekday = isset($r['weekday']) && $r['weekday'] !== '' ? (int)$r['weekday'] : null;
        $nthWeekday = isset($r['nth_weekday']) && $r['nth_weekday'] !== '' ? (int)$r['nth_weekday'] : null;
        $dayOfMonth = isset($r['day_of_month']) && $r['day_of_month'] !== '' ? (int)$r['day_of_month'] : null;
        $isBusinessDay = !empty($r['is_business_day']);

        if ($frequency === 'custom') {
            return "Every {$repeatInterval} week(s) from most recent actual transaction";
        }

        if ($frequency === 'weekly') {
            return $weekday !== null && isset($weekdayNames[$weekday])
                ? "Weekly on {$weekdayNames[$weekday]}"
                : "Weekly";
        }

        if ($frequency === 'fortnightly') {
            return $weekday !== null && isset($weekdayNames[$weekday])
                ? "Fortnightly on {$weekdayNames[$weekday]}"
                : "Fortnightly";
        }

        $prefix = $repeatInterval > 1 ? "Every {$repeatInterval} months" : "Monthly";

        if ($anchor === 'day_of_month' && $dayOfMonth) {
            return "{$prefix} on the " . prediction_rule_ordinal($dayOfMonth);
        }

        if ($anchor === 'nth_weekday' && $nthWeekday && $weekday !== null && isset($weekdayNames[$weekday])) {
            return "{$prefix} on the " . prediction_rule_ordinal($nthWeekday) . " {$weekdayNames[$weekday]}";
        }

        if ($anchor === 'last_business_day') {
            return $isBusinessDay ? "{$prefix} on the last business day" : "{$prefix} at month end";
        }

        return ucfirst($frequency);
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
