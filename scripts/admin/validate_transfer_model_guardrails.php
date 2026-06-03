<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/**
 * BKL-056L
 *
 * Transfer model guardrail validator.
 *
 * This script is intentionally stricter than normal page logic. It is designed
 * to catch regressions after imports, manual repairs, prediction generation,
 * reconciliation changes, and future schema work.
 *
 * It does not change data.
 */

$options = [
    'verbose' => in_array('--verbose', $argv, true),
    'showRows' => in_array('--show-rows', $argv, true),
];

$pdo = get_db_connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function guard_heading(string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('=', strlen($title)) . "\n";
}

function guard_subheading(string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('-', strlen($title)) . "\n";
}

function guard_fetch_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function guard_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function guard_print_check(string $name, int $count, bool $failWhenNonZero, array &$failures): void
{
    $status = $count === 0 ? 'OK' : ($failWhenNonZero ? 'FAIL' : 'WARN');

    printf("  %-78s %8d  [%s]\n", $name, $count, $status);

    if ($failWhenNonZero && $count > 0) {
        $failures[] = "{$name}: {$count}";
    }
}

function guard_print_rows(array $rows, int $limit = 25): void
{
    if (empty($rows)) {
        echo "    No example rows.\n";
        return;
    }

    $shown = 0;
    foreach ($rows as $row) {
        if ($shown >= $limit) {
            $remaining = count($rows) - $limit;
            echo "    ... {$remaining} more row(s) not shown.\n";
            break;
        }

        $parts = [];
        foreach ($row as $key => $value) {
            $parts[] = "{$key}=" . ($value === null ? 'NULL' : (string)$value);
        }

        echo "    - " . implode(', ', $parts) . "\n";
        $shown++;
    }
}

function guard_run_check(PDO $pdo, array $check, array &$failures, bool $showRows): void
{
    $name = (string)$check['name'];
    $count = guard_fetch_count($pdo, (string)$check['count_sql']);
    $failWhenNonZero = (bool)($check['fail'] ?? true);

    guard_print_check($name, $count, $failWhenNonZero, $failures);

    if ($showRows && $count > 0 && isset($check['sample_sql'])) {
        $rows = guard_fetch_rows($pdo, (string)$check['sample_sql']);
        guard_print_rows($rows);
    }
}

$failures = [];

guard_heading('BKL-056L Transfer Model Guardrail Validation');
echo 'Generated: ' . (new DateTimeImmutable())->format(DateTimeInterface::ATOM) . "\n";
echo 'Mode: ' . ($options['showRows'] ? 'show rows' : 'summary') . "\n";

guard_subheading('Actual transfer transaction guardrails');

$actualTransferChecks = [
    [
        'name' => "transactions.type='transfer' rows missing transfer_group_id",
        'count_sql' => "
            SELECT COUNT(*)
            FROM transactions
            WHERE type = 'transfer'
              AND transfer_group_id IS NULL
        ",
        'sample_sql' => "
            SELECT id, date, account_id, amount, description, category_id, transfer_group_id
            FROM transactions
            WHERE type = 'transfer'
              AND transfer_group_id IS NULL
            ORDER BY date DESC, id DESC
            LIMIT 25
        ",
    ],
    [
        'name' => "transactions.type='transfer' rows carrying category_id",
        'count_sql' => "
            SELECT COUNT(*)
            FROM transactions
            WHERE type = 'transfer'
              AND category_id IS NOT NULL
        ",
        'sample_sql' => "
            SELECT id, date, account_id, amount, description, category_id, transfer_group_id
            FROM transactions
            WHERE type = 'transfer'
              AND category_id IS NOT NULL
            ORDER BY date DESC, id DESC
            LIMIT 25
        ",
    ],
    [
        'name' => 'grouped transaction rows carrying category_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
              AND category_id IS NOT NULL
        ",
        'sample_sql' => "
            SELECT id, date, account_id, amount, description, category_id, transfer_group_id
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
              AND category_id IS NOT NULL
            ORDER BY date DESC, id DESC
            LIMIT 25
        ",
    ],
    [
        'name' => 'transaction rows referencing legacy transfer categories',
        'count_sql' => "
            SELECT COUNT(*)
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            WHERE c.type = 'transfer'
        ",
        'sample_sql' => "
            SELECT t.id, t.date, t.account_id, t.amount, t.description, t.category_id, c.name AS category_name
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            WHERE c.type = 'transfer'
            ORDER BY t.date DESC, t.id DESC
            LIMIT 25
        ",
    ],
    [
        'name' => 'transaction split rows referencing legacy transfer categories',
        'count_sql' => "
            SELECT COUNT(*)
            FROM transaction_splits ts
            JOIN categories c ON c.id = ts.category_id
            WHERE c.type = 'transfer'
        ",
        'sample_sql' => "
            SELECT ts.id, ts.transaction_id, ts.amount, ts.category_id, c.name AS category_name
            FROM transaction_splits ts
            JOIN categories c ON c.id = ts.category_id
            WHERE c.type = 'transfer'
            ORDER BY ts.id DESC
            LIMIT 25
        ",
    ],
];

