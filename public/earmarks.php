<?php
require_once '../config/db.php';
include '../layout/header.php';

$stmt = $pdo->query("
    SELECT e.id, e.name, e.description, 
           IFNULL(SUM(t.amount), 0) AS total_amount,
           min(t.date) as start_date,
           max(t.date) as end_date
    FROM earmarks e
    LEFT JOIN transactions t ON t.earmark_id = e.id
    GROUP BY e.id
    ORDER BY max(t.date) desc
");

// Load IDs of all current/credit/savings accounts for linking
$acct_stmt = $pdo->query("SELECT id FROM accounts WHERE type IN ('current','credit','savings') and active=1");
$account_ids = array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
$account_query = implode('&', array_map(fn($id) => "accounts[]=$id", $account_ids));


echo "<h2>Fund Summary</h2>";
echo "<table class='table table-striped table-sm align-middle'>";

echo "<thead><tr><th>Fund Name</th><th>Description</th><th>First Spend</th><th>Last Spend</th><th>Total Remaining</th></tr></thead><tbody>";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $earmark_link = "ledger.php?earmark_id=" . urlencode($row['id']) . "&start=" . urlencode($row['start_date']) . "&end=" . urlencode($row['end_date']) . "&" . $account_query;
    echo "<tr>";
    echo "<td><a href='$earmark_link'>{$row['name']}</a></td>";
    echo "<td>{$row['description']}</td>";
    echo "<td>" . (new DateTime($row['start_date']))->format('M y') . "</td>";
    echo "<td>" . (new DateTime($row['end_date']))->format('M y') . "</td>";
    echo "<td>£" . number_format($row['total_amount'], 2) . "</td>";
    echo "</tr>";
}
echo "</tbody></table>";

include '../layout/footer.php';
?>
