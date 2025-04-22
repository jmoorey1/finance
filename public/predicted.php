<?php
require_once '../config/db.php';
include '../layout/header.php';

// Fetch all accounts and categories for joins
$accounts = $pdo->query("SELECT id, name FROM accounts ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch recurring rules (predicted_transactions)
$stmt = $pdo->query("
    SELECT * FROM predicted_transactions
    ORDER BY active DESC, id ASC
");
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch predicted instances (next 90 days)
$today = (new DateTime())->format('Y-m-d');
$horizon = (new DateTime('+90 days'))->format('Y-m-d');

$stmt = $pdo->prepare("
    SELECT pi.*, c.name AS category, fa.name AS from_account, ta.name AS to_account
    FROM predicted_instances pi
    LEFT JOIN categories c ON pi.category_id = c.id
    LEFT JOIN accounts fa ON pi.from_account_id = fa.id
    LEFT JOIN accounts ta ON pi.to_account_id = ta.id
    WHERE pi.scheduled_date BETWEEN ? AND ?
    ORDER BY pi.scheduled_date ASC
");
$stmt->execute([$today, $horizon]);
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1 class="mb-4">🔁 Predicted Transactions & Instances</h1>

<!-- 🔁 Recurring Rules -->
<h4 class="mt-4">Recurring Rules</h4>
<table class="table table-sm table-bordered table-striped align-middle">
    <thead class="table-light">
        <tr>
            <th>ID</th>
            <th>Description</th>
            <th>From → To</th>
            <th>Category</th>
            <th>Amount</th>
            <th>Freq / Anchor</th>
            <th>Variable?</th>
            <th>Active</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rules as $r): ?>
            <tr class="<?= $r['active'] ? '' : 'text-muted' ?>">
                <td><?= $r['id'] ?></td>
                <td><?= htmlspecialchars($r['description']) ?></td>
                <td>
                    <?= $accounts[$r['from_account_id']] ?? '—' ?>
                    →
                    <?= $accounts[$r['to_account_id']] ?? '—' ?>
                </td>
                <td><?= $categories[$r['category_id']] ?? '—' ?></td>
                <td class="text-end">£<?= number_format($r['amount'], 2) ?></td>
                <td>
                    <?= $r['frequency'] ?> /
                    <?= $r['anchor_type'] ?>
                    <?php if ($r['anchor_type'] === 'weekly'): ?>
                        (<?= ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$r['weekday']] ?>)
                    <?php elseif ($r['anchor_type'] === 'nth_weekday'): ?>
                        (<?= $r['nth_weekday'] ?>× <?= ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$r['weekday']] ?>)
                    <?php endif; ?>
                </td>
                <td>
                    <?= $r['variable'] ? "Yes (avg last " . $r['average_over_last'] . ")" : "No" ?>
                </td>
                <td class="text-center">
                    <form method="POST" action="toggle_rule.php" class="d-inline">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="active" value="<?= $r['active'] ? 0 : 1 ?>">
                        <button type="submit" class="btn btn-sm <?= $r['active'] ? 'btn-success' : 'btn-secondary' ?>">
                            <?= $r['active'] ? '✔' : '✖' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 📅 Predicted Instances -->
<h4 class="mt-5">Scheduled Predicted Instances (Next 90 Days)</h4>
<table class="table table-sm table-bordered table-striped align-middle">
    <thead class="table-light">
        <tr>
            <th>Date</th>
            <th>Description</th>
            <th>From → To</th>
            <th>Category</th>
            <th class="text-end">Amount</th>
            <th>Fulfilled</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($instances as $i): ?>
            <tr class="<?= $i['fulfilled'] ? 'table-success' : ($i['scheduled_date'] < $today ? 'table-danger' : '') ?>">
                <td><?= $i['scheduled_date'] ?></td>
                <td><?= htmlspecialchars($i['description'] ?? $i['category']) ?></td>
                <td><?= $i['from_account'] ?? '—' ?> → <?= $i['to_account'] ?? '—' ?></td>
                <td><?= $i['category'] ?? '—' ?></td>
                <td class="text-end">£<?= number_format($i['amount'], 2) ?></td>
                <td><?= $i['fulfilled'] ? 'Yes' : 'No' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../layout/footer.php'; ?>
