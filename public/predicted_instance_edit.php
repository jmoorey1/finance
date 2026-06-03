<?php
require_once '../config/db.php';
auth_session_start();
require_once 'predicted_instance_helpers.php';
require_once 'prediction_rule_helpers.php';
include '../layout/header.php';

$accountsStmt = $pdo->query("
    SELECT id, name, type
    FROM accounts
    WHERE active = 1
    ORDER BY type, name
");
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesStmt = $pdo->query("
    SELECT id, name, type
    FROM categories
    WHERE type IN ('income', 'expense')
    ORDER BY FIELD(type, 'income', 'expense'), name
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
$typeOptions = prediction_rule_type_options();

$defaults = predicted_instance_defaults();
$formValues = null;
$formErrors = $_SESSION['predicted_instance_errors'] ?? [];
unset($_SESSION['predicted_instance_errors']);

if (isset($_SESSION['predicted_instance_form'])) {
    $formValues = array_merge($defaults, $_SESSION['predicted_instance_form']);
    unset($_SESSION['predicted_instance_form']);
}

$instanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $instanceId > 0;
$futureDays = isset($_GET['future_days']) ? (int)$_GET['future_days'] : 180;
if (!in_array($futureDays, [90, 180, 365], true)) {
    $futureDays = 180;
}

if ($formValues === null) {
    if ($editing) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM predicted_instances
            WHERE id = ?
              AND predicted_transaction_id IS NULL
              AND COALESCE(fulfilled, 0) = 0
            LIMIT 1
        ");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$instance) {
            $_SESSION['prediction_action_flash'] = '⚠️ Manual one-off prediction not found or can no longer be edited.';
            header('Location: predicted.php?future_days=' . $futureDays);
            exit;
        }

        $formValues = array_merge($defaults, $instance);
    } else {
        $formValues = $defaults;
    }
}

if (empty($formValues['budget_month']) && !empty($formValues['budget_month_start'])) {
    $formValues['budget_month'] = substr((string)$formValues['budget_month_start'], 0, 7);
}

function pi_selected($a, $b): string {
    return (string)$a === (string)$b ? 'selected' : '';
}
?>

<h1 class="mb-4"><?= $editing ? '✏️ Edit One-off Planned Item' : '➕ New One-off Planned Item' ?></h1>

<?php if (!empty($formErrors)): ?>
    <div class="alert alert-danger">
        <strong>Please fix the following:</strong>
        <ul class="mb-0">
            <?php foreach ($formErrors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    Use this form for one-off planned items that are <strong>not generated from a recurring rule</strong>.
</div>

<div class="alert alert-secondary">
    <strong>Solvency treatment</strong> controls whether this item is:
    <ul class="mb-0">
        <li><strong>Additional to budget</strong> — the full amount is added on top of the monthly budget baseline.</li>
        <li><strong>Budget-backed</strong> — this item replaces budget already carried in a chosen financial month, so it is not double counted.</li>
    </ul>
</div>

<form method="post" action="predicted_instance_save.php">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$formValues['id']) ?>">
    <input type="hidden" name="future_days" value="<?= (int)$futureDays ?>">

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Scheduled Date</label>
            <input type="date" name="scheduled_date" id="scheduled_date" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['scheduled_date']) ?>">
        </div>

        <div class="col-md-8">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" maxlength="65535" required
                   value="<?= htmlspecialchars((string)$formValues['description']) ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Item Type</label>
            <select name="prediction_type" id="prediction_type" class="form-select" required>
                <?php foreach ($typeOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= pi_selected($formValues['prediction_type'] ?? 'expense', $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Transfers are modelled by From/To Account, not by transfer categories.</div>
        </div>

        <div class="col-md-4 js-category-field">
            <label class="form-label">Category</label>
            <select name="category_id" id="category_id" class="form-select">
                <option value="">— Select —</option>
                <?php
                $currentType = null;
                foreach ($categories as $c):
                    if ($currentType !== $c['type']):
                        if ($currentType !== null) echo '</optgroup>';
                        $currentType = $c['type'];
                        echo '<optgroup label="' . htmlspecialchars(ucfirst($currentType)) . '">';
                    endif;
                ?>
                    <option value="<?= (int)$c['id'] ?>"
                            data-type="<?= htmlspecialchars($c['type']) ?>"
                            <?= pi_selected($formValues['category_id'], $c['id']) ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($currentType !== null): ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">From Account</label>
            <select name="from_account_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= pi_selected($formValues['from_account_id'], $a['id']) ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">For income and expense items, this is the account affected. For transfers, this is the sending account.</div>
        </div>

        <div class="col-md-4 js-to-account-field">
            <label class="form-label">To Account</label>
            <select name="to_account_id" id="to_account_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= pi_selected($formValues['to_account_id'], $a['id']) ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Required for transfer items only.</div>
        </div>

        <div class="col-md-4">
            <label class="form-label">Amount</label>
            <input type="number" name="amount" step="0.01" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['amount']) ?>">
            <div class="form-text" id="amount_help">
                Enter a signed amount: positive for income, negative for expense, positive for transfer value.
            </div>
        </div>

        <div class="col-md-4 js-budget-treatment-wrapper">
            <label class="form-label">Solvency Treatment</label>
            <select name="budget_treatment" id="budget_treatment" class="form-select">
                <option value="additional" <?= pi_selected($formValues['budget_treatment'], 'additional') ?>>Additional to budget</option>
                <option value="budget_backed" <?= pi_selected($formValues['budget_treatment'], 'budget_backed') ?>>Budget-backed / replaces budget allowance</option>
            </select>
        </div>

        <div class="col-md-4 js-budget-fields">
            <label class="form-label">Budget Financial Month</label>
            <input type="month" name="budget_month" id="budget_month" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['budget_month']) ?>">
            <div class="form-text">
                Leave blank and it will default to the financial month containing the scheduled date.
                For dates before the 13th, that means the previous calendar month.
            </div>
        </div>

        <div class="col-md-4 js-budget-fields">
            <label class="form-label">Budget Amount Already Included (£)</label>
            <input type="number" name="budget_amount" step="0.01" min="0" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['budget_amount']) ?>">
            <div class="form-text">
                Optional if an exact budget row exists for the selected top-level category in the chosen financial month.
                Enter it manually when you want to offset only part of the budget.
            </div>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Save Changes' : '✅ Create One-off Item' ?></button>
            <a href="predicted.php?future_days=<?= (int)$futureDays ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<script>
