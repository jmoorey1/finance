<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once 'predicted_instance_helpers.php';
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
    ORDER BY FIELD(type, 'income', 'expense', 'transfer'), name
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

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

<form method="post" action="predicted_instance_save.php">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$formValues['id']) ?>">
    <input type="hidden" name="future_days" value="<?= (int)$futureDays ?>">

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Scheduled Date</label>
            <input type="date" name="scheduled_date" class="form-control" required
                   value="<?= htmlspecialchars((string)$formValues['scheduled_date']) ?>">
        </div>

        <div class="col-md-8">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" maxlength="65535" required
                   value="<?= htmlspecialchars((string)$formValues['description']) ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Category</label>
            <select name="category_id" id="category_id" class="form-select" required>
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
            <select name="to_account_id" class="form-select">
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

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Save Changes' : '✅ Create One-off Item' ?></button>
            <a href="predicted.php?future_days=<?= (int)$futureDays ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<script>
function updateOneOffForm() {
    const categorySelect = document.getElementById('category_id');
    const selectedOption = categorySelect.options[categorySelect.selectedIndex];
    const categoryType = selectedOption ? selectedOption.getAttribute('data-type') : '';
    const toAccountFields = document.querySelectorAll('.js-to-account-field');
    const amountHelp = document.getElementById('amount_help');

    toAccountFields.forEach(el => {
        el.style.display = (categoryType === 'transfer') ? '' : 'none';
    });

    if (categoryType === 'income') {
        amountHelp.textContent = 'Enter a positive amount for income.';
    } else if (categoryType === 'expense') {
        amountHelp.textContent = 'Enter a negative amount for expense.';
    } else if (categoryType === 'transfer') {
        amountHelp.textContent = 'Enter a positive transfer amount. The system will flow it from From Account to To Account.';
    } else {
        amountHelp.textContent = 'Enter a signed amount: positive for income, negative for expense, positive for transfer value.';
    }
}

document.getElementById('category_id').addEventListener('change', updateOneOffForm);
updateOneOffForm();
</script>

<?php include '../layout/footer.php'; ?>
