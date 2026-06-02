<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function tg_repair_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/admin/repair_transfer_group_integrity.php --dry-run\n";
    echo "  php scripts/admin/repair_transfer_group_integrity.php --apply\n";
}

function tg_repair_fail(string $message): void
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function tg_repair_money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function tg_repair_fetch_group_totals(PDO $pdo, array $groupIds): array
{
    if (empty($groupIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            transfer_group_id,
            ROUND(SUM(amount), 2) AS total_amount,
            COUNT(*) AS transaction_count
        FROM transactions
        WHERE transfer_group_id IN ({$placeholders})
        GROUP BY transfer_group_id
        ORDER BY transfer_group_id
    ");
    $stmt->execute(array_values($groupIds));

    $totals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $totals[(int)$row['transfer_group_id']] = [
            'total_amount' => tg_repair_money((float)$row['total_amount']),
            'transaction_count' => (int)$row['transaction_count'],
        ];
    }

    return $totals;
}

function tg_repair_fetch_bad_group_count(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT COUNT(*) AS bad_group_count
        FROM (
            SELECT transfer_group_id
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
              AND type = 'transfer'
            GROUP BY transfer_group_id
            HAVING ROUND(SUM(amount), 2) <> 0
        ) bad
    ");

    return (int)$stmt->fetchColumn();
}

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    tg_repair_usage();
    exit(1);
}

$apply = $mode === '--apply';

$planPath = __DIR__ . '/../../storage/reports/transfer_group_integrity_plan.json';
if (!is_file($planPath)) {
    tg_repair_fail("Missing repair plan: {$planPath}. Run audit_transfer_group_integrity.php first.");
}

$planRaw = file_get_contents($planPath);
if ($planRaw === false) {
    tg_repair_fail("Unable to read repair plan: {$planPath}");
}

try {
    $plan = json_decode($planRaw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    tg_repair_fail('Repair plan is not valid JSON: ' . $e->getMessage());
}

if (($plan['version'] ?? null) !== 1) {
    tg_repair_fail('Unsupported repair plan version.');
}

$proposals = array_values(array_filter(
    $plan['proposals'] ?? [],
    static fn(array $proposal): bool => ($proposal['proposal_type'] ?? '') === 'safe_full_group_pair'
));

if (empty($proposals)) {
    echo "No safe_full_group_pair proposals found. Nothing to do.\n";
    exit(0);
}

$pdo = get_db_connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$updates = [];
$targetGroupIds = [];

foreach ($proposals as $proposal) {
    foreach (($proposal['pairs'] ?? []) as $pair) {
        $targetGroupId = (int)($pair['target_transfer_group_id'] ?? 0);
        $negativeId = (int)($pair['negative_transaction_id'] ?? 0);
        $positiveId = (int)($pair['positive_transaction_id'] ?? 0);

        if ($targetGroupId <= 0 || $negativeId <= 0 || $positiveId <= 0) {
            tg_repair_fail('Invalid proposal pair encountered.');
        }

        $targetGroupIds[$targetGroupId] = $targetGroupId;

        $updates[$negativeId] = $targetGroupId;
        $updates[$positiveId] = $targetGroupId;
    }
}

ksort($updates);
ksort($targetGroupIds);

echo $apply ? "Applying transfer group repair\n" : "Dry run: transfer group repair\n";
echo "================================\n";
echo "Plan: {$planPath}\n";
echo "Safe proposals: " . count($proposals) . "\n";
echo "Transaction rows whose transfer_group_id may be updated: " . count($updates) . "\n";
echo "Only column updated by this script: transactions.transfer_group_id\n\n";

$beforeTotals = tg_repair_fetch_group_totals($pdo, array_values($targetGroupIds));
echo "Affected group totals before:\n";
foreach ($beforeTotals as $groupId => $meta) {
    echo "  TG {$groupId}: total {$meta['total_amount']}, rows {$meta['transaction_count']}\n";
}

if (!$apply) {
    echo "\nPlanned updates:\n";
    foreach ($updates as $transactionId => $targetGroupId) {
        echo "  Transaction #{$transactionId} -> transfer_group_id {$targetGroupId}\n";
    }
    echo "\nDry run only. Re-run with --apply to update transactions.transfer_group_id.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $selectStmt = $pdo->prepare("
        SELECT id, transfer_group_id, amount, type
        FROM transactions
        WHERE id = ?
        FOR UPDATE
    ");

    $updateStmt = $pdo->prepare("
        UPDATE transactions
        SET transfer_group_id = ?
        WHERE id = ?
    ");

    foreach ($updates as $transactionId => $targetGroupId) {
        $selectStmt->execute([$transactionId]);
        $row = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Transaction #{$transactionId} was not found.");
        }

        if ((string)$row['type'] !== 'transfer') {
            throw new RuntimeException("Transaction #{$transactionId} is not type='transfer'.");
        }

        $currentGroupId = (int)$row['transfer_group_id'];
        if ($currentGroupId === $targetGroupId) {
            continue;
        }

        $updateStmt->execute([$targetGroupId, $transactionId]);
    }

    $afterTotals = tg_repair_fetch_group_totals($pdo, array_values($targetGroupIds));
    foreach ($afterTotals as $groupId => $meta) {
        if (abs((float)$meta['total_amount']) >= 0.01) {
            throw new RuntimeException("Transfer group {$groupId} would still be non-zero after repair.");
        }

        if ((int)$meta['transaction_count'] !== 2) {
            throw new RuntimeException("Transfer group {$groupId} would not contain exactly two rows after repair.");
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    tg_repair_fail('Repair rolled back: ' . $e->getMessage());
}

echo "\nAffected group totals after:\n";
$afterTotals = tg_repair_fetch_group_totals($pdo, array_values($targetGroupIds));
foreach ($afterTotals as $groupId => $meta) {
    echo "  TG {$groupId}: total {$meta['total_amount']}, rows {$meta['transaction_count']}\n";
}

$badGroupCount = tg_repair_fetch_bad_group_count($pdo);
echo "\nRemaining non-zero transfer groups: {$badGroupCount}\n";

if ($badGroupCount > 0) {
    echo "Some groups remain for manual review. This is expected if they contain partial or ambiguous historic matches.\n";
}

echo "Repair complete.\n";
exit(0);
