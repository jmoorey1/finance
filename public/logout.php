<?php
require_once '../config/db.php';
require_once '../scripts/lib/auth.php';

auth_logout();
header('Location: /finance/public/login.php');
exit;
