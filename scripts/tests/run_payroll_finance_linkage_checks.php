<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(
        STDERR,
        "This script must be run from the command line.\n"
    );

    exit(1);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../payroll_write.php';
require_once __DIR__ . '/../payroll_ui.php';
require_once __DIR__ . '/../payroll_finance.php';

function payroll_finance_test_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}

function payroll_finance_test_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_finance_test_fail(
            $message
        );
    }
}

function payroll_finance_test_count(
    PDO $pdo,
    string $sql,
    array $params = []
): int {
    $stmt = $pdo->prepare(
        $sql
    );

    $stmt->execute(
        $params
    );

    return (int)$stmt->fetchColumn();
}

function payroll_finance_test_money(
    $actual,
    float $expected,
    string $message
): void {
    if (
        abs(
            (float)$actual
            - $expected
        ) > 0.001
    ) {
        payroll_finance_test_fail(
            $message
            . ' Expected '
            . number_format(
                $expected,
                2,
                '.',
                ''
            )
            . ', got '
            . number_format(
                (float)$actual,
                2,
                '.',
                ''
            )
            . '.'
        );
    }
}

/*
 * --------------------------------------------------------------------------
 * Governed object checks
 * --------------------------------------------------------------------------
 */

foreach (
    [
        'payroll_finance_mappings',
        'payroll_payslip_transaction_links',
    ]
    as $table
) {
    payroll_finance_test_assert(
        payroll_finance_test_count(
            $pdo,
            "
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND table_type = 'BASE TABLE'
            ",
            [
                $table,
            ]
        ) === 1,
        "Missing table {$table}."
    );
}

foreach (
    [
        'payroll_payslip_transaction_link_totals',
        'payroll_finance_link_status',
    ]
    as $view
) {
    payroll_finance_test_assert(
        payroll_finance_test_count(
            $pdo,
            "
            SELECT COUNT(*)
            FROM information_schema.views
            WHERE table_schema = DATABASE()
              AND table_name = ?
            ",
            [
                $view,
            ]
        ) === 1,
        "Missing view {$view}."
    );
}

/*
 * --------------------------------------------------------------------------
 * Permanent-state snapshots
 * --------------------------------------------------------------------------
 */

$beforePeople =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_people
        "
    );

$beforeEmployments =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_employments
        "
    );

$beforePayslips =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslips
        "
    );

$beforeLines =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_line_items
        "
    );

$beforeTransactions =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM transactions
        "
    );

$beforeMappings =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_finance_mappings
        "
    );

$beforeLinks =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_transaction_links
        "
    );

/*
 * Finance supporting data can come from permanent reference/config rows.
 *
 * The Payroll person/employment itself must NOT be borrowed from live data:
 * doing so makes this regression dependent on the user's real mapping and
 * existing transaction links.
 */

$account =
    $pdo->query("
        SELECT
            id,
            name
        FROM accounts
        ORDER BY
            active DESC,
            CASE
                WHEN type = 'current'
                THEN 0
                ELSE 1
            END,
            id
        LIMIT 1
    ")->fetch(
        PDO::FETCH_ASSOC
    );

if (!$account) {
    payroll_finance_test_fail(
        'A Finance account is required for regression testing.'
    );
}

$accountId =
    (int)$account[
        'id'
    ];

