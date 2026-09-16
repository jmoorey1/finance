<?php
require_once '../config/db.php';
auth_session_start();
require_once 'prediction_rule_helpers.php';
include '../layout/header.php';

$accountsStmt = $pdo->query("SELECT id, name, type FROM accounts WHERE active = 1 ORDER BY type, name");
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesStmt = $pdo->query("
    SELECT id, name, type
    FROM categories
    WHERE type IN ('income', 'expense')
    ORDER BY FIELD(type, 'income', 'expense'), name
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

$defaults = prediction_rule_defaults();
$formValues = null;
$formErrors = $_SESSION['prediction_rule_errors'] ?? [];
unset($_SESSION['prediction_rule_errors']);

if (isset($_SESSION['prediction_rule_form'])) {
    $formValues = array_merge($defaults, $_SESSION['prediction_rule_form']);
    unset($_SESSION['prediction_rule_form']);
}

$ruleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $ruleId > 0;

if ($formValues === null) {
    if ($editing) {
        $stmt = $pdo->prepare("SELECT * FROM predicted_transactions WHERE id = ?");
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rule) {
            $_SESSION['prediction_rule_flash'] = '⚠️ Prediction rule not found.';
            header('Location: predicted.php');
            exit;
        }

        $formValues = array_merge($defaults, $rule);
    } else {
        $formValues = $defaults;
    }
}

$weekdayOptions = prediction_rule_weekday_options();
$recurrenceUnitOptions = prediction_rule_recurrence_unit_options();
$schedulePatternOptions = prediction_rule_schedule_pattern_options();
$adjustmentOptions = prediction_rule_business_day_adjustment_options();
$typeOptions = prediction_rule_type_options();

function selected($a, $b): string {
    return (string)$a === (string)$b ? 'selected' : '';
}

function checked($value): string {
    return !empty($value) ? 'checked' : '';
}
?>

<h1 class="mb-4"><?= $editing ? '✏️ Edit Prediction Rule' : '➕ New Prediction Rule' ?></h1>

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

