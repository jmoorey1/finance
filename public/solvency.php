<?php
require_once '../config/db.php';
require_once '../scripts/solvency_engine.php';
include '../layout/header.php';

function money_class($value): string
{
    return ((float)$value < 0) ? 'text-danger' : 'text-success';
}

function money_fmt($value): string
{
    return '£' . number_format((float)$value, 2);
}

$savingsStmt = $pdo->query("
    SELECT id, name
    FROM accounts
    WHERE active = 1
      AND type = 'savings'
    ORDER BY name
");
$savingsAccounts = $savingsStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($savingsAccounts)) {
    echo "<div class='alert alert-warning'>No active savings accounts found. The solvency reserve engine needs at least one active savings account.</div>";
    include '../layout/footer.php';
    exit;
}

$selectedAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : (int)$savingsAccounts[0]['id'];
$validIds = array_map(fn($a) => (int)$a['id'], $savingsAccounts);
if (!in_array($selectedAccountId, $validIds, true)) {
    $selectedAccountId = (int)$savingsAccounts[0]['id'];
}

$selectedAccountName = null;
foreach ($savingsAccounts as $a) {
    if ((int)$a['id'] === $selectedAccountId) {
        $selectedAccountName = $a['name'];
        break;
    }
}

$timeline = se_build_reserve_timeline($pdo, $selectedAccountId);
?>

<h1 class="mb-4">🛡️ Solvency Reserve Timeline</h1>

<div class="alert alert-info">
    This is the new reserve engine introduced in BKL-025. It is an interim diagnostic view before <code>project_fund.php</code> is rebuilt in BKL-026.
</div>

<form method="get" class="mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Reserve Account</label>
            <select name="account_id" class="form-select">
                <?php foreach ($savingsAccounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $selectedAccountId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary">Refresh View</button>
        </div>
    </div>
</form>

<div class="mb-4">
    <h5>Assumptions</h5>
    <ul>
        <li>Budget net is the baseline monthly plan, including irregular income that has already been entered into budgets.</li>
        <li>Past months use actual household net. The current month uses actuals to date plus remaining budget for the month.</li>
        <li>Future months use budget net plus manual one-off prediction adjustments where <code>predicted_transaction_id IS NULL</code>.</li>
        <li>Earmarks are deducted from available reserve capacity.</li>
    </ul>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Reserve Account</div>
                <div class="fw-bold"><?= htmlspecialchars($selectedAccountName ?? 'Unknown') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Current Reserve Balance</div>
                <div class="fw-bold"><?= money_fmt($timeline['current_reserve_balance']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total Earmarks</div>
                <div class="fw-bold"><?= money_fmt($timeline['earmarks_total']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Required Reserve From Now</div>
                <div class="fw-bold"><?= money_fmt($timeline['current_required_reserve']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-<?= ((float)$timeline['current_available_above_reserve'] < 0) ? 'danger' : 'success' ?>">
            <div class="card-body">
                <div class="text-muted small">Available Above Reserve Now</div>
                <div class="fw-bold <?= money_class($timeline['current_available_above_reserve']) ?>">
                    <?= money_fmt($timeline['current_available_above_reserve']) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Peak Required Reserve This Year</div>
                <div class="fw-bold"><?= money_fmt($timeline['peak_required_reserve']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-<?= ((float)$timeline['lowest_available_above_reserve'] < 0) ? 'danger' : 'secondary' ?>">
            <div class="card-body">
                <div class="text-muted small">Lowest Available Above Reserve</div>
                <div class="fw-bold <?= money_class($timeline['lowest_available_above_reserve']) ?>">
                    <?= money_fmt($timeline['lowest_available_above_reserve']) ?>
                </div>
                <div class="small text-muted">
                    <?= htmlspecialchars($timeline['lowest_available_month'] ?? '—') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Month</th>
                <th>Phase</th>
                <th class="text-end">Budget Net</th>
                <th class="text-end">Actual (Past / To Date)</th>
                <th class="text-end">Manual One-offs</th>
                <th class="text-end">Planning Net</th>
                <th class="text-end">Reserve Balance at Point</th>
                <th class="text-end">Required Reserve From Here</th>
                <th class="text-end">Available Above Reserve</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($timeline['rows'] as $row): ?>
                <?php
                    $phaseLabel = match ($row['phase']) {
                        'past' => 'Actual',
                        'current' => 'Current',
                        'future' => 'Future',
                        default => ucfirst($row['phase']),
                    };

                    $actualShown = $row['phase'] === 'past'
                        ? $row['actual_full_month_net']
                        : ($row['phase'] === 'current' ? $row['actual_to_date_net'] : null);

                    $planningClass = ((float)($row['planning_net'] ?? 0) < 0) ? 'text-danger' : 'text-success';
                    $availClass = $row['available_above_reserve'] === null ? '' : money_class($row['available_above_reserve']);
                ?>
                <tr class="<?= $row['phase'] === 'current' ? 'table-warning' : '' ?>">
                    <td><?= htmlspecialchars($row['label']) ?></td>
                    <td><?= htmlspecialchars($phaseLabel) ?></td>
                    <td class="text-end"><?= money_fmt($row['budget_net']) ?></td>
                    <td class="text-end">
                        <?= $actualShown === null ? '—' : money_fmt($actualShown) ?>
                    </td>
                    <td class="text-end">
                        <?= money_fmt($row['manual_adjustment_net']) ?>
                        <?php if (!empty($row['manual_items'])): ?>
                            <div class="small text-muted text-start mt-1">
                                <?php foreach ($row['manual_items'] as $item): ?>
                                    <div><?= htmlspecialchars($item['date']) ?> — <?= htmlspecialchars($item['description']) ?> (<?= money_fmt($item['amount']) ?>)</div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end <?= $planningClass ?>">
                        <?= $row['planning_net'] === null ? '—' : money_fmt($row['planning_net']) ?>
                    </td>
                    <td class="text-end">
                        <?= $row['reference_balance'] === null ? '—' : money_fmt($row['reference_balance']) ?>
                    </td>
                    <td class="text-end">
                        <?= $row['required_reserve_from_here'] === null ? '—' : money_fmt($row['required_reserve_from_here']) ?>
                    </td>
                    <td class="text-end <?= $availClass ?>">
                        <?= $row['available_above_reserve'] === null ? '—' : money_fmt($row['available_above_reserve']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
