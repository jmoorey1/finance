<?php
require_once '../config/db.php';

$id = (int) ($_POST['id'] ?? 0);
$active = (int) ($_POST['active'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare("UPDATE predicted_transactions SET active = ? WHERE id = ?");
    $stmt->execute([$active, $id]);
}

header('Location: predicted.php');
exit;
