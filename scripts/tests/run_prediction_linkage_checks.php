<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../lib/prediction_transaction_links.php';

$pdo = get_db_connection();
$failures = 0;

function linkage_check(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo "PASS: {$message}\n";
        return;
    }

    $failures++;
    echo "FAIL: {$message}\n";
}

function linkage_source_contains(string $relativePath, string $needle): bool
{
    $root = dirname(__DIR__, 2);
    $source = file_get_contents($root . '/' . $relativePath);
    return $source !== false && str_contains($source, $needle);
}

linkage_check(
    linkage_source_contains('public/ledger.php', 'predicted_rule_history.php?id='),
    'ledger exposes prediction-rule history navigation'
);
linkage_check(
    linkage_source_contains('public/ledger.php', 'from_transaction_id='),
    'ledger exposes create-rule-from-actual navigation'
);
linkage_check(
    linkage_source_contains('public/transaction_edit.php', 'name="predicted_transaction_id"'),
    'transaction editor exposes prediction rule linkage'
);
linkage_check(
    linkage_source_contains('public/transaction_edit_submit.php', 'ptl_apply_rule_link'),
    'transaction save persists prediction rule linkage through shared helper'
);
linkage_check(
    linkage_source_contains('public/predicted.php', '📜 History'),
    'prediction rule list exposes history action'
);
linkage_check(
    linkage_source_contains('public/predicted_rule_edit.php', 'source_transaction_id'),
    'prediction rule editor preserves source transaction when creating from actual'
);
linkage_check(
    linkage_source_contains('public/predicted_rule_save.php', 'ptl_lock_source_transaction'),
    'prediction rule save locks and links source transaction atomically'
);
linkage_check(
    is_file(dirname(__DIR__, 2) . '/public/predicted_rule_history.php'),
    'prediction rule history page exists'
);

$syntheticTransferRows = [
    [
        'id' => 101,
        'date' => '2026-08-01',
        'description' => 'Synthetic transfer out',
        'amount' => '-125.00',
        'account_id' => 10,
        'account_name' => 'From',
        'category_name' => null,
        'reconciled' => 1,
        'transfer_group_id' => 77,
        'transfer_from_account_id' => 10,
        'transfer_to_account_id' => 20,
        'transfer_from_account_name' => 'From',
        'transfer_to_account_name' => 'To',
    ],
    [
        'id' => 102,
        'date' => '2026-08-01',
        'description' => 'Synthetic transfer in',
        'amount' => '125.00',
        'account_id' => 20,
        'account_name' => 'To',
        'category_name' => null,
        'reconciled' => 1,
        'transfer_group_id' => 77,
        'transfer_from_account_id' => 10,
        'transfer_to_account_id' => 20,
        'transfer_from_account_name' => 'From',
        'transfer_to_account_name' => 'To',
    ],
];
$syntheticOccurrences = ptl_group_rule_history_rows($syntheticTransferRows, 'transfer');
linkage_check(count($syntheticOccurrences) === 1, 'transfer history groups two transaction legs into one occurrence');
linkage_check(
    count($syntheticOccurrences) === 1 && abs((float)$syntheticOccurrences[0]['amount'] - 125.0) < 0.001,
    'grouped transfer history uses the transfer amount rather than netting to zero'
);
linkage_check(
    count($syntheticOccurrences) === 1 && $syntheticOccurrences[0]['transaction_ids'] === [101, 102],
    'grouped transfer history preserves both transaction IDs'
);

