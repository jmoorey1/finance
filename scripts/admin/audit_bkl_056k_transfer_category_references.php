<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$strict = in_array('--strict', $argv, true);

$projectRoot = realpath(__DIR__ . '/../..');
if ($projectRoot === false) {
    fwrite(STDERR, "Could not resolve project root.\n");
    exit(1);
}

$pdo = get_db_connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function bkl056k_heading(string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('=', strlen($title)) . "\n";
}

function bkl056k_subheading(string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('-', strlen($title)) . "\n";
}

function bkl056k_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function bkl056k_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bkl056k_print_check(string $name, int $count, bool $failWhenNonZero, array &$failures): void
{
    $status = $count === 0 ? 'OK' : ($failWhenNonZero ? 'FAIL' : 'INFO');
    printf("  %-72s %8d  [%s]\n", $name, $count, $status);

    if ($failWhenNonZero && $count > 0) {
        $failures[] = "{$name}: {$count}";
    }
}

function bkl056k_print_rows(array $rows, array $columns, int $limit = 25): void
{
    if (empty($rows)) {
        echo "  No rows.\n";
        return;
    }

    $shown = 0;
    foreach ($rows as $row) {
        if ($shown >= $limit) {
            $remaining = count($rows) - $limit;
            echo "  ... {$remaining} more row(s) not shown.\n";
            break;
        }

        $bits = [];
        foreach ($columns as $column) {
            $bits[] = "{$column}=" . (array_key_exists($column, $row) && $row[$column] !== null ? (string)$row[$column] : 'NULL');
        }

        echo "  - " . implode(', ', $bits) . "\n";
        $shown++;
    }
}

function bkl056k_scan_files(string $root, array $patterns): array
{
    $excludedDirs = [
        '.git',
        'vendor',
        'node_modules',
        'storage',
        '__pycache__',
    ];

    $excludedFileSuffixes = [
        '.bak',
        '.pyc',
        '.zip',
        '.png',
        '.jpg',
        '.jpeg',
        '.gif',
        '.pdf',
        '.sqlite',
    ];

    $includedExtensions = [
        'php',
        'py',
        'sql',
        'sh',
        'md',
        'js',
        'json',
    ];

    $results = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file, string $key, RecursiveIterator $iterator) use ($excludedDirs): bool {
                if ($file->isDir()) {
                    return !in_array($file->getFilename(), $excludedDirs, true);
                }

                return true;
            }
        )
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        foreach ($excludedFileSuffixes as $suffix) {
            if (str_ends_with($relative, $suffix) || str_contains($relative, $suffix . '_')) {
                continue 2;
            }
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, $includedExtensions, true)) {
            continue;
        }

        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            continue;
        }

        foreach ($content as $lineNo => $line) {
            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $line) === 1) {
                    $results[] = [
                        'file' => $relative,
                        'line' => $lineNo + 1,
                        'pattern' => $label,
                        'text' => trim($line),
                    ];
                    break;
                }
            }
        }
    }

    usort($results, static function (array $a, array $b): int {
        return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
    });

    return $results;
}

function bkl056k_classify_file_hit(array $hit): string
{
    $file = (string)$hit['file'];
    $pattern = (string)($hit['pattern'] ?? '');
    $text = (string)($hit['text'] ?? '');

    $allowedPrefixes = [
        'migrations/',
        'config/schema.sql',
        'scripts/admin/audit_bkl_056k_transfer_category_references.php',
        'scripts/admin/audit_transfer_group_integrity.php',
        'scripts/admin/repair_transfer_group_integrity.php',
        'scripts/admin/validate_transfer_model_guardrails.php',
    ];

    foreach ($allowedPrefixes as $prefix) {
        if ($file === $prefix || str_starts_with($file, $prefix)) {
            return 'expected/history/admin';
        }
    }

    if (
        str_starts_with($file, 'scripts/admin/apply_bkl_')
        || str_starts_with($file, 'scripts/admin/patch_bkl_')
        || str_starts_with($file, 'scripts/admin/continue_bkl_')
    ) {
        return 'expected/history/admin';
    }

    $expectedDeprecationUiFiles = [
        'public/categories.php',
        'public/category_edit.php',
        'public/category_edit_submit.php',
    ];

    if (in_array($file, $expectedDeprecationUiFiles, true)) {
        return 'expected/deprecation-ui';
    }

    $expectedTransferModelFiles = [
        'public/predicted_reconcile_action.php',
        'scripts/predict_instances.py',
        'scripts/predicted_reconciliation.php',
    ];

    if (
        in_array($file, $expectedTransferModelFiles, true)
        && preg_match('/\\b[a-z_]*\\.?type\\s*=\\s*[\'"]transfer[\'"]/i', $text) === 1
        && stripos($text, 'categories') === false
        && stripos($text, 'c.type') === false
    ) {
        return 'expected/transfer-transaction-model';
    }

    $expectedCounterpartyUiFiles = [
        'public/review_actions_handler.php',
        'public/review_view.php',
    ];

    if (
        in_array($file, $expectedCounterpartyUiFiles, true)
        && $pattern === 'linked account category use'
    ) {
        return 'expected/manual-transfer-counterparty-ui';
    }

    if (
        stripos($text, 'prediction_type') !== false
        && (
            stripos($text, "prediction_type = 'transfer'") !== false
            || stripos($text, 'prediction_type = "transfer"') !== false
        )
        && stripos($text, 'categories') === false
        && stripos($text, 'c.type') === false
    ) {
        return 'expected/transfer-prediction-model';
    }

    return 'review';
}

