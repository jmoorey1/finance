<?php require_once('../config/db.php'); ?>
<!DOCTYPE html>
<html>
<head><title>Finance System</title></head>
<body>
  <h1>Finance Import Dashboard</h1>
  <ul>
    <?php
    $stmt = $pdo->query("SELECT status, COUNT(*) AS count FROM staging_transactions GROUP BY status");
    foreach ($stmt as $row) {
        echo "<li>{$row['status']}: {$row['count']}</li>";
    }
    ?>
  </ul>
  <p><a href="upload.php">Upload OFX file</a></p>
  <p><a href="review.php">Review & Categorize Transactions</a></p>
</body>
</html>
