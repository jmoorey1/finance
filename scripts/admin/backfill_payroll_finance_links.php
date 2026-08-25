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

function payroll_finance_backfill_cli_fail(
    string $message
): never {
    fwrite(
        STDERR,
        "ERROR: {$message}\n"
    );

    exit(1);
}

function payroll_finance_backfill_cli_usage(): void
{
    echo <<<TEXT
Payroll ↔ Finance exact-link backfill

Usage:
  php8.2 scripts/admin/backfill_payroll_finance_links.php
  php8.2 scripts/admin/backfill_payroll_finance_links.php --detail
  php8.2 scripts/admin/backfill_payroll_finance_links.php --employment-id=N
  php8.2 scripts/admin/backfill_payroll_finance_links.php --apply --expect-ready=N

Options:
  --apply
      Write all rows currently classified Ready.

  --expect-ready=N
      Mandatory with --apply. The current ready count must exactly equal N
      or the entire apply is refused.

  --employment-id=N
      Restrict the plan/apply to one Payroll employment.

  --detail
      Show every classified payslip rather than the Ready sample.

  --help
      Show this help.

Default behaviour is READ-ONLY DRY RUN.

TEXT;
}

function payroll_finance_backfill_cli_money(
    $value
): string {
    if ($value === null) {
        return '—';
    }

    return '£'
        . number_format(
            (float)$value,
            2
        );
}

function payroll_finance_backfill_cli_context_flag(
    ?bool $value
): string {
    if ($value === null) {
        return 'n/a';
    }

    return $value
        ? 'yes'
        : 'no';
}

function payroll_finance_backfill_cli_render_row(
    array $row
): void {
    $classification =
        payroll_finance_backfill_classification_label(
            (string)$row[
                'classification'
            ]
        );

    echo sprintf(
        "#%-5d  %-10s  %-24s  %-12s  %-11s",
        (int)$row[
            'payslip_id'
        ],
        (string)$row[
            'pay_date'
        ],
        substr(
            (string)$row[
                'person_name'
            ],
            0,
            24
        ),
        payroll_finance_backfill_cli_money(
            $row[
                'expected_settlement_amount'
            ]
        ),
        $classification
    );

    if (
        $row[
            'transaction_id'
        ] !== null
    ) {
        echo sprintf(
            "  tx#%-6d  %-12s",
            (int)$row[
                'transaction_id'
            ],
            payroll_finance_backfill_cli_money(
                $row[
                    'transaction_amount'
                ]
            )
        );

        echo '  cat='
            . payroll_finance_backfill_cli_context_flag(
                $row[
                    'category_match'
                ]
            );

        echo '  pred='
            . payroll_finance_backfill_cli_context_flag(
                $row[
                    'prediction_rule_match'
                ]
            );

        $description =
            trim(
                (string)$row[
                    'transaction_description'
                ]
            );

        if ($description !== '') {
            echo '  '
                . substr(
                    $description,
                    0,
                    50
                );
        }
    }

    echo "\n";
}

function payroll_finance_backfill_cli_render_plan(
    array $plan,
    bool $detail
): void {
    $summary =
        $plan[
            'summary'
        ];

    echo "\n";
    echo "Payroll ↔ Finance backfill plan\n";
    echo "==============================\n";

    if (
        $plan[
            'employment_filter'
        ] !== null
    ) {
        echo "Employment filter:      #"
            . (int)$plan[
                'employment_filter'
            ]
            . "\n";
    } else {
        echo "Employment filter:      all configured employments\n";
    }

    echo "Mapped employments:     "
        . (int)$summary[
            'mapped_employments'
        ]
        . "\n";

    echo "Mapped payslips:        "
        . (int)$summary[
            'mapped_payslips'
        ]
        . "\n";

    echo "\n";
    echo "Classification summary\n";
    echo "----------------------\n";

    $ordered = [
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

    foreach (
        $ordered
        as $key
    ) {
        echo str_pad(
            payroll_finance_backfill_classification_label(
                $key
            ),
            38
        )
        . (int)$summary[
            $key
        ]
        . "\n";
    }

    echo "\n";
    echo "By employment\n";
    echo "-------------\n";

    foreach (
        $plan[
            'employment_summary'
        ]
        as $employment
    ) {
        echo '#'
            . (int)$employment[
                'employment_id'
            ]
            . ' '
            . (string)$employment[
                'person_name'
            ]
            . "\n";

        echo '  Payslips: '
            . (int)$employment[
                'mapped_payslips'
            ]
            . ' | Ready: '
            . (int)$employment[
                'ready'
            ]
            . ' | Linked: '
            . (int)$employment[
                'already_linked'
            ]
            . ' | No exact: '
            . (int)$employment[
                'no_exact_match'
            ]
            . ' | Ambiguous: '
            . (
                (int)$employment[
                    'ambiguous_transactions'
                ]
                +
                (int)$employment[
                    'transaction_collision'
                ]
            )
            . ' | Out of scope: '
            . (int)$employment[
                'out_of_scope'
            ]
            . "\n";
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

    echo "\n";

    if ($detail) {
        echo "Detailed classifications\n";
        echo "------------------------\n";

        foreach (
            $ordered
            as $classification
        ) {
            $matches =
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
                            === $classification
                    )
                );

            if ($matches === []) {
                continue;
            }

            echo "\n"
                . payroll_finance_backfill_classification_label(
                    $classification
                )
                . " ("
                . count(
                    $matches
                )
                . ")\n";

            foreach (
                $matches
                as $row
            ) {
                payroll_finance_backfill_cli_render_row(
                    $row
                );
            }
        }

        echo "\n";

        return;
    }

    echo "Ready match sample\n";
    echo "------------------\n";

    if ($readyRows === []) {
        echo "No new exact links are currently ready.\n\n";

        return;
    }

    $sampleLimit = 20;

    foreach (
        array_slice(
            $readyRows,
            0,
            $sampleLimit
        )
        as $row
    ) {
        payroll_finance_backfill_cli_render_row(
            $row
        );
    }

    if (
        count(
            $readyRows
        ) > $sampleLimit
    ) {
        echo "... "
            . (
                count(
                    $readyRows
                )
                - $sampleLimit
            )
            . " additional Ready rows not shown.\n";

        echo "Run again with --detail to inspect every row.\n";
    }

    echo "\n";
}

