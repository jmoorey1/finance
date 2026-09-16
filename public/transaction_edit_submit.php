<?php
require_once '../config/db.php';
require_once '../scripts/lib/split_transaction_helpers.php';
require_once '../scripts/lib/prediction_transaction_links.php';
require_once '../scripts/run_predict_instances.php';
$conn = get_db_connection();

function safe_transaction_edit_redirect(?string $rawRedirect): string
{
    $default = 'ledger.php';
    $rawRedirect = trim((string)$rawRedirect);

    if ($rawRedirect === '' || str_contains($rawRedirect, "\r") || str_contains($rawRedirect, "\n") || str_contains($rawRedirect, "\0")) {
        return $default;
    }

    $parts = parse_url($rawRedirect);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $default;
    }

    $path = (string)($parts['path'] ?? '');
    if ($path === '') {
        return $default;
    }

    $allowedPages = [
        'ledger.php',
        'category_report.php',
        'subcategory_report.php',
        'job_expense_report.php',
        'predicted_rule_history.php',
    ];

    $page = basename($path);
    if (!in_array($page, $allowedPages, true)) {
        return $default;
    }

    $allowedPaths = array_map(
        fn(string $allowedPage): string => '/finance/public/' . $allowedPage,
        $allowedPages
    );

    if ($path !== $page && !in_array($path, $allowedPaths, true)) {
        return $default;
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    die('Invalid request.');
}

$id = (int)$_POST['id'];
$categoryRaw = trim((string)($_POST['category_id'] ?? ''));
$isSplitTransaction = finance_is_split_sentinel($categoryRaw);
$categoryId = $isSplitTransaction ? null : (int)$categoryRaw;
$predictionLinkRaw = trim((string)($_POST['predicted_transaction_id'] ?? ''));
$preservePredictionLinks = $predictionLinkRaw === '__preserve__';
$predictionRuleInputValid = $predictionLinkRaw === ''
    || $preservePredictionLinks
    || (ctype_digit($predictionLinkRaw) && (int)$predictionLinkRaw > 0);
$selectedPredictionRuleId = (!$preservePredictionLinks && $predictionLinkRaw !== '' && $predictionRuleInputValid)
    ? (int)$predictionLinkRaw
    : null;

$originalPredictionContext = ptl_load_transaction_context($conn, $id);
if (!$originalPredictionContext) {
    die('Transaction not found.');
}
$originalPredictionRuleIds = ptl_current_rule_ids($conn, $originalPredictionContext);
sort($originalPredictionRuleIds);
$predictionLinkChanged = false;

// Begin transaction
$conn->beginTransaction();

try {
    if (!$predictionRuleInputValid) {
        throw new Exception("Invalid prediction rule selection.");
    }

    if (!$isSplitTransaction && $categoryId <= 0) {
        throw new Exception("A valid category is required unless the transaction is split.");
    }

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
        $categoryId,
        $_POST['payee_id'] !== '' ? $_POST['payee_id'] : null,
        isset($_POST['reconciled']) ? 1 : 0,
        $_POST['statement_id'] !== '' ? $_POST['statement_id'] : null,
        $_POST['project_id'] !== '' ? $_POST['project_id'] : null,
        $_POST['earmark_id'] !== '' ? $_POST['earmark_id'] : null,
        $id
    ]);

    // Handle splits
    if ($isSplitTransaction) {
        // Clear old splits
        $conn->prepare("DELETE FROM transaction_splits WHERE transaction_id = ?")->execute([$id]);

        $splitCats = $_POST['split_categories'] ?? [];
        $splitAmounts = $_POST['split_amounts'] ?? [];
        $splitProjectIds = $_POST['split_project_ids'] ?? [];
        $splitEarmarkIds = $_POST['split_earmark_ids'] ?? [];

        $splitCount = count($splitCats);
        if (
            $splitCount !== count($splitAmounts) ||
            $splitCount !== count($splitProjectIds) ||
            $splitCount !== count($splitEarmarkIds)
        ) {
            throw new Exception("Mismatch in split field counts.");
        }

        $total = 0;
        for ($i = 0; $i < $splitCount; $i++) {
            $catId = (int)$splitCats[$i];
            $amt = round((float)$splitAmounts[$i], 2);
            $projectId = ($splitProjectIds[$i] ?? '') !== '' ? (int)$splitProjectIds[$i] : null;
            $earmarkId = ($splitEarmarkIds[$i] ?? '') !== '' ? (int)$splitEarmarkIds[$i] : null;

            $conn->prepare("
                INSERT INTO transaction_splits (transaction_id, category_id, project_id, fund_source_id, amount)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$id, $catId, $projectId, $earmarkId, $amt]);

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

    if (!$preservePredictionLinks) {
        $updatedPredictionContext = ptl_load_transaction_context($conn, $id);
        if (!$updatedPredictionContext) {
            throw new RuntimeException('Unable to reload transaction for prediction linking.');
        }

        $desiredPredictionRuleIds = $selectedPredictionRuleId !== null ? [$selectedPredictionRuleId] : [];
        sort($desiredPredictionRuleIds);

        if ($desiredPredictionRuleIds !== $originalPredictionRuleIds) {
            if ($selectedPredictionRuleId !== null) {
                ptl_assert_rule_compatible($conn, $updatedPredictionContext, $selectedPredictionRuleId);
            }
            ptl_apply_rule_link($conn, $updatedPredictionContext, $selectedPredictionRuleId);
            $predictionLinkChanged = true;
        }
    }

    $conn->commit();

    if ($predictionLinkChanged) {
        $job = run_predict_instances_job(true, 'transaction_prediction_link');
        if (($job['status'] ?? '') !== 'success') {
            app_log('Prediction reforecast failed after transaction prediction-link change: ' . ($job['message'] ?? 'unknown error'), 'ERROR');
        }
    }

    $redirect = safe_transaction_edit_redirect($_POST['redirect'] ?? null);
    header("Location: $redirect");
    exit;
} catch (Throwable $e) {
    $conn->rollBack();
    include '../layout/header.php';
    echo "<p>Error updating transaction: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='transaction_edit.php?id=$id'>Go Back</a></p>";
    include '../layout/footer.php';
}
?>
