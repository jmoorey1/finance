<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../scripts/planned_income_engine.php';
include '../layout/header.php';

$accountsStmt = $pdo->query("
    SELECT id, name, type
    FROM accounts
    WHERE active = 1
      AND type IN ('current', 'savings')
    ORDER BY type, name
");
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesStmt = $pdo->query("
    SELECT id, name
    FROM categories
    WHERE type = 'income'
    ORDER BY name
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

$defaults = pie_defaults();
$formValues = null;
$formErrors = $_SESSION['planned_income_errors'] ?? [];
unset($_SESSION['planned_income_errors']);

if (isset($_SESSION['planned_income_form'])) {
    $formValues = array_merge($defaults, $_SESSION['planned_income_form']);
    unset($_SESSION['planned_income_form']);
}

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $eventId > 0;
$futureDays = isset($_GET['future_days']) ? (int)$_GET['future_days'] : 180;
if (!in_array($futureDays, [90, 180, 365], true)) {
    $futureDays = 180;
}

if ($formValues === null) {
    if ($editing) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM planned_income_events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            $_SESSION['planned_income_event_flash'] = '⚠️ Flexible planned income event not found.';
            header('Location: predicted.php?future_days=' . $futureDays);
            exit;
        }

        $formValues = array_merge($defaults, $event);
    } else {
        $formValues = $defaults;
    }
}

function pie_selected($a, $b): string
{
    return (string)$a === (string)$b ? 'selected' : '';
}

function pie_checked($value): string
{
    return !empty($value) ? 'checked' : '';
}
?>

<h1 class="mb-4"><?= $editing ? '✏️ Edit Flexible Planned Income' : '➕ New Flexible Planned Income' ?></h1>

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
    Use this for planned income that is <strong>already represented in budget</strong> but does not have a fixed landing date.
    The event will be used for <strong>cash-planning only</strong> at an assumed date within its window.
</div>

<div class="alert alert-warning">
    Do <strong>not</strong> also add the same item as a manual one-off predicted instance, or cash planning will double count it.
</div>

<form method="post" action="planned_income_save.php">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$formValues['id']) ?>">
    <input type="hidden" name="future_days" value="<?= (int)$futureDays ?>">

    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" maxlength="255" required
                   value="<?= htmlspecialchars((string)$formValues['description']) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">Active</label>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="active" id="active" value="1" <?= pie_checked($formValues['active']) ?>>
                <label class="form-check-label" for="active">Enabled</label>
            </div>
        </div>

        <div class="col-md-4">
            <label class="form-label">Receiving Account</label>
            <select name="account_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= pie_selected($formValues['account_id'], $a['id']) ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">Income Category</label>
            <select name="category_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= pie_selected($formValues['category_id'], $c['id']) ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">Amount (£)</label>
            <input type="number" name="amount" step="0.01" min="0.01" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['amount']) ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Window Start</label>
            <input type="date" name="window_start" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['window_start']) ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Window End</label>
            <input type="date" name="window_end" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['window_end']) ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Timing Strategy</label>
            <select name="timing_strategy" id="timing_strategy" class="form-select" required>
                <?php foreach (pie_timing_options() as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= pie_selected($formValues['timing_strategy'], $key) ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 js-manual-date-field">
            <label class="form-label">Manual Assumed Date</label>
            <input type="date" name="manual_date" class="form-control"
                   value="<?= htmlspecialchars((string)$formValues['manual_date']) ?>">
            <div class="form-text">Required only when Timing Strategy = Manual.</div>
        </div>

        <div class="col-12">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" maxlength="255"
                   value="<?= htmlspecialchars((string)$formValues['notes']) ?>">
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Save Changes' : '✅ Create Flexible Income Event' ?></button>
            <a href="predicted.php?future_days=<?= (int)$futureDays ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<script>
function updateFlexibleIncomeForm() {
    const strategy = document.getElementById('timing_strategy').value;
    document.querySelectorAll('.js-manual-date-field').forEach(el => {
        el.style.display = (strategy === 'manual') ? '' : 'none';
    });
}
document.getElementById('timing_strategy').addEventListener('change', updateFlexibleIncomeForm);
updateFlexibleIncomeForm();
</script>

<?php include '../layout/footer.php'; ?>
