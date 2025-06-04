<?php
require_once '../config/db.php';
include '../layout/header.php';

function ordinal($number) {
    $suffixes = ['th','st','nd','rd','th','th','th','th','th','th'];
    if ($number == '') {
		return '—';
	} elseif (($number % 100) >= 11 && ($number % 100) <= 13) {
        return $number . 'th';
    } else {
        return $number . $suffixes[$number % 10];
    }
}

$stmt = $pdo->query("
SELECT * from accounts order by active desc, name
");
$accounts = $stmt->fetchAll();

$grouped = [
    'current' => [],
    'credit' => [],
    'savings' => [],
    'house' => [],
    'investment' => []
];
foreach ($accounts as $acc) {
    $grouped[$acc['type']][] = $acc;
}
?>

<div class="container mt-4">
    <h2>📂 Account Management</h2>

    <table class="table table-bordered table-striped mt-3">
        <thead class="table-dark">
            <tr>
                <th>Name</th><th>Type</th><th>Institution</th><th>Active</th><th>Starting Balance</th><th>Statement DOM</th><th>Payment DOM</th><th>Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (['current', 'credit', 'savings', 'house', 'investment'] as $type): ?>
            <tr class="table-secondary">
                <td colspan="8" class="fw-bold"><?= ucfirst($type) ?> Accounts</td>
            </tr>
            <?php foreach ($grouped[$type] as $acc): ?>

                <tr>
                    <td><?= $acc['name'] ?></td>
                    <td><?= ucfirst($acc['type']) ?></td>
                    <td><?= htmlspecialchars($acc['institution'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($acc['active'] ?? '—') ?></td>
                    <td><?= htmlspecialchars('£' . number_format($acc['starting_balance'], 2) ?? '—') ?></td>
                    <td><?= htmlspecialchars(ordinal($acc['statement_day']) ?? '—') ?></td>
                    <td><?= htmlspecialchars(ordinal($acc['payment_day']) ?? '—') ?></td>
                    <td><a href="accounts_edit.php?id=<?= $acc['id'] ?>" title="Edit Category">✏️</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