foreach ($actualTransferChecks as $check) {
    guard_run_check($pdo, $check, $failures, $options['showRows']);
}

guard_subheading('Transfer group accounting guardrails');

$transferGroupChecks = [
    [
        'name' => 'transfer groups whose transaction rows do not net to zero',
        'count_sql' => "
            SELECT COUNT(*)
            FROM (
                SELECT transfer_group_id
                FROM transactions
                WHERE transfer_group_id IS NOT NULL
                GROUP BY transfer_group_id
                HAVING ROUND(SUM(amount), 2) <> 0
            ) bad
        ",
        'sample_sql' => "
            SELECT
                transfer_group_id,
                ROUND(SUM(amount), 2) AS total_amount,
                COUNT(*) AS row_count
            FROM transactions
            WHERE transfer_group_id IS NOT NULL
            GROUP BY transfer_group_id
            HAVING ROUND(SUM(amount), 2) <> 0
            ORDER BY transfer_group_id
            LIMIT 25
        ",
    ],
    [
        'name' => 'complete transfer groups missing metadata',
        'count_sql' => "
            SELECT COUNT(*)
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'complete'
              AND EXISTS (
                  SELECT 1
                  FROM transactions t
                  WHERE t.transfer_group_id = tg.id
              )
              AND (
                  tg.from_account_id IS NULL
                  OR tg.to_account_id IS NULL
                  OR tg.expected_amount IS NULL
                  OR tg.expected_amount <= 0
                  OR tg.transfer_date IS NULL
              )
        ",
        'sample_sql' => "
            SELECT id, description, transfer_status, from_account_id, to_account_id, expected_amount, transfer_date
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'complete'
              AND EXISTS (
                  SELECT 1
                  FROM transactions t
                  WHERE t.transfer_group_id = tg.id
              )
              AND (
                  tg.from_account_id IS NULL
                  OR tg.to_account_id IS NULL
                  OR tg.expected_amount IS NULL
                  OR tg.expected_amount <= 0
                  OR tg.transfer_date IS NULL
              )
            ORDER BY id
            LIMIT 25
        ",
    ],
    [
        'name' => 'complete transfer groups with invalid row shape',
        'count_sql' => "
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
        'sample_sql' => "
            SELECT
                tg.id,
                tg.description,
                tg.transfer_status,
                x.row_count,
                x.total_amount,
                x.negative_count,
                x.positive_count
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
            ORDER BY tg.id
            LIMIT 25
        ",
    ],
    [
        'name' => 'complete transfer groups whose metadata accounts do not match transaction accounts',
        'count_sql' => "
            SELECT COUNT(*)
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'complete'
              AND EXISTS (
                  SELECT 1
                  FROM transactions t
                  WHERE t.transfer_group_id = tg.id
              )
              AND (
                  NOT EXISTS (
                      SELECT 1
                      FROM transactions t_from
                      WHERE t_from.transfer_group_id = tg.id
                        AND t_from.account_id = tg.from_account_id
                        AND t_from.amount < 0
                  )
                  OR NOT EXISTS (
                      SELECT 1
                      FROM transactions t_to
                      WHERE t_to.transfer_group_id = tg.id
                        AND t_to.account_id = tg.to_account_id
                        AND t_to.amount > 0
                  )
              )
        ",
        'sample_sql' => "
            SELECT id, description, transfer_status, from_account_id, to_account_id, expected_amount, transfer_date
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'complete'
              AND EXISTS (
                  SELECT 1
                  FROM transactions t
                  WHERE t.transfer_group_id = tg.id
              )
              AND (
                  NOT EXISTS (
                      SELECT 1
                      FROM transactions t_from
                      WHERE t_from.transfer_group_id = tg.id
                        AND t_from.account_id = tg.from_account_id
                        AND t_from.amount < 0
                  )
                  OR NOT EXISTS (
                      SELECT 1
                      FROM transactions t_to
                      WHERE t_to.transfer_group_id = tg.id
                        AND t_to.account_id = tg.to_account_id
                        AND t_to.amount > 0
                  )
              )
            ORDER BY id
            LIMIT 25
        ",
    ],
    [
        'name' => 'partial transfer groups missing metadata',
        'count_sql' => "
            SELECT COUNT(*)
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'partial'
              AND EXISTS (
                  SELECT 1
                  FROM transactions t
                  WHERE t.transfer_group_id = tg.id
              )
              AND (
                  tg.from_account_id IS NULL
                  OR tg.to_account_id IS NULL
                  OR tg.expected_amount IS NULL
                  OR tg.expected_amount <= 0
                  OR tg.transfer_date IS NULL
              )
        ",
        'sample_sql' => "
            SELECT id, description, transfer_status, from_account_id, to_account_id, expected_amount, transfer_date
            FROM transfer_groups tg
            WHERE tg.transfer_status = 'partial'
              AND EXISTS (
                  SELECT 1
                  FROM transactions t
                  WHERE t.transfer_group_id = tg.id
              )
              AND (
                  tg.from_account_id IS NULL
                  OR tg.to_account_id IS NULL
                  OR tg.expected_amount IS NULL
                  OR tg.expected_amount <= 0
                  OR tg.transfer_date IS NULL
              )
            ORDER BY id
            LIMIT 25
        ",
    ],
];

