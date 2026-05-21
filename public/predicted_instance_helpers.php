<?php

if (!function_exists('predicted_instance_defaults')) {
    function predicted_instance_defaults(): array
    {
        return [
            'id' => '',
            'scheduled_date' => '',
            'description' => '',
            'from_account_id' => '',
            'to_account_id' => '',
            'category_id' => '',
            'amount' => '',
            'budget_treatment' => 'additional',
            'budget_month' => '',
            'budget_month_start' => '',
            'budget_amount' => '',
        ];
    }
}

if (!function_exists('predicted_instance_financial_month_from_date')) {
    function predicted_instance_financial_month_from_date(string $date): string
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
}

if (!function_exists('predicted_instance_month_input_to_start_date')) {
    function predicted_instance_month_input_to_start_date(string $monthInput): ?string
    {
        $monthInput = trim($monthInput);

        if ($monthInput === '' || !preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
            return null;
        }

        return $monthInput . '-13';
    }
}

if (!function_exists('predicted_instance_guess_budget_amount')) {
    function predicted_instance_guess_budget_amount(PDO $pdo, int $categoryId, string $budgetMonthStart): ?float
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(parent_id, id) AS budget_category_id, type
            FROM categories
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$categoryId]);
        $meta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$meta) {
            return null;
        }

        if (!in_array((string)$meta['type'], ['income', 'expense'], true)) {
            return null;
        }

        $budgetCategoryId = (int)$meta['budget_category_id'];

        $stmt = $pdo->prepare("
            SELECT amount
            FROM budgets
            WHERE month_start = ?
              AND category_id = ?
            LIMIT 1
        ");
        $stmt->execute([$budgetMonthStart, $budgetCategoryId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (float)$value : null;
    }
}
