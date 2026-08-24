<?php

declare(strict_types=1);

function payroll_finance_get_mapping(
    PDO $pdo,
    int $employmentId
): ?array {
    $stmt = $pdo->prepare("
        SELECT
            fm.employment_id,
            fm.receiving_account_id,
            account.name AS receiving_account_name,
            account.type AS receiving_account_type,
            account.active AS receiving_account_active,

            fm.income_category_id,

            CASE
                WHEN category.id IS NULL
                    THEN NULL

                WHEN parent.id IS NULL
                    THEN category.name

                ELSE CONCAT(
                    parent.name,
                    ' : ',
                    category.name
                )
            END AS income_category_label,

            fm.prediction_rule_id,
            prediction.description AS prediction_rule_description,

            fm.linkage_start_date,
            fm.candidate_window_days,
            fm.created_at,
            fm.updated_at

        FROM payroll_finance_mappings fm

        JOIN accounts account
          ON account.id = fm.receiving_account_id

        LEFT JOIN categories category
          ON category.id = fm.income_category_id

        LEFT JOIN categories parent
          ON parent.id = category.parent_id

        LEFT JOIN predicted_transactions prediction
          ON prediction.id = fm.prediction_rule_id

        WHERE fm.employment_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $employmentId,
    ]);

    $row = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    return $row ?: null;
}

