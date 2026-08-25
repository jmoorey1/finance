<?php

require_once '../config/db.php';
require_once '../scripts/payroll_ui.php';

$employments = payroll_ui_get_employments($pdo);

$requestedEmploymentId = isset($_GET['employment_id'])
    ? (int)$_GET['employment_id']
    : null;

$employment = payroll_ui_resolve_employment(
    $employments,
    $requestedEmploymentId
);

$latestPayslip = null;
$currentYtd = null;
$expenseTotals = null;
$taxYears = [];
$recentPayslips = [];

if ($employment !== null) {
    $employmentId = (int)$employment['employment_id'];

    $latestPayslip = payroll_ui_get_latest_payslip(
        $pdo,
        $employmentId
    );

    $currentYtd = payroll_ui_get_current_ytd(
        $pdo,
        $employmentId
    );

    $expenseTotals = payroll_ui_get_expense_totals(
        $pdo,
        $employmentId
    );

    $taxYears = payroll_ui_get_tax_year_summaries(
        $pdo,
        $employmentId
    );

    $recentPayslips = payroll_ui_get_recent_payslips(
        $pdo,
        $employmentId,
        6
    );
}

include '../layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="mb-1">🧾 Payroll</h1>
        <p class="text-muted mb-0">
            Payslips, earnings, deductions and employment expenses.
        </p>
    </div>

    <?php if (count($employments) > 0): ?>

        <div class="d-flex flex-wrap align-items-end gap-2">

            <?php if ($employment !== null): ?>

                <a
                    class="btn btn-outline-primary"
                    href="payroll_reporting.php?employment_id=<?= (int)$employment['employment_id'] ?>"
                >
                    📈 Reporting
                </a>

            <?php endif; ?>

            <form
                method="get"
                class="d-flex align-items-end gap-2"
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
                                <?= $employment
                                    && (int)$option['employment_id']
                                    === (int)$employment['employment_id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= payroll_ui_h($option['full_name']) ?>
                                <?= !empty($option['employee_number'])
                                    ? ' · ' . payroll_ui_h($option['employee_number'])
                                    : '' ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <noscript>
                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        Go
                    </button>
                </noscript>

            </form>

        </div>

    <?php endif; ?>
</div>

<?php if ($employment === null): ?>

    <div class="alert alert-warning">
        No payroll employments are available.
    </div>

<?php else: ?>

    <?php
        $employmentId = (int)$employment['employment_id'];

        $currentTaxYearStart = payroll_ui_current_tax_year_start();
        $currentTaxYearLabel = payroll_ui_tax_year_label(
            $currentTaxYearStart
        );
    ?>

    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Latest payslip
                    </div>

                    <div class="fs-4 fw-bold">
                        <?= $latestPayslip
                            ? (
                                new DateTimeImmutable(
                                    $latestPayslip['pay_date']
                                )
                            )->format('d M Y')
                            : '—' ?>
                    </div>

                    <?php if ($latestPayslip): ?>
                        <div class="small text-muted">
                            Tax month
                            <?= (int)$latestPayslip['tax_month'] ?>
                            ·
                            <?= payroll_ui_h(
                                $latestPayslip['tax_year']
                            ) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">

                    <div class="text-muted small">
                        Current annual salary
                    </div>

                    <div class="fs-4 fw-bold">
                        <?= $latestPayslip
                            && $latestPayslip['annual_salary'] !== null
                            ? payroll_ui_money(
                                $latestPayslip['annual_salary']
                            )
                            : '—' ?>
                    </div>

                    <div class="small text-muted">
                        Tax code:
                        <?= $latestPayslip
                            && $latestPayslip['tax_code']
                            ? payroll_ui_h(
                                $latestPayslip['tax_code']
                            )
                            : '—' ?>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">

                    <div class="text-muted small">
                        <?= payroll_ui_h($currentTaxYearLabel) ?>
                        YTD net pay
                    </div>

                    <div class="fs-4 fw-bold">
                        <?= $currentYtd
                            ? payroll_ui_money(
                                $currentYtd['ytd_net_pay']
                            )
                            : '—' ?>
                    </div>

                    <div class="small text-muted">
                        <?= $currentYtd
                            ? (int)$currentYtd['months_processed']
                                . ' tax month(s) represented'
                            : 'No payslips in current tax year' ?>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">

                    <div class="text-muted small">
                        Recorded employment expenses
                    </div>

                    <div class="fs-4 fw-bold">
                        <?= payroll_ui_money(
                            $expenseTotals['total_expenses'] ?? 0
                        ) ?>
                    </div>

                    <div class="small text-muted">
                        <?= (int)($expenseTotals['report_count'] ?? 0) ?>
                        reports ·
                        <?= (int)($expenseTotals['expense_count'] ?? 0) ?>
                        items
                    </div>

                </div>
            </div>
        </div>

    </div>

    <?php if ($currentYtd): ?>

        <div class="card mb-4">

            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>
                    <?= payroll_ui_h($currentYtd['tax_year']) ?>
                    year to date
                </strong>

                <span class="badge bg-secondary">
                    <?= (int)$currentYtd['months_processed'] ?>
                    tax month(s)
                </span>
            </div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Gross</div>
                        <div class="fw-bold">
                            <?= payroll_ui_money(
                                $currentYtd['ytd_gross']
                            ) ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Basic pay</div>
                        <div class="fw-bold">
                            <?= payroll_ui_money(
                                $currentYtd['ytd_basic_pay']
                            ) ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Bonus</div>
                        <div class="fw-bold">
                            <?= payroll_ui_money(
                                $currentYtd['ytd_bonus']
                            ) ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Tax</div>
                        <div class="fw-bold">
                            <?= payroll_ui_money(
                                $currentYtd['ytd_taxes']
                            ) ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Pension</div>
                        <div class="fw-bold">
                            <?= payroll_ui_money(
                                $currentYtd['ytd_pension']
                            ) ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-2">
                        <div class="text-muted small">Net</div>
                        <div class="fw-bold text-success">
                            <?= payroll_ui_money(
                                $currentYtd['ytd_net_pay']
                            ) ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    <?php endif; ?>

    <div class="row g-4 mb-4">

        <div class="col-lg-8">

            <div class="card h-100">

                <div class="card-header d-flex justify-content-between align-items-center">

                    <strong>Recent payslips</strong>

                    <a
                        class="btn btn-sm btn-outline-primary"
                        href="payroll_payslips.php?employment_id=<?= $employmentId ?>"
                    >
                        View all
                    </a>

                </div>

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Tax year</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">Tax</th>
                                <th class="text-end">Net</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (count($recentPayslips) === 0): ?>

                            <tr>
                                <td colspan="6" class="text-muted">
                                    No payslips found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($recentPayslips as $row): ?>

                                <tr>

                                    <td>
                                        <?= (
                                            new DateTimeImmutable(
                                                $row['pay_date']
                                            )
                                        )->format('d M Y') ?>
                                    </td>

                                    <td>
                                        <?= payroll_ui_h(
                                            $row['tax_year']
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= payroll_ui_money(
                                            $row['total_gross']
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= payroll_ui_money(
                                            $row['taxes']
                                        ) ?>
                                    </td>

                                    <td class="text-end fw-bold">
                                        <?= payroll_ui_money(
                                            $row['net_pay']
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <a
                                            href="payroll_payslip.php?id=<?= (int)$row['payslip_id'] ?>"
                                            class="btn btn-sm btn-outline-secondary"
                                        >
                                            Open
                                        </a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card h-100">

                <div class="card-header">
                    <strong>Expense funding</strong>
                </div>

                <div class="card-body">

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>Corporate</span>
                        <strong>
                            <?= payroll_ui_money(
                                $expenseTotals['corporate_expenses'] ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>Personal</span>
                        <strong>
                            <?= payroll_ui_money(
                                $expenseTotals['personal_expenses'] ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between py-2">
                        <span>Total</span>
                        <strong>
                            <?= payroll_ui_money(
                                $expenseTotals['total_expenses'] ?? 0
                            ) ?>
                        </strong>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        Expense report management will be added
                        in the next write-enabled payroll tranche.
                    </p>

                </div>

            </div>

        </div>

    </div>

    <div class="card mb-4">

        <div class="card-header">
            <strong>Tax-year history</strong>
        </div>

        <div class="table-responsive">

            <table class="table table-striped align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th>Tax year</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Bonus</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Pension</th>
                        <th class="text-end">Net</th>
                        <th class="text-end">Payslips</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($taxYears as $row): ?>

                    <tr>

                        <td class="fw-bold">
                            <?= payroll_ui_h($row['tax_year']) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_ui_money(
                                $row['total_gross']
                            ) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_ui_money($row['bonus']) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_ui_money($row['taxes']) ?>
                        </td>

                        <td class="text-end">
                            <?= payroll_ui_money($row['pension']) ?>
                        </td>

                        <td class="text-end fw-bold">
                            <?= payroll_ui_money($row['net_pay']) ?>
                        </td>

                        <td class="text-end">
                            <?= (int)$row['payslip_count'] ?>
                        </td>

                        <td class="text-end">

                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="payroll_payslips.php?employment_id=<?= $employmentId ?>&tax_year_start=<?= (int)$row['tax_year_start'] ?>"
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

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
