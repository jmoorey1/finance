<?php include '../layout/header.php'; ?>
<?php
require_once '../config/db.php';
$conn = get_db_connection();

// Load full category hierarchy for dropdown rendering
$allCategories = $conn->query("
    SELECT c.id, c.name, c.type, c.parent_id, p.name AS parent_name
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    WHERE c.type IN ('expense', 'income')
    ORDER BY c.type, COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch staging transactions
$sql = "
    SELECT s.*, 
           p.scheduled_date, 
           p.description AS predicted_description,
           p.amount AS predicted_amount,
           t.id AS matched_transaction_id,
           t.date AS matched_transaction_date,
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

// Prepare helper queries
$payeeMatchStmt = $conn->prepare("
    SELECT payee_id
    FROM payee_patterns
    WHERE ? LIKE match_pattern
    ORDER BY LENGTH(match_pattern) DESC
    LIMIT 1
");
$topCatsStmt = $conn->prepare("
    SELECT category_id, c.name
    FROM (
        SELECT t.category_id
        FROM transactions t
        LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id
        WHERE ts.id IS NULL AND (t.description LIKE ? OR (? != 0 AND t.payee_id = ?))
        UNION ALL
        SELECT ts.category_id
        FROM transactions t
        JOIN transaction_splits ts ON ts.transaction_id = t.id
        WHERE t.description LIKE ? OR (? != 0 AND t.payee_id = ?)
    ) usage_data
    JOIN categories c ON c.id = usage_data.category_id
    WHERE c.type IN ('expense', 'income')
    GROUP BY category_id, c.name
    ORDER BY COUNT(*) DESC
    LIMIT 5
");


// Categorize transactions and attach top categories
$grouped = ['all' => [], 'new' => [], 'fulfills_prediction' => [], 'potential_duplicate' => []];

foreach ($rows as $row) {
    $row['top_categories'] = [];

    if ($row['status'] === 'new') {
        $descPattern = '%' . $row['description'] . '%';
        $desc = $row['description'];
        $payeeMatchStmt->execute([$desc]);
        $payee_id = (int) ($payeeMatchStmt->fetchColumn() ?? 0);

        $topCatsStmt->execute([
            $descPattern, $payee_id, $payee_id,
            $descPattern, $payee_id, $payee_id
        ]);

        $row['top_categories'] = $topCatsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $grouped['all'][] = $row;
    $grouped[$row['status']][] = $row;
}
?>

<h1>Review Staging Transactions</h1>

<div class="review-tabs">
    <?php foreach ($grouped as $key => $list): ?>
        <span class="tab-btn" data-tab="<?= $key ?>"><?= ucwords(str_replace('_', ' ', $key)) ?> (<?= count($list) ?>)</span>
    <?php endforeach; ?>
</div>

<?php if (!empty($rows)): ?>
<?php foreach ($grouped as $group => $entries): ?>
    <div class="tab-content" id="tab-<?= $group ?>">
        <?php if (empty($entries)): ?>
            <p>No transactions found.</p>
        <?php else: ?>
            <table class="review-table">
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
                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['status']))) ?></td>
                        <td>
                            <?php if ($row['status'] === 'fulfills_prediction'): ?>
                                <form method="post" action="review_actions.php">
                                    <input type="hidden" name="action" value="fulfill_prediction">
                                    <input type="hidden" name="staging_transaction_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="predicted_instance_id" value="<?= $row['predicted_instance_id'] ?>">
                                    <p class="note">⚡ Predicted: <?= htmlspecialchars($row['predicted_description']) ?> (<?= $row['scheduled_date'] ?>, £<?= number_format($row['predicted_amount'], 2) ?>)</p>
                                    <div class="actions">
                                        <button type="submit">Confirm Match</button>
                                        <button type="submit" name="action" value="reject_prediction">Not a Match</button>
                                    </div>
                                </form>
                            <?php elseif ($row['status'] === 'potential_duplicate'): ?>
                                <form method="post" action="review_actions.php">
                                    <input type="hidden" name="staging_transaction_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="matched_transaction_id" value="<?= $row['matched_transaction_id'] ?>">
                                    <p class="note">⚠️ Possibly matches: <?= htmlspecialchars($row['matched_transaction_desc']) ?> (<?= htmlspecialchars($row['matched_transaction_date']) ?>)</p>
                                    <div class="actions">
                                        <button type="submit" name="action" value="confirm_duplicate">Confirm Duplicate</button>
                                        <button type="submit" name="action" value="reject_duplicate">Not a Duplicate</button>
                                    </div>
                                </form>
                            <?php elseif ($row['status'] === 'new'): ?>
                                <form method="post" action="review_actions.php">
                                    <input type="hidden" name="staging_transaction_id" value="<?= $row['id'] ?>">

                                    <!-- Category Dropdown -->

									<label>Category:
										<select name="category_id" class="category-select" data-parent-id="<?= $row['id'] ?>">
											<?php if (isset($row['top_categories']) && is_array($row['top_categories']) && count($row['top_categories']) > 0): ?>
												<optgroup label="Suggested Categories">
												<?php foreach ($row['top_categories'] as $cat): ?>
													<option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
												<?php endforeach; ?>
												</optgroup>
											<?php endif; ?>

											<?php
											$lastType = null;
											foreach ($allCategories as $cat):
												if ($cat['type'] !== $lastType):
													if ($lastType !== null) echo "</optgroup>";
													echo "<optgroup label=\"" . ucfirst($cat['type']) . " Categories\">";
													$lastType = $cat['type'];
												endif;

												$indent = $cat['parent_id'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
												
												$alreadySuggested = isset($row['top_categories']) && is_array($row['top_categories']) ? array_column($row['top_categories'], 'category_id') : [];
												if (!in_array($cat['id'], $alreadySuggested)):
											?>
												<option value="<?= $cat['id'] ?>"><?= $indent . htmlspecialchars($cat['name']) ?></option>
											<?php
												endif;
											endforeach;
											echo "</optgroup>";
											?>
											<option value="197">-- Split/Multiple Categories --</option>
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
												$candidates_list = $candidates->fetchAll(PDO::FETCH_ASSOC);
												$candidates_count = count($candidates_list);
												foreach ($candidates_list as $match) {
													echo "<option value=\"staging_{$match['id']}\">[STAGING] {$match['description']} ({$match['date']}, {$match['amount']})</option>";
												}

												// Placeholder transactions (FIXED: fetch once, reuse)
												$placeholders = $conn->prepare("
													SELECT id, account_id, date, amount 
													FROM transactions 
													WHERE description = 'PLACEHOLDER' 
													  AND ABS(amount - ?) < 0.01 
													  AND date BETWEEN ? AND ?
												");
												$placeholders->execute([$opposite_amount, $start_date, $end_date]);
												$placeholders_list = $placeholders->fetchAll(PDO::FETCH_ASSOC);
												$placeholders_count = count($placeholders_list);

												foreach ($placeholders_list as $match) {
													echo "<option value=\"existing_{$match['id']}\">[PLACEHOLDER] Account {$match['account_id']} ({$match['date']}, {$match['amount']})</option>";
												}

												if ($candidates_count === 0 && $placeholders_count === 0) {
													echo "<option value=''>--Choose Transfer Type--</option>";
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
												$acctStmt = $conn->prepare("SELECT id, name FROM accounts WHERE id != ? and active=1");
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
															<?php if (!empty($row['top_categories'])): ?>
																<optgroup label="Suggested Categories">
																<?php foreach ($row['top_categories'] as $cat): ?>
																	<?php if ($cat['category_id'] != 197): ?>
																		<option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
																	<?php endif; ?>
																<?php endforeach; ?>
																</optgroup>
															<?php endif; ?>

															<?php
															$lastType = null;
															foreach ($allCategories as $cat):
																if ($cat['id'] == 197) continue;
																if ($cat['type'] !== $lastType):
																	if ($lastType !== null) echo "</optgroup>";
																	echo "<optgroup label=\"" . ucfirst($cat['type']) . " Categories\">";
																	$lastType = $cat['type'];
																endif;

																$alreadySuggested = isset($row['top_categories']) && is_array($row['top_categories']) ? array_column($row['top_categories'], 'category_id') : [];
																if (!in_array($cat['id'], $alreadySuggested)):
																	$indent = $cat['parent_id'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
															?>
																	<option value="<?= $cat['id'] ?>"><?= $indent . htmlspecialchars($cat['name']) ?></option>
															<?php
																endif;
															endforeach;
															echo "</optgroup>";
															?>
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

<?php else: ?>
            <p>No transactions found in any category.</p>
<?php endif; ?>

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

    // Explicitly activate the saved tab on page load (no reliance on .click())
    const savedTab = localStorage.getItem(tabKey) || 'all';
    $('.tab-btn').removeClass('active');
    $('.tab-btn[data-tab="' + savedTab + '"]').addClass('active');
    $('.tab-content').removeClass('active');
    $('#tab-' + savedTab).addClass('active');

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

<?php include '../layout/footer.php'; ?>
