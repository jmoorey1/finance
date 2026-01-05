<?php
require_once '../config/db.php';
include '../layout/header.php';

if (!isset($_GET['id'])) {
    echo "<div class='alert alert-danger'>No account ID provided.</div>";
    exit;
}

$id = (int)$_GET['id'];

// Fetch account details
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "<div class='alert alert-danger'>Account not found.</div>";
    exit;
}

// Fetch active current accounts for "paid_from" dropdown (credit cards only)
$paidFromAccounts = $pdo->query("
    SELECT id, name
    FROM accounts
    WHERE active = 1 AND type = 'current'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Check if the associated transfer categories exist
$transferToName = "Transfer To : " . $account['name'];
$transferFromName = "Transfer From : " . $account['name'];

$stmt = $pdo->prepare("
    SELECT id, name FROM categories
    WHERE type = 'transfer' AND linked_account_id = ? AND parent_id = 275
");
$stmt->execute([$id]);
$transferCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if Transfer To and Transfer From exist
$hasTransferTo = false;
$hasTransferFrom = false;
foreach ($transferCategories as $cat) {
    if (strpos($cat['name'], "Transfer To :") === 0) {
        $hasTransferTo = true;
    }
    if (strpos($cat['name'], "Transfer From :") === 0) {
        $hasTransferFrom = true;
    }
}

// Create missing transfer categories
if (!$hasTransferTo) {
    $stmt = $pdo->prepare("
        INSERT INTO categories (name, parent_id, type, linked_account_id, budget_order)
        VALUES (?, 275, 'transfer', ?, 0)
    ");
    $stmt->execute([$transferToName, $id]);
}
if (!$hasTransferFrom) {
    $stmt = $pdo->prepare("
        INSERT INTO categories (name, parent_id, type, linked_account_id, budget_order)
        VALUES (?, 275, 'transfer', ?, 0)
    ");
    $stmt->execute([$transferFromName, $id]);
}
?>

<div class="container mt-4">
    <h2>✏️ Edit Account</h2>

    <form action="accounts_edit_submit.php" method="POST" class="border p-3 rounded">
        <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">

        <div class="mb-2">
            <label for="name" class="form-label">Account Name</label>
            <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($account['name']) ?>" required>
        </div>

        <div class="mb-2">
            <label for="type" class="form-label">Type</label>
            <select name="type" id="type" class="form-select" required>
                <?php foreach (['current', 'credit', 'savings', 'house', 'investment', 'loan'] as $type): ?>
                    <option value="<?= $type ?>" <?= ($account['type'] === $type ? 'selected' : '') ?>><?= ucfirst($type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-2">
            <label for="institution" class="form-label">Institution</label>
            <input type="text" class="form-control" name="institution" id="institution" value="<?= htmlspecialchars($account['institution'] ?? '') ?>">
        </div>

        <div class="mb-2">
            <label for="statement_day" class="form-label">Statement Day of Month</label>
            <input type="number" min="1" max="31" class="form-control" name="statement_day" id="statement_day" value="<?= htmlspecialchars($account['statement_day'] ?? '') ?>">
        </div>

        <div class="mb-2 credit-only" style="display:none;">
            <label for="payment_day" class="form-label">Payment Day of Month (Credit cards)</label>
            <input type="number" min="1" max="31" class="form-control" name="payment_day" id="payment_day" value="<?= htmlspecialchars($account['payment_day'] ?? '') ?>">
        </div>

        <div class="mb-2">
            <label for="starting_balance" class="form-label">Starting Balance</label>
            <input type="number" step="0.01" class="form-control" name="starting_balance" id="starting_balance" value="<?= htmlspecialchars($account['starting_balance'] ?? '0.00') ?>">
        </div>

        <!-- Credit card-specific settings -->
        <div id="creditSettings" class="border rounded p-3 mt-3" style="display:none;">
            <h5 class="mb-3">💳 Credit Card Settings</h5>

            <div class="row g-3">
                <div class="col-md-4">
                    <label for="paid_from" class="form-label">Paid From Account</label>
                    <select name="paid_from" id="paid_from" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($paidFromAccounts as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= ((string)$account['paid_from'] === (string)$a['id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">For credit cards: which current account pays the repayment.</div>
                </div>

                <div class="col-md-4">
                    <label for="repayment_method" class="form-label">Repayment Method</label>
                    <select name="repayment_method" id="repayment_method" class="form-select">
                        <?php foreach (['full' => 'Pay in full', 'minimum' => 'Minimum payment', 'fixed' => 'Fixed amount'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($account['repayment_method'] === $val ? 'selected' : '') ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4" id="fixedAmountWrap">
                    <label for="fixed_payment_amount" class="form-label">Fixed Payment Amount (£)</label>
                    <input type="number" step="0.01" class="form-control" name="fixed_payment_amount" id="fixed_payment_amount" value="<?= htmlspecialchars($account['fixed_payment_amount'] ?? '') ?>">
                </div>

                <div class="col-md-4" id="minFloorWrap">
                    <label for="min_payment_floor" class="form-label">Minimum Payment Floor (£)</label>
                    <input type="number" step="0.01" class="form-control" name="min_payment_floor" id="min_payment_floor" value="<?= htmlspecialchars($account['min_payment_floor'] ?? '') ?>">
                </div>

                <div class="col-md-4" id="minPercentWrap">
                    <label for="min_payment_percent" class="form-label">Minimum Payment Percent (%)</label>
                    <input type="number" step="0.001" class="form-control" name="min_payment_percent" id="min_payment_percent" value="<?= htmlspecialchars($account['min_payment_percent'] ?? '') ?>">
                    <div class="form-text">Enter as a percentage (e.g. 2.250 for 2.25%).</div>
                </div>

                <div class="col-md-4" id="minCalcWrap">
                    <label for="min_payment_calc" class="form-label">Minimum Payment Rule</label>
                    <select name="min_payment_calc" id="min_payment_calc" class="form-select">
                        <option value="floor_or_percent" <?= ($account['min_payment_calc'] === 'floor_or_percent' ? 'selected' : '') ?>>Greater of floor or percent</option>
                        <option value="floor_or_percent_plus_interest" <?= ($account['min_payment_calc'] === 'floor_or_percent_plus_interest' ? 'selected' : '') ?>>Greater of floor or percent (+ interest/fees later)</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="promo_apr" class="form-label">Promotional APR (%)</label>
                    <input type="number" step="0.001" class="form-control" name="promo_apr" id="promo_apr" value="<?= htmlspecialchars($account['promo_apr'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label for="promo_end_date" class="form-label">Promo End Date</label>
                    <input type="date" class="form-control" name="promo_end_date" id="promo_end_date" value="<?= htmlspecialchars($account['promo_end_date'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label for="standard_apr" class="form-label">Standard APR (%)</label>
                    <input type="number" step="0.001" class="form-control" name="standard_apr" id="standard_apr" value="<?= htmlspecialchars($account['standard_apr'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-check mt-3 mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="active" <?= ((int)$account['active'] === 1 ? 'checked' : '') ?>>
            <label class="form-check-label" for="active">Active</label>
        </div>

        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="accounts.php" class="btn btn-secondary ms-2">Cancel</a>
    </form>
</div>

<script>
(function () {
    function toggleCreditFields() {
        const typeEl = document.getElementById('type');
        const creditSettings = document.getElementById('creditSettings');
        const creditOnly = document.querySelectorAll('.credit-only');
        const isCredit = (typeEl && typeEl.value === 'credit');

        if (creditSettings) creditSettings.style.display = isCredit ? 'block' : 'none';
        creditOnly.forEach(el => el.style.display = isCredit ? 'block' : 'none');

        toggleRepaymentFields();
    }

    function toggleRepaymentFields() {
        const methodEl = document.getElementById('repayment_method');
        if (!methodEl) return;

        const method = methodEl.value;
        const showFixed = (method === 'fixed');
        const showMin = (method === 'minimum');

        const fixedWrap = document.getElementById('fixedAmountWrap');
        const minFloorWrap = document.getElementById('minFloorWrap');
        const minPercentWrap = document.getElementById('minPercentWrap');
        const minCalcWrap = document.getElementById('minCalcWrap');

        if (fixedWrap) fixedWrap.style.display = showFixed ? 'block' : 'none';
        if (minFloorWrap) minFloorWrap.style.display = showMin ? 'block' : 'none';
        if (minPercentWrap) minPercentWrap.style.display = showMin ? 'block' : 'none';
        if (minCalcWrap) minCalcWrap.style.display = showMin ? 'block' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const typeEl = document.getElementById('type');
        const methodEl = document.getElementById('repayment_method');

        if (typeEl) typeEl.addEventListener('change', toggleCreditFields);
        if (methodEl) methodEl.addEventListener('change', toggleRepaymentFields);

        toggleCreditFields();
    });
})();
</script>

<?php include '../layout/footer.php'; ?>