function suggestedBudgetMonthFromScheduledDate(dateStr) {
    if (!dateStr || !/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
        return '';
    }

    const parts = dateStr.split('-').map(Number);
    let year = parts[0];
    let month = parts[1];
    const day = parts[2];

    if (day < 13) {
        month -= 1;
        if (month === 0) {
            month = 12;
            year -= 1;
        }
    }

    return String(year) + '-' + String(month).padStart(2, '0');
}

function updateOneOffForm() {
    const predictionType = document.getElementById('prediction_type').value;
    const categorySelect = document.getElementById('category_id');
    const toAccountSelect = document.getElementById('to_account_id');
    const toAccountFields = document.querySelectorAll('.js-to-account-field');
    const budgetTreatmentWrapper = document.querySelectorAll('.js-budget-treatment-wrapper');
    const budgetFields = document.querySelectorAll('.js-budget-fields');
    const amountHelp = document.getElementById('amount_help');
    const treatmentSelect = document.getElementById('budget_treatment');
    const budgetMonthInput = document.getElementById('budget_month');
    const scheduledDateInput = document.getElementById('scheduled_date');

    const isTransfer = predictionType === 'transfer';

    document.querySelectorAll('.js-category-field').forEach(el => {
        el.style.display = isTransfer ? 'none' : '';
    });
    categorySelect.required = !isTransfer;
    categorySelect.disabled = isTransfer;

    toAccountFields.forEach(el => {
        el.style.display = isTransfer ? '' : 'none';
    });
    toAccountSelect.required = isTransfer;
    toAccountSelect.disabled = !isTransfer;

    const supportsBudgetTreatment = (predictionType === 'income' || predictionType === 'expense');

    budgetTreatmentWrapper.forEach(el => {
        el.style.display = supportsBudgetTreatment ? '' : 'none';
    });

    const showBudgetFields = supportsBudgetTreatment && treatmentSelect.value === 'budget_backed';
    budgetFields.forEach(el => {
        el.style.display = showBudgetFields ? '' : 'none';
    });

    if (showBudgetFields && !budgetMonthInput.value && scheduledDateInput.value) {
        budgetMonthInput.value = suggestedBudgetMonthFromScheduledDate(scheduledDateInput.value);
    }

    if (predictionType === 'income') {
        amountHelp.textContent = 'Enter a positive amount for income.';
    } else if (predictionType === 'expense') {
        amountHelp.textContent = 'Enter a negative amount for expense.';
    } else if (predictionType === 'transfer') {
        amountHelp.textContent = 'Enter a positive transfer amount. The system will flow it from From Account to To Account.';
        treatmentSelect.value = 'additional';
    } else {
        amountHelp.textContent = 'Enter a signed amount: positive for income, negative for expense, positive for transfer value.';
    }
}

document.getElementById('prediction_type').addEventListener('change', updateOneOffForm);
document.getElementById('category_id').addEventListener('change', updateOneOffForm);
document.getElementById('budget_treatment').addEventListener('change', updateOneOffForm);
document.getElementById('scheduled_date').addEventListener('change', updateOneOffForm);
updateOneOffForm();
</script>

<?php include '../layout/footer.php'; ?>
