<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$sourcePath = $repoRoot . '/public/predicted_reconcile_action.php';

if (!is_file($sourcePath)) {
    fwrite(STDERR, "FAIL: Missing source file: {$sourcePath}\n");
    exit(1);
}

$source = file_get_contents($sourcePath);
if ($source === false) {
    fwrite(STDERR, "FAIL: Unable to read source file: {$sourcePath}\n");
    exit(1);
}

$required = [
    "require_once '../scripts/lib/transfer_group_helpers.php';" => 'transfer-group helper must be loaded',
    'foreach (pr_find_regular_candidates($pdo, $instance) as $candidate)' => 'posted regular transaction must be revalidated against the server-side candidate set',
    'Selected transaction is not a valid reconciliation candidate.' => 'invalid posted regular candidates must be rejected',
    'SELECT id, account_id, date, amount, transfer_group_id, predicted_transaction_id' => 'transfer pair load must include the actual transfer date',
    'finance_create_transfer_group(' => 'new retrospective transfer groups must be created with first-class metadata',
    'finance_update_transfer_group_metadata(' => 'existing transfer groups must be normalised to complete metadata during reconciliation',
];

$forbidden = [
    "INSERT INTO transfer_groups (description) VALUES ('Retrospective predicted reconciliation')" => 'metadata-less retrospective transfer-group creation must not return',
];

$failures = [];

foreach ($required as $needle => $description) {
    if (!str_contains($source, $needle)) {
        $failures[] = "Missing guardrail: {$description}";
    }
}

foreach ($forbidden as $needle => $description) {
    if (str_contains($source, $needle)) {
        $failures[] = "Forbidden regression found: {$description}";
    }
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Predicted reconciliation source checks passed.\n";
echo 'Required guardrails checked: ' . count($required) . "\n";
echo 'Forbidden regressions checked: ' . count($forbidden) . "\n";
exit(0);
