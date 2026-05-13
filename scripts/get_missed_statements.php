<?php
function get_missed_statements(PDO $pdo): array {
    $stmt = $pdo->prepare("
        WITH unreconciled_by_statement AS (
            SELECT
                t.account_id,
                a.name AS account_name,
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
                ) AS statement_date
            FROM transactions t
            JOIN accounts a ON t.account_id = a.id
            WHERE (t.reconciled = 0 OR t.reconciled IS NULL)
              AND a.statement_day IS NOT NULL
              AND a.active = 1
        )
        SELECT
            u.account_name,
            u.statement_date,
            COUNT(*) AS transaction_count
        FROM unreconciled_by_statement u
        WHERE u.statement_date < CURDATE()
          AND NOT EXISTS (
              SELECT 1
              FROM statements s
              WHERE s.account_id = u.account_id
                AND s.reconciled = 1
                AND s.statement_date >= u.statement_date
          )
        GROUP BY u.account_id, u.account_name, u.statement_date
        ORDER BY u.account_name, u.statement_date
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}