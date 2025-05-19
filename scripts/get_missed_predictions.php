<?php
function get_missed_predictions(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT pi.scheduled_date, c.name AS category, pi.amount, pi.description, a.name as acc_name
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
		JOIN accounts a on pi.from_account_id = a.id
        WHERE pi.fulfilled = 0 AND pi.scheduled_date < CURDATE()
        ORDER BY pi.scheduled_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