$failures = [];

bkl056k_heading('BKL-056K Transfer Category Reference Audit');

echo "Project root: {$projectRoot}\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n";
echo "Generated: " . (new DateTimeImmutable())->format(DateTimeInterface::ATOM) . "\n";

bkl056k_subheading('Data integrity checks');

$dataChecks = [
    [
        'name' => 'Actual transactions referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => true,
    ],
    [
        'name' => 'Transaction splits referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM transaction_splits ts
            JOIN categories c ON c.id = ts.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => true,
    ],
    [
        'name' => 'Prediction rules referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions pt
            JOIN categories c ON c.id = pt.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => true,
    ],
    [
        'name' => 'Prediction instances referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM predicted_instances pi
            JOIN categories c ON c.id = pi.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => true,
    ],
    [
        'name' => 'Staging transactions referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM staging_transactions st
            JOIN categories c ON c.id = st.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => false,
    ],
    [
        'name' => 'Budgets referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM budgets b
            JOIN categories c ON c.id = b.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => true,
    ],
    [
        'name' => 'Planned income events referencing transfer categories',
        'sql' => "
            SELECT COUNT(*)
            FROM planned_income_events pie
            JOIN categories c ON c.id = pie.category_id
            WHERE c.type = 'transfer'
        ",
        'fail' => true,
    ],
    [
        'name' => 'Transfer transaction rows with non-null category_id',
        'sql' => "
            SELECT COUNT(*)
            FROM transactions
            WHERE type = 'transfer'
              AND category_id IS NOT NULL
        ",
        'fail' => true,
    ],
    [
        'name' => 'Transfer transaction rows missing transfer_group_id',
        'sql' => "
            SELECT COUNT(*)
            FROM transactions
            WHERE type = 'transfer'
              AND transfer_group_id IS NULL
        ",
        'fail' => true,
    ],
    [
        'name' => 'Grouped transaction rows still carrying category_id',
        'sql' => "
            SELECT COUNT(*)
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
              AND category_id IS NOT NULL
        ",
        'fail' => true,
    ],
    [
        'name' => 'Transfer prediction rules with non-null category_id',
        'sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions
            WHERE prediction_type = 'transfer'
              AND category_id IS NOT NULL
        ",
        'fail' => true,
    ],
    [
        'name' => 'Transfer prediction instances with non-null category_id',
        'sql' => "
            SELECT COUNT(*)
            FROM predicted_instances
            WHERE prediction_type = 'transfer'
              AND category_id IS NOT NULL
        ",
        'fail' => true,
    ],
    [
        'name' => 'Income/expense prediction rules missing category_id',
        'sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions
            WHERE prediction_type IN ('income', 'expense')
              AND category_id IS NULL
        ",
        'fail' => true,
    ],
    [
        'name' => 'Income/expense prediction instances missing category_id',
        'sql' => "
            SELECT COUNT(*)
            FROM predicted_instances
            WHERE prediction_type IN ('income', 'expense')
              AND category_id IS NULL
        ",
        'fail' => true,
    ],
];

foreach ($dataChecks as $check) {
    bkl056k_print_check(
        $check['name'],
        bkl056k_count($pdo, $check['sql']),
        (bool)$check['fail'],
        $failures
    );
}

bkl056k_subheading('Legacy transfer category rows');

