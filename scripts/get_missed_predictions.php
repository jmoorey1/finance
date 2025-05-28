<?php
function get_missed_predictions(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT pi.id, pi.scheduled_date, c.name AS category, pi.amount, COALESCE(pay.name, pi.description) as description, a.name as acc_name
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
		JOIN accounts a on pi.from_account_id = a.id
		left join payee_patterns pp on pi.description like pp.match_pattern
		left join payees pay on pp.payee_id = pay.id
        WHERE pi.fulfilled = 0 AND pi.scheduled_date < CURDATE()
        ORDER BY pi.scheduled_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