$incomeCategory =
    $pdo->query("
        SELECT c.id
        FROM categories c
        LEFT JOIN categories parent
          ON parent.id = c.parent_id
        WHERE COALESCE(
            c.type,
            parent.type
        ) = 'income'
        ORDER BY c.id
        LIMIT 1
    ")->fetchColumn();

$incomeCategoryId =
    $incomeCategory !== false
        ? (int)$incomeCategory
        : null;

$payrollCategories =
    payroll_write_get_categories(
        $pdo
    );

$payrollCategoryIds = [];

foreach (
    $payrollCategories
    as $category
) {
    $payrollCategoryIds[
        (string)$category[
            'name'
        ]
    ] =
        (int)$category[
            'id'
        ];
}

foreach (
    [
        'BASIC PAY',
        'TAXES',
    ]
    as $required
) {
    payroll_finance_test_assert(
        isset(
            $payrollCategoryIds[
                $required
            ]
        ),
        "Missing required Payroll category {$required}."
    );
}

$testDate =
    '2035-01-31';

$transactionStarted =
    false;

try {
    $pdo->beginTransaction();
    $transactionStarted =
        true;

    /*
     * Create a fully synthetic Payroll identity/employment.
     *
     * This is the key isolation fix: no real John/India mapping is read,
     * overwritten, or constrained by the regression test.
     */
    $stmt =
        $pdo->prepare("
            INSERT INTO payroll_people (
                full_name
            ) VALUES (
                ?
            )
        ");

    $stmt->execute([
        'Payroll Finance Link Regression Person',
    ]);

    $personId =
        (int)$pdo->lastInsertId();

    payroll_finance_test_assert(
        $personId > 0,
        'Synthetic Payroll person must be created.'
    );

    $stmt =
        $pdo->prepare("
            INSERT INTO payroll_employments (
                person_id,
                employer_name,
                employee_number,
                status
            ) VALUES (
                ?,
                'Payroll Finance Regression Employer',
                'FIN-LINK-TEST',
                'active'
            )
        ");

    $stmt->execute([
        $personId,
    ]);

    $employmentId =
        (int)$pdo->lastInsertId();

    payroll_finance_test_assert(
        $employmentId > 0,
        'Synthetic Payroll employment must be created.'
    );

    $mapping =
        payroll_finance_validate_mapping(
            $pdo,
            [
                'employment_id' =>
                    $employmentId,

                'receiving_account_id' =>
                    $accountId,

                'income_category_id' =>
                    $incomeCategoryId
                    ?? '',

                'prediction_rule_id' =>
                    '',

                'linkage_start_date' =>
                    '2020-01-01',

                'candidate_window_days' =>
                    7,
            ]
        );

    payroll_finance_save_mapping(
        $pdo,
        $mapping,
        false
    );

    $header =
        payroll_write_validate_header(
            $pdo,
            [
                'employment_id' =>
                    $employmentId,

                'pay_date' =>
                    $testDate,

                'tax_code' =>
                    'LINKTEST',

                'annual_salary' =>
                    '60000.00',

                'statement_total_earnings' =>
                    '1000.00',

                'statement_total_deductions' =>
                    '200.00',

                'statement_net_pay' =>
                    '800.00',

                'statement_amount_paid' =>
                    '800.00',

                'payment_method' =>
                    'Bacs',
            ]
        );

    $lines =
        payroll_write_validate_lines(
            $pdo,
            [
                [
                    'id' =>
                        0,

                    'category_id' =>
                        $payrollCategoryIds[
                            'BASIC PAY'
                        ],

                    'code' =>
                        'LINK BASIC',

                    'description' =>
                        'Finance linkage regression basic pay',

                    'amount' =>
                        '1000.00',

                    'is_notional' =>
                        '0',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $payrollCategoryIds[
                            'TAXES'
                        ],

                    'code' =>
                        'LINK TAX',

                    'description' =>
                        'Finance linkage regression tax',

                    'amount' =>
                        '200.00',

                    'is_notional' =>
                        '0',
                ],
            ],
            null
        );

    $payslipId =
        payroll_write_save_payslip(
            $pdo,
            null,
            $header,
            $lines,
            false
        );

    payroll_finance_test_assert(
        $payslipId > 0,
        'Synthetic payslip must be created.'
    );

    $stmt =
        $pdo->prepare("
            INSERT INTO transactions (
                account_id,
                date,
                description,
                amount,
                type,
                cleared,
                category_id,
                predicted_transaction_id,
                reconciled
            ) VALUES (
                ?,
                ?,
                ?,
                ?,
                'deposit',
                1,
                ?,
                NULL,
                0
            )
        ");

    $stmt->execute([
        $accountId,
        $testDate,
        'PAYROLL FINANCE LINK REGRESSION',
        800.00,
        $incomeCategoryId,
    ]);

    $transactionId =
        (int)$pdo->lastInsertId();

    payroll_finance_test_assert(
        $transactionId > 0,
        'Synthetic Finance transaction must be created.'
    );

    $transactionBeforeStmt =
        $pdo->prepare("
            SELECT
                account_id,
                date,
                description,
                amount,
                type,
                category_id,
                predicted_transaction_id,
                reconciled
            FROM transactions
            WHERE id = ?
        ");

    $transactionBeforeStmt->execute([
        $transactionId,
    ]);

    $transactionBefore =
        $transactionBeforeStmt->fetch(
            PDO::FETCH_ASSOC
        );

    payroll_finance_test_assert(
        $transactionBefore !== false,
        'Synthetic Finance transaction must be readable.'
    );

    $status =
        payroll_finance_get_link_status(
            $pdo,
            $payslipId
        );

    payroll_finance_test_assert(
        $status !== null,
        'Synthetic payslip must have Finance link status.'
    );

    payroll_finance_test_assert(
        (string)$status[
            'link_status'
        ] === 'unlinked',
        'New synthetic payslip must initially be unlinked.'
    );

    payroll_finance_test_money(
        $status[
            'expected_settlement_amount'
        ],
        800.00,
        'Expected settlement amount is incorrect.'
    );

    payroll_finance_test_assert(
        (string)$status[
            'expected_amount_source'
        ] === 'statement_amount_paid',
        'Amount Paid must be the preferred linkage source.'
    );

    $candidates =
        payroll_finance_get_candidate_transactions(
            $pdo,
            $payslipId
        );

    $candidate =
        null;

    foreach (
        $candidates
        as $row
    ) {
        if (
            (int)$row[
                'id'
            ]
            === $transactionId
        ) {
            $candidate =
                $row;

            break;
        }
    }

    payroll_finance_test_assert(
        $candidate !== null,
        'Exact same-day bank credit must be discovered as a candidate.'
    );

    payroll_finance_test_assert(
        (bool)$candidate[
            'same_day'
        ],
        'Synthetic candidate must be same-day.'
    );

    payroll_finance_test_assert(
        (bool)$candidate[
            'exact_amount'
        ],
        'Synthetic candidate must be an exact amount match.'
    );

    $linkId =
        payroll_finance_link_transaction(
            $pdo,
            $payslipId,
            $transactionId,
            false
        );

    payroll_finance_test_assert(
        $linkId > 0,
        'Persistent Payroll Finance link must be created.'
    );

    $status =
        payroll_finance_get_link_status(
            $pdo,
            $payslipId
        );

    payroll_finance_test_assert(
        (string)$status[
            'link_status'
        ] === 'settled',
        'Exact linked transaction must settle the synthetic payslip.'
    );

    payroll_finance_test_assert(
        (int)$status[
            'link_count'
        ] === 1,
        'Synthetic payslip must have exactly one link.'
    );

    payroll_finance_test_money(
        $status[
            'linked_amount'
        ],
        800.00,
        'Linked amount is incorrect.'
    );

    $transactionAfterStmt =
        $pdo->prepare("
            SELECT
                account_id,
                date,
                description,
                amount,
                type,
                category_id,
                predicted_transaction_id,
                reconciled
            FROM transactions
            WHERE id = ?
        ");

    $transactionAfterStmt->execute([
        $transactionId,
    ]);

    $transactionAfter =
        $transactionAfterStmt->fetch(
            PDO::FETCH_ASSOC
        );

    payroll_finance_test_assert(
        $transactionAfter
        === $transactionBefore,
        'Creating a Payroll Finance link must not alter the bank transaction.'
    );

    $duplicateBlocked =
        false;

    try {
        payroll_finance_link_transaction(
            $pdo,
            $payslipId,
            $transactionId,
            false
        );

    } catch (RuntimeException $e) {
        $duplicateBlocked =
            true;
    }

    payroll_finance_test_assert(
        $duplicateBlocked,
        'A bank transaction cannot be linked twice.'
    );

    payroll_finance_unlink_transaction(
        $pdo,
        $payslipId,
        $linkId,
        false
    );

    $status =
        payroll_finance_get_link_status(
            $pdo,
            $payslipId
        );

    payroll_finance_test_assert(
        (string)$status[
            'link_status'
        ] === 'unlinked',
        'Removing the link must return the payslip to unlinked status.'
    );

    /*
     * Explicit 2020 scope guardrail.
     */
    $stmt =
        $pdo->prepare("
            UPDATE payroll_payslips
            SET pay_date = '2019-12-31'
            WHERE id = ?
        ");

    $stmt->execute([
        $payslipId,
    ]);

    $status =
        payroll_finance_get_link_status(
            $pdo,
            $payslipId
        );

    payroll_finance_test_assert(
        (string)$status[
            'link_status'
        ] === 'out_of_scope',
        'A pre-2020 payslip must remain outside Finance linkage scope.'
    );

    payroll_finance_test_assert(
        payroll_finance_get_candidate_transactions(
            $pdo,
            $payslipId
        ) === [],
        'Out-of-scope payslips must not expose transaction candidates.'
    );

    $pdo->rollBack();
    $transactionStarted =
        false;

} catch (Throwable $e) {
    if (
        $transactionStarted
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    fwrite(
        STDERR,
        'FAIL: '
        . $e->getMessage()
        . "\n"
    );

    exit(1);
}

/*
 * --------------------------------------------------------------------------
 * Rollback validation
 * --------------------------------------------------------------------------
 */

$afterPeople =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_people
        "
    );

$afterEmployments =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_employments
        "
    );

$afterPayslips =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslips
        "
    );

$afterLines =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_line_items
        "
    );

$afterTransactions =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM transactions
        "
    );

$afterMappings =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_finance_mappings
        "
    );

$afterLinks =
    payroll_finance_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_transaction_links
        "
    );

payroll_finance_test_assert(
    $afterPeople
    === $beforePeople,
    'Regression test must roll back its synthetic Payroll person.'
);

payroll_finance_test_assert(
    $afterEmployments
    === $beforeEmployments,
    'Regression test must roll back its synthetic Payroll employment.'
);

payroll_finance_test_assert(
    $afterPayslips
    === $beforePayslips,
    'Regression test must roll back its synthetic payslip.'
);

payroll_finance_test_assert(
    $afterLines
    === $beforeLines,
    'Regression test must roll back its synthetic payroll lines.'
);

payroll_finance_test_assert(
    $afterTransactions
    === $beforeTransactions,
    'Regression test must roll back its synthetic transaction.'
);

payroll_finance_test_assert(
    $afterMappings
    === $beforeMappings,
    'Regression test must roll back its temporary Finance mapping.'
);

payroll_finance_test_assert(
    $afterLinks
    === $beforeLinks,
    'Regression test must roll back its temporary Payroll Finance link.'
);

/*
 * --------------------------------------------------------------------------
 * UI/source assertions
 * --------------------------------------------------------------------------
 */

$detailSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_payslip.php'
    );

$settingsSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_finance_settings.php'
    );

payroll_finance_test_assert(
    $detailSource !== false
    && str_contains(
        $detailSource,
        'Finance linkage'
    )
    && str_contains(
        $detailSource,
        'payroll_finance_action.php'
    ),
    'Payslip detail must expose Finance linkage UI.'
);

payroll_finance_test_assert(
    $settingsSource !== false
    && str_contains(
        $settingsSource,
        '2020-01-01'
    ),
    'Finance settings UI must preserve the 2020 linkage boundary.'
);

echo "Payroll Finance linkage checks passed.\n";
echo "Synthetic employment isolation: verified.\n";
echo "Employment Finance mapping: verified.\n";
echo "Exact same-day candidate discovery: verified.\n";
echo "Persistent link / unlink path: verified.\n";
echo "Bank transaction immutability: verified.\n";
echo "Duplicate transaction-link guardrail: verified.\n";
echo "Pre-2020 scope exclusion: verified.\n";
echo "Synthetic test data: rolled back.\n";
echo "Permanent counts unchanged: "
    . $afterPayslips
    . " payslips / "
    . $afterLines
    . " payroll lines / "
    . $afterTransactions
    . " bank transactions.\n";
echo "Persistent real mappings currently present: "
    . $afterMappings
    . ".\n";
echo "Persistent real Payroll Finance links currently present: "
    . $afterLinks
    . ".\n";

exit(0);
