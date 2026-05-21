<?php
require_once '../config/db.php';
require_once '../scripts/solvency_engine.php';
require_once '../scripts/solvency_timing_engine.php';
include '../layout/header.php';

function money_class($value): string
{
    return ((float)$value < 0) ? 'text-danger' : 'text-success';
}

function money_fmt($value): string
{
    return '£' . number_format((float)$value, 2);
}

$savingsAccounts = se_get_savings_accounts($pdo);

if (empty($savingsAccounts)) {
    echo "<div class='alert alert-warning'>No active savings accounts found. The solvency reserve engine needs at least one active savings account.</div>";
    include '../layout/footer.php';
    exit;
}

$defaultReserveAccountId = se_get_default_reserve_account_id($pdo) ?? (int)$savingsAccounts[0]['id'];
$selectedAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : $defaultReserveAccountId;
$validIds = array_map(fn($a) => (int)$a['id'], $savingsAccounts);
if (!in_array($selectedAccountId, $validIds, true)) {
    $selectedAccountId = $defaultReserveAccountId;
}

$selectedAccountName = null;
foreach ($savingsAccounts as $a) {
    if ((int)$a['id'] === $selectedAccountId) {
        $selectedAccountName = $a['name'];
        break;
    }
}

$timeline = se_build_reserve_timeline($pdo, $selectedAccountId);
$timingOverlay = sti_build_timing_overlay($pdo, $selectedAccountId, 45);
?>

<h1 class="mb-4">🧭 Monthly Funding Diagnostic</h1>

<div class="alert alert-secondary">
    This page is now a <strong>secondary long-range diagnostic</strong>.
    For the actual question “do I need to move money soon?”, use
    <a href="funding_health.php">Funding Health</a>.
</div>

<div class="alert alert-info">
    <strong>Soft earmarks are informational only.</strong>
    They are tracked for context but do not reduce transferable cash in the primary funding view.
</div>

<div class="alert alert-info">
    This is the reserve engine used to calculate how much of the main savings balance must remain protected for future household solvency.
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
		<li>Manual one-off predicted items can be marked as <strong>additional</strong> or <strong>budget-backed</strong>.</li>
		<li>Budget-backed one-offs offset budget already carried in the chosen financial month, so they are not double counted in solvency.</li>
		<li>Flexible planned income events are treated as <strong>budget-backed timing adjustments</strong>: the budgeted income is released from the explicit <code>budget_month_start</code> month and moved into the month containing the assumed landing date.</li>
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

<div class="mt-4 mb-4">
    <h5>Within-month Timing Overlay (Next <?= (int)$timingOverlay['window_days'] ?> Days)</h5>

    <div class="alert alert-secondary">
        This overlay uses dated <strong>current-account</strong> cash events — including flexible planned income assumptions —
        to show timing pressure that the month-level reserve table cannot. A flexible income can matter here even when it causes
        no change to the monthly reserve row because it still lands late within the same financial month.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-<?= $timingOverlay['issue_count'] > 0 ? 'warning' : 'success' ?>">
                <div class="card-body">
                    <div class="text-muted small">Current-account timing risks</div>
                    <div class="fw-bold"><?= (int)$timingOverlay['issue_count'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Top-up needed inside window</div>
                    <div class="fw-bold"><?= money_fmt($timingOverlay['total_top_up']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-<?= ((float)$timingOverlay['total_breach'] > 0) ? 'danger' : 'secondary' ?>">
                <div class="card-body">
                    <div class="text-muted small">Reserve breach inside window</div>
                    <div class="fw-bold <?= money_class(-1 * (float)$timingOverlay['total_breach']) ?>">
                        <?= money_fmt($timingOverlay['total_breach']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($timingOverlay['flexible_income_events'])): ?>
        <h6>Flexible Planned Income Assumptions</h6>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th>Receiving Account</th>
                        <th class="text-end">Amount</th>
                        <th>Budget Month</th>
                        <th>Assumed Date</th>
                        <th>Timing Strategy</th>
                        <th>Window</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timingOverlay['flexible_income_events'] as $event): ?>
                        <tr>
                            <td><?= htmlspecialchars($event['description']) ?></td>
                            <td><?= htmlspecialchars($event['account_name']) ?></td>
                            <td class="text-end"><?= money_fmt($event['amount']) ?></td>
                            <td><?= htmlspecialchars($event['budget_month_start'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($event['assumed_date']) ?></td>
                            <td>
                                <?= htmlspecialchars(pie_timing_label((string)$event['timing_strategy'])) ?>
                                <?php if (!empty($event['month_shift'])): ?>
                                    <span class="badge bg-warning text-dark ms-1">Month shift</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($event['window_start']) ?> → <?= htmlspecialchars($event['window_end']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($timingOverlay['issues'])): ?>
        <h6>Current-account Timing Risks</h6>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Account</th>
                        <th>Shortfall Starts</th>
                        <th>Worst Day</th>
                        <th class="text-end">Top-up Needed</th>
                        <th class="text-end">Safe From Reserve</th>
                        <th class="text-end">Breach</th>
                        <th>Likely Support Events</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timingOverlay['issues'] as $issue): ?>
                        <tr class="<?= ((float)$issue['breach_amount'] > 0) ? 'table-danger' : 'table-warning' ?>">
                            <td><?= htmlspecialchars($issue['account_name']) ?></td>
                            <td><?= htmlspecialchars($issue['start_day']) ?></td>
                            <td><?= htmlspecialchars($issue['min_day']) ?> (<?= money_fmt($issue['min_balance']) ?>)</td>
                            <td class="text-end"><?= money_fmt($issue['top_up']) ?></td>
                            <td class="text-end"><?= money_fmt($issue['safe_from_reserve']) ?></td>
                            <td class="text-end <?= money_class(-1 * (float)$issue['breach_amount']) ?>">
                                <?= money_fmt($issue['breach_amount']) ?>
                            </td>
                            <td>
                                <?php if (!empty($issue['support_events'])): ?>
                                    <div class="small">
                                        <?php foreach ($issue['support_events'] as $event): ?>
                                            <div>
                                                <?= htmlspecialchars($event['event_date']) ?> —
                                                <?= htmlspecialchars($event['description']) ?>
                                                (<?= money_fmt($event['amount']) ?>, <?= htmlspecialchars($event['source_label']) ?>)
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No positive events identified inside the deficit window.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="text-muted">No current-account timing shortfalls detected in the next <?= (int)$timingOverlay['window_days'] ?> days.</p>
    <?php endif; ?>
</div>

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Month</th>
                <th>Phase</th>
                <th class="text-end">Budget Net</th>
                <th class="text-end">Actual (Past / To Date)</th>
                <th class="text-end">Manual Adjustments</th>
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
