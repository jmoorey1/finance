<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(
        STDERR,
        "This script must be run from the command line.\n"
    );

    exit(1);
}

require_once __DIR__
    . '/../../config/db.php';


function payroll_rsu_history_fail(
    string $message
): never {
    throw new RuntimeException(
        $message
    );
}


function payroll_rsu_history_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        payroll_rsu_history_fail(
            $message
        );
    }
}


function payroll_rsu_history_money(
    $actual,
    float $expected,
    string $message
): void {
    if (
        abs(
            (float)$actual
            - $expected
        ) > 0.01
    ) {
        payroll_rsu_history_fail(
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


/*
 * Exact source-reviewed historical Equity classifications.
 *
 * These are deliberately explicit rather than inferred from descriptions,
 * categories or Notional status.
 */
$expected = [

    166 => [
        'pay_date' =>
            '2022-05-31',

        'line_ids' => [
            1551,
            1552,
            1553,
            1554,
            1555,
            1904,
            2297,
            2298,
        ],

        'equity_compensation' =>
            11886.49,

        'equity_tax_ni' =>
            4783.11,
    ],

    173 => [
        'pay_date' =>
            '2022-11-30',

        'line_ids' => [
            1591,
            1592,
            1593,
            1594,
            1595,
            1905,
            2299,
            2300,
        ],

        'equity_compensation' =>
            7909.27,

        'equity_tax_ni' =>
            3103.78,
    ],

    180 => [
        'pay_date' =>
            '2023-05-31',

        'line_ids' => [
            1647,
            1648,
            1649,
            1650,
            1906,
            2296,
        ],

        'equity_compensation' =>
            14806.55,

        'equity_tax_ni' =>
            5910.45,
    ],

    187 => [
        'pay_date' =>
            '2023-11-30',

        'line_ids' => [
            1703,
            1704,
            1705,
            1706,
            1907,
            2295,
        ],

        'equity_compensation' =>
            15928.69,

        'equity_tax_ni' =>
            6250.17,
    ],

    194 => [
        'pay_date' =>
            '2024-05-31',

        'line_ids' => [
            1755,
            1756,
            1757,
            1758,
            1759,
            1908,
            2089,
        ],

        'equity_compensation' =>
            29777.92,

        'equity_tax_ni' =>
            12461.03,
    ],

    201 => [
        'pay_date' =>
            '2024-11-29',

        'line_ids' => [
            1820,
            1821,
            1822,
            1823,
            1909,
        ],

        'equity_compensation' =>
            15769.82,

        'equity_tax_ni' =>
            6533.67,
    ],

    213 => [
        'pay_date' =>
            '2024-11-29',

        'line_ids' => [
            1912,
            1913,
            1914,
            1915,
            1916,
        ],

        'equity_compensation' =>
            14000.68,

        'equity_tax_ni' =>
            5493.61,
    ],

    208 => [
        'pay_date' =>
            '2025-05-30',

        'line_ids' => [
            1869,
            1870,
            1871,
            1872,
            1910,
            2294,
        ],

        'equity_compensation' =>
            16071.59,

        'equity_tax_ni' =>
            6994.90,
    ],

    212 => [
        'pay_date' =>
            '2025-08-29',

        'line_ids' => [
            1900,
            1901,
            1902,
            1903,
            1911,
        ],

        'equity_compensation' =>
            9902.37,

        'equity_tax_ni' =>
            4421.08,
    ],

    344 => [
        'pay_date' =>
            '2025-11-28',

        'line_ids' => [
            2247,
            2248,
            2249,
            2250,
            2251,
            2252,
        ],

        'equity_compensation' =>
            17879.21,

        'equity_tax_ni' =>
            8172.77,
    ],

    347 => [
        'pay_date' =>
            '2026-02-27',

        'line_ids' => [
            2271,
            2272,
            2273,
            2274,
            2275,
        ],

        'equity_compensation' =>
            8944.80,

        'equity_tax_ni' =>
            3968.83,
    ],
];


$expectedTotalLines = 0;


foreach (
    $expected
    as $payslipId => $case
) {

    $expectedTotalLines +=
        count(
            $case[
                'line_ids'
            ]
        );


    /*
     * Confirm identity/date and the reporting-view economic rollup.
     */
    $stmt =
        $pdo->prepare("
            SELECT
                p.id AS payslip_id,
                p.pay_date,
                person.full_name AS person_name,
                report.equity_compensation,
                report.equity_tax_ni

            FROM payroll_payslips p

            JOIN payroll_employments e
              ON e.id = p.employment_id

            JOIN payroll_people person
              ON person.id = e.person_id

            JOIN payroll_payslip_reporting_summary report
              ON report.payslip_id = p.id

            WHERE p.id = ?

            LIMIT 1
        ");


    $stmt->execute([
        $payslipId,
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    payroll_rsu_history_assert(
        $row !== false,
        "Reviewed RSU payslip #{$payslipId} is missing."
    );


    payroll_rsu_history_assert(
        (string)$row[
            'person_name'
        ] === 'India Moorey',
        "Reviewed RSU payslip #{$payslipId} belongs to the wrong person."
    );


    payroll_rsu_history_assert(
        (string)$row[
            'pay_date'
        ]
        === (string)$case[
            'pay_date'
        ],
        "Reviewed RSU payslip #{$payslipId} has an unexpected pay date."
    );


    payroll_rsu_history_money(
        $row[
            'equity_compensation'
        ],
        (float)$case[
            'equity_compensation'
        ],
        "Reviewed RSU payslip #{$payslipId} Equity compensation is incorrect."
    );


    payroll_rsu_history_money(
        $row[
            'equity_tax_ni'
        ],
        (float)$case[
            'equity_tax_ni'
        ],
        "Reviewed RSU payslip #{$payslipId} Equity tax / NI is incorrect."
    );


    /*
     * Confirm the exact reviewed Equity line-ID set.
     *
     * This permits Ordinary lines to coexist on a payslip if the source
     * genuinely contains them, but the historical Equity subset itself must
     * remain exactly the reviewed set.
     */
    $stmt =
        $pdo->prepare("
            SELECT id
            FROM payroll_line_items
            WHERE payslip_id = ?
              AND reporting_scope = 'equity'
            ORDER BY id
        ");


    $stmt->execute([
        $payslipId,
    ]);


    $actualLineIds =
        array_map(
            'intval',
            $stmt->fetchAll(
                PDO::FETCH_COLUMN
            )
        );


    $expectedLineIds =
        array_map(
            'intval',
            $case[
                'line_ids'
            ]
        );


    sort(
        $actualLineIds
    );


    sort(
        $expectedLineIds
    );


    payroll_rsu_history_assert(
        $actualLineIds
        === $expectedLineIds,
        "Reviewed RSU payslip #{$payslipId} does not have the exact expected Equity line set."
    );
}


payroll_rsu_history_assert(
    count(
        $expected
    ) === 11,
    'Historical RSU regression must represent 11 reviewed payslips.'
);


payroll_rsu_history_assert(
    $expectedTotalLines === 67,
    'Historical RSU regression must represent 67 reviewed Equity lines.'
);


/*
 * Confirm the aggregate reviewed set directly.
 */
$payslipIds =
    array_map(
        'intval',
        array_keys(
            $expected
        )
    );


$placeholders =
    implode(
        ',',
        array_fill(
            0,
            count(
                $payslipIds
            ),
            '?'
        )
    );


$stmt =
    $pdo->prepare("
        SELECT COUNT(*)
        FROM payroll_line_items
        WHERE payslip_id IN ({$placeholders})
          AND reporting_scope = 'equity'
    ");


$stmt->execute(
    $payslipIds
);


$actualReviewedEquityCount =
    (int)$stmt->fetchColumn();


payroll_rsu_history_assert(
    $actualReviewedEquityCount
    === $expectedTotalLines,
    'Reviewed historical RSU Equity line count is incorrect.'
);


echo "Payroll historical RSU Equity checks passed.\n";
echo "Reviewed payslips: "
    . count(
        $expected
    )
    . ".\n";

echo "Reviewed Equity lines: "
    . $expectedTotalLines
    . ".\n";

echo "Exact historical Equity line-ID sets: verified.\n";
echo "Historical Equity compensation rollups: verified.\n";
echo "Historical Equity tax / NI rollups: verified.\n";

exit(0);