$legacyTransferRows = bkl056k_rows($pdo, "
    SELECT
        c.id,
        c.name,
        c.parent_id,
        c.linked_account_id,
        a.name AS linked_account_name
    FROM categories c
    LEFT JOIN accounts a ON a.id = c.linked_account_id
    WHERE c.type = 'transfer'
    ORDER BY c.id
");
bkl056k_print_rows($legacyTransferRows, ['id', 'name', 'parent_id', 'linked_account_id', 'linked_account_name'], 50);

bkl056k_subheading('Non-zero transfer groups');

$nonZeroGroups = bkl056k_rows($pdo, "
    SELECT
        transfer_group_id,
        ROUND(SUM(amount), 2) AS total_amount,
        COUNT(*) AS row_count
    FROM transactions
    WHERE transfer_group_id IS NOT NULL
    GROUP BY transfer_group_id
    HAVING ROUND(SUM(amount), 2) <> 0
    ORDER BY transfer_group_id
");
bkl056k_print_rows($nonZeroGroups, ['transfer_group_id', 'total_amount', 'row_count'], 25);
if (!empty($nonZeroGroups)) {
    $failures[] = 'Non-zero transfer groups: ' . count($nonZeroGroups);
}

bkl056k_subheading('Transfer group metadata shape');

$metadataChecks = [
    [
        'name' => 'Complete transfer groups missing from/to/amount/date',
        'sql' => "
            SELECT COUNT(*)
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'complete'
              AND EXISTS (
                  SELECT 1 FROM transactions t WHERE t.transfer_group_id = tg.id
              )
              AND (
                  tg.from_account_id IS NULL
                  OR tg.to_account_id IS NULL
                  OR tg.expected_amount IS NULL
                  OR tg.expected_amount <= 0
                  OR tg.transfer_date IS NULL
              )
        ",
    ],
    [
        'name' => 'Complete transfer groups whose rows do not balance as one in/one out',
        'sql' => "
            SELECT COUNT(*)
            FROM transfer_groups tg
            JOIN (
                SELECT
                    transfer_group_id,
                    COUNT(*) AS row_count,
                    ROUND(SUM(amount), 2) AS total_amount,
                    SUM(CASE WHEN amount < 0 THEN 1 ELSE 0 END) AS negative_count,
                    SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) AS positive_count
                FROM transactions
                WHERE transfer_group_id IS NOT NULL
                GROUP BY transfer_group_id
            ) x ON x.transfer_group_id = tg.id
            WHERE tg.transfer_status = 'complete'
              AND (
                  x.row_count <> 2
                  OR ABS(x.total_amount) >= 0.01
                  OR x.negative_count <> 1
                  OR x.positive_count <> 1
              )
        ",
    ],
];

foreach ($metadataChecks as $check) {
    bkl056k_print_check(
        $check['name'],
        bkl056k_count($pdo, $check['sql']),
        true,
        $failures
    );
}

bkl056k_subheading('Source-code reference scan');

$patterns = [
    'categories.type transfer literal' => "/type\\s*=\\s*['\\\"]transfer['\\\"]|c\\.type\\s*=\\s*['\\\"]transfer['\\\"]|`type`\\s*=\\s*'transfer'/i",
    'transfer category name literal' => "/Transfer\\s+(To|From)\\s*:/i",
    'transfer parent id 275' => "/\\bparent_id\\s*=\\s*275\\b|\\bTRANSFER_PARENT_ID\\b/",
    'linked account category use' => "/linked_account_id/",
    'transfer category SQL join' => "/JOIN\\s+categories\\s+.*category_id.*transfer|category_id.*JOIN\\s+categories.*transfer/i",
];

$fileHits = bkl056k_scan_files($projectRoot, $patterns);

$reviewHits = [];
$classCounts = [];

foreach ($fileHits as $hit) {
    $class = bkl056k_classify_file_hit($hit);
    $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;

    if ($class === 'review') {
        $reviewHits[] = $hit;
    }
}

foreach ($classCounts as $class => $count) {
    printf("  %-32s %8d\n", $class, $count);
}

echo "\nReview-required hits:\n";
if (empty($reviewHits)) {
    echo "  No review-required source references found.\n";
} else {
    foreach ($reviewHits as $hit) {
        echo "  - {$hit['file']}:{$hit['line']} [{$hit['pattern']}] {$hit['text']}\n";
    }

    if ($strict) {
        $failures[] = 'Review-required source references: ' . count($reviewHits);
    }
}

bkl056k_subheading('Summary');

if (empty($failures)) {
    echo "BKL-056K audit passed.\n";
    echo "Transfer categories appear to be legacy/deprecated data only, subject to manual review of expected source-code references.\n";
    exit(0);
}

echo "BKL-056K audit found blocking issue(s):\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}

exit(1);
