<?php
require_once '/var/www/html/finance/config/db.php';

try {
    $pdo = get_db_connection();

    // Fetch all match patterns with their corresponding payee_id
    $stmt = $pdo->query("
        SELECT pp.payee_id, pp.match_pattern, p.name
        FROM payee_patterns pp
        JOIN payees p ON pp.payee_id = p.id
    ");
    $patterns = $stmt->fetchAll();

    $totalUpdated = 0;

    foreach ($patterns as $pattern) {
        $payeeId = $pattern['payee_id'];
        $matchPattern = $pattern['match_pattern'];
        $payeeName = $pattern['name'];

        $update = $pdo->prepare("
            UPDATE transactions
            SET payee_id = :payee_id
            WHERE payee_id IS NULL AND description LIKE :pattern
        ");

        $update->execute([
            ':payee_id' => $payeeId,
            ':pattern' => $matchPattern
        ]);

        $count = $update->rowCount();
        if ($count > 0) {
            echo "Updated {$count} transactions for payee '{$payeeName}' using pattern '{$matchPattern}'\n";
            $totalUpdated += $count;
        }
    }

    echo "\nDone. Total transactions updated: {$totalUpdated}\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