$apply = false;
$detail = false;
$employmentId = null;
$expectedReady = null;

foreach (
    array_slice(
        $argv,
        1
    )
    as $argument
) {
    if ($argument === '--apply') {
        $apply = true;

        continue;
    }

    if ($argument === '--detail') {
        $detail = true;

        continue;
    }

    if (
        $argument === '--help'
        || $argument === '-h'
    ) {
        payroll_finance_backfill_cli_usage();

        exit(0);
    }

    if (
        str_starts_with(
            $argument,
            '--employment-id='
        )
    ) {
        $raw =
            substr(
                $argument,
                strlen(
                    '--employment-id='
                )
            );

        if (
            $raw === ''
            || !ctype_digit(
                $raw
            )
            || (int)$raw <= 0
        ) {
            payroll_finance_backfill_cli_fail(
                'Employment ID must be a positive integer.'
            );
        }

        $employmentId =
            (int)$raw;

        continue;
    }

    if (
        str_starts_with(
            $argument,
            '--expect-ready='
        )
    ) {
        $raw =
            substr(
                $argument,
                strlen(
                    '--expect-ready='
                )
            );

        if (
            $raw === ''
            || !ctype_digit(
                $raw
            )
        ) {
            payroll_finance_backfill_cli_fail(
                'Expected ready count must be a non-negative integer.'
            );
        }

        $expectedReady =
            (int)$raw;

        continue;
    }

    payroll_finance_backfill_cli_fail(
        'Unknown argument: '
        . $argument
    );
}

if (
    !$apply
    && $expectedReady !== null
) {
    payroll_finance_backfill_cli_fail(
        '--expect-ready is only valid with --apply.'
    );
}

if (
    $apply
    && $expectedReady === null
) {
    payroll_finance_backfill_cli_fail(
        '--apply requires --expect-ready=N from a reviewed dry-run.'
    );
}

if (!$apply) {
    if ($pdo->inTransaction()) {
        payroll_finance_backfill_cli_fail(
            'Unexpected active transaction before dry-run.'
        );
    }

    $pdo->exec(
        'SET TRANSACTION READ ONLY'
    );

    $pdo->beginTransaction();

    try {
        $plan =
            payroll_finance_backfill_build_plan(
                $pdo,
                $employmentId
            );

        payroll_finance_backfill_cli_render_plan(
            $plan,
            $detail
        );

        $pdo->rollBack();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        payroll_finance_backfill_cli_fail(
            $e->getMessage()
        );
    }

    echo "DRY RUN ONLY — no Payroll Finance links were written.\n";

    echo "Apply is intentionally disabled unless you rerun with:\n";
    echo "  --apply --expect-ready="
        . (int)$plan[
            'summary'
        ][
            'ready'
        ]
        . "\n";

    exit(0);
}

echo "APPLY MODE\n";
echo "==========\n";
echo "Requested reviewed Ready count: "
    . $expectedReady
    . "\n\n";

$preApplyPlan =
    payroll_finance_backfill_build_plan(
        $pdo,
        $employmentId
    );

payroll_finance_backfill_cli_render_plan(
    $preApplyPlan,
    $detail
);

if (
    (int)$preApplyPlan[
        'summary'
    ][
        'ready'
    ]
    !== $expectedReady
) {
    payroll_finance_backfill_cli_fail(
        'Current Ready count does not equal --expect-ready. '
        . 'Nothing was written.'
    );
}

try {
    $result =
        payroll_finance_backfill_apply(
            $pdo,
            $employmentId,
            $expectedReady
        );

} catch (Throwable $e) {
    payroll_finance_backfill_cli_fail(
        $e->getMessage()
    );
}

echo "\n";
echo "Backfill committed successfully.\n";
echo "Links inserted: "
    . (int)$result[
        'inserted_count'
    ]
    . "\n";

$postApplyPlan =
    payroll_finance_backfill_build_plan(
        $pdo,
        $employmentId
    );

echo "Ready links remaining after apply: "
    . (int)$postApplyPlan[
        'summary'
    ][
        'ready'
    ]
    . "\n";

if (
    (int)$postApplyPlan[
        'summary'
    ][
        'ready'
    ] !== 0
) {
    payroll_finance_backfill_cli_fail(
        'Unexpected Ready rows remain after apply. '
        . 'Review before doing anything else.'
    );
}

echo "Idempotence check: PASS.\n";
