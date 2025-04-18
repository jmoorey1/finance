<?php include '../layout/header.php'; ?>

<?php
require_once '../config/db.php';
$conn = get_db_connection();

// Get all non-transfer categories for selection
$categoryOptions = $conn->query("SELECT id, name FROM categories WHERE type != 'transfer' or type is null ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch staging transactions
$sql = "
    SELECT s.*, 
           p.scheduled_date, 
           p.description AS predicted_description,
           t.id AS matched_transaction_id,
           t.description AS matched_transaction_desc,
		   a.name AS account_name
    FROM staging_transactions s
    LEFT JOIN predicted_instances p ON s.predicted_instance_id = p.id
    LEFT JOIN transactions t ON s.matched_transaction_id = t.id
    LEFT JOIN accounts a ON s.account_id = a.id
    ORDER BY s.date DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group transactions by status
$grouped = [
    'all' => $rows,
    'new' => array_filter($rows, fn($r) => $r['status'] === 'new'),
    'fulfills_prediction' => array_filter($rows, fn($r) => $r['status'] === 'fulfills_prediction'),
    'potential_duplicate' => array_filter($rows, fn($r) => $r['status'] === 'potential_duplicate')
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Review Transactions</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .tabs { margin-bottom: 20px; }
        .tab-btn {
            padding: 10px 20px;
            display: inline-block;
            border: 1px solid #ccc;
            background: #eee;
            margin-right: 5px;
            cursor: pointer;
        }
        .tab-btn.active { background: #ddd; font-weight: bold; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; vertical-align: top; }
        th { background-color: #f0f0f0; }
        tr:nth-child(even) { background-color: #fafafa; }
        form { margin: 0; }
        .note { font-size: 0.85em; color: #666; }
        .actions { margin-top: 10px; }
        .split-section { margin-top: 10px; }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<h1>Review Staging Transactions</h1>

<div class="tabs">
    <span class="tab-btn" data-tab="all">All (<?= count($grouped['all']) ?>)</span>
    <span class="tab-btn" data-tab="new">New (<?= count($grouped['new']) ?>)</span>
    <span class="tab-btn" data-tab="fulfills_prediction">Predicted Matches (<?= count($grouped['fulfills_prediction']) ?>)</span>
    <span class="tab-btn" data-tab="potential_duplicate">Duplicates (<?= count($grouped['potential_duplicate']) ?>)</span>
</div>

<?php foreach ($grouped as $group => $entries): ?>
    <div class="tab-content" id="tab-<?= $group ?>">
        <?php if (empty($entries)): ?>
            <p>No transactions found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['date']) ?></td>
                        <td><?= htmlspecialchars($row['account_name']) ?></td>
                        <td><?= htmlspecialchars($row['description']) ?></td>
                        <td><?= number_format($row['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td>
                            <?php if ($row['status'] === 'fulfills_prediction'): ?>
                                <form method="post" action="review_actions.php">
                                    <input type="hidden" name="action" value="fulfill_prediction">
                                    <input type="hidden" name="staging_transaction_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="predicted_instance_id" value="<?= $row['predicted_instance_id'] ?>">
                                    <p class="note">⚡ Predicted: <?= htmlspecialchars($row['predicted_description']) ?> (<?= $row['scheduled_date'] ?>)</p>
                                    <div class="actions">
                                        <button type="submit">Confirm Match</button>
                                        <button type="submit" name="action" value="reject_prediction">Not a Match</button>
                                    </div>
                                </form>
                            <?php elseif ($row['status'] === 'potential_duplicate'): ?>
                                <form method="post" action="review_actions.php">
                                    <input type="hidden" name="staging_transaction_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="matched_transaction_id" value="<?= $row['matched_transaction_id'] ?>">
                                    <p class="note">⚠️ Possibly matches: <?= htmlspecialchars($row['matched_transaction_desc']) ?></p>
                                    <div class="actions">
                                        <button type="submit" name="action" value="confirm_duplicate">Confirm Duplicate</button>
                                        <button type="submit" name="action" value="reject_duplicate">Not a Duplicate</button>
                                    </div>
                                </form>
                            <?php elseif ($row['status'] === 'new'): ?>
                                <form method="post" action="review_actions.php">
                                    <input type="hidden" name="staging_transaction_id" value="<?= $row['id'] ?>">

                                    <!-- Category Dropdown -->
                                    <label>
                                        Category:
                                        <select name="category_id" class="category-select" data-parent-id="<?= $row['id'] ?>">
                                            <?php foreach ($categoryOptions as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                            <?php endforeach; ?>
											<option value="-1">-- Mark as Transfer --</option>
                                        </select>
                                    </label>

									<!-- 🔁 Transfer Pair Dropdown (shown only if category = -1) -->
									<div class="transfer-section" style="display: none; margin-top: 10px;">
										<label>
											Select Counterparty:
											<select name="transfer_target">
												<?php
												$this_amount = (float)$row['amount'];
												$opposite_amount = -1 * $this_amount;
												$target_date = $row['date'];
												$start_date = (new DateTime($target_date))->modify('-3 days')->format('Y-m-d');
												$end_date = (new DateTime($target_date))->modify('+3 days')->format('Y-m-d');

												// Candidate staging rows
												$candidates = $conn->prepare("
													SELECT id, description, date, amount
													FROM staging_transactions
													WHERE id != ? 
													  AND ABS(amount - ?) < 0.01 
													  AND date BETWEEN ? AND ?
												");
												$candidates->execute([$row['id'], $opposite_amount, $start_date, $end_date]);
												$candidates_count = $candidates->fetchAll(PDO::FETCH_ASSOC);
												foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $match) {
													echo "<option value=\"staging_{$match['id']}\">[STAGING] {$match['description']} ({$match['date']}, {$match['amount']})</option>";
												}

												// Placeholder transactions
												$placeholders = $conn->prepare("
													SELECT id, account_id, date, amount 
													FROM transactions 
													WHERE description = 'PLACEHOLDER' 
													  AND ABS(amount - ?) < 0.01 
													  AND date BETWEEN ? AND ?
												");
												$placeholders->execute([$opposite_amount, $start_date, $end_date]);
												$placeholders_count = $placeholders->fetchAll(PDO::FETCH_ASSOC);
												foreach ($placeholders->fetchAll(PDO::FETCH_ASSOC) as $match) {
													echo "<option value=\"existing_{$match['id']}\">[PLACEHOLDER] Account {$match['account_id']} ({$match['date']}, {$match['amount']})</option>";
												}
												if (empty($candidates_count) && empty($placeholders_count)) {
													echo "<option> value=''>--Choose Transfer Type--</option>";
												}
												echo "<option value=\"one_sided\">Counterparty Not Yet Uploaded</option>";
												?>
											</select>
										</label>
									</div>
									<!-- 🔗 Linked account selection (only if one_sided is selected) -->
									<div class="linked-account-section" style="display: none; margin-top: 10px;">
										<label>
											Placeholder Account:
											<select name="linked_account_id" disabled>
												<?php
												$acctStmt = $conn->prepare("SELECT id, name FROM accounts WHERE id != ?");
												$acctStmt->execute([$row['account_id']]);
												foreach ($acctStmt->fetchAll(PDO::FETCH_ASSOC) as $acct) {
													echo "<option value=\"{$acct['id']}\">{$acct['name']}</option>";
												}
												?>
											</select>
										</label>
									</div>


                                    <!-- Split Table -->
                                    <div class="split-section" style="display: none;">
                                        <table class="split-table" data-parent-id="<?= $row['id'] ?>">
                                            <thead>
                                                <tr>
                                                    <th>Category</th>
                                                    <th>Amount</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>
                                                        <select name="split_categories[]">
                                                            <?php foreach ($categoryOptions as $cat): if ($cat['id'] != 197): ?>
                                                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td><input type="number" step="0.01" name="split_amounts[]" required></td>
                                                    <td><button type="button" class="remove-split">−</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <button type="button" class="add-split">+ Add Split</button>
                                        <p class="split-warning" style="color: red; display: none;">⚠️ Split amounts must match total (<?= number_format($row['amount'], 2) ?>)</p>
                                    </div>

                                    <div class="actions">
                                        <button type="submit" name="action" value="categorise">Approve</button>
                                        <button type="submit" name="action" value="delete_staging">Delete</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<script>
function toggleSplitSection(select) {
    const selected = parseInt($(select).val());
    const form = $(select).closest('form');
    const split = form.find('.split-section');
    const transfer = form.find('.transfer-section');
    const linked = form.find('.linked-account-section');

    if (selected === 197) {
        split.show().find('input, select').prop('disabled', false);
        transfer.hide().find('select').prop('disabled', true);
        linked.hide().find('select').prop('disabled', true);
    } else if (selected === -1) {
        transfer.show().find('select').prop('disabled', false);
        split.hide().find('input, select').prop('disabled', true);

        // Trigger downstream logic on page load
        form.find('select[name="transfer_target"]').trigger('change');
    } else {
        split.hide().find('input, select').prop('disabled', true);
        transfer.hide().find('select').prop('disabled', true);
        linked.hide().find('select').prop('disabled', true);
    }
}

$(function () {
    const tabKey = 'review_active_tab';

    // Tab switching
    $('.tab-btn').click(function () {
        const tab = $(this).data('tab');
        localStorage.setItem(tabKey, tab);
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });

    const savedTab = localStorage.getItem(tabKey) || 'all';
    $('.tab-btn[data-tab="' + savedTab + '"]').click();

    // Initial toggle of split/transfer UI
    $('.category-select').each(function () {
        toggleSplitSection(this);
    });

    $('.category-select').change(function () {
        toggleSplitSection(this);
    });

    // Transfer target dropdown triggers linked account visibility
    $(document).on('change', 'select[name="transfer_target"]', function () {
        const form = $(this).closest('form');
        const wrapper = form.find('.linked-account-section');
        const val = $(this).val();

        if (val === 'one_sided') {
            wrapper.show();
            wrapper.find('select').prop('disabled', false);
        } else {
            wrapper.hide();
            wrapper.find('select').prop('disabled', true);
        }
    });

    // Ensure it triggers on load too
    $('select[name="transfer_target"]').each(function () {
        $(this).trigger('change');
    });

    // Split logic
    $('.add-split').click(function () {
        const table = $(this).siblings('table.split-table');
        const row = table.find('tbody tr:first').clone();
        row.find('input').val('');
        table.find('tbody').append(row);
    });

    $(document).on('click', '.remove-split', function () {
        const table = $(this).closest('table.split-table');
        if (table.find('tbody tr').length > 1) {
            $(this).closest('tr').remove();
        }
    });
});
</script>




</body>
</html>
<?php include '../layout/footer.php'; ?>
