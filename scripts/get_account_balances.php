<?php

function get_account_balances(PDO $db) {
    $stmt = $db->query("SELECT * FROM account_balances_as_of_last_night ORDER BY last_transaction desc, (case when account_type = 'credit' then (balance_as_of_last_night * -1) else balance_as_of_last_night end) desc");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
