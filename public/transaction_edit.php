<?php
require_once '../config/db.php';
include '../layout/header.php';

$conn = get_db_connection();

// Validate transaction ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "Invalid transaction ID.";
    include '../layout/footer.php';
    exit;
}

$id = (int)$_GET['id'];

// Fetch transaction
$stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    echo "Transaction not found.";
    include '../layout/footer.php';
    exit;
}

// Fetch supporting data
$accounts = $conn->query("SELECT id, name FROM accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $conn->query("SELECT c.id, c.name, c.type, c.parent_id, p.name AS parent_name FROM categories c LEFT JOIN categories p ON c.parent_id = p.id where c.id not in (197,275) ORDER BY c.type, COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name")->fetchAll(PDO::FETCH_ASSOC);
$payees = $conn->query("SELECT id, name FROM payees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$funds = $conn->query("SELECT id, name FROM earmarks ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$projects = $conn->query("SELECT id, name FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$statements = $conn->query("SELECT s.id, s.statement_date, s.account_id, a.name as account_name FROM statements s join accounts a on a.id=s.account_id ORDER BY s.statement_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch any splits
$splits = [];
if ($transaction['category_id'] == 197) {
    $stmt = $conn->prepare("
        SELECT ts.category_id, ts.amount, c.name
        FROM transaction_splits ts
        JOIN categories c ON c.id = ts.category_id
        WHERE ts.transaction_id = ?
    ");
    $stmt->execute([$id]);
    $splits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch transfer counterparty if relevant
$counterparty = null;
if ($transaction['type'] === 'transfer' && $transaction['transfer_group_id']) {
    $stmt = $conn->prepare("SELECT id FROM transactions WHERE transfer_group_id = ? AND id != ?");
    $stmt->execute([$transaction['transfer_group_id'], $id]);
    $counterparty = $stmt->fetchColumn();
}
?>

<h1>Edit Transaction #<?= $id ?></h1>

<form method="post" action="transaction_edit_submit.php">
    <input type="hidden" name="id" value="<?= $id ?>">
	<input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
    <div style="display: grid; grid-template-columns: max-content auto; gap: 10px 20px; align-items: center; max-width: 700px;">
        <label>Date:</label>
        <input type="date" name="date" value="<?= htmlspecialchars($transaction['date']) ?>">

        <label>Amount:</label>
        <input type="number" step="0.01" name="amount" value="<?= $transaction['amount'] ?>">

        <label>Description:</label>
        <input type="text" name="description" value="<?= htmlspecialchars($transaction['description']) ?>">

        <label>Account:</label>
        <select name="account_id">
            <?php foreach ($accounts as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $transaction['account_id'] == $a['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Category:</label>
        <select name="category_id" id="category_id">
            <?php
            $lastType = null;
            foreach ($categories as $cat):
                if ($cat['type'] !== $lastType):
                    if ($lastType !== null) echo "</optgroup>";
                    echo "<optgroup label=\"" . ucfirst($cat['type']) . " Categories\">";
                    $lastType = $cat['type'];
                endif;

                $indent = $cat['parent_id'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
            ?>
                <option value="<?= $cat['id'] ?>" <?= $transaction['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                    <?= $indent . htmlspecialchars($cat['name']) ?>
                </option>
            <?php endforeach; ?>
            </optgroup>
            <option value="197" <?= $transaction['category_id'] == 197 ? 'selected' : '' ?>>-- Split/Multiple Categories --</option>
        </select>

        <label>Payee:</label>
        <select name="payee_id">
            <option value="">-- None --</option>
            <?php foreach ($payees as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $transaction['payee_id'] == $p['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Reconciled:</label>
        <input type="checkbox" name="reconciled" value="1" <?= $transaction['reconciled'] ? 'checked' : '' ?>>

        <label>Statement:</label>
        <select name="statement_id">
            <option value="">-- None --</option>
            <?php foreach ($statements as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $transaction['statement_id'] == $s['id'] ? 'selected' : '' ?>>
                    <?= $s['statement_date'] ?> (<?= $s['account_name'] ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label>Project/Trip:</label>
        <select name="project_id">
            <option value="">-- None --</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $transaction['project_id'] == $p['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Fund (Earmark):</label>
        <select name="earmark_id">
            <option value="">-- None --</option>
            <?php foreach ($funds as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $transaction['earmark_id'] == $f['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($f['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

<!-- Split Section -->
<div id="split-section" style="margin-top: 20px; <?= $transaction['category_id'] == 197 ? '' : 'display: none;' ?>">
    <h3>Split Categories</h3>
    <table id="split-table">
        <thead>
            <tr>
                <th>Category</th>
                <th>Amount</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($splits)): foreach ($splits as $s): ?>
            <tr>
                <td>
                    <select name="split_categories[]">
                        <?php
                        $lastType = null;
                        foreach ($categories as $cat):
                            if ($cat['type'] !== $lastType):
                                if ($lastType !== null) echo "</optgroup>";
                                echo "<optgroup label=\"" . ucfirst($cat['type']) . " Categories\">";
                                $lastType = $cat['type'];
                            endif;

                            $indent = $cat['parent_id'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
                            $selected = ($s['category_id'] == $cat['id']) ? 'selected' : '';
                        ?>
                            <option value="<?= $cat['id'] ?>" <?= $selected ?>>
                                <?= $indent . htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </td>
                <td><input type="number" step="0.01" name="split_amounts[]" value="<?= $s['amount'] ?>" required></td>
                <td><button type="button" class="remove-split">−</button></td>
            </tr>
        <?php endforeach; else: ?>
            <tr>
                <td>
                    <select name="split_categories[]">
                        <?php
                        $lastType = null;
                        foreach ($categories as $cat):
                            if ($cat['type'] !== $lastType):
                                if ($lastType !== null) echo "</optgroup>";
                                echo "<optgroup label=\"" . ucfirst($cat['type']) . " Categories\">";
                                $lastType = $cat['type'];
                            endif;

                            $indent = $cat['parent_id'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
                        ?>
                            <option value="<?= $cat['id'] ?>">
                                <?= $indent . htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </td>
                <td><input type="number" step="0.01" name="split_amounts[]" required></td>
                <td><button type="button" class="remove-split">−</button></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <button type="button" id="add-split">+ Add Split</button>
    <p id="split-warning" style="color: red; display: none;">⚠️ Split total must match <?= number_format($transaction['amount'], 2) ?></p>
</div>


    <?php if ($counterparty): ?>
        <p><strong>Transfer Counterparty:</strong> <a href="transaction_edit.php?id=<?= $counterparty ?>">Edit Transaction #<?= $counterparty ?></a></p>
    <?php endif; ?>

    <p><button type="submit">Save Changes</button></p>
</form>

<script>
function toggleSplitSection() {
    const splitSection = document.getElementById('split-section');
    const inputs = splitSection.querySelectorAll('input, select');
    const selectedCategory = parseInt(document.getElementById('category_id').value);

    if (selectedCategory === 197) {
        splitSection.style.display = '';
        inputs.forEach(el => el.disabled = false);
    } else {
        splitSection.style.display = 'none';
        inputs.forEach(el => el.disabled = true);
    }
}

function bindRemoveButtons() {
    document.querySelectorAll('.remove-split').forEach(btn => {
        btn.removeEventListener('click', handleRemoveSplit); // prevent duplicate binding
        btn.addEventListener('click', handleRemoveSplit);
    });
}

function handleRemoveSplit(e) {
    const row = this.closest('tr');
    const table = row.parentNode;
    if (table.children.length > 1) {
        table.removeChild(row);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Initial toggle state
    toggleSplitSection();

    // Hook category selector
    document.getElementById('category_id').addEventListener('change', toggleSplitSection);

    // Add split row
    document.getElementById('add-split').addEventListener('click', function () {
        const table = document.getElementById('split-table').querySelector('tbody');
        const row = table.querySelector('tr').cloneNode(true);

        // Clear values
        row.querySelector('input').value = '';
        row.querySelectorAll('input, select').forEach(el => el.disabled = false);

        table.appendChild(row);
        bindRemoveButtons();
    });

    // Bind remove buttons initially
    bindRemoveButtons();
});
</script>


<?php include '../layout/footer.php'; ?>
