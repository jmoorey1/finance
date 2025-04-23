<?php
require_once '../config/db.php';
include '../layout/header.php';

// Fetch lookups
$accounts = $pdo->query("SELECT id, name FROM accounts ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch predicted_transactions
$rules = $pdo->query("SELECT * FROM predicted_transactions ORDER BY active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch predicted_instances
$today = (new DateTime())->format('Y-m-d');
$future = (new DateTime('+90 days'))->format('Y-m-d');
$stmt = $pdo->prepare("
    SELECT pi.*, c.name AS category, fa.name AS from_account, ta.name AS to_account
    FROM predicted_instances pi
    LEFT JOIN categories c ON pi.category_id = c.id
    LEFT JOIN accounts fa ON pi.from_account_id = fa.id
    LEFT JOIN accounts ta ON pi.to_account_id = ta.id
    WHERE pi.scheduled_date BETWEEN ? AND ?
    ORDER BY pi.scheduled_date ASC
");
$stmt->execute([$today, $future]);
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function ordinal($n) {
    if (!in_array(($n % 100), [11,12,13])) {
        switch ($n % 10) {
            case 1: return $n . 'st';
            case 2: return $n . 'nd';
            case 3: return $n . 'rd';
        }
    }
    return $n . 'th';
}

function format_schedule_summary($r) {
    $weekday_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $anchor = $r['anchor_type'];
    if ($anchor === 'weekly') {
        return "Every {$weekday_names[$r['weekday']]}";
    }
    if ($anchor === 'nth_weekday') {
        return "Every " . ordinal($r['repeat_interval']) . " " . $weekday_names[$r['weekday']];
    }
    if ($anchor === 'day_of_month') {
        return "On the " . ordinal($r['day_of_month']) . " of each month";
    }
    if ($anchor === 'last_business_day') {
        return "Last business day of each month";
    }
    return ucfirst($r['frequency']);
}
?>

<h1 class="mb-4">🔁 Predicted Transactions & Instances</h1>

<!-- 🔁 Recurring Rules -->
<h4>Recurring Rules</h4>
<table class="table table-sm table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th>ID</th>
            <th>Description</th>
            <th>From → To</th>
            <th>Category</th>
            <th>Amount</th>
            <th>Schedule</th>
            <th>Variable</th>
            <th>Active</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rules as $r): ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><?= htmlspecialchars($r['description']) ?></td>
            <td><?= $accounts[$r['from_account_id']] ?? '?' ?> → <?= $r['to_account_id'] ? $accounts[$r['to_account_id']] : '—' ?></td>
            <td><?= $categories[$r['category_id']] ?? '?' ?></td>
            <td class="text-end">£<?= number_format($r['amount'], 2) ?></td>
            <td><?= format_schedule_summary($r) ?></td>
            <td><?= $r['variable'] ? "Yes (Avg {$r['average_over_last']})" : "No" ?></td>
            <td><?= $r['active'] ? '✅' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 📅 Upcoming Predicted Instances -->
<h4 class="mt-5">Upcoming Instances (Next 90 Days)</h4>
<table class="table table-sm table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th>Date</th>
            <th>Description</th>
            <th>From → To</th>
            <th>Category</th>
            <th class="text-end">Amount</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($instances as $i): ?>
        <tr class="<?= $i['fulfilled'] ? 'table-success' : ($i['scheduled_date'] < $today ? 'table-danger' : '') ?>">
            <td><?= $i['scheduled_date'] ?></td>
            <td><?= htmlspecialchars($i['description']) ?></td>
            <td><?= $i['from_account'] ?> → <?= $i['to_account'] ?? '—' ?></td>
            <td><?= htmlspecialchars($i['category']) ?></td>
            <td class="text-end">£<?= number_format($i['amount'], 2) ?></td>
            <td><?= $i['fulfilled'] ? '✅ Fulfilled' : ($i['scheduled_date'] < $today ? '⚠️ Missed' : 'Planned') ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../layout/footer.php'; ?>
