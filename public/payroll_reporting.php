<?php

require_once '../config/db.php';
require_once '../scripts/payroll_ui.php';
require_once '../scripts/payroll_finance.php';
require_once '../scripts/payroll_reporting.php';

$employments =
    payroll_ui_get_employments(
        $pdo
    );

$requestedEmploymentId =
    isset($_GET['employment_id'])
        ? (int)$_GET['employment_id']
        : null;

$employment =
    payroll_ui_resolve_employment(
        $employments,
        $requestedEmploymentId
    );

$requestedTaxYearStart =
    isset($_GET['tax_year_start'])
        ? (int)$_GET['tax_year_start']
        : null;

$report = null;

if ($employment !== null) {
    $report =
        payroll_reporting_build_report(
            $pdo,
            (int)$employment[
                'employment_id'
            ],
            $requestedTaxYearStart
        );
}

function payroll_reporting_page_money(
    $value
): string {
    return payroll_ui_money(
        (float)$value
    );
}

function payroll_reporting_page_abs_money(
    $value
): string {
    return payroll_ui_money(
        abs(
            (float)$value
        )
    );
}

function payroll_reporting_page_status_label(
    ?string $status
): string {
    if ($status === null) {
        return 'Not configured';
    }

    return payroll_finance_status_label(
        $status
    );
}

function payroll_reporting_page_status_class(
    ?string $status
): string {
    return match ($status) {
        'settled' =>
            'bg-success',

        'partial' =>
            'bg-warning text-dark',

        'overlinked' =>
            'bg-danger',

        'unlinked' =>
            'bg-secondary',

        'no_settlement' =>
            'bg-warning text-dark',

        'out_of_scope' =>
            'bg-light text-dark border',

        'unconfigured',
        null =>
            'bg-light text-dark border',

        default =>
            'bg-light text-dark border',
    };
}

