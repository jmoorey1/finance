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
require_once __DIR__ . '/../payroll_ui.php';

$failures = [];

function payroll_smoke_assert(
    bool $condition,
    string $message
): void {
    global $failures;

    if (!$condition) {
        $failures[] = $message;
    }
}

function payroll_smoke_scalar(
    PDO $pdo,
    string $sql
): int {
    return (int)$pdo->query($sql)->fetchColumn();
}

$employments = payroll_ui_get_employments($pdo);

payroll_smoke_assert(
    count($employments) > 0,
    'At least one payroll employment must exist.'
);

$totalRawPayslips = payroll_smoke_scalar(
    $pdo,
    'SELECT COUNT(*) FROM payroll_payslips'
);

$totalSummaryPayslips = payroll_smoke_scalar(
    $pdo,
    'SELECT COUNT(*) FROM payroll_payslip_summary'
);

payroll_smoke_assert(
    $totalRawPayslips === $totalSummaryPayslips,
    'Every payroll_payslips row must appear once in payroll_payslip_summary.'
);

$lineCountMismatches = payroll_smoke_scalar(
    $pdo,
    "
    SELECT COUNT(*)

    FROM payroll_payslip_summary ps

    LEFT JOIN (
        SELECT
            payslip_id,
            COUNT(*) AS actual_line_count
        FROM payroll_line_items
        GROUP BY payslip_id
    ) li
      ON li.payslip_id = ps.payslip_id

    WHERE ps.line_item_count
          <> COALESCE(li.actual_line_count, 0)
    "
);

payroll_smoke_assert(
    $lineCountMismatches === 0,
    'Payslip summary line_item_count must match payroll_line_items.'
);

$duplicateSameDateGroups = payroll_smoke_scalar(
    $pdo,
    "
    SELECT COUNT(*)

    FROM (
        SELECT
            employment_id,
            pay_date

        FROM payroll_payslips

        GROUP BY
            employment_id,
            pay_date

        HAVING COUNT(*) > 1
    ) d
    "
);

foreach ($employments as $employment) {

    $employmentId = (int)$employment['employment_id'];

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payroll_payslips
        WHERE employment_id = ?
    ");

    $countStmt->execute([$employmentId]);

    $rawCount = (int)$countStmt->fetchColumn();

    $history = payroll_ui_get_payslips(
        $pdo,
        $employmentId,
        null
    );

    payroll_smoke_assert(
        count($history) === $rawCount,
        "Employment {$employmentId} history query must return every payslip."
    );

    $latest = payroll_ui_get_latest_payslip(
        $pdo,
        $employmentId
    );

    payroll_smoke_assert(
        ($rawCount === 0 && $latest === null)
        || ($rawCount > 0 && $latest !== null),
        "Employment {$employmentId} latest-payslip query must match payslip availability."
    );

    $expenseTotals = payroll_ui_get_expense_totals(
        $pdo,
        $employmentId
    );

    payroll_smoke_assert(
        isset($expenseTotals['total_expenses']),
        "Employment {$employmentId} expense totals query must return a total."
    );

    $taxYears = payroll_ui_get_tax_year_options(
        $pdo,
        $employmentId
    );

    foreach ($taxYears as $taxYear) {

        $yearRows = payroll_ui_get_payslips(
            $pdo,
            $employmentId,
            (int)$taxYear['tax_year_start']
        );

        payroll_smoke_assert(
            count($yearRows)
            === (int)$taxYear['payslip_count'],
            "Employment {$employmentId} tax-year filter must match its advertised count."
        );
    }
}

if ($totalRawPayslips > 0) {

    $sampleId = (int)$pdo->query("
        SELECT id
        FROM payroll_payslips
        ORDER BY pay_date DESC, id DESC
        LIMIT 1
    ")->fetchColumn();

    $sample = payroll_ui_get_payslip(
        $pdo,
        $sampleId
    );

    payroll_smoke_assert(
        $sample !== null,
        'Latest sample payslip must be loadable by ID.'
    );

    if ($sample !== null) {

        $lines = payroll_ui_get_payslip_lines(
            $pdo,
            $sampleId
        );

        payroll_smoke_assert(
            count($lines)
            === (int)$sample['line_item_count'],
            'Payslip detail query must return exactly the summary line count.'
        );

        $adjacent = payroll_ui_get_adjacent_payslips(
            $pdo,
            (int)$sample['employment_id'],
            (string)$sample['pay_date'],
            $sampleId
        );

        payroll_smoke_assert(
            array_key_exists('previous', $adjacent)
            && array_key_exists('next', $adjacent),
            'Payslip navigation query must return previous/next keys.'
        );
    }
}

$requiredPages = [
    __DIR__ . '/../../public/payroll.php',
    __DIR__ . '/../../public/payroll_payslips.php',
    __DIR__ . '/../../public/payroll_payslip.php',
];

foreach ($requiredPages as $page) {
    payroll_smoke_assert(
        is_file($page),
        'Missing payroll UI page: ' . $page
    );
}

if ($failures) {

    foreach ($failures as $failure) {
        fwrite(
            STDERR,
            "FAIL: {$failure}\n"
        );
    }

    exit(1);
}

echo "Payroll UI smoke checks passed.\n";
echo "Employments checked: "
    . count($employments)
    . "\n";

echo "Payslips checked through summary: "
    . $totalRawPayslips
    . "\n";

echo "Same-date duplicate groups preserved: "
    . $duplicateSameDateGroups
    . "\n";

exit(0);
