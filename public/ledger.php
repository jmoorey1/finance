<?php
require_once '../config/db.php';
require_once '../scripts/get_accounts.php';
include '../layout/header.php';

$accounts = get_all_active_accounts($pdo);

// Default account = JOINT BILLS
$default_account = null;
foreach ($accounts as $acct) {
    if ($acct['name'] === 'JOINT BILLS') {
        $default_account = $acct['id'];
        break;
    }
}

// Inputs from query string
$selected_accounts = $_GET['accounts'] ?? [$default_account];
$start_date = $_GET['start'] ?? (new DateTimeImmutable('-30 days'))->format('Y-m-d');
$end_date = $_GET['end'] ?? (new DateTimeImmutable('today'))->format('Y-m-d');
$selected_categories = $_GET['category_id'] ?? [];
$parent_filter = $_GET['parent_id'] ?? '';
$search_term = trim($_GET['description'] ?? '');
$search_like = '%' . $search_term . '%';
// Add to your existing filter logic:
$projectFilter = '';
if (isset($_GET['project_id']) && is_numeric($_GET['project_id'])) {
    $project_id = (int) $_GET['project_id'];
    $projectFilter = "AND (t.project_id = $project_id)";
}
$earmarkFilter = '';
if (isset($_GET['earmark_id']) && is_numeric($_GET['earmark_id'])) {
$earmark_id = (int) $_GET['earmark_id'];
$earmarkFilter = "AND (t.earmark_id = $earmark_id)";
}


