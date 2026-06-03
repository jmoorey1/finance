<?php
require_once '../config/db.php';
require_once '../scripts/lib/split_transaction_helpers.php';

define('TRANSFER_PARENT_ID', 275);
$legacySplitCategoryName = finance_legacy_split_category_name();

include '../layout/header.php';

function cat_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cat_valid_watcher_budget_mode(?string $value): string
{
    $allowed = ['normal', 'reimbursable', 'ignore'];
    return in_array((string)$value, $allowed, true) ? (string)$value : 'normal';
}

function cat_valid_watcher_timing_mode(?string $value): string
{
    $allowed = ['operational', 'flexible', 'ignore'];
    return in_array((string)$value, $allowed, true) ? (string)$value : 'operational';
}

function cat_label_or_dash(?string $value): string
{
    return $value !== null && $value !== '' ? ucfirst((string)$value) : '—';
}

// Load top-level (parent) categories for dropdown
$parent_stmt = $pdo->prepare("
    SELECT id, name, type
    FROM categories
    WHERE parent_id IS NULL
      AND id != ?
      AND name != ?
    ORDER BY type ASC, name
");
$parent_stmt->execute([TRANSFER_PARENT_ID, $legacySplitCategoryName]);
$parent_categories = $parent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new category form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_category'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? '');
    $parent_id = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
    $fixedness = $parent_id === null ? (($_POST['fixedness'] ?? '') !== '' ? (string)$_POST['fixedness'] : null) : null;
    $priority = ($parent_id === null && $type === 'expense') ? ((($_POST['priority'] ?? '') !== '') ? (string)$_POST['priority'] : null) : null;

    $watcher_budget_mode = ($parent_id === null && $type === 'expense')
        ? cat_valid_watcher_budget_mode($_POST['watcher_budget_mode'] ?? 'normal')
        : 'normal';

    $watcher_timing_mode = ($parent_id === null && $type === 'expense')
        ? cat_valid_watcher_timing_mode($_POST['watcher_timing_mode'] ?? 'operational')
        : 'operational';

    if ($watcher_budget_mode !== 'normal') {
        $watcher_timing_mode = 'operational';
    }

    try {
        if ($name === '') {
            throw new RuntimeException('Category name is required.');
        }

        if (!in_array($type, ['income', 'expense'], true)) {
            throw new RuntimeException('Invalid category type.');
        }

        $pdo->beginTransaction();

        // Determine final category name
        if ($parent_id !== null) {
            $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $stmt->execute([$parent_id]);
            $parent_name = $stmt->fetchColumn();

            if (!$parent_name) {
                throw new RuntimeException('Selected parent category was not found.');
            }

            $final_name = "{$parent_name} : {$name}";
        } else {
            $final_name = $name;
        }

        // Insert new category
        $stmt = $pdo->prepare("
            INSERT INTO categories (
                name,
                parent_id,
                type,
                fixedness,
                priority,
                watcher_budget_mode,
                watcher_timing_mode,
                budget_order
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $final_name,
            $parent_id,
            $type,
            $fixedness ?: null,
            $priority ?: null,
            $watcher_budget_mode,
            $watcher_timing_mode,
        ]);

        $pdo->commit();
        echo "<div class='alert alert-success'>Category '" . cat_h($final_name) . "' added successfully.</div>";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "<div class='alert alert-danger'>Error adding category: " . cat_h($e->getMessage()) . "</div>";
    }
}

