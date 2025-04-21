<?php

function get_upcoming_predictions(PDO $db, $limit_days = 10) {
    $today = (new DateTimeImmutable())->format('Y-m-d');

    $stmt = $db->prepare("
        SELECT p.scheduled_date, p.amount, p.description,
               p.from_account_id, p.to_account_id,
               a1.name AS from_account, a2.name AS to_account,
               c.type AS category_type, c.name AS category_name
        FROM predicted_instances p
        LEFT JOIN accounts a1 ON p.from_account_id = a1.id
        LEFT JOIN accounts a2 ON p.to_account_id = a2.id
        INNER JOIN categories c ON p.category_id = c.id
        WHERE p.scheduled_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)
        ORDER BY p.scheduled_date ASC, p.amount DESC
    ");
    $stmt->execute([$today, $today, $limit_days]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as &$row) {
        $from = $row['from_account'] ?: 'N/A';
        $to = $row['to_account'] ?: '';
        $desc = $row['description'] ?: '[No Description]';
        $amt = number_format($row['amount'], 2);
		$cat_name = $row['category_name'] ?: '';
        $row['label'] = match ($row['category_type']) {
            'income', 'expense' => "£{$amt} – {$desc} – {$cat_name} ({$from})",
            'transfer' => "£{$amt} – {$desc} ({$from} → {$to})",
            default => "£{$amt} – {$desc}"
        };
    }

    return $results;
}
