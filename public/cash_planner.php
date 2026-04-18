<?php
require_once '../config/db.php';
require_once '../scripts/cash_planner.php';
include '../layout/header.php';

function cpm_money($value): string
{
    return '£' . number_format((float)$value, 2);
}

function cpm_money_class($value): string
{
    return ((float)$value < 0) ? 'text-danger' : 'text-success';
}

$accounts = cp_get_active_accounts($pdo, ['current', 'savings', 'credit']);
if (empty($accounts)) {
    echo "<div class='alert alert-warning'>No active current, savings, or credit accounts were found.</div>";
    include '../layout/footer.php';
    exit;
}

$defaultAccountId = cp_get_default_cash_planner_account_id($pdo) ?? (int)$accounts[0]['id'];
$selectedAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : $defaultAccountId;
$validIds = array_map(fn($a) => (int)$a['id'], $accounts);
if (!in_array($selectedAccountId, $validIds, true)) {
    $selectedAccountId = $defaultAccountId;
}

$days = isset($_GET['days']) ? (int)$_GET['days'] : 90;
if (!in_array($days, [30, 60, 90, 180], true)) {
    $days = 90;
}

$today = new DateTimeImmutable('today');
$startDate = $today->modify('+1 day')->format('Y-m-d');
$endDate = $today->modify('+' . $days . ' days')->format('Y-m-d');

$stream = cp_get_account_event_stream($pdo, $selectedAccountId, $startDate, $endDate);
$currentBalance = se_get_account_balance_as_of($pdo, $selectedAccountId, $today->format('Y-m-d'));

$minBalance = $currentBalance;
$minBalanceDate = $today->format('Y-m-d');
foreach ($stream['events'] as $event) {
    if ((float)$event['balance_after'] < $minBalance) {
        $minBalance = (float)$event['balance_after'];
        $minBalanceDate = $event['event_date'];
    }
}
?>

<h1 class="mb-4">💧 Cash Planner</h1>

<div class="alert alert-info">
    This is the BKL-028/BKL-030 canonical account-dated cash event view.
    It shows <strong>actual account cash events, dated predicted account events, and flexible planned income events</strong>.
</div>

<div class="alert alert-warning">
    Budget-only future items are <strong>not</strong> shown here unless they also exist as dated account-level planned events.
    Flexible planned income events are shown here at their <strong>assumed date</strong> inside the configured window.
</div>

<form method="get" class="mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Account</label>
            <select name="account_id" class="form-select">
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $selectedAccountId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Horizon</label>
            <select name="days" class="form-select">
                <?php foreach ([30, 60, 90, 180] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $days === $opt ? 'selected' : '' ?>>
                        Next <?= $opt ?> days
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <button type="submit" class="btn btn-primary">Refresh View</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Current Balance</div>
                <div class="fw-bold"><?= cpm_money($currentBalance) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-<?= $minBalance < 0 ? 'danger' : 'secondary' ?>">
            <div class="card-body">
                <div class="text-muted small">Lowest Projected Balance</div>
                <div class="fw-bold <?= cpm_money_class($minBalance) ?>"><?= cpm_money($minBalance) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($minBalanceDate) ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Event Count</div>
                <div class="fw-bold"><?= count($stream['events']) ?></div>
            </div>
        </div>
    </div>
</div>

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
            <?php if (empty($stream['events'])): ?>
                <tr>
                    <td colspan="6" class="text-muted">No account-dated cash events in the selected horizon.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($stream['events'] as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars($event['event_date']) ?></td>
                        <td><?= htmlspecialchars($event['source_label']) ?></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $event['event_type'])) ?></td>
                        <td><?= htmlspecialchars($event['description']) ?></td>
                        <td class="text-end <?= cpm_money_class($event['amount']) ?>">
                            <?= cpm_money($event['amount']) ?>
                        </td>
                        <td class="text-end <?= cpm_money_class($event['balance_after']) ?>">
                            <?= cpm_money($event['balance_after']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
