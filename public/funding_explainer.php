<?php
require_once '../config/db.php';
require_once '../scripts/lib/funding_explainer_builder.php';

$accounts = fe_get_explainer_accounts($pdo);
if (empty($accounts)) {
    include '../layout/header.php';
    echo "<div class='alert alert-danger'>No active current accounts were found.</div>";
    include '../layout/footer.php';
    exit;
}

$monthOptions = fe_get_month_options();
$selectedMonth = fe_resolve_month_option($monthOptions, $_GET['month_start'] ?? null);
$selectedAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : fe_get_default_account_id($pdo);

$report = fe_build_report($pdo, $selectedAccountId, (string)$selectedMonth['value']);

$statusClass = ((float)$report['required_support'] > 0) ? 'alert-danger' : 'alert-success';
$statusHeadline = ((float)$report['required_support'] > 0)
    ? 'This month goes negative without support.'
    : 'This month stays funded without support.';
$statusSummary = ((float)$report['required_support'] > 0)
    ? 'Lowest projected balance is ' . fe_money((float)$report['lowest_balance']) . ' on ' . fe_h((string)$report['lowest_balance_date']) . '.'
    : 'Lowest projected balance is ' . fe_money((float)$report['lowest_balance']) . ' on ' . fe_h((string)$report['lowest_balance_date']) . '.';

include '../layout/header.php';
?>

<h1 class="mb-4">🧭 Funding Explainer</h1>

<div class="alert <?= $statusClass ?>">
    <strong><?= $statusHeadline ?></strong><br>
    <?= $statusSummary ?>
</div>

<div class="alert alert-info">
    This page is the month-level explainer view. It answers:
    <strong>what is driving the pressure in this account for this selected financial month?</strong>
</div>

<form method="get" class="mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Account</label>
            <select name="account_id" class="form-select">
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= (int)$account['id'] ?>" <?= ((int)$account['id'] === (int)$report['account']['id']) ? 'selected' : '' ?>>
                        <?= fe_h((string)$account['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Financial Month</label>
            <select name="month_start" class="form-select">
                <?php foreach ($monthOptions as $option): ?>
                    <option value="<?= fe_h((string)$option['value']) ?>" <?= ((string)$option['value'] === $report['month']['start']->format('Y-m-d')) ? 'selected' : '' ?>>
                        <?= fe_h((string)$option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">Explain Month</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Opening balance</div>
                <div class="fw-bold"><?= fe_money((float)$report['opening_balance']) ?></div>
                <div class="small text-muted"><?= fe_h($report['month']['start']->format('j M Y')) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Net dated change</div>
                <div class="fw-bold"><?= fe_money((float)$report['totals']['all_in'] - (float)$report['totals']['all_out']) ?></div>
                <div class="small text-muted">
                    In <?= fe_money((float)$report['totals']['all_in']) ?> / Out <?= fe_money((float)$report['totals']['all_out']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-<?= ((float)$report['lowest_balance'] < 0) ? 'danger' : 'secondary' ?>">
            <div class="card-body">
                <div class="text-muted small">Lowest balance</div>
                <div class="fw-bold <?= ((float)$report['lowest_balance'] < 0) ? 'text-danger' : '' ?>">
                    <?= fe_money((float)$report['lowest_balance']) ?>
                </div>
                <div class="small text-muted"><?= fe_h((string)$report['lowest_balance_date']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-<?= ((float)$report['required_support'] > 0) ? 'danger' : 'success' ?>">
            <div class="card-body">
                <div class="text-muted small">Support needed to stay ≥ 0</div>
                <div class="fw-bold <?= ((float)$report['required_support'] > 0) ? 'text-danger' : 'text-success' ?>">
                    <?= fe_money((float)$report['required_support']) ?>
                </div>
                <div class="small text-muted">Closing balance <?= fe_money((float)$report['closing_balance']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h4>Why this month looks like this</h4>
    <ul class="list-group">
        <?php foreach ($report['summary_lines'] as $line): ?>
            <li class="list-group-item"><?= fe_h((string)$line) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <h4>Largest Outgoing Drivers</h4>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['top_outgoing'])): ?>
                        <tr><td colspan="4" class="text-muted">No outgoing items in this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report['top_outgoing'] as $row): ?>
                            <tr>
                                <td><?= fe_h((string)$row['event_date']) ?></td>
                                <td><?= fe_h((string)($row['source_label'] ?? $row['source'] ?? '')) ?></td>
                                <td><?= fe_h((string)$row['description']) ?></td>
                                <td class="text-end text-danger"><?= fe_money((float)$row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-6">
        <h4>Largest Incoming Offsets</h4>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['top_incoming'])): ?>
                        <tr><td colspan="4" class="text-muted">No incoming items in this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report['top_incoming'] as $row): ?>
                            <tr>
                                <td><?= fe_h((string)$row['event_date']) ?></td>
                                <td><?= fe_h((string)($row['source_label'] ?? $row['source'] ?? '')) ?></td>
                                <td><?= fe_h((string)$row['description']) ?></td>
                                <td class="text-end text-success"><?= fe_money((float)$row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mb-4">
    <h4>Full Dated Event Stream</h4>
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
                <?php if (empty($report['events'])): ?>
                    <tr>
                        <td colspan="6" class="text-muted">No dated events in this selected month.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report['events'] as $event): ?>
                        <tr>
                            <td><?= fe_h((string)$event['event_date']) ?></td>
                            <td><?= fe_h((string)($event['source_label'] ?? $event['source'] ?? '')) ?></td>
                            <td><?= fe_h(str_replace('_', ' ', (string)($event['event_type'] ?? ''))) ?></td>
                            <td><?= fe_h((string)$event['description']) ?></td>
                            <td class="text-end <?= ((float)$event['amount'] < 0) ? 'text-danger' : 'text-success' ?>">
                                <?= fe_money((float)$event['amount']) ?>
                            </td>
                            <td class="text-end <?= ((float)$event['balance_after'] < 0) ? 'text-danger' : '' ?>">
                                <?= fe_money((float)$event['balance_after']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../layout/footer.php'; ?>
