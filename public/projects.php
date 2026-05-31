<?php
require_once '../config/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Handle form submission to create a new project
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($name !== '') {
        $stmt = $pdo->prepare("INSERT INTO projects (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $description]);
    }

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
/*
 * Use ledger_lines rather than transactions so split-level project attribution
 * is included automatically.
 *
 * - Non-split transactions appear once as Actual rows
 * - Split transactions appear as Split rows
 * - Split rows inherit parent project_id in ledger_lines when split project_id is blank
 */
$stmt = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.description,
        COALESCE(SUM(-ll.amount), 0) AS total_amount,
        MIN(ll.line_date) AS start_date,
        MAX(ll.line_date) AS end_date,
        COUNT(ll.transaction_id) AS line_count
    FROM projects p
    LEFT JOIN ledger_lines ll
        ON ll.project_id = p.id
       AND ll.is_prediction = 0
    GROUP BY p.id, p.name, p.description
    ORDER BY
        CASE WHEN MAX(ll.line_date) IS NULL THEN 1 ELSE 0 END,
        MAX(ll.line_date) DESC,
        p.name ASC
");

// Load IDs of all current/credit/savings accounts for linking
$acct_stmt = $pdo->query("
    SELECT id
    FROM accounts
    WHERE type IN ('current','credit','savings')
      AND active = 1
");
$account_ids = array_column($acct_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
$account_query = implode('&', array_map(fn($id) => "accounts[]=" . (int)$id, $account_ids));

echo "<table class='table table-striped table-sm align-middle'>";
echo "<thead><tr><th>Project/Trip Name</th><th>Description</th><th>First Spend</th><th>Last Spend</th><th>Total Spend</th></tr></thead><tbody>";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $project_link = "ledger.php?project_id=" . urlencode((string)$row['id']);

    if (!empty($row['start_date']) && !empty($row['end_date'])) {
        $project_link .= "&start=" . urlencode((string)$row['start_date']);
        $project_link .= "&end=" . urlencode((string)$row['end_date']);
    }

    if ($account_query !== '') {
        $project_link .= "&" . $account_query;
    }

    $firstSpend = (!empty($row['start_date']))
        ? (new DateTime((string)$row['start_date']))->format('M y')
        : '—';

    $lastSpend = (!empty($row['end_date']))
        ? (new DateTime((string)$row['end_date']))->format('M y')
        : '—';

    echo "<tr>";
    echo "<td><a href='" . h($project_link) . "'>" . h((string)$row['name']) . "</a></td>";
    echo "<td>" . h((string)$row['description']) . "</td>";
    echo "<td>" . h($firstSpend) . "</td>";
    echo "<td>" . h($lastSpend) . "</td>";
    echo "<td>£" . number_format((float)$row['total_amount'], 2) . "</td>";
    echo "</tr>";
}

echo "</tbody></table>";

include '../layout/footer.php';
?>
