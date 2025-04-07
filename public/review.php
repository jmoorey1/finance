<?php
require_once('../config/db.php');

$statusFilter = $_GET['status'] ?? 'new';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $txn_id = (int)$_POST['txn_id'];
    $action = $_POST['action'] ?? '';
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;

    $stmt = $pdo->prepare("SELECT * FROM staging_transactions WHERE id = ?");
    $stmt->execute([$txn_id]);
    $row = $stmt->fetch();

    if (!$row) exit;

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);

    } elseif ($action === 'confirm_duplicate') {
        if ($row['matched_transaction_id']) {
            $pdo->prepare("UPDATE transactions SET date = ? WHERE id = ?")
                ->execute([$row['date'], $row['matched_transaction_id']]);
            $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
        }

    } elseif ($action === 'not_duplicate' && $category_id) {
        $type = $row['amount'] >= 0 ? 'deposit' : 'withdrawal';
        $pdo->prepare("INSERT INTO transactions (account_id, date, description, amount, type, category_id)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$row['account_id'], $row['date'], $row['description'], $row['amount'], $type, $category_id]);
        $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);

    } elseif ($action === 'approve' && $category_id) {
        // Lookup the full category row
        $catStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $catStmt->execute([$category_id]);
        $cat = $catStmt->fetch();

        $isTransfer = ($cat && $cat['type'] === 'transfer' && $cat['linked_account_id']);
        $transferTo = str_starts_with($cat['name'], 'Transfer To');
        $transferFrom = str_starts_with($cat['name'], 'Transfer From');

        $type = $isTransfer ? 'transfer' : ($row['amount'] >= 0 ? 'deposit' : 'withdrawal');

        // Step 1: Create transfer_group if needed
        $transferGroupId = null;
        if ($isTransfer) {
            $pdo->prepare("INSERT INTO transfer_groups (description) VALUES (?)")
                ->execute(["Auto transfer for staging txn ID " . $row['id']]);
            $transferGroupId = $pdo->lastInsertId();
        }

        // Step 2: Insert primary transaction
        $pdo->prepare("INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $row['account_id'],
                $row['date'],
                $row['description'],
                $row['amount'],
                $type,
                $category_id,
                $transferGroupId
            ]);

        // Step 3: Insert opposite-side transfer if needed
        if ($isTransfer) {
            $oppositeAmount = $row['amount'] * -1;
            $oppositeAccountId = $cat['linked_account_id'];

            $srcAccountNameStmt = $pdo->prepare("SELECT name FROM accounts WHERE id = ?");
            $srcAccountNameStmt->execute([$row['account_id']]);
            $srcAccountName = $srcAccountNameStmt->fetchColumn();

            $expectedOppositeName = $transferTo
                ? "Transfer From : $srcAccountName"
                : "Transfer To : $srcAccountName";

            $lookup = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND linked_account_id = ?");
            $lookup->execute([$expectedOppositeName, $row['account_id']]);
            $oppositeCategoryId = $lookup->fetchColumn();

            $pdo->prepare("INSERT INTO transactions (account_id, date, description, amount, type, category_id, transfer_group_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $oppositeAccountId,
                    $row['date'],
                    $row['description'],
                    $oppositeAmount,
                    'transfer',
                    $oppositeCategoryId ?: null,
                    $transferGroupId
                ]);
        }

        // Step 4: Remove from staging
        $pdo->prepare("DELETE FROM staging_transactions WHERE id = ?")->execute([$txn_id]);
    }
}

// Fetch staging rows
$filterSql = "
    SELECT s.*, a.name AS account_name,
           t.date AS match_date, t.description AS match_desc,
           t.amount AS match_amt, t.type AS match_type, ac.name AS match_account
    FROM staging_transactions s
    JOIN accounts a ON s.account_id = a.id
    LEFT JOIN transactions t ON s.matched_transaction_id = t.id
    LEFT JOIN accounts ac ON t.account_id = ac.id
";

if ($statusFilter !== 'all') {
    $filterSql .= " WHERE s.status = ?";
    $filterStmt = $pdo->prepare($filterSql . " ORDER BY s.date ASC LIMIT 25");
    $filterStmt->execute([$statusFilter]);
} else {
    $filterStmt = $pdo->query($filterSql . " ORDER BY s.date ASC LIMIT 25");
}

$rows = $filterStmt->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
  <title>Review Staging Transactions</title>
</head>
<body>
<h1>Review Staging Transactions (<?= htmlspecialchars($statusFilter) ?>)</h1>
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
  <th>Original Memo</th>
  <th>Category</th>
  <th>Action</th>
  <th>Matched Transaction</th>
</tr>

<?php foreach ($rows as $txn): ?>
<tr>
  <form method="post">
    <td><?= htmlspecialchars($txn['status']) ?></td>
    <td><?= htmlspecialchars($txn['date']) ?></td>
    <td><?= htmlspecialchars($txn['account_name']) ?></td>
    <td><?= htmlspecialchars($txn['description']) ?></td>
    <td><?= htmlspecialchars($txn['amount']) ?></td>
    <td><?= htmlspecialchars($txn['original_memo']) ?></td>
    <td>
      <select name="category_id">
        <option value="">-- select --</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td>
      <input type="hidden" name="txn_id" value="<?= $txn['id'] ?>">
      <?php if ($txn['status'] === 'potential_duplicate'): ?>
        <button type="submit" name="action" value="confirm_duplicate">Confirm Match</button>
        <button type="submit" name="action" value="not_duplicate">Not a Match</button>
      <?php elseif ($txn['status'] === 'duplicate'): ?>
        <button type="submit" name="action" value="delete">Delete</button>
      <?php else: ?>
        <button type="submit" name="action" value="approve">Approve</button>
        <button type="submit" name="action" value="delete">Delete</button>
      <?php endif; ?>
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
  </form>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
