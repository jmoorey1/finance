<?php
require_once '../config/db.php';
include '../layout/header.php';

// Config
$savings_account_id = 24;
$earmarks = [
    'Teddy\'s Inheritance'     => 10000,
    'Grandad\'s Money for Travel' => 4812.91
];
$solvency_fund = 8500;
$year = 2025;
$months = [];
for ($i = 0; $i < 12; $i++) {
    $start = new DateTime("$year-01-13");
    $start->modify("+$i months");
    $end = (clone $start)->modify("+1 month")->modify("-1 day");
    $months[] = ['start' => $start, 'end' => $end];
}

// Get current savings balance
$stmt = $pdo->prepare("SELECT balance_as_of_last_night FROM accounts.account_balances_as_of_last_night WHERE account_id = ?");
$stmt->execute([$savings_account_id]);
$current_balance = floatval($stmt->fetchColumn());

// Get savings balance at the end of last month
// Calculate most recent 12th of the month
$today = new DateTime();
$savings_last_month = new DateTime($today->format('Y-m-12'));
if ($today->format('d') < 12) {
    $savings_last_month->modify('-1 month');
}

$stmt = $pdo->prepare("
select (select sum(amount) from transactions where account_id=? and date <= ?) + (select starting_balance from accounts where id=?) as balance
");
$stmt->execute([$savings_account_id, $savings_last_month->format('Y-m-d'), $savings_account_id]);
$savings_last_month_balance = floatval($stmt->fetchColumn());

// Get savings starting balance
$savings_start_date = new DateTime("$year-01-12");
$stmt = $pdo->prepare("
select (select sum(amount) from transactions where account_id=? and date <= ?) + (select starting_balance from accounts where id=?) as balance
");
$stmt->execute([$savings_account_id, $savings_start_date->format('Y-m-d'), $savings_account_id]);
$savings_start_balance = floatval($stmt->fetchColumn());

// Get net budget per month
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

$actuals = [];
$savings_balances = [];

foreach ($months as $m) {
    $start = $m['start']->format('Y-m-d');
    $end = $m['end']->format('Y-m-d');

    // Actuals
    $stmt = $pdo->prepare("
	SELECT sum(total) as total from 
	(SELECT IFNULL(top.id, c.id) AS top_id, SUM(s.amount) AS total
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
		GROUP BY top_id) actuals
    ");
    $stmt->execute([$start, $end, $start, $end]);
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$actuals[$m['start']->format('Y-m-d')] = $result && isset($result['total']) ? floatval($result['total']) : 0;
	
	// Savings balance
	$stmt = $pdo->prepare("
	select (select sum(amount) from transactions where account_id=? and date <= ?) + (select starting_balance from accounts where id=?) as balance
    ");
    $stmt->execute([$savings_account_id, $end, $savings_account_id]);
	$result = $stmt->fetch(PDO::FETCH_ASSOC);
	$savings_balances[$m['start']->format('Y-m-d')] = $result && isset($result['balance']) ? floatval($result['balance']) : 0;
	

}

// Determine totals
$initial_project_fund = $savings_start_balance - array_sum($earmarks);
$solvency = 0;
$rows = [];
$today = new DateTime();

foreach ($months as $m) {
    $key = $m['start']->format('Y-m-d');
    $label = $m['start']->format('F') . ' – ' . $m['end']->format('F Y');

    $budget = $budgets[$key] ?? 0;
    $savings_balance = $savings_balances[$key] ?? 0;

    // Use actuals and variance only for past months
    $actual = ($today > $m['end']) ? ($actuals[$key] ?? 0) : 0;
    $variance = ($today > $m['end']) ? ($actual - $budget) : 0;

    // Solvency builds from actuals (past) or budget (future)
	$deficit = ($today > $m['end']) ? $actual : $budget;
    $running_deficit += ($today > $m['end']) ? $actual : $budget;
	$running_solvency_fund = $solvency_fund + $running_deficit;
	if ($running_solvency_fund > $solvency_fund) {
		$excess_solvency = $running_solvency_fund - $solvency_fund;
		$running_solvency_fund = $solvency_fund;
		$project = $savings_balance - array_sum($earmarks) - $solvency_fund ;
	} else {
		$project = $savings_balance - array_sum($earmarks) - $running_solvency_fund;
	}
	if ($m['start']->format('F') == 'December' && $running_deficit < 0){
		$project += $running_deficit;
		$running_deficit = 0;
		$running_solvency_fund = $solvency_fund;
	}

    $rows[] = [
		'key' => $key,
        'label' => $label,
        'budget' => $budget,
        'actual' => $actual,
        'variance' => $variance,
        'running_solvency_fund' => $running_solvency_fund,
        'total_deficit' => $total_deficit,
        'total_surplus' => $total_surplus,
        'deficit' => $deficit,
		'running_deficit' => $running_deficit,
        'project' => $project,
		'savings_balance' => $savings_balance
    ];
}

$min_project_fund = min(array_column($rows, 'project'));
$min_project_key = null;

foreach ($rows as $r) {
    if ($r['project'] === $min_project_fund) {
        $min_project_key = $r['key'] ?? null; // Only works if you included 'key' in the $rows[]
        break;
    }
}
$min_project_key_dt = new DateTime($min_project_key);
$min_project_key_dt->modify("+1 month")->modify("-1 day");

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
        <li><strong>Project Fund Remaining: £<?= number_format($min_project_fund, 2) ?> available from <?= $min_project_key_dt->format('F Y') ?></strong></li>
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
                <th class="text-end">Deficit</th>
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
                    <td class="text-end">£<?= number_format($r['deficit'], 2) ?></td>
                    <td class="text-end">£<?= number_format($r['running_deficit'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
