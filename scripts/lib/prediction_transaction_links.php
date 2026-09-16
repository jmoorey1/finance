<?php

declare(strict_types=1);

if (!function_exists('ptl_safe_return_url')) {
    function ptl_safe_return_url(?string $rawRedirect, string $default = 'ledger.php'): string
    {
        $rawRedirect = trim((string)$rawRedirect);
        if ($rawRedirect === '' || str_contains($rawRedirect, "\r") || str_contains($rawRedirect, "\n") || str_contains($rawRedirect, "\0")) {
            return $default;
        }

        $parts = parse_url($rawRedirect);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $default;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            return $default;
        }

        $allowedPages = [
            'ledger.php',
            'category_report.php',
            'subcategory_report.php',
            'job_expense_report.php',
            'predicted.php',
            'predicted_rule_history.php',
        ];

        $page = basename($path);
        if (!in_array($page, $allowedPages, true)) {
            return $default;
        }

        $allowedPaths = array_map(
            static fn(string $allowedPage): string => '/finance/public/' . $allowedPage,
            $allowedPages
        );

        if ($path !== $page && !in_array($path, $allowedPaths, true)) {
            return $default;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $path . $query;
    }
}

if (!function_exists('ptl_load_transaction_context')) {
    function ptl_load_transaction_context(PDO $pdo, int $transactionId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.account_id,
                t.date,
                t.description,
                t.amount,
                t.type AS transaction_type,
                t.category_id,
                t.predicted_transaction_id,
                t.transfer_group_id,
                t.reconciled,
                c.type AS category_type,
                a.name AS account_name,
                tg.id AS transfer_group_exists,
                tg.from_account_id AS transfer_from_account_id,
                tg.to_account_id AS transfer_to_account_id,
                fa.name AS transfer_from_account_name,
                ta.name AS transfer_to_account_name,
                (
                    SELECT COUNT(*)
                    FROM transaction_splits ts
                    WHERE ts.transaction_id = t.id
                ) AS split_count
            FROM transactions t
            JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN transfer_groups tg ON tg.id = t.transfer_group_id
            LEFT JOIN accounts fa ON fa.id = tg.from_account_id
            LEFT JOIN accounts ta ON ta.id = tg.to_account_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $hasTransferGroup = !empty($row['transfer_group_id']) && !empty($row['transfer_group_exists']);
        $isTransfer = $hasTransferGroup || (($row['transaction_type'] ?? '') === 'transfer');

        if ($isTransfer) {
            $row['effective_prediction_type'] = 'transfer';
        } elseif (in_array(($row['category_type'] ?? ''), ['income', 'expense'], true)) {
            $row['effective_prediction_type'] = $row['category_type'];
        } else {
            $row['effective_prediction_type'] = null;
        }

        $row['is_transfer'] = $isTransfer;
        return $row;
    }
}

if (!function_exists('ptl_current_rule_ids')) {
    function ptl_current_rule_ids(PDO $pdo, array $context): array
    {
        if (!empty($context['is_transfer']) && !empty($context['transfer_group_id'])) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT predicted_transaction_id
                FROM transactions
                WHERE transfer_group_id = ?
                  AND predicted_transaction_id IS NOT NULL
                ORDER BY predicted_transaction_id
            ");
            $stmt->execute([(int)$context['transfer_group_id']]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!empty($context['predicted_transaction_id'])) {
            return [(int)$context['predicted_transaction_id']];
        }

        return [];
    }
}

