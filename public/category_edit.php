<?php
require_once '../config/db.php';
include '../layout/header.php';

function ced_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ced_valid_watcher_budget_mode(?string $value): string
{
    $allowed = ['normal', 'reimbursable', 'ignore'];
    return in_array((string)$value, $allowed, true) ? (string)$value : 'normal';
}

function ced_valid_watcher_timing_mode(?string $value): string
{
    $allowed = ['operational', 'flexible', 'ignore'];
    return in_array((string)$value, $allowed, true) ? (string)$value : 'operational';
}

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<div class='alert alert-danger'>Missing category ID.</div>";
    exit;
}

// Fetch category
$stmt = $pdo->prepare("SELECT c.*, top.name as parent_name FROM categories c LEFT JOIN categories top ON c.parent_id = top.id WHERE c.id = ?");
$stmt->execute([$id]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cat) {
    echo "<div class='alert alert-danger'>Category not found.</div>";
    exit;
}

$watcherBudgetMode = ced_valid_watcher_budget_mode($cat['watcher_budget_mode'] ?? 'normal');
$watcherTimingMode = ced_valid_watcher_timing_mode($cat['watcher_timing_mode'] ?? 'operational');

// Determine if the category has children (to allow demotion)
$hasChildren = false;
if ($cat['parent_id'] === null && in_array($cat['type'], ['income', 'expense'], true)) {
    $checkChildren = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ?");
    $checkChildren->execute([$id]);
    $hasChildren = $checkChildren->fetchColumn() > 0;
}

// Reassignable parent categories of same type
$parents = $pdo->prepare("SELECT id, name FROM categories WHERE parent_id IS NULL AND type = ? AND id != ? ORDER BY name");
$parents->execute([$cat['type'], $id]);
$parentOptions = $parents->fetchAll(PDO::FETCH_ASSOC);

