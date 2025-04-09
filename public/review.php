<?php
require_once('../config/db.php');

// Fetch transactions
$stmt = $pdo->query("
    SELECT s.*, a.name AS account_name
    FROM staging_transactions s
    JOIN accounts a ON s.account_id = a.id
    ORDER BY s.date ASC
");
$transactions = $stmt->fetchAll();

$categories = $pdo->query("
    SELECT id, name FROM categories
    WHERE type IN ('income', 'expense')
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$accounts = $pdo->query("
    SELECT id, name FROM accounts
    WHERE type IN ('current','credit','savings')
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Review Transactions</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; vertical-align: top; }
        th { background: #f2f2f2; }

        .form-container { display: flex; flex-direction: column; gap: 8px; }

        .form-group label { display: block; font-weight: bold; margin-bottom: 3px; }
        .form-group select, .form-group input[type="number"], .form-group input[type="text"] {
            width: 100%;
            padding: 4px;
        }

        .split-section, .transfer-section {
            background: #f9f9f9;
            padding: 6px;
            border: 1px solid #ddd;
            display: none;
        }

        .split-section div { display: flex; gap: 6px; margin-top: 5px; }
        .split-section button, .transfer-section button { margin-top: 4px; }

        .actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .actions button { padding: 4px 8px; }
    </style>
    <script>
    function toggleSplit(txnId, checked) {
        document.getElementById('split-' + txnId).style.display = checked ? 'block' : 'none';
    }

    function addSplitRow(txnId) {
        const container = document.getElementById('split-' + txnId + '-rows');
        const div = document.createElement('div');
        div.innerHTML = `
            <select name="split_category_id[]" required>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" step="0.01" name="split_amount[]" required>
            <button type="button" onclick="this.parentElement.remove()">×</button>
        `;
        container.appendChild(div);
    }

    function toggleTransferFields(selectEl, txnId) {
        const val = selectEl.value;
        document.getElementById('transfer-counter-' + txnId).style.display = (val === 'create_opposite') ? 'block' : 'none';
        document.getElementById('transfer-link-' + txnId).style.display = (val === 'link_existing') ? 'block' : 'none';
    }
    </script>
</head>
<body>

<h1>Review Staging Transactions</h1>

<table>
    <thead>
        <tr>
            <th>ID</th>
			<th>Status</th>
            <th>Date</th>
            <th>Account</th>
            <th>Description</th>
            <th>Amount</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($transactions as $txn): ?>
<tr>
    <td><?= $txn['id'] ?></td>
    <td><?= htmlspecialchars($txn['status']) ?></td>
    <td><?= htmlspecialchars($txn['date']) ?></td>
    <td><?= htmlspecialchars($txn['account_name']) ?></td>
    <td><?= htmlspecialchars($txn['description']) ?></td>
    <td><?= number_format($txn['amount'], 2) ?></td>
    <td>
        <form method="POST" action="review_actions.php" class="form-container">
            <input type="hidden" name="txn_id" value="<?= $txn['id'] ?>">

            <div class="form-group">
                <label>Category</label>
                <select name="category_id">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="split_mode" value="1" onchange="toggleSplit(<?= $txn['id'] ?>, this.checked)"> Split this transaction</label>
            </div>

            <div class="split-section" id="split-<?= $txn['id'] ?>">
                <div id="split-<?= $txn['id'] ?>-rows"></div>
                <button type="button" onclick="addSplitRow(<?= $txn['id'] ?>)">+ Add Split</button>
            </div>

            <div class="form-group">
                <label>Transfer Mode</label>
                <select name="transfer_mode" onchange="toggleTransferFields(this, <?= $txn['id'] ?>)">
                    <option value="">None</option>
                    <option value="create_opposite">Create Opposite Entry</option>
                    <option value="link_existing">Link to Other Transaction</option>
                </select>
            </div>

            <div class="transfer-section" id="transfer-counter-<?= $txn['id'] ?>">
                <label>Counterparty Account</label>
                <select name="counter_account_id">
                    <option value="">-- Select Account --</option>
                    <?php foreach ($accounts as $acct): ?>
                        <option value="<?= $acct['id'] ?>"><?= htmlspecialchars($acct['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="transfer-section" id="transfer-link-<?= $txn['id'] ?>">
                <label>Other Transaction ID</label>
                <input type="number" name="link_txn_id" placeholder="e.g. 1032">
            </div>

            <div class="actions">
                <button type="submit" name="action" value="approve">Approve</button>
                <button type="submit" name="action" value="delete">Delete</button>
            </div>
        </form>
    </td>
</tr>
<?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
