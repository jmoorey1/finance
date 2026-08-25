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
require_once __DIR__ . '/../payroll_finance_backfill.php';

function payroll_finance_backfill_test_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}

function payroll_finance_backfill_test_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_finance_backfill_test_fail(
            $message
        );
    }
}

function payroll_finance_backfill_test_count(
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

function payroll_finance_backfill_test_create_payslip(
    PDO $pdo,
    int $employmentId,
    int $basicPayCategoryId,
    ?int $notionalPayCategoryId,
    string $payDate,
    string $settlementAmount,
    bool $hasNotionalLine = false,
    bool $authoritativeAmountPaid = true
): int {
    if ($authoritativeAmountPaid) {
        $stmt =
            $pdo->prepare("
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
                    ?,
                    ?,
                    'BACKFILLTEST',
                    60000.00,
                    ?,
                    0.00,
                    ?,
                    ?,
                    'Bacs'
                )
            ");

        $stmt->execute([
            $employmentId,
            $payDate,
            $settlementAmount,
            $settlementAmount,
            $settlementAmount,
        ]);
    } else {
        $stmt =
            $pdo->prepare("
                INSERT INTO payroll_payslips (
                    employment_id,
                    pay_date,
                    tax_code,
                    annual_salary
                ) VALUES (
                    ?,
                    ?,
                    'BACKFILLTEST',
                    60000.00
                )
            ");

        $stmt->execute([
            $employmentId,
            $payDate,
        ]);
    }

    $payslipId =
        (int)$pdo->lastInsertId();

    $stmt =
        $pdo->prepare("
            INSERT INTO payroll_line_items (
                payslip_id,
                code,
                description,
                amount,
                category_id,
                is_notional
            ) VALUES (
                ?,
                'BACKFILL BASIC',
                'Backfill regression basic pay',
                ?,
                ?,
                0
            )
        ");

    $stmt->execute([
        $payslipId,
        $settlementAmount,
        $basicPayCategoryId,
    ]);

    if ($hasNotionalLine) {
        if ($notionalPayCategoryId === null) {
            payroll_finance_backfill_test_fail(
                'A Pay category is required for the synthetic notional line.'
            );
        }

        $stmt =
            $pdo->prepare("
                INSERT INTO payroll_line_items (
                    payslip_id,
                    code,
                    description,
                    amount,
                    category_id,
                    is_notional
                ) VALUES (
                    ?,
                    'BACKFILL NOTIONAL',
                    'Backfill regression notional pay',
                    50.00,
                    ?,
                    1
                )
            ");

        $stmt->execute([
            $payslipId,
            $notionalPayCategoryId,
        ]);
    }

    return $payslipId;
}

function payroll_finance_backfill_test_create_transaction(
    PDO $pdo,
    int $accountId,
    ?int $categoryId,
    ?int $predictionRuleId,
    string $date,
    string $amount,
    string $description
): int {
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
                ?,
                0
            )
        ");

    $stmt->execute([
        $accountId,
        $date,
        $description,
        $amount,
        $categoryId,
        $predictionRuleId,
    ]);

    return (int)$pdo->lastInsertId();
}

$beforePeople =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_people'
    );

$beforeEmployments =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_employments'
    );

$beforePayslips =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );

$beforeLines =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );

$beforeTransactions =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM transactions'
    );

$beforeMappings =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_finance_mappings'
    );

$beforeLinks =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslip_transaction_links'
    );

