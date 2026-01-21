<?php
session_start();

require_once '../config/db.php';
require_once '../scripts/run_predict_instances.php';

// Handle manual reforecast BEFORE we include other scripts / output anything
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['reforecast']) || isset($_POST['force_reforecast']))) {

    $force = isset($_POST['force_reforecast']) && $_POST['force_reforecast'] === '1';
    $result = run_predict_instances_job($force, 'manual');

    // Flash message for next page load (PRG pattern)
    $_SESSION['flash'] = [
        'status' => $result['status'] ?? 'failed',
        'message' => $result['message'] ?? 'Unknown result.',
        'output_tail' => $result['output_tail'] ?? '',
    ];

    header("Location: index.php");
    exit;
}

require_once '../scripts/forecast_utils.php';
require_once '../scripts/get_upcoming_predictions.php';
require_once '../scripts/get_account_balances.php';
require_once '../scripts/get_missed_predictions.php';
require_once '../scripts/get_missed_statements.php';
$headlines = require_once '../scripts/get_insights.php';

include '../layout/header.php';

// Flash messages
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $st = $flash['status'] ?? 'failed';
    $msg = htmlspecialchars($flash['message'] ?? '');

    if ($st === 'success') {
        echo "<div class='alert alert-success'>{$msg}</div>";
    } elseif ($st === 'skipped') {
        echo "<div class='alert alert-warning'>{$msg}</div>";
    } elseif ($st === 'running') {
        echo "<div class='alert alert-info'>{$msg}</div>";
    } else {
        $tail = htmlspecialchars($flash['output_tail'] ?? '');
        echo "<div class='alert alert-danger'><strong>{$msg}</strong>";
        if ($tail !== '') {
            echo "<br><pre>{$tail}</pre>";
        }
        echo "</div>";
    }
}

if (isset($_SESSION['prediction_deleted'])) {
    echo "<div class='alert alert-success'>✅ Prediction successfully deleted.</div>";
    unset($_SESSION['prediction_deleted']);
}

// Auto-run forecast (throttled + locked)
$autoResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && feature_enabled('prediction_job_on_ui', true)) {
    $autoResult = run_predict_instances_job(false, 'auto');

    // Only shout if THIS auto-run actually ran and failed
    if (($autoResult['ran'] ?? false) && ($autoResult['status'] ?? '') === 'failed') {
        echo "<div class='alert alert-danger'><strong>Forecast engine failed (auto-run):</strong>";
        $tail = htmlspecialchars($autoResult['output_tail'] ?? '');
        if ($tail !== '') {
            echo "<br><pre>{$tail}</pre>";
        }
        echo "</div>";
    }
}

$balance_issues = get_forecast_shortfalls($pdo);
$predictions = get_upcoming_predictions($pdo, 5);
$balances = get_account_balances($pdo);
$missed = get_missed_predictions($pdo);
$missed_state = get_missed_statements($pdo);

// Job state for display
$jobState = get_predict_instances_state();

$lastRunLabel = 'Never';
if (($jobState['last_status'] ?? null) === 'running' && !empty($jobState['last_start'])) {
    try {
        $dt = new DateTime($jobState['last_start']);
        $lastRunLabel = $dt->format('d M Y H:i') . " (started)";
    } catch (Throwable $e) {
        $lastRunLabel = (string)$jobState['last_start'] . " (started)";
    }
} elseif (!empty($jobState['last_end'])) {
    try {
        $dt = new DateTime($jobState['last_end']);
        $lastRunLabel = $dt->format('d M Y H:i');
    } catch (Throwable $e) {
        $lastRunLabel = (string)$jobState['last_end'];
    }
}

// If the last run failed, show tail of last log for visibility even when throttled
$lastLogTail = '';
if (($jobState['last_status'] ?? null) === 'failed') {
    $lastLogTail = get_predict_instances_output_tail(120);
}
?>

<h1 class="mb-4">Dashboard</h1>

