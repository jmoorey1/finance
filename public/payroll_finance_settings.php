<?php

require_once '../config/db.php';
require_once '../scripts/payroll_ui.php';
require_once '../scripts/payroll_finance.php';

$employments =
    payroll_ui_get_employments(
        $pdo
    );

$requestedEmploymentId =
    isset($_REQUEST['employment_id'])
        ? (int)$_REQUEST['employment_id']
        : null;

$employment =
    payroll_ui_resolve_employment(
        $employments,
        $requestedEmploymentId
    );

$accounts =
    payroll_finance_get_accounts(
        $pdo
    );

$categories =
    payroll_finance_get_income_categories(
        $pdo
    );

$predictionRules =
    payroll_finance_get_income_prediction_rules(
        $pdo
    );

$error = null;

$saved =
    $_SERVER['REQUEST_METHOD'] === 'GET'
        ? (string)($_GET['saved'] ?? '')
        : '';

$mapping = null;

if ($employment !== null) {
    $mapping =
        payroll_finance_get_mapping(
            $pdo,
            (int)$employment[
                'employment_id'
            ]
        );
}

$form = [
    'employment_id' =>
        $employment !== null
            ? (string)$employment[
                'employment_id'
            ]
            : '',

    'receiving_account_id' =>
        $mapping[
            'receiving_account_id'
        ]
            ?? '',

    'income_category_id' =>
        $mapping[
            'income_category_id'
        ]
            ?? '',

    'prediction_rule_id' =>
        $mapping[
            'prediction_rule_id'
        ]
            ?? '',

    'linkage_start_date' =>
        $mapping[
            'linkage_start_date'
        ]
            ?? '2020-01-01',

    'candidate_window_days' =>
        $mapping[
            'candidate_window_days'
        ]
            ?? '7',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'employment_id' =>
            (string)(
                $_POST[
                    'employment_id'
                ]
                ?? ''
            ),

        'receiving_account_id' =>
            (string)(
                $_POST[
                    'receiving_account_id'
                ]
                ?? ''
            ),

        'income_category_id' =>
            (string)(
                $_POST[
                    'income_category_id'
                ]
                ?? ''
            ),

        'prediction_rule_id' =>
            (string)(
                $_POST[
                    'prediction_rule_id'
                ]
                ?? ''
            ),

        'linkage_start_date' =>
            (string)(
                $_POST[
                    'linkage_start_date'
                ]
                ?? ''
            ),

        'candidate_window_days' =>
            (string)(
                $_POST[
                    'candidate_window_days'
                ]
                ?? ''
            ),
    ];

    try {
        $validated =
            payroll_finance_validate_mapping(
                $pdo,
                $form
            );

        payroll_finance_save_mapping(
            $pdo,
            $validated
        );

        header(
            'Location: payroll_finance_settings.php?employment_id='
            . (int)$validated[
                'employment_id'
            ]
            . '&saved=1'
        );

        exit;

    } catch (Throwable $e) {
        $error =
            $e->getMessage();
    }
}

include '../layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

    <div>

        <div class="small mb-2">
            <a href="payroll.php">
                ← Payroll
            </a>
        </div>

        <h1 class="mb-1">
            Payroll Finance settings
        </h1>

        <p class="text-muted mb-0">
            Define the Finance context used to discover and validate
            payslip settlement transactions.
        </p>

    </div>

    <?php if ($employment !== null): ?>

        <form
            method="get"
            class="d-flex align-items-end gap-2"
        >

            <div>

                <label
                    for="employment-picker"
                    class="form-label small text-muted mb-1"
                >
                    Employee
                </label>

                <select
                    name="employment_id"
                    id="employment-picker"
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

<?php if ($saved === '1'): ?>

    <div class="alert alert-success">
        Payroll Finance mapping saved successfully.
    </div>

<?php endif; ?>

<?php if ($error !== null): ?>

    <div class="alert alert-danger">
        <?= payroll_ui_h($error) ?>
    </div>

<?php endif; ?>

<div class="alert alert-info">
    <strong>Scope boundary:</strong>
    Payroll ↔ Finance linkage intentionally starts no earlier than
    <strong>1 January 2020</strong>.
    Earlier payroll records remain outside reconciliation scope.
