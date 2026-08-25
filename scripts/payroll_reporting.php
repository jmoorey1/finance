<?php

declare(strict_types=1);

require_once __DIR__ . '/reporting_periods.php';

function payroll_reporting_get_tax_year_options(
    PDO $pdo,
    int $employmentId
): array {
    $stmt =
        $pdo->prepare("
            SELECT
                tax_year_start,
                tax_year,
                COUNT(*) AS payslip_count,
                MIN(pay_date) AS first_pay_date,
                MAX(pay_date) AS latest_pay_date
            FROM payroll_payslip_summary
            WHERE employment_id = ?
            GROUP BY
                tax_year_start,
                tax_year
            ORDER BY
                tax_year_start DESC
        ");

    $stmt->execute([
        $employmentId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_reporting_resolve_tax_year(
    array $options,
    ?int $requestedTaxYearStart
): ?int {
    if (
        $requestedTaxYearStart !== null
        && $requestedTaxYearStart > 0
    ) {
        foreach (
            $options
            as $option
        ) {
            if (
                (int)$option[
                    'tax_year_start'
                ]
                === $requestedTaxYearStart
            ) {
                return $requestedTaxYearStart;
            }
        }
    }

    if ($options === []) {
        return null;
    }

    return (int)$options[
        0
    ][
        'tax_year_start'
    ];
}

function payroll_reporting_get_payslip_rows(
    PDO $pdo,
    int $employmentId,
    int $taxYearStart
): array {
    $stmt =
        $pdo->prepare("
            SELECT
                ps.payslip_id,
                ps.employment_id,
                ps.person_id,
                ps.person_name,
                ps.pay_date,
                ps.tax_year_start,
                ps.tax_year,
                ps.tax_month,
                ps.tax_code,
                ps.annual_salary,

                ps.statement_total_earnings,
                ps.statement_total_deductions,
                ps.statement_net_pay,
                ps.statement_amount_paid,
                ps.payment_method,

                ps.basic_pay,
                ps.benefits,
                ps.pre_tax_deductions,
                ps.additional_earnings,
                ps.bonus,
                ps.pension,
                ps.taxes,
                ps.post_tax_deductions,

                ps.total_gross,
                ps.notional_pay,
                ps.calculated_cash_earnings,
                ps.cash_earnings,
                ps.calculated_total_deductions,
                ps.total_deductions,
                ps.calculated_net_pay,
                ps.net_pay,
                ps.amount_paid,
                ps.settlement_amount,
                ps.settlement_amount_source,
                ps.line_item_count,
                ps.notional_line_count,

                finance.receiving_account_id,
                finance.receiving_account_name,
                finance.linkage_start_date,
                finance.expected_settlement_amount,
                finance.expected_amount_source,
                finance.link_count,
                finance.linked_amount,
                finance.first_transaction_date,
                finance.last_transaction_date,
                finance.link_status

            FROM payroll_payslip_summary ps

            LEFT JOIN payroll_finance_link_status finance
              ON finance.payslip_id = ps.payslip_id

            WHERE ps.employment_id = ?
              AND ps.tax_year_start = ?

            ORDER BY
                ps.tax_month,
                ps.pay_date,
                ps.payslip_id
        ");

    $stmt->execute([
        $employmentId,
        $taxYearStart,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_reporting_empty_totals(): array
{
    return [
        'payslip_count' =>
            0,

        'cash_earnings' =>
            0.0,

        'payroll_net' =>
            0.0,

        'tax' =>
            0.0,

        'pension' =>
            0.0,

        'bonus' =>
            0.0,

        'notional_pay' =>
            0.0,

        'bank_settled' =>
            0.0,

        'finance_settled_count' =>
            0,

        'finance_unsettled_count' =>
            0,

        'finance_out_of_scope_count' =>
            0,

        'finance_unconfigured_count' =>
            0,

        'statement_amount_paid_count' =>
            0,

        'calculated_settlement_count' =>
            0,
    ];
}

function payroll_reporting_status_is_unsettled(
    ?string $status
): bool {
    return in_array(
        $status,
        [
            'unlinked',
            'partial',
            'overlinked',
            'no_settlement',
        ],
        true
    );
}

function payroll_reporting_add_row_to_totals(
    array &$totals,
    array $row
): void {
    $totals[
        'payslip_count'
    ]++;

    $totals[
        'cash_earnings'
    ] +=
        (float)$row[
            'cash_earnings'
        ];

    $totals[
        'payroll_net'
    ] +=
        (float)$row[
            'net_pay'
        ];

    $totals[
        'tax'
    ] +=
        (float)$row[
            'taxes'
        ];

    $totals[
        'pension'
    ] +=
        (float)$row[
            'pension'
        ];

    $totals[
        'bonus'
    ] +=
        (float)$row[
            'bonus'
        ];

    $totals[
        'notional_pay'
    ] +=
        (float)$row[
            'notional_pay'
        ];

    $totals[
        'bank_settled'
    ] +=
        (float)(
            $row[
                'linked_amount'
            ]
            ?? 0
        );

    $status =
        $row[
            'link_status'
        ] !== null
            ? (string)$row[
                'link_status'
            ]
            : 'unconfigured';

    if ($status === 'settled') {
        $totals[
            'finance_settled_count'
        ]++;

    } elseif (
        payroll_reporting_status_is_unsettled(
            $status
        )
    ) {
        $totals[
            'finance_unsettled_count'
        ]++;

    } elseif ($status === 'out_of_scope') {
        $totals[
            'finance_out_of_scope_count'
        ]++;

    } elseif (
        $status === 'unconfigured'
    ) {
        $totals[
            'finance_unconfigured_count'
        ]++;
    }

    if (
        $row[
            'statement_amount_paid'
        ] !== null
    ) {
        $totals[
            'statement_amount_paid_count'
        ]++;
    }

    if (
        (string)$row[
            'settlement_amount_source'
        ] === 'calculated_lines'
    ) {
        $totals[
            'calculated_settlement_count'
        ]++;
    }
}

function payroll_reporting_round_totals(
    array $totals
): array {
    foreach (
        [
            'cash_earnings',
            'payroll_net',
            'tax',
            'pension',
            'bonus',
            'notional_pay',
            'bank_settled',
        ]
        as $moneyKey
    ) {
        $totals[
            $moneyKey
        ] =
            round(
                (float)$totals[
                    $moneyKey
                ],
                2
            );
    }

    return $totals;
}

function payroll_reporting_build_month_rows(
    array $payslips
): array {
    $months = [];

    foreach (
        $payslips
        as $row
    ) {
        $taxMonth =
            (int)$row[
                'tax_month'
            ];

        if (
            !isset(
                $months[
                    $taxMonth
                ]
            )
        ) {
            $months[
                $taxMonth
            ] = [
                'tax_month' =>
                    $taxMonth,

                'label' =>
                    'M'
                    . $taxMonth,

                'long_label' =>
                    reporting_paye_tax_month_label(
                        $taxMonth
                    ),

                'first_pay_date' =>
                    (string)$row[
                        'pay_date'
                    ],

                'latest_pay_date' =>
                    (string)$row[
                        'pay_date'
                    ],

                'latest_payslip_id' =>
                    (int)$row[
                        'payslip_id'
                    ],

                'annual_salary' =>
                    $row[
                        'annual_salary'
                    ] !== null
                        ? (float)$row[
                            'annual_salary'
                        ]
                        : null,

                'totals' =>
                    payroll_reporting_empty_totals(),
            ];
        }

        $month =&
            $months[
                $taxMonth
            ];

        if (
            (string)$row[
                'pay_date'
            ]
            < $month[
                'first_pay_date'
            ]
        ) {
            $month[
                'first_pay_date'
            ] =
                (string)$row[
                    'pay_date'
                ];
        }

        if (
            (string)$row[
                'pay_date'
            ]
            > $month[
                'latest_pay_date'
            ]
            || (
                (string)$row[
                    'pay_date'
                ]
                === $month[
                    'latest_pay_date'
                ]
                && (int)$row[
                    'payslip_id'
                ]
                > (int)$month[
                    'latest_payslip_id'
                ]
            )
        ) {
            $month[
                'latest_pay_date'
            ] =
                (string)$row[
                    'pay_date'
                ];

            $month[
                'latest_payslip_id'
            ] =
                (int)$row[
                    'payslip_id'
                ];

            $month[
                'annual_salary'
            ] =
                $row[
                    'annual_salary'
                ] !== null
                    ? (float)$row[
                        'annual_salary'
                    ]
                    : null;
        }

        payroll_reporting_add_row_to_totals(
            $month[
                'totals'
            ],
            $row
        );

        unset(
            $month
        );
    }

    ksort(
        $months
    );

    foreach (
        $months
        as &$month
    ) {
        $month[
            'totals'
        ] =
            payroll_reporting_round_totals(
                $month[
                    'totals'
                ]
            );
    }

    unset(
        $month
    );

    return array_values(
        $months
    );
}

function payroll_reporting_build_totals(
    array $payslips
): array {
    $totals =
        payroll_reporting_empty_totals();

    foreach (
        $payslips
        as $row
    ) {
        payroll_reporting_add_row_to_totals(
            $totals,
            $row
        );
    }

    return payroll_reporting_round_totals(
        $totals
    );
}

function payroll_reporting_get_prior_ytd_rows(
    PDO $pdo,
    int $employmentId,
    int $taxYearStart,
    int $throughTaxMonth
): array {
    $stmt =
        $pdo->prepare("
            SELECT
                ps.*,

                finance.link_count,
                finance.linked_amount,
                finance.link_status

            FROM payroll_payslip_summary ps

            LEFT JOIN payroll_finance_link_status finance
              ON finance.payslip_id = ps.payslip_id

            WHERE ps.employment_id = ?
              AND ps.tax_year_start = ?
              AND ps.tax_month <= ?

            ORDER BY
                ps.tax_month,
                ps.pay_date,
                ps.payslip_id
        ");

    $stmt->execute([
        $employmentId,
        $taxYearStart - 1,
        $throughTaxMonth,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_reporting_percentage_change(
    float $current,
    float $previous
): ?float {
    if (
        abs(
            $previous
        ) < 0.005
    ) {
        return null;
    }

    return round(
        (
            (
                $current
                - $previous
            )
            / abs(
                $previous
            )
        )
        * 100,
        1
    );
}

function payroll_reporting_build_ytd_comparison(
    array $currentTotals,
    array $priorTotals
): array {
    $metrics = [
        'cash_earnings' =>
            'Cash earnings',

        'payroll_net' =>
            'Payroll net',

        'tax' =>
            'Tax',

        'pension' =>
            'Pension',

        'bonus' =>
            'Bonus',

        'bank_settled' =>
            'Bank settled',
    ];

    $rows = [];

    foreach (
        $metrics
        as $key => $label
    ) {
        $current =
            (float)$currentTotals[
                $key
            ];

        $previous =
            (float)$priorTotals[
                $key
            ];

        $rows[] = [
            'key' =>
                $key,

            'label' =>
                $label,

            'current' =>
                round(
                    $current,
                    2
                ),

            'previous' =>
                round(
                    $previous,
                    2
                ),

            'change_pct' =>
                payroll_reporting_percentage_change(
                    $current,
                    $previous
                ),
        ];
    }

    return $rows;
}

function payroll_reporting_get_salary_history(
    PDO $pdo,
    int $employmentId,
    int $throughTaxYearStart
): array {
    $stmt =
        $pdo->prepare("
            SELECT
                id AS payslip_id,
                pay_date,
                tax_year_start,
                tax_month,
                annual_salary
            FROM payroll_payslips
            WHERE employment_id = ?
              AND annual_salary IS NOT NULL
              AND tax_year_start <= ?
            ORDER BY
                pay_date,
                id
        ");

    $stmt->execute([
        $employmentId,
        $throughTaxYearStart,
    ]);

    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    $history = [];
    $previousSalary = null;

    foreach (
        $rows
        as $row
    ) {
        $salary =
            round(
                (float)$row[
                    'annual_salary'
                ],
                2
            );

        if (
            $previousSalary !== null
            && abs(
                $salary
                - $previousSalary
            ) < 0.005
        ) {
            continue;
        }

        $history[] = [
            'payslip_id' =>
                (int)$row[
                    'payslip_id'
                ],

            'pay_date' =>
                (string)$row[
                    'pay_date'
                ],

            'tax_year_start' =>
                (int)$row[
                    'tax_year_start'
                ],

            'tax_month' =>
                (int)$row[
                    'tax_month'
                ],

            'annual_salary' =>
                $salary,
        ];

        $previousSalary =
            $salary;
    }

    return $history;
}

function payroll_reporting_build_quality(
    array $totals
): array {
    $warnings = [];

    if (
        (int)$totals[
            'finance_unsettled_count'
        ] > 0
    ) {
        $warnings[] =
            (int)$totals[
                'finance_unsettled_count'
            ]
            . ' in-scope payslip(s) are not fully settled '
            . 'to a Finance transaction.';
    }

    if (
        (int)$totals[
            'finance_unconfigured_count'
        ] > 0
    ) {
        $warnings[] =
            (int)$totals[
                'finance_unconfigured_count'
            ]
            . ' payslip(s) belong to an employment without '
            . 'Finance linkage configuration.';
    }

    if (
        (int)$totals[
            'calculated_settlement_count'
        ] > 0
    ) {
        $warnings[] =
            (int)$totals[
                'calculated_settlement_count'
            ]
            . ' payslip(s) still rely on line-derived settlement '
            . 'rather than a captured source Amount Paid.';
    }

    return [
        'has_warning' =>
            $warnings !== [],

        'warnings' =>
            $warnings,

        'settled_count' =>
            (int)$totals[
                'finance_settled_count'
            ],

        'unsettled_count' =>
            (int)$totals[
                'finance_unsettled_count'
            ],

        'out_of_scope_count' =>
            (int)$totals[
                'finance_out_of_scope_count'
            ],

        'unconfigured_count' =>
            (int)$totals[
                'finance_unconfigured_count'
            ],
    ];
}

function payroll_reporting_build_report(
    PDO $pdo,
    int $employmentId,
    ?int $requestedTaxYearStart = null
): array {
    if ($employmentId <= 0) {
        throw new InvalidArgumentException(
            'Employment ID must be positive.'
        );
    }

    $taxYearOptions =
        payroll_reporting_get_tax_year_options(
            $pdo,
            $employmentId
        );

    $taxYearStart =
        payroll_reporting_resolve_tax_year(
            $taxYearOptions,
            $requestedTaxYearStart
        );

    if ($taxYearStart === null) {
        return [
            'period_basis' =>
                REPORTING_PERIOD_BASIS_PAYE,

            'period_basis_label' =>
                reporting_period_basis_label(
                    REPORTING_PERIOD_BASIS_PAYE
                ),

            'employment_id' =>
                $employmentId,

            'tax_year_start' =>
                null,

            'tax_year' =>
                null,

            'tax_year_options' =>
                $taxYearOptions,

            'payslips' =>
                [],

            'months' =>
                [],

            'totals' =>
                payroll_reporting_empty_totals(),

            'through_tax_month' =>
                null,

            'prior_tax_year_start' =>
                null,

            'prior_tax_year' =>
                null,

            'prior_ytd_totals' =>
                payroll_reporting_empty_totals(),

            'ytd_comparison' =>
                [],

            'salary_history' =>
                [],

            'quality' =>
                payroll_reporting_build_quality(
                    payroll_reporting_empty_totals()
                ),
        ];
    }

    $payslips =
        payroll_reporting_get_payslip_rows(
            $pdo,
            $employmentId,
            $taxYearStart
        );

    $months =
        payroll_reporting_build_month_rows(
            $payslips
        );

    $totals =
        payroll_reporting_build_totals(
            $payslips
        );

    $throughTaxMonth =
        $months !== []
            ? max(
                array_column(
                    $months,
                    'tax_month'
                )
            )
            : null;

    $priorRows =
        $throughTaxMonth !== null
            ? payroll_reporting_get_prior_ytd_rows(
                $pdo,
                $employmentId,
                $taxYearStart,
                $throughTaxMonth
            )
            : [];

    $priorTotals =
        payroll_reporting_build_totals(
            $priorRows
        );

    return [
        'period_basis' =>
            REPORTING_PERIOD_BASIS_PAYE,

        'period_basis_label' =>
            reporting_period_basis_label(
                REPORTING_PERIOD_BASIS_PAYE
            ),

        'employment_id' =>
            $employmentId,

        'tax_year_start' =>
            $taxYearStart,

        'tax_year' =>
            reporting_paye_tax_year_label(
                $taxYearStart
            ),

        'tax_year_options' =>
            $taxYearOptions,

        'payslips' =>
            $payslips,

        'months' =>
            $months,

        'totals' =>
            $totals,

        'through_tax_month' =>
            $throughTaxMonth,

        'prior_tax_year_start' =>
            $taxYearStart - 1,

        'prior_tax_year' =>
            reporting_paye_tax_year_label(
                $taxYearStart - 1
            ),

        'prior_ytd_totals' =>
            $priorTotals,

        'ytd_comparison' =>
            payroll_reporting_build_ytd_comparison(
                $totals,
                $priorTotals
            ),

        'salary_history' =>
            payroll_reporting_get_salary_history(
                $pdo,
                $employmentId,
                $taxYearStart
            ),

        'quality' =>
            payroll_reporting_build_quality(
                $totals
            ),
    ];
}