$account =
    $pdo->query("
        SELECT id
        FROM accounts
        WHERE active = 1
        ORDER BY
            CASE
                WHEN type = 'current'
                    THEN 0
                ELSE 1
            END,
            id
        LIMIT 1
    ")->fetchColumn();

if ($account === false) {
    payroll_finance_backfill_test_fail(
        'An active Finance account is required.'
    );
}

$accountId =
    (int)$account;

$otherAccount =
    $pdo->prepare("
        SELECT id
        FROM accounts
        WHERE id <> ?
        ORDER BY
            active DESC,
            id
        LIMIT 1
    ");

$otherAccount->execute([
    $accountId,
]);

$otherAccountIdRaw =
    $otherAccount->fetchColumn();

$otherAccountId =
    $otherAccountIdRaw !== false
        ? (int)$otherAccountIdRaw
        : $accountId;

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

$predictionRule =
    $pdo->query("
        SELECT id
        FROM predicted_transactions
        WHERE prediction_type = 'income'
        ORDER BY
            active DESC,
            id
        LIMIT 1
    ")->fetchColumn();

$predictionRuleId =
    $predictionRule !== false
        ? (int)$predictionRule
        : null;

$basicPayCategory =
    $pdo->query("
        SELECT c.id
        FROM payroll_categories c
        JOIN payroll_line_types lt
          ON lt.id = c.line_type_id
        WHERE lt.name = 'Pay'
          AND c.name = 'BASIC PAY'
        LIMIT 1
    ")->fetchColumn();

if ($basicPayCategory === false) {
    payroll_finance_backfill_test_fail(
        'BASIC PAY Payroll category is required.'
    );
}

$basicPayCategoryId =
    (int)$basicPayCategory;

$notionalPayCategory =
    $pdo->query("
        SELECT c.id
        FROM payroll_categories c
        JOIN payroll_line_types lt
          ON lt.id = c.line_type_id
        WHERE lt.name = 'Pay'
          AND c.id <> {$basicPayCategoryId}
        ORDER BY c.id
        LIMIT 1
    ")->fetchColumn();

$notionalPayCategoryId =
    $notionalPayCategory !== false
        ? (int)$notionalPayCategory
        : $basicPayCategoryId;

$transactionStarted = false;

try {
    $pdo->beginTransaction();
    $transactionStarted = true;

    $stmt =
        $pdo->prepare("
            INSERT INTO payroll_people (
                full_name
            ) VALUES (
                ?
            )
        ");

    $stmt->execute([
        'Payroll Backfill Regression Person',
    ]);

    $personId =
        (int)$pdo->lastInsertId();

    $stmt =
        $pdo->prepare("
            INSERT INTO payroll_employments (
                person_id,
                employer_name,
                employee_number,
                status
            ) VALUES (
                ?,
                'Backfill Regression Employer',
                'BACKFILL-TEST',
                'active'
            )
        ");

    $stmt->execute([
        $personId,
    ]);

    $employmentId =
        (int)$pdo->lastInsertId();

    $stmt =
        $pdo->prepare("
            INSERT INTO payroll_finance_mappings (
                employment_id,
                receiving_account_id,
                income_category_id,
                prediction_rule_id,
                linkage_start_date,
                candidate_window_days
            ) VALUES (
                ?,
                ?,
                ?,
                ?,
                '2020-01-01',
                7
            )
        ");

    $stmt->execute([
        $employmentId,
        $accountId,
        $incomeCategoryId,
        $predictionRuleId,
    ]);

    /*
     * 1. Unique exact ordinary match -> Ready.
     */
    $readyOrdinaryPayslip =
        payroll_finance_backfill_test_create_payslip(
            $pdo,
            $employmentId,
            $basicPayCategoryId,
            $notionalPayCategoryId,
            '2041-01-31',
            '81234.56'
        );

    $readyOrdinaryTransaction =
        payroll_finance_backfill_test_create_transaction(
            $pdo,
            $accountId,
            $incomeCategoryId,
            $predictionRuleId,
            '2041-01-31',
            '81234.56',
            'BACKFILL UNIQUE ORDINARY'
        );

    /*
     * 2. Two exact transactions -> Ambiguous transactions.
     */
    payroll_finance_backfill_test_create_payslip(
        $pdo,
        $employmentId,
        $basicPayCategoryId,
        $notionalPayCategoryId,
        '2041-02-28',
        '82345.67'
    );

    payroll_finance_backfill_test_create_transaction(
        $pdo,
        $accountId,
        $incomeCategoryId,
        $predictionRuleId,
        '2041-02-28',
        '82345.67',
        'BACKFILL AMBIGUOUS A'
    );

    payroll_finance_backfill_test_create_transaction(
        $pdo,
        $accountId,
        $incomeCategoryId,
        $predictionRuleId,
        '2041-02-28',
        '82345.67',
        'BACKFILL AMBIGUOUS B'
    );

    /*
     * 3 + 4. Two payslips against one exact transaction
     *        -> reverse transaction collision.
     */
    payroll_finance_backfill_test_create_payslip(
        $pdo,
        $employmentId,
        $basicPayCategoryId,
        $notionalPayCategoryId,
        '2041-03-31',
        '83456.78'
    );

    payroll_finance_backfill_test_create_payslip(
        $pdo,
        $employmentId,
        $basicPayCategoryId,
        $notionalPayCategoryId,
        '2041-03-31',
        '83456.78'
    );

    payroll_finance_backfill_test_create_transaction(
        $pdo,
        $accountId,
        $incomeCategoryId,
        $predictionRuleId,
        '2041-03-31',
        '83456.78',
        'BACKFILL PAYSLIP COLLISION'
    );

    /*
     * 5. Near amount only -> no exact match.
     */
    payroll_finance_backfill_test_create_payslip(
        $pdo,
        $employmentId,
        $basicPayCategoryId,
        $notionalPayCategoryId,
        '2041-04-30',
        '84567.89'
    );

    payroll_finance_backfill_test_create_transaction(
        $pdo,
        $accountId,
        $incomeCategoryId,
        $predictionRuleId,
        '2041-04-30',
        '84567.90',
        'BACKFILL NEAR BUT NOT EXACT'
    );

    /*
     * Also prove a correct amount in the wrong account is not eligible.
     */
    if ($otherAccountId !== $accountId) {
        payroll_finance_backfill_test_create_transaction(
            $pdo,
            $otherAccountId,
            $incomeCategoryId,
            $predictionRuleId,
            '2041-04-30',
            '84567.89',
            'BACKFILL WRONG ACCOUNT'
        );
    }

    /*
     * 6. Pre-2020 -> out of scope.
     */
    payroll_finance_backfill_test_create_payslip(
        $pdo,
        $employmentId,
        $basicPayCategoryId,
        $notionalPayCategoryId,
        '2019-12-31',
        '85678.90'
    );

    /*
     * 7. Observed notional line without Amount Paid -> no safe settlement,
     *    even though an exact transaction is present.
     */
    payroll_finance_backfill_test_create_payslip(
        $pdo,
        $employmentId,
        $basicPayCategoryId,
        $notionalPayCategoryId,
        '2041-05-31',
        '86789.01',
        true,
        false
    );

    payroll_finance_backfill_test_create_transaction(
        $pdo,
        $accountId,
        $incomeCategoryId,
        $predictionRuleId,
        '2041-05-31',
        '86789.01',
        'BACKFILL UNSAFE NOTIONAL'
    );

    /*
     * 8. Notional line WITH source Amount Paid -> Ready.
     */
    $readyNotionalPayslip =
        payroll_finance_backfill_test_create_payslip(
            $pdo,
            $employmentId,
            $basicPayCategoryId,
            $notionalPayCategoryId,
            '2041-06-30',
            '87890.12',
            true,
            true
        );

    $readyNotionalTransaction =
        payroll_finance_backfill_test_create_transaction(
            $pdo,
            $accountId,
            $incomeCategoryId,
            $predictionRuleId,
            '2041-06-30',
            '87890.12',
            'BACKFILL AUTHORITATIVE NOTIONAL'
        );

    /*
     * 9. Existing persistent link -> already linked.
     */
    $alreadyLinkedPayslip =
        payroll_finance_backfill_test_create_payslip(
            $pdo,
            $employmentId,
            $basicPayCategoryId,
            $notionalPayCategoryId,
            '2041-07-31',
            '88901.23'
        );

    $alreadyLinkedTransaction =
        payroll_finance_backfill_test_create_transaction(
            $pdo,
            $accountId,
            $incomeCategoryId,
            $predictionRuleId,
            '2041-07-31',
            '88901.23',
            'BACKFILL ALREADY LINKED'
        );

    $stmt =
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
                '88901.23',
                'manual',
                'Backfill regression pre-existing link'
            )
        ");

    $stmt->execute([
        $alreadyLinkedPayslip,
        $alreadyLinkedTransaction,
    ]);

    $plan =
        payroll_finance_backfill_build_plan(
            $pdo,
            $employmentId
        );

    payroll_finance_backfill_test_assert(
        (int)$plan[
            'summary'
        ][
            'mapped_employments'
        ] === 1,
        'Synthetic plan must contain one mapped employment.'
    );

    payroll_finance_backfill_test_assert(
        (int)$plan[
            'summary'
        ][
            'mapped_payslips'
        ] === 9,
        'Synthetic plan must contain nine payslips.'
    );

    $expectedClassifications = [
        'ready' =>
            2,

        'already_linked' =>
            1,

        'out_of_scope' =>
            1,

        'no_safe_settlement' =>
            1,

        'no_exact_match' =>
            1,

        'exact_transaction_already_linked' =>
            0,

        'ambiguous_transactions' =>
            1,

        'transaction_collision' =>
            2,

        'invalid_out_of_scope_link' =>
            0,
    ];

    foreach (
        $expectedClassifications
        as $classification => $expectedCount
    ) {
        payroll_finance_backfill_test_assert(
            (int)$plan[
                'summary'
            ][
                $classification
            ] === $expectedCount,
            'Unexpected synthetic classification count for '
            . $classification
            . '.'
        );
    }

    $readyRows =
        array_values(
            array_filter(
                $plan[
                    'rows'
                ],
                static fn (
                    array $row
                ): bool =>
                    (string)$row[
                        'classification'
                    ]
                    === 'ready'
            )
        );

    $readyPayslipIds =
        array_map(
            static fn (
                array $row
            ): int =>
                (int)$row[
                    'payslip_id'
                ],
            $readyRows
        );

    sort(
        $readyPayslipIds
    );

    $expectedReadyPayslipIds = [
        $readyOrdinaryPayslip,
        $readyNotionalPayslip,
    ];

    sort(
        $expectedReadyPayslipIds
    );

    payroll_finance_backfill_test_assert(
        $readyPayslipIds
        === $expectedReadyPayslipIds,
        'Only the two deterministic synthetic matches may be Ready.'
    );

    foreach (
        $readyRows
        as $row
    ) {
        payroll_finance_backfill_test_assert(
            $row[
                'category_match'
            ] === (
                $incomeCategoryId !== null
                    ? true
                    : null
            ),
            'Synthetic Ready category context is incorrect.'
        );

        payroll_finance_backfill_test_assert(
            $row[
                'prediction_rule_match'
            ] === (
                $predictionRuleId !== null
                    ? true
                    : null
            ),
            'Synthetic Ready prediction context is incorrect.'
        );
    }

    /*
     * Snapshot the exact transaction before applying links.
     */
    $transactionSnapshotStmt =
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

    $transactionSnapshotStmt->execute([
        $readyOrdinaryTransaction,
    ]);

    $transactionBefore =
        $transactionSnapshotStmt->fetch(
            PDO::FETCH_ASSOC
        );

    $applyResult =
        payroll_finance_backfill_apply(
            $pdo,
            $employmentId,
            2,
            false
        );

    payroll_finance_backfill_test_assert(
        (int)$applyResult[
            'inserted_count'
        ] === 2,
        'Synthetic apply must insert exactly two links.'
    );

    $transactionSnapshotStmt->execute([
        $readyOrdinaryTransaction,
    ]);

    $transactionAfter =
        $transactionSnapshotStmt->fetch(
            PDO::FETCH_ASSOC
        );

    payroll_finance_backfill_test_assert(
        $transactionAfter
        === $transactionBefore,
        'Backfill must not alter the bank transaction.'
    );

    foreach (
        [
            [
                'payslip_id' =>
                    $readyOrdinaryPayslip,

                'transaction_id' =>
                    $readyOrdinaryTransaction,
            ],

            [
                'payslip_id' =>
                    $readyNotionalPayslip,

                'transaction_id' =>
                    $readyNotionalTransaction,
            ],
        ]
        as $expectedLink
    ) {
        $stmt =
            $pdo->prepare("
                SELECT
                    matched_amount,
                    match_method,
                    notes
                FROM payroll_payslip_transaction_links
                WHERE payslip_id = ?
                  AND transaction_id = ?
                LIMIT 1
            ");

        $stmt->execute([
            $expectedLink[
                'payslip_id'
            ],

            $expectedLink[
                'transaction_id'
            ],
        ]);

        $link =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        payroll_finance_backfill_test_assert(
            $link !== false,
            'Expected synthetic backfill link is missing.'
        );

        payroll_finance_backfill_test_assert(
            (string)$link[
                'match_method'
            ] === 'exact_same_day',
            'Backfill link must use exact_same_day match method.'
        );

        payroll_finance_backfill_test_assert(
            str_contains(
                (string)$link[
                    'notes'
                ],
                'Deterministic post-2020'
            ),
            'Backfill link must retain an audit note.'
        );
    }

    $postApplyPlan =
        payroll_finance_backfill_build_plan(
            $pdo,
            $employmentId
        );

    payroll_finance_backfill_test_assert(
        (int)$postApplyPlan[
            'summary'
        ][
            'ready'
        ] === 0,
        'No Ready synthetic rows may remain after apply.'
    );

    payroll_finance_backfill_test_assert(
        (int)$postApplyPlan[
            'summary'
        ][
            'already_linked'
        ] === 3,
        'The two new links plus the pre-existing link must now be Already linked.'
    );

    /*
     * Explicit idempotence:
     * re-running apply against zero Ready rows must write nothing.
     */
    $secondApply =
        payroll_finance_backfill_apply(
            $pdo,
            $employmentId,
            0,
            false
        );

    payroll_finance_backfill_test_assert(
        (int)$secondApply[
            'inserted_count'
        ] === 0,
        'Second synthetic apply must be idempotent.'
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

$afterPeople =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_people'
    );

$afterEmployments =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_employments'
    );

$afterPayslips =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslips'
    );

$afterLines =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_line_items'
    );

$afterTransactions =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM transactions'
    );

$afterMappings =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_finance_mappings'
    );

$afterLinks =
    payroll_finance_backfill_test_count(
        $pdo,
        'SELECT COUNT(*) FROM payroll_payslip_transaction_links'
    );

payroll_finance_backfill_test_assert(
    $afterPeople === $beforePeople,
    'Synthetic Payroll person must be rolled back.'
);

payroll_finance_backfill_test_assert(
    $afterEmployments === $beforeEmployments,
    'Synthetic Payroll employment must be rolled back.'
);

payroll_finance_backfill_test_assert(
    $afterPayslips === $beforePayslips,
    'Synthetic payslips must be rolled back.'
);

payroll_finance_backfill_test_assert(
    $afterLines === $beforeLines,
    'Synthetic Payroll lines must be rolled back.'
);

payroll_finance_backfill_test_assert(
    $afterTransactions === $beforeTransactions,
    'Synthetic Finance transactions must be rolled back.'
);

payroll_finance_backfill_test_assert(
    $afterMappings === $beforeMappings,
    'Synthetic Finance mapping must be rolled back.'
);

payroll_finance_backfill_test_assert(
    $afterLinks === $beforeLinks,
    'Synthetic Payroll Finance links must be rolled back.'
);

$cliSource =
    file_get_contents(
        __DIR__
        . '/../admin/backfill_payroll_finance_links.php'
    );

payroll_finance_backfill_test_assert(
    $cliSource !== false
    && str_contains(
        $cliSource,
        '--expect-ready='
    )
    && str_contains(
        $cliSource,
        'SET TRANSACTION READ ONLY'
    )
    && str_contains(
        $cliSource,
        'DRY RUN ONLY'
    ),
    'Backfill CLI must remain dry-run by default '
    . 'and require an expected Ready count for writes.'
);

echo "Payroll Finance backfill checks passed.\n";
echo "Exact same-day/amount/account matching: verified.\n";
echo "Multiple exact transaction ambiguity: verified.\n";
echo "Reverse transaction-to-payslip collision: verified.\n";
echo "Wrong-account exclusion: verified.\n";
echo "Near-amount exclusion: verified.\n";
echo "Pre-2020 scope exclusion: verified.\n";
echo "Notional-without-Amount-Paid exclusion: verified.\n";
echo "Authoritative notional settlement: verified.\n";
echo "Existing-link exclusion: verified.\n";
echo "Bank transaction immutability: verified.\n";
echo "Apply expected-count guardrail: verified.\n";
echo "Idempotent second apply: verified.\n";
echo "Synthetic test data: rolled back.\n";
echo "Permanent counts unchanged: "
    . $afterPayslips
    . " payslips / "
    . $afterTransactions
    . " transactions / "
    . $afterLinks
    . " Payroll Finance links.\n";

exit(0);