foreach ($transferGroupChecks as $check) {
    guard_run_check($pdo, $check, $failures, $options['showRows']);
}

guard_subheading('Prediction model guardrails');

$predictionChecks = [
    [
        'name' => 'transfer prediction rules carrying category_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions
            WHERE prediction_type = 'transfer'
              AND category_id IS NOT NULL
        ",
        'sample_sql' => "
            SELECT id, description, from_account_id, to_account_id, category_id, prediction_type, amount
            FROM predicted_transactions
            WHERE prediction_type = 'transfer'
              AND category_id IS NOT NULL
            ORDER BY id
            LIMIT 25
        ",
    ],
    [
        'name' => 'transfer prediction instances carrying category_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_instances
            WHERE prediction_type = 'transfer'
              AND category_id IS NOT NULL
        ",
        'sample_sql' => "
            SELECT id, scheduled_date, from_account_id, to_account_id, category_id, prediction_type, amount
            FROM predicted_instances
            WHERE prediction_type = 'transfer'
              AND category_id IS NOT NULL
            ORDER BY id
            LIMIT 25
        ",
    ],
    [
        'name' => 'transfer prediction rules missing to_account_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions
            WHERE prediction_type = 'transfer'
              AND to_account_id IS NULL
        ",
        'sample_sql' => "
            SELECT id, description, from_account_id, to_account_id, category_id, prediction_type, amount
            FROM predicted_transactions
            WHERE prediction_type = 'transfer'
              AND to_account_id IS NULL
            ORDER BY id
            LIMIT 25
        ",
    ],
    [
        'name' => 'open transfer prediction instances missing to_account_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_instances
            WHERE prediction_type = 'transfer'
              AND COALESCE(fulfilled, 0) = 0
              AND COALESCE(resolution_status, 'open') = 'open'
              AND to_account_id IS NULL
        ",
        'sample_sql' => "
            SELECT id, scheduled_date, from_account_id, to_account_id, category_id, prediction_type, amount, fulfilled, resolution_status
            FROM predicted_instances
            WHERE prediction_type = 'transfer'
              AND COALESCE(fulfilled, 0) = 0
              AND COALESCE(resolution_status, 'open') = 'open'
              AND to_account_id IS NULL
            ORDER BY scheduled_date, id
            LIMIT 25
        ",
    ],
    [
        'name' => 'income/expense prediction rules missing category_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions
            WHERE prediction_type IN ('income', 'expense')
              AND category_id IS NULL
        ",
        'sample_sql' => "
            SELECT id, description, from_account_id, to_account_id, category_id, prediction_type, amount
            FROM predicted_transactions
            WHERE prediction_type IN ('income', 'expense')
              AND category_id IS NULL
            ORDER BY id
            LIMIT 25
        ",
    ],
    [
        'name' => 'income/expense prediction instances missing category_id',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_instances
            WHERE prediction_type IN ('income', 'expense')
              AND category_id IS NULL
        ",
        'sample_sql' => "
            SELECT id, scheduled_date, from_account_id, to_account_id, category_id, prediction_type, amount
            FROM predicted_instances
            WHERE prediction_type IN ('income', 'expense')
              AND category_id IS NULL
            ORDER BY scheduled_date, id
            LIMIT 25
        ",
    ],
    [
        'name' => 'prediction rules whose prediction_type does not match category type',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_transactions pt
            JOIN categories c ON c.id = pt.category_id
            WHERE pt.prediction_type IN ('income', 'expense')
              AND pt.prediction_type <> c.type
        ",
        'sample_sql' => "
            SELECT pt.id, pt.description, pt.category_id, pt.prediction_type, c.type AS category_type, c.name AS category_name
            FROM predicted_transactions pt
            JOIN categories c ON c.id = pt.category_id
            WHERE pt.prediction_type IN ('income', 'expense')
              AND pt.prediction_type <> c.type
            ORDER BY pt.id
            LIMIT 25
        ",
    ],
    [
        'name' => 'prediction instances whose prediction_type does not match category type',
        'count_sql' => "
            SELECT COUNT(*)
            FROM predicted_instances pi
            JOIN categories c ON c.id = pi.category_id
            WHERE pi.prediction_type IN ('income', 'expense')
              AND pi.prediction_type <> c.type
        ",
        'sample_sql' => "
            SELECT pi.id, pi.scheduled_date, pi.category_id, pi.prediction_type, c.type AS category_type, c.name AS category_name
            FROM predicted_instances pi
            JOIN categories c ON c.id = pi.category_id
            WHERE pi.prediction_type IN ('income', 'expense')
              AND pi.prediction_type <> c.type
            ORDER BY pi.scheduled_date, pi.id
            LIMIT 25
        ",
    ],
    [
        'name' => 'duplicate open transfer prediction instances by date/from/to/type',
        'count_sql' => "
            SELECT COUNT(*)
            FROM (
                SELECT scheduled_date, from_account_id, to_account_id, prediction_type, COUNT(*) AS row_count
                FROM predicted_instances
                WHERE prediction_type = 'transfer'
                  AND to_account_id IS NOT NULL
                  AND COALESCE(fulfilled, 0) = 0
                  AND COALESCE(resolution_status, 'open') = 'open'
                GROUP BY scheduled_date, from_account_id, to_account_id, prediction_type
                HAVING COUNT(*) > 1
            ) dupes
        ",
        'sample_sql' => "
            SELECT scheduled_date, from_account_id, to_account_id, prediction_type, COUNT(*) AS row_count
            FROM predicted_instances
            WHERE prediction_type = 'transfer'
              AND to_account_id IS NOT NULL
              AND COALESCE(fulfilled, 0) = 0
              AND COALESCE(resolution_status, 'open') = 'open'
            GROUP BY scheduled_date, from_account_id, to_account_id, prediction_type
            HAVING COUNT(*) > 1
            ORDER BY scheduled_date, from_account_id, to_account_id
            LIMIT 25
        ",
    ],
];

