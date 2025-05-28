<?php
require_once '../config/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    $stmt = $pdo->prepare("DELETE FROM predicted_instances WHERE id = ?");
    $stmt->execute([$id]);

    $_SESSION['prediction_deleted'] = true;
}

header('Location: index.php');
exit;
