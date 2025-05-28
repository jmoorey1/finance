<?php
session_start();

require_once '../config/db.php';
require_once '../scripts/forecast_utils.php';
require_once '../scripts/get_upcoming_predictions.php';
require_once '../scripts/get_account_balances.php';
require_once '../scripts/get_missed_predictions.php';
require_once '../scripts/get_missed_statements.php';
$headlines = require_once '../scripts/get_insights.php';


include '../layout/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reforecast'])) {
    $output = [];
    $return_code = 0;
    exec('python3 ../scripts/predict_instances.py 2>&1', $output, $return_code);
    if ($return_code === 0) {
        echo "<div class='alert alert-success'>✅ Reforecasting complete.</div>";
    } else {
        echo "<div class='alert alert-danger'><strong>Error running reforecast:</strong><br><pre>" .
             htmlspecialchars(implode("\n", $output)) . "</pre></div>";
    }
}

if (isset($_SESSION['prediction_deleted'])) {
    echo "<div class='alert alert-success'>✅ Prediction successfully deleted.</div>";
    unset($_SESSION['prediction_deleted']);
}

$balance_issues = get_forecast_shortfalls($pdo);
$predictions = get_upcoming_predictions($pdo, 5);
$balances = get_account_balances($pdo);
$missed = get_missed_predictions($pdo);
$missed_state = get_missed_statements($pdo);
?>

<h1 class="mb-4">Dashboard</h1>

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
                    <span>
                        <?= htmlspecialchars($m['statement_date']) ?>
                    </span>
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
                $highlight_class = 'forecast-panel'; // default red

                if ((new DateTime($f['start_day'])) < new DateTime()) {
                    // already started – keep red
                    $highlight_class = 'forecast-panel';
                } elseif ($days_until <= 3) {
                    $highlight_class = 'forecast-panel'; // red (default)
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




<!-- 🔄 Reforecast Button -->
<div class="mb-4">
    <form method="POST">
        <button type="submit" name="reforecast" class="btn btn-warning">🔄 Reforecast</button>
    </form>
</div>

<?php include '../layout/footer.php'; ?>
