<?php

function get_account_balances(PDO $db) {
    $stmt = $db->query("SELECT * FROM account_balances_as_of_last_night ORDER BY account_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
