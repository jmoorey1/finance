<?php require_once('../config/db.php'); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Home Finance System</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        h1 { color: #333; }
        ul, li { font-size: 1rem; line-height: 1.6; }
        a { text-decoration: none; color: #0057a3; }
        a:hover { text-decoration: underline; }
        .section { margin-bottom: 2em; }
    </style>
</head>
<body>

    <h1>Home Finance System</h1>

    <div class="section">
        <h2>⚙️ Transaction Processing</h2>
        <ul>
            <li><a href="upload.php">Upload Bank File (OFX/CSV)</a></li>
            <li><a href="review.php">Review & Categorize New Transactions</a></li>
        </ul>
        <ul>
        <?php
        $stmt = $pdo->query("SELECT status, COUNT(*) AS count FROM staging_transactions GROUP BY status");
        foreach ($stmt as $row) {
            echo "<li><strong>{$row['status']}</strong>: {$row['count']} transaction(s)</li>";
        }
        ?>
        </ul>
    </div>

    <div class="section">
        <h2>📊 Budgeting</h2>
        <ul>
            <li><a href="budgets.php">Edit Annual Budget</a></li>
        </ul>
    </div>

    <div class="section">
        <h2>📈 Reporting</h2>
        <ul>
            <li><a href="dashboard.php">Monthly Budget vs Actual</a></li>
            <li><a href="dashboard_ytd.php">Year-To-Date Dashboard</a></li>
        </ul>
    </div>

</body>
</html>
