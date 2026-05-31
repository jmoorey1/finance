<?php
require_once '../config/db.php';
require_once '../scripts/get_accounts.php';

$pdo = get_db_connection();

// Load active accounts once
$accounts = get_all_active_accounts($pdo);

// Resolve project / earmark first, because they influence default account scope
$ledger_title = '';

$project_id = null;
if (isset($_GET['project_id']) && is_numeric($_GET['project_id'])) {
    $project_id = (int)$_GET['project_id'];
    $project_name_stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
    $project_name_stmt->execute([$project_id]);
    $project = $project_name_stmt->fetch(PDO::FETCH_ASSOC);
    $ledger_title = $project ? $project['name'] : '';
}

$earmark_id = null;
if (isset($_GET['earmark_id']) && is_numeric($_GET['earmark_id'])) {
    $earmark_id = (int)$_GET['earmark_id'];
    $earmark_name_stmt = $pdo->prepare("SELECT name FROM earmarks WHERE id = ?");
    $earmark_name_stmt->execute([$earmark_id]);
    $earmark = $earmark_name_stmt->fetch(PDO::FETCH_ASSOC);
    $ledger_title = $earmark ? $earmark['name'] : '';
}

// Default account = JOINT BILLS, unless a project/earmark ledger is being opened,
// in which case default to ALL active accounts.
$default_account = null;
foreach ($accounts as $acct) {
    if ($acct['name'] === 'JOINT BILLS') {
        $default_account = (int)$acct['id'];
        break;
    }
}
if ($default_account === null && !empty($accounts)) {
    $default_account = (int)$accounts[0]['id'];
}

$all_active_account_ids = array_map(fn($a) => (int)$a['id'], $accounts);
$has_explicit_accounts = isset($_GET['accounts']) && is_array($_GET['accounts']) && count($_GET['accounts']) > 0;

// Inputs from query string
if ($has_explicit_accounts) {
    $selected_accounts = array_map('intval', (array)$_GET['accounts']);
    $selected_accounts = array_values(array_filter($selected_accounts, fn($v) => $v > 0));
} else {
    if ($project_id !== null || $earmark_id !== null) {
        $selected_accounts = $all_active_account_ids;
    } else {
        $selected_accounts = $default_account !== null ? [$default_account] : [];
    }
}

if (empty($selected_accounts) && $default_account !== null) {
    $selected_accounts = [$default_account];
}

$start_date = $_GET['start'] ?? (new DateTimeImmutable('-30 days'))->format('Y-m-d');
$end_date = $_GET['end'] ?? (new DateTimeImmutable('today'))->format('Y-m-d');
$selected_categories = array_map('intval', (array)($_GET['category_id'] ?? []));
$parent_filter = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int)$_GET['parent_id'] : null;
$search_term = trim($_GET['description'] ?? '');
$search_like = '%' . $search_term . '%';

