<?php

declare(strict_types=1);

/**
 * Build a deterministic plan for exact same-day Payroll ↔ Finance links.
 *
 * This function is read-only.
 *
 * A row is only ready when:
 *   - the employment has a Finance mapping;
 *   - the payslip is within linkage scope;
 *   - the payslip has no existing Finance link;
 *   - a safe positive expected settlement exists;
 *   - exactly one positive, non-transfer transaction exists on the configured
 *     account with exactly the same date and amount;
 *   - that transaction has no existing Payroll link;
 *   - the same transaction does not uniquely match another eligible payslip.
 */
function payroll_finance_backfill_build_plan(
    PDO $pdo,
    ?int $employmentId = null
): array {
    if (
        $employmentId !== null
        && $employmentId <= 0
    ) {
        throw new InvalidArgumentException(
            'Employment ID must be a positive integer.'
        );
    }

    $sql = "
        SELECT
            status.payslip_id,
            status.employment_id,
            status.person_name,
            status.pay_date,

            status.statement_amount_paid,
            status.statement_net_pay,
            status.calculated_net_pay,
            status.notional_line_count,
            status.payment_method,

            status.receiving_account_id,
            status.receiving_account_name,
            status.income_category_id,
            status.prediction_rule_id,
            status.linkage_start_date,
            status.candidate_window_days,

            status.expected_settlement_amount,
            status.expected_amount_source,

            status.link_count,
            status.linked_amount,
            status.link_status

        FROM payroll_finance_link_status status

        WHERE status.receiving_account_id IS NOT NULL
    ";

    $params = [];

    if ($employmentId !== null) {
        $sql .= "
            AND status.employment_id = ?
        ";

        $params[] =
            $employmentId;
    }

    $sql .= "
        ORDER BY
            status.employment_id,
            status.pay_date,
            status.payslip_id
    ";

    $stmt =
        $pdo->prepare(
            $sql
        );

    $stmt->execute(
        $params
    );

    $payslips =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    $transactionStmt =
        $pdo->prepare("
            SELECT
                tx.id,
                tx.account_id,
                tx.date,
                tx.description,
                tx.amount,
                tx.type,
                tx.category_id,
                tx.predicted_transaction_id,

                link_row.id AS existing_link_id,
                link_row.payslip_id
                    AS existing_link_payslip_id,

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

            LEFT JOIN payroll_payslip_transaction_links link_row
              ON link_row.transaction_id = tx.id

            LEFT JOIN categories category
              ON category.id = tx.category_id

            LEFT JOIN categories parent
              ON parent.id = category.parent_id

            LEFT JOIN predicted_transactions prediction
              ON prediction.id = tx.predicted_transaction_id

            WHERE tx.account_id = ?
              AND tx.date = ?
              AND tx.amount = ?
              AND tx.amount > 0
              AND tx.type <> 'transfer'

            ORDER BY
                tx.id
        ");

    $rows = [];

    $provisionalByTransaction = [];

    $employmentNames = [];

    foreach (
        $payslips
        as $payslip
    ) {
        $employmentIdValue =
            (int)$payslip[
                'employment_id'
            ];

        $employmentNames[
            $employmentIdValue
        ] =
            (string)$payslip[
                'person_name'
            ];

        $row = [
            'payslip_id' =>
                (int)$payslip[
                    'payslip_id'
                ],

            'employment_id' =>
                $employmentIdValue,

            'person_name' =>
                (string)$payslip[
                    'person_name'
                ],

            'pay_date' =>
                (string)$payslip[
                    'pay_date'
                ],

            'receiving_account_id' =>
                (int)$payslip[
                    'receiving_account_id'
                ],

            'receiving_account_name' =>
                (string)$payslip[
                    'receiving_account_name'
                ],

            'income_category_id' =>
                $payslip[
                    'income_category_id'
                ] !== null
                    ? (int)$payslip[
                        'income_category_id'
                    ]
                    : null,

            'prediction_rule_id' =>
                $payslip[
                    'prediction_rule_id'
                ] !== null
                    ? (int)$payslip[
                        'prediction_rule_id'
                    ]
                    : null,

            'linkage_start_date' =>
                (string)$payslip[
                    'linkage_start_date'
                ],

            'expected_settlement_amount' =>
                $payslip[
                    'expected_settlement_amount'
                ] !== null
                    ? (string)$payslip[
                        'expected_settlement_amount'
                    ]
                    : null,

            'expected_amount_source' =>
                (string)$payslip[
                    'expected_amount_source'
                ],

            'notional_line_count' =>
                (int)$payslip[
                    'notional_line_count'
                ],

            'payment_method' =>
                $payslip[
                    'payment_method'
                ] !== null
                    ? (string)$payslip[
                        'payment_method'
                    ]
                    : null,

            'existing_link_count' =>
                (int)$payslip[
                    'link_count'
                ],

            'existing_linked_amount' =>
                (string)$payslip[
                    'linked_amount'
                ],

            'classification' =>
                null,

            'reason' =>
                null,

            'exact_transaction_count' =>
                0,

            'transaction_id' =>
                null,

            'transaction_description' =>
                null,

            'transaction_amount' =>
                null,

            'transaction_category_id' =>
                null,

            'transaction_category_label' =>
                null,

            'transaction_prediction_rule_id' =>
                null,

            'transaction_prediction_rule_description' =>
                null,

            'category_match' =>
                null,

            'prediction_rule_match' =>
                null,
        ];

        $scopeStart =
            max(
                '2020-01-01',
                (string)$payslip[
                    'linkage_start_date'
                ]
            );

        if (
            (string)$payslip[
                'pay_date'
            ] < $scopeStart
        ) {
            if (
                (int)$payslip[
                    'link_count'
                ] > 0
            ) {
                $row[
                    'classification'
                ] =
                    'invalid_out_of_scope_link';

                $row[
                    'reason'
                ] =
                    'Payslip is outside configured linkage scope but already has a Finance link.';
            } else {
                $row[
                    'classification'
                ] =
                    'out_of_scope';

                $row[
                    'reason'
                ] =
                    'Payslip predates the configured linkage boundary.';
            }

            $rows[] =
                $row;

            continue;
        }

        if (
            (int)$payslip[
                'link_count'
            ] > 0
        ) {
            $row[
                'classification'
            ] =
                'already_linked';

            $row[
                'reason'
            ] =
                'Payslip already has one or more persistent Finance links.';

            $rows[] =
                $row;

            continue;
        }

        if (
            $payslip[
                'expected_settlement_amount'
            ] === null
            || (float)$payslip[
                'expected_settlement_amount'
            ] <= 0
        ) {
            $row[
                'classification'
            ] =
                'no_safe_settlement';

            $row[
                'reason'
            ] =
                'No positive settlement amount is safe for automatic linkage.';

            $rows[] =
                $row;

            continue;
        }

        /*
         * Defence in depth:
         * any payslip with observed notional lines may only be automatically
         * backfilled when Amount Paid was explicitly captured from source.
         */
        if (
            (int)$payslip[
                'notional_line_count'
            ] > 0
            && (string)$payslip[
                'expected_amount_source'
            ] !== 'statement_amount_paid'
        ) {
            $row[
                'classification'
            ] =
                'no_safe_settlement';

            $row[
                'reason'
            ] =
                'Payslip contains notional lines without authoritative Statement Amount Paid.';

            $rows[] =
                $row;

            continue;
        }

        $expectedAmount =
            number_format(
                (float)$payslip[
                    'expected_settlement_amount'
                ],
                2,
                '.',
                ''
            );

        $transactionStmt->execute([
            (int)$payslip[
                'receiving_account_id'
            ],

            (string)$payslip[
                'pay_date'
            ],

            $expectedAmount,
        ]);

        $exactTransactions =
            $transactionStmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $row[
            'exact_transaction_count'
        ] =
            count(
                $exactTransactions
            );

        if ($exactTransactions === []) {
            $row[
                'classification'
            ] =
                'no_exact_match';

            $row[
                'reason'
            ] =
                'No exact same-day/same-amount credit exists in the configured receiving account.';

            $rows[] =
                $row;

            continue;
        }

        if (
            count(
                $exactTransactions
            ) > 1
        ) {
            $row[
                'classification'
            ] =
                'ambiguous_transactions';

            $row[
                'reason'
            ] =
                'More than one exact bank transaction matches this payslip.';

            $rows[] =
                $row;

            continue;
        }

        $transaction =
            $exactTransactions[
                0
            ];

        $row[
            'transaction_id'
        ] =
            (int)$transaction[
                'id'
            ];

        $row[
            'transaction_description'
        ] =
            (string)(
                $transaction[
                    'description'
                ]
                ?? ''
            );

        $row[
            'transaction_amount'
        ] =
            (string)$transaction[
                'amount'
            ];

        $row[
            'transaction_category_id'
        ] =
            $transaction[
                'category_id'
            ] !== null
                ? (int)$transaction[
                    'category_id'
                ]
                : null;

        $row[
            'transaction_category_label'
        ] =
            $transaction[
                'category_label'
            ] !== null
                ? (string)$transaction[
                    'category_label'
                ]
                : null;

        $row[
            'transaction_prediction_rule_id'
        ] =
            $transaction[
                'predicted_transaction_id'
            ] !== null
                ? (int)$transaction[
                    'predicted_transaction_id'
                ]
                : null;

        $row[
            'transaction_prediction_rule_description'
        ] =
            $transaction[
                'prediction_rule_description'
            ] !== null
                ? (string)$transaction[
                    'prediction_rule_description'
                ]
                : null;

        $row[
            'category_match'
        ] =
            $row[
                'income_category_id'
            ] === null
                ? null
                : (
                    $row[
                        'transaction_category_id'
                    ]
                    === $row[
                        'income_category_id'
                    ]
                );

        $row[
            'prediction_rule_match'
        ] =
            $row[
                'prediction_rule_id'
            ] === null
                ? null
                : (
                    $row[
                        'transaction_prediction_rule_id'
                    ]
                    === $row[
                        'prediction_rule_id'
                    ]
                );

        if (
            $transaction[
                'existing_link_id'
            ] !== null
        ) {
            $row[
                'classification'
            ] =
                'exact_transaction_already_linked';

            $row[
                'reason'
            ] =
                'The only exact transaction is already linked to another payslip.';

            $rows[] =
                $row;

            continue;
        }

        $row[
            'classification'
        ] =
            'provisional';

        $row[
            'reason'
        ] =
            'Unique exact transaction found; checking reverse transaction-to-payslip uniqueness.';

        $rowIndex =
            count(
                $rows
            );

        $rows[] =
            $row;

        $transactionId =
            (int)$transaction[
                'id'
            ];

        if (
            !isset(
                $provisionalByTransaction[
                    $transactionId
                ]
            )
        ) {
            $provisionalByTransaction[
                $transactionId
            ] = [];
        }

        $provisionalByTransaction[
            $transactionId
        ][] =
            $rowIndex;
    }

    foreach (
        $provisionalByTransaction
        as $transactionId => $rowIndexes
    ) {
        if (
            count(
                $rowIndexes
            ) === 1
        ) {
            $index =
                $rowIndexes[
                    0
                ];

            $rows[
                $index
            ][
                'classification'
            ] =
                'ready';

            $rows[
                $index
            ][
                'reason'
            ] =
                'Unique exact same-day/same-amount/account pairing.';

            continue;
        }

        foreach (
            $rowIndexes
            as $index
        ) {
            $rows[
                $index
            ][
                'classification'
            ] =
                'transaction_collision';

            $rows[
                $index
            ][
                'reason'
            ] =
                'One exact bank transaction matches more than one eligible payslip.';
        }
    }

    $classificationOrder = [
        'ready',
        'already_linked',
        'out_of_scope',
        'no_safe_settlement',
        'no_exact_match',
        'exact_transaction_already_linked',
        'ambiguous_transactions',
        'transaction_collision',
        'invalid_out_of_scope_link',
    ];

    $summary = [
        'mapped_employments' =>
            count(
                $employmentNames
            ),

        'mapped_payslips' =>
            count(
                $rows
            ),

        'ready' =>
            0,

        'already_linked' =>
            0,

        'out_of_scope' =>
            0,

        'no_safe_settlement' =>
            0,

        'no_exact_match' =>
            0,

        'exact_transaction_already_linked' =>
            0,

        'ambiguous_transactions' =>
            0,

        'transaction_collision' =>
            0,

        'invalid_out_of_scope_link' =>
            0,
    ];

    $employmentSummary = [];

    foreach (
        $rows
        as $row
    ) {
        $classification =
            (string)$row[
                'classification'
            ];

        if (
            !array_key_exists(
                $classification,
                $summary
            )
        ) {
            throw new RuntimeException(
                'Unexpected Payroll Finance backfill classification: '
                . $classification
            );
        }

        $summary[
            $classification
        ]++;

        $employmentIdValue =
            (int)$row[
                'employment_id'
            ];

        if (
            !isset(
                $employmentSummary[
                    $employmentIdValue
                ]
            )
        ) {
            $employmentSummary[
                $employmentIdValue
            ] = [
                'employment_id' =>
                    $employmentIdValue,

                'person_name' =>
                    (string)$row[
                        'person_name'
                    ],

                'mapped_payslips' =>
                    0,
            ];

            foreach (
                $classificationOrder
                as $key
            ) {
                $employmentSummary[
                    $employmentIdValue
                ][
                    $key
                ] = 0;
            }
        }

        $employmentSummary[
            $employmentIdValue
        ][
            'mapped_payslips'
        ]++;

        $employmentSummary[
            $employmentIdValue
        ][
            $classification
        ]++;
    }

    ksort(
        $employmentSummary
    );

    return [
        'employment_filter' =>
            $employmentId,

        'summary' =>
            $summary,

        'employment_summary' =>
            array_values(
                $employmentSummary
            ),

        'rows' =>
            $rows,
    ];
}


/**
 * Apply exactly the rows classified as ready.
 *
 * The expected ready count is mandatory. The plan is rebuilt after entering
 * the write transaction, so a change between dry-run review and apply causes
 * a hard stop rather than silently changing the write set.
 */
function payroll_finance_backfill_apply(
    PDO $pdo,
    ?int $employmentId,
    int $expectedReady,
    bool $manageTransaction = true
): array {
    if ($expectedReady < 0) {
        throw new InvalidArgumentException(
            'Expected ready count cannot be negative.'
        );
    }

    if (
        $manageTransaction
        && $pdo->inTransaction()
    ) {
        throw new RuntimeException(
            'Cannot start Payroll Finance backfill because '
            . 'a database transaction is already active.'
        );
    }

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $plan =
            payroll_finance_backfill_build_plan(
                $pdo,
                $employmentId
            );

        $actualReady =
            (int)$plan[
                'summary'
            ][
                'ready'
            ];

        if (
            $actualReady
            !== $expectedReady
        ) {
            throw new RuntimeException(
                'Backfill ready-count changed since review. '
                . 'Expected '
                . $expectedReady
                . ', current plan has '
                . $actualReady
                . '. Run a fresh dry-run and review it before applying.'
            );
        }

        if (
            (int)$plan[
                'summary'
            ][
                'invalid_out_of_scope_link'
            ] > 0
        ) {
            throw new RuntimeException(
                'Backfill cannot proceed while an out-of-scope '
                . 'payslip already has a Finance link.'
            );
        }

        $insert =
            $pdo->prepare("
                INSERT INTO payroll_payslip_transaction_links (
                    payslip_id,
                    transaction_id,
                    matched_amount,
                    match_method,
                    notes
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    'exact_same_day',
                    ?
                )
            ");

        $inserted = [];

        foreach (
            $plan[
                'rows'
            ]
            as $row
        ) {
            if (
                (string)$row[
                    'classification'
                ] !== 'ready'
            ) {
                continue;
            }

            $payslipId =
                (int)$row[
                    'payslip_id'
                ];

            $transactionId =
                (int)$row[
                    'transaction_id'
                ];

            $matchedAmount =
                number_format(
                    (float)$row[
                        'expected_settlement_amount'
                    ],
                    2,
                    '.',
                    ''
                );

            $note =
                'Deterministic post-2020 exact same-day/amount/account backfill';

            $insert->execute([
                $payslipId,
                $transactionId,
                $matchedAmount,
                $note,
            ]);

            $linkId =
                (int)$pdo->lastInsertId();

            if ($linkId <= 0) {
                throw new RuntimeException(
                    'Failed to obtain inserted Payroll Finance link ID.'
                );
            }

            $inserted[] = [
                'link_id' =>
                    $linkId,

                'payslip_id' =>
                    $payslipId,

                'transaction_id' =>
                    $transactionId,

                'matched_amount' =>
                    $matchedAmount,
            ];
        }

        if (
            count(
                $inserted
            ) !== $expectedReady
        ) {
            throw new RuntimeException(
                'Inserted Payroll Finance link count does not '
                . 'match the reviewed ready count.'
            );
        }

        $statusStmt =
            $pdo->prepare("
                SELECT
                    link_status,
                    linked_amount,
                    expected_settlement_amount
                FROM payroll_finance_link_status
                WHERE payslip_id = ?
                LIMIT 1
            ");

        foreach (
            $inserted
            as $insertedRow
        ) {
            $statusStmt->execute([
                $insertedRow[
                    'payslip_id'
                ],
            ]);

            $status =
                $statusStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$status) {
                throw new RuntimeException(
                    'Unable to re-read Payroll Finance status '
                    . 'after creating a link.'
                );
            }

            if (
                (string)$status[
                    'link_status'
                ] !== 'settled'
            ) {
                throw new RuntimeException(
                    'Exact backfill link did not settle payslip #'
                    . $insertedRow[
                        'payslip_id'
                    ]
                    . '.'
                );
            }

            if (
                abs(
                    (float)$status[
                        'linked_amount'
                    ]
                    -
                    (float)$status[
                        'expected_settlement_amount'
                    ]
                ) > 0.001
            ) {
                throw new RuntimeException(
                    'Exact backfill linked amount does not equal '
                    . 'expected settlement for payslip #'
                    . $insertedRow[
                        'payslip_id'
                    ]
                    . '.'
                );
            }
        }

        if ($manageTransaction) {
            $pdo->commit();
        }

        return [
            'inserted_count' =>
                count(
                    $inserted
                ),

            'inserted' =>
                $inserted,

            'plan' =>
                $plan,
        ];

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


function payroll_finance_backfill_classification_label(
    string $classification
): string {
    return match ($classification) {
        'ready' =>
            'Ready',

        'already_linked' =>
            'Already linked',

        'out_of_scope' =>
            'Out of scope',

        'no_safe_settlement' =>
            'No safe settlement',

        'no_exact_match' =>
            'No exact match',

        'exact_transaction_already_linked' =>
            'Exact transaction already linked',

        'ambiguous_transactions' =>
            'Multiple exact transactions',

        'transaction_collision' =>
            'Transaction matches multiple payslips',

        'invalid_out_of_scope_link' =>
            'INVALID out-of-scope link',

        default =>
            $classification,
    };
}
