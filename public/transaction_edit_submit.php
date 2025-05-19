<?php
require_once '../config/db.php';
$conn = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    die('Invalid request.');
}

$id = (int)$_POST['id'];

// Begin transaction
$conn->beginTransaction();

try {
    // Prepare core transaction update
    $stmt = $conn->prepare("
        UPDATE transactions SET
            date = ?,
            amount = ?,
            description = ?,
            account_id = ?,
            category_id = ?,
            payee_id = ?,
            reconciled = ?,
            statement_id = ?,
            project_id = ?,
            earmark_id = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $_POST['date'],
        $_POST['amount'],
        $_POST['description'],
        $_POST['account_id'],
        $_POST['category_id'],
        $_POST['payee_id'] !== '' ? $_POST['payee_id'] : null,
        isset($_POST['reconciled']) ? 1 : 0,
        $_POST['statement_id'] !== '' ? $_POST['statement_id'] : null,
        $_POST['project_id'] !== '' ? $_POST['project_id'] : null,
        $_POST['earmark_id'] !== '' ? $_POST['earmark_id'] : null,
        $id
    ]);

    // Handle splits
    if ((int)$_POST['category_id'] === 197) {
        // Clear old splits
        $conn->prepare("DELETE FROM transaction_splits WHERE transaction_id = ?")->execute([$id]);

        $splitCats = $_POST['split_categories'] ?? [];
        $splitAmounts = $_POST['split_amounts'] ?? [];

        if (count($splitCats) !== count($splitAmounts)) {
            throw new Exception("Mismatch in split category and amount counts.");
        }

        $total = 0;
        for ($i = 0; $i < count($splitCats); $i++) {
            $catId = (int)$splitCats[$i];
            $amt = round((float)$splitAmounts[$i], 2);
            $conn->prepare("INSERT INTO transaction_splits (transaction_id, category_id, amount) VALUES (?, ?, ?)")
                ->execute([$id, $catId, $amt]);
            $total += $amt;
        }

        // Validate total
        $expected = round((float)$_POST['amount'], 2);
        if (abs($total - $expected) > 0.01) {
            throw new Exception("Split total (".$total.") does not match transaction amount (".$expected.").");
        }
    } else {
        // Remove splits if no longer a split
        $conn->prepare("DELETE FROM transaction_splits WHERE transaction_id = ?")->execute([$id]);
    }

    $conn->commit();
    header("Location: transaction_edit.php?id=" . $id . "&success=1");
    exit;
} catch (Exception $e) {
    $conn->rollBack();
    echo "<p>Error updating transaction: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='transaction_edit.php?id=$id'>Go Back</a></p>";
}
?>
