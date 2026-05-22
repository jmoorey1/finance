<?php
require_once '../config/db.php';
require_once '../scripts/funding_health_engine.php';
include '../layout/header.php';

function fh_page_money($value): string
{
    return '£' . number_format((float)$value, 2);
}

function fh_page_money_class($value): string
{
    return ((float)$value < 0) ? 'text-danger' : 'text-success';
}

$windowDays = isset($_GET['days']) ? (int)$_GET['days'] : 31;
if (!in_array($windowDays, [14, 21, 31, 45, 60], true)) {
    $windowDays = 31;
}

$funding = fh_build_primary_funding_health($pdo, $windowDays);

$statusClass = 'alert-success';
if (($funding['status'] ?? '') === 'action') {
    $statusClass = 'alert-warning';
} elseif (($funding['status'] ?? '') === 'gap' || ($funding['status'] ?? '') === 'no_savings') {
    $statusClass = 'alert-danger';
}
?>

<h1 class="mb-4">💧 Funding Health</h1>

<div class="alert <?= $statusClass ?>">
    <strong><?= htmlspecialchars((string)$funding['headline']) ?></strong><br>
    <?= htmlspecialchars((string)$funding['summary']) ?>
</div>

<div class="alert alert-info">
    This is now the <strong>primary operational view</strong>.
    It answers one question: <strong>do I need to move money soon to keep current accounts funded?</strong>
    Starting balances are treated as <strong>cleared as of last night</strong>, and today's uncleared predicted / flexible-income items are included in the dated event stream.
</div>

<div class="alert alert-secondary">
    <strong>Soft earmarks are informational only.</strong>
    They are tracked and shown for context, but they do <strong>not</strong> reduce transferable cash in this view.
</div>

<form method="get" class="mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Action Window</label>
            <select name="days" class="form-select">
                <?php foreach ([14, 21, 31, 45, 60] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $windowDays === $opt ? 'selected' : '' ?>>Next <?= $opt ?> days</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary">Refresh View</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small"><?= htmlspecialchars((string)$funding['reserve_account_name']) ?> cleared balance as of last night</div>
                <div class="fw-bold"><?= fh_page_money($funding['current_balance']) ?></div>
                <div class="small text-muted">
                    Projected after today's uncleared items:
                    <?= fh_page_money((float)($funding['projected_balance_after_today_events'] ?? $funding['current_balance'])) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total required support</div>
                <div class="fw-bold"><?= fh_page_money($funding['total_required_support']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-<?= ((float)$funding['lowest_projected_balance'] < 0) ? 'danger' : 'secondary' ?>">
            <div class="card-body">
                <div class="text-muted small">Lowest projected savings balance</div>
                <div class="fw-bold <?= fh_page_money_class($funding['lowest_projected_balance']) ?>">
                    <?= fh_page_money($funding['lowest_projected_balance']) ?>
                </div>
                <div class="small text-muted"><?= htmlspecialchars((string)$funding['lowest_projected_balance_date']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-<?= ((float)$funding['total_funding_gap'] > 0) ? 'danger' : 'success' ?>">
            <div class="card-body">
                <div class="text-muted small">Actual funding gap</div>
                <div class="fw-bold <?= ((float)$funding['total_funding_gap'] > 0) ? 'text-danger' : 'text-success' ?>">
                    <?= fh_page_money($funding['total_funding_gap']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h4>Required Support Transfers</h4>
    <?php if (empty($funding['issues'])): ?>
        <p class="text-muted">No current-account support transfers are needed in this window.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Account</th>
                        <th>Transfer By</th>
                        <th>Worst Day</th>
                        <th class="text-end">Needed</th>
                        <th class="text-end">Fundable From Savings</th>
                        <th class="text-end">Gap</th>
                        <th class="text-end">Savings Before</th>
                        <th class="text-end">Savings After</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($funding['issues'] as $issue): ?>
                        <tr class="<?= ((float)$issue['funding_gap'] > 0) ? 'table-danger' : 'table-warning' ?>">
                            <td><?= htmlspecialchars((string)$issue['account_name']) ?></td>
                            <td><?= htmlspecialchars((string)$issue['start_day']) ?></td>
                            <td><?= htmlspecialchars((string)$issue['min_day']) ?> (<?= fh_page_money((float)$issue['min_balance']) ?>)</td>
                            <td class="text-end"><?= fh_page_money((float)$issue['top_up']) ?></td>
                            <td class="text-end"><?= fh_page_money((float)$issue['fundable_from_savings']) ?></td>
                            <td class="text-end <?= ((float)$issue['funding_gap'] > 0) ? 'text-danger' : '' ?>">
                                <?= fh_page_money((float)$issue['funding_gap']) ?>
                            </td>
                            <td class="text-end"><?= fh_page_money((float)$issue['savings_balance_before_support']) ?></td>
                            <td class="text-end"><?= fh_page_money((float)$issue['savings_balance_after_support']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="mb-4">
    <h4><?= htmlspecialchars((string)$funding['reserve_account_name']) ?> Dated Event Stream</h4>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Source</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Balance After</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($funding['events'])): ?>
                    <tr>
                        <td colspan="6" class="text-muted">No dated events in the selected funding window.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($funding['events'] as $event): ?>
                        <tr class="<?= (($event['event_type'] ?? '') === 'required_support_transfer') ? 'table-warning' : '' ?>">
                            <td><?= htmlspecialchars((string)$event['event_date']) ?></td>
                            <td><?= htmlspecialchars((string)$event['source_label']) ?></td>
                            <td><?= htmlspecialchars(str_replace('_', ' ', (string)$event['event_type'])) ?></td>
                            <td><?= htmlspecialchars((string)$event['description']) ?></td>
                            <td class="text-end <?= fh_page_money_class((float)$event['amount']) ?>">
                                <?= fh_page_money((float)$event['amount']) ?>
                            </td>
                            <td class="text-end <?= fh_page_money_class((float)$event['balance_after']) ?>">
                                <?= fh_page_money((float)$event['balance_after']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="small text-muted">
    Soft earmarks tracked: <?= fh_page_money((float)$funding['soft_earmarks_total']) ?> (shown for context only)
</div>

<?php include '../layout/footer.php'; ?>
