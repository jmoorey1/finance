<?php

/**
 * Shared split transaction helpers.
 *
 * BKL-055:
 * Splitness is now structural. A transaction is split when it has child rows in
 * transaction_splits. The legacy "Split/Multiple Categories" category remains
 * only as historical data and must not be used as an operational marker.
 */

if (!function_exists('finance_split_category_sentinel')) {
    function finance_split_category_sentinel(): string
    {
        return '__split__';
    }
}

if (!function_exists('finance_is_split_sentinel')) {
    function finance_is_split_sentinel(?string $value): bool
    {
        return trim((string)$value) === finance_split_category_sentinel();
    }
}

if (!function_exists('finance_legacy_split_category_name')) {
    function finance_legacy_split_category_name(): string
    {
        return 'Split/Multiple Categories';
    }
}

if (!function_exists('finance_transaction_has_splits')) {
    function finance_transaction_has_splits(PDO $pdo, int $transactionId): bool
    {
        if ($transactionId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM transaction_splits
            WHERE transaction_id = ?
            LIMIT 1
        ");
        $stmt->execute([$transactionId]);

        return $stmt->fetchColumn() !== false;
    }
}
