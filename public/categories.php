<?php
require_once '../config/db.php';
include '../layout/header.php';

define('TRANSFER_PARENT_ID', 275);

// Load top-level (parent) categories for dropdown
$parent_stmt = $pdo->query("
    SELECT id, name, type
    FROM categories
    WHERE parent_id IS NULL AND id != 197 AND id != 275
    ORDER BY type asc, name
");
$parent_categories = $parent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new category form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_category'])) {
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $parent_id = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $fixedness = $parent_id === null ? $_POST['fixedness'] : null;
    $priority = ($parent_id === null && $type === 'expense') ? $_POST['priority'] : null;

    try {
        $pdo->beginTransaction();

        // Determine final category name
        if ($parent_id !== null) {
            $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $stmt->execute([$parent_id]);
            $parent_name = $stmt->fetchColumn();
            $final_name = "{$parent_name} : {$name}";
        } else {
            $final_name = $name;
        }

        // Insert new category
        $stmt = $pdo->prepare("
            INSERT INTO categories (name, parent_id, type, fixedness, priority, budget_order)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $final_name,
            $parent_id,
            $type,
            $fixedness ?: null,
            $priority ?: null
        ]);

        $pdo->commit();
        echo "<div class='alert alert-success'>Category '{$final_name}' added successfully.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>Error adding category: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Load all categories for table
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
    <h2>📂 Add New Category</h2>
    <form method="POST" class="mb-4 border p-3 rounded">
        <div class="mb-2">
            <label for="name" class="form-label">Category Name</label>
            <input type="text" class="form-control" name="name" id="name" required>
        </div>
        <div class="mb-2">
            <label for="type" class="form-label">Type</label>
            <select name="type" id="type" class="form-select" required>
                <option value="income">Income</option>
                <option value="expense">Expense</option>
            </select>
        </div>
        <div class="mb-2">
            <label for="parent_id" class="form-label">Parent Category</label>
            <select name="parent_id" id="parent_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($parent_categories as $parent): ?>
                    <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['name']) ?> (<?= ucfirst($parent['type']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2">
            <label for="fixedness" class="form-label">Fixedness</label>
            <select name="fixedness" id="fixedness" class="form-select">
                <option value="">—</option>
                <option value="fixed">Fixed</option>
                <option value="variable">Variable</option>
            </select>
        </div>
        <div class="mb-2">
            <label for="priority" class="form-label">Priority</label>
            <select name="priority" id="priority" class="form-select">
                <option value="">—</option>
                <option value="essential">Essential</option>
                <option value="discretionary">Discretionary</option>
            </select>
        </div>
        <button type="submit" name="new_category" class="btn btn-primary">Add Category</button>
    </form>

    <h2>📂 Category Management</h2>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const parentSelect = document.getElementById('parent_id');
    const fixednessSelect = document.getElementById('fixedness');
    const prioritySelect = document.getElementById('priority');
    const typeSelect = document.getElementById('type');

    function updateFields() {
        const hasParent = parentSelect.value !== '';
        const isExpense = typeSelect.value === 'expense';

        fixednessSelect.disabled = hasParent;
        prioritySelect.disabled = hasParent || !isExpense;

        if (hasParent) {
            fixednessSelect.value = '';
            prioritySelect.value = '';
        }
    }

    parentSelect.addEventListener('change', updateFields);
    typeSelect.addEventListener('change', updateFields);
    updateFields();
});
</script>

<?php include '../layout/footer.php'; ?>