// Active accounts for transfer linkage
$accounts = $pdo->query("SELECT id, name FROM accounts WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Categories of same type (excluding current) for deletion reassignment
$replacements = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE type = ? AND id != ? ORDER BY name");
$replacements->execute([$cat['type'], $id]);
$replacementOptions = $replacements->fetchAll(PDO::FETCH_ASSOC);

/**
 * Robust default selection for Transfer Direction
 */
$catName = (string)($cat['name'] ?? '');
$catNameUpper = strtoupper(trim($catName));

$isTo   = (strpos($catNameUpper, 'TRANSFER TO') === 0)   || (preg_match('/^TRANSFER\\s+TO\\s*:/', $catNameUpper) === 1);
$isFrom = (strpos($catNameUpper, 'TRANSFER FROM') === 0) || (preg_match('/^TRANSFER\\s+FROM\\s*:/', $catNameUpper) === 1);

if (!$isTo && !$isFrom) {
    $isTo   = (preg_match('/\\bTRANSFER\\s+TO\\s*:/i', $catName) === 1);
    $isFrom = (preg_match('/\\bTRANSFER\\s+FROM\\s*:/i', $catName) === 1);
}

$defaultDirection = $isFrom ? 'FROM' : 'TO';
?>
<div class="container mt-4">
    <h2>Edit Category: <?= ced_h((string)$cat['name']) ?></h2>
    <?= $cat['parent_name'] !== '' ? '<a href="category_edit.php?id=' . (int)$cat['parent_id'] . '">Edit ' . ced_h((string)$cat['parent_name']) . ' →</a>' : '' ?>

    <form action="category_edit_submit.php" method="POST" class="mt-3" id="category-form">
        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
        <input type="hidden" name="type" value="<?= ced_h((string)$cat['type']) ?>">

        <div class="mb-3">
            <label class="form-label">Category Type</label>
            <input type="text" class="form-control" value="<?= ucfirst((string)$cat['type']) ?>" disabled>
        </div>

        <?php if ($cat['type'] === 'transfer'): ?>
            <div class="mb-3">
                <label class="form-label">Transfer Direction</label>
                <select name="direction" class="form-select" required>
                    <option value="TO" <?= $defaultDirection === 'TO' ? 'selected' : '' ?>>To</option>
                    <option value="FROM" <?= $defaultDirection === 'FROM' ? 'selected' : '' ?>>From</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Linked Account</label>
                <select name="linked_account_id" class="form-select" required>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= (int)$acc['id'] ?>" <?= (int)$cat['linked_account_id'] === (int)$acc['id'] ? 'selected' : '' ?>>
                            <?= ced_h((string)$acc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        <?php elseif ($cat['parent_id']): ?>
            <div class="mb-3">
                <label class="form-label">Reassign Parent Category</label>
                <select name="parent_id" class="form-select" id="parent-select" required>
                    <option value="">— Promote to Parent —</option>
                    <?php foreach ($parentOptions as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)$cat['parent_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= ced_h((string)$p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($cat['type'] === 'expense'): ?>
                    <div class="form-text">Watcher treatment only applies when this expense category is promoted to a parent.</div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label">Suffix</label>
                <?php $suffix = explode(' : ', (string)$cat['name'])[1] ?? (string)$cat['name']; ?>
                <input type="text" name="suffix" class="form-control" value="<?= ced_h($suffix) ?>" required>
            </div>
            <div class="mb-3" id="fixedness-group" style="display: none;">
                <label class="form-label">Fixedness</label>
                <select name="fixedness" id="fixedness" class="form-select">
                    <option value="">Unset</option>
                    <option value="fixed" <?= $cat['fixedness'] === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                    <option value="variable" <?= $cat['fixedness'] === 'variable' ? 'selected' : '' ?>>Variable</option>
                </select>
            </div>
            <div class="mb-3" id="priority-group" style="display: none;">
                <label class="form-label">Priority</label>
                <select name="priority" id="priority" class="form-select">
                    <option value="">Unset</option>
                    <option value="essential" <?= $cat['priority'] === 'essential' ? 'selected' : '' ?>>Essential</option>
                    <option value="discretionary" <?= $cat['priority'] === 'discretionary' ? 'selected' : '' ?>>Discretionary</option>
                </select>
            </div>

            <?php if ($cat['type'] === 'expense'): ?>
                <div class="mb-3" id="watcher-budget-group" style="display: none;">
                    <label class="form-label">Watcher Budget Treatment</label>
                    <select name="watcher_budget_mode" id="watcher_budget_mode" class="form-select">
                        <option value="normal" <?= $watcherBudgetMode === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="reimbursable" <?= $watcherBudgetMode === 'reimbursable' ? 'selected' : '' ?>>Reimbursable</option>
                        <option value="ignore" <?= $watcherBudgetMode === 'ignore' ? 'selected' : '' ?>>Ignore</option>
                    </select>
                </div>
                <div class="mb-3" id="watcher-timing-group" style="display: none;">
                    <label class="form-label">Watcher Timing Treatment</label>
                    <select name="watcher_timing_mode" id="watcher_timing_mode" class="form-select">
                        <option value="operational" <?= $watcherTimingMode === 'operational' ? 'selected' : '' ?>>Operational</option>
                        <option value="flexible" <?= $watcherTimingMode === 'flexible' ? 'selected' : '' ?>>Flexible</option>
                        <option value="ignore" <?= $watcherTimingMode === 'ignore' ? 'selected' : '' ?>>Ignore</option>
                    </select>
                    <div class="form-text">Timing treatment is only used for top-level expense categories with normal budget treatment.</div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="mb-3">
                <label class="form-label">Category Name</label>
                <input type="text" name="name" class="form-control" value="<?= ced_h((string)$cat['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Fixedness</label>
                <select name="fixedness" class="form-select">
                    <option value="">Unset</option>
                    <option value="fixed" <?= $cat['fixedness'] === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                    <option value="variable" <?= $cat['fixedness'] === 'variable' ? 'selected' : '' ?>>Variable</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <option value="">Unset</option>
                    <option value="essential" <?= $cat['priority'] === 'essential' ? 'selected' : '' ?>>Essential</option>
                    <option value="discretionary" <?= $cat['priority'] === 'discretionary' ? 'selected' : '' ?>>Discretionary</option>
                </select>
            </div>

            <?php if ($cat['type'] === 'expense'): ?>
                <div class="mb-3">
                    <label class="form-label">Watcher Budget Treatment</label>
                    <select name="watcher_budget_mode" id="watcher_budget_mode" class="form-select">
                        <option value="normal" <?= $watcherBudgetMode === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="reimbursable" <?= $watcherBudgetMode === 'reimbursable' ? 'selected' : '' ?>>Reimbursable</option>
                        <option value="ignore" <?= $watcherBudgetMode === 'ignore' ? 'selected' : '' ?>>Ignore</option>
                    </select>
                </div>
                <div class="mb-3" id="watcher-timing-group">
                    <label class="form-label">Watcher Timing Treatment</label>
                    <select name="watcher_timing_mode" id="watcher_timing_mode" class="form-select">
                        <option value="operational" <?= $watcherTimingMode === 'operational' ? 'selected' : '' ?>>Operational</option>
                        <option value="flexible" <?= $watcherTimingMode === 'flexible' ? 'selected' : '' ?>>Flexible</option>
                        <option value="ignore" <?= $watcherTimingMode === 'ignore' ? 'selected' : '' ?>>Ignore</option>
                    </select>
                    <div class="form-text">Timing treatment is only used for top-level expense categories with normal budget treatment.</div>
                </div>
            <?php endif; ?>

            <?php if (!$hasChildren): ?>
                <div class="mb-3">
                    <label class="form-label">Demote to Subcategory</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— Keep as Parent —</option>
                        <?php foreach ($parentOptions as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= ced_h((string)$p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <button type="submit" class="btn btn-success">Save Changes</button>
        <a href="categories.php" class="btn btn-secondary">Cancel</a>
    </form>

    <?php if (!$hasChildren): ?>
    <hr class="my-4">
        <form action="category_edit_submit.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this category?')">
            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <div class="mb-3">
                <label class="form-label text-danger">Reassign to Category (before deleting)</label>
                <select name="replacement_id" class="form-select" required>
                    <?php foreach ($replacementOptions as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= $r['parent_id'] != '' ? '&nbsp&nbsp&nbsp&nbsp' : '' ?><?= ced_h((string)$r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-danger">Delete Category</button>
        </form>
    <?php endif; ?>
</div>

<script>
    const parentSelect = document.getElementById('parent-select');
    const fixedGroup = document.getElementById('fixedness-group');
    const priorityGroup = document.getElementById('priority-group');
    const catType = "<?= ced_h((string)$cat['type']) ?>";
    const watcherBudgetGroup = document.getElementById('watcher-budget-group');
    const watcherTimingGroup = document.getElementById('watcher-timing-group');
    const watcherBudgetSelect = document.getElementById('watcher_budget_mode');
    const watcherTimingSelect = document.getElementById('watcher_timing_mode');

    function updateTimingWatcherState() {
        if (!watcherBudgetSelect || !watcherTimingSelect || !watcherTimingGroup) {
            return;
        }

        const timingRelevant = !watcherBudgetSelect.disabled && watcherBudgetSelect.value === 'normal';
        watcherTimingSelect.disabled = !timingRelevant;

        if (!timingRelevant) {
            watcherTimingSelect.value = 'operational';
        }
    }

    if (parentSelect) {
        function toggleParentFields() {
            const isPromotion = !parentSelect.value;

            fixedGroup.style.display = isPromotion ? 'block' : 'none';
            priorityGroup.style.display = isPromotion && catType === 'expense' ? 'block' : 'none';

            if (fixedGroup) {
                fixedGroup.querySelector('select').required = isPromotion;
            }
            if (priorityGroup) {
                priorityGroup.querySelector('select').required = isPromotion && catType === 'expense';
            }

            if (watcherBudgetGroup && watcherBudgetSelect) {
                watcherBudgetGroup.style.display = isPromotion && catType === 'expense' ? 'block' : 'none';
                watcherBudgetSelect.disabled = !(isPromotion && catType === 'expense');
                if (watcherBudgetSelect.disabled) {
                    watcherBudgetSelect.value = 'normal';
                }
            }

            if (watcherTimingGroup && watcherTimingSelect) {
                watcherTimingGroup.style.display = isPromotion && catType === 'expense' ? 'block' : 'none';
                watcherTimingSelect.disabled = !(isPromotion && catType === 'expense');
                if (watcherTimingSelect.disabled) {
                    watcherTimingSelect.value = 'operational';
                }
            }

            updateTimingWatcherState();
        }

        parentSelect.addEventListener('change', toggleParentFields);
        if (watcherBudgetSelect) {
            watcherBudgetSelect.addEventListener('change', updateTimingWatcherState);
        }
        toggleParentFields();
    } else if (watcherBudgetSelect) {
        watcherBudgetSelect.addEventListener('change', updateTimingWatcherState);
        updateTimingWatcherState();
    }
</script>

<?php include '../layout/footer.php'; ?>
