<?php

require_once '../config/db.php';
require_once '../scripts/payroll_ui.php';
require_once '../scripts/payroll_write.php';

$employments = payroll_ui_get_employments($pdo);
$categories = payroll_write_get_categories($pdo);

$error = null;
$payslipId = null;
$isNew = true;
$notFound = false;

$savedState = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? (string)($_GET['saved'] ?? '')
    : '';

$formHeader = [
    'employment_id' => '',
    'pay_date' => date('Y-m-d'),
    'tax_code' => '',
    'annual_salary' => '',
];

$formLines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formMode = (string)($_POST['form_mode'] ?? '');

    if ($formMode === 'edit') {
        $payslipId = (int)($_POST['payslip_id'] ?? 0);
        $isNew = false;
    } elseif ($formMode === 'create') {
        $payslipId = null;
        $isNew = true;
    } else {
        $error = 'Invalid payslip form submission.';
    }

    $formHeader = [
        'employment_id' => (string)($_POST['employment_id'] ?? ''),
        'pay_date' => (string)($_POST['pay_date'] ?? ''),
        'tax_code' => (string)($_POST['tax_code'] ?? ''),
        'annual_salary' => (string)($_POST['annual_salary'] ?? ''),
    ];

    $postedLines = $_POST['lines'] ?? [];
    $formLines = is_array($postedLines)
        ? array_values($postedLines)
        : [];

    if (!$isNew && ($payslipId ?? 0) <= 0) {
        $error = 'Invalid payslip ID.';
    }

    if ($error === null) {
        try {
            if (
                !$isNew
                && payroll_write_get_header($pdo, (int)$payslipId) === null
            ) {
                throw new RuntimeException('Payslip no longer exists.');
            }

            $validatedHeader = payroll_write_validate_header(
                $pdo,
                $formHeader
            );

            $validatedLines = payroll_write_validate_lines(
                $pdo,
                $formLines,
                $isNew ? null : (int)$payslipId
            );

            $savedId = payroll_write_save_payslip(
                $pdo,
                $isNew ? null : (int)$payslipId,
                $validatedHeader,
                $validatedLines
            );

            header(
                'Location: payroll_payslip_edit.php?id='
                . $savedId
                . '&saved='
                . ($isNew ? 'created' : 'updated')
            );
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
} else {
    $requestedPayslipId = isset($_GET['id'])
        ? (int)$_GET['id']
        : 0;

    if ($requestedPayslipId > 0) {
        $payslipId = $requestedPayslipId;
        $isNew = false;

        $header = payroll_write_get_header(
            $pdo,
            $payslipId
        );

        if ($header === null) {
            $notFound = true;
            http_response_code(404);
        } else {
            $formHeader = [
                'employment_id' => (string)$header['employment_id'],
                'pay_date' => (string)$header['pay_date'],
                'tax_code' => (string)($header['tax_code'] ?? ''),
                'annual_salary' => (string)($header['annual_salary'] ?? ''),
            ];

            $formLines = payroll_write_get_lines(
                $pdo,
                $payslipId
            );
        }
    } else {
        $requestedEmploymentId = isset($_GET['employment_id'])
            ? (int)$_GET['employment_id']
            : null;

        $employment = payroll_ui_resolve_employment(
            $employments,
            $requestedEmploymentId
        );

        if ($employment !== null) {
            $formHeader = payroll_write_get_new_defaults(
                $pdo,
                (int)$employment['employment_id']
            );
        }

        $formLines = [
            [
                'id' => 0,
                'category_id' => '',
                'code' => '',
                'description' => '',
                'amount' => '',
            ],
        ];
    }
}

if ($formLines === [] && !$notFound) {
    $formLines = [
        [
            'id' => 0,
            'category_id' => '',
            'code' => '',
            'description' => '',
            'amount' => '',
        ],
    ];
}

include '../layout/header.php';
?>

<?php if ($notFound): ?>

    <div class="alert alert-danger mt-4">
        <h1 class="h4">Payslip not found</h1>
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

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

        <div>
            <div class="small mb-2">
                <?php if ($isNew): ?>
                    <a
                        href="payroll_payslips.php<?= !empty($formHeader['employment_id'])
                            ? '?employment_id=' . (int)$formHeader['employment_id']
                            : '' ?>"
                    >
                        ← Payslip history
                    </a>
                <?php else: ?>
                    <a
                        href="payroll_payslip.php?id=<?= (int)$payslipId ?>"
                    >
                        ← Payslip
                    </a>
                <?php endif; ?>
            </div>

            <h1 class="mb-1">
                <?= $isNew
                    ? 'Add payslip'
                    : 'Edit payslip' ?>
            </h1>

            <p class="text-muted mb-0">
                Header and line-item changes are saved together
                in one database transaction.
            </p>
        </div>

        <?php if (!$isNew && $payslipId !== null): ?>
            <a
                href="payroll_payslip.php?id=<?= (int)$payslipId ?>"
                class="btn btn-outline-secondary"
            >
                View payslip
            </a>
        <?php endif; ?>

    </div>

    <?php if ($savedState === 'created'): ?>
        <div class="alert alert-success">
            Payslip added successfully.
        </div>
    <?php elseif ($savedState === 'updated'): ?>
        <div class="alert alert-success">
            Payslip updated successfully.
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>

        <div class="alert alert-danger">
            <?= payroll_ui_h($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($employments === []): ?>

        <div class="alert alert-warning">
            No payroll employments are available.
        </div>

    <?php elseif ($categories === []): ?>

        <div class="alert alert-warning">
            No active payroll categories are available.
        </div>

    <?php else: ?>

        <form method="post" id="payslip-form">

            <?= csrf_input() ?>

            <input
                type="hidden"
                name="form_mode"
                value="<?= $isNew ? 'create' : 'edit' ?>"
            >

            <?php if (!$isNew): ?>
                <input
                    type="hidden"
                    name="payslip_id"
                    value="<?= (int)$payslipId ?>"
                >
            <?php endif; ?>

            <div class="card mb-4">

                <div class="card-header">
                    <strong>Payslip details</strong>
                </div>

                <div class="card-body">

                    <div class="row g-3">

                        <div class="col-md-6">

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
                                <?= $isNew
                                    ? 'onchange="window.location=\'payroll_payslip_edit.php?employment_id=\'+encodeURIComponent(this.value)"'
                                    : '' ?>
                                required
                            >
                                <?php foreach ($employments as $employment): ?>
                                    <option
                                        value="<?= (int)$employment['employment_id'] ?>"
                                        <?= (int)$formHeader['employment_id']
                                            === (int)$employment['employment_id']
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= payroll_ui_h(
                                            $employment['full_name']
                                        ) ?>
                                        <?= !empty($employment['employee_number'])
                                            ? ' · '
                                                . payroll_ui_h(
                                                    $employment['employee_number']
                                                )
                                            : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        </div>

                        <div class="col-md-3">

                            <label
                                for="pay_date"
                                class="form-label"
                            >
                                Pay date
                            </label>

                            <input
                                type="date"
                                name="pay_date"
                                id="pay_date"
                                class="form-control"
                                value="<?= payroll_ui_h(
                                    $formHeader['pay_date']
                                ) ?>"
                                required
                            >

                        </div>

                        <div class="col-md-3">

                            <label
                                for="tax_code"
                                class="form-label"
                            >
                                Tax code
                            </label>

                            <input
                                type="text"
                                name="tax_code"
                                id="tax_code"
                                class="form-control"
                                maxlength="20"
                                value="<?= payroll_ui_h(
                                    $formHeader['tax_code']
                                ) ?>"
                            >

                        </div>

                        <div class="col-md-4">

                            <label
                                for="annual_salary"
                                class="form-label"
                            >
                                Annual salary
                            </label>

                            <div class="input-group">
                                <span class="input-group-text">£</span>
                                <input
                                    type="number"
                                    name="annual_salary"
                                    id="annual_salary"
                                    class="form-control"
                                    step="0.01"
                                    min="0"
                                    value="<?= payroll_ui_h(
                                        $formHeader['annual_salary']
                                    ) ?>"
                                >
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="card mb-4">

                <div class="card-header d-flex justify-content-between align-items-center gap-3">

                    <div>
                        <strong>Payslip lines</strong>
                        <div class="small text-muted">
                            Enter amounts as shown on the payslip.
                            The selected category determines whether
                            a line is Pay or Deduction.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        id="add-line"
                    >
                        + Add line
                    </button>

                </div>

                <div class="table-responsive">

                    <table class="table align-middle mb-0" id="payslip-lines">

                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 180px;">Category</th>
                                <th style="min-width: 130px;">Code</th>
                                <th style="min-width: 220px;">Description</th>
                                <th style="min-width: 130px;" class="text-end">Amount</th>
                                <th style="min-width: 100px;">Remove</th>
                            </tr>
                        </thead>

                        <tbody id="line-rows">

                        <?php foreach (array_values($formLines) as $index => $line): ?>

                            <?php
                                $lineId = (int)($line['id'] ?? 0);
                                $deleteChecked = isset($line['delete'])
                                    && (string)$line['delete'] === '1';
                            ?>

                            <tr class="payslip-line-row">

                                <td>

                                    <input
                                        type="hidden"
                                        name="lines[<?= $index ?>][id]"
                                        value="<?= $lineId ?>"
                                    >

                                    <select
                                        name="lines[<?= $index ?>][category_id]"
                                        class="form-select"
                                        required
                                    >
                                        <option value="">
                                            Choose…
                                        </option>

                                        <?php foreach ($categories as $category): ?>
                                            <option
                                                value="<?= (int)$category['id'] ?>"
                                                <?= (int)($line['category_id'] ?? 0)
                                                    === (int)$category['id']
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= payroll_ui_h(
                                                    $category['line_type']
                                                ) ?>
                                                ·
                                                <?= payroll_ui_h(
                                                    $category['name']
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>

                                    </select>

                                </td>

                                <td>
                                    <input
                                        type="text"
                                        name="lines[<?= $index ?>][code]"
                                        class="form-control"
                                        maxlength="50"
                                        value="<?= payroll_ui_h(
                                            $line['code'] ?? ''
                                        ) ?>"
                                        required
                                    >
                                </td>

                                <td>
                                    <input
                                        type="text"
                                        name="lines[<?= $index ?>][description]"
                                        class="form-control"
                                        maxlength="150"
                                        value="<?= payroll_ui_h(
                                            $line['description'] ?? ''
                                        ) ?>"
                                        required
                                    >
                                </td>

                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text">£</span>
                                        <input
                                            type="number"
                                            name="lines[<?= $index ?>][amount]"
                                            class="form-control text-end"
                                            step="0.01"
                                            value="<?= payroll_ui_h(
                                                $line['amount'] ?? ''
                                            ) ?>"
                                            required
                                        >
                                    </div>
                                </td>

                                <td>
                                    <?php if ($lineId > 0): ?>

                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="lines[<?= $index ?>][delete]"
                                                value="1"
                                                id="delete-line-<?= $lineId ?>"
                                                <?= $deleteChecked ? 'checked' : '' ?>
                                            >
                                            <label
                                                class="form-check-label"
                                                for="delete-line-<?= $lineId ?>"
                                            >
                                                Delete
                                            </label>
                                        </div>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger remove-new-line"
                                        >
                                            Remove
                                        </button>

                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <?= $isNew
                        ? 'Add payslip'
                        : 'Save changes' ?>
                </button>

                <?php if ($isNew): ?>
                    <a
                        href="payroll_payslips.php<?= !empty($formHeader['employment_id'])
                            ? '?employment_id=' . (int)$formHeader['employment_id']
                            : '' ?>"
                        class="btn btn-outline-secondary"
                    >
                        Cancel
                    </a>
                <?php else: ?>
                    <a
                        href="payroll_payslip.php?id=<?= (int)$payslipId ?>"
                        class="btn btn-outline-secondary"
                    >
                        Cancel
                    </a>
                <?php endif; ?>

            </div>

        </form>

        <template id="line-template">
            <tr class="payslip-line-row">

                <td>

                    <input
                        type="hidden"
                        name="lines[__INDEX__][id]"
                        value="0"
                    >

                    <select
                        name="lines[__INDEX__][category_id]"
                        class="form-select"
                        required
                    >
                        <option value="">
                            Choose…
                        </option>

                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int)$category['id'] ?>">
                                <?= payroll_ui_h(
                                    $category['line_type']
                                ) ?>
                                ·
                                <?= payroll_ui_h(
                                    $category['name']
                                ) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>

                </td>

                <td>
                    <input
                        type="text"
                        name="lines[__INDEX__][code]"
                        class="form-control"
                        maxlength="50"
                        required
                    >
                </td>

                <td>
                    <input
                        type="text"
                        name="lines[__INDEX__][description]"
                        class="form-control"
                        maxlength="150"
                        required
                    >
                </td>

                <td>
                    <div class="input-group">
                        <span class="input-group-text">£</span>
                        <input
                            type="number"
                            name="lines[__INDEX__][amount]"
                            class="form-control text-end"
                            step="0.01"
                            required
                        >
                    </div>
                </td>

                <td>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-danger remove-new-line"
                    >
                        Remove
                    </button>
                </td>

            </tr>
        </template>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const rows = document.getElementById('line-rows');
            const addButton = document.getElementById('add-line');
            const template = document.getElementById('line-template');

            if (!rows || !addButton || !template) {
                return;
            }

            let nextIndex = <?= count($formLines) ?>;

            addButton.addEventListener('click', function () {
                const html = template.innerHTML.replaceAll(
                    '__INDEX__',
                    String(nextIndex++)
                );

                rows.insertAdjacentHTML('beforeend', html);
            });

            rows.addEventListener('click', function (event) {
                const button = event.target.closest('.remove-new-line');

                if (!button) {
                    return;
                }

                const row = button.closest('.payslip-line-row');

                if (row) {
                    row.remove();
                }
            });
        });
        </script>

    <?php endif; ?>

<?php endif; ?>

<?php include '../layout/footer.php'; ?>
