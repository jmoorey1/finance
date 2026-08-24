<?php

declare(strict_types=1);

function payroll_write_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value)
        : strlen($value);
}

function payroll_write_get_categories(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            c.id,
            c.name,
            c.display_order,
            lt.name AS line_type
        FROM payroll_categories c
        JOIN payroll_line_types lt
          ON lt.id = c.line_type_id
        WHERE c.active = 1
        ORDER BY
            CASE
                WHEN lt.name = 'Pay'
                THEN 0
                ELSE 1
            END,
            c.display_order,
            c.name
    ");

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_write_get_header(
    PDO $pdo,
    int $payslipId
): ?array {
    $stmt = $pdo->prepare("
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
            payment_method
        FROM payroll_payslips
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $payslipId,
    ]);

    $row = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    return $row ?: null;
}

function payroll_write_get_lines(
    PDO $pdo,
    int $payslipId
): array {
    $stmt = $pdo->prepare("
        SELECT
            id,
            payslip_id,
            code,
            description,
            amount,
            category_id,
            is_notional
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

function payroll_write_get_new_defaults(
    PDO $pdo,
    int $employmentId
): array {
    $stmt = $pdo->prepare("
        SELECT
            tax_code,
            annual_salary,
            payment_method
        FROM payroll_payslips
        WHERE employment_id = ?
        ORDER BY
            pay_date DESC,
            id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $employmentId,
    ]);

    $row = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    return [
        'employment_id' =>
            $employmentId,

        'pay_date' =>
            date('Y-m-d'),

        'tax_code' =>
            $row['tax_code'] ?? '',

        'annual_salary' =>
            $row['annual_salary'] ?? '',

        'statement_total_earnings' =>
            '',

        'statement_total_deductions' =>
            '',

        'statement_net_pay' =>
            '',

        'statement_amount_paid' =>
            '',

        'payment_method' =>
            $row['payment_method'] ?? '',
    ];
}

function payroll_write_parse_date(
    string $value
): string {
    $value = trim(
        $value
    );

    $date =
        DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

    $errors =
        DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || (
            $errors !== false
            && (
                (int)$errors['warning_count'] > 0
                || (int)$errors['error_count'] > 0
            )
        )
        || $date->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException(
            'Pay date must be a valid date.'
        );
    }

    return $value;
}

function payroll_write_parse_optional_money(
    string $raw,
    string $label
): ?float {
    $raw = trim(
        $raw
    );

    if ($raw === '') {
        return null;
    }

    if (!is_numeric($raw)) {
        throw new RuntimeException(
            "{$label} must be a valid number."
        );
    }

    $value = round(
        (float)$raw,
        2
    );

    if (
        abs($value)
        > 9999999999.99
    ) {
        throw new RuntimeException(
            "{$label} is too large."
        );
    }

    return $value;
}