// Load all categories for table
$stmt = $pdo->prepare("
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
SELECT
    c.*,
    top.name AS parent_name,
    top.watcher_budget_mode AS parent_watcher_budget_mode,
    top.watcher_timing_mode AS parent_watcher_timing_mode,
    a.name AS account_name,
    last_cat.last_date
FROM categories c
LEFT JOIN categories top ON c.parent_id = top.id
LEFT JOIN accounts a ON c.linked_account_id = a.id
LEFT JOIN last_cat ON last_cat.category_id = c.id
WHERE c.id != ? AND c.name != ? AND (a.active IS NULL OR a.active = 1)
ORDER BY
    FIELD(c.type, 'income', 'expense', 'transfer'),
    COALESCE(top.name, c.name),
    c.name
");
$stmt->execute([TRANSFER_PARENT_ID, $legacySplitCategoryName]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [
    'income' => [],
    'expense' => [],
];

foreach ($categories as $cat) {
    if (isset($grouped[$cat['type']])) {
        $grouped[$cat['type']][] = $cat;
    }
}

$legacy_transfer_stmt = $pdo->query("
    SELECT
        c.id,
        c.name,
        c.linked_account_id,
        a.name AS account_name,
        (
            SELECT COUNT(*)
            FROM transactions t
            WHERE t.category_id = c.id
        ) AS transaction_refs,
        (
            SELECT COUNT(*)
            FROM transaction_splits ts
            WHERE ts.category_id = c.id
        ) AS split_refs,
        (
            SELECT COUNT(*)
            FROM predicted_transactions pt
            WHERE pt.category_id = c.id
        ) AS predicted_rule_refs,
        (
            SELECT COUNT(*)
            FROM predicted_instances pi
            WHERE pi.category_id = c.id
        ) AS predicted_instance_refs
    FROM categories c
    LEFT JOIN accounts a ON a.id = c.linked_account_id
    WHERE c.type = 'transfer'
      AND c.id != " . TRANSFER_PARENT_ID . "
    ORDER BY c.name
");
$legacy_transfer_categories = $legacy_transfer_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>📂 Add New Category</h2>
    <form method="POST" class="mb-4 border p-3 rounded" id="new-category-form">
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
                    <option value="<?= (int)$parent['id'] ?>"><?= cat_h($parent['name']) ?> (<?= ucfirst((string)$parent['type']) ?>)</option>
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

        <div class="mb-2" id="watcher-budget-group">
            <label for="watcher_budget_mode" class="form-label">Watcher Budget Treatment</label>
            <select name="watcher_budget_mode" id="watcher_budget_mode" class="form-select">
                <option value="normal" selected>Normal</option>
                <option value="reimbursable">Reimbursable</option>
                <option value="ignore">Ignore</option>
            </select>
            <div class="form-text">Used only for top-level expense categories.</div>
        </div>

        <div class="mb-2" id="watcher-timing-group">
            <label for="watcher_timing_mode" class="form-label">Watcher Timing Treatment</label>
            <select name="watcher_timing_mode" id="watcher_timing_mode" class="form-select">
                <option value="operational" selected>Operational</option>
                <option value="flexible">Flexible</option>
                <option value="ignore">Ignore</option>
            </select>
            <div class="form-text">Used only for top-level expense categories with normal budget treatment.</div>
        </div>

        <button type="submit" name="new_category" class="btn btn-primary">Add Category</button>
    </form>

    <h2>📂 Category Management</h2>

    <div class="alert alert-info">
        <strong>Transfer categories are deprecated.</strong>
        Transfers are now modelled through <code>transfer_groups</code> and transfer prediction metadata.
        Legacy transfer category rows are retained below as a read-only audit aid, but they are no longer used for new actual or predicted transfers.
    </div>

    <table class="table table-bordered table-striped mt-3">
        <thead class="table-dark">
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Parent</th>
                <th>Account</th>
                <th>Fixedness</th>
                <th>Priority</th>
                <th>Budget Watcher</th>
                <th>Timing Watcher</th>
                <th>Last Transaction</th>
                <th>Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (['income', 'expense'] as $type): ?>
            <tr class="table-secondary">
                <td colspan="10" class="fw-bold"><?= ucfirst($type) ?> Categories</td>
            </tr>
            <?php foreach ($grouped[$type] as $cat): ?>
                <?php
                    $isParent = $cat['parent_id'] === null;
                    $nameStyle = $isParent ? 'fw-bold' : '';
                    $nameIndent = $isParent ? '' : ' style="padding-left: 2rem;"';
                    $link_base = $isParent ? "category_report.php?category_id={$cat['id']}" : "subcategory_report.php?subcategory_id={$cat['id']}";
                    $cat_link = "<a href=\"$link_base\" class=\"text-decoration-none\">" . cat_h((string)$cat['name']) . "</a>";
                    $last_date = $cat['last_date']
                        ? (new DateTime((string)$cat['last_date']))->format('jS M y')
                        : '—';

                    $budgetWatcher = '—';
                    $timingWatcher = '—';

                    if ($cat['type'] === 'expense' && $isParent) {
                        $budgetWatcher = ucfirst((string)$cat['watcher_budget_mode']);
                        $timingWatcher = ucfirst((string)$cat['watcher_timing_mode']);
                    } elseif ($cat['type'] === 'expense' && !$isParent && !empty($cat['parent_name'])) {
                        $budgetWatcher = 'Inherited: ' . ucfirst((string)$cat['parent_watcher_budget_mode']);
                        $timingWatcher = 'Inherited: ' . ucfirst((string)$cat['parent_watcher_timing_mode']);
                    }
                ?>
                <tr>
                    <td class="<?= $nameStyle ?>"<?= $nameIndent ?>><?= $cat_link ?></td>
                    <td><?= ucfirst((string)$cat['type']) ?></td>
                    <td><?= cat_h((string)($cat['parent_name'] ?? '—')) ?></td>
                    <td><?= cat_h((string)($cat['account_name'] ?? '—')) ?></td>
                    <td><?= cat_label_or_dash($cat['fixedness'] ?? null) ?></td>
                    <td><?= cat_label_or_dash($cat['priority'] ?? null) ?></td>
                    <td><?= cat_h($budgetWatcher) ?></td>
                    <td><?= cat_h($timingWatcher) ?></td>
                    <td><?= cat_h((string)$last_date) ?></td>
                    <td><a href="category_edit.php?id=<?= (int)$cat['id'] ?>" title="Edit Category">✏️</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 class="mt-5">🔒 Legacy Transfer Categories</h3>
    <div class="alert alert-secondary">
        These rows are kept for audit/history only. Do not edit or delete them manually.
        New transfer transactions and transfer predictions should not reference these categories.
    </div>

    <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Linked Account</th>
                <th class="text-end">Transactions</th>
                <th class="text-end">Splits</th>
                <th class="text-end">Prediction Rules</th>
                <th class="text-end">Prediction Instances</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($legacy_transfer_categories)): ?>
                <tr>
                    <td colspan="8" class="text-muted">No legacy transfer categories found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($legacy_transfer_categories as $legacy): ?>
                    <?php
                        $activeRefs =
                            (int)$legacy['transaction_refs']
                            + (int)$legacy['split_refs']
                            + (int)$legacy['predicted_rule_refs']
                            + (int)$legacy['predicted_instance_refs'];

                        $status = $activeRefs === 0
                            ? '<span class="badge bg-success">Unreferenced</span>'
                            : '<span class="badge bg-warning text-dark">Still referenced</span>';
                    ?>
                    <tr>
                        <td><?= (int)$legacy['id'] ?></td>
                        <td><?= cat_h((string)$legacy['name']) ?></td>
                        <td><?= cat_h((string)($legacy['account_name'] ?? '—')) ?></td>
                        <td class="text-end"><?= (int)$legacy['transaction_refs'] ?></td>
                        <td class="text-end"><?= (int)$legacy['split_refs'] ?></td>
                        <td class="text-end"><?= (int)$legacy['predicted_rule_refs'] ?></td>
                        <td class="text-end"><?= (int)$legacy['predicted_instance_refs'] ?></td>
                        <td><?= $status ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const parentSelect = document.getElementById('parent_id');
    const fixednessSelect = document.getElementById('fixedness');
    const prioritySelect = document.getElementById('priority');
    const typeSelect = document.getElementById('type');
    const watcherBudgetGroup = document.getElementById('watcher-budget-group');
    const watcherTimingGroup = document.getElementById('watcher-timing-group');
    const watcherBudgetSelect = document.getElementById('watcher_budget_mode');
    const watcherTimingSelect = document.getElementById('watcher_timing_mode');

    function updateFields() {
        const hasParent = parentSelect.value !== '';
        const isExpense = typeSelect.value === 'expense';
        const isTopLevelExpense = !hasParent && isExpense;

        fixednessSelect.disabled = hasParent;
        prioritySelect.disabled = hasParent || !isExpense;

        watcherBudgetGroup.style.display = isTopLevelExpense ? 'block' : 'none';
        watcherTimingGroup.style.display = isTopLevelExpense ? 'block' : 'none';

        watcherBudgetSelect.disabled = !isTopLevelExpense;
        watcherTimingSelect.disabled = !isTopLevelExpense;

        if (hasParent) {
            fixednessSelect.value = '';
            prioritySelect.value = '';
        }

        if (!isTopLevelExpense) {
            watcherBudgetSelect.value = 'normal';
            watcherTimingSelect.value = 'operational';
        }

        updateTimingField();
    }

    function updateTimingField() {
        const timingRelevant = !watcherBudgetSelect.disabled && watcherBudgetSelect.value === 'normal';
        watcherTimingSelect.disabled = !timingRelevant;
        watcherTimingGroup.style.display = (!watcherBudgetSelect.disabled) ? 'block' : 'none';

        if (!timingRelevant) {
            watcherTimingSelect.value = 'operational';
        }
    }

    parentSelect.addEventListener('change', updateFields);
    typeSelect.addEventListener('change', updateFields);
    watcherBudgetSelect.addEventListener('change', updateTimingField);

    updateFields();
});
</script>

<?php include '../layout/footer.php'; ?>