</div>

<div class="alert alert-light border">
    This mapping only defines matching context. Saving it does
    <strong>not</strong> alter bank transactions, fulfil predictions, or
    perform automatic matching.
</div>

<?php if ($employment === null): ?>

    <div class="alert alert-warning">
        No payroll employment is available.
    </div>

<?php else: ?>

    <form method="post">

        <?= csrf_input() ?>

        <input
            type="hidden"
            name="employment_id"
            value="<?= (int)$employment['employment_id'] ?>"
        >

        <div class="card mb-4">

            <div class="card-header">
                <strong>
                    <?= payroll_ui_h(
                        $employment['full_name']
                    ) ?>
                </strong>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-6">

                        <label
                            for="receiving_account_id"
                            class="form-label"
                        >
                            Salary receiving account
                        </label>

                        <select
                            name="receiving_account_id"
                            id="receiving_account_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Choose…
                            </option>

                            <?php foreach ($accounts as $account): ?>

                                <option
                                    value="<?= (int)$account['id'] ?>"
                                    <?= (int)$form['receiving_account_id']
                                        === (int)$account['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= payroll_ui_h(
                                        $account['name']
                                    ) ?>
                                    ·
                                    <?= payroll_ui_h(
                                        $account['type']
                                    ) ?>
                                    <?= (int)$account['active'] === 1
                                        ? ''
                                        : ' · inactive' ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            This is a hard candidate constraint:
                            settlement transactions must arrive in this account.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label
                            for="income_category_id"
                            class="form-label"
                        >
                            Expected salary category
                        </label>

                        <select
                            name="income_category_id"
                            id="income_category_id"
                            class="form-select"
                        >

                            <option value="">
                                No category constraint
                            </option>

                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?= (int)$category['id'] ?>"
                                    <?= (int)$form['income_category_id']
                                        === (int)$category['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= payroll_ui_h(
                                        $category['label']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Used as a confidence signal only.
                            A correct bank transaction is not rejected merely
                            because its category differs or is blank.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label
                            for="prediction_rule_id"
                            class="form-label"
                        >
                            Recurring salary prediction rule
                        </label>

                        <select
                            name="prediction_rule_id"
                            id="prediction_rule_id"
                            class="form-select"
                        >

                            <option value="">
                                No prediction rule
                            </option>

                            <?php foreach ($predictionRules as $rule): ?>

                                <option
                                    value="<?= (int)$rule['id'] ?>"
                                    <?= (int)$form['prediction_rule_id']
                                        === (int)$rule['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    #<?= (int)$rule['id'] ?>
                                    ·
                                    <?= payroll_ui_h(
                                        $rule['description']
                                    ) ?>
                                    <?= (int)$rule['active'] === 1
                                        ? ''
                                        : ' · inactive' ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Stored as matching context only in this tranche.
                            Prediction fulfilment remains unchanged.
                        </div>

                    </div>

                    <div class="col-md-3">

                        <label
                            for="linkage_start_date"
                            class="form-label"
                        >
                            Linkage start date
                        </label>

                        <input
                            type="date"
                            name="linkage_start_date"
                            id="linkage_start_date"
                            class="form-control"
                            min="2020-01-01"
                            value="<?= payroll_ui_h(
                                $form['linkage_start_date']
                            ) ?>"
                            required
                        >

                    </div>

                    <div class="col-md-3">

                        <label
                            for="candidate_window_days"
                            class="form-label"
                        >
                            Candidate window
                        </label>

                        <div class="input-group">

                            <input
                                type="number"
                                name="candidate_window_days"
                                id="candidate_window_days"
                                class="form-control"
                                min="0"
                                max="31"
                                step="1"
                                value="<?= payroll_ui_h(
                                    $form['candidate_window_days']
                                ) ?>"
                                required
                            >

                            <span class="input-group-text">
                                days
                            </span>

                        </div>

                        <div class="form-text">
                            Applied either side of pay date.
                            Default: ±7 days.
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Save Finance mapping
        </button>

    </form>

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
