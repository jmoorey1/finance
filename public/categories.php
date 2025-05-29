<?php
require_once '../config/db.php';
include '../layout/header.php';

define('TRANSFER_PARENT_ID', 275);

$stmt = $pdo->query("
WITH cat_dates AS (
    SELECT t.date, t.category_id
    FROM transactions t
    LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id
    WHERE ts.id IS NULL

    UNION ALL

    SELECT t.date, ts.category_id
    FROM transaction_splits ts
    JOIN transactions t ON ts.transaction_id = t.id
),
last_cat AS (
    SELECT category_id, MAX(date) AS last_date
    FROM cat_dates
    GROUP BY category_id
)
SELECT c.*, top.name AS parent_name, a.name AS account_name, last_cat.last_date
FROM categories c
LEFT JOIN categories top ON c.parent_id = top.id
LEFT JOIN accounts a ON c.linked_account_id = a.id
LEFT JOIN last_cat ON last_cat.category_id = c.id
WHERE c.id != 197 AND c.id != 275 AND (a.active IS NULL OR a.active = 1)
ORDER BY 
    FIELD(c.type, 'income', 'expense', 'transfer'),
    COALESCE(top.name, c.name), 
    c.name
");
$categories = $stmt->fetchAll();

$grouped = [
    'income' => [],
    'expense' => [],
    'transfer' => [],
];
foreach ($categories as $cat) {
    $grouped[$cat['type']][] = $cat;
}
?>

<div class="container mt-4">
    <h2>📂 Category Maintenance</h2>

    <table class="table table-bordered table-striped mt-3">
        <thead class="table-dark">
            <tr>
                <th>Name</th><th>Type</th><th>Parent</th><th>Account</th><th>Fixedness</th><th>Priority</th><th>Last Transaction</th><th>Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (['income', 'expense', 'transfer'] as $type): ?>
            <tr class="table-secondary">
                <td colspan="8" class="fw-bold"><?= ucfirst($type) ?> Categories</td>
            </tr>
            <?php foreach ($grouped[$type] as $cat): ?>
                <?php
                    $isParent = $cat['parent_id'] === null;
                    $nameStyle = $isParent ? 'fw-bold' : '';
                    $nameIndent = $isParent ? '' : ' style="padding-left: 2rem;"';
					$link_base = $isParent ? "category_report.php?category_id={$cat['id']}" : "subcategory_report.php?subcategory_id={$cat['id']}";
					$cat_link = "<a href=\"$link_base\" class=\"text-decoration-none\">" . htmlspecialchars($cat['name']) . "</a>";
					$last_date = $cat['last_date'] 
						? (new DateTime($cat['last_date']))->format('jS M y') 
						: '—';
			?>
                <tr>
                    <td class="<?= $nameStyle ?>"<?= $nameIndent ?>><?= $cat_link ?></td>
                    <td><?= ucfirst($cat['type']) ?></td>
                    <td><?= htmlspecialchars($cat['parent_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($cat['account_name'] ?? '—') ?></td>
                    <td><?= ucfirst(htmlspecialchars($cat['fixedness'] ?? '—')) ?></td>
                    <td><?= ucfirst(htmlspecialchars($cat['priority'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars($last_date) ?></td>
                    <td><a href="category_edit.php?id=<?= $cat['id'] ?>" title="Edit Category">✏️</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layout/footer.php'; ?>
