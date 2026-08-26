<?php

declare(strict_types=1);

require_once __DIR__ . '/payroll_write.php';

/**
 * Build a form-ready NEW payslip draft from an existing payslip.
 *
 * No database writes occur here.
 *
 * The source payslip remains independent from the eventual new payslip:
 * existing line IDs are deliberately discarded, and no copy provenance is
 * persisted in the Payroll database.
 */
function payroll_copy_prepare_draft(
    PDO $pdo,
    int $sourcePayslipId,
    ?string $copyDate = null
): ?array {
    if ($sourcePayslipId <= 0) {
        return null;
    }

    $header =
        payroll_write_get_header(
            $pdo,
            $sourcePayslipId
        );

    if ($header === null) {
        return null;
    }

    $sourceLines =
        payroll_write_get_lines(
            $pdo,
            $sourcePayslipId
        );

    if ($sourceLines === []) {
        throw new RuntimeException(
            'The source payslip has no line items to copy.'
        );
    }

    $resolvedCopyDate =
        payroll_write_parse_date(
            $copyDate
            ?? date('Y-m-d')
        );

    $formHeader = [
        'employment_id' =>
            (string)$header[
                'employment_id'
            ],

        'pay_date' =>
            $resolvedCopyDate,

        'tax_code' =>
            (string)(
                $header[
                    'tax_code'
                ]
                ?? ''
            ),

        'annual_salary' =>
            $header[
                'annual_salary'
            ] !== null
                ? (string)$header[
                    'annual_salary'
                ]
                : '',

        'statement_total_earnings' =>
            $header[
                'statement_total_earnings'
            ] !== null
                ? (string)$header[
                    'statement_total_earnings'
                ]
                : '',

        'statement_total_deductions' =>
            $header[
                'statement_total_deductions'
            ] !== null
                ? (string)$header[
                    'statement_total_deductions'
                ]
                : '',

        'statement_net_pay' =>
            $header[
                'statement_net_pay'
            ] !== null
                ? (string)$header[
                    'statement_net_pay'
                ]
                : '',

        'statement_amount_paid' =>
            $header[
                'statement_amount_paid'
            ] !== null
                ? (string)$header[
                    'statement_amount_paid'
                ]
                : '',

        'payment_method' =>
            (string)(
                $header[
                    'payment_method'
                ]
                ?? ''
            ),
    ];

    $formLines = [];

    foreach (
        $sourceLines
        as $line
    ) {
        /*
         * ID=0 is the critical copy boundary:
         * saving this draft must INSERT new line rows rather than update
         * the source payslip's line rows.
         */
        $formLines[] = [
            'id' =>
                0,

            'category_id' =>
                (string)$line[
                    'category_id'
                ],

            'code' =>
                (string)$line[
                    'code'
                ],

            'description' =>
                (string)$line[
                    'description'
                ],

            'amount' =>
                (string)$line[
                    'amount'
                ],

            'is_notional' =>
                (int)$line[
                    'is_notional'
                ] === 1
                    ? '1'
                    : '0',
        ];
    }

    return [
        'source_payslip_id' =>
            $sourcePayslipId,

        'source_employment_id' =>
            (int)$header[
                'employment_id'
            ],

        'source_pay_date' =>
            (string)$header[
                'pay_date'
            ],

        'copy_date' =>
            $resolvedCopyDate,

        'header' =>
            $formHeader,

        'lines' =>
            $formLines,
    ];
}