if (!function_exists('ptl_load_rule')) {
    function ptl_load_rule(PDO $pdo, int $ruleId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM predicted_transactions WHERE id = ? LIMIT 1");
        $stmt->execute([$ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('ptl_rule_is_compatible')) {
    function ptl_rule_is_compatible(array $rule, array $context): bool
    {
        $predictionType = (string)($rule['prediction_type'] ?? '');
        $effectiveType = (string)($context['effective_prediction_type'] ?? '');

        if (!empty($context['is_transfer'])) {
            if ($predictionType !== 'transfer') {
                return false;
            }

            $fromId = isset($context['transfer_from_account_id']) ? (int)$context['transfer_from_account_id'] : 0;
            $toId = isset($context['transfer_to_account_id']) ? (int)$context['transfer_to_account_id'] : 0;

            return $fromId > 0
                && $toId > 0
                && (int)($rule['from_account_id'] ?? 0) === $fromId
                && (int)($rule['to_account_id'] ?? 0) === $toId;
        }

        return in_array($effectiveType, ['income', 'expense'], true)
            && $predictionType === $effectiveType
            && (int)($rule['from_account_id'] ?? 0) === (int)($context['account_id'] ?? 0);
    }
}

if (!function_exists('ptl_load_compatible_rules')) {
    function ptl_load_compatible_rules(PDO $pdo, array $context): array
    {
        $currentIds = ptl_current_rule_ids($pdo, $context);
        $stmt = $pdo->query("
            SELECT *
            FROM predicted_transactions
            ORDER BY active DESC, description ASC, id ASC
        ");

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            if (ptl_rule_is_compatible($rule, $context) || in_array((int)$rule['id'], $currentIds, true)) {
                $out[] = $rule;
            }
        }

        return $out;
    }
}

if (!function_exists('ptl_assert_rule_compatible')) {
    function ptl_assert_rule_compatible(PDO $pdo, array $context, int $ruleId): array
    {
        $rule = ptl_load_rule($pdo, $ruleId);
        if (!$rule) {
            throw new RuntimeException('Selected prediction rule does not exist.');
        }

        if (!ptl_rule_is_compatible($rule, $context)) {
            throw new RuntimeException('Selected prediction rule is not compatible with this transaction account/type.');
        }

        return $rule;
    }
}

if (!function_exists('ptl_apply_rule_link')) {
    function ptl_apply_rule_link(PDO $pdo, array $context, ?int $ruleId): int
    {
        if (!empty($context['is_transfer']) && !empty($context['transfer_group_id'])) {
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET predicted_transaction_id = ?
                WHERE transfer_group_id = ?
            ");
            $stmt->execute([$ruleId, (int)$context['transfer_group_id']]);
            return $stmt->rowCount();
        }

        $stmt = $pdo->prepare("
            UPDATE transactions
            SET predicted_transaction_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$ruleId, (int)$context['id']]);
        return $stmt->rowCount();
    }
}

if (!function_exists('ptl_can_create_rule_from_transaction')) {
    function ptl_can_create_rule_from_transaction(PDO $pdo, array $context): array
    {
        $currentRuleIds = ptl_current_rule_ids($pdo, $context);
        if (!empty($currentRuleIds)) {
            return [false, 'This transaction is already linked to a prediction rule.'];
        }

        if ((int)($context['split_count'] ?? 0) > 0) {
            return [false, 'Split transactions cannot create a single prediction rule because future split allocation is not modelled by recurring rules.'];
        }

        $effectiveType = (string)($context['effective_prediction_type'] ?? '');
        if (!in_array($effectiveType, ['income', 'expense', 'transfer'], true)) {
            return [false, 'This transaction does not map cleanly to an income, expense, or transfer prediction type.'];
        }

        if ($effectiveType === 'transfer') {
            if (
                empty($context['transfer_group_id'])
                || (int)($context['transfer_from_account_id'] ?? 0) <= 0
                || (int)($context['transfer_to_account_id'] ?? 0) <= 0
            ) {
                return [false, 'Transfer transactions need valid transfer-group account metadata before a recurring rule can be created.'];
            }
        } elseif ((int)($context['category_id'] ?? 0) <= 0) {
            return [false, 'A regular transaction needs a category before a recurring rule can be created.'];
        }

        return [true, ''];
    }
}

if (!function_exists('ptl_build_rule_prefill')) {
    function ptl_build_rule_prefill(PDO $pdo, array $context): array
    {
        [$ok, $reason] = ptl_can_create_rule_from_transaction($pdo, $context);
        if (!$ok) {
            throw new RuntimeException($reason);
        }

        $date = new DateTimeImmutable((string)$context['date']);
        $isTransfer = !empty($context['is_transfer']);
        $amount = (float)$context['amount'];

        return [
            'description' => (string)$context['description'],
            'from_account_id' => $isTransfer
                ? (int)$context['transfer_from_account_id']
                : (int)$context['account_id'],
            'to_account_id' => $isTransfer
                ? (int)$context['transfer_to_account_id']
                : '',
            'category_id' => $isTransfer ? '' : (int)$context['category_id'],
            'prediction_type' => (string)$context['effective_prediction_type'],
            'amount' => number_format($isTransfer ? abs($amount) : $amount, 2, '.', ''),
            'variable' => 0,
            'average_over_last' => 3,
            'active' => 1,
            'recurrence_unit' => 'month',
            'recurrence_interval' => 1,
            'anchor_date' => $date->format('Y-m-d'),
            'schedule_pattern' => 'day_of_month',
            'day_of_month' => (int)$date->format('j'),
            'weekday' => '',
            'nth_weekday' => '',
            'business_day_adjustment' => 'none',
        ];
    }
}

if (!function_exists('ptl_lock_source_transaction')) {
    function ptl_lock_source_transaction(PDO $pdo, int $transactionId): array
    {
        $stmt = $pdo->prepare("SELECT id, transfer_group_id FROM transactions WHERE id = ? FOR UPDATE");
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Source transaction no longer exists.');
        }

        if (!empty($row['transfer_group_id'])) {
            $groupStmt = $pdo->prepare("SELECT id FROM transactions WHERE transfer_group_id = ? FOR UPDATE");
            $groupStmt->execute([(int)$row['transfer_group_id']]);
            $groupStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $context = ptl_load_transaction_context($pdo, $transactionId);
        if (!$context) {
            throw new RuntimeException('Unable to reload source transaction.');
        }

        return $context;
    }
}

if (!function_exists('ptl_load_rule_history_rows')) {
    function ptl_load_rule_history_rows(PDO $pdo, int $ruleId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.date,
                t.description,
                t.amount,
                t.account_id,
                a.name AS account_name,
                t.category_id,
                c.name AS category_name,
                c.type AS category_type,
                t.reconciled,
                t.transfer_group_id,
                t.predicted_transaction_id,
                tg.from_account_id AS transfer_from_account_id,
                tg.to_account_id AS transfer_to_account_id,
                fa.name AS transfer_from_account_name,
                ta.name AS transfer_to_account_name
            FROM transactions t
            JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN transfer_groups tg ON tg.id = t.transfer_group_id
            LEFT JOIN accounts fa ON fa.id = tg.from_account_id
            LEFT JOIN accounts ta ON ta.id = tg.to_account_id
            WHERE t.predicted_transaction_id = ?
            ORDER BY t.date DESC, t.id DESC
        ");
        $stmt->execute([$ruleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('ptl_group_rule_history_rows')) {
    function ptl_group_rule_history_rows(array $rows, string $predictionType): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $isGroupedTransfer = $predictionType === 'transfer' && !empty($row['transfer_group_id']);
            $key = $isGroupedTransfer
                ? 'g:' . (int)$row['transfer_group_id']
                : 't:' . (int)$row['id'];

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $occurrences = [];
        foreach ($groups as $groupRows) {
            $first = $groupRows[0];
            $isTransfer = $predictionType === 'transfer';
            $transactionIds = array_map(static fn(array $row): int => (int)$row['id'], $groupRows);
            sort($transactionIds);

            if ($isTransfer && !empty($first['transfer_group_id'])) {
                $fromAccountId = (int)($first['transfer_from_account_id'] ?? 0);
                $preferredRow = null;
                foreach ($groupRows as $candidate) {
                    if ((int)$candidate['account_id'] === $fromAccountId) {
                        $preferredRow = $candidate;
                        break;
                    }
                }
                $preferredRow ??= $first;

                $dates = array_column($groupRows, 'date');
                sort($dates);
                $dateStart = (string)$dates[0];
                $dateEnd = (string)$dates[count($dates) - 1];

                $amount = abs((float)$preferredRow['amount']);
                if ($amount === 0.0) {
                    foreach ($groupRows as $candidate) {
                        $amount = max($amount, abs((float)$candidate['amount']));
                    }
                }

                $allReconciled = true;
                foreach ($groupRows as $candidate) {
                    if (empty($candidate['reconciled'])) {
                        $allReconciled = false;
                        break;
                    }
                }

                $fromName = (string)($first['transfer_from_account_name'] ?? 'Unknown');
                $toName = (string)($first['transfer_to_account_name'] ?? 'Unknown');

                $occurrences[] = [
                    'date' => $dateStart,
                    'date_end' => $dateEnd,
                    'description' => (string)$preferredRow['description'],
                    'amount' => $amount,
                    'account_label' => $fromName . ' → ' . $toName,
                    'category_label' => 'Transfer',
                    'reconciled' => $allReconciled,
                    'transaction_ids' => $transactionIds,
                    'edit_transaction_id' => (int)$preferredRow['id'],
                    'transfer_group_id' => (int)$first['transfer_group_id'],
                ];
            } else {
                $occurrences[] = [
                    'date' => (string)$first['date'],
                    'date_end' => (string)$first['date'],
                    'description' => (string)$first['description'],
                    'amount' => (float)$first['amount'],
                    'account_label' => (string)$first['account_name'],
                    'category_label' => (string)($first['category_name'] ?? '—'),
                    'reconciled' => !empty($first['reconciled']),
                    'transaction_ids' => [(int)$first['id']],
                    'edit_transaction_id' => (int)$first['id'],
                    'transfer_group_id' => !empty($first['transfer_group_id']) ? (int)$first['transfer_group_id'] : null,
                ];
            }
        }

        usort($occurrences, static function (array $a, array $b): int {
            $dateCompare = strcmp((string)$b['date'], (string)$a['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return ((int)$b['edit_transaction_id']) <=> ((int)$a['edit_transaction_id']);
        });

        return $occurrences;
    }
}
