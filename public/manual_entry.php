<?php include '../layout/header.php'; ?>

<?php
require_once '../config/db.php';

// Fetch accounts and categories
$accounts = $pdo->query("SELECT id, name, type FROM accounts where active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $date = $_POST['date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $amount = round(floatval($_POST['amount']), 2);

        if ($_POST['form_type'] === 'single') {
            $account_id = (int)$_POST['account_id'];
            $category_id = (int)$_POST['category_id'];

            $acct_type = $pdo->query("SELECT type FROM accounts WHERE id = $account_id")->fetchColumn();
            $type = match ($acct_type) {
                'credit' => ($amount > 0 ? 'credit' : 'charge'),
                default  => ($amount > 0 ? 'deposit' : 'withdrawal')
            };

            $stmt = $pdo->prepare("
                INSERT INTO transactions (account_id, date, description, amount, type, category_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$account_id, $date, $description, $amount, $type, $category_id]);

        } elseif ($_POST['form_type'] === 'transfer') {
            $from_id = (int)$_POST['from_account_id'];
            $to_id = (int)$_POST['to_account_id'];

            if ($from_id === $to_id) throw new Exception("Cannot transfer between the same account.");

            $pdo->exec("INSERT INTO transfer_groups () VALUES ()");
            $group_id = $pdo->lastInsertId();

            // Look up categories for both sides
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE linked_account_id = ? AND name LIKE ?");
            $stmt->execute([$to_id, 'Transfer To :%']);
            $from_cat = $stmt->fetchColumn();

            $stmt->execute([$from_id, 'Transfer From :%']);
            $to_cat = $stmt->fetchColumn();

            if (!$from_cat || !$to_cat) throw new Exception("Transfer categories not found for this pair.");

            $type = 'transfer';

            // From side
            $pdo->prepare("
                INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$from_id, $date, $description, -$amount, $type, $from_cat, $group_id]);

            // To side
            $pdo->prepare("
                INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$to_id, $date, $description, $amount, $type, $to_cat, $group_id]);
        }

        $pdo->commit();
        $success = "Transaction successfully added.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manual Entry</title>
    <style>
        body { font-family: sans-serif; margin: 2em; }
        form { margin-bottom: 2em; border: 1px solid #ccc; padding: 1em; }
        label { display: block; margin-top: 0.5em; }
        input, select { width: 100%; padding: 6px; margin-top: 2px; }
        .section-title { font-weight: bold; font-size: 1.2em; margin-top: 1em; }
        .submit-btn { margin-top: 1em; }
        .message { padding: 10px; margin-bottom: 10px; }
        .success { background: #e0ffe0; border: 1px solid #4caf50; }
        .error { background: #ffe0e0; border: 1px solid #f44336; }
    </style>
</head>
<body>

<h1>Manually Add Transactions</h1>

<?php if ($success): ?>
    <div class="message success"><?= $success ?></div>
<?php elseif ($error): ?>
    <div class="message error"><?= $error ?></div>
<?php endif; ?>

<!-- Single Transaction Form -->
<form method="POST">
    <input type="hidden" name="form_type" value="single" />
    <div class="section-title">Add Single Transaction</div>

    <label for="date">Date</label>
    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required />

    <label for="account_id">Account</label>
    <select name="account_id" required>
        <option value="">Select account</option>
        <?php foreach ($accounts as $acct): ?>
            <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="category_id">Category</label>
    <select name="category_id" required>
        <option value="">Select category</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="amount">Amount</label>
    <input type="number" name="amount" step="0.01" required />

    <label for="description">Description</label>
    <input type="text" name="description" required />

    <button class="submit-btn" type="submit">Add Transaction</button>
</form>

<!-- Transfer Form -->
<form method="POST">
    <input type="hidden" name="form_type" value="transfer" />
    <div class="section-title">Add Transfer Between Accounts</div>

    <label for="date">Date</label>
    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required />

    <label for="from_account_id">From Account</label>
    <select name="from_account_id" required>
        <option value="">Select account</option>
        <?php foreach ($accounts as $acct): ?>
            <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="to_account_id">To Account</label>
    <select name="to_account_id" required>
        <option value="">Select account</option>
        <?php foreach ($accounts as $acct): ?>
            <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="amount">Amount</label>
    <input type="number" name="amount" step="0.01" required />

    <label for="description">Description</label>
    <input type="text" name="description" required />

    <button class="submit-btn" type="submit">Add Transfer</button>
</form>

</body>
</html>
<?php include '../layout/footer.php'; ?>
