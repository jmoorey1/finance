<?php
function get_missed_statements(PDO $pdo): array {
    $stmt = $pdo->prepare("
		SELECT
		  a.name AS account_name,
		  -- Properly calculate the expected statement date as a DATE
		  STR_TO_DATE(
			CONCAT(
			  CASE
				WHEN DAY(t.date) < a.statement_day THEN
				  DATE_FORMAT(t.date, '%Y-%m-')
				ELSE
				  DATE_FORMAT(DATE_ADD(t.date, INTERVAL 1 MONTH), '%Y-%m-')
			  END,
			  LPAD(a.statement_day, 2, '0')
			),
			'%Y-%m-%d'
		  ) AS statement_date,
		  COUNT(*) AS transaction_count
		FROM transactions t
		JOIN accounts a ON t.account_id = a.id
		WHERE (t.reconciled = 0 OR t.reconciled IS NULL)
		  AND a.statement_day IS NOT NULL
		  AND STR_TO_DATE(
			CONCAT(
			  CASE
				WHEN DAY(t.date) < a.statement_day THEN
				  DATE_FORMAT(t.date, '%Y-%m-')
				ELSE
				  DATE_FORMAT(DATE_ADD(t.date, INTERVAL 1 MONTH), '%Y-%m-')
			  END,
			  LPAD(a.statement_day, 2, '0')
			),
			'%Y-%m-%d'
		  ) <= curdate()
		GROUP BY a.name, statement_date
		ORDER BY a.name, statement_date
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
