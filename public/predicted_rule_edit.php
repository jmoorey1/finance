<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once 'prediction_rule_helpers.php';
include '../layout/header.php';

$accountsStmt = $pdo->query("SELECT id, name, type FROM accounts WHERE active = 1 ORDER BY type, name");
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesStmt = $pdo->query("SELECT id, name, type FROM categories ORDER BY FIELD(type, 'income', 'expense', 'transfer'), name");
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

        $rule['monthly_anchor_type'] = in_array($rule['frequency'], ['weekly', 'fortnightly', 'custom'], true)
            ? 'day_of_month'
            : ($rule['anchor_type'] ?? 'day_of_month');

        $formValues = array_merge($defaults, $rule);
    } else {
        $formValues = $defaults;
    }
}

$weekdayOptions = prediction_rule_weekday_options();
$frequencyOptions = prediction_rule_frequency_options();
$monthlyAnchorOptions = prediction_rule_monthly_anchor_options();
$adjustOptions = prediction_rule_adjust_options();

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

        <div class="col-md-4">
            <label class="form-label">To Account</label>
            <select name="to_account_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= selected($formValues['to_account_id'], $a['id']) ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Required for transfer rules only.</div>
        </div>

        <div class="col-md-4">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select" required>
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
            <label class="form-label">Frequency</label>
            <select name="frequency" id="frequency" class="form-select" required>
                <?php foreach ($frequencyOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['frequency'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Repeat Interval</label>
            <input type="number" name="repeat_interval" min="1" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['repeat_interval']) ?>">
            <div class="form-text">For monthly rules, this means every N months.</div>
        </div>

        <div class="col-md-4 js-monthly-anchor-fields">
            <label class="form-label">Monthly Anchor</label>
            <select name="monthly_anchor_type" id="monthly_anchor_type" class="form-select">
                <?php foreach ($monthlyAnchorOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['monthly_anchor_type'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 js-day-of-month-field">
            <label class="form-label">Day of Month</label>
            <input type="number" name="day_of_month" min="1" max="31" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['day_of_month']) ?>">
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

        <div class="col-md-4 js-adjust-field">
            <label class="form-label">Weekend Adjustment</label>
            <select name="adjust_for_weekend" class="form-select">
                <?php foreach ($adjustOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= selected($formValues['adjust_for_weekend'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 js-last-business-day-field">
            <label class="form-label">Last Business Day Logic</label>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="is_business_day" id="is_business_day" value="1" <?= checked($formValues['is_business_day']) ?>>
                <label class="form-check-label" for="is_business_day">Use previous business day when month-end is not a business day</label>
            </div>
        </div>

        <div class="col-12">
            <div class="alert alert-light border js-custom-note" role="alert">
                Custom frequency means <strong>every N weeks from the most recent actual transaction linked to this rule</strong>.
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
    const frequency = document.getElementById('frequency').value;
    const monthlyAnchor = document.getElementById('monthly_anchor_type').value;
    const variable = document.getElementById('variable').checked;

    document.querySelectorAll('.js-variable-fields').forEach(el => {
        el.style.display = variable ? '' : 'none';
    });

    const isMonthly = frequency === 'monthly';
    const isWeeklyish = frequency === 'weekly' || frequency === 'fortnightly';
    const isCustom = frequency === 'custom';

    document.querySelectorAll('.js-monthly-anchor-fields').forEach(el => {
        el.style.display = isMonthly ? '' : 'none';
    });

    document.querySelectorAll('.js-day-of-month-field').forEach(el => {
        el.style.display = (isMonthly && monthlyAnchor === 'day_of_month') ? '' : 'none';
    });

    document.querySelectorAll('.js-weekday-field').forEach(el => {
        el.style.display = (isWeeklyish || (isMonthly && monthlyAnchor === 'nth_weekday')) ? '' : 'none';
    });

    document.querySelectorAll('.js-nth-weekday-field').forEach(el => {
        el.style.display = (isMonthly && monthlyAnchor === 'nth_weekday') ? '' : 'none';
    });

    document.querySelectorAll('.js-adjust-field').forEach(el => {
        el.style.display = (isMonthly && monthlyAnchor !== 'last_business_day') ? '' : 'none';
    });

    document.querySelectorAll('.js-last-business-day-field').forEach(el => {
        el.style.display = (isMonthly && monthlyAnchor === 'last_business_day') ? '' : 'none';
    });

    document.querySelectorAll('.js-custom-note').forEach(el => {
        el.style.display = isCustom ? '' : 'none';
    });
}

document.getElementById('frequency').addEventListener('change', updatePredictionRuleForm);
document.getElementById('monthly_anchor_type').addEventListener('change', updatePredictionRuleForm);
document.getElementById('variable').addEventListener('change', updatePredictionRuleForm);
updatePredictionRuleForm();
</script>

<?php include '../layout/footer.php'; ?>
