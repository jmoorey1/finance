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
$account = $stmt->fetch();

if (!$account) {
    echo "<div class='alert alert-danger'>Account not found.</div>";
    exit;
}

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
        <input type="hidden" name="id" value="<?= $account['id'] ?>">

        <div class="mb-2">
            <label for="name" class="form-label">Account Name</label>
            <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($account['name']) ?>" required>
        </div>
        <div class="mb-2">
            <label for="type" class="form-label">Type</label>
            <select name="type" id="type" class="form-select" required>
                <?php foreach (['current', 'credit', 'savings', 'house', 'investment'] as $type): ?>
                    <option value="<?= $type ?>" <?= $account['type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2">
            <label for="institution" class="form-label">Institution</label>
            <input type="text" class="form-control" name="institution" id="institution" value="<?= htmlspecialchars($account['institution']) ?>">
        </div>
        <div class="mb-2">
            <label for="statement_day" class="form-label">Statement Day of Month</label>
            <input type="number" class="form-control" name="statement_day" id="statement_day" min="1" max="31" value="<?= htmlspecialchars($account['statement_day']) ?>">
        </div>
        <div class="mb-2">
            <label for="payment_day" class="form-label">Payment Day of Month</label>
            <input type="number" class="form-control" name="payment_day" id="payment_day" min="1" max="31" value="<?= htmlspecialchars($account['payment_day']) ?>">
        </div>
        <div class="mb-2">
            <label for="starting_balance" class="form-label">Starting Balance</label>
            <input type="number" class="form-control" name="starting_balance" id="starting_balance" value="<?= htmlspecialchars($account['starting_balance']) ?>">
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="active" <?= $account['active'] == 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Active</label>
        </div>

        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="accounts.php" class="btn btn-secondary ms-2">Cancel</a>
    </form>
</div>

<?php include '../layout/footer.php'; ?>
