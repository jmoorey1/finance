<?php
require_once('../config/db.php');

// Fetch status filter
$statusFilter = $_GET['status'] ?? 'new';
$statuses = ['new', 'potential_duplicate', 'duplicate', 'all'];

// Fetch transactions
$sql = "
    SELECT s.*, a.name AS account_name,
           t.date AS match_date, t.description AS match_desc,
           t.amount AS match_amt, t.type AS match_type, ac.name AS match_account
    FROM staging_transactions s
    JOIN accounts a ON s.account_id = a.id
    LEFT JOIN transactions t ON s.matched_transaction_id = t.id
    LEFT JOIN accounts ac ON t.account_id = ac.id
";

if (in_array($statusFilter, $statuses) && $statusFilter !== 'all') {
    $sql .= " WHERE s.status = ?";
    $stmt = $pdo->prepare($sql . " ORDER BY s.date ASC");
    $stmt->execute([$statusFilter]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY s.date ASC");
}

$rows = $stmt->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Review Transactions (<?= htmlspecialchars($statusFilter) ?>)</title>
    <script>
    function addSplitRow(txnId) {
        const container = document.getElementById(`split-container-${txnId}`);
        const div = document.createElement('div');
        div.innerHTML = `
            <select name="split_category_id[]" required>
                ${document.getElementById('split-template-options').innerHTML}
            </select>
            <input type="number" name="split_amount[]" step="0.01" required>
            <button type="button" onclick="this.parentElement.remove()">X</button>
        `;
        container.appendChild(div);
    }
    </script>
    <style>
    .split-mode {
        background: #fef9e7;
        padding: 5px;
        margin-top: 5px;
    }
    </style>
</head>
<body>
<h1>Review Transactions (<?= htmlspecialchars($statusFilter) ?>)</h1>
<nav>
  <a href="?status=new">New</a> |
  <a href="?status=potential_duplicate">Potential Duplicates</a> |
  <a href="?status=duplicate">Duplicates</a> |
  <a href="?status=all">All</a>
</nav>

<br><table border="1" cellpadding="5">
<tr>
  <th>Status</th>
  <th>Date</th>
  <th>Account</th>
  <th>Description</th>
  <th>Amount</th>
  <th>Memo</th>
  <th>Category / Splits</th>
  <th>Action</th>
  <th>Match</th>
</tr>

<!-- Template for categories used in JS -->
<template id="split-template-options">
    <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
    <?php endforeach; ?>
</template>

<?php foreach ($rows as $txn): ?>
<tr<?= $txn['status'] === 'duplicate' ? ' style="background: #fdd;"' : '' ?>>
  <td><?= htmlspecialchars($txn['status']) ?></td>
  <td><?= htmlspecialchars($txn['date']) ?></td>
  <td><?= htmlspecialchars($txn['account_name']) ?></td>
  <td><?= htmlspecialchars($txn['description']) ?></td>
  <td><?= htmlspecialchars($txn['amount']) ?></td>
  <td><?= htmlspecialchars($txn['original_memo']) ?></td>

  <td>
    <form method="post" action="review_actions.php">
      <select name="category_id" onchange="this.form.split_mode.checked = (this.value == 197)">
        <option value="">-- select --</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <br>
      <label><input type="checkbox" name="split_mode" id="split_mode_<?= $txn['id'] ?>" value="1" <?= $txn['category_id'] == 197 ? 'checked' : '' ?>> Split?</label>
      <input type="hidden" name="txn_id" value="<?= $txn['id'] ?>">

      <div id="split-container-<?= $txn['id'] ?>" class="split-mode" style="display: block;">
        <button type="button" onclick="addSplitRow(<?= $txn['id'] ?>)">Add Split</button>
      </div>
  </td>

  <td>
      <?php if ($txn['status'] === 'potential_duplicate'): ?>
        <button type="submit" name="action" value="confirm_duplicate">Confirm Match</button>
        <button type="submit" name="action" value="not_duplicate">Not a Match</button>
      <?php elseif ($txn['status'] === 'duplicate'): ?>
        <button type="submit" name="action" value="delete">Delete</button>
      <?php else: ?>
        <button type="submit" name="action" value="approve">Approve</button>
        <button type="submit" name="action" value="delete">Delete</button>
      <?php endif; ?>
    </form>
  </td>

  <td>
    <?php if ($txn['match_date']): ?>
      <?= htmlspecialchars($txn['match_date']) ?><br>
      <?= htmlspecialchars($txn['match_desc']) ?><br>
      <?= htmlspecialchars($txn['match_amt']) ?><br>
      <?= htmlspecialchars($txn['match_type']) ?><br>
      <?= htmlspecialchars($txn['match_account']) ?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