function payroll_write_validate_header(
    PDO $pdo,
    array $input
): array {
    $employmentId =
        (int)(
            $input['employment_id']
            ?? 0
        );

    if ($employmentId <= 0) {
        throw new RuntimeException(
            'Employee is required.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payroll_employments
        WHERE id = ?
    ");

    $stmt->execute([
        $employmentId,
    ]);

    if (
        (int)$stmt->fetchColumn()
        !== 1
    ) {
        throw new RuntimeException(
            'Selected employee does not exist.'
        );
    }

    $payDate =
        payroll_write_parse_date(
            (string)(
                $input['pay_date']
                ?? ''
            )
        );

    $taxCode = trim(
        (string)(
            $input['tax_code']
            ?? ''
        )
    );

    if (
        payroll_write_length(
            $taxCode
        ) > 20
    ) {
        throw new RuntimeException(
            'Tax code must be 20 characters or fewer.'
        );
    }

    $annualSalaryRaw = trim(
        (string)(
            $input['annual_salary']
            ?? ''
        )
    );

    $annualSalary = null;

    if ($annualSalaryRaw !== '') {
        if (
            !is_numeric(
                $annualSalaryRaw
            )
        ) {
            throw new RuntimeException(
                'Annual salary must be a valid number.'
            );
        }

        $annualSalary = round(
            (float)$annualSalaryRaw,
            2
        );

        if ($annualSalary < 0) {
            throw new RuntimeException(
                'Annual salary cannot be negative.'
            );
        }

        if (
            $annualSalary
            > 9999999999.99
        ) {
            throw new RuntimeException(
                'Annual salary is too large.'
            );
        }
    }

    $statementTotalEarnings =
        payroll_write_parse_optional_money(
            (string)(
                $input[
                    'statement_total_earnings'
                ]
                ?? ''
            ),
            'Statement total earnings'
        );

    $statementTotalDeductions =
        payroll_write_parse_optional_money(
            (string)(
                $input[
                    'statement_total_deductions'
                ]
                ?? ''
            ),
            'Statement total deductions'
        );

    $statementNetPay =
        payroll_write_parse_optional_money(
            (string)(
                $input[
                    'statement_net_pay'
                ]
                ?? ''
            ),
            'Statement net pay'
        );

    $statementAmountPaid =
        payroll_write_parse_optional_money(
            (string)(
                $input[
                    'statement_amount_paid'
                ]
                ?? ''
            ),
            'Statement amount paid'
        );

    $paymentMethod = trim(
        (string)(
            $input['payment_method']
            ?? ''
        )
    );

    if (
        payroll_write_length(
            $paymentMethod
        ) > 30
    ) {
        throw new RuntimeException(
            'Payment method must be 30 characters or fewer.'
        );
    }

    if (
        $statementTotalEarnings !== null
        && $statementTotalDeductions !== null
        && $statementNetPay !== null
    ) {
        $expectedNet = round(
            $statementTotalEarnings
            - $statementTotalDeductions,
            2
        );

        if (
            abs(
                $expectedNet
                - $statementNetPay
            ) > 0.001
        ) {
            throw new RuntimeException(
                'Statement net pay must equal '
                . 'statement total earnings minus '
                . 'statement total deductions.'
            );
        }
    }

    return [
        'employment_id' =>
            $employmentId,

        'pay_date' =>
            $payDate,

        'tax_code' =>
            $taxCode === ''
                ? null
                : $taxCode,

        'annual_salary' =>
            $annualSalary,

        'statement_total_earnings' =>
            $statementTotalEarnings,

        'statement_total_deductions' =>
            $statementTotalDeductions,

        'statement_net_pay' =>
            $statementNetPay,

        'statement_amount_paid' =>
            $statementAmountPaid,

        'payment_method' =>
            $paymentMethod === ''
                ? null
                : $paymentMethod,
    ];
}

function payroll_write_category_map(
    PDO $pdo
): array {
    $categories =
        payroll_write_get_categories(
            $pdo
        );

    $map = [];

    foreach (
        $categories
        as $category
    ) {
        $map[
            (int)$category['id']
        ] = $category;
    }

    return $map;
}

function payroll_write_existing_line_ids(
    PDO $pdo,
    int $payslipId
): array {
    $stmt = $pdo->prepare("
        SELECT id
        FROM payroll_line_items
        WHERE payslip_id = ?
        ORDER BY id
    ");

    $stmt->execute([
        $payslipId,
    ]);

    return array_map(
        'intval',
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        )
    );
}

function payroll_write_validate_lines(
    PDO $pdo,
    array $inputLines,
    ?int $existingPayslipId
): array {
    if ($inputLines === []) {
        throw new RuntimeException(
            'At least one payslip line is required.'
        );
    }

    $categoryMap =
        payroll_write_category_map(
            $pdo
        );

    if ($categoryMap === []) {
        throw new RuntimeException(
            'No active payroll categories are available.'
        );
    }

    $normalised = [];
    $postedExistingIds = [];
    $retainedCount = 0;

    foreach (
        $inputLines
        as $row
    ) {
        if (!is_array($row)) {
            throw new RuntimeException(
                'Invalid payslip line submission.'
            );
        }

        $lineId =
            (int)(
                $row['id']
                ?? 0
            );

        $delete =
            isset(
                $row['delete']
            )
            && (string)$row['delete']
                === '1';

        $isNotional =
            isset(
                $row['is_notional']
            )
            && (string)$row['is_notional']
                === '1';

        if ($lineId < 0) {
            throw new RuntimeException(
                'Invalid payslip line ID.'
            );
        }

        if ($lineId > 0) {
            if (
                isset(
                    $postedExistingIds[
                        $lineId
                    ]
                )
            ) {
                throw new RuntimeException(
                    'A payslip line was submitted more than once.'
                );
            }

            $postedExistingIds[
                $lineId
            ] = true;
        }

        if ($delete) {
            if ($lineId > 0) {
                $normalised[] = [
                    'id' =>
                        $lineId,

                    'delete' =>
                        true,

                    'code' =>
                        null,

                    'description' =>
                        null,

                    'amount' =>
                        null,

                    'category_id' =>
                        null,

                    'is_notional' =>
                        null,
                ];
            }

            continue;
        }

        if (
            $lineId > 0
            && $existingPayslipId === null
        ) {
            throw new RuntimeException(
                'New payslips cannot reference existing line IDs.'
            );
        }

        $categoryId =
            (int)(
                $row['category_id']
                ?? 0
            );

        if (
            !isset(
                $categoryMap[
                    $categoryId
                ]
            )
        ) {
            throw new RuntimeException(
                'Every payslip line must use an active payroll category.'
            );
        }

        $code = trim(
            (string)(
                $row['code']
                ?? ''
            )
        );

        $description = trim(
            (string)(
                $row['description']
                ?? ''
            )
        );

        $amountRaw = trim(
            (string)(
                $row['amount']
                ?? ''
            )
        );

        if ($code === '') {
            throw new RuntimeException(
                'Every payslip line requires a code.'
            );
        }

        if (
            payroll_write_length(
                $code
            ) > 50
        ) {
            throw new RuntimeException(
                'Payslip line codes must be 50 characters or fewer.'
            );
        }

        if ($description === '') {
            throw new RuntimeException(
                'Every payslip line requires a description.'
            );
        }

        if (
            payroll_write_length(
                $description
            ) > 150
        ) {
            throw new RuntimeException(
                'Payslip line descriptions must be 150 characters or fewer.'
            );
        }

        if (
            $amountRaw === ''
            || !is_numeric(
                $amountRaw
            )
        ) {
            throw new RuntimeException(
                'Every payslip line requires a valid amount.'
            );
        }

        $amount = round(
            (float)$amountRaw,
            2
        );

        if (
            abs(
                $amount
            ) > 9999999999.99
        ) {
            throw new RuntimeException(
                'A payslip line amount is too large.'
            );
        }

        $normalised[] = [
            'id' =>
                $lineId,

            'delete' =>
                false,

            'code' =>
                $code,

            'description' =>
                $description,

            'amount' =>
                $amount,

            'category_id' =>
                $categoryId,

            'is_notional' =>
                $isNotional,
        ];

        $retainedCount++;
    }

    if (
        $existingPayslipId
        !== null
    ) {
        $existingIds =
            payroll_write_existing_line_ids(
                $pdo,
                $existingPayslipId
            );

        $submittedIds = array_map(
            'intval',
            array_keys(
                $postedExistingIds
            )
        );

        sort(
            $existingIds
        );

        sort(
            $submittedIds
        );

        if (
            $existingIds
            !== $submittedIds
        ) {
            throw new RuntimeException(
                'Payslip lines changed while the form was open. '
                . 'Reload the page and try again.'
            );
        }
    }

    if ($retainedCount < 1) {
        throw new RuntimeException(
            'A payslip must contain at least one line.'
        );
    }

    return $normalised;
}

function payroll_write_save_payslip(
    PDO $pdo,
    ?int $payslipId,
    array $header,
    array $lines,
    bool $manageTransaction = true
): int {
    if (
        $manageTransaction
        && $pdo->inTransaction()
    ) {
        throw new RuntimeException(
            'Cannot start payroll save because '
            . 'a database transaction is already active.'
        );
    }

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($payslipId !== null) {
            $lock = $pdo->prepare("
                SELECT id
                FROM payroll_payslips
                WHERE id = ?
                FOR UPDATE
            ");

            $lock->execute([
                $payslipId,
            ]);

            if (
                $lock->fetchColumn()
                === false
            ) {
                throw new RuntimeException(
                    'Payslip no longer exists.'
                );
            }

            $lineLock = $pdo->prepare("
                SELECT id
                FROM payroll_line_items
                WHERE payslip_id = ?
                ORDER BY id
                FOR UPDATE
            ");

            $lineLock->execute([
                $payslipId,
            ]);

            $lockedIds = array_map(
                'intval',
                $lineLock->fetchAll(
                    PDO::FETCH_COLUMN
                )
            );

            $submittedIds = [];

            foreach (
                $lines
                as $line
            ) {
                if (
                    (int)$line['id']
                    > 0
                ) {
                    $submittedIds[] =
                        (int)$line['id'];
                }
            }

            sort(
                $lockedIds
            );

            sort(
                $submittedIds
            );

            if (
                $lockedIds
                !== $submittedIds
            ) {
                throw new RuntimeException(
                    'Payslip lines changed while the form was open. '
                    . 'Reload the page and try again.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE payroll_payslips
                SET
                    employment_id = ?,
                    pay_date = ?,
                    tax_code = ?,
                    annual_salary = ?,
                    statement_total_earnings = ?,
                    statement_total_deductions = ?,
                    statement_net_pay = ?,
                    statement_amount_paid = ?,
                    payment_method = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $header[
                    'employment_id'
                ],

                $header[
                    'pay_date'
                ],

                $header[
                    'tax_code'
                ],

                $header[
                    'annual_salary'
                ],

                $header[
                    'statement_total_earnings'
                ],

                $header[
                    'statement_total_deductions'
                ],

                $header[
                    'statement_net_pay'
                ],

                $header[
                    'statement_amount_paid'
                ],

                $header[
                    'payment_method'
                ],

                $payslipId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO payroll_payslips (
                    employment_id,
                    pay_date,
                    tax_code,
                    annual_salary,
                    statement_total_earnings,
                    statement_total_deductions,
                    statement_net_pay,
                    statement_amount_paid,
                    payment_method
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $header[
                    'employment_id'
                ],

                $header[
                    'pay_date'
                ],

                $header[
                    'tax_code'
                ],

                $header[
                    'annual_salary'
                ],

                $header[
                    'statement_total_earnings'
                ],

                $header[
                    'statement_total_deductions'
                ],

                $header[
                    'statement_net_pay'
                ],

                $header[
                    'statement_amount_paid'
                ],

                $header[
                    'payment_method'
                ],
            ]);

            $payslipId =
                (int)$pdo->lastInsertId();
        }

        foreach (
            $lines
            as $line
        ) {
            $lineId =
                (int)$line['id'];

            if ($line['delete']) {
                $stmt = $pdo->prepare("
                    DELETE FROM payroll_line_items
                    WHERE id = ?
                      AND payslip_id = ?
                ");

                $stmt->execute([
                    $lineId,
                    $payslipId,
                ]);

                if (
                    $stmt->rowCount()
                    !== 1
                ) {
                    throw new RuntimeException(
                        'Unable to delete one of the selected payslip lines.'
                    );
                }

                continue;
            }

            if ($lineId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE payroll_line_items
                    SET
                        code = ?,
                        description = ?,
                        amount = ?,
                        category_id = ?,
                        is_notional = ?
                    WHERE id = ?
                      AND payslip_id = ?
                ");

                $stmt->execute([
                    $line[
                        'code'
                    ],

                    $line[
                        'description'
                    ],

                    $line[
                        'amount'
                    ],

                    $line[
                        'category_id'
                    ],

                    $line[
                        'is_notional'
                    ]
                        ? 1
                        : 0,

                    $lineId,
                    $payslipId,
                ]);

                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO payroll_line_items (
                    payslip_id,
                    code,
                    description,
                    amount,
                    category_id,
                    is_notional
                ) VALUES (
                    ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $payslipId,

                $line[
                    'code'
                ],

                $line[
                    'description'
                ],

                $line[
                    'amount'
                ],

                $line[
                    'category_id'
                ],

                $line[
                    'is_notional'
                ]
                    ? 1
                    : 0,
            ]);
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM payroll_line_items
            WHERE payslip_id = ?
        ");

        $stmt->execute([
            $payslipId,
        ]);

        if (
            (int)$stmt->fetchColumn()
            < 1
        ) {
            throw new RuntimeException(
                'A payslip must contain at least one line.'
            );
        }

        if ($manageTransaction) {
            $pdo->commit();
        }

        return $payslipId;

    } catch (Throwable $e) {
        if (
            $manageTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
