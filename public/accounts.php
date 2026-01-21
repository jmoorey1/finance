<?php
require_once '../config/db.php';
include '../layout/header.php';

function ordinal($number) {
    $suffixes = ['th','st','nd','rd','th','th','th','th','th','th'];
    if ($number == '') {
        return '—';
    } elseif (($number % 100) >= 11 && ($number % 100) <= 13) {
        return $number . 'th';
    } else {
        return $number . $suffixes[$number % 10];
    }
}

// Handle new account form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_account'])) {
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $institution = trim($_POST['institution']);
    $statement_day = !empty($_POST['statement_day']) ? (int)$_POST['statement_day'] : null;
    $payment_day = !empty($_POST['payment_day']) ? (int)$_POST['payment_day'] : null;

    // ✅ BKL-003: preserve pennies (DECIMAL(10,2) in schema)
    $starting_balance = (isset($_POST['starting_balance']) && $_POST['starting_balance'] !== '')
        ? round((float)$_POST['starting_balance'], 2)
        : 0.00;

    $active = isset($_POST['active']) ? 1 : 0;

    try {
        $pdo->beginTransaction();

        // Insert the new account
        $stmt = $pdo->prepare("
            INSERT INTO accounts (name, type, institution, statement_day, payment_day, starting_balance, active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $type, $institution, $statement_day, $payment_day, $starting_balance, $active]);

        // Get the new account ID
        $account_id = $pdo->lastInsertId();

        // Insert the two linked transfer categories
        $transferToName = "Transfer To : " . $name;
        $transferFromName = "Transfer From : " . $name;

        $stmt = $pdo->prepare("
            INSERT INTO categories (name, parent_id, type, linked_account_id, budget_order)
            VALUES (?, 275, 'transfer', ?, 0)
        ");
        $stmt->execute([$transferToName, $account_id]);

        $stmt->execute([$transferFromName, $account_id]);

        $pdo->commit();

        echo "<div class='alert alert-success'>Account '" . htmlspecialchars($name) . "' and linked transfer categories have been added successfully.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>Error adding account: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}


// Load accounts
$stmt = $pdo->query("SELECT a.* from accounts a 
join transactions t on a.id = t.account_id
group by a.id
order by active desc, type, max(t.date) desc");
$accounts = $stmt->fetchAll();

$grouped = [
    'current' => [],
    'credit' => [],
    'savings' => [],
    'house' => [],
    'investment' => []
];
foreach ($accounts as $acc) {
    $grouped[$acc['type']][] = $acc;
}
?>

<div class="container mt-4">
    <h2>📂 Add New Account</h2>
    <form method="POST" class="mb-4 border p-3 rounded">
        <div class="mb-2">
            <label for="name" class="form-label">Account Name</label>
            <input type="text" class="form-control" name="name" id="name" required>
        </div>
        <div class="mb-2">
            <label for="type" class="form-label">Type</label>
            <select name="type" id="type" class="form-select" required>
                <option value="current">Current</option>
                <option value="credit">Credit</option>
                <option value="savings">Savings</option>
                <option value="house">House</option>
                <option value="investment">Investment</option>
            </select>
        </div>
        <div class="mb-2">
            <label for="institution" class="form-label">Institution</label>
            <input type="text" class="form-control" name="institution" id="institution">
        </div>
        <div class="mb-2">
            <label for="statement_day" class="form-label">Statement Day of Month</label>
            <input type="number" class="form-control" name="statement_day" id="statement_day" min="1" max="31">
        </div>
        <div class="mb-2">
            <label for="payment_day" class="form-label">Payment Day of Month</label>
            <input type="number" class="form-control" name="payment_day" id="payment_day" min="1" max="31">
        </div>
        <div class="mb-2">
            <label for="starting_balance" class="form-label">Starting Balance</label>
            <!-- ✅ BKL-003: allow decimals -->
            <input type="number" step="0.01" class="form-control" name="starting_balance" id="starting_balance">
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="active" checked>
            <label class="form-check-label" for="active">
                Active
            </label>
        </div>
        <button type="submit" name="new_account" class="btn btn-primary">Add Account</button>
    </form>

    <h2>📂 Account Management</h2>

    <table class="table table-bordered table-striped mt-3">
        <thead class="table-dark">
            <tr>
                <th>Name</th><th>Institution</th><th>State</th><th>Statement DOM</th><th>Payment DOM</th><th>Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (['current', 'credit', 'savings', 'house', 'investment'] as $type): ?>
            <tr class="table-secondary">
                <td colspan="8" class="fw-bold"><?= ucfirst($type) ?> Accounts</td>
            </tr>
            <?php foreach ($grouped[$type] as $acc): ?>
                <tr>
                    <td><?= htmlspecialchars($acc['name']) ?></td>
                    <td><?= $acc['institution'] !== '' ? htmlspecialchars($acc['institution']) : '—' ?></td>
                    <td><?= $acc['active'] == 1 ? 'Active' : 'Closed'; ?></td>
                    <td><?= htmlspecialchars(ordinal($acc['statement_day']) ?? '—') ?></td>
                    <td><?= htmlspecialchars(ordinal($acc['payment_day']) ?? '—') ?></td>
                    <td><a href="accounts_edit.php?id=<?= $acc['id'] ?>" title="Edit Account">✏️</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
