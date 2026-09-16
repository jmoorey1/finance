<?php
require_once '../config/db.php';
auth_session_start();
require_once 'prediction_rule_helpers.php';
require_once '../scripts/lib/prediction_transaction_links.php';

$ruleId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($ruleId <= 0) {
    include '../layout/header.php';
    echo '<div class="alert alert-danger">Invalid prediction rule ID.</div>';
    include '../layout/footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        pt.*,
        c.name AS category_name,
        fa.name AS from_account_name,
        ta.name AS to_account_name
    FROM predicted_transactions pt
    LEFT JOIN categories c ON c.id = pt.category_id
    LEFT JOIN accounts fa ON fa.id = pt.from_account_id
    LEFT JOIN accounts ta ON ta.id = pt.to_account_id
    WHERE pt.id = ?
    LIMIT 1
");
$stmt->execute([$ruleId]);
$rule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rule) {
    include '../layout/header.php';
    echo '<div class="alert alert-danger">Prediction rule not found.</div>';
    include '../layout/footer.php';
    exit;
}

$backUrl = ptl_safe_return_url($_GET['redirect'] ?? null, 'predicted.php');
$actualRows = ptl_load_rule_history_rows($pdo, $ruleId);
$actualOccurrences = ptl_group_rule_history_rows($actualRows, (string)$rule['prediction_type']);

$instanceStmt = $pdo->prepare("
    SELECT
        id,
        scheduled_date,
        amount,
        fulfilled,
        confirmed,
        resolution_status,
        fulfilled_by_transaction_id,
        fulfilled_by_transfer_group_id,
        fulfilled_at,
        resolved_at,
        resolution_note
    FROM predicted_instances
    WHERE predicted_transaction_id = ?
    ORDER BY scheduled_date DESC, id DESC
    LIMIT 100
");
$instanceStmt->execute([$ruleId]);
$instances = $instanceStmt->fetchAll(PDO::FETCH_ASSOC);

$currentHistoryUrl = 'predicted_rule_history.php?' . http_build_query([
    'id' => $ruleId,
    'redirect' => $backUrl,
]);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

include '../layout/header.php';
?>

<h1 class="mb-3">🔁 Prediction Rule History</h1>

<?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
    <div class="alert alert-success">
        ✅ Prediction rule created and the source transaction was linked to it.
    </div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap mb-4">
    <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline-secondary">← Back</a>
    <a href="predicted_rule_edit.php?id=<?= $ruleId ?>" class="btn btn-outline-primary">✏️ Edit Rule</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Rule</div>
                <div><strong>#<?= $ruleId ?> — <?= htmlspecialchars((string)$rule['description']) ?></strong></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">From → To</div>
                <div>
                    <?= htmlspecialchars((string)($rule['from_account_name'] ?? '—')) ?>
                    →
                    <?= htmlspecialchars((string)($rule['to_account_name'] ?? '—')) ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Category / Type</div>
                <div>
                    <?php if (($rule['prediction_type'] ?? '') === 'transfer'): ?>
                        Transfer
                    <?php else: ?>
                        <?= htmlspecialchars((string)($rule['category_name'] ?? '—')) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Schedule</div>
                <div><?= htmlspecialchars(prediction_rule_format_schedule($rule)) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Amount Logic</div>
                <div><?= htmlspecialchars(prediction_rule_format_variable_label($rule)) ?></div>
            </div>
            <div class="col-md-2">
                <div class="text-muted small">Active</div>
                <div><?= !empty($rule['active']) ? '✅ Yes' : '— No' ?></div>
            </div>
        </div>
    </div>
</div>

<h4>Linked Actual Transactions</h4>
<p class="text-muted">
    These are real transactions whose <code>predicted_transaction_id</code> points to this rule.
    Transfer legs are grouped into a single occurrence.
</p>

<div class="table-responsive mb-5">
    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Account / Transfer</th>
                <th>Category</th>
                <th class="text-end">Amount</th>
                <th>Reconciled</th>
                <th>Transaction ID(s)</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($actualOccurrences)): ?>
                <tr>
                    <td colspan="8" class="text-muted">No real transactions are linked to this rule yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($actualOccurrences as $occurrence): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars((string)$occurrence['date']) ?>
                            <?php if (($occurrence['date_end'] ?? $occurrence['date']) !== $occurrence['date']): ?>
                                <div class="small text-muted">to <?= htmlspecialchars((string)$occurrence['date_end']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)$occurrence['description']) ?></td>
                        <td><?= htmlspecialchars((string)$occurrence['account_label']) ?></td>
                        <td><?= htmlspecialchars((string)$occurrence['category_label']) ?></td>
                        <td class="text-end <?= (float)$occurrence['amount'] < 0 ? 'text-danger' : '' ?>">
                            £<?= number_format((float)$occurrence['amount'], 2) ?>
                        </td>
                        <td><?= !empty($occurrence['reconciled']) ? '✅' : '—' ?></td>
                        <td>
                            <?= htmlspecialchars(implode(', ', array_map('strval', $occurrence['transaction_ids']))) ?>
                            <?php if (!empty($occurrence['transfer_group_id'])): ?>
                                <div class="small text-muted">Group #<?= (int)$occurrence['transfer_group_id'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="transaction_edit.php?id=<?= (int)$occurrence['edit_transaction_id'] ?>&redirect=<?= urlencode($currentHistoryUrl) ?>"
                               title="Edit linked transaction">✏️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<h4>Prediction Instance Audit</h4>
<p class="text-muted">
    Recent generated occurrences for this rule, including fulfilled, skipped, missed and future planned instances.
</p>

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Scheduled Date</th>
                <th class="text-end">Amount</th>
                <th>Status</th>
                <th>Fulfilment</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($instances)): ?>
                <tr>
                    <td colspan="5" class="text-muted">No generated instances found for this rule.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($instances as $instance): ?>
                    <?php
                    $fulfilled = (int)($instance['fulfilled'] ?? 0);
                    $resolution = (string)($instance['resolution_status'] ?? 'open');

                    if ($fulfilled === 1) {
                        $status = '✅ Fulfilled';
                    } elseif ($fulfilled === 2) {
                        $status = '🌓 Partial';
                    } elseif ($resolution === 'skipped') {
                        $status = '⏭️ Skipped';
                    } elseif ((string)$instance['scheduled_date'] < $today) {
                        $status = '⚠️ Missed';
                    } else {
                        $status = 'Planned';
                    }

                    $fulfilment = '—';
                    if (!empty($instance['fulfilled_by_transaction_id'])) {
                        $fulfilment = 'Transaction #' . (int)$instance['fulfilled_by_transaction_id'];
                    } elseif (!empty($instance['fulfilled_by_transfer_group_id'])) {
                        $fulfilment = 'Transfer group #' . (int)$instance['fulfilled_by_transfer_group_id'];
                    }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$instance['scheduled_date']) ?></td>
                        <td class="text-end">£<?= number_format((float)$instance['amount'], 2) ?></td>
                        <td><?= $status ?></td>
                        <td><?= htmlspecialchars($fulfilment) ?></td>
                        <td><?= htmlspecialchars((string)($instance['resolution_note'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
