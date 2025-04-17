<?php
require_once '../config/db.php';
require_once '../scripts/forecast_utils.php';
require_once '../scripts/get_upcoming_predictions.php';
require_once '../scripts/get_account_balances.php';
include '../layout/header.php';

$forecast = get_forecast_shortfalls($pdo);
$predictions = get_upcoming_predictions($pdo, 10);
$balances = get_account_balances($pdo);
?>

<h1 class="mb-4">Dashboard</h1>

<!-- 📅 Upcoming Predicted Transactions -->
<div class="mb-4">
    <h4>📅 Upcoming Transactions (Next 10 Days)</h4>
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

<!-- 💼 Account Balances -->
<div class="mb-4">
    <h4>💼 Account Balances (As of Last Night)</h4>
    <table class="table table-sm table-bordered">
        <thead class="table-light">
            <tr>
                <th>Account</th>
                <th>Type</th>
                <th class="text-end">Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($balances as $b): ?>
                <?php
                    $bal = (float) $b['balance_as_of_last_night'];
                    $is_negative = $b['account_type'] === 'current' && $bal < 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($b['account_name']) ?></td>
                    <td><?= $b['account_type'] ?></td>
                    <td class="text-end <?= $is_negative ? 'text-danger fw-bold' : '' ?>">
                        £<?= number_format($bal, 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 🔴 Forecasted Balance Issues -->
<?php if (count($forecast) > 0): ?>
    <div class="mb-4">
        <h4>⚠️ Forecasted Balance Issues</h4>
        <?php foreach ($forecast as $f): ?>
            <div class="forecast-panel">
                <h5>💸 <?= htmlspecialchars($f['account_name']) ?></h5>
                <p>Today's Balance: <strong>£<?= number_format($f['today_balance'], 2) ?></strong></p>
                <p>Projected to hit <strong>£<?= number_format($f['min_balance'], 2) ?></strong> on <?= $f['min_day'] ?></p>
                <p>👉 Recommended Top-Up: <strong>£<?= number_format($f['top_up'], 2) ?></strong></p>
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

<?php include '../layout/footer.php'; ?>
