<?php
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $institution = trim($_POST['institution']);
    $statement_day = !empty($_POST['statement_day']) ? (int)$_POST['statement_day'] : null;
    $payment_day = !empty($_POST['payment_day']) ? (int)$_POST['payment_day'] : null;
    $starting_balance = !empty($_POST['starting_balance']) ? (int)$_POST['starting_balance'] : 0;
    $active = isset($_POST['active']) ? 1 : 0;

    try {
        $pdo->beginTransaction();

        // Update the account
        $stmt = $pdo->prepare("
            UPDATE accounts
            SET name = ?, type = ?, institution = ?, statement_day = ?, payment_day = ?, starting_balance = ?, active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $institution, $statement_day, $payment_day, $starting_balance, $active, $id]);

        // Update the linked transfer category names
        $transferToName = "Transfer To : " . $name;
        $transferFromName = "Transfer From : " . $name;

        $stmt = $pdo->prepare("
            UPDATE categories
            SET name = ?
            WHERE linked_account_id = ? AND parent_id = 275 AND type = 'transfer' AND name LIKE 'Transfer To :%'
        ");
        $stmt->execute([$transferToName, $id]);

        $stmt = $pdo->prepare("
            UPDATE categories
            SET name = ?
            WHERE linked_account_id = ? AND parent_id = 275 AND type = 'transfer' AND name LIKE 'Transfer From :%'
        ");
        $stmt->execute([$transferFromName, $id]);

        $pdo->commit();

        header("Location: accounts.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>Error updating account: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<div class='alert alert-danger'>Invalid request.</div>";
}
