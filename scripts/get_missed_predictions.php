<?php
function get_missed_predictions(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT
            pi.id,
            pi.scheduled_date,
            c.name AS category,
            pi.amount,
            COALESCE(pay.name, pi.description) AS description,
            a.name AS acc_name
        FROM predicted_instances pi
        JOIN categories c ON pi.category_id = c.id
        JOIN accounts a ON pi.from_account_id = a.id
        LEFT JOIN payee_patterns pp ON pi.description LIKE pp.match_pattern
        LEFT JOIN payees pay ON pp.payee_id = pay.id
        WHERE COALESCE(pi.fulfilled, 0) = 0
          AND COALESCE(pi.resolution_status, 'open') = 'open'
          AND pi.scheduled_date < CURDATE()
        ORDER BY pi.scheduled_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
