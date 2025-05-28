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

		$update = $pdo->prepare("UPDATE transactions SET payee_id = 72 WHERE payee_id IS NULL AND predicted_transaction_id = 23");
		$update->execute();
		$count = $update->rowCount();
        if ($count > 0) {
            echo "Updated {$count} transactions for payee 'N.F. Collins'\n";
            $totalUpdated += $count;
        }
		$update = $pdo->prepare("UPDATE transactions SET payee_id = 71 WHERE payee_id IS NULL AND predicted_transaction_id = 20");
		$update->execute();
		$count = $update->rowCount();
        if ($count > 0) {
            echo "Updated {$count} transactions for payee 'Integra Networks'\n";
            $totalUpdated += $count;
        }

    echo "\nDone. Total transactions updated: {$totalUpdated}\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
