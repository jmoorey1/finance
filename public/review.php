<?php
require_once('../config/db.php');

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['txn_id'])) {
    $txn_id = (int)$_POST['txn_id'];
    $action = $_POST['action'];

    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?");
        $stmt->execute([$txn_id]);
    } elseif ($action === 'defer') {
        $stmt = $pdo->prepare("UPDATE staging_transactions SET status = 'deferred' WHERE id = ?");
        $stmt->execute([$txn_id]);
    } elseif ($action === 'approve' && isset($_POST['category_id'])) {
        $category_id = (int)$_POST['category_id'];

        // Get the staging row
        $stmt = $pdo->prepare("SELECT * FROM staging_transactions WHERE id = ?");
        $stmt->execute([$txn_id]);
        $row = $stmt->fetch();

        if ($row) {
            // Insert into transactions
            $insert = $pdo->prepare("INSERT INTO transactions (account_id, date, description, amount, type, category_id)
                                     VALUES (?, ?, ?, ?, ?, ?)");
            $txn_type = $row['amount'] >= 0 ? 'deposit' : 'withdrawal'; // basic logic
            $insert->execute([
                $row['account_id'],
                $row['date'],
                $row['description'],
                $row['amount'],
                $txn_type,
                $category_id
            ]);

            // Mark as imported
            $mark = $pdo->prepare("UPDATE staging_transactions SET status = 'imported' WHERE id = ?");
            $mark->execute([$txn_id]);
        }
    }
}

// Fetch 25 'new' staging transactions
$stmt = $pdo->prepare("SELECT s.*, a.name AS account_name FROM staging_transactions s
                       JOIN accounts a ON s.account_id = a.id
                       WHERE s.status = 'new' ORDER BY s.date ASC LIMIT 25");
$stmt->execute();
$transactions = $stmt->fetchAll();

// Fetch categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head><title>Review Transactions</title></head>
<body>
  <h1>Review Staging Transactions</h1>
  <table border="1" cellpadding="5">
    <tr>
      <th>Date</th>
      <th>Account</th>
      <th>Description</th>
      <th>Amount</th>
      <th>Original Memo</th>
      <th>Category</th>
      <th>Action</th>
    </tr>

    <?php foreach ($transactions as $txn): ?>
    <tr>
      <form method="post">
        <td><?= htmlspecialchars($txn['date']) ?></td>
        <td><?= htmlspecialchars($txn['account_name']) ?></td>
        <td><?= htmlspecialchars($txn['description']) ?></td>
        <td><?= htmlspecialchars($txn['amount']) ?></td>
        <td><?= htmlspecialchars($txn['original_memo']) ?></td>
        <td>
          <select name="category_id" required>
            <option value="">-- select --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <input type="hidden" name="txn_id" value="<?= $txn['id'] ?>">
          <button type="submit" name="action" value="approve">Approve</button>
          <button type="submit" name="action" value="defer">Defer</button>
          <button type="submit" name="action" value="delete" onclick="return confirm('Are you sure?')">Delete</button>
        </td>
      </form>
    </tr>
    <?php endforeach; ?>
  </table>
</body>
</html>
