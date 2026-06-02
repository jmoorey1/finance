<?php

/**
 * Shared transfer group helpers.
 *
 * BKL-056C:
 * transfer_groups now hold first-class transfer metadata. New application
 * flows should create/update transfer groups with from_account_id,
 * to_account_id, expected_amount, transfer_date and transfer_status populated.
 *
 * This compatibility step does not remove transfer categories from transaction
 * rows yet. It only ensures new transfer_groups are no longer empty shells.
 */

if (!function_exists('finance_transfer_group_valid_status')) {
    function finance_transfer_group_valid_status(string $status): string
    {
        $allowed = ['complete', 'partial', 'needs_review'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException("Invalid transfer group status: {$status}");
        }

        return $status;
    }
}

if (!function_exists('finance_transfer_group_normalise_date')) {
    function finance_transfer_group_normalise_date(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            throw new InvalidArgumentException('Transfer date is required.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$parsed || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            try {
                $parsed = new DateTimeImmutable($date);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Transfer date is invalid.');
            }
        }

        return $parsed->format('Y-m-d');
    }
}

if (!function_exists('finance_transfer_group_assert_metadata')) {
    function finance_transfer_group_assert_metadata(
        int $fromAccountId,
        int $toAccountId,
        float $expectedAmount,
        string $transferDate,
        string $status
    ): array {
        if ($fromAccountId <= 0) {
            throw new InvalidArgumentException('Transfer from_account_id is required.');
        }

        if ($toAccountId <= 0) {
            throw new InvalidArgumentException('Transfer to_account_id is required.');
        }

        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('Transfer accounts must be different.');
        }

        $expectedAmount = round(abs($expectedAmount), 2);
        if ($expectedAmount <= 0) {
            throw new InvalidArgumentException('Transfer expected_amount must be greater than zero.');
        }

        return [
            'from_account_id' => $fromAccountId,
            'to_account_id' => $toAccountId,
            'expected_amount' => $expectedAmount,
            'transfer_date' => finance_transfer_group_normalise_date($transferDate),
            'transfer_status' => finance_transfer_group_valid_status($status),
        ];
    }
}

if (!function_exists('finance_transfer_group_description')) {
    function finance_transfer_group_description(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            $description = 'Transfer';
        }

        return mb_substr($description, 0, 255, 'UTF-8');
    }
}

if (!function_exists('finance_create_transfer_group')) {
    function finance_create_transfer_group(
        PDO $pdo,
        string $description,
        int $fromAccountId,
        int $toAccountId,
        float $expectedAmount,
        string $transferDate,
        string $status = 'complete'
    ): int {
        $metadata = finance_transfer_group_assert_metadata(
            $fromAccountId,
            $toAccountId,
            $expectedAmount,
            $transferDate,
            $status
        );

        $stmt = $pdo->prepare("
            INSERT INTO transfer_groups (
                description,
                from_account_id,
                to_account_id,
                expected_amount,
                transfer_date,
                transfer_status
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            finance_transfer_group_description($description),
            $metadata['from_account_id'],
            $metadata['to_account_id'],
            $metadata['expected_amount'],
            $metadata['transfer_date'],
            $metadata['transfer_status'],
        ]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('finance_update_transfer_group_metadata')) {
    function finance_update_transfer_group_metadata(
        PDO $pdo,
        int $transferGroupId,
        ?string $description,
        int $fromAccountId,
        int $toAccountId,
        float $expectedAmount,
        string $transferDate,
        string $status = 'complete'
    ): void {
        if ($transferGroupId <= 0) {
            throw new InvalidArgumentException('Transfer group ID is required.');
        }

        $metadata = finance_transfer_group_assert_metadata(
            $fromAccountId,
            $toAccountId,
            $expectedAmount,
            $transferDate,
            $status
        );

        $stmt = $pdo->prepare("
            UPDATE transfer_groups
            SET description = COALESCE(?, description),
                from_account_id = ?,
                to_account_id = ?,
                expected_amount = ?,
                transfer_date = ?,
                transfer_status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $description !== null ? finance_transfer_group_description($description) : null,
            $metadata['from_account_id'],
            $metadata['to_account_id'],
            $metadata['expected_amount'],
            $metadata['transfer_date'],
            $metadata['transfer_status'],
            $transferGroupId,
        ]);
    }
}
