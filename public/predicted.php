<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once 'prediction_rule_helpers.php';
include '../layout/header.php';

if (isset($_SESSION['prediction_rule_flash'])) {
    $flashMsg = htmlspecialchars($_SESSION['prediction_rule_flash']);
    echo "<div class='alert alert-success'>{$flashMsg}</div>";
    unset($_SESSION['prediction_rule_flash']);
}

if (isset($_SESSION['prediction_action_flash'])) {
    $flashMsg = htmlspecialchars($_SESSION['prediction_action_flash']);
    echo "<div class='alert alert-success'>{$flashMsg}</div>";
    unset($_SESSION['prediction_action_flash']);
}

$rulesStmt = $pdo->query("
    SELECT
        pt.*,
        c.name AS category_name,
        fa.name AS from_account_name,
        ta.name AS to_account_name
    FROM predicted_transactions pt
    LEFT JOIN categories c ON pt.category_id = c.id
    LEFT JOIN accounts fa ON pt.from_account_id = fa.id
    LEFT JOIN accounts ta ON pt.to_account_id = ta.id
    ORDER BY pt.active DESC, pt.id ASC
");
$rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

$todayObj = new DateTimeImmutable('today');
$today = $todayObj->format('Y-m-d');
$past = $todayObj->sub(new DateInterval('P30D'))->format('Y-m-d');
$future = $todayObj->add(new DateInterval('P90D'))->format('Y-m-d');

$stmt = $pdo->prepare("
    SELECT pi.*, c.name AS category, fa.name AS from_account, ta.name AS to_account
    FROM predicted_instances pi
    LEFT JOIN categories c ON pi.category_id = c.id
    LEFT JOIN accounts fa ON pi.from_account_id = fa.id
    LEFT JOIN accounts ta ON pi.to_account_id = ta.id
    WHERE pi.scheduled_date BETWEEN ? AND ?
    ORDER BY pi.scheduled_date ASC
");
$stmt->execute([$past, $future]);
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1 class="mb-4">🔁 Predicted Transactions & Instances</h1>

<div class="mb-3 d-flex gap-2">
    <a href="predicted_rule_edit.php" class="btn btn-primary">➕ New Rule</a>
</div>

<div class="mb-3 text-muted">
    Editing or deactivating a rule refreshes its future open instances and triggers a reforecast automatically.
</div>

<h4>Recurring Rules</h4>
<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Description</th>
                <th>From → To</th>
                <th>Category</th>
                <th>Schedule</th>
                <th>Amount Logic</th>
                <th>Active</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rules as $r): ?>
                <tr class="<?= !empty($r['active']) ? '' : 'table-secondary' ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
                    <td>
                        <?= htmlspecialchars($r['from_account_name'] ?? '?') ?>
                        →
                        <?= $r['to_account_name'] ? htmlspecialchars($r['to_account_name']) : '—' ?>
                    </td>
                    <td><?= htmlspecialchars($r['category_name'] ?? '?') ?></td>
                    <td><?= htmlspecialchars(prediction_rule_format_schedule($r)) ?></td>
                    <td><?= htmlspecialchars(prediction_rule_format_variable_label($r)) ?></td>
                    <td><?= !empty($r['active']) ? '✅' : '—' ?></td>
                    <td class="d-flex gap-1 flex-wrap">
                        <a href="predicted_rule_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">✏️ Edit</a>

                        <form method="post" action="predicted_rule_toggle.php" class="d-inline">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="<?= !empty($r['active']) ? 'deactivate' : 'activate' ?>">
                            <button type="submit" class="btn btn-sm <?= !empty($r['active']) ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                <?= !empty($r['active']) ? '⏸ Deactivate' : '▶️ Activate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h4 class="mt-5">Instances (Last 30 Days + Next 90 Days)</h4>
<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>From → To</th>
                <th>Category</th>
                <th class="text-end">Amount</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($instances as $i): ?>
                <?php
                    $fulfilled = (int)($i['fulfilled'] ?? 0);
                    $resolution = $i['resolution_status'] ?? 'open';
                    $date = $i['scheduled_date'] ?? '';
                    $amount = (float)($i['amount'] ?? 0);

                    $rowClass = '';
                    $statusLabel = 'Planned';

                    if ($fulfilled === 1) {
                        $rowClass = 'table-success';
                        $statusLabel = '✅ Fulfilled';
                    } elseif ($fulfilled === 2) {
                        $rowClass = 'table-warning';
                        $statusLabel = '🌓 Partial';
                    } elseif ($resolution === 'skipped') {
                        $rowClass = 'table-secondary';
                        $statusLabel = '⏭️ Skipped';
                    } else {
                        if ($date !== '' && $date < $today) {
                            $rowClass = 'table-danger';
                            $statusLabel = '⚠️ Missed';
                        }
                    }
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= htmlspecialchars($date) ?></td>
                    <td><?= htmlspecialchars($i['description'] ?? '') ?></td>
                    <td><?= htmlspecialchars($i['from_account'] ?? '—') ?> → <?= htmlspecialchars($i['to_account'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($i['category'] ?? '') ?></td>
                    <td class="text-end">£<?= number_format($amount, 2) ?></td>
                    <td><?= $statusLabel ?></td>
                    <td>
                        <?php if ($fulfilled === 0): ?>
                            <?php if ($resolution === 'skipped'): ?>
                                <form method="post" action="prediction_action.php" class="d-inline">
                                    <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                    <input type="hidden" name="action" value="reopen">
                                    <input type="hidden" name="redirect" value="predicted.php">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">↩️ Reopen</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="prediction_action.php" class="d-inline">
                                    <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                    <input type="hidden" name="action" value="skip">
                                    <input type="hidden" name="redirect" value="predicted.php">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">⏭️ Skip</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
