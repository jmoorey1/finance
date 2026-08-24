<?php

require_once '../config/db.php';
require_once '../scripts/payroll_ui.php';
require_once '../scripts/payroll_finance.php';

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

$financeStatus = null;
$financeMapping = null;
$financeLinks = [];
$financeCandidates = [];

$financeSaved = isset($_GET['finance_saved'])
    ? (string)$_GET['finance_saved']
    : '';

$financeError = isset($_GET['finance_error'])
    ? (string)$_GET['finance_error']
    : '';

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

    $financeStatus = payroll_finance_get_link_status(
        $pdo,
        $payslipId
    );

    $financeMapping = payroll_finance_get_mapping(
        $pdo,
        (int)$payslip['employment_id']
    );

    $financeLinks = payroll_finance_get_links(
        $pdo,
        $payslipId
    );

    $financeCandidates = payroll_finance_get_candidate_transactions(
        $pdo,
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

        <div class="d-flex flex-wrap gap-2">

            <a
                class="btn btn-outline-primary"
                href="payroll_finance_settings.php?employment_id=<?= $employmentId ?>"
            >
                Finance settings
            </a>

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

    <div class="card mb-4">

        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">

            <div>
                <strong>Finance linkage</strong>

                <div class="small text-muted">
                    Connect this payslip to the actual bank transaction
                    without creating or altering a transaction.
                </div>
            </div>

            <?php if ($financeStatus !== null): ?>

                <?php
                    $financeStatusClass = match (
                        (string)$financeStatus['link_status']
                    ) {
                        'settled' =>
                            'bg-success',

                        'partial' =>
                            'bg-warning text-dark',

                        'overlinked' =>
                            'bg-danger',

                        'unlinked' =>
                            'bg-secondary',

                        'out_of_scope' =>
                            'bg-light text-dark border',

                        'no_settlement' =>
                            'bg-light text-dark border',

                        default =>
                            'bg-light text-dark border',
                    };
                ?>

                <span class="badge <?= $financeStatusClass ?>">
                    <?= payroll_ui_h(
                        payroll_finance_status_label(
                            (string)$financeStatus['link_status']
                        )
                    ) ?>
                </span>

            <?php endif; ?>

        </div>

        <div class="card-body">

            <?php if ($financeError !== ''): ?>

                <div class="alert alert-danger">
                    <?= payroll_ui_h($financeError) ?>
                </div>

            <?php endif; ?>

            <?php if ($financeSaved === 'linked'): ?>

                <div class="alert alert-success">
                    Bank transaction linked successfully.
                </div>

            <?php elseif ($financeSaved === 'unlinked'): ?>

                <div class="alert alert-success">
                    Bank transaction link removed successfully.
                </div>

            <?php endif; ?>

            <?php if ($financeMapping === null): ?>

                <div class="alert alert-warning mb-0">

                    <div class="fw-bold mb-1">
                        Finance mapping has not been configured for this employment.
                    </div>

                    <div class="mb-3">
                        Configure the receiving account and optional salary
                        category / recurring prediction context before
                        transactions can be linked.
                    </div>

                    <a
                        class="btn btn-sm btn-primary"
                        href="payroll_finance_settings.php?employment_id=<?= $employmentId ?>"
                    >
                        Configure Finance mapping
                    </a>

                </div>

            <?php elseif ($financeStatus !== null): ?>

                <div class="row g-3 mb-4">

                    <div class="col-md-3">

                        <div class="border rounded p-3 h-100">

                            <div class="small text-muted">
                                Expected settlement
                            </div>

                            <div class="fw-bold fs-5">
                                <?= $financeStatus['expected_settlement_amount'] !== null
                                    ? payroll_ui_money(
                                        $financeStatus['expected_settlement_amount']
                                    )
                                    : '—' ?>
                            </div>

                            <div class="small text-muted">
                                <?= payroll_ui_h(
                                    payroll_finance_expected_source_label(
                                        (string)$financeStatus['expected_amount_source']
                                    )
                                ) ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-md-3">

                        <div class="border rounded p-3 h-100">

                            <div class="small text-muted">
                                Linked amount
                            </div>

                            <div class="fw-bold fs-5">
                                <?= payroll_ui_money(
                                    $financeStatus['linked_amount']
                                ) ?>
                            </div>

                            <div class="small text-muted">
                                <?= (int)$financeStatus['link_count'] ?>
                                transaction link(s)
                            </div>

                        </div>

                    </div>

                    <div class="col-md-3">

                        <div class="border rounded p-3 h-100">

                            <div class="small text-muted">
                                Receiving account
                            </div>

                            <div class="fw-bold">
                                <?= payroll_ui_h(
                                    $financeMapping['receiving_account_name']
                                ) ?>
                            </div>

                            <div class="small text-muted">
                                Linkage starts
                                <?= (
                                    new DateTimeImmutable(
                                        $financeMapping['linkage_start_date']
                                    )
                                )->format('d M Y') ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-md-3">

                        <div class="border rounded p-3 h-100">

                            <div class="small text-muted">
                                Matching context
                            </div>

                            <div class="fw-bold">
                                ±<?= (int)$financeMapping['candidate_window_days'] ?>
                                day(s)
                            </div>

                            <div class="small text-muted">
                                <?= $financeMapping['income_category_label']
                                    ? payroll_ui_h(
                                        $financeMapping['income_category_label']
                                    )
                                    : 'No income category configured' ?>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="alert alert-light border">
                    <strong>Bank data remains authoritative.</strong>
                    Creating or removing a Payroll link does not update the
                    bank transaction, its category, its prediction rule, or
                    any predicted-instance fulfilment state.
                </div>

                <?php if ((string)$financeStatus['link_status'] === 'out_of_scope'): ?>

                    <div class="alert alert-info mb-0">
                        This payslip is before the configured Payroll ↔ Finance
                        linkage start date of
                        <?= payroll_ui_h(
                            (
                                new DateTimeImmutable(
                                    $financeMapping['linkage_start_date']
                                )
                            )->format('d F Y')
                        ) ?>.
                        It is intentionally excluded from reconciliation.
                    </div>

                <?php elseif ((string)$financeStatus['link_status'] === 'no_settlement'): ?>

                    <div class="alert alert-warning mb-0">
                        There is no safe expected settlement amount for this
                        payslip. For a payslip containing notional pay, capture
                        the source statement's Amount Paid before attempting
                        Finance linkage.
                    </div>

                <?php else: ?>

                    <?php if ($financeLinks !== []): ?>

                        <h2 class="h5 mt-4">
                            Linked transaction<?= count($financeLinks) === 1 ? '' : 's' ?>
                        </h2>

                        <div class="table-responsive mb-4">

                            <table class="table table-sm align-middle">

                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Account</th>
                                        <th>Description</th>
                                        <th class="text-end">
                                            Bank amount
                                        </th>
                                        <th class="text-end">
                                            Matched amount
                                        </th>
                                        <th>Method</th>
                                        <th></th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($financeLinks as $link): ?>

                                    <tr>

                                        <td>
                                            <?= payroll_ui_h(
                                                (
                                                    new DateTimeImmutable(
                                                        $link['transaction_date']
                                                    )
                                                )->format('d M Y')
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= payroll_ui_h(
                                                $link['account_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <a
                                                href="transaction_edit.php?id=<?= (int)$link['transaction_id'] ?>"
                                            >
                                                <?= payroll_ui_h(
                                                    $link['transaction_description']
                                                ) ?>
                                            </a>
                                        </td>

                                        <td class="text-end">
                                            <?= payroll_ui_money(
                                                $link['transaction_amount']
                                            ) ?>
                                        </td>

                                        <td class="text-end fw-bold">
                                            <?= payroll_ui_money(
                                                $link['matched_amount']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= payroll_ui_h(
                                                payroll_finance_match_method_label(
                                                    (string)$link['match_method']
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">

                                            <form
                                                method="post"
                                                action="payroll_finance_action.php"
                                                class="d-inline"
                                            >

                                                <?= csrf_input() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="unlink"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="payslip_id"
                                                    value="<?= $payslipId ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="link_id"
                                                    value="<?= (int)$link['link_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                >
                                                    Unlink
                                                </button>

                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                    <?php if (
                        in_array(
                            (string)$financeStatus['link_status'],
                            [
                                'unlinked',
                                'partial',
                            ],
                            true
                        )
                    ): ?>

                        <h2 class="h5">
                            Candidate bank transactions
                        </h2>

                        <?php if ($financeCandidates === []): ?>

                            <div class="alert alert-secondary mb-0">
                                No unlinked bank transaction falls within the
                                configured date/amount candidate window.
                            </div>

                        <?php else: ?>

                            <div class="table-responsive">

                                <table class="table table-sm align-middle">

                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Category</th>
                                            <th class="text-end">
                                                Amount
                                            </th>
                                            <th class="text-end">
                                                Difference
                                            </th>
                                            <th>Context</th>
                                            <th></th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                    <?php foreach ($financeCandidates as $candidate): ?>

                                        <tr>

                                            <td>
                                                <?= payroll_ui_h(
                                                    (
                                                        new DateTimeImmutable(
                                                            $candidate['date']
                                                        )
                                                    )->format('d M Y')
                                                ) ?>

                                                <?php if ($candidate['same_day']): ?>
                                                    <span class="badge bg-success">
                                                        Same day
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <a
                                                    href="transaction_edit.php?id=<?= (int)$candidate['id'] ?>"
                                                >
                                                    <?= payroll_ui_h(
                                                        $candidate['description']
                                                    ) ?>
                                                </a>
                                            </td>

                                            <td>
                                                <?= $candidate['category_label']
                                                    ? payroll_ui_h(
                                                        $candidate['category_label']
                                                    )
                                                    : '—' ?>
                                            </td>

                                            <td class="text-end fw-bold">
                                                <?= payroll_ui_money(
                                                    $candidate['amount']
                                                ) ?>
                                            </td>

                                            <td class="text-end">
                                                <?= payroll_ui_money(
                                                    $candidate['amount_difference']
                                                ) ?>
                                            </td>

                                            <td>

                                                <?php if ($candidate['exact_amount']): ?>
                                                    <span class="badge bg-success">
                                                        Exact amount
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">
                                                        Near amount
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($candidate['category_match']): ?>
                                                    <span class="badge bg-info text-dark">
                                                        Category
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($candidate['prediction_rule_match']): ?>
                                                    <span class="badge bg-info text-dark">
                                                        Prediction
                                                    </span>
                                                <?php endif; ?>

                                            </td>

                                            <td class="text-end">

                                                <form
                                                    method="post"
                                                    action="payroll_finance_action.php"
                                                >

                                                    <?= csrf_input() ?>

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="link"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="payslip_id"
                                                        value="<?= $payslipId ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="transaction_id"
                                                        value="<?= (int)$candidate['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-primary"
                                                    >
                                                        Link
                                                    </button>

                                                </form>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