function payroll_finance_get_accounts(
    PDO $pdo
): array {
    $stmt = $pdo->query("
        SELECT
            id,
            name,
            type,
            active
        FROM accounts
        ORDER BY
            active DESC,
            name,
            id
    ");

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_finance_get_income_categories(
    PDO $pdo
): array {
    $stmt = $pdo->query("
        SELECT
            c.id,
            c.name,
            c.parent_id,
            parent.name AS parent_name,
            COALESCE(
                c.type,
                parent.type
            ) AS effective_type
        FROM categories c
        LEFT JOIN categories parent
          ON parent.id = c.parent_id
        WHERE COALESCE(
            c.type,
            parent.type
        ) = 'income'
        ORDER BY
            COALESCE(
                parent.name,
                c.name
            ),
            c.parent_id IS NOT NULL,
            c.name,
            c.id
    ");

    $rows = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($rows as &$row) {
        $row['label'] =
            $row['parent_name']
            ? (
                $row['parent_name']
                . ' : '
                . $row['name']
            )
            : $row['name'];
    }

    unset($row);

    return $rows;
}

function payroll_finance_get_income_prediction_rules(
    PDO $pdo
): array {
    $stmt = $pdo->query("
        SELECT
            id,
            description,
            amount,
            variable,
            active
        FROM predicted_transactions
        WHERE prediction_type = 'income'
        ORDER BY
            active DESC,
            description,
            id
    ");

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_finance_validate_mapping(
    PDO $pdo,
    array $input
): array {
    $employmentId =
        (int)(
            $input['employment_id']
            ?? 0
        );

    $receivingAccountId =
        (int)(
            $input['receiving_account_id']
            ?? 0
        );

    $incomeCategoryRaw =
        trim(
            (string)(
                $input['income_category_id']
                ?? ''
            )
        );

    $predictionRuleRaw =
        trim(
            (string)(
                $input['prediction_rule_id']
                ?? ''
            )
        );

    $linkageStartDate =
        trim(
            (string)(
                $input['linkage_start_date']
                ?? '2020-01-01'
            )
        );

    $candidateWindowDays =
        (int)(
            $input['candidate_window_days']
            ?? 7
        );

    if ($employmentId <= 0) {
        throw new RuntimeException(
            'Payroll employment is required.'
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
            'Selected payroll employment does not exist.'
        );
    }

    if ($receivingAccountId <= 0) {
        throw new RuntimeException(
            'Receiving account is required.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM accounts
        WHERE id = ?
    ");

    $stmt->execute([
        $receivingAccountId,
    ]);

    if (
        (int)$stmt->fetchColumn()
        !== 1
    ) {
        throw new RuntimeException(
            'Selected receiving account does not exist.'
        );
    }

    $incomeCategoryId = null;

    if ($incomeCategoryRaw !== '') {
        $incomeCategoryId =
            (int)$incomeCategoryRaw;

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(
                    c.type,
                    parent.type
                ) AS effective_type
            FROM categories c
            LEFT JOIN categories parent
              ON parent.id = c.parent_id
            WHERE c.id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $incomeCategoryId,
        ]);

        $effectiveType =
            $stmt->fetchColumn();

        if ($effectiveType !== 'income') {
            throw new RuntimeException(
                'Selected Finance category must be an income category.'
            );
        }
    }

    $predictionRuleId = null;

    if ($predictionRuleRaw !== '') {
        $predictionRuleId =
            (int)$predictionRuleRaw;

        $stmt = $pdo->prepare("
            SELECT prediction_type
            FROM predicted_transactions
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $predictionRuleId,
        ]);

        $predictionType =
            $stmt->fetchColumn();

        if ($predictionType !== 'income') {
            throw new RuntimeException(
                'Selected recurring prediction rule must be an income rule.'
            );
        }
    }

    $date =
        DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $linkageStartDate
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
        || $date->format('Y-m-d')
            !== $linkageStartDate
    ) {
        throw new RuntimeException(
            'Linkage start date must be a valid date.'
        );
    }

    if (
        $linkageStartDate
        < '2020-01-01'
    ) {
        throw new RuntimeException(
            'Payroll ↔ Finance linkage cannot begin before 1 January 2020.'
        );
    }

    if (
        $candidateWindowDays < 0
        || $candidateWindowDays > 31
    ) {
        throw new RuntimeException(
            'Candidate window must be between 0 and 31 days.'
        );
    }

    /*
     * Existing persistent links must remain compatible with a mapping edit.
     */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payroll_payslip_transaction_links link_row
        JOIN payroll_payslips payslip
          ON payslip.id = link_row.payslip_id
        JOIN transactions tx
          ON tx.id = link_row.transaction_id
        WHERE payslip.employment_id = ?
          AND tx.account_id <> ?
    ");

    $stmt->execute([
        $employmentId,
        $receivingAccountId,
    ]);

    if (
        (int)$stmt->fetchColumn()
        > 0
    ) {
        throw new RuntimeException(
            'The receiving account cannot be changed because '
            . 'existing Payroll links use a different account.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT MIN(payslip.pay_date)
        FROM payroll_payslip_transaction_links link_row
        JOIN payroll_payslips payslip
          ON payslip.id = link_row.payslip_id
        WHERE payslip.employment_id = ?
    ");

    $stmt->execute([
        $employmentId,
    ]);

    $earliestLinkedDate =
        $stmt->fetchColumn();

    if (
        is_string(
            $earliestLinkedDate
        )
        && $earliestLinkedDate !== ''
        && $linkageStartDate
            > $earliestLinkedDate
    ) {
        throw new RuntimeException(
            'Linkage start date cannot move later than an '
            . 'existing linked payslip.'
        );
    }

    return [
        'employment_id' =>
            $employmentId,

        'receiving_account_id' =>
            $receivingAccountId,

        'income_category_id' =>
            $incomeCategoryId,

        'prediction_rule_id' =>
            $predictionRuleId,

        'linkage_start_date' =>
            $linkageStartDate,

        'candidate_window_days' =>
            $candidateWindowDays,
    ];
}

function payroll_finance_save_mapping(
    PDO $pdo,
    array $mapping,
    bool $manageTransaction = true
): void {
    if (
        $manageTransaction
        && $pdo->inTransaction()
    ) {
        throw new RuntimeException(
            'Cannot save Payroll Finance mapping because '
            . 'a database transaction is already active.'
        );
    }

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO payroll_finance_mappings (
                employment_id,
                receiving_account_id,
                income_category_id,
                prediction_rule_id,
                linkage_start_date,
                candidate_window_days
            ) VALUES (
                ?, ?, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                receiving_account_id =
                    VALUES(receiving_account_id),

                income_category_id =
                    VALUES(income_category_id),

                prediction_rule_id =
                    VALUES(prediction_rule_id),

                linkage_start_date =
                    VALUES(linkage_start_date),

                candidate_window_days =
                    VALUES(candidate_window_days)
        ");

        $stmt->execute([
            $mapping[
                'employment_id'
            ],

            $mapping[
                'receiving_account_id'
            ],

            $mapping[
                'income_category_id'
            ],

            $mapping[
                'prediction_rule_id'
            ],

            $mapping[
                'linkage_start_date'
            ],

            $mapping[
                'candidate_window_days'
            ],
        ]);

        if ($manageTransaction) {
            $pdo->commit();
        }
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

function payroll_finance_get_link_status(
    PDO $pdo,
    int $payslipId
): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM payroll_finance_link_status
        WHERE payslip_id = ?
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

function payroll_finance_get_links(
    PDO $pdo,
    int $payslipId
): array {
    $stmt = $pdo->prepare("
        SELECT
            link_row.id AS link_id,
            link_row.payslip_id,
            link_row.transaction_id,
            link_row.matched_amount,
            link_row.match_method,
            link_row.notes,
            link_row.created_at,

            tx.date AS transaction_date,
            tx.description AS transaction_description,
            tx.amount AS transaction_amount,
            tx.type AS transaction_type,
            tx.category_id,
            tx.predicted_transaction_id,

            account.id AS account_id,
            account.name AS account_name,

            CASE
                WHEN category.id IS NULL
                    THEN NULL

                WHEN parent.id IS NULL
                    THEN category.name

                ELSE CONCAT(
                    parent.name,
                    ' : ',
                    category.name
                )
            END AS category_label,

            prediction.description
                AS prediction_rule_description

        FROM payroll_payslip_transaction_links link_row

        JOIN transactions tx
          ON tx.id = link_row.transaction_id

        JOIN accounts account
          ON account.id = tx.account_id

        LEFT JOIN categories category
          ON category.id = tx.category_id

        LEFT JOIN categories parent
          ON parent.id = category.parent_id

        LEFT JOIN predicted_transactions prediction
          ON prediction.id = tx.predicted_transaction_id

        WHERE link_row.payslip_id = ?

        ORDER BY
            tx.date,
            tx.id,
            link_row.id
    ");

    $stmt->execute([
        $payslipId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function payroll_finance_get_candidate_transactions(
    PDO $pdo,
    int $payslipId,
    int $limit = 12
): array {
    $status =
        payroll_finance_get_link_status(
            $pdo,
            $payslipId
        );

    if ($status === null) {
        return [];
    }

    if (
        !in_array(
            (string)$status['link_status'],
            [
                'unlinked',
                'partial',
            ],
            true
        )
    ) {
        return [];
    }

    if (
        $status['receiving_account_id']
        === null
    ) {
        return [];
    }

    if (
        $status['expected_settlement_amount']
        === null
    ) {
        return [];
    }

    $expected =
        (float)$status[
            'expected_settlement_amount'
        ];

    $linked =
        (float)$status[
            'linked_amount'
        ];

    $remaining =
        round(
            $expected
            - $linked,
            2
        );

    if ($remaining <= 0.01) {
        return [];
    }

    $window =
        max(
            0,
            min(
                31,
                (int)$status[
                    'candidate_window_days'
                ]
            )
        );

    $payDate =
        new DateTimeImmutable(
            (string)$status[
                'pay_date'
            ]
        );

    $startDate =
        $payDate
            ->modify(
                "-{$window} days"
            )
            ->format('Y-m-d');

    $endDate =
        $payDate
            ->modify(
                "+{$window} days"
            )
            ->format('Y-m-d');

    /*
     * Manual-review candidate tolerance:
     * larger of £5 or 2% of the remaining expected settlement.
     *
     * This deliberately surfaces tiny transcription differences while still
     * excluding unrelated salary-like credits.
     */
    $tolerance =
        max(
            5.00,
            round(
                abs(
                    $remaining
                ) * 0.02,
                2
            )
        );

    $limit =
        max(
            1,
            min(
                50,
                $limit
            )
        );

    $stmt = $pdo->prepare("
        SELECT
            tx.id,
            tx.account_id,
            tx.date,
            tx.description,
            tx.amount,
            tx.type,
            tx.category_id,
            tx.predicted_transaction_id,

            account.name AS account_name,

            CASE
                WHEN category.id IS NULL
                    THEN NULL

                WHEN parent.id IS NULL
                    THEN category.name

                ELSE CONCAT(
                    parent.name,
                    ' : ',
                    category.name
                )
            END AS category_label,

            prediction.description
                AS prediction_rule_description

        FROM transactions tx

        JOIN accounts account
          ON account.id = tx.account_id

        LEFT JOIN categories category
          ON category.id = tx.category_id

        LEFT JOIN categories parent
          ON parent.id = category.parent_id

        LEFT JOIN predicted_transactions prediction
          ON prediction.id = tx.predicted_transaction_id

        LEFT JOIN payroll_payslip_transaction_links existing_link
          ON existing_link.transaction_id = tx.id

        WHERE tx.account_id = ?
          AND tx.type <> 'transfer'
          AND tx.amount > 0
          AND tx.date BETWEEN ? AND ?
          AND existing_link.id IS NULL
          AND ABS(
              tx.amount - ?
          ) <= ?

        ORDER BY
            ABS(
                DATEDIFF(
                    tx.date,
                    ?
                )
            ),
            ABS(
                tx.amount - ?
            ),
            tx.date,
            tx.id

        LIMIT {$limit}
    ");

    $stmt->execute([
        (int)$status[
            'receiving_account_id'
        ],

        $startDate,
        $endDate,
        $remaining,
        $tolerance,

        (string)$status[
            'pay_date'
        ],

        $remaining,
    ]);

    $rows = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($rows as &$row) {
        $amountDifference =
            round(
                (float)$row['amount']
                - $remaining,
                2
            );

        $row['amount_difference'] =
            $amountDifference;

        $row['same_day'] =
            (string)$row['date']
            === (string)$status[
                'pay_date'
            ];

        $row['exact_amount'] =
            abs(
                $amountDifference
            ) <= 0.001;

        $row['category_match'] =
            $status['income_category_id']
                !== null
            && (int)$row['category_id']
                === (int)$status[
                    'income_category_id'
                ];

        $row['prediction_rule_match'] =
            $status['prediction_rule_id']
                !== null
            && (int)$row[
                'predicted_transaction_id'
            ]
                === (int)$status[
                    'prediction_rule_id'
                ];
    }

    unset($row);

    return $rows;
}

function payroll_finance_link_transaction(
    PDO $pdo,
    int $payslipId,
    int $transactionId,
    bool $manageTransaction = true
): int {
    if ($payslipId <= 0) {
        throw new RuntimeException(
            'Invalid payslip ID.'
        );
    }

    if ($transactionId <= 0) {
        throw new RuntimeException(
            'Invalid bank transaction ID.'
        );
    }

    if (
        $manageTransaction
        && $pdo->inTransaction()
    ) {
        throw new RuntimeException(
            'Cannot create Payroll Finance link because '
            . 'a database transaction is already active.'
        );
    }

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM payroll_payslips
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->execute([
            $payslipId,
        ]);

        if (
            $stmt->fetchColumn()
            === false
        ) {
            throw new RuntimeException(
                'Payslip no longer exists.'
            );
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM transactions
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->execute([
            $transactionId,
        ]);

        if (
            $stmt->fetchColumn()
            === false
        ) {
            throw new RuntimeException(
                'Bank transaction no longer exists.'
            );
        }

        $candidates =
            payroll_finance_get_candidate_transactions(
                $pdo,
                $payslipId,
                50
            );

        $selected = null;

        foreach (
            $candidates
            as $candidate
        ) {
            if (
                (int)$candidate['id']
                === $transactionId
            ) {
                $selected =
                    $candidate;

                break;
            }
        }

        if ($selected === null) {
            throw new RuntimeException(
                'The selected bank transaction is no longer '
                . 'a valid candidate for this payslip.'
            );
        }

        $matchedAmount =
            round(
                (float)$selected[
                    'amount'
                ],
                2
            );

        if ($matchedAmount <= 0) {
            throw new RuntimeException(
                'Payroll settlement links require a positive bank credit.'
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO payroll_payslip_transaction_links (
                payslip_id,
                transaction_id,
                matched_amount,
                match_method
            ) VALUES (
                ?, ?, ?, 'manual'
            )
        ");

        $stmt->execute([
            $payslipId,
            $transactionId,
            $matchedAmount,
        ]);

        $linkId =
            (int)$pdo->lastInsertId();

        if ($manageTransaction) {
            $pdo->commit();
        }

        return $linkId;

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

function payroll_finance_unlink_transaction(
    PDO $pdo,
    int $payslipId,
    int $linkId,
    bool $manageTransaction = true
): void {
    if (
        $payslipId <= 0
        || $linkId <= 0
    ) {
        throw new RuntimeException(
            'Invalid Payroll Finance link.'
        );
    }

    if (
        $manageTransaction
        && $pdo->inTransaction()
    ) {
        throw new RuntimeException(
            'Cannot remove Payroll Finance link because '
            . 'a database transaction is already active.'
        );
    }

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM payroll_payslip_transaction_links
            WHERE id = ?
              AND payslip_id = ?
            FOR UPDATE
        ");

        $stmt->execute([
            $linkId,
            $payslipId,
        ]);

        if (
            $stmt->fetchColumn()
            === false
        ) {
            throw new RuntimeException(
                'Payroll Finance link no longer exists.'
            );
        }

        $stmt = $pdo->prepare("
            DELETE FROM payroll_payslip_transaction_links
            WHERE id = ?
              AND payslip_id = ?
        ");

        $stmt->execute([
            $linkId,
            $payslipId,
        ]);

        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'Unable to remove Payroll Finance link.'
            );
        }

        if ($manageTransaction) {
            $pdo->commit();
        }

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

function payroll_finance_status_label(
    string $status
): string {
    return match ($status) {
        'unconfigured' =>
            'Not configured',

        'out_of_scope' =>
            'Out of scope',

        'no_settlement' =>
            'Settlement review required',

        'unlinked' =>
            'Unlinked',

        'partial' =>
            'Partially linked',

        'settled' =>
            'Settled',

        'overlinked' =>
            'Over-linked',

        default =>
            ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $status
                )
            ),
    };
}

function payroll_finance_expected_source_label(
    string $source
): string {
    return match ($source) {
        'statement_amount_paid' =>
            'Source payslip · Amount Paid',

        'statement_net_pay' =>
            'Source payslip · Net Pay',

        'calculated_lines' =>
            'Legacy line-derived settlement',

        'manual_required' =>
            'Manual settlement review required',

        default =>
            'Unknown settlement source',
    };
}

function payroll_finance_match_method_label(
    string $method
): string {
    return match ($method) {
        'manual' =>
            'Manual',

        'exact_same_day' =>
            'Exact same-day',

        'historical_backfill' =>
            'Historical backfill',

        'import_assisted' =>
            'Import assisted',

        default =>
            ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $method
                )
            ),
    };
}
