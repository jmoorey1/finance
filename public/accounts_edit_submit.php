<?php
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<div class='alert alert-danger'>Invalid request.</div>";
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$type = $_POST['type'] ?? '';
$institution = trim($_POST['institution'] ?? '');

$statement_day = (isset($_POST['statement_day']) && $_POST['statement_day'] !== '') ? (int)$_POST['statement_day'] : null;
$payment_day   = (isset($_POST['payment_day']) && $_POST['payment_day'] !== '') ? (int)$_POST['payment_day'] : null;

$starting_balance = (isset($_POST['starting_balance']) && $_POST['starting_balance'] !== '') ? (float)$_POST['starting_balance'] : 0.0;
$active = isset($_POST['active']) ? 1 : 0;

// Credit card settings (safe defaults)
$paid_from = (isset($_POST['paid_from']) && $_POST['paid_from'] !== '') ? (int)$_POST['paid_from'] : null;

$repayment_method = $_POST['repayment_method'] ?? 'full';
if (!in_array($repayment_method, ['full', 'minimum', 'fixed'], true)) {
    $repayment_method = 'full';
}

$fixed_payment_amount = (isset($_POST['fixed_payment_amount']) && $_POST['fixed_payment_amount'] !== '') ? (float)$_POST['fixed_payment_amount'] : null;
$min_payment_floor    = (isset($_POST['min_payment_floor']) && $_POST['min_payment_floor'] !== '') ? (float)$_POST['min_payment_floor'] : null;
$min_payment_percent  = (isset($_POST['min_payment_percent']) && $_POST['min_payment_percent'] !== '') ? (float)$_POST['min_payment_percent'] : null;

$min_payment_calc = $_POST['min_payment_calc'] ?? 'floor_or_percent';
if (!in_array($min_payment_calc, ['floor_or_percent', 'floor_or_percent_plus_interest'], true)) {
    $min_payment_calc = 'floor_or_percent';
}

$promo_apr       = (isset($_POST['promo_apr']) && $_POST['promo_apr'] !== '') ? (float)$_POST['promo_apr'] : null;
$promo_end_date  = (isset($_POST['promo_end_date']) && $_POST['promo_end_date'] !== '') ? $_POST['promo_end_date'] : null;
$standard_apr    = (isset($_POST['standard_apr']) && $_POST['standard_apr'] !== '') ? (float)$_POST['standard_apr'] : null;

if ($id <= 0 || $name === '' || $type === '') {
    echo "<div class='alert alert-danger'>Missing required fields.</div>";
    exit;
}

try {
    $pdo->beginTransaction();

    // Update the account
    $stmt = $pdo->prepare("
        UPDATE accounts
        SET
            name = ?,
            type = ?,
            institution = ?,
            statement_day = ?,
            payment_day = ?,
            paid_from = ?,
            starting_balance = ?,
            active = ?,
            repayment_method = ?,
            fixed_payment_amount = ?,
            min_payment_floor = ?,
            min_payment_percent = ?,
            min_payment_calc = ?,
            promo_apr = ?,
            promo_end_date = ?,
            standard_apr = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $name,
        $type,
        ($institution !== '' ? $institution : null),
        $statement_day,
        $payment_day,
        $paid_from,
        $starting_balance,
        $active,
        $repayment_method,
        $fixed_payment_amount,
        $min_payment_floor,
        $min_payment_percent,
        $min_payment_calc,
        $promo_apr,
        $promo_end_date,
        $standard_apr,
        $id
    ]);

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
