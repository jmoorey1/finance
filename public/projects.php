<?php
require_once '../config/db.php';

// Handle form submission to create a new statement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $description = $_POST['description'];

    $stmt = $pdo->prepare("INSERT INTO projects (name, description) VALUES (?, ?)");
    $stmt->execute([$name, $description]);

    // Redirect back to projects to reload the page
    header("Location: projects.php?success=1");
    exit;
}
include '../layout/header.php';
echo "<h2>🏗 Project and Trip Summary</h2>";
?>


<form method="POST" class="mb-4">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Project Name</label>
            <input type="text" name="name" class="form-control" value="">
        </div>
        <div class="col-md-3">
            <label class="form-label">Project Description</label>
            <input type="text" name="description" class="form-control" value="">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Create Project/Trip</button>
        </div>
    </div>
</form>

<?php
$stmt = $pdo->query("
    SELECT p.id, p.name, p.description, 
           IFNULL(SUM(-t.amount), 0) AS total_amount,
           min(t.date) as start_date,
           max(t.date) as end_date
    FROM projects p
    LEFT JOIN transactions t ON t.project_id = p.id
    GROUP BY p.id
    ORDER BY max(t.date) desc
");

// Load IDs of all current/credit/savings accounts for linking
$acct_stmt = $pdo->query("SELECT id FROM accounts WHERE type IN ('current','credit','savings') and active=1");
$account_ids = array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
$account_query = implode('&', array_map(fn($id) => "accounts[]=$id", $account_ids));



echo "<table class='table table-striped table-sm align-middle'>";

echo "<thead><tr><th>Project/Trip Name</th><th>Description</th><th>First Spend</th><th>Last Spend</th><th>Total Spend</th></tr></thead><tbody>";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $project_link = "ledger.php?project_id=" . urlencode($row['id']) . "&start=" . urlencode($row['start_date']) . "&end=" . urlencode($row['end_date']) . "&" . $account_query;
    echo "<tr>";
    echo "<td><a href='$project_link'>{$row['name']}</a></td>";
    echo "<td>{$row['description']}</td>";
    echo "<td>" . (new DateTime($row['start_date']))->format('M y') . "</td>";
    echo "<td>" . (new DateTime($row['end_date']))->format('M y') . "</td>";
    echo "<td>£" . number_format($row['total_amount'], 2) . "</td>";
    echo "</tr>";
}
echo "</tbody></table>";

include '../layout/footer.php';
?>