<!-- 🔧 Forecast Engine Status -->
<div class="mb-3">
    <div class="alert alert-light border mb-0">
        <strong>Forecast engine:</strong>
        Last run: <span class="badge bg-secondary"><?= htmlspecialchars($lastRunLabel) ?></span>
        Status:
        <?php
            $st = $jobState['last_status'] ?? null;
            $badge = 'bg-secondary';
            if ($st === 'success') $badge = 'bg-success';
            if ($st === 'failed') $badge = 'bg-danger';
            if ($st === 'running') $badge = 'bg-warning text-dark';
        ?>
        <span class="badge <?= $badge ?>"><?= htmlspecialchars($st ?? 'unknown') ?></span>
        <?php if (!empty($jobState['last_runtime_seconds'])): ?>
            <span class="text-muted ms-2">runtime: <?= (int)$jobState['last_runtime_seconds'] ?>s</span>
        <?php endif; ?>
        <?php if (!empty($jobState['last_trigger'])): ?>
            <span class="text-muted ms-2">trigger: <?= htmlspecialchars($jobState['last_trigger']) ?></span>
        <?php endif; ?>
    </div>

    <?php if (($jobState['last_status'] ?? null) === 'failed' && $lastLogTail !== ''): ?>
        <div class="mt-2">
            <details>
                <summary class="text-danger">Show last forecast log (tail)</summary>
                <pre class="mt-2"><?= htmlspecialchars($lastLogTail) ?></pre>
            </details>
        </div>
    <?php endif; ?>
</div>

<!-- 📊 Monthly Financial Insights -->
<div class="mb-4">
    <h4>📊 Monthly Insights (<?= $start_month->format('F') . "/" . $end_month->format('F Y')?>)</h4>
    <?php if (count($headlines) > 0): ?>
        <ul class="list-group">
            <?php foreach ($headlines as $line): ?>
                <li class="list-group-item"><?= nl2br(htmlspecialchars($line)) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">No headlines yet — keep spending and we'll have something to report!</p>
    <?php endif; ?>
</div>

