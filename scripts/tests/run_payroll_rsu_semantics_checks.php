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
require_once __DIR__ . '/../payroll_copy.php';
require_once __DIR__ . '/../payroll_reporting.php';


function rsu_test_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}


function rsu_test_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        rsu_test_fail(
            $message
        );
    }
}


function rsu_test_money(
    $actual,
    float $expected,
    string $message
): void {
    if (
        abs(
            (float)$actual
            - $expected
        ) > 0.01
    ) {
        rsu_test_fail(
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


function rsu_test_count(
    PDO $pdo,
    string $sql,
    array $params = []
): int {
    $stmt =
        $pdo->prepare(
            $sql
        );

    $stmt->execute(
        $params
    );

    return (int)$stmt->fetchColumn();
}


/*
 * --------------------------------------------------------------------------
 * Schema/view existence.
 * --------------------------------------------------------------------------
 */

rsu_test_assert(
    rsu_test_count(
        $pdo,
        "
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'payroll_line_items'
              AND column_name = 'reporting_scope'
        "
    ) === 1,
    'reporting_scope column is missing.'
);


foreach (
    [
        'payroll_payslip_reporting_line_summary',
        'payroll_payslip_reporting_summary',
    ]
    as $viewName
) {
    rsu_test_assert(
        rsu_test_count(
            $pdo,
            "
                SELECT COUNT(*)
                FROM information_schema.views
                WHERE table_schema = DATABASE()
                  AND table_name = ?
            ",
            [
                $viewName,
            ]
        ) === 1,
        "Missing reporting view {$viewName}."
    );
}


/*
 * --------------------------------------------------------------------------
 * Source-validated real RSU statement #194.
 * --------------------------------------------------------------------------
 */

$referenceLineCount =
    rsu_test_count(
        $pdo,
        "
            SELECT COUNT(*)
            FROM payroll_line_items
            WHERE payslip_id = 194
        "
    );


$referenceEquityCount =
    rsu_test_count(
        $pdo,
        "
            SELECT COUNT(*)
            FROM payroll_line_items
            WHERE payslip_id = 194
              AND reporting_scope = 'equity'
        "
    );


rsu_test_assert(
    $referenceLineCount > 0
    && $referenceLineCount === $referenceEquityCount,
    'Every source-validated #194 line must be Equity / RSU.'
);


$reference =
    $pdo->query("
        SELECT *
        FROM payroll_payslip_reporting_summary
        WHERE payslip_id = 194
        LIMIT 1
    ")->fetch(
        PDO::FETCH_ASSOC
    );


rsu_test_assert(
    $reference !== false,
    '#194 reporting summary is missing.'
);


rsu_test_money(
    $reference[
        'ordinary_compensation'
    ],
    0.00,
    '#194 ordinary compensation is incorrect.'
);


rsu_test_money(
    $reference[
        'equity_compensation'
    ],
    29777.92,
    '#194 equity compensation is incorrect.'
);


rsu_test_money(
    $reference[
        'equity_tax_ni'
    ],
    12461.03,
    '#194 equity tax / NI is incorrect.'
);


rsu_test_money(
    $reference[
        'combined_tax_ni'
    ],
    0.00,
    '#194 combined tax / NI is incorrect.'
);


rsu_test_money(
    $reference[
        'equity_tax_percentage'
    ],
    41.85,
    '#194 equity tax rate is incorrect.'
);


rsu_test_assert(
    (string)$reference[
        'reporting_mix'
    ] === 'equity_only',
    '#194 must report as Equity only.'
);


/*
 * --------------------------------------------------------------------------
 * Synthetic semantic cases.
 * Everything below is transaction-wrapped and rolled back.
 * --------------------------------------------------------------------------
 */

$beforePayslips =
    rsu_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );


$beforeLines =
    rsu_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );


$employmentId =
    (int)$pdo->query("
        SELECT employment_id
        FROM payroll_payslip_summary
        GROUP BY employment_id
        ORDER BY
            MAX(pay_date) DESC,
            employment_id
        LIMIT 1
    ")->fetchColumn();


rsu_test_assert(
    $employmentId > 0,
    'A Payroll employment is required for the test.'
);


$categories =
    payroll_write_get_categories(
        $pdo
    );


$categoryIds = [];

foreach (
    $categories
    as $category
) {
    $categoryIds[
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
        'BENEFITS',
        'ADDITIONAL EARNINGS',
        'TAXES',
        'POST-TAX DEDUCTIONS',
    ]
    as $required
) {
    rsu_test_assert(
        isset(
            $categoryIds[
                $required
            ]
        ),
        "Missing Payroll category {$required}."
    );
}


$started = false;


try {

    $pdo->beginTransaction();
    $started = true;


    /*
     * Mixed salary/equity:
     *
     * - Notional medical benefit remains Ordinary.
     * - RSU vest is Notional + Equity.
     * - PAYE is Combined because its split is unknown.
     * - Equity adjustment remains Equity.
     */

    $mixedHeader =
        payroll_write_validate_header(
            $pdo,
            [
                'employment_id' =>
                    $employmentId,

                'pay_date' =>
                    '2038-01-31',

                'tax_code' =>
                    'RSUTEST',

                'annual_salary' =>
                    '100000.00',

                'statement_total_earnings' =>
                    '',

                'statement_total_deductions' =>
                    '',

                'statement_net_pay' =>
                    '',

                'statement_amount_paid' =>
                    '',

                'payment_method' =>
                    '',
            ]
        );


    $mixedLines =
        payroll_write_validate_lines(
            $pdo,
            [
                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'BASIC PAY'
                        ],

                    'code' =>
                        'ORDINARY',

                    'description' =>
                        'Ordinary salary',

                    'amount' =>
                        '1000.00',

                    'is_notional' =>
                        '0',

                    'reporting_scope' =>
                        'ordinary',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'BENEFITS'
                        ],

                    'code' =>
                        'MEDICAL',

                    'description' =>
                        'Notional medical benefit',

                    'amount' =>
                        '50.00',

                    'is_notional' =>
                        '1',

                    'reporting_scope' =>
                        'ordinary',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'ADDITIONAL EARNINGS'
                        ],

                    'code' =>
                        'RSU',

                    'description' =>
                        'RSU vest',

                    'amount' =>
                        '500.00',

                    'is_notional' =>
                        '1',

                    'reporting_scope' =>
                        'equity',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'TAXES'
                        ],

                    'code' =>
                        'PAYE',

                    'description' =>
                        'Combined PAYE',

                    'amount' =>
                        '100.00',

                    'is_notional' =>
                        '0',

                    'reporting_scope' =>
                        'combined',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'POST-TAX DEDUCTIONS'
                        ],

                    'code' =>
                        'RSUADJ',

                    'description' =>
                        'Equity adjustment',

                    'amount' =>
                        '-200.00',

                    'is_notional' =>
                        '0',

                    'reporting_scope' =>
                        'equity',
                ],
            ],
            null
        );


    $mixedId =
        payroll_write_save_payslip(
            $pdo,
            null,
            $mixedHeader,
            $mixedLines,
            false
        );


    $mixed =
        $pdo->query("
            SELECT *
            FROM payroll_payslip_reporting_summary
            WHERE payslip_id = {$mixedId}
            LIMIT 1
        ")->fetch(
            PDO::FETCH_ASSOC
        );


    rsu_test_assert(
        $mixed !== false,
        'Mixed RSU summary is missing.'
    );


    rsu_test_money(
        $mixed[
            'ordinary_compensation'
        ],
        1050.00,
        'Mixed ordinary compensation is incorrect.'
    );


    rsu_test_money(
        $mixed[
            'ordinary_cash_compensation'
        ],
        1000.00,
        'Mixed ordinary cash compensation is incorrect.'
    );


    rsu_test_money(
        $mixed[
            'ordinary_notional_compensation'
        ],
        50.00,
        'Notional ordinary benefit is incorrect.'
    );


    rsu_test_money(
        $mixed[
            'equity_compensation'
        ],
        500.00,
        'Mixed equity compensation is incorrect.'
    );


    rsu_test_money(
        $mixed[
            'equity_notional_compensation'
        ],
        500.00,
        'Mixed notional equity compensation is incorrect.'
    );


    rsu_test_money(
        $mixed[
            'combined_tax_ni'
        ],
        100.00,
        'Mixed combined tax is incorrect.'
    );


    rsu_test_money(
        $mixed[
            'equity_other_deductions'
        ],
        -200.00,
        'Mixed equity adjustment is incorrect.'
    );


    rsu_test_assert(
        $mixed[
            'ordinary_tax_percentage'
        ] === null,
        'Ordinary rate must be suppressed when Combined tax exists.'
    );


    rsu_test_assert(
        $mixed[
            'equity_tax_percentage'
        ] === null,
        'Equity rate must be suppressed when Combined tax exists.'
    );


    /*
     * Pure Equity case with defensible tax allocation.
     */

    $equityHeader =
        $mixedHeader;

    $equityHeader[
        'pay_date'
    ] =
        '2038-02-28';


    $equityLines =
        payroll_write_validate_lines(
            $pdo,
            [
                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'ADDITIONAL EARNINGS'
                        ],

                    'code' =>
                        'RSUVEST',

                    'description' =>
                        'Equity vest',

                    'amount' =>
                        '1000.00',

                    'is_notional' =>
                        '1',

                    'reporting_scope' =>
                        'equity',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'TAXES'
                        ],

                    'code' =>
                        'RSUTAX',

                    'description' =>
                        'Equity tax',

                    'amount' =>
                        '400.00',

                    'is_notional' =>
                        '0',

                    'reporting_scope' =>
                        'equity',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'POST-TAX DEDUCTIONS'
                        ],

                    'code' =>
                        'RSUWITHHELD',

                    'description' =>
                        'Equity withholding adjustment',

                    'amount' =>
                        '-400.00',

                    'is_notional' =>
                        '0',

                    'reporting_scope' =>
                        'equity',
                ],
            ],
            null
        );


    $equityId =
        payroll_write_save_payslip(
            $pdo,
            null,
            $equityHeader,
            $equityLines,
            false
        );


    $equity =
        $pdo->query("
            SELECT *
            FROM payroll_payslip_reporting_summary
            WHERE payslip_id = {$equityId}
            LIMIT 1
        ")->fetch(
            PDO::FETCH_ASSOC
        );


    rsu_test_assert(
        $equity !== false,
        'Pure Equity summary is missing.'
    );


    rsu_test_money(
        $equity[
            'equity_compensation'
        ],
        1000.00,
        'Pure Equity compensation is incorrect.'
    );


    rsu_test_money(
        $equity[
            'equity_tax_ni'
        ],
        400.00,
        'Pure Equity tax is incorrect.'
    );


    rsu_test_money(
        $equity[
            'equity_tax_percentage'
        ],
        40.00,
        'Pure Equity tax rate is incorrect.'
    );


    rsu_test_assert(
        (string)$equity[
            'reporting_mix'
        ] === 'equity_only',
        'Pure Equity mix is incorrect.'
    );


    /*
     * Copy Payslip must preserve Reporting scope.
     */

    $copyDraft =
        payroll_copy_prepare_draft(
            $pdo,
            $equityId,
            '2038-03-31'
        );


    rsu_test_assert(
        $copyDraft !== null,
        'Equity copy draft is missing.'
    );


    foreach (
        $copyDraft[
            'lines'
        ]
        as $line
    ) {
        rsu_test_assert(
            (string)$line[
                'reporting_scope'
            ] === 'equity',
            'Copy Payslip must preserve Equity reporting scope.'
        );
    }


    /*
     * Main Payroll report:
     *
     * Ordinary tax must exclude both Equity tax and Combined tax.
     */

    $report =
        payroll_reporting_build_report(
            $pdo,
            $employmentId,
            2037
        );


    rsu_test_money(
        $report[
            'totals'
        ][
            'ordinary_compensation'
        ],
        1050.00,
        'Ordinary report compensation is incorrect.'
    );


    rsu_test_money(
        $report[
            'totals'
        ][
            'equity_compensation'
        ],
        1500.00,
        'Equity report compensation is incorrect.'
    );


    rsu_test_money(
        $report[
            'totals'
        ][
            'tax'
        ],
        0.00,
        'Ordinary report tax must exclude Equity and Combined tax.'
    );


    rsu_test_money(
        $report[
            'totals'
        ][
            'equity_tax'
        ],
        400.00,
        'Equity report tax is incorrect.'
    );


    rsu_test_money(
        $report[
            'totals'
        ][
            'combined_tax'
        ],
        100.00,
        'Combined report tax is incorrect.'
    );


    $pdo->rollBack();
    $started = false;

} catch (Throwable $e) {

    if (
        $started
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
 * Permanent data unchanged by synthetic regression.
 * --------------------------------------------------------------------------
 */

rsu_test_assert(
    rsu_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    ) === $beforePayslips,
    'Synthetic RSU payslips were not rolled back.'
);


rsu_test_assert(
    rsu_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    ) === $beforeLines,
    'Synthetic RSU lines were not rolled back.'
);


