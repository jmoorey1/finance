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
require_once __DIR__ . '/../payroll_write.php';

function payroll_write_test_fail(string $message): never
{
    throw new RuntimeException($message);
}

function payroll_write_test_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_write_test_fail($message);
    }
}

function payroll_write_test_count(
    PDO $pdo,
    string $sql,
    array $params = []
): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function payroll_write_test_float(
    $actual,
    float $expected,
    string $message
): void {
    if (abs((float)$actual - $expected) > 0.001) {
        payroll_write_test_fail(
            $message
            . ' Expected '
            . number_format($expected, 2, '.', '')
            . ', got '
            . number_format((float)$actual, 2, '.', '')
            . '.'
        );
    }
}

function payroll_write_test_source_contains(
    string $path,
    string $needle,
    string $message
): void {
    $source = file_get_contents($path);

    if ($source === false || !str_contains($source, $needle)) {
        payroll_write_test_fail($message);
    }
}

$beforePayslips = payroll_write_test_count(
    $pdo,
    'SELECT COUNT(*) FROM payroll_payslips'
);

$beforeLines = payroll_write_test_count(
    $pdo,
    'SELECT COUNT(*) FROM payroll_line_items'
);

$employmentStmt = $pdo->query("
    SELECT
        e.id AS employment_id,
        MAX(p.pay_date) AS latest_pay_date
    FROM payroll_employments e
    JOIN payroll_payslips p
      ON p.employment_id = e.id
    GROUP BY e.id
    ORDER BY latest_pay_date DESC, e.id
    LIMIT 1
");

$employment = $employmentStmt->fetch(PDO::FETCH_ASSOC);

if (!$employment) {
    payroll_write_test_fail(
        'A payroll employment with at least one payslip is required.'
    );
}

$employmentId = (int)$employment['employment_id'];
$testDate = (string)$employment['latest_pay_date'];

$categories = payroll_write_get_categories($pdo);
$categoryByName = [];

foreach ($categories as $category) {
    $categoryByName[(string)$category['name']] = (int)$category['id'];
}

foreach (['BASIC PAY', 'TAXES', 'BONUS'] as $requiredCategory) {
    payroll_write_test_assert(
        isset($categoryByName[$requiredCategory]),
        "Missing required payroll category: {$requiredCategory}"
    );
}

$beforeSameDate = payroll_write_test_count(
    $pdo,
    "
    SELECT COUNT(*)
    FROM payroll_payslips
    WHERE employment_id = ?
      AND pay_date = ?
    ",
    [$employmentId, $testDate]
);

$transactionStarted = false;

try {
    $pdo->beginTransaction();
    $transactionStarted = true;

    $header = payroll_write_validate_header(
        $pdo,
        [
            'employment_id' => $employmentId,
            'pay_date' => $testDate,
            'tax_code' => 'TEST',
            'annual_salary' => '12345.67',
        ]
    );

    $lines = payroll_write_validate_lines(
        $pdo,
        [
            [
                'id' => 0,
                'category_id' => $categoryByName['BASIC PAY'],
                'code' => 'TEST BASIC',
                'description' => 'Payroll write test basic pay',
                'amount' => '1000.00',
            ],
            [
                'id' => 0,
                'category_id' => $categoryByName['TAXES'],
                'code' => 'TEST TAX',
                'description' => 'Payroll write test tax',
                'amount' => '200.00',
            ],
        ],
        null
    );

    $newPayslipId = payroll_write_save_payslip(
        $pdo,
        null,
        $header,
        $lines,
        false
    );

    payroll_write_test_assert(
        $newPayslipId > 0,
        'Creating a payslip must return a new ID.'
    );

    $afterSameDate = payroll_write_test_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM payroll_payslips
        WHERE employment_id = ?
          AND pay_date = ?
        ",
        [$employmentId, $testDate]
    );

    payroll_write_test_assert(
        $afterSameDate === $beforeSameDate + 1,
        'A same-date supplemental payslip must be allowed.'
    );

    $createdSummary = payroll_ui_get_payslip(
        $pdo,
        $newPayslipId
    );

    payroll_write_test_assert(
        $createdSummary !== null,
        'New payslip must immediately appear in payroll_payslip_summary.'
    );

    payroll_write_test_assert(
        (int)$createdSummary['line_item_count'] === 2,
        'New payslip must contain both submitted lines.'
    );

    payroll_write_test_float(
        $createdSummary['total_gross'],
        1000.00,
        'New payslip gross is incorrect.'
    );

    payroll_write_test_float(
        $createdSummary['total_deductions'],
        200.00,
        'New payslip deductions are incorrect.'
    );

    payroll_write_test_float(
        $createdSummary['net_pay'],
        800.00,
        'New payslip net pay is incorrect.'
    );

    $createdLines = payroll_write_get_lines(
        $pdo,
        $newPayslipId
    );

    payroll_write_test_assert(
        count($createdLines) === 2,
        'Created payslip must expose two raw lines.'
    );

    $basicLineId = (int)$createdLines[0]['id'];
    $taxLineId = (int)$createdLines[1]['id'];

    $editHeader = payroll_write_validate_header(
        $pdo,
        [
            'employment_id' => $employmentId,
            'pay_date' => $testDate,
            'tax_code' => 'TEST2',
            'annual_salary' => '23456.78',
        ]
    );

    $editLines = payroll_write_validate_lines(
        $pdo,
        [
            [
                'id' => $basicLineId,
                'category_id' => $categoryByName['BASIC PAY'],
                'code' => 'TEST BASIC UPDATED',
                'description' => 'Updated payroll write test basic pay',
                'amount' => '1100.00',
            ],
            [
                'id' => $taxLineId,
                'delete' => '1',
                'category_id' => $categoryByName['TAXES'],
                'code' => 'TEST TAX',
                'description' => 'Payroll write test tax',
                'amount' => '200.00',
            ],
            [
                'id' => 0,
                'category_id' => $categoryByName['BONUS'],
                'code' => 'TEST BONUS',
                'description' => 'Payroll write test bonus',
                'amount' => '300.00',
            ],
        ],
        $newPayslipId
    );

    $savedPayslipId = payroll_write_save_payslip(
        $pdo,
        $newPayslipId,
        $editHeader,
        $editLines,
        false
    );

    payroll_write_test_assert(
        $savedPayslipId === $newPayslipId,
        'Editing a payslip must preserve its payslip ID.'
    );

    $editedHeader = payroll_write_get_header(
        $pdo,
        $newPayslipId
    );

    payroll_write_test_assert(
        (string)$editedHeader['tax_code'] === 'TEST2',
        'Edited tax code was not persisted.'
    );

    payroll_write_test_float(
        $editedHeader['annual_salary'],
        23456.78,
        'Edited annual salary was not persisted.'
    );

    $editedLines = payroll_write_get_lines(
        $pdo,
        $newPayslipId
    );

    payroll_write_test_assert(
        count($editedLines) === 2,
        'Edit must leave one updated existing line and one new line.'
    );

    $editedLineIds = array_map(
        fn(array $row): int => (int)$row['id'],
        $editedLines
    );

    payroll_write_test_assert(
        in_array($basicLineId, $editedLineIds, true),
        'Updating an existing line must preserve its line ID.'
    );

    payroll_write_test_assert(
        !in_array($taxLineId, $editedLineIds, true),
        'A line marked for deletion must be removed.'
    );

    $editedSummary = payroll_ui_get_payslip(
        $pdo,
        $newPayslipId
    );

    payroll_write_test_float(
        $editedSummary['total_gross'],
        1400.00,
        'Edited payslip gross is incorrect.'
    );

    payroll_write_test_float(
        $editedSummary['bonus'],
        300.00,
        'Edited payslip bonus is incorrect.'
    );

    payroll_write_test_float(
        $editedSummary['total_deductions'],
        0.00,
        'Edited payslip deductions should be zero.'
    );

    payroll_write_test_float(
        $editedSummary['net_pay'],
        1400.00,
        'Edited payslip net pay is incorrect.'
    );

    $allDeleteRows = [];

    foreach ($editedLines as $line) {
        $allDeleteRows[] = [
            'id' => (int)$line['id'],
            'delete' => '1',
            'category_id' => (int)$line['category_id'],
            'code' => (string)$line['code'],
            'description' => (string)$line['description'],
            'amount' => (string)$line['amount'],
        ];
    }

    $allDeleteRejected = false;

    try {
        payroll_write_validate_lines(
            $pdo,
            $allDeleteRows,
            $newPayslipId
        );
    } catch (RuntimeException $e) {
        $allDeleteRejected = str_contains(
            $e->getMessage(),
            'at least one line'
        );
    }

    payroll_write_test_assert(
        $allDeleteRejected,
        'Server validation must reject deleting every line from a payslip.'
    );

    $pdo->rollBack();
    $transactionStarted = false;
} catch (Throwable $e) {
    if ($transactionStarted && $pdo->inTransaction()) {
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

$afterPayslips = payroll_write_test_count(
    $pdo,
    'SELECT COUNT(*) FROM payroll_payslips'
);

$afterLines = payroll_write_test_count(
    $pdo,
    'SELECT COUNT(*) FROM payroll_line_items'
);

payroll_write_test_assert(
    $afterPayslips === $beforePayslips,
    'Write regression test must roll back its temporary payslip.'
);

payroll_write_test_assert(
    $afterLines === $beforeLines,
    'Write regression test must roll back its temporary line items.'
);

$editPage = __DIR__ . '/../../public/payroll_payslip_edit.php';
$historyPage = __DIR__ . '/../../public/payroll_payslips.php';
$writeHelper = __DIR__ . '/../payroll_write.php';

payroll_write_test_source_contains(
    $editPage,
    'csrf_input()',
    'Payslip editor must include an explicit CSRF token.'
);

payroll_write_test_source_contains(
    $editPage,
    'payroll_write_save_payslip(',
    'Payslip editor must use the transactional payroll save helper.'
);

payroll_write_test_source_contains(
    $historyPage,
    'payroll_payslip_edit.php?employment_id=',
    'Payslip history must expose the add-payslip action.'
);

payroll_write_test_source_contains(
    $historyPage,
    'payroll_payslip_edit.php?id=',
    'Payslip history must expose the edit-payslip action.'
);

payroll_write_test_source_contains(
    $writeHelper,
    'FOR UPDATE',
    'Existing payslips must be locked during transactional updates.'
);

$writeSource = file_get_contents($writeHelper);

payroll_write_test_assert(
    $writeSource !== false
    && !str_contains(
        $writeSource,
        'DELETE FROM payroll_payslips'
    ),
    'This tranche must not introduce whole-payslip deletion.'
);

echo "Payroll write regression checks passed.\n";
echo "Temporary same-date supplemental payslip: rolled back.\n";
echo "Create/update/delete-line path: verified.\n";
echo "Zero-line guardrail: verified.\n";
echo "Permanent payroll row counts unchanged: "
    . $afterPayslips
    . " payslips / "
    . $afterLines
    . " lines.\n";

exit(0);