$regularStmt = $pdo->query("
    SELECT t.id, t.predicted_transaction_id
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    JOIN predicted_transactions pt ON pt.id = t.predicted_transaction_id
    LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id
    WHERE t.predicted_transaction_id IS NOT NULL
      AND t.transfer_group_id IS NULL
      AND ts.id IS NULL
      AND c.type IN ('income', 'expense')
      AND pt.prediction_type = c.type
      AND pt.from_account_id = t.account_id
    ORDER BY t.date DESC, t.id DESC
    LIMIT 1
");
$regularCandidate = $regularStmt->fetch(PDO::FETCH_ASSOC);

if ($regularCandidate) {
    $context = ptl_load_transaction_context($pdo, (int)$regularCandidate['id']);
    linkage_check($context !== null, 'regular linked transaction context loads from database');

    if ($context) {
        $rule = ptl_load_rule($pdo, (int)$regularCandidate['predicted_transaction_id']);
        linkage_check(
            $rule !== null && ptl_rule_is_compatible($rule, $context),
            'existing regular transaction link is compatible with its rule'
        );

        $pdo->beginTransaction();
        try {
            ptl_apply_rule_link($pdo, $context, null);
            $checkStmt = $pdo->prepare('SELECT predicted_transaction_id FROM transactions WHERE id = ?');
            $checkStmt->execute([(int)$context['id']]);
            linkage_check($checkStmt->fetchColumn() === null, 'regular transaction can be unlinked inside transaction');

            ptl_apply_rule_link($pdo, $context, (int)$regularCandidate['predicted_transaction_id']);
            $checkStmt->execute([(int)$context['id']]);
            linkage_check(
                (int)$checkStmt->fetchColumn() === (int)$regularCandidate['predicted_transaction_id'],
                'regular transaction can be linked back to its rule'
            );
        } finally {
            $pdo->rollBack();
        }
    }
} else {
    echo "SKIP: no compatible linked regular transaction available for rollback linkage test\n";
}

$transferStmt = $pdo->query("
    SELECT
        MIN(t.id) AS transaction_id,
        t.transfer_group_id,
        t.predicted_transaction_id,
        COUNT(*) AS linked_rows
    FROM transactions t
    JOIN transfer_groups tg ON tg.id = t.transfer_group_id
    JOIN predicted_transactions pt ON pt.id = t.predicted_transaction_id
    WHERE t.transfer_group_id IS NOT NULL
      AND t.predicted_transaction_id IS NOT NULL
      AND pt.prediction_type = 'transfer'
      AND pt.from_account_id = tg.from_account_id
      AND pt.to_account_id = tg.to_account_id
    GROUP BY t.transfer_group_id, t.predicted_transaction_id
    HAVING COUNT(*) >= 2
    ORDER BY MAX(t.date) DESC
    LIMIT 1
");
$transferCandidate = $transferStmt->fetch(PDO::FETCH_ASSOC);

if ($transferCandidate) {
    $context = ptl_load_transaction_context($pdo, (int)$transferCandidate['transaction_id']);
    linkage_check($context !== null && !empty($context['is_transfer']), 'linked transfer context loads from database');

    if ($context) {
        $pdo->beginTransaction();
        try {
            ptl_apply_rule_link($pdo, $context, null);
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM transactions
                WHERE transfer_group_id = ?
                  AND predicted_transaction_id IS NOT NULL
            ");
            $checkStmt->execute([(int)$context['transfer_group_id']]);
            linkage_check((int)$checkStmt->fetchColumn() === 0, 'unlinking a transfer clears every leg in the transfer group');

            ptl_apply_rule_link($pdo, $context, (int)$transferCandidate['predicted_transaction_id']);
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM transactions
                WHERE transfer_group_id = ?
                  AND predicted_transaction_id = ?
            ");
            $checkStmt->execute([
                (int)$context['transfer_group_id'],
                (int)$transferCandidate['predicted_transaction_id'],
            ]);
            linkage_check(
                (int)$checkStmt->fetchColumn() >= 2,
                'linking a transfer applies the rule to every leg in the transfer group'
            );
        } finally {
            $pdo->rollBack();
        }
    }
} else {
    echo "SKIP: no compatible linked transfer group available for rollback linkage test\n";
}

$prefillStmt = $pdo->query("
    SELECT t.id
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id
    WHERE t.predicted_transaction_id IS NULL
      AND t.transfer_group_id IS NULL
      AND ts.id IS NULL
      AND c.type IN ('income', 'expense')
    ORDER BY t.date DESC, t.id DESC
    LIMIT 1
");
$prefillTransactionId = $prefillStmt->fetchColumn();

if ($prefillTransactionId) {
    $context = ptl_load_transaction_context($pdo, (int)$prefillTransactionId);
    if ($context) {
        [$canCreate, $reason] = ptl_can_create_rule_from_transaction($pdo, $context);
        linkage_check($canCreate, 'an eligible unlinked actual can seed a new prediction rule' . ($reason !== '' ? ": {$reason}" : ''));

        if ($canCreate) {
            $prefill = ptl_build_rule_prefill($pdo, $context);
            linkage_check($prefill['anchor_date'] === $context['date'], 'new-rule prefill anchors recurrence to source transaction date');
            linkage_check((int)$prefill['from_account_id'] === (int)$context['account_id'], 'new-rule prefill uses source account');
            linkage_check((int)$prefill['category_id'] === (int)$context['category_id'], 'new-rule prefill uses source category');
            linkage_check($prefill['recurrence_unit'] === 'month', 'new-rule prefill defaults to monthly but remains user-editable');
        }
    }
} else {
    echo "SKIP: no eligible unlinked regular transaction available for prefill test\n";
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} prediction linkage check(s) failed.\n");
    exit(1);
}

echo "All prediction linkage checks passed.\n";
