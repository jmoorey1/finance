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

function payroll_copy_test_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}

function payroll_copy_test_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_copy_test_fail(
            $message
        );
    }
}

function payroll_copy_test_count(
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

function payroll_copy_test_money(
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
        payroll_copy_test_fail(
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

function payroll_copy_test_get_header_snapshot(
    PDO $pdo,
    int $payslipId
): array {
    $stmt =
        $pdo->prepare("
            SELECT
                id,
                employment_id,
                pay_date,
                tax_code,
                annual_salary,
                statement_total_earnings,
                statement_total_deductions,
                statement_net_pay,
                statement_amount_paid,
                payment_method,
                legacy_payslip_id
            FROM payroll_payslips
            WHERE id = ?
            LIMIT 1
        ");

    $stmt->execute([
        $payslipId,
    ]);

    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$row) {
        payroll_copy_test_fail(
            'Unable to read payslip snapshot.'
        );
    }

    return $row;
}

function payroll_copy_test_get_line_snapshot(
    PDO $pdo,
    int $payslipId
): array {
    $stmt =
        $pdo->prepare("
            SELECT
                id,
                payslip_id,
                code,
                description,
                amount,
                category_id,
                is_notional,
                legacy_line_item_id
            FROM payroll_line_items
            WHERE payslip_id = ?
            ORDER BY id
        ");

    $stmt->execute([
        $payslipId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

$beforePayslips =
    payroll_copy_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );

$beforeLines =
    payroll_copy_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );

$employment =
    $pdo->query("
        SELECT
            e.id AS employment_id
        FROM payroll_employments e
        JOIN payroll_payslips p
          ON p.employment_id = e.id
        GROUP BY e.id
        ORDER BY
            MAX(p.pay_date) DESC,
            e.id
        LIMIT 1
    ")->fetch(
        PDO::FETCH_ASSOC
    );

if (!$employment) {
    payroll_copy_test_fail(
        'A Payroll employment is required for Copy Payslip testing.'
    );
}

$employmentId =
    (int)$employment[
        'employment_id'
    ];

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
        'TAXES',
    ]
    as $required
) {
    payroll_copy_test_assert(
        isset(
            $categoryIds[
                $required
            ]
        ),
        "Missing required Payroll category {$required}."
    );
}

$transactionStarted = false;

try {
    $pdo->beginTransaction();
    $transactionStarted = true;

    /*
     * ----------------------------------------------------------------------
     * Create a synthetic source payslip containing every important copy
     * semantic, including source statement totals and a Notional line.
     * ----------------------------------------------------------------------
     */

    $sourceHeader =
        payroll_write_validate_header(
            $pdo,
            [
                'employment_id' =>
                    $employmentId,

                'pay_date' =>
                    '2037-01-31',

                'tax_code' =>
                    'COPYTEST',

                'annual_salary' =>
                    '90000.00',

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

    $sourceLines =
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
                        'COPY BASIC',

                    'description' =>
                        'Copy regression basic pay',

                    'amount' =>
                        '1000.00',

                    'is_notional' =>
                        '0',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'BENEFITS'
                        ],

                    'code' =>
                        'COPY NOTIONAL',

                    'description' =>
                        'Copy regression notional benefit',

                    'amount' =>
                        '50.00',

                    'is_notional' =>
                        '1',
                ],

                [
                    'id' =>
                        0,

                    'category_id' =>
                        $categoryIds[
                            'TAXES'
                        ],

                    'code' =>
                        'COPY TAX',

                    'description' =>
                        'Copy regression tax',

                    'amount' =>
                        '200.00',

                    'is_notional' =>
                        '0',
                ],
            ],
            null
        );

    $sourcePayslipId =
        payroll_write_save_payslip(
            $pdo,
            null,
            $sourceHeader,
            $sourceLines,
            false
        );

    payroll_copy_test_assert(
        $sourcePayslipId > 0,
        'Synthetic source payslip must be created.'
    );

    $sourceHeaderBefore =
        payroll_copy_test_get_header_snapshot(
            $pdo,
            $sourcePayslipId
        );

    $sourceLinesBefore =
        payroll_copy_test_get_line_snapshot(
            $pdo,
            $sourcePayslipId
        );

    payroll_copy_test_assert(
        count(
            $sourceLinesBefore
        ) === 3,
        'Synthetic source payslip must contain three lines.'
    );

    /*
     * ----------------------------------------------------------------------
     * Default copy must use today's date and must have no source line IDs.
     * ----------------------------------------------------------------------
     */

    $todayDraft =
        payroll_copy_prepare_draft(
            $pdo,
            $sourcePayslipId
        );

    payroll_copy_test_assert(
        $todayDraft !== null,
        'Copy helper must return a draft for an existing payslip.'
    );

    payroll_copy_test_assert(
        (string)$todayDraft[
            'copy_date'
        ] === date('Y-m-d'),
        'Default copied pay date must be today.'
    );

    payroll_copy_test_assert(
        (string)$todayDraft[
            'header'
        ][
            'pay_date'
        ] === date('Y-m-d'),
        'Copied form header must use today as its initial pay date.'
    );

    payroll_copy_test_assert(
        (int)$todayDraft[
            'source_payslip_id'
        ] === $sourcePayslipId,
        'Copy draft must identify the source payslip in memory.'
    );

    payroll_copy_test_assert(
        (int)$todayDraft[
            'source_employment_id'
        ] === $employmentId,
        'Copied payslip must retain the source employment.'
    );

    payroll_copy_test_assert(
        count(
            $todayDraft[
                'lines'
            ]
        ) === count(
            $sourceLinesBefore
        ),
        'Copied draft must contain every source line.'
    );

    foreach (
        $todayDraft[
            'lines'
        ]
        as $index => $copiedLine
    ) {
        $sourceLine =
            $sourceLinesBefore[
                $index
            ];

        payroll_copy_test_assert(
            (int)$copiedLine[
                'id'
            ] === 0,
            'Copied line IDs must be reset to zero.'
        );

        payroll_copy_test_assert(
            (int)$copiedLine[
                'category_id'
            ] === (int)$sourceLine[
                'category_id'
            ],
            'Copied category differs from source.'
        );

        payroll_copy_test_assert(
            (string)$copiedLine[
                'code'
            ] === (string)$sourceLine[
                'code'
            ],
            'Copied line code differs from source.'
        );

        payroll_copy_test_assert(
            (string)$copiedLine[
                'description'
            ] === (string)$sourceLine[
                'description'
            ],
            'Copied line description differs from source.'
        );

        payroll_copy_test_money(
            $copiedLine[
                'amount'
            ],
            (float)$sourceLine[
                'amount'
            ],
            'Copied line amount differs from source.'
        );

        payroll_copy_test_assert(
            (int)$copiedLine[
                'is_notional'
            ] === (int)$sourceLine[
                'is_notional'
            ],
            'Copied Notional flag differs from source.'
        );
    }

    foreach (
        [
            'employment_id',
            'tax_code',
            'annual_salary',
            'statement_total_earnings',
            'statement_total_deductions',
            'statement_net_pay',
            'statement_amount_paid',
            'payment_method',
        ]
        as $field
    ) {
        $sourceValue =
            $sourceHeaderBefore[
                $field
            ];

        $draftValue =
            $todayDraft[
                'header'
            ][
                $field
            ];

        payroll_copy_test_assert(
            (string)(
                $sourceValue
                ?? ''
            )
            === (string)(
                $draftValue
                ?? ''
            ),
            "Copied header field {$field} differs from source."
        );
    }

    /*
     * ----------------------------------------------------------------------
     * Build another draft using a deterministic future date, alter it as a
     * user would before Save, then create a genuinely new payslip.
     * ----------------------------------------------------------------------
     */

    $draft =
        payroll_copy_prepare_draft(
            $pdo,
            $sourcePayslipId,
            '2037-02-28'
        );

    payroll_copy_test_assert(
        $draft !== null,
        'Deterministic Copy Payslip draft must be created.'
    );

    $draft[
        'header'
    ][
        'tax_code'
    ] =
        'COPYEDIT';

    $draft[
        'header'
    ][
        'statement_total_earnings'
    ] =
        '1100.00';

    $draft[
        'header'
    ][
        'statement_total_deductions'
    ] =
        '200.00';

    $draft[
        'header'
    ][
        'statement_net_pay'
    ] =
        '900.00';

    $draft[
        'header'
    ][
        'statement_amount_paid'
    ] =
        '900.00';

    $draft[
        'lines'
    ][
        0
    ][
        'amount'
    ] =
        '1100.00';

    $draft[
        'lines'
    ][
        0
    ][
        'description'
    ] =
        'Copied and edited basic pay';

    $validatedCopyHeader =
        payroll_write_validate_header(
            $pdo,
            $draft[
                'header'
            ]
        );

    $validatedCopyLines =
        payroll_write_validate_lines(
            $pdo,
            $draft[
                'lines'
            ],
            null
        );

    $copiedPayslipId =
        payroll_write_save_payslip(
            $pdo,
            null,
            $validatedCopyHeader,
            $validatedCopyLines,
            false
        );

    payroll_copy_test_assert(
        $copiedPayslipId > 0,
        'Copied payslip must be saved.'
    );

    payroll_copy_test_assert(
        $copiedPayslipId !== $sourcePayslipId,
        'Copy operation must create a different payslip ID.'
    );

    $copyHeaderAfterCreate =
        payroll_copy_test_get_header_snapshot(
            $pdo,
            $copiedPayslipId
        );

    $copyLinesAfterCreate =
        payroll_copy_test_get_line_snapshot(
            $pdo,
            $copiedPayslipId
        );

    payroll_copy_test_assert(
        (string)$copyHeaderAfterCreate[
            'pay_date'
        ] === '2037-02-28',
        'User-edited copied pay date must be saved.'
    );

    payroll_copy_test_assert(
        (string)$copyHeaderAfterCreate[
            'tax_code'
        ] === 'COPYEDIT',
        'User-edited copied tax code must be saved.'
    );

    payroll_copy_test_money(
        $copyHeaderAfterCreate[
            'statement_amount_paid'
        ],
        900.00,
        'User-edited Amount Paid must be saved.'
    );

    payroll_copy_test_assert(
        count(
            $copyLinesAfterCreate
        ) === 3,
        'Copied payslip must contain all three copied lines.'
    );

    $sourceLineIds =
        array_map(
            static fn (
                array $row
            ): int =>
                (int)$row[
                    'id'
                ],
            $sourceLinesBefore
        );

    $copyLineIds =
        array_map(
            static fn (
                array $row
            ): int =>
                (int)$row[
                    'id'
                ],
            $copyLinesAfterCreate
        );

    payroll_copy_test_assert(
        array_intersect(
            $sourceLineIds,
            $copyLineIds
        ) === [],
        'Copied payslip must receive entirely new line IDs.'
    );

    foreach (
        $copyLinesAfterCreate
        as $line
    ) {
        payroll_copy_test_assert(
            $line[
                'legacy_line_item_id'
            ] === null,
            'Copied lines must not inherit legacy source IDs.'
        );
    }

    payroll_copy_test_assert(
        $copyHeaderAfterCreate[
            'legacy_payslip_id'
        ] === null,
        'Copied payslip must not inherit the source legacy payslip ID.'
    );

    $notionalRows =
        array_values(
            array_filter(
                $copyLinesAfterCreate,
                static fn (
                    array $row
                ): bool =>
                    (int)$row[
                        'is_notional'
                    ] === 1
            )
        );

    payroll_copy_test_assert(
        count(
            $notionalRows
        ) === 1,
        'Copied payslip must preserve its Notional line.'
    );

    payroll_copy_test_assert(
        (string)$notionalRows[
            0
        ][
            'code'
        ] === 'COPY NOTIONAL',
        'Copied Notional line identity is incorrect.'
    );

    /*
     * ----------------------------------------------------------------------
     * The source must be byte-for-byte unchanged after creating the copy.
     * ----------------------------------------------------------------------
     */

    $sourceHeaderAfterCopy =
        payroll_copy_test_get_header_snapshot(
            $pdo,
            $sourcePayslipId
        );

    $sourceLinesAfterCopy =
        payroll_copy_test_get_line_snapshot(
            $pdo,
            $sourcePayslipId
        );

    payroll_copy_test_assert(
        $sourceHeaderAfterCopy
        === $sourceHeaderBefore,
        'Creating a copy altered the source payslip header.'
    );

    payroll_copy_test_assert(
        $sourceLinesAfterCopy
        === $sourceLinesBefore,
        'Creating a copy altered the source payslip lines.'
    );

    /*
     * ----------------------------------------------------------------------
     * Edit the copied payslip afterwards and prove again that the source
     * remains independent.
     * ----------------------------------------------------------------------
     */

    $copyHeaderForEdit = [
        'employment_id' =>
            (string)$copyHeaderAfterCreate[
                'employment_id'
            ],

        'pay_date' =>
            (string)$copyHeaderAfterCreate[
                'pay_date'
            ],

        'tax_code' =>
            (string)(
                $copyHeaderAfterCreate[
                    'tax_code'
                ]
                ?? ''
            ),

        'annual_salary' =>
            (string)(
                $copyHeaderAfterCreate[
                    'annual_salary'
                ]
                ?? ''
            ),

        'statement_total_earnings' =>
            '1200.00',

        'statement_total_deductions' =>
            '200.00',

        'statement_net_pay' =>
            '1000.00',

        'statement_amount_paid' =>
            '1000.00',

        'payment_method' =>
            (string)(
                $copyHeaderAfterCreate[
                    'payment_method'
                ]
                ?? ''
            ),
    ];

    $copyLinesForEdit =
        payroll_write_get_lines(
            $pdo,
            $copiedPayslipId
        );

    $copyLinesForEdit[
        0
    ][
        'amount'
    ] =
        '1200.00';

    $copyLinesForEdit[
        0
    ][
        'description'
    ] =
        'Edited again after copy save';

    $validatedEditedHeader =
        payroll_write_validate_header(
            $pdo,
            $copyHeaderForEdit
        );

    $validatedEditedLines =
        payroll_write_validate_lines(
            $pdo,
            $copyLinesForEdit,
            $copiedPayslipId
        );

    payroll_write_save_payslip(
        $pdo,
        $copiedPayslipId,
        $validatedEditedHeader,
        $validatedEditedLines,
        false
    );

    $sourceHeaderAfterCopyEdit =
        payroll_copy_test_get_header_snapshot(
            $pdo,
            $sourcePayslipId
        );

    $sourceLinesAfterCopyEdit =
        payroll_copy_test_get_line_snapshot(
            $pdo,
            $sourcePayslipId
        );

    payroll_copy_test_assert(
        $sourceHeaderAfterCopyEdit
        === $sourceHeaderBefore,
        'Editing the copied payslip altered the source header.'
    );

    payroll_copy_test_assert(
        $sourceLinesAfterCopyEdit
        === $sourceLinesBefore,
        'Editing the copied payslip altered the source lines.'
    );

    /*
     * Missing source must produce no draft.
     */
    payroll_copy_test_assert(
        payroll_copy_prepare_draft(
            $pdo,
            2147483647
        ) === null,
        'Missing source payslip must not produce a copy draft.'
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
    payroll_copy_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );

$afterLines =
    payroll_copy_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );

payroll_copy_test_assert(
    $afterPayslips
    === $beforePayslips,
    'Synthetic source/copy payslips must be rolled back.'
);

payroll_copy_test_assert(
    $afterLines
    === $beforeLines,
    'Synthetic source/copy lines must be rolled back.'
);

$detailSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_payslip.php'
    );

