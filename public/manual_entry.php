<?php include '../layout/header.php'; ?>

<?php
require_once '../config/db.php';

// Fetch accounts and categories
$accounts = $pdo->query("SELECT id, name, type FROM accounts where active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("
    SELECT c.id, c.name, c.type, c.parent_id, p.name AS parent_name
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    WHERE c.type IN ('income', 'expense')
    ORDER BY c.type, COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name
")->fetchAll(PDO::FETCH_ASSOC);
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

<h1>Manually Add Transactions</h1>

<?php if ($success): ?>
    <div class="message success"><?= $success ?></div>
<?php elseif ($error): ?>
    <div class="message error"><?= $error ?></div>
<?php endif; ?>

<style>
    .form-section {
        border: 1px solid #ccc;
        padding: 20px;
        margin-bottom: 30px;
        border-radius: 8px;
        background: #f9f9f9;
    }
    .form-section h2 {
        margin-top: 0;
        font-size: 1.3em;
        color: #444;
    }
    .form-group {
        margin-bottom: 15px;
    }
    label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    input[type="text"],
    input[type="date"],
    input[type="number"],
    select {
        width: 100%;
        max-width: 400px;
        padding: 6px;
        font-size: 1em;
    }
    .submit-btn {
        margin-top: 10px;
        padding: 10px 16px;
        font-size: 1em;
    }
</style>

<!-- Single Transaction Form -->
<form method="POST" class="form-section">
    <input type="hidden" name="form_type" value="single" />
    <h2>Add Single Transaction</h2>

    <div class="form-group">
        <label for="date">Date</label>
        <input type="date" name="date" value="<?= date('Y-m-d') ?>" required />
    </div>

    <div class="form-group">
        <label for="account_id">Account</label>
        <select name="account_id" required>
            <option value="">Select account</option>
            <?php foreach ($accounts as $acct): ?>
                <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="category_id">Category</label>
        <select name="category_id" required>
            <option value="">Select category</option>
            <?php
            $last_type = null;
            foreach ($categories as $cat):
                if ($cat['type'] !== $last_type):
                    if ($last_type !== null) echo "</optgroup>";
                    echo "<optgroup label=\"" . ucfirst($cat['type']) . " Categories\">";
                    $last_type = $cat['type'];
                endif;

                $indent = $cat['parent_id'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
                $label = $indent . htmlspecialchars($cat['name']);
            ?>
                <option value="<?= $cat['id'] ?>"><?= $label ?></option>
            <?php endforeach; ?>
            </optgroup>
        </select>
    </div>

    <div class="form-group">
        <label for="amount">Amount</label>
        <input type="number" name="amount" step="0.01" required />
    </div>

    <div class="form-group">
        <label for="description">Description</label>
        <input type="text" name="description" required />
    </div>

    <button class="submit-btn" type="submit">Add Transaction</button>
</form>

<!-- Transfer Form -->
<form method="POST" class="form-section">
    <input type="hidden" name="form_type" value="transfer" />
    <h2>Add Transfer Between Accounts</h2>

    <div class="form-group">
        <label for="date">Date</label>
        <input type="date" name="date" value="<?= date('Y-m-d') ?>" required />
    </div>

    <div class="form-group">
        <label for="from_account_id">From Account</label>
        <select name="from_account_id" required>
            <option value="">Select account</option>
            <?php foreach ($accounts as $acct): ?>
                <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="to_account_id">To Account</label>
        <select name="to_account_id" required>
            <option value="">Select account</option>
            <?php foreach ($accounts as $acct): ?>
                <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="amount">Amount</label>
        <input type="number" name="amount" step="0.01" required />
    </div>

    <div class="form-group">
        <label for="description">Description</label>
        <input type="text" name="description" required />
    </div>

    <button class="submit-btn" type="submit">Add Transfer</button>
</form>

<?php include '../layout/footer.php'; ?>