<form method="post" action="predicted_rule_save.php">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$formValues['id']) ?>">

    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" maxlength="255" required
                   value="<?= htmlspecialchars((string)$formValues['description']) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">Active</label>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="active" id="active" value="1" <?= checked($formValues['active']) ?>>
                <label class="form-check-label" for="active">Enabled</label>
            </div>
        </div>

        <div class="col-md-2">
            <label class="form-label">Variable Amount</label>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="variable" id="variable" value="1" <?= checked($formValues['variable']) ?>>
                <label class="form-check-label" for="variable">Yes</label>
            </div>
        </div>

        <div class="col-md-4">
            <label class="form-label">Rule Type</label>
            <select name="prediction_type" id="prediction_type" class="form-select" required>
                <?php foreach ($typeOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['prediction_type'] ?? 'expense', $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Transfers are modelled by From/To Account, not by transfer categories.</div>
        </div>

        <div class="col-md-4">
            <label class="form-label">From Account</label>
            <select name="from_account_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= selected($formValues['from_account_id'], $a['id']) ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 js-to-account-field">
            <label class="form-label">To Account</label>
            <select name="to_account_id" id="to_account_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= selected($formValues['to_account_id'], $a['id']) ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Required for transfer rules only.</div>
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
                    <option value="<?= (int)$c['id'] ?>" <?= selected($formValues['category_id'], $c['id']) ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($currentType !== null): ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Fallback Amount (£)</label>
            <input type="number" name="amount" step="0.01" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['amount']) ?>">
            <div class="form-text">Used as the fixed amount, or as fallback if no variable history exists.</div>
        </div>

        <div class="col-md-3 js-variable-fields">
            <label class="form-label">Average Over Last</label>
            <input type="number" name="average_over_last" min="1" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['average_over_last']) ?>">
            <div class="form-text">Only used when Variable Amount is enabled.</div>
        </div>

        <div class="col-md-3">
            <label class="form-label">Repeat Every</label>
            <input type="number" name="recurrence_interval" id="recurrence_interval" min="1" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['recurrence_interval']) ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Recurrence Unit</label>
            <select name="recurrence_unit" id="recurrence_unit" class="form-select" required>
                <?php foreach ($recurrenceUnitOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['recurrence_unit'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">Anchor Date</label>
            <input type="date" name="anchor_date" id="anchor_date" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['anchor_date']) ?>">
            <div class="form-text">
                Permanent phase reference. Actual transactions never move this date.
                For multi-month rules, its month fixes the recurrence phase.
            </div>
        </div>

        <div class="col-md-4 js-monthly-pattern-field">
            <label class="form-label">Monthly Schedule Pattern</label>
            <select name="schedule_pattern" id="schedule_pattern" class="form-select">
                <?php foreach ($schedulePatternOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['schedule_pattern'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 js-day-of-month-field">
            <label class="form-label">Day of Month</label>
            <input type="number" name="day_of_month" min="1" max="31" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['day_of_month']) ?>">
            <div class="form-text">Days beyond month-end clamp to the final calendar day.</div>
        </div>

        <div class="col-md-4 js-weekday-field">
            <label class="form-label">Weekday</label>
            <select name="weekday" class="form-select">
                <option value="">— Select —</option>
                <?php foreach ($weekdayOptions as $key => $label): ?>
                    <option value="<?= (int)$key ?>" <?= selected($formValues['weekday'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 js-nth-weekday-field">
            <label class="form-label">Nth Weekday</label>
            <select name="nth_weekday" class="form-select">
                <option value="">— Select —</option>
                <?php foreach ([1,2,3,4,5] as $n): ?>
                    <option value="<?= $n ?>" <?= selected($formValues['nth_weekday'], $n) ?>>
                        <?= htmlspecialchars(prediction_rule_ordinal($n)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">Business Day Adjustment</label>
            <select name="business_day_adjustment" class="form-select">
                <?php foreach ($adjustmentOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['business_day_adjustment'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12">
            <div class="alert alert-light border mb-0" role="alert">
                <strong>Recurrence phase is deterministic.</strong>
                Weekly, fortnightly, four-weekly, monthly, quarterly and annual schedules all advance from the saved anchor.
                Matching an actual transaction can fulfil an occurrence, but it cannot shift future dates.
            </div>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Save Changes' : '✅ Create Rule' ?></button>
            <a href="predicted.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<script>
function updatePredictionRuleForm() {
    const predictionType = document.getElementById('prediction_type').value;
    const recurrenceUnit = document.getElementById('recurrence_unit').value;
    const schedulePattern = document.getElementById('schedule_pattern').value;
    const variable = document.getElementById('variable').checked;
    const categorySelect = document.getElementById('category_id');
    const toAccountSelect = document.getElementById('to_account_id');

    const isTransfer = predictionType === 'transfer';
    const isMonthly = recurrenceUnit === 'month';

    document.querySelectorAll('.js-category-field').forEach(el => {
        el.style.display = isTransfer ? 'none' : '';
    });
    categorySelect.required = !isTransfer;
    categorySelect.disabled = isTransfer;

    document.querySelectorAll('.js-to-account-field').forEach(el => {
        el.style.display = isTransfer ? '' : 'none';
    });
    toAccountSelect.required = isTransfer;
    toAccountSelect.disabled = !isTransfer;

    document.querySelectorAll('.js-variable-fields').forEach(el => {
        el.style.display = variable ? '' : 'none';
    });

    document.querySelectorAll('.js-monthly-pattern-field').forEach(el => {
        el.style.display = isMonthly ? '' : 'none';
    });

    document.querySelectorAll('.js-day-of-month-field').forEach(el => {
        el.style.display = (isMonthly && schedulePattern === 'day_of_month') ? '' : 'none';
    });

    document.querySelectorAll('.js-weekday-field').forEach(el => {
        el.style.display = (isMonthly && schedulePattern === 'nth_weekday') ? '' : 'none';
    });

    document.querySelectorAll('.js-nth-weekday-field').forEach(el => {
        el.style.display = (isMonthly && schedulePattern === 'nth_weekday') ? '' : 'none';
    });
}

document.getElementById('prediction_type').addEventListener('change', updatePredictionRuleForm);
document.getElementById('recurrence_unit').addEventListener('change', updatePredictionRuleForm);
document.getElementById('schedule_pattern').addEventListener('change', updatePredictionRuleForm);
document.getElementById('variable').addEventListener('change', updatePredictionRuleForm);
updatePredictionRuleForm();
</script>

<?php include '../layout/footer.php'; ?>