include '../layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

    <div>

        <div class="small mb-2">
            <a href="payroll.php<?= $employment !== null
                ? '?employment_id=' . (int)$employment['employment_id']
                : '' ?>">
                ← Payroll
            </a>
        </div>

        <h1 class="mb-1">
            📈 Payroll Reporting
        </h1>

        <p class="text-muted mb-0">
            Earnings, settlement, tax and salary trends using explicit
            PAYE tax-period reporting.
        </p>

    </div>

    <?php if ($employment !== null && $report !== null): ?>

        <form
            method="get"
            class="d-flex flex-wrap align-items-end gap-2"
        >

            <div>

                <label
                    for="employment_id"
                    class="form-label small text-muted mb-1"
                >
                    Employee
                </label>

                <select
                    name="employment_id"
                    id="employment_id"
                    class="form-select"
                    onchange="this.form.submit()"
                >

                    <?php foreach ($employments as $option): ?>

                        <option
                            value="<?= (int)$option['employment_id'] ?>"
                            <?= (int)$option['employment_id']
                                === (int)$employment['employment_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= payroll_ui_h(
                                $option['full_name']
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div>

                <label
                    for="tax_year_start"
                    class="form-label small text-muted mb-1"
                >
                    Tax year
                </label>

                <select
                    name="tax_year_start"
                    id="tax_year_start"
                    class="form-select"
                    onchange="this.form.submit()"
                >

                    <?php foreach ($report['tax_year_options'] as $option): ?>

                        <option
                            value="<?= (int)$option['tax_year_start'] ?>"
                            <?= (int)$option['tax_year_start']
                                === (int)$report['tax_year_start']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= payroll_ui_h(
                                $option['tax_year']
                            ) ?>
                            ·
                            <?= (int)$option['payslip_count'] ?>
                            payslip<?= (int)$option['payslip_count'] === 1
                                ? ''
                                : 's' ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <noscript>
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Go
                </button>
            </noscript>

        </form>

    <?php endif; ?>

</div>

<?php if ($employment === null): ?>

    <div class="alert alert-warning">
        No payroll employments are available.
    </div>

<?php elseif ($report === null || $report['tax_year_start'] === null): ?>

    <div class="alert alert-warning">
        No payslips are available for this employment.
    </div>

<?php else: ?>

    <?php
        $totals =
            $report[
                'totals'
            ];

        $quality =
            $report[
                'quality'
            ];

        $months =
            $report[
                'months'
            ];

        $latestMonth =
            $months !== []
                ? $months[
                    count(
                        $months
                    ) - 1
                ]
                : null;

        $latestSalary =
            $latestMonth[
                'annual_salary'
            ]
                ?? null;
    ?>

    <div class="alert alert-light border d-flex flex-wrap justify-content-between gap-2">

        <div>
            <strong>
                Period basis:
            </strong>
            <?= payroll_ui_h(
                $report['period_basis_label']
            ) ?>
        </div>

        <div class="text-muted">
            <?= payroll_ui_h(
                $report['tax_year']
            ) ?>
            <?php if ($report['through_tax_month'] !== null): ?>
                · through tax month
                <?= (int)$report['through_tax_month'] ?>
            <?php endif; ?>
        </div>

    </div>

    <?php if ($quality['has_warning']): ?>

        <div class="alert alert-warning">

            <div class="fw-bold mb-2">
                Reporting data-quality notice
            </div>

            <ul class="mb-0">

                <?php foreach ($quality['warnings'] as $warning): ?>

                    <li>
                        <?= payroll_ui_h($warning) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

            <div class="small mt-2">
                Payroll figures remain the values represented by the
                Payroll database. Finance settlement is displayed separately
                and is not used to silently rewrite Payroll history.
            </div>

        </div>

    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-2">

            <div class="card h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Cash earnings
                    </div>

                    <div class="fs-5 fw-bold">
                        <?= payroll_reporting_page_money(
                            $totals['cash_earnings']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-6 col-lg-2">

            <div class="card h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Payroll net
                    </div>

                    <div class="fs-5 fw-bold">
                        <?= payroll_reporting_page_money(
                            $totals['payroll_net']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-6 col-lg-2">

            <div class="card h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Bank settled
                    </div>

                    <div class="fs-5 fw-bold text-success">
                        <?= payroll_reporting_page_money(
                            $totals['bank_settled']
                        ) ?>
                    </div>

                    <div class="small text-muted">
                        <?= (int)$totals['finance_settled_count'] ?>
                        settled payslip(s)
                    </div>

                </div>

            </div>

        </div>

        <div class="col-6 col-lg-2">

            <div class="card h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Tax
                    </div>

                    <div class="fs-5 fw-bold">
                        <?= payroll_reporting_page_abs_money(
                            $totals['tax']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-6 col-lg-2">

            <div class="card h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Bonus
                    </div>

                    <div class="fs-5 fw-bold">
                        <?= payroll_reporting_page_money(
                            $totals['bonus']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-6 col-lg-2">

            <div class="card h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Annual salary
                    </div>

                    <div class="fs-5 fw-bold">
                        <?= $latestSalary !== null
                            ? payroll_reporting_page_money(
                                $latestSalary
                            )
                            : '—' ?>
                    </div>

                    <div class="small text-muted">
                        Latest represented month
                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="row g-4 mb-4">

        <div class="col-xl-8">

            <div class="card h-100">

                <div class="card-header">
                    <strong>
                        Earnings and settlement by tax month
                    </strong>
                </div>

                <div class="card-body">
                    <canvas
                        id="payrollSettlementChart"
                        height="110"
                    ></canvas>
                </div>

            </div>

        </div>

        <div class="col-xl-4">

            <div class="card h-100">

                <div class="card-header">
                    <strong>
                        Settlement coverage
                    </strong>
                </div>

                <div class="card-body">

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>Settled</span>
                        <strong>
                            <?= (int)$quality['settled_count'] ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>In-scope unsettled</span>
                        <strong>
                            <?= (int)$quality['unsettled_count'] ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>Out of Finance scope</span>
                        <strong>
                            <?= (int)$quality['out_of_scope_count'] ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between py-2">
                        <span>Finance not configured</span>
                        <strong>
                            <?= (int)$quality['unconfigured_count'] ?>
                        </strong>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="row g-4 mb-4">

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-header">
                    <strong>
                        Tax, pension and bonus
                    </strong>
                </div>

                <div class="card-body">
                    <canvas
                        id="payrollDeductionsChart"
                        height="130"
                    ></canvas>
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-header">
                    <strong>
                        Annual salary progression
                    </strong>
                </div>

                <div class="card-body">
                    <canvas
                        id="payrollSalaryChart"
                        height="130"
                    ></canvas>
                </div>

            </div>

        </div>

    </div>

    <div class="card mb-4">

        <div class="card-header">

            <strong>
                YTD comparison
            </strong>

            <?php if ($report['through_tax_month'] !== null): ?>

                <span class="text-muted small">
                    ·
                    <?= payroll_ui_h(
                        $report['tax_year']
                    ) ?>
                    versus
                    <?= payroll_ui_h(
                        $report['prior_tax_year']
                    ) ?>
                    through tax month
                    <?= (int)$report['through_tax_month'] ?>
                </span>

            <?php endif; ?>

        </div>

        <div class="table-responsive">

            <table class="table table-sm table-striped align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th>Measure</th>
                        <th class="text-end">
                            <?= payroll_ui_h(
                                $report['tax_year']
                            ) ?>
                        </th>
                        <th class="text-end">
                            <?= payroll_ui_h(
                                $report['prior_tax_year']
                            ) ?>
                        </th>
                        <th class="text-end">Change</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($report['ytd_comparison'] as $row): ?>

                    <tr>

                        <td>
                            <?= payroll_ui_h(
                                $row['label']
                            ) ?>
                        </td>

                        <td class="text-end fw-bold">
                            <?= payroll_reporting_page_money(
                                $row['current']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_money(
                                $row['previous']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= $row['change_pct'] !== null
                                ? (
                                    (
                                        (float)$row['change_pct'] > 0
                                            ? '+'
                                            : ''
                                    )
                                    . number_format(
                                        (float)$row['change_pct'],
                                        1
                                    )
                                    . '%'
                                )
                                : '—' ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

    <div class="card mb-4">

        <div class="card-header">
            <strong>
                Tax-month figures
            </strong>
        </div>

        <div class="table-responsive">

            <table class="table table-sm table-hover align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th>Tax month</th>
                        <th>Pay date(s)</th>
                        <th class="text-end">Cash earnings</th>
                        <th class="text-end">Payroll net</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Pension</th>
                        <th class="text-end">Bonus</th>
                        <th class="text-end">Bank settled</th>
                        <th class="text-end">Payslips</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($months as $month): ?>

                    <tr>

                        <td class="fw-bold">
                            <?= payroll_ui_h(
                                $month['long_label']
                            ) ?>
                        </td>

                        <td>
                            <?= payroll_ui_h(
                                (
                                    new DateTimeImmutable(
                                        $month['first_pay_date']
                                    )
                                )->format('d M Y')
                            ) ?>

                            <?php if (
                                $month['latest_pay_date']
                                !== $month['first_pay_date']
                            ): ?>

                                <span class="text-muted">
                                    –
                                    <?= payroll_ui_h(
                                        (
                                            new DateTimeImmutable(
                                                $month['latest_pay_date']
                                            )
                                        )->format('d M Y')
                                    ) ?>
                                </span>

                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_money(
                                $month['totals']['cash_earnings']
                            ) ?>
                        </td>

                        <td class="text-end fw-bold">
                            <?= payroll_reporting_page_money(
                                $month['totals']['payroll_net']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_abs_money(
                                $month['totals']['tax']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_abs_money(
                                $month['totals']['pension']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_money(
                                $month['totals']['bonus']
                            ) ?>
                        </td>

                        <td class="text-end text-success">
                            <?= payroll_reporting_page_money(
                                $month['totals']['bank_settled']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= (int)$month['totals']['payslip_count'] ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

    <div class="card mb-4">

        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">

            <strong>
                Payslip reconciliation
            </strong>

            <a
                class="btn btn-sm btn-outline-primary"
                href="payroll_payslips.php?employment_id=<?= (int)$employment['employment_id'] ?>&tax_year_start=<?= (int)$report['tax_year_start'] ?>"
            >
                Payslip history
            </a>

        </div>

        <div class="table-responsive">

            <table class="table table-sm table-striped align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Tax month</th>
                        <th class="text-end">Cash earnings</th>
                        <th class="text-end">Payroll net</th>
                        <th>Settlement basis</th>
                        <th>Finance status</th>
                        <th class="text-end">Bank linked</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($report['payslips'] as $row): ?>

                    <tr>

                        <td>
                            <?= payroll_ui_h(
                                (
                                    new DateTimeImmutable(
                                        $row['pay_date']
                                    )
                                )->format('d M Y')
                            ) ?>
                        </td>

                        <td>
                            M<?= (int)$row['tax_month'] ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_money(
                                $row['cash_earnings']
                            ) ?>
                        </td>

                        <td class="text-end fw-bold">
                            <?= payroll_reporting_page_money(
                                $row['net_pay']
                            ) ?>
                        </td>

                        <td>
                            <?= payroll_ui_h(
                                match (
                                    (string)$row['settlement_amount_source']
                                ) {
                                    'statement_amount_paid' =>
                                        'Amount Paid',

                                    'statement_net_pay' =>
                                        'Statement Net Pay',

                                    default =>
                                        'Calculated lines',
                                }
                            ) ?>
                        </td>

                        <td>

                            <span
                                class="badge <?= payroll_reporting_page_status_class(
                                    $row['link_status']
                                ) ?>"
                            >
                                <?= payroll_ui_h(
                                    payroll_reporting_page_status_label(
                                        $row['link_status']
                                    )
                                ) ?>
                            </span>

                        </td>

                        <td class="text-end">
                            <?= payroll_reporting_page_money(
                                $row['linked_amount'] ?? 0
                            ) ?>
                        </td>

                        <td class="text-end">

                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="payroll_payslip.php?id=<?= (int)$row['payslip_id'] ?>"
                            >
                                Open
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
    const payrollMonthRows = <?= json_encode(
        $months,
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const salaryHistory = <?= json_encode(
        $report['salary_history'],
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const monthLabels = payrollMonthRows.map(
        row => row.label
    );

    new Chart(
        document.getElementById('payrollSettlementChart'),
        {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        label: 'Cash earnings',
                        data: payrollMonthRows.map(
                            row => row.totals.cash_earnings
                        ),
                        borderColor: 'rgb(13, 110, 253)',
                        backgroundColor: 'rgba(13, 110, 253, 0.12)',
                        tension: 0.2
                    },
                    {
                        label: 'Payroll net',
                        data: payrollMonthRows.map(
                            row => row.totals.payroll_net
                        ),
                        borderColor: 'rgb(25, 135, 84)',
                        backgroundColor: 'rgba(25, 135, 84, 0.12)',
                        tension: 0.2
                    },
                    {
                        label: 'Bank settled',
                        data: payrollMonthRows.map(
                            row => row.totals.bank_settled
                        ),
                        borderColor: 'rgb(108, 117, 125)',
                        borderDash: [6, 4],
                        tension: 0.2
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value =>
                                '£' + Number(value).toLocaleString()
                        }
                    }
                }
            }
        }
    );

    new Chart(
        document.getElementById('payrollDeductionsChart'),
        {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        label: 'Tax',
                        data: payrollMonthRows.map(
                            row => Math.abs(row.totals.tax)
                        ),
                        backgroundColor: 'rgba(220, 53, 69, 0.65)'
                    },
                    {
                        label: 'Pension',
                        data: payrollMonthRows.map(
                            row => Math.abs(row.totals.pension)
                        ),
                        backgroundColor: 'rgba(111, 66, 193, 0.65)'
                    },
                    {
                        label: 'Bonus',
                        data: payrollMonthRows.map(
                            row => row.totals.bonus
                        ),
                        backgroundColor: 'rgba(255, 193, 7, 0.65)'
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value =>
                                '£' + Number(value).toLocaleString()
                        }
                    }
                }
            }
        }
    );

    new Chart(
        document.getElementById('payrollSalaryChart'),
        {
            type: 'line',
            data: {
                labels: salaryHistory.map(
                    row => row.pay_date
                ),
                datasets: [
                    {
                        label: 'Annual salary',
                        data: salaryHistory.map(
                            row => row.annual_salary
                        ),
                        borderColor: 'rgb(13, 202, 240)',
                        backgroundColor: 'rgba(13, 202, 240, 0.12)',
                        stepped: true
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: value =>
                                '£' + Number(value).toLocaleString()
                        }
                    }
                }
            }
        }
    );
    </script>

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