// Load categories
$categories = $pdo->query("SELECT id, name, parent_id FROM categories WHERE type IN ('income','expense') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$parents = array_filter($categories, fn($c) => is_null($c['parent_id']));
$children = array_filter($categories, fn($c) => !is_null($c['parent_id']));

// If a parent is selected, add it and its children to the filter
if ($parent_filter !== '') {
    $selected_categories[] = $parent_filter; // include parent itself
    $child_ids = array_column(
        array_filter($children, fn($c) => $c['parent_id'] == $parent_filter),
        'id'
    );
    $selected_categories = array_merge($selected_categories, $child_ids);
}

// Remove duplicates
$selected_categories = array_unique($selected_categories);

$account_placeholders = implode(',', array_fill(0, count($selected_accounts), '?'));
$category_clause = '';
$category_placeholders = [];

if (!empty($selected_categories)) {
    $category_placeholders = array_fill(0, count($selected_categories), '?');
    $cat_placeholder_str = implode(',', $category_placeholders);
    $category_clause = "
        AND (
            (s.category_id IS NOT NULL AND s.category_id IN ($cat_placeholder_str)) OR
            (s.category_id IS NULL AND t.category_id IN ($cat_placeholder_str))
        )
    ";
}

// SQL query
$query = "
	SELECT 
		CASE
				WHEN s.id IS NOT NULL THEN 'Split'
				ELSE 'Actual'
		END AS source, t.id, t.date, t.account_id,
		CASE 
			WHEN s.id IS NOT NULL THEN s.amount
			ELSE t.amount
		END AS amount, coalesce(p.name, t.description) as description, IFNULL(cs.name, ct.name) AS category, IFNULL(cs.type, ct.type) as cat_type, (case when cs.parent_id is null and ct.parent_id is null then 0 else 1 end) as sub_flag, IFNULL(cs.id, ct.id) as cat_id
	FROM transactions t
	LEFT JOIN transaction_splits s ON t.id = s.transaction_id
	LEFT JOIN categories cs ON cs.id = s.category_id
	LEFT JOIN categories ct ON ct.id = t.category_id
    left join payees p on p.id = t.payee_id
    WHERE t.account_id IN ($account_placeholders)
      AND t.date BETWEEN ? AND ?
      $category_clause
      $projectFilter
      $earmarkFilter
      AND COALESCE(p.name, t.description) LIKE ?
";

if (!isset($_GET['project_id'])) {
$query .= "
    UNION ALL
    SELECT 'Predicted' AS source, '' as id, p.scheduled_date AS date, p.from_account_id, p.amount, COALESCE(pay.name, p.description) as description, c.name as category, c.type as cat_type, (case when c.parent_id is null then 0 else 1 end) as sub_flag, c.id as cat_id
    FROM predicted_instances p
    JOIN categories c ON p.category_id = c.id
	left join payee_patterns pp on p.description like pp.match_pattern
	left join payees pay on pp.payee_id = pay.id
    WHERE c.type IN ('income', 'expense')
      AND p.from_account_id IN ($account_placeholders)
      AND p.scheduled_date BETWEEN ? AND ?
      AND COALESCE(pay.name, p.description) LIKE ?
";

if (!empty($selected_categories)) {
    $query .= " AND p.category_id IN (" . implode(',', $category_placeholders) . ")";
}

$query .= "
    UNION ALL
    SELECT 'Predicted' AS source, '' as id, p.scheduled_date AS date, p.from_account_id, -p.amount AS amount, COALESCE(pay.name, p.description) as description, c.name as category, c.type as cat_type, (case when c.parent_id is null then 0 else 1 end) as sub_flag, c.id as cat_id
    FROM predicted_instances p
    JOIN categories c ON p.category_id = c.id
	left join payee_patterns pp on p.description like pp.match_pattern
	left join payees pay on pp.payee_id = pay.id
    WHERE c.type = 'transfer'
      AND p.from_account_id IN ($account_placeholders)
      AND p.scheduled_date BETWEEN ? AND ?
      AND COALESCE(pay.name, p.description) LIKE ?
";

if (!empty($selected_categories)) {
    $query .= " AND p.category_id IN (" . implode(',', $category_placeholders) . ")";
}

$query .= "
    UNION ALL
    SELECT 'Predicted' AS source, '' as id, p.scheduled_date AS date, p.to_account_id, p.amount AS amount, COALESCE(pay.name, p.description) as description, c.name as category, c.type as cat_type, (case when c.parent_id is null then 0 else 1 end) as sub_flag, c.id as cat_id
    FROM predicted_instances p
    JOIN categories c ON p.category_id = c.id
	left join payee_patterns pp on p.description like pp.match_pattern
	left join payees pay on pp.payee_id = pay.id
    WHERE p.to_account_id IN ($account_placeholders)
      AND p.scheduled_date BETWEEN ? AND ?
      AND COALESCE(pay.name, p.description) LIKE ?
";

if (!empty($selected_categories)) {
    $query .= " AND p.category_id IN (" . implode(',', $category_placeholders) . ")";
}
}
$query .= " ORDER BY date ASC";

// Build parameters

if (!isset($_GET['project_id'])) {
$params = array_merge(
    $selected_accounts, [$start_date, $end_date],
    $selected_categories, $selected_categories, [$search_like], // actuals
    $selected_accounts, [$start_date, $end_date], [$search_like], $selected_categories, // predicted income/expense
    $selected_accounts, [$start_date, $end_date], [$search_like], $selected_categories, // predicted transfer (from)
    $selected_accounts, [$start_date, $end_date], [$search_like], $selected_categories  // predicted transfer (to)
);
} else {
$params = array_merge(
    $selected_accounts, [$start_date, $end_date],
    $selected_categories, $selected_categories, [$search_like] // actuals
);
}


$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1 class="mb-4">📒 Ledger Viewer</h1>

<form method="GET" class="mb-4">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Account(s)</label>
            <select name="accounts[]" class="form-select" multiple size="5">
                <?php foreach ($accounts as $acct): ?>
                    <option value="<?= $acct['id'] ?>" <?= in_array($acct['id'], $selected_accounts) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($acct['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" name="start" class="form-control" value="<?= $start_date ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" name="end" class="form-control" value="<?= $end_date ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Top-Level Category</label>
            <select name="parent_id" class="form-select">
                <option value="">— All —</option>
                <?php foreach ($parents as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $parent_filter == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Description Contains</label>
            <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($search_term) ?>">
        </div>
        <div class="col-md-12 text-end">
            <button type="submit" class="btn btn-primary mt-2">Filter</button>
        </div>
    </div>
</form>

<?php if ($ledger): ?>
	<table class="table table-striped table-sm align-middle">
		<thead>
			<tr>
				<th>Date</th>
				<th>Account</th>
				<th>Description</th>
				<th>Category</th>
				<th class="text-end">Amount</th>
				<th>Source</th>
				<th></th> <!-- Pencil column -->
			</tr>
		</thead>
		<tbody>
			<?php foreach ($ledger as $entry): ?>
				<?php
					$acct_name = '';
					foreach ($accounts as $acct) {
						if ($acct['id'] == $entry['account_id']) {
							$acct_name = $acct['name'];
							break;
						}
					}
				?>
				<tr>
					<td><?= $entry['date'] ?></td>
					<td><?= htmlspecialchars($acct_name) ?></td>
					<td><?= htmlspecialchars($entry['description']) ?></td>
					<td>
						<?php if ($entry['cat_type'] !== 'transfer'): ?>
							<?php if (!empty($entry['sub_flag']) && $entry['sub_flag'] == 1): ?>
								<a href="subcategory_report.php?subcategory_id=<?= $entry['cat_id'] ?>">
									<?= htmlspecialchars($entry['category']) ?>
								</a>
							<?php else: ?>
								<a href="category_report.php?category_id=<?= $entry['cat_id'] ?>">
									<?= htmlspecialchars($entry['category']) ?>
								</a>
							<?php endif; ?>
						<?php else: ?>
							<?= htmlspecialchars($entry['category']) ?>
						<?php endif; ?>
					</td>
					<td class="text-end <?= $entry['amount'] < 0 ? 'text-danger' : '' ?>">
						£<?= number_format($entry['amount'], 2) ?>
					</td>
					<td><?= $entry['source'] ?></td>
					<td>
						<?= $entry['id'] != '' ? '<a href="transaction_edit.php?id=' . $entry['id'] . '&redirect=' . urlencode($_SERVER['REQUEST_URI']) .'" title="Edit Transaction">✏️</a>' : '' ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php else: ?>
    <p>No results found for the selected criteria.</p>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>
