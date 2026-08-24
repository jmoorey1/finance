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
require_once __DIR__ . '/../payroll_ui.php';
require_once __DIR__ . '/../payroll_write.php';

function payroll_semantics_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}

function payroll_semantics_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_semantics_fail(
            $message
        );
    }
}

function payroll_semantics_count(
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

function payroll_semantics_float(
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
        payroll_semantics_fail(
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

function payroll_semantics_column_exists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");

    $stmt->execute([
        $table,
        $column,
    ]);

    return (
        (int)$stmt->fetchColumn()
        === 1
    );
}

function payroll_semantics_constraint_exists(
    PDO $pdo,
    string $table,
    string $constraint
): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = ?
          AND constraint_name = ?
          AND constraint_type = 'CHECK'
    ");

    $stmt->execute([
        $table,
        $constraint,
    ]);

    return (
        (int)$stmt->fetchColumn()
        === 1
    );
}

/*
 * --------------------------------------------------------------------------
 * Schema and constraint checks
 * --------------------------------------------------------------------------
 */

foreach (
    [
        'statement_total_earnings',
        'statement_total_deductions',
        'statement_net_pay',
        'statement_amount_paid',
        'payment_method',
    ]
    as $column
) {
    payroll_semantics_assert(
        payroll_semantics_column_exists(
            $pdo,
            'payroll_payslips',
            $column
        ),
        "Missing payroll_payslips.{$column}."
    );
}

payroll_semantics_assert(
    payroll_semantics_column_exists(
        $pdo,
        'payroll_line_items',
        'is_notional'
    ),
    'Missing payroll_line_items.is_notional.'
);

payroll_semantics_assert(
    payroll_semantics_constraint_exists(
        $pdo,
        'payroll_payslips',
        'chk_payroll_payslips_statement_arithmetic'
    ),
    'Missing payslip statement arithmetic CHECK constraint.'
);

payroll_semantics_assert(
    payroll_semantics_constraint_exists(
        $pdo,
        'payroll_line_items',
        'chk_payroll_line_items_is_notional'
    ),
    'Missing notional-line CHECK constraint.'
);

/*
 * --------------------------------------------------------------------------
 * Permanent semantic invariants
 * --------------------------------------------------------------------------
 *
 * Statement fields and notional markers are now legitimate user-maintained
 * data. We therefore DO NOT assert that all historical rows remain NULL/zero.
 *
 * Instead, test the actual invariants that must remain true regardless of how
 * many source payslips are subsequently checked and enriched.
 */

/*
 * Rows where Statement Net Pay has not been captured must continue to use
 * calculated line-derived net pay exactly.
 */
$legacyFallbackRows =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_summary
        WHERE statement_net_pay IS NULL
        "
    );

payroll_semantics_assert(
    $legacyFallbackRows > 0,
    'Expected at least one legacy payslip still using calculated fallback.'
);

$legacyFallbackMismatches =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_summary
        WHERE statement_net_pay IS NULL
          AND ABS(
              net_pay
              - calculated_net_pay
          ) > 0.001
        "
    );

payroll_semantics_assert(
    $legacyFallbackMismatches === 0,
    'Legacy rows without Statement Net Pay must retain calculated-line fallback.'
);

/*
 * Any row with all three printed arithmetic totals captured must remain
 * internally consistent.
 */
$statementArithmeticMismatches =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslips
        WHERE statement_total_earnings IS NOT NULL
          AND statement_total_deductions IS NOT NULL
          AND statement_net_pay IS NOT NULL
          AND ABS(
              statement_net_pay
              - (
                  statement_total_earnings
                  - statement_total_deductions
              )
          ) > 0.001
        "
    );

payroll_semantics_assert(
    $statementArithmeticMismatches === 0,
    'Captured source-statement arithmetic is inconsistent.'
);

$invalidNotionalFlags =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_line_items
        WHERE is_notional NOT IN (0, 1)
        "
    );