/*
 * --------------------------------------------------------------------------
 * UI/source assertions.
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


$reportSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_reporting.php'
    );


rsu_test_assert(
    $editSource !== false
    && str_contains(
        $editSource,
        'reporting_scope'
    )
    && str_contains(
        $editSource,
        'Combined / unallocated'
    ),
    'Payslip editor must expose Reporting scope.'
);


rsu_test_assert(
    $detailSource !== false
    && str_contains(
        $detailSource,
        'Equity / RSU semantics'
    )
    && str_contains(
        $detailSource,
        'Ordinary tax / NI %'
    ),
    'Payslip detail must expose separated Equity semantics.'
);


rsu_test_assert(
    $reportSource !== false
    && str_contains(
        $reportSource,
        'Equity / RSU reporting is separated'
    )
    && str_contains(
        $reportSource,
        'Ordinary tax / NI'
    ),
    'Payroll Reporting must expose separated ordinary/equity semantics.'
);


echo "Payroll RSU reporting-semantic checks passed.\n";
echo "Source #194 Equity classification: verified.\n";
echo "Notional versus Equity independence: verified.\n";
echo "Ordinary / Equity / Combined tax allocation: verified.\n";
echo "Combined-tax rate suppression: verified.\n";
echo "Pure Equity withholding rate: verified.\n";
echo "Copy Payslip scope preservation: verified.\n";
echo "Main Payroll report excludes Equity/Combined tax from Ordinary tax: verified.\n";
echo "Synthetic test data: rolled back.\n";
echo "Permanent counts unchanged: "
    . $beforePayslips
    . " payslips / "
    . $beforeLines
    . " lines.\n";

exit(0);
