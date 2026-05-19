<?php
if (session_status() === PHP_SESSION_NONE) {
    auth_session_start();
}

require_once '../config/db.php';
require_once '../scripts/predicted_reconciliation.php';
include '../layout/header.php';

$predictionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$redirect = trim((string)($_GET['redirect'] ?? 'predicted.php'));

$allowedRedirects = ['index.php', 'predicted.php'];
$parsed = parse_url($redirect);
$redirectPath = basename($parsed['path'] ?? 'predicted.php');
$redirectQuery = isset($parsed['query']) ? ('?' . $parsed['query']) : '';
if (!in_array($redirectPath, $allowedRedirects, true)) {
    $redirectPath = 'predicted.php';
    $redirectQuery = '';
}
$redirect = $redirectPath . $redirectQuery;

$instance = $predictionId > 0 ? pr_load_predicted_instance($pdo, $predictionId) : null;

if (!$instance) {
    $_SESSION['prediction_action_flash'] = '⚠️ Predicted instance not found.';
    header('Location: ' . $redirect);
    exit;
}

if (!pr_validate_reconcilable($instance)) {
    $_SESSION['prediction_action_flash'] = '⚠️ This predicted instance is already fulfilled and cannot be reconciled again.';
    header('Location: ' . $redirect);
    exit;
}

$regularCandidates = [];
$transferGroupCandidates = [];
$fromTransferCandidates = [];
$toTransferCandidates = [];

if (pr_is_regular($instance)) {
    $regularCandidates = pr_find_regular_candidates($pdo, $instance);
} else {
    $transferGroupCandidates = pr_find_transfer_group_candidates($pdo, $instance);
    $fromTransferCandidates = pr_find_transfer_row_candidates($pdo, $instance, 'from');
    $toTransferCandidates = pr_find_transfer_row_candidates($pdo, $instance, 'to');
}
?>

<h1 class="mb-4">🔗 Reconcile Missed Prediction to Actual Ledger Entry</h1>

<div class="mb-3">
    <a href="<?= htmlspecialchars($redirect) ?>" class="btn btn-outline-secondary">← Back</a>
</div>

<div class="card mb-4">
    <div class="card-header">Predicted Instance</div>
    <div class="card-body">
        <div><strong>ID:</strong> <?= (int)$instance['id'] ?></div>
        <div><strong>Date:</strong> <?= htmlspecialchars($instance['scheduled_date']) ?></div>
        <div><strong>Description:</strong> <?= htmlspecialchars($instance['description'] ?? '') ?></div>
        <div><strong>Category:</strong> <?= htmlspecialchars($instance['category_name'] ?? '') ?> (<?= htmlspecialchars($instance['category_type'] ?? '') ?>)</div>
        <div><strong>From → To:</strong> <?= htmlspecialchars($instance['from_account_name'] ?? '—') ?> → <?= htmlspecialchars($instance['to_account_name'] ?? '—') ?></div>
        <div><strong>Amount:</strong> £<?= number_format((float)$instance['amount'], 2) ?></div>
    </div>
</div>

<?php if (pr_is_regular($instance)): ?>
    <h4>Candidate Ledger Transactions</h4>
    <p class="text-muted">Choose the real ledger row that should fulfil this missed prediction.</p>

    <?php if (empty($regularCandidates)): ?>
        <div class="alert alert-warning">No candidate transactions found within the matching window.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th class="text-end">Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($regularCandidates as $row): ?>
                        <tr class="<?= !empty($row['already_linked']) ? 'table-secondary' : '' ?>">
                            <td><?= (int)$row['id'] ?></td>
                            <td><?= htmlspecialchars($row['date']) ?></td>
                            <td><?= htmlspecialchars($row['account_name']) ?></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                            <td><?= htmlspecialchars($row['category_name'] ?? '—') ?></td>
                            <td class="text-end">£<?= number_format((float)$row['amount'], 2) ?></td>
                            <td>
                                <?php if (!empty($row['already_linked'])): ?>
                                    <span class="text-muted">Already linked elsewhere</span>
                                <?php else: ?>
                                    <form method="post" action="predicted_reconcile_action.php" class="d-inline">
                                        <input type="hidden" name="action" value="reconcile_regular">
                                        <input type="hidden" name="prediction_id" value="<?= (int)$instance['id'] ?>">
                                        <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">Match This Transaction</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <h4>Candidate Transfer Groups</h4>
    <p class="text-muted">Use an existing transfer group where available. If the two real rows are not grouped yet, use the pairing section below.</p>

    <?php if (empty($transferGroupCandidates)): ?>
        <div class="alert alert-warning">No matching transfer groups found within the matching window.</div>
    <?php else: ?>
        <?php foreach ($transferGroupCandidates as $group): ?>
            <div class="card mb-3 <?= !empty($group['already_linked']) ? 'border-secondary' : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Transfer Group #<?= (int)$group['transfer_group_id'] ?></span>
                    <?php if (!empty($group['already_linked'])): ?>
                        <span class="text-muted">Already linked elsewhere</span>
                    <?php else: ?>
                        <form method="post" action="predicted_reconcile_action.php" class="d-inline">
                            <input type="hidden" name="action" value="reconcile_transfer_group">
                            <input type="hidden" name="prediction_id" value="<?= (int)$instance['id'] ?>">
                            <input type="hidden" name="transfer_group_id" value="<?= (int)$group['transfer_group_id'] ?>">
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                            <button type="submit" class="btn btn-sm btn-primary">Match This Transfer Group</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php foreach ($group['rows'] as $row): ?>
                        <div>
                            <?= htmlspecialchars($row['date']) ?>
                            — <?= htmlspecialchars($row['account_name']) ?>
                            — £<?= number_format((float)$row['amount'], 2) ?>
                            — <?= htmlspecialchars($row['description']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h4 class="mt-4">Match Two Individual Transfer Rows</h4>

    <?php if (empty($fromTransferCandidates) || empty($toTransferCandidates)): ?>
        <div class="alert alert-warning">Could not find enough matching transfer rows to pair manually.</div>
    <?php else: ?>
        <form method="post" action="predicted_reconcile_action.php">
            <input type="hidden" name="action" value="reconcile_transfer_pair">
            <input type="hidden" name="prediction_id" value="<?= (int)$instance['id'] ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Outgoing Transfer Row (from <?= htmlspecialchars($instance['from_account_name']) ?>)</label>
                    <select name="from_transaction_id" class="form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($fromTransferCandidates as $row): ?>
                            <?php if (!empty($row['already_linked'])) continue; ?>
                            <option value="<?= (int)$row['id'] ?>">
                                #<?= (int)$row['id'] ?> | <?= htmlspecialchars($row['date']) ?> | £<?= number_format((float)$row['amount'], 2) ?> | <?= htmlspecialchars($row['description']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Incoming Transfer Row (to <?= htmlspecialchars($instance['to_account_name']) ?>)</label>
                    <select name="to_transaction_id" class="form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($toTransferCandidates as $row): ?>
                            <?php if (!empty($row['already_linked'])) continue; ?>
                            <option value="<?= (int)$row['id'] ?>">
                                #<?= (int)$row['id'] ?> | <?= htmlspecialchars($row['date']) ?> | £<?= number_format((float)$row['amount'], 2) ?> | <?= htmlspecialchars($row['description']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Match Selected Transfer Pair</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>
