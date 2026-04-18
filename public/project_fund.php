<?php
require_once '../config/db.php';
require_once '../scripts/solvency_engine.php';
include '../layout/header.php';

function pf_money_fmt($value): string
{
    return '£' . number_format((float)$value, 2);
}

function pf_money_class($value): string
{
    return ((float)$value < 0) ? 'text-danger' : 'text-success';
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
    echo "<div class='alert alert-warning'>No active savings accounts found. Project fund capacity requires at least one active savings account.</div>";
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

$targetSpendRaw = trim((string)($_GET['target_spend'] ?? ''));
$targetSpend = null;
if ($targetSpendRaw !== '') {
    $clean = str_replace([',', '£', ' '], '', $targetSpendRaw);
    if (is_numeric($clean)) {
        $targetSpend = round((float)$clean, 2);
        if ($targetSpend <= 0) {
            $targetSpend = null;
        }
    }
}

$timeline = se_build_reserve_timeline($pdo, $selectedAccountId);
$currentProjectFund = (float)$timeline['current_available_above_reserve'];

$currentCanFund = null;
$earliestSafeRow = null;

if ($targetSpend !== null) {
    $currentCanFund = $currentProjectFund >= $targetSpend;

    foreach ($timeline['rows'] as $row) {
        if (!in_array($row['phase'], ['current', 'future'], true)) {
            continue;
        }
        if ($row['available_above_reserve'] !== null && (float)$row['available_above_reserve'] >= $targetSpend) {
            $earliestSafeRow = $row;
            break;
        }
    }
}
?>

<h1 class="mb-4">🏗 Project Fund Timeline</h1>

<div class="alert alert-info">
    This page is rebuilt in BKL-026 on top of the new solvency reserve engine.
    <br>
    <strong>Project Fund = Reserve Balance at Point – Earmarks – Required Reserve From Here</strong>
</div>

<div class="alert alert-warning">
    Manual one-off predicted items are treated as <strong>additional adjustments on top of budget</strong>.
    If the same item is also included in budget for that month, project capacity will be understated until one of the two is removed.
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

        <div class="col-md-4">
            <label class="form-label">Target Spend Scenario (£)</label>
            <input type="number" name="target_spend" step="0.01" min="0" class="form-control"
                   value="<?= htmlspecialchars($targetSpendRaw) ?>"
                   placeholder="e.g. 6500.00">
            <div class="form-text">Optional. Use this to see the earliest safe month for a major spend.</div>
        </div>

        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">Refresh View</button>
        </div>
    </div>
</form>

<?php if ($targetSpend !== null): ?>
    <?php if ($currentCanFund): ?>
        <div class="alert alert-success">
            A target spend of <strong><?= pf_money_fmt($targetSpend) ?></strong> is supportable <strong>now</strong>.
            Residual project fund after spending now would be
            <strong><?= pf_money_fmt($currentProjectFund - $targetSpend) ?></strong>.
        </div>
    <?php elseif ($earliestSafeRow): ?>
        <div class="alert alert-warning">
            A target spend of <strong><?= pf_money_fmt($targetSpend) ?></strong> is <strong>not</strong> supportable now.
            The earliest safe month in the current timeline is
            <strong><?= htmlspecialchars($earliestSafeRow['label']) ?></strong>,
            when projected project fund reaches
            <strong><?= pf_money_fmt($earliestSafeRow['available_above_reserve']) ?></strong>.
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            A target spend of <strong><?= pf_money_fmt($targetSpend) ?></strong> is not supportable anywhere in the current financial-year timeline.
        </div>
    <?php endif; ?>
<?php endif; ?>

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
                <div class="fw-bold"><?= pf_money_fmt($timeline['current_reserve_balance']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total Earmarks</div>
                <div class="fw-bold"><?= pf_money_fmt($timeline['earmarks_total']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-<?= $currentProjectFund < 0 ? 'danger' : 'success' ?>">
            <div class="card-body">
                <div class="text-muted small">Project Fund Now</div>
                <div class="fw-bold <?= pf_money_class($currentProjectFund) ?>">
                    <?= pf_money_fmt($currentProjectFund) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Required Reserve From Now</div>
                <div class="fw-bold"><?= pf_money_fmt($timeline['current_required_reserve']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Peak Required Reserve This Year</div>
                <div class="fw-bold"><?= pf_money_fmt($timeline['peak_required_reserve']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-<?= ((float)$timeline['lowest_available_above_reserve'] < 0) ? 'danger' : 'secondary' ?>">
            <div class="card-body">
                <div class="text-muted small">Lowest Project Fund This Year</div>
                <div class="fw-bold <?= pf_money_class($timeline['lowest_available_above_reserve']) ?>">
                    <?= pf_money_fmt($timeline['lowest_available_above_reserve']) ?>
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
                <th class="text-end">Reserve Balance at Point</th>
                <th class="text-end">Required Reserve From Here</th>
                <th class="text-end">Project Fund</th>
                <?php if ($targetSpend !== null): ?>
                    <th class="text-end">After Target Spend</th>
                    <th>Safe?</th>
                <?php endif; ?>
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

                    $projectFund = $row['available_above_reserve'];
                    $projectFundClass = $projectFund === null ? '' : pf_money_class($projectFund);

                    $rowClass = '';
                    if ($row['phase'] === 'current') {
                        $rowClass = 'table-warning';
                    }
                    if ($projectFund !== null && (float)$projectFund < 0) {
                        $rowClass = 'table-danger';
                    }

                    $afterSpend = null;
                    $safeForSpend = null;
                    if ($targetSpend !== null && $projectFund !== null) {
                        $afterSpend = (float)$projectFund - $targetSpend;
                        $safeForSpend = ((float)$projectFund >= $targetSpend);
                    }
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= htmlspecialchars($row['label']) ?></td>
                    <td><?= htmlspecialchars($phaseLabel) ?></td>
                    <td class="text-end"><?= pf_money_fmt($row['budget_net']) ?></td>
                    <td class="text-end">
                        <?= $actualShown === null ? '—' : pf_money_fmt($actualShown) ?>
                    </td>
                    <td class="text-end">
                        <?= pf_money_fmt($row['manual_adjustment_net']) ?>
                        <?php if (!empty($row['manual_items'])): ?>
                            <div class="small text-muted text-start mt-1">
                                <?php foreach ($row['manual_items'] as $item): ?>
                                    <div><?= htmlspecialchars($item['date']) ?> — <?= htmlspecialchars($item['description']) ?> (<?= pf_money_fmt($item['amount']) ?>)</div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= $row['reference_balance'] === null ? '—' : pf_money_fmt($row['reference_balance']) ?>
                    </td>
                    <td class="text-end">
                        <?= $row['required_reserve_from_here'] === null ? '—' : pf_money_fmt($row['required_reserve_from_here']) ?>
                    </td>
                    <td class="text-end <?= $projectFundClass ?>">
                        <?= $projectFund === null ? '—' : pf_money_fmt($projectFund) ?>
                    </td>
                    <?php if ($targetSpend !== null): ?>
                        <td class="text-end <?= $afterSpend !== null ? pf_money_class($afterSpend) : '' ?>">
                            <?= $afterSpend === null ? '—' : pf_money_fmt($afterSpend) ?>
                        </td>
                        <td>
                            <?= $safeForSpend === null ? '—' : ($safeForSpend ? '✅ Yes' : '❌ No') ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
