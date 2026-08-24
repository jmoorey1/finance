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

$requestedTaxYearStart = null;

if (
    isset($_GET['tax_year_start'])
    && $_GET['tax_year_start'] !== ''
) {
    $requestedTaxYearStart = (int)$_GET['tax_year_start'];
}

$taxYearOptions = [];
$payslips = [];
$selectedTaxYearStart = null;

if ($employment !== null) {
    $employmentId = (int)$employment['employment_id'];

    $taxYearOptions = payroll_ui_get_tax_year_options(
        $pdo,
        $employmentId
    );

    if ($requestedTaxYearStart !== null) {
        foreach ($taxYearOptions as $option) {
            if (
                (int)$option['tax_year_start']
                === $requestedTaxYearStart
            ) {
                $selectedTaxYearStart = $requestedTaxYearStart;
                break;
            }
        }
    }

    $payslips = payroll_ui_get_payslips(
        $pdo,
        $employmentId,
        $selectedTaxYearStart
    );
}

include '../layout/header.php';
?>

<style>
    /*
     * The shared application shell caps normal pages at 960px. Payroll history
     * is a wide analytical table, so give this page enough desktop width to
     * show every financial column and the actions together. On narrower
     * screens Bootstrap's responsive table wrapper can still scroll normally.
     */
    body > .container {
        max-width: 1500px;
    }

    .payroll-history-table th,
    .payroll-history-table td {
        vertical-align: middle;
    }

    .payroll-history-table .payroll-nowrap {
        white-space: nowrap;
    }

    .payroll-history-table .payroll-actions {
        min-width: 118px;
        white-space: nowrap;
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

    <div>

        <div class="small mb-2">
            <a href="payroll.php<?= $employment
                ? '?employment_id='
                    . (int)$employment['employment_id']
                : '' ?>">
                ← Payroll dashboard
            </a>
        </div>

        <h1 class="mb-1">
            Payslip history
        </h1>

        <p class="text-muted mb-0">
            Every imported payroll document is kept separately,
            including supplemental payslips on the same date.
        </p>

    </div>

    <?php if ($employment !== null): ?>
        <a
            href="payroll_payslip_edit.php?employment_id=<?= (int)$employment['employment_id'] ?>"
            class="btn btn-primary"
        >
            + Add payslip
        </a>
    <?php endif; ?>

</div>

<?php if ($employment === null): ?>

    <div class="alert alert-warning">
        No payroll employments are available.
    </div>

<?php else: ?>

    <?php
        $employmentId = (int)$employment['employment_id'];
    ?>

    <form method="get" class="card card-body mb-4">

        <div class="row g-3 align-items-end">

            <div class="col-md-5">

                <label
                    for="employment_id"
                    class="form-label"
                >
                    Employee
                </label>

                <select
                    name="employment_id"
                    id="employment_id"
                    class="form-select"
                >

                    <?php foreach ($employments as $option): ?>

                        <option
                            value="<?= (int)$option['employment_id'] ?>"
                            <?= (int)$option['employment_id']
                                === $employmentId
                                ? 'selected'
                                : '' ?>
                        >
                            <?= payroll_ui_h(
                                $option['full_name']
                            ) ?>
                            <?= !empty($option['employee_number'])
                                ? ' · '
                                    . payroll_ui_h(
                                        $option['employee_number']
                                    )
                                : '' ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-md-4">

                <label
                    for="tax_year_start"
                    class="form-label"
                >
                    Tax year
                </label>

                <select
                    name="tax_year_start"
                    id="tax_year_start"
                    class="form-select"
                >

                    <option value="">
                        All tax years
                    </option>

                    <?php foreach ($taxYearOptions as $option): ?>

                        <option
                            value="<?= (int)$option['tax_year_start'] ?>"
                            <?= $selectedTaxYearStart
                                === (int)$option['tax_year_start']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= payroll_ui_h(
                                $option['tax_year']
                            ) ?>
                            (<?= (int)$option['payslip_count'] ?>)
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-md-3 d-flex gap-2">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Apply
                </button>

                <a
                    href="payroll_payslips.php?employment_id=<?= $employmentId ?>"
                    class="btn btn-outline-secondary"
                >
                    Reset
                </a>

            </div>

        </div>

    </form>

    <div class="d-flex justify-content-between align-items-center mb-2">

        <h2 class="h5 mb-0">
            <?= payroll_ui_h($employment['full_name']) ?>
        </h2>

        <span class="badge bg-secondary">
            <?= count($payslips) ?>
            payslip<?= count($payslips) === 1 ? '' : 's' ?>
        </span>

    </div>

    <div class="table-responsive">

        <table class="table table-hover table-striped table-sm align-middle payroll-history-table">

            <thead class="table-dark">

                <tr>
                    <th>Date</th>
                    <th class="payroll-nowrap">Tax period</th>
                    <th class="payroll-nowrap">Tax code</th>
                    <th class="text-end payroll-nowrap">Annual salary</th>
                    <th class="text-end payroll-nowrap">Basic</th>
                    <th class="text-end payroll-nowrap">Bonus</th>
                    <th class="text-end payroll-nowrap">Gross</th>
                    <th class="text-end payroll-nowrap">Tax</th>
                    <th class="text-end payroll-nowrap">Pension</th>
                    <th class="text-end payroll-nowrap">Net</th>
                    <th class="payroll-actions text-end"><span class="visually-hidden">Actions</span></th>
                </tr>

            </thead>

            <tbody>

            <?php if (count($payslips) === 0): ?>

                <tr>
                    <td
                        colspan="11"
                        class="text-muted"
                    >
                        No payslips match this filter.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($payslips as $row): ?>

                    <tr>

                        <td class="text-nowrap">
                            <?= (
                                new DateTimeImmutable(
                                    $row['pay_date']
                                )
                            )->format('d M Y') ?>
                        </td>

                        <td class="payroll-nowrap">
                            <?= payroll_ui_h(
                                $row['tax_year']
                            ) ?>
                            · M<?= (int)$row['tax_month'] ?>
                        </td>

                        <td class="payroll-nowrap">
                            <?= $row['tax_code']
                                ? payroll_ui_h(
                                    $row['tax_code']
                                )
                                : '—' ?>
                        </td>

                        <td class="text-end payroll-nowrap">
                            <?= $row['annual_salary'] !== null
                                ? payroll_ui_money(
                                    $row['annual_salary']
                                )
                                : '—' ?>
                        </td>

                        <td class="text-end payroll-nowrap">
                            <?= payroll_ui_money(
                                $row['basic_pay']
                            ) ?>
                        </td>

                        <td class="text-end payroll-nowrap">
                            <?= payroll_ui_money(
                                $row['bonus']
                            ) ?>
                        </td>

                        <td class="text-end payroll-nowrap">
                            <?= payroll_ui_money(
                                $row['total_gross']
                            ) ?>
                        </td>

                        <td class="text-end payroll-nowrap">
                            <?= payroll_ui_money(
                                $row['taxes']
                            ) ?>
                        </td>

                        <td class="text-end payroll-nowrap">
                            <?= payroll_ui_money(
                                $row['pension']
                            ) ?>
                        </td>

                        <td class="text-end fw-bold payroll-nowrap">
                            <?= payroll_ui_money(
                                $row['net_pay']
                            ) ?>
                        </td>

                        <td class="text-end payroll-actions">

                            <a
                                href="payroll_payslip.php?id=<?= (int)$row['payslip_id'] ?>"
                                class="btn btn-sm btn-outline-secondary"
                            >
                                Open
                            </a>

                            <a
                                href="payroll_payslip_edit.php?id=<?= (int)$row['payslip_id'] ?>"
                                class="btn btn-sm btn-outline-primary"
                            >
                                Edit
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