payroll_semantics_assert(
    $invalidNotionalFlags === 0,
    'Notional markers must remain boolean 0/1 values.'
);

/*
 * Settlement provenance must remain deterministic.
 *
 * Amount Paid is strongest.
 * Statement Net Pay is second.
 * Calculated lines are the legacy fallback.
 */
$amountPaidSourceMismatch =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_summary
        WHERE statement_amount_paid IS NOT NULL
          AND (
              settlement_amount_source <> 'statement_amount_paid'
              OR ABS(
                  settlement_amount
                  - statement_amount_paid
              ) > 0.001
          )
        "
    );

payroll_semantics_assert(
    $amountPaidSourceMismatch === 0,
    'Settlement must prefer captured Statement Amount Paid.'
);

$statementNetSourceMismatch =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_summary
        WHERE statement_amount_paid IS NULL
          AND statement_net_pay IS NOT NULL
          AND (
              settlement_amount_source <> 'statement_net_pay'
              OR ABS(
                  settlement_amount
                  - statement_net_pay
              ) > 0.001
          )
        "
    );

payroll_semantics_assert(
    $statementNetSourceMismatch === 0,
    'Settlement must use Statement Net Pay when Amount Paid is absent.'
);

$calculatedSourceMismatch =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslip_summary
        WHERE statement_amount_paid IS NULL
          AND statement_net_pay IS NULL
          AND (
              settlement_amount_source <> 'calculated_lines'
              OR ABS(
                  settlement_amount
                  - calculated_net_pay
              ) > 0.001
          )
        "
    );

payroll_semantics_assert(
    $calculatedSourceMismatch === 0,
    'Legacy settlement fallback must use calculated lines.'
);

/*
 * --------------------------------------------------------------------------
 * Transactional synthetic regression
 * --------------------------------------------------------------------------
 */

$beforePayslips =
    payroll_semantics_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );

$beforeLines =
    payroll_semantics_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );

