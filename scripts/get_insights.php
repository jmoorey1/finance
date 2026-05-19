<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/insights_service.php';

$pdo = get_db_connection();

return build_budget_headlines($pdo);
