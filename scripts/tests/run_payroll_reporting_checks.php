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
require_once __DIR__ . '/../reporting_periods.php';
require_once __DIR__ . '/../payroll_reporting.php';

function payroll_reporting_test_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}

function payroll_reporting_test_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_reporting_test_fail(
            $message
        );
    }
}

function payroll_reporting_test_money(
    $actual,
    $expected,
    string $message
): void {
    if (
        abs(
            (float)$actual
            - (float)$expected
        ) > 0.01
    ) {
        payroll_reporting_test_fail(
            $message
            . ' Expected '
            . number_format(
                (float)$expected,
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

payroll_reporting_test_assert(
    reporting_period_basis_label(
        REPORTING_PERIOD_BASIS_PAYE
    ) === 'PAYE tax period',
    'PAYE reporting period basis must be explicit.'
);

payroll_reporting_test_assert(
    reporting_paye_tax_year_label(
        2026
    ) === '2026/27',
    'PAYE tax-year label is incorrect.'
);

$employment =
    $pdo->query("
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
    ")->fetch(
        PDO::FETCH_ASSOC
    );

if (!$employment) {
    payroll_reporting_test_fail(
        'At least one Payroll employment with payslips is required.'
    );
}

$employmentId =
    (int)$employment[
        'employment_id'
    ];

$report =
    payroll_reporting_build_report(
        $pdo,
        $employmentId
    );

payroll_reporting_test_assert(
    $report[
        'period_basis'
    ] === REPORTING_PERIOD_BASIS_PAYE,
    'Payroll report must use PAYE period semantics.'
);

payroll_reporting_test_assert(
    $report[
        'tax_year_start'
    ] !== null,
    'Payroll report must resolve a tax year.'
);

payroll_reporting_test_assert(
    $report[
        'payslips'
    ] !== [],
    'Payroll report must contain payslips.'
);

$taxYearStart =
    (int)$report[
        'tax_year_start'
    ];

$expectedTaxYear =
    reporting_paye_tax_year_label(
        $taxYearStart
    );

payroll_reporting_test_assert(
    $report[
        'tax_year'
    ] === $expectedTaxYear,
    'Payroll report tax-year label is incorrect.'
);

$directStmt =
    $pdo->prepare("
        SELECT
            COUNT(*) AS payslip_count,
            ROUND(
                COALESCE(
                    SUM(cash_earnings),
                    0
                ),
                2
            ) AS cash_earnings,
            ROUND(
                COALESCE(
                    SUM(net_pay),
                    0
                ),
                2
            ) AS payroll_net,
            ROUND(
                COALESCE(
                    SUM(taxes),
                    0
                ),
                2
            ) AS tax,
            ROUND(
                COALESCE(
                    SUM(pension),
                    0
                ),
                2
            ) AS pension,
            ROUND(
                COALESCE(
                    SUM(bonus),
                    0
                ),
                2
            ) AS bonus,
            ROUND(
                COALESCE(
                    SUM(notional_pay),
                    0
                ),
                2
            ) AS notional_pay
        FROM payroll_payslip_summary
        WHERE employment_id = ?
          AND tax_year_start = ?
    ");

$directStmt->execute([
    $employmentId,
    $taxYearStart,
]);

$direct =
    $directStmt->fetch(
        PDO::FETCH_ASSOC
    );

payroll_reporting_test_assert(
    $direct !== false,
    'Direct Payroll total validation query failed.'
);

payroll_reporting_test_assert(
    (int)$report[
        'totals'
    ][
        'payslip_count'
    ]
    === (int)$direct[
        'payslip_count'
    ],
    'Report payslip count differs from direct SQL.'
);

foreach (
    [
        'cash_earnings',
        'payroll_net',
        'tax',
        'pension',
        'bonus',
        'notional_pay',
    ]
    as $metric
) {
    payroll_reporting_test_money(
        $report[
            'totals'
        ][
            $metric
        ],
        $direct[
            $metric
        ],
        'Report total differs from direct SQL for '
        . $metric
        . '.'
    );
}

$financeStmt =
    $pdo->prepare("
        SELECT
            ROUND(
                COALESCE(
                    SUM(finance.linked_amount),
                    0
                ),
                2
            ) AS bank_settled,
            SUM(
                CASE
                    WHEN finance.link_status = 'settled'
                    THEN 1
                    ELSE 0
                END
            ) AS settled_count,
            SUM(
                CASE
                    WHEN finance.link_status IN (
                        'unlinked',
                        'partial',
                        'overlinked',
                        'no_settlement'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS unsettled_count,
            SUM(
                CASE
                    WHEN finance.link_status = 'out_of_scope'
                    THEN 1
                    ELSE 0
                END
            ) AS out_of_scope_count

        FROM payroll_finance_link_status finance

        JOIN payroll_payslip_summary ps
          ON ps.payslip_id = finance.payslip_id

        WHERE finance.employment_id = ?
          AND ps.tax_year_start = ?
    ");

$financeStmt->execute([
    $employmentId,
    $taxYearStart,
]);

$finance =
    $financeStmt->fetch(
        PDO::FETCH_ASSOC
    );

payroll_reporting_test_assert(
    $finance !== false,
    'Direct Finance settlement validation query failed.'
);

payroll_reporting_test_money(
    $report[
        'totals'
    ][
        'bank_settled'
    ],
    $finance[
        'bank_settled'
    ],
    'Bank-settled report total differs from direct SQL.'
);

payroll_reporting_test_assert(
    (int)$report[
        'totals'
    ][
        'finance_settled_count'
    ]
    === (int)$finance[
        'settled_count'
    ],
    'Settled payslip count differs from direct SQL.'
);

payroll_reporting_test_assert(
    (int)$report[
        'totals'
    ][
        'finance_unsettled_count'
    ]
    === (int)$finance[
        'unsettled_count'
    ],
    'Unsettled payslip count differs from direct SQL.'
);

payroll_reporting_test_assert(
    (int)$report[
        'totals'
    ][
        'finance_out_of_scope_count'
    ]
    === (int)$finance[
        'out_of_scope_count'
    ],
    'Out-of-scope payslip count differs from direct SQL.'
);

$monthPayslipCount =
    0;

$monthCash =
    0.0;

$monthNet =
    0.0;

$monthBank =
    0.0;

$previousTaxMonth =
    0;

foreach (
    $report[
        'months'
    ]
    as $month
) {
    $taxMonth =
        (int)$month[
            'tax_month'
        ];

    payroll_reporting_test_assert(
        $taxMonth > $previousTaxMonth,
        'Tax-month rows must be strictly ordered.'
    );

    $previousTaxMonth =
        $taxMonth;

    $monthPayslipCount +=
        (int)$month[
            'totals'
        ][
            'payslip_count'
        ];

    $monthCash +=
        (float)$month[
            'totals'
        ][
            'cash_earnings'
        ];

    $monthNet +=
        (float)$month[
            'totals'
        ][
            'payroll_net'
        ];

    $monthBank +=
        (float)$month[
            'totals'
        ][
            'bank_settled'
        ];
}

payroll_reporting_test_assert(
    $monthPayslipCount
    === (int)$report[
        'totals'
    ][
        'payslip_count'
    ],
    'Tax-month payslip counts do not reconcile to report total.'
);

payroll_reporting_test_money(
    $monthCash,
    $report[
        'totals'
    ][
        'cash_earnings'
    ],
    'Tax-month cash earnings do not reconcile.'
);

payroll_reporting_test_money(
    $monthNet,
    $report[
        'totals'
    ][
        'payroll_net'
    ],
    'Tax-month Payroll net does not reconcile.'
);

payroll_reporting_test_money(
    $monthBank,
    $report[
        'totals'
    ][
        'bank_settled'
    ],
    'Tax-month bank settlement does not reconcile.'
);

payroll_reporting_test_assert(
    $report[
        'through_tax_month'
    ] !== null,
    'Report must expose the represented YTD tax month.'
);

$priorTaxYearStart =
    $taxYearStart - 1;

$throughTaxMonth =
    (int)$report[
        'through_tax_month'
    ];

$priorDirectStmt =
    $pdo->prepare("
        SELECT
            ROUND(
                COALESCE(
                    SUM(net_pay),
                    0
                ),
                2
            )
        FROM payroll_payslip_summary
        WHERE employment_id = ?
          AND tax_year_start = ?
          AND tax_month <= ?
    ");

$priorDirectStmt->execute([
    $employmentId,
    $priorTaxYearStart,
    $throughTaxMonth,
]);

$priorNet =
    $priorDirectStmt->fetchColumn();

payroll_reporting_test_money(
    $report[
        'prior_ytd_totals'
    ][
        'payroll_net'
    ],
    $priorNet,
    'Prior-year YTD Payroll net alignment is incorrect.'
);

payroll_reporting_test_assert(
    count(
        $report[
            'ytd_comparison'
        ]
    ) === 6,
    'YTD comparison must expose the expected six measures.'
);

$salaryHistory =
    $report[
        'salary_history'
    ];

$previousSalary =
    null;

foreach (
    $salaryHistory
    as $salaryRow
) {
    $salary =
        (float)$salaryRow[
            'annual_salary'
        ];

    if ($previousSalary !== null) {
        payroll_reporting_test_assert(
            abs(
                $salary
                - $previousSalary
            ) > 0.005,
            'Salary history must collapse consecutive duplicate salaries.'
        );
    }

    $previousSalary =
        $salary;
}

$pageSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_reporting.php'
    );

$payrollSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll.php'
    );

payroll_reporting_test_assert(
    $pageSource !== false
    && str_contains(
        $pageSource,
        'Payroll Reporting'
    )
    && str_contains(
        $pageSource,
        'payrollSettlementChart'
    )
    && str_contains(
        $pageSource,
        'payrollDeductionsChart'
    )
    && str_contains(
        $pageSource,
        'payrollSalaryChart'
    )
    && str_contains(
        $pageSource,
        'payroll_payslip.php?id='
    ),
    'Payroll Reporting page must expose charts and payslip drill-through.'
);

payroll_reporting_test_assert(
    $payrollSource !== false
    && str_contains(
        $payrollSource,
        'payroll_reporting.php'
    ),
    'Main Payroll page must link to Payroll Reporting.'
);

echo "Payroll Reporting checks passed.\n";
echo "Explicit PAYE period basis: verified.\n";
echo "Payroll totals vs direct SQL: verified.\n";
echo "Finance settlement totals vs direct SQL: verified.\n";
echo "Tax-month rollup reconciliation: verified.\n";
echo "Prior-YTD tax-month alignment: verified.\n";
echo "Salary-change compression: verified.\n";
echo "Reporting charts and payslip drill-through: verified.\n";
echo "Employment checked: #"
    . $employmentId
    . ".\n";
echo "Tax year checked: "
    . $report[
        'tax_year'
    ]
    . ".\n";
echo "Payslips represented: "
    . (int)$report[
        'totals'
    ][
        'payslip_count'
    ]
    . ".\n";
echo "Settled Finance links represented: "
    . (int)$report[
        'totals'
    ][
        'finance_settled_count'
    ]
    . ".\n";
echo "In-scope unsettled payslips represented: "
    . (int)$report[
        'totals'
    ][
        'finance_unsettled_count'
    ]
    . ".\n";

exit(0);
