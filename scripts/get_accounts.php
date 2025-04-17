<?php

function get_all_active_accounts(PDO $db) {
    $stmt = $db->query("SELECT id, name FROM accounts WHERE active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