$editSource =
    file_get_contents(
        __DIR__
        . '/../../public/payroll_payslip_edit.php'
    );

payroll_copy_test_assert(
    $detailSource !== false
    && str_contains(
        $detailSource,
        'payroll_payslip_edit.php?id='
    )
    && str_contains(
        $detailSource,
        'Edit payslip'
    )
    && str_contains(
        $detailSource,
        'copy_from='
    )
    && str_contains(
        $detailSource,
        'Copy payslip'
    ),
    'Payslip detail must expose Edit and Copy Payslip actions.'
);

payroll_copy_test_assert(
    $editSource !== false
    && str_contains(
        $editSource,
        'payroll_copy_prepare_draft'
    )
    && str_contains(
        $editSource,
        'copy_source_payslip_id'
    )
    && str_contains(
        $editSource,
        'Creating a new payslip copied from'
    ),
    'Add/Edit Payslip page must expose Copy mode semantics.'
);

echo "Payroll Copy Payslip checks passed.\n";
echo "Today's date default: verified.\n";
echo "Header field copying: verified.\n";
echo "Statement/settlement copying: verified.\n";
echo "All line-item fields copied: verified.\n";
echo "Notional line copying: verified.\n";
echo "Copied source line IDs reset: verified.\n";
echo "New payslip and new line IDs: verified.\n";
echo "Legacy IDs not inherited: verified.\n";
echo "User edits before Save: verified.\n";
echo "Subsequent copied-payslip edits: verified.\n";
echo "Source payslip immutability: verified.\n";
echo "Missing-source handling: verified.\n";
echo "Synthetic copy data: rolled back.\n";
echo "Permanent Payroll row counts unchanged: "
    . $afterPayslips
    . " payslips / "
    . $afterLines
    . " lines.\n";

exit(0);
