<?php

require_once '../config/db.php';
require_once '../scripts/payroll_ui.php';

$payslipId = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$payslip = $payslipId > 0
    ? payroll_ui_get_payslip(
        $pdo,
        $payslipId
    )
    : null;

$lines = [];

$adjacent = [
    'previous' => null,
    'next' => null,
];

if ($payslip !== null) {
    $lines = payroll_ui_get_payslip_lines(
        $pdo,
        $payslipId
    );

    $adjacent = payroll_ui_get_adjacent_payslips(
        $pdo,
        (int)$payslip['employment_id'],
        (string)$payslip['pay_date'],
        $payslipId
    );
} else {
    http_response_code(404);
}

include '../layout/header.php';
?>

<?php if ($payslip === null): ?>

    <div class="alert alert-danger mt-4">

        <h1 class="h4">
            Payslip not found
        </h1>

        <p class="mb-2">
            The requested payslip does not exist.
        </p>

        <a
            href="payroll.php"
            class="btn btn-outline-danger btn-sm"
        >
            Back to Payroll
        </a>

    </div>

<?php else: ?>

    <?php
        $employmentId = (int)$payslip['employment_id'];

        $payDate = new DateTimeImmutable(
            $payslip['pay_date']
        );
    ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

        <div>

            <div class="small mb-2">

                <a
                    href="payroll.php?employment_id=<?= $employmentId ?>"
                >
                    Payroll
                </a>

                <span class="text-muted"> / </span>

                <a
                    href="payroll_payslips.php?employment_id=<?= $employmentId ?>&tax_year_start=<?= (int)$payslip['tax_year_start'] ?>"
                >
                    Payslips
                </a>

            </div>

            <h1 class="mb-1">
                Payslip ·
                <?= $payDate->format('d F Y') ?>
            </h1>

            <p class="text-muted mb-0">
                <?= payroll_ui_h(
                    $payslip['person_name']
                ) ?>
                ·
                <?= payroll_ui_h(
                    $payslip['tax_year']
                ) ?>
                tax month
                <?= (int)$payslip['tax_month'] ?>
            </p>

        </div>

        <div class="d-flex gap-2">

            <?php if ($adjacent['previous']): ?>

                <a
                    class="btn btn-outline-secondary"
                    href="payroll_payslip.php?id=<?= (int)$adjacent['previous']['id'] ?>"
                >
                    ← Older
                </a>

            <?php endif; ?>

            <?php if ($adjacent['next']): ?>

                <a
                    class="btn btn-outline-secondary"
                    href="payroll_payslip.php?id=<?= (int)$adjacent['next']['id'] ?>"
                >
                    Newer →
                </a>

            <?php endif; ?>

        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Cash earnings
                    </div>
                    <div class="fw-bold">
                        <?= payroll_ui_money(
                            $payslip['cash_earnings']
                        ) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Tax
                    </div>
                    <div class="fw-bold">
                        <?= payroll_ui_money(
                            $payslip['taxes']
                        ) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Pension
                    </div>
                    <div class="fw-bold">
                        <?= payroll_ui_money(
                            $payslip['pension']
                        ) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Bonus
                    </div>
                    <div class="fw-bold">
                        <?= payroll_ui_money(
                            $payslip['bonus']
                        ) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Deductions
                    </div>
                    <div class="fw-bold">
                        <?= payroll_ui_money(
                            $payslip['total_deductions']
                        ) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card border-success h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Net pay
                    </div>
                    <div class="fw-bold text-success">
                        <?= payroll_ui_money(
                            $payslip['net_pay']
                        ) ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4 mb-4">

        <div class="col-lg-8">

            <div class="card">

                <div class="card-header">
                    <strong>Payslip lines</strong>
                </div>

                <div class="table-responsive">

                    <table class="table table-striped align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Code</th>
                                <th>Description</th>
                                <th>Notional</th>
                                <th class="text-end">
                                    Amount
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($lines as $line): ?>

                            <tr>

                                <td>

                                    <span
                                        class="badge <?= $line['line_type']
                                            === 'Pay'
                                            ? 'bg-success'
                                            : 'bg-secondary' ?>"
                                    >
                                        <?= payroll_ui_h(
                                            $line['line_type']
                                        ) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= payroll_ui_h(
                                        $line['category_name']
                                    ) ?>
                                </td>

                                <td>
                                    <code>
                                        <?= payroll_ui_h(
                                            $line['code']
                                        ) ?>
                                    </code>
                                </td>

                                <td>
                                    <?= payroll_ui_h(
                                        $line['description']
                                    ) ?>
                                </td>

                                <td>
                                    <?php if ((int)$line['is_notional'] === 1): ?>
                                        <span class="badge bg-warning text-dark">
                                            Notional
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end fw-bold">
                                    <?= payroll_ui_money(
                                        $line['amount']
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card mb-4">

                <div class="card-header">
                    <strong>Payroll details</strong>
                </div>

                <div class="card-body">

                    <dl class="row mb-0">

                        <dt class="col-6">
                            Annual salary
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['annual_salary'] !== null
                                ? payroll_ui_money(
                                    $payslip['annual_salary']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Tax code
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['tax_code']
                                ? payroll_ui_h(
                                    $payslip['tax_code']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Statement earnings
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['statement_total_earnings'] !== null
                                ? payroll_ui_money(
                                    $payslip['statement_total_earnings']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Statement deductions
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['statement_total_deductions'] !== null
                                ? payroll_ui_money(
                                    $payslip['statement_total_deductions']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Statement net pay
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['statement_net_pay'] !== null
                                ? payroll_ui_money(
                                    $payslip['statement_net_pay']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Amount paid
                        </dt>

                        <dd class="col-6 text-end fw-bold">
                            <?= $payslip['amount_paid'] !== null
                                ? payroll_ui_money(
                                    $payslip['amount_paid']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Payment method
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['payment_method']
                                ? payroll_ui_h(
                                    $payslip['payment_method']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Settlement basis
                        </dt>

                        <dd class="col-6 text-end">
                            <?= match (
                                (string)$payslip['settlement_amount_source']
                            ) {
                                'statement_amount_paid' => 'Amount paid',
                                'statement_net_pay' => 'Statement net pay',
                                default => 'Calculated lines',
                            } ?>
                        </dd>

                        <dt class="col-6">
                            Employee no.
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['employee_number']
                                ? payroll_ui_h(
                                    $payslip['employee_number']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Tax reference
                        </dt>

                        <dd class="col-6 text-end">
                            <?= $payslip['tax_reference']
                                ? payroll_ui_h(
                                    $payslip['tax_reference']
                                )
                                : '—' ?>
                        </dd>

                        <dt class="col-6">
                            Line items
                        </dt>

                        <dd class="col-6 text-end">
                            <?= (int)$payslip['line_item_count'] ?>
                        </dd>

                    </dl>

                </div>

            </div>

            <div class="card">

                <div class="card-header">
                    <strong>Breakdown</strong>
                </div>

                <div class="card-body">

                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span>Basic pay</span>
                        <strong>
                            <?= payroll_ui_money(
                                $payslip['basic_pay']
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span>Benefits</span>
                        <strong>
                            <?= payroll_ui_money(
                                $payslip['benefits']
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span>Additional earnings</span>
                        <strong>
                            <?= payroll_ui_money(
                                $payslip['additional_earnings']
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span>Pre-tax deductions</span>
                        <strong>
                            <?= payroll_ui_money(
                                $payslip['pre_tax_deductions']
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span>Post-tax deductions</span>
                        <strong>
                            <?= payroll_ui_money(
                                $payslip['post_tax_deductions']
                            ) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between pt-1">
                        <span>Tax % of gross</span>
                        <strong>
                            <?= number_format(
                                (float)$payslip['tax_percentage'],
                                2
                            ) ?>%
                        </strong>
                    </div>

                </div>

            </div>

        </div>

    </div>

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
