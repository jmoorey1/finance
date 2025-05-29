<?php
require_once '../config/db.php';
include '../layout/header.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<div class='alert alert-danger'>Missing category ID.</div>";
    exit;
}

// Fetch category
$stmt = $pdo->prepare("SELECT c.*, top.name as parent_name FROM categories c left join categories top on c.parent_id=top.id WHERE c.id = ?");
$stmt->execute([$id]);
$cat = $stmt->fetch();
if (!$cat) {
    echo "<div class='alert alert-danger'>Category not found.</div>";
    exit;
}

// Determine if the category has children (to allow demotion)
$hasChildren = false;
if ($cat['parent_id'] === null && in_array($cat['type'], ['income', 'expense'])) {
    $checkChildren = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ?");
    $checkChildren->execute([$id]);
    $hasChildren = $checkChildren->fetchColumn() > 0;
}

// Reassignable parent categories of same type
$parents = $pdo->prepare("SELECT id, name FROM categories WHERE parent_id IS NULL AND type = ? AND id != ? ORDER BY name");
$parents->execute([$cat['type'], $id]);
$parentOptions = $parents->fetchAll();

// Active accounts for transfer linkage
$accounts = $pdo->query("SELECT id, name FROM accounts WHERE active = 1 ORDER BY name")->fetchAll();

// Categories of same type (excluding current) for deletion reassignment
$replacements = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE type = ? AND id != ? ORDER BY name");
$replacements->execute([$cat['type'], $id]);
$replacementOptions = $replacements->fetchAll();
?>

<div class="container mt-4">
    <h2>Edit Category: <?= $cat['name'] ?></h2>
	<?= $cat['parent_name'] != '' ? '<a href="category_edit.php?id=' . $cat['parent_id'] . '">Edit ' . $cat['parent_name'] . ' →</a>' : '' ?>

    <form action="category_edit_submit.php" method="POST" class="mt-3" id="category-form">
        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
        <input type="hidden" name="type" value="<?= $cat['type'] ?>">

        <div class="mb-3">
            <label class="form-label">Category Type</label>
            <input type="text" class="form-control" value="<?= ucfirst($cat['type']) ?>" disabled>
        </div>

        <?php if ($cat['type'] === 'transfer'): ?>
            <div class="mb-3">
                <label class="form-label">Transfer Direction</label>
                <select name="direction" class="form-select" required>
                    <option value="TO" <?= str_contains($cat['name'], 'TO') ? 'selected' : '' ?>>To</option>
                    <option value="FROM" <?= str_contains($cat['name'], 'FROM') ? 'selected' : '' ?>>From</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Linked Account</label>
                <select name="linked_account_id" class="form-select" required>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>" <?= $cat['linked_account_id'] == $acc['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($acc['name']) ?>
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
                        <option value="<?= $p['id'] ?>" <?= $cat['parent_id'] == $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Suffix</label>
                <?php
                    $suffix = explode(' : ', $cat['name'])[1] ?? $cat['name'];
                ?>
                <input type="text" name="suffix" class="form-control" value="<?= htmlspecialchars($suffix) ?>" required>
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

        <?php else: ?>
            <div class="mb-3">
                <label class="form-label">Category Name</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cat['name']) ?>" required>
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
            <?php if (!$hasChildren): ?>
                <div class="mb-3">
                    <label class="form-label">Demote to Subcategory</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— Keep as Parent —</option>
                        <?php foreach ($parentOptions as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
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
			<input type="hidden" name="id" value="<?= $cat['id'] ?>">
			<input type="hidden" name="action" value="delete">
			<div class="mb-3">
				<label class="form-label text-danger">Reassign to Category (before deleting)</label>
				<select name="replacement_id" class="form-select" required>
					<?php foreach ($replacementOptions as $r): ?>
						<option value="<?= $r['id'] ?>"><?= $r['parent_id'] != '' ? '&nbsp&nbsp&nbsp&nbsp' : '' ?><?= htmlspecialchars($r['name']) ?></option>
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
    const catType = "<?= $cat['type'] ?>";

    if (parentSelect) {
        function toggleParentFields() {
            const isPromotion = !parentSelect.value;
            fixedGroup.style.display = isPromotion ? 'block' : 'none';
            priorityGroup.style.display = isPromotion && catType === 'expense' ? 'block' : 'none';

            fixedGroup.querySelector('select').required = isPromotion;
            priorityGroup.querySelector('select').required = isPromotion && catType === 'expense';
        }

        parentSelect.addEventListener('change', toggleParentFields);
        toggleParentFields();
    }
</script>

<?php include '../layout/footer.php'; ?>