$employmentStmt = $pdo->query("
    SELECT
        e.id AS employment_id,
        MAX(p.pay_date) AS latest_pay_date
    FROM payroll_employments e
    JOIN payroll_payslips p
      ON p.employment_id = e.id
    GROUP BY e.id
    ORDER BY
        latest_pay_date DESC,
        e.id
    LIMIT 1
");

$employment =
    $employmentStmt->fetch(
        PDO::FETCH_ASSOC
    );

if (!$employment) {
    payroll_semantics_fail(
        'A payroll employment with at least one payslip is required.'
    );
}

$employmentId =
    (int)$employment[
        'employment_id'
    ];

$testDate =
    (string)$employment[
        'latest_pay_date'
    ];

$categories =
    payroll_write_get_categories(
        $pdo
    );

$categoryByName = [];

foreach (
    $categories
    as $category
) {
    $categoryByName[
        (string)$category['name']
    ] = (int)$category['id'];
}

foreach (
    [
        'BASIC PAY',
        'BENEFITS',
        'TAXES',
    ]
    as $requiredCategory
) {
    payroll_semantics_assert(
        isset(
            $categoryByName[
                $requiredCategory
            ]
        ),
        "Missing required payroll category: {$requiredCategory}"
    );
}

/*
 * Application-level guardrail:
 * a one-penny transcription error must be rejected.
 */
$inconsistentRejected = false;

try {
    payroll_write_validate_header(
        $pdo,
        [
            'employment_id' =>
                $employmentId,

            'pay_date' =>
                $testDate,

            'tax_code' =>
                'SEMANTIC',

            'annual_salary' =>
                '50000.00',

            'statement_total_earnings' =>
                '1000.00',

            'statement_total_deductions' =>
                '200.00',

            'statement_net_pay' =>
                '799.99',

            'statement_amount_paid' =>
                '799.99',

            'payment_method' =>
                'Bacs',
        ]
    );
} catch (RuntimeException $e) {
    $inconsistentRejected =
        str_contains(
            $e->getMessage(),
            'must equal statement total earnings'
        );
}

payroll_semantics_assert(
    $inconsistentRejected,
    'Inconsistent statement arithmetic must be rejected.'
);

$transactionStarted = false;

try {
    $pdo->beginTransaction();
    $transactionStarted = true;

    $header =
        payroll_write_validate_header(
            $pdo,
            [
                'employment_id' =>
                    $employmentId,

                'pay_date' =>
                    $testDate,

                'tax_code' =>
                    'SEMANTIC',

                'annual_salary' =>
                    '50000.00',

                'statement_total_earnings' =>
                    '1000.00',

                'statement_total_deductions' =>
                    '200.00',

                'statement_net_pay' =>
                    '800.00',

                /*
                 * Deliberately different from Net Pay to prove that
                 * Amount Paid is independently represented.
                 */
                'statement_amount_paid' =>
                    '795.00',

                'payment_method' =>
                    'Bacs',
            ]
        );

    $lines =
        payroll_write_validate_lines(
            $pdo,
            [
                [
                    'id' => 0,

                    'category_id' =>
                        $categoryByName[
                            'BASIC PAY'
                        ],

                    'code' =>
                        'SEM CASH',

                    'description' =>
                        'Semantic cash earning',

                    'amount' =>
                        '1000.00',

                    'is_notional' =>
                        '0',
                ],

                [
                    'id' => 0,

                    'category_id' =>
                        $categoryByName[
                            'BENEFITS'
                        ],

                    'code' =>
                        'SEM NOTIONAL',

                    'description' =>
                        'Semantic notional benefit',

                    'amount' =>
                        '50.00',

                    'is_notional' =>
                        '1',
                ],

                [
                    'id' => 0,

                    'category_id' =>
                        $categoryByName[
                            'TAXES'
                        ],

                    'code' =>
                        'SEM TAX',

                    'description' =>
                        'Semantic tax deduction',

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

    payroll_semantics_assert(
        $payslipId > 0,
        'Semantic test payslip must be created.'
    );

    $storedHeader =
        payroll_write_get_header(
            $pdo,
            $payslipId
        );

    payroll_semantics_assert(
        $storedHeader !== null,
        'Semantic test header must be readable.'
    );

    payroll_semantics_float(
        $storedHeader[
            'statement_amount_paid'
        ],
        795.00,
        'Statement Amount Paid was not persisted.'
    );

    payroll_semantics_assert(
        (string)$storedHeader[
            'payment_method'
        ] === 'Bacs',
        'Payment method was not persisted.'
    );

    $storedLines =
        payroll_write_get_lines(
            $pdo,
            $payslipId
        );

    payroll_semantics_assert(
        count(
            $storedLines
        ) === 3,
        'Semantic test payslip must retain all three lines.'
    );

    $notionalCount = 0;

    foreach (
        $storedLines
        as $line
    ) {
        if (
            (int)$line[
                'is_notional'
            ] === 1
        ) {
            $notionalCount++;
        }
    }

    payroll_semantics_assert(
        $notionalCount === 1,
        'Exactly one semantic test line must be notional.'
    );

    $summary =
        payroll_ui_get_payslip(
            $pdo,
            $payslipId
        );

    payroll_semantics_assert(
        $summary !== null,
        'Semantic test payslip must appear in payroll_payslip_summary.'
    );

    /*
     * Existing analytical total retains the old meaning:
     * all Pay lines, including notional Pay.
     */
    payroll_semantics_float(
        $summary[
            'total_gross'
        ],
        1050.00,
        'Legacy line-pay total changed unexpectedly.'
    );

    payroll_semantics_float(
        $summary[
            'notional_pay'
        ],
        50.00,
        'Notional pay total is incorrect.'
    );

    payroll_semantics_float(
        $summary[
            'calculated_cash_earnings'
        ],
        1000.00,
        'Calculated cash earnings must exclude notional Pay lines.'
    );

    payroll_semantics_float(
        $summary[
            'cash_earnings'
        ],
        1000.00,
        'Authoritative cash earnings are incorrect.'
    );

    payroll_semantics_float(
        $summary[
            'calculated_total_deductions'
        ],
        200.00,
        'Calculated deductions are incorrect.'
    );

    payroll_semantics_float(
        $summary[
            'total_deductions'
        ],
        200.00,
        'Statement deductions are incorrect.'
    );

    payroll_semantics_float(
        $summary[
            'calculated_net_pay'
        ],
        800.00,
        'Calculated net pay is incorrect.'
    );

    payroll_semantics_float(
        $summary[
            'net_pay'
        ],
        800.00,
        'Statement net pay must override calculated fallback.'
    );

    payroll_semantics_float(
        $summary[
            'amount_paid'
        ],
        795.00,
        'Statement Amount Paid is incorrect.'
    );

    payroll_semantics_float(
        $summary[
            'settlement_amount'
        ],
        795.00,
        'Settlement amount must prefer statement Amount Paid.'
    );

    payroll_semantics_assert(
        (string)$summary[
            'settlement_amount_source'
        ] === 'statement_amount_paid',
        'Settlement source must identify Statement Amount Paid.'
    );

    payroll_semantics_assert(
        (int)$summary[
            'notional_line_count'
        ] === 1,
        'Summary notional-line count is incorrect.'
    );

    $pdo->rollBack();
    $transactionStarted = false;

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

$afterPayslips =
    payroll_semantics_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );

$afterLines =
    payroll_semantics_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );

payroll_semantics_assert(
    $afterPayslips === $beforePayslips,
    'Semantic regression test must roll back its temporary payslip.'
);

payroll_semantics_assert(
    $afterLines === $beforeLines,
    'Semantic regression test must roll back its temporary lines.'
);

/*
 * --------------------------------------------------------------------------
 * Source/UI assertions
 * --------------------------------------------------------------------------
 */

$editSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_payslip_edit.php'
    );

$detailSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_payslip.php'
    );

payroll_semantics_assert(
    $editSource !== false
    && str_contains(
        $editSource,
        'statement_amount_paid'
    )
    && str_contains(
        $editSource,
        '[is_notional]'
    ),
    'Payslip editor must expose Amount Paid and notional-line controls.'
);

payroll_semantics_assert(
    $detailSource !== false
    && str_contains(
        $detailSource,
        "['cash_earnings']"
    )
    && str_contains(
        $detailSource,
        "['amount_paid']"
    ),
    'Payslip detail must expose semantic cash earnings and Amount Paid.'
);

$capturedStatementCount =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslips
        WHERE statement_total_earnings IS NOT NULL
           OR statement_total_deductions IS NOT NULL
           OR statement_net_pay IS NOT NULL
           OR statement_amount_paid IS NOT NULL
           OR payment_method IS NOT NULL
        "
    );

$capturedNotionalLines =
    payroll_semantics_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_line_items
        WHERE is_notional = 1
        "
    );

echo "Payroll semantic checks passed.\n";
echo "Legacy fallback semantics: verified.\n";
echo "Captured statement arithmetic: verified.\n";
echo "Settlement provenance: verified.\n";
echo "Notional Pay exclusion from cash earnings: verified.\n";
echo "Net Pay / Amount Paid separation: verified.\n";
echo "Temporary semantic payslip: rolled back.\n";
echo "Captured statement rows currently present: "
    . $capturedStatementCount
    . ".\n";
echo "Captured notional lines currently present: "
    . $capturedNotionalLines
    . ".\n";
echo "Permanent payroll row counts unchanged: "
    . $afterPayslips
    . " payslips / "
    . $afterLines
    . " lines.\n";

exit(0);
