<?php
require_once '../config/db.php';
require_once '../scripts/forecast_utils.php';
include '../layout/header.php';

function getProjectedForMonth(string $start, string $end, PDO $pdo): float {
	$sql = "
		SELECT (ad.actual + fd.forecast) as projected
		FROM 
			(SELECT SUM(t.amount) AS actual
			 FROM transactions t
			 JOIN accounts a ON t.account_id = a.id
			 WHERE a.type IN ('current', 'credit', 'savings')
			   AND t.type != 'transfer'
			   AND t.date BETWEEN :actual_start AND :actual_end) ad,
			(SELECT SUM(pi.amount) AS forecast
			 FROM predicted_instances pi
			 JOIN accounts a ON pi.from_account_id = a.id
			 WHERE pi.to_account_id IS NULL
			   AND a.type IN ('current', 'credit', 'savings')
			   AND pi.scheduled_date BETWEEN :forecast_start AND :forecast_end) fd
	";

    $stmt = $pdo->prepare($sql);
	$stmt->execute([
		':actual_start' => $start,
		':actual_end' => $end,
		':forecast_start' => $start,
		':forecast_end' => $end,
	]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($row['projected'] ?? 0);
}

// Get earmarks
$earmarks = [];

$stmt = $pdo->query("
    SELECT em.remaining, e.name
    FROM (
        SELECT SUM(t.amount) AS remaining, t.earmark_id
        FROM transactions t
        WHERE t.earmark_id IS NOT NULL
        GROUP BY t.earmark_id
    ) em
    JOIN earmarks e ON e.id = em.earmark_id
    WHERE em.remaining > 0
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $earmarks[$row['name']] = floatval($row['remaining']);
}


// Config
$savings_account_id = 24;
$solvency_fund = 8500;
$year = 2025;
$months = [];
for ($i = 0; $i < 12; $i++) {
    $start = new DateTime("$year-01-13");
    $start->modify("+$i months");
    $end = (clone $start)->modify("+1 month")->modify("-1 day");
    $months[] = ['start' => $start, 'end' => $end];
}

// Current balance
$stmt = $pdo->prepare("SELECT balance_as_of_last_night FROM accounts.account_balances_as_of_last_night WHERE account_id = ?");
$stmt->execute([$savings_account_id]);
$current_balance = floatval($stmt->fetchColumn());

// Starting balance
$savings_start_date = new DateTime("$year-01-12");
$stmt = $pdo->prepare("
	select (select sum(amount) from transactions where account_id=? and date <= ?) + 
	       (select starting_balance from accounts where id=?) as balance
");
$stmt->execute([$savings_account_id, $savings_start_date->format('Y-m-d'), $savings_account_id]);
$savings_start_balance = floatval($stmt->fetchColumn());

// Budgets
$budgets = [];
$stmt = $pdo->prepare("
    SELECT month_start,
           SUM(CASE WHEN c.type = 'income' THEN b.amount ELSE 0 END) -
           SUM(CASE WHEN c.type = 'expense' THEN b.amount ELSE 0 END) AS net
    FROM budgets b
    JOIN categories c ON b.category_id = c.id
    WHERE b.month_start BETWEEN ? AND ?
    GROUP BY b.month_start
");
$stmt->execute([
    $months[0]['start']->format('Y-m-d'),
    end($months)['end']->format('Y-m-d')
]);
foreach ($stmt as $row) {
    $budgets[$row['month_start']] = floatval($row['net']);
}

// Forecast top ups

$forecast_issues = get_forecast_shortfalls($pdo);
$topups_by_month = [];
foreach ($forecast_issues as $issue) {
    $date = new DateTime($issue['start_day']);
    $month_end = ($date->format('d') >= 12)
        ? new DateTime($date->format('Y-m-12'))
        : (new DateTime($date->format('Y-m-01')))->modify('-1 day')->setDate(null, null, 12);
    $month_key = $month_end->modify("-1 month")->modify("+1 day")->format('Y-m-d');

    if (!isset($topups_by_month[$month_key])) {
        $topups_by_month[$month_key] = 0;
    }
    $topups_by_month[$month_key] += $issue['top_up'];
}

// Actuals and savings balances
$actuals = [];
$savings_balances = [];
foreach ($months as $m) {
    $start = $m['start']->format('Y-m-d');
    $end = $m['end']->format('Y-m-d');

    // Actuals
    $stmt = $pdo->prepare("
	SELECT SUM(total) as total FROM (
		SELECT IFNULL(top.id, c.id) AS top_id, SUM(s.amount) AS total
		FROM transaction_splits s
		JOIN transactions t ON t.id = s.transaction_id
		JOIN accounts a ON t.account_id = a.id
		JOIN categories c ON s.category_id = c.id
		LEFT JOIN categories top ON c.parent_id = top.id
		WHERE t.date BETWEEN ? AND ?
		  AND a.type IN ('current','credit','savings')
		  AND c.type IN ('income', 'expense')
		GROUP BY top_id

		UNION ALL

		SELECT IFNULL(top.id, c.id) AS top_id, SUM(t.amount) AS total
		FROM transactions t
		JOIN accounts a ON t.account_id = a.id
		JOIN categories c ON t.category_id = c.id
		LEFT JOIN categories top ON c.parent_id = top.id
		LEFT JOIN transaction_splits s ON s.transaction_id = t.id
		WHERE t.date BETWEEN ? AND ?
		  AND s.id IS NULL
		  AND a.type IN ('current','credit','savings')
		  AND c.type IN ('income', 'expense')
		GROUP BY top_id
	) actuals
    ");
    $stmt->execute([$start, $end, $start, $end]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $actuals[$m['start']->format('Y-m-d')] = $row && isset($row['total']) ? floatval($row['total']) : 0;

    // Savings
    $stmt = $pdo->prepare("
	select (select sum(amount) from transactions where account_id=? and date < ?) + 
	       (select starting_balance from accounts where id=?) as balance
    ");
    $stmt->execute([$savings_account_id, $end, $savings_account_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $savings_balances[$m['start']->format('Y-m-d')] = $row && isset($row['balance']) ? floatval($row['balance']) : 0;
}

// Build rows
$initial_project_fund = $savings_start_balance - array_sum($earmarks);
$solvency = 0;
$topup = 0;
$rows = [];
$today = new DateTime();

foreach ($months as $m) {
    $key = $m['start']->format('Y-m-d');
    $label = $m['start']->format('F') . ' – ' . $m['end']->format('F Y');
    $budget = $budgets[$key] ?? 0;

    if ($today > $m['end']) {
        $actual = $actuals[$key] ?? 0;
        $variance = $actual - $budget;
        $deficit = $actual;
        $running_deficit += $actual;
		$savings_balance = $savings_balances[$key] ?? 0;
    } else {
        $actual = 0;
        $variance = 0;
        $deficit = $budget;
        $running_deficit += $budget;
		$month_key = $m['start']->format('Y-m-d');
		$topup = $topups_by_month[$month_key] ?? 0;

		if ($topup > 0) {
			$savings_balance -= $topup;
		} else {
			$savings_balance += $deficit;
		}
    }
	$excess_solvency = 0;
    $running_solvency_fund = $solvency_fund + $running_deficit;
    if ($running_solvency_fund > $solvency_fund) {
		$excess_solvency = $running_solvency_fund - $solvency_fund;
        $running_solvency_fund = $solvency_fund;
    }

    $project = $savings_balance - array_sum($earmarks) - $running_solvency_fund;
    if ($m['start']->format('F') == 'December' && $running_deficit < 0) {
        $project += $running_deficit;
        $running_deficit = 0;
        $running_solvency_fund = $solvency_fund;
    }

    $rows[] = [
        'key' => $key,
        'label' => $label,
		'end' => $m['end']->format('F Y'),
        'budget' => $budget,
        'actual' => $actual,
        'variance' => $variance,
        'running_solvency_fund' => $running_solvency_fund,
        'deficit' => $deficit,
        'running_deficit' => $running_deficit,
        'project' => $project,
        'savings_balance' => $savings_balance,
        'topup' => $topup
    ];
}


$dec_project_fund = end($rows)['project'];
//Work backwards from December to find first month where project fund >= December's
$eligible_month = null;
for ($i = count($rows) - 1; $i >= 0; $i--) {
    if ($rows[$i]['project'] >= $dec_project_fund) {
        $eligible_month = $rows[$i]['end'];
    } else {
		break;
	}
}


?>

<h1 class="mb-4">🏗 Project Fund Forecast</h1>

<div class="mb-4">
    <h5>Current Savings Balance (Account #<?= $savings_account_id ?>): £<?= number_format($current_balance, 2) ?></h5>
    <ul>
        <?php foreach ($earmarks as $label => $amt): ?>
            <li><?= htmlspecialchars($label) ?>: £<?= number_format($amt, 2) ?></li>
        <?php endforeach; ?>
        <li><strong>Total Earmarked: £<?= number_format(array_sum($earmarks), 2) ?></strong></li>
        <li><strong>Slush Fund Required For Solvency: £<?= number_format($solvency_fund, 2) ?></strong></li>
        <li><strong>Project Fund: £<?= number_format($project, 2) ?> available from <?= $eligible_month ?> onwards</strong></li>
    </ul>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-sm align-middle">
        <thead class="table-light">
            <tr>
                <th>Month</th>
                <th class="text-end">Budget Net</th>
                <th class="text-end">Actual</th>
                <th class="text-end">Variance</th>
                <th class="text-end">Running Deficit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $r['label'] ?></td>
                    <td class="text-end">£<?= number_format($r['budget'], 2) ?></td>
                    <td class="text-end">£<?= number_format($r['actual'], 2) ?></td>
                    <td class="text-end">£<?= number_format($r['variance'], 2) ?></td>
                    <td class="text-end">£<?= number_format($r['running_deficit'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
