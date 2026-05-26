<?php
require_once '../config/db.php';
require_once '../scripts/lib/job_expense_report_builder.php';
require_once '../scripts/lib/job_expense_report_delivery.php';

$input = [
    'preset' => $_REQUEST['preset'] ?? '12m',
    'from' => $_REQUEST['from'] ?? '',
    'to' => $_REQUEST['to'] ?? '',
    'person' => $_REQUEST['person'] ?? 'both',
];

$error = null;
$success = null;

try {
    $report = jer_build_report($pdo, $input);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $report = jer_build_report($pdo, [
        'preset' => '12m',
        'person' => $input['person'] ?? 'both',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'send_email') {
    try {
        $recipients = jer_default_recipients();
        jer_send_email($pdo, $report, $recipients);
        $success = 'Report emailed to: ' . implode(', ', $recipients);
    } catch (Throwable $e) {
        $error = 'Failed to send email: ' . $e->getMessage();
    }
}

include '../layout/header.php';
?>

<h1 class="mb-4">💼 Job Expense Report</h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?= jer_h($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= jer_h($error) ?></div>
<?php endif; ?>

<div class="mb-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label for="person" class="form-label">Person</label>
            <select class="form-select" id="person" name="person">
                <option value="both" <?= (($report['selection']['key'] ?? '') === 'both') ? 'selected' : '' ?>>India and John</option>
                <option value="india" <?= (($report['selection']['key'] ?? '') === 'india') ? 'selected' : '' ?>>India only</option>
                <option value="john" <?= (($report['selection']['key'] ?? '') === 'john') ? 'selected' : '' ?>>John only</option>
            </select>
        </div>
        <div class="col-md-3">
            <label for="preset" class="form-label">Range preset</label>
            <select class="form-select" id="preset" name="preset">
                <option value="12m" <?= (($report['range']['preset'] ?? '') === '12m') ? 'selected' : '' ?>>Last 12 months</option>
                <option value="fy" <?= (($report['range']['preset'] ?? '') === 'fy') ? 'selected' : '' ?>>Current financial year</option>
                <option value="all" <?= (($report['range']['preset'] ?? '') === 'all') ? 'selected' : '' ?>>All time</option>
                <option value="custom" <?= (($report['range']['preset'] ?? '') === 'custom') ? 'selected' : '' ?>>Custom</option>
            </select>
        </div>
        <div class="col-md-2">
            <label for="from" class="form-label">From</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= jer_h((string)($_GET['from'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
            <label for="to" class="form-label">To</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= jer_h((string)($_GET['to'] ?? '')) ?>">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Run Report</button>
            <a href="job_expense_report.php?preset=12m&person=both" class="btn btn-outline-secondary">Reset Filters</a>
        </div>
    </form>
    <div class="text-muted small mt-2">
        <?= jer_h((string)$report['selection']['label']) ?> — <?= jer_h((string)$report['range']['label']) ?>
    </div>
</div>

<div class="mb-4">
    <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
        <?php if (function_exists('csrf_input')): ?>
            <?= csrf_input() ?>
        <?php endif; ?>
        <input type="hidden" name="action" value="send_email">
        <input type="hidden" name="person" value="<?= jer_h((string)$report['selection']['key']) ?>">
        <input type="hidden" name="preset" value="<?= jer_h((string)$report['range']['preset']) ?>">
        <input type="hidden" name="from" value="<?= jer_h((string)(($_GET['from'] ?? ''))) ?>">
        <input type="hidden" name="to" value="<?= jer_h((string)(($_GET['to'] ?? ''))) ?>">
        <button type="submit" class="btn btn-outline-primary">Send Email</button>
        <span class="text-muted small">
            Sends the currently displayed report to: <?= jer_h(implode(', ', jer_default_recipients())) ?>
        </span>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small"><?= jer_h((string)$report['summary_label']) ?> outgoings</div>
                <div class="fw-bold"><?= jer_money((float)$report['combined']['total_outgoing']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small"><?= jer_h((string)$report['summary_label']) ?> incoming offsets</div>
                <div class="fw-bold"><?= jer_money((float)$report['combined']['total_incoming']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-<?= ((float)$report['combined']['net_position'] < 0) ? 'warning' : 'success' ?>">
            <div class="card-body">
                <div class="text-muted small"><?= jer_h((string)$report['summary_label']) ?> net position</div>
                <div class="fw-bold <?= ((float)$report['combined']['net_position'] < 0) ? 'text-warning' : 'text-success' ?>">
                    <?= jer_money((float)$report['combined']['net_position']) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small"><?= jer_h((string)$report['summary_label']) ?> transaction count</div>
                <div class="fw-bold"><?= (int)$report['combined']['transaction_count'] ?></div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($report['sections'] as $section): ?>
    <div class="mb-5">
        <h3><?= jer_h((string)$section['label']) ?></h3>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Outgoings</div>
                        <div class="fw-bold"><?= jer_money((float)$section['total_outgoing']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Incoming offsets</div>
                        <div class="fw-bold"><?= jer_money((float)$section['total_incoming']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-<?= ((float)$section['net_position'] < 0) ? 'warning' : 'success' ?>">
                    <div class="card-body">
                        <div class="text-muted small">Net position</div>
                        <div class="fw-bold <?= ((float)$section['net_position'] < 0) ? 'text-warning' : 'text-success' ?>">
                            <?= jer_money((float)$section['net_position']) ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Transaction count</div>
                        <div class="fw-bold"><?= (int)$section['transaction_count'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Account</th>
                        <th>Amount</th>
                        <th>Running Net</th>
                        <th>Source</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($section['rows'])): ?>
                        <?php foreach ($section['rows'] as $row): ?>
                            <tr>
                                <td><?= jer_h((string)$row['line_date']) ?></td>
                                <td><?= jer_h((string)$row['description']) ?></td>
                                <td><?= jer_h((string)$row['account_name']) ?></td>
                                <td><?= jer_money((float)$row['amount']) ?></td>
                                <td><?= jer_money((float)$row['running_net']) ?></td>
                                <td><?= jer_h((string)$row['source']) ?></td>
                                <td>
                                    <?php if (!empty($row['transaction_id'])): ?>
                                        <a href="transaction_edit.php?id=<?= (int)$row['transaction_id'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" title="Edit Transaction">✏️</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-muted">No transactions in range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php include '../layout/footer.php'; ?>
