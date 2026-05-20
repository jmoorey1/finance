<?php
require_once '../config/db.php';
require_once '../scripts/lib/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

auth_logout();
header('Location: /finance/public/login.php');
exit;