foreach ($predictionChecks as $check) {
    guard_run_check($pdo, $check, $failures, $options['showRows']);
}

guard_subheading('Legacy transfer category reference guardrails');

$legacyCategoryChecks = [
    [
        'name' => 'staging transactions referencing legacy transfer categories',
        'count_sql' => "
            SELECT COUNT(*)
            FROM staging_transactions st
            JOIN categories c ON c.id = st.category_id
            WHERE c.type = 'transfer'
        ",
        'sample_sql' => "
            SELECT st.id, st.date, st.account_id, st.amount, st.description, st.category_id, c.name AS category_name
            FROM staging_transactions st
            JOIN categories c ON c.id = st.category_id
            WHERE c.type = 'transfer'
            ORDER BY st.date DESC, st.id DESC
            LIMIT 25
        ",
    ],
    [
        'name' => 'budgets referencing legacy transfer categories',
        'count_sql' => "
            SELECT COUNT(*)
            FROM budgets b
            JOIN categories c ON c.id = b.category_id
            WHERE c.type = 'transfer'
        ",
        'sample_sql' => "
            SELECT b.month_start, b.category_id, b.amount, c.name AS category_name
            FROM budgets b
            JOIN categories c ON c.id = b.category_id
            WHERE c.type = 'transfer'
            ORDER BY b.month_start DESC, b.category_id
            LIMIT 25
        ",
    ],
    [
        'name' => 'planned income events referencing legacy transfer categories',
        'count_sql' => "
            SELECT COUNT(*)
            FROM planned_income_events pie
            JOIN categories c ON c.id = pie.category_id
            WHERE c.type = 'transfer'
        ",
        'sample_sql' => "
            SELECT pie.id, pie.category_id, pie.amount, c.name AS category_name
            FROM planned_income_events pie
            JOIN categories c ON c.id = pie.category_id
            WHERE c.type = 'transfer'
            ORDER BY pie.id DESC
            LIMIT 25
        ",
    ],
];

foreach ($legacyCategoryChecks as $check) {
    guard_run_check($pdo, $check, $failures, $options['showRows']);
}

guard_subheading('Summary');

if (empty($failures)) {
    echo "BKL-056L guardrails passed.\n";
    echo "Transfer model invariants are intact.\n";
    exit(0);
}

echo "BKL-056L guardrails failed:\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}

echo "\nRe-run with --show-rows to see examples.\n";
exit(1);