// Load categories
$categories = $pdo->query("
    SELECT id, name, parent_id
    FROM categories
    WHERE type IN ('income','expense')
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$parents = array_filter($categories, fn($c) => is_null($c['parent_id']));
$children = array_filter($categories, fn($c) => !is_null($c['parent_id']));

// If a parent is selected, add it and its children to the filter
if ($parent_filter !== null) {
    $selected_categories[] = $parent_filter;

    $child_ids = array_column(
        array_filter($children, fn($c) => (int)$c['parent_id'] === $parent_filter),
        'id'
    );
    $selected_categories = array_merge($selected_categories, array_map('intval', $child_ids));

    if ($ledger_title === '') {
        $parent_name_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $parent_name_stmt->execute([$parent_filter]);
        $parent = $parent_name_stmt->fetch(PDO::FETCH_ASSOC);
        $ledger_title = $parent ? $parent['name'] : '';
    }
}

$selected_categories = array_values(array_unique(array_filter($selected_categories, fn($v) => $v > 0)));

$account_placeholders = implode(',', array_fill(0, count($selected_accounts), '?'));

$query = "
    SELECT
        ll.source,
        ll.transaction_id AS id,
        ll.line_date AS date,
        ll.account_id,
        ll.account_name,
        ll.amount,
        ll.description,
        ll.category_name AS category,
        ll.category_type AS cat_type,
        ll.sub_flag,
        ll.category_id AS cat_id,
        ll.line_role,
        ll.transaction_split_id,
        ll.predicted_instance_id
    FROM ledger_lines ll
    WHERE ll.account_id IN ($account_placeholders)
      AND ll.line_date BETWEEN ? AND ?
      AND ll.description LIKE ?
      AND (ll.is_prediction = 0 OR ll.line_date >= CURDATE())
";

$params = array_merge($selected_accounts, [$start_date, $end_date, $search_like]);

if (!empty($selected_categories)) {
    $category_placeholders = implode(',', array_fill(0, count($selected_categories), '?'));
    $query .= " AND ll.category_id IN ($category_placeholders)";
    $params = array_merge($params, $selected_categories);
}

// Project / earmark filtering is applied against ledger_lines so split rows
// can be matched using split-level attribution with parent fallback from the view.
if ($project_id !== null) {
    $query .= " AND ll.project_id = ?";
    $params[] = $project_id;
}

if ($earmark_id !== null) {
    $query .= " AND ll.earmark_id = ?";
    $params[] = $earmark_id;
}

$query .= "
    ORDER BY
        ll.line_date ASC,
        COALESCE(ll.transaction_id, 0) ASC,
        COALESCE(ll.transaction_split_id, 0) ASC,
        COALESCE(ll.predicted_instance_id, 0) ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../layout/header.php';
?>

<h1 class="mb-4">📒 Ledger Viewer<?= $ledger_title !== '' ? ' : ' . htmlspecialchars($ledger_title) : '' ?></h1>

<form method="GET" class="mb-4">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Account(s)</label>
            <select name="accounts[]" class="form-select" multiple size="5">
                <?php foreach ($accounts as $acct): ?>
                    <option value="<?= (int)$acct['id'] ?>" <?= in_array((int)$acct['id'], $selected_accounts, true) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($acct['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Top-Level Category</label>
            <select name="parent_id" class="form-select">
                <option value="">— All —</option>
                <?php foreach ($parents as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $parent_filter === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Description Contains</label>
            <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($search_term) ?>">
        </div>

        <?php if ($project_id !== null): ?>
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <?php endif; ?>

        <?php if ($earmark_id !== null): ?>
            <input type="hidden" name="earmark_id" value="<?= $earmark_id ?>">
        <?php endif; ?>

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
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php $total = 0; ?>
            <?php foreach ($ledger as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['date']) ?></td>
                    <td><?= htmlspecialchars($entry['account_name']) ?></td>
                    <td><?= htmlspecialchars($entry['description']) ?></td>
                    <td>
                        <?php if ($entry['cat_type'] !== 'transfer'): ?>
                            <?php if (!empty($entry['sub_flag']) && (int)$entry['sub_flag'] === 1): ?>
                                <a href="subcategory_report.php?subcategory_id=<?= (int)$entry['cat_id'] ?>">
                                    <?= htmlspecialchars($entry['category']) ?>
                                </a>
                            <?php else: ?>
                                <a href="category_report.php?category_id=<?= (int)$entry['cat_id'] ?>">
                                    <?= htmlspecialchars($entry['category']) ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= htmlspecialchars($entry['category']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end <?= (float)$entry['amount'] < 0 ? 'text-danger' : '' ?>">
                        £<?= number_format((float)$entry['amount'], 2) ?>
                        <?php $total += (float)$entry['amount']; ?>
                    </td>
                    <td><?= htmlspecialchars($entry['source']) ?></td>
                    <td>
                        <?= !empty($entry['id'])
                            ? '<a href="transaction_edit.php?id=' . (int)$entry['id'] . '&redirect=' . urlencode($_SERVER['REQUEST_URI']) . '" title="Edit Transaction">✏️</a>'
                            : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4"><strong>TOTAL</strong></td>
                <td class="text-end <?= $total < 0 ? 'text-danger' : '' ?>"><strong>£<?= number_format($total, 2) ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <p>No results found for the selected criteria.</p>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>