<!-- 💼 Account Balances -->
<div class="mb-4">
    <h4>💼 Account Balances (As of Last Night)</h4>
    <table class="table table-striped table-sm align-middle"">
        <thead class="table-light">
            <tr>
                <th>Account</th>
                <th>Type</th>
                <th>Last Transaction</th>
                <th class="text-end">Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($balances as $b): ?>
                <?php
                    $bal = (float) $b['balance_as_of_last_night'];
                    $is_negative = $b['account_type'] === 'current' && $bal < 0;
                    $last_tx = new DateTime($b['last_transaction']);
                    $start_query = (clone $last_tx)->modify('-1 month');
                    $today = new DateTime();
                    $days_ago = $today->diff($last_tx)->days;
                ?>
                <tr>
                    <td><a href='ledger.php?accounts[]=<?= $b['account_id'] ?>&start=<?= $start_query->format('Y-m-d') ?>&end=<?= $today->format('Y-m-d') ?>'><?= htmlspecialchars($b['account_name']) ?></a></td>
                    <td><?= ucfirst($b['account_type']) ?></td>
                    <td><?= $last_tx->format('d M Y') ?> (<?= $days_ago ?> day<?= $days_ago !== 1 ? 's' : '' ?> ago)</td>
                    <td class="text-end <?= $is_negative ? 'text-danger fw-bold' : '' ?>">
                        £<?= number_format($bal, 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ⏳ Missed Predicted Transactions -->
<div class="mb-4">
    <h4>⏳ Missed Predicted Transactions</h4>
    <?php if (count($missed) > 0): ?>
        <ul class="list-group">
            <?php foreach ($missed as $m): ?>
                <?php
                    $scheduled = new DateTime($m['scheduled_date']);
                    $today = new DateTime();
                    $days_late = $today->diff($scheduled)->days;
                    $is_late = $today > $scheduled;
                ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <form method="post" action="delete_prediction.php" class="d-flex justify-content-between w-100">
                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <span>
                            <?= htmlspecialchars($m['scheduled_date']) ?>
                            <?php if ($is_late): ?>
                                (<?= $days_late ?> day<?= $days_late !== 1 ? 's' : '' ?> late)
                            <?php endif; ?>
                            – <?= htmlspecialchars($m['description'] ?? $m['category']) ?>
                            (<?= htmlspecialchars($m['acc_name']) ?>)
                        </span>
                        <span class="d-flex align-items-center">
                            £<?= number_format($m['amount'], 2) ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger ms-2" title="Delete Prediction">🗑️</button>
                        </span>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">No missed predicted transactions.</p>
    <?php endif; ?>
</div>

<!-- ⏳ Missed Statements -->
<div class="mb-4">
    <h4>🧾 Missed Statements</h4>
    <?php if (count($missed_state) > 0): ?>
        <ul class="list-group">
            <?php foreach ($missed_state as $m): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?= htmlspecialchars($m['statement_date']) ?></span>
                    <span><?= $m['account_name'] ?> - <?= $m['transaction_count'] ?> unreconciled transaction(s)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">No missed statements.</p>
    <?php endif; ?>
</div>

<!-- 📅 Upcoming Predicted Transactions -->
<div class="mb-4">
    <h4>📅 Upcoming Transactions (Next 5 Days)</h4>
    <?php if (count($predictions) > 0): ?>
        <ul class="list-group">
            <?php foreach ($predictions as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?= htmlspecialchars($p['scheduled_date']) ?></span>
                    <span><?= htmlspecialchars($p['label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">No upcoming predicted transactions.</p>
    <?php endif; ?>
</div>

<!-- 🔴 Forecasted Balance Issues -->
<?php if (count($balance_issues) > 0): ?>
    <div class="mb-4">
        <h4>💰 Upcoming Required Transfers</h4>
        <?php foreach ($balance_issues as $f): ?>
            <?php
                $days_until = (new DateTime($f['start_day']))->diff(new DateTime())->days;
                $highlight_class = 'forecast-panel';

                if ((new DateTime($f['start_day'])) < new DateTime()) {
                    $highlight_class = 'forecast-panel';
                } elseif ($days_until <= 3) {
                    $highlight_class = 'forecast-panel';
                } elseif ($days_until <= 7) {
                    $highlight_class = 'forecast-panel amber';
                } else {
                    $highlight_class = 'forecast-panel good';
                }
            ?>
            <div class="<?= $highlight_class ?>">
                <h5>💸 <?= htmlspecialchars($f['account_name']) ?></h5>
                <p>Today's Balance: <strong>£<?= number_format($f['today_balance'], 2) ?></strong></p>
                <p>Projected to hit <strong>£<?= number_format($f['min_balance'], 2) ?></strong> on <?= $f['min_day'] ?></p>
                <?php
                    $start_date = new DateTime($f['start_day']);
                    $today = new DateTime();
                    $diff_days = (int)$today->diff($start_date)->format('%r%a');
                    $weekday = $start_date->format('l');
                    $short_date = $start_date->format('D d M');

                    if ($diff_days === 0) {
                        $label = "today";
                    } elseif ($diff_days === 1) {
                        $label = "tomorrow";
                    } elseif ($diff_days < 7) {
                        $label = "this $weekday";
                    } elseif ($diff_days == 7) {
                        $label = "1 week tomorrow";
                    } elseif ($diff_days < 14) {
                        $label = "$weekday week";
                    } elseif (($diff_days + 1) % 7 === 0) {
                        $weeks = ($diff_days + 1 ) / 7;
                        $label = "$weeks week" . ($weeks > 1 ? "s" : "") . " today";
                    } elseif (($diff_days + 1) % 7 === 1) {
                        $weeks = ($diff_days ) / 7;
                        $label = "$weeks week" . ($weeks > 1 ? "s" : "") . " tomorrow";
                    } else {
                        $weeks = floor($diff_days / 7);
                        $label = "$weeks week" . ($weeks > 1 ? "s" : "") . " on $weekday";
                    }
                ?>

                <p>👉 Recommended Top-Up: <strong>£<?= number_format($f['top_up'], 2) ?></strong> by <?= $label ?> (<?= $short_date ?>)</p>
                <p>🔍 Window: <?= $f['start_day'] ?> ➞ <?= $f['min_day'] ?></p>
                <ul class="mb-0">
                    <?php foreach ($f['events'] as $e): ?>
                        <?php $sign = ($e['amount'] > 0 ? "+" : ""); ?>
                        <li><?= $e['date'] ?>:
                            £<?= $sign . number_format($e['amount'], 2) ?>
                            → £<?= number_format($e['balance'], 2) ?>
                            – <?= htmlspecialchars($e['desc']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="forecast-panel good">
        <h5>✅ You're in good shape!</h5>
        <p>No projected shortfalls in the next 31 days.</p>
    </div>
<?php endif; ?>

<!-- 🔄 Reforecast Buttons -->
<div class="mb-4">
    <form method="POST" class="d-flex gap-2">
        <button type="submit" name="reforecast" value="1" class="btn btn-warning">🔄 Reforecast</button>
        <button type="submit" name="force_reforecast" value="1" class="btn btn-outline-danger"
                onclick="return confirm('Force reforecast will run immediately even if a recent run exists. Continue?');">
            ⚠️ Force Reforecast
        </button>
    </form>
    <div class="text-muted small mt-2">
        Tip: normal Reforecast respects the min-interval throttle; Force bypasses throttle but still prevents concurrent runs.
    </div>
</div>

<?php include '../layout/footer.php'; ?>
