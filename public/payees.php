<?php
require_once '../config/db.php';
auth_session_start();
require_once '../scripts/payee_matching.php';

function redirect_self(): void
{
    header('Location: payees.php');
    exit;
}

function flash_and_redirect(string $message, string $type = 'success'): void
{
    $_SESSION['payees_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
    redirect_self();
}

$testDescription = '';
$testResult = null;
$testNoMatch = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_payee') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Payee name is required.');
            }

            $stmt = $pdo->prepare("INSERT INTO payees (name) VALUES (?)");
            $stmt->execute([$name]);

            flash_and_redirect("✅ Payee created.");
        }

        if ($action === 'rename_payee') {
            $payeeId = (int)($_POST['payee_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));

            if ($payeeId <= 0) {
                throw new RuntimeException('Invalid payee.');
            }
            if ($name === '') {
                throw new RuntimeException('Payee name is required.');
            }

            $stmt = $pdo->prepare("UPDATE payees SET name = ? WHERE id = ?");
            $stmt->execute([$name, $payeeId]);

            flash_and_redirect("✅ Payee updated.");
        }

        if ($action === 'delete_payee') {
            $payeeId = (int)($_POST['payee_id'] ?? 0);
            if ($payeeId <= 0) {
                throw new RuntimeException('Invalid payee.');
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE payee_id = ?");
            $stmt->execute([$payeeId]);
            $txnCount = (int)$stmt->fetchColumn();

            if ($txnCount > 0) {
                throw new RuntimeException('This payee is already used on transactions and cannot be deleted.');
            }

            $stmt = $pdo->prepare("DELETE FROM payees WHERE id = ?");
            $stmt->execute([$payeeId]);

            flash_and_redirect("🗑️ Payee deleted.");
        }

        if ($action === 'add_pattern') {
            $payeeId = (int)($_POST['payee_id'] ?? 0);
            $pattern = trim((string)($_POST['match_pattern'] ?? ''));
            $priority = (int)($_POST['priority'] ?? 0);

            if ($payeeId <= 0) {
                throw new RuntimeException('Invalid payee.');
            }
            if ($pattern === '') {
                throw new RuntimeException('Pattern is required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO payee_patterns (payee_id, match_pattern, priority)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$payeeId, $pattern, $priority]);

            flash_and_redirect("✅ Pattern added.");
        }

        if ($action === 'update_pattern') {
            $patternId = (int)($_POST['pattern_id'] ?? 0);
            $pattern = trim((string)($_POST['match_pattern'] ?? ''));
            $priority = (int)($_POST['priority'] ?? 0);

            if ($patternId <= 0) {
                throw new RuntimeException('Invalid pattern.');
            }
            if ($pattern === '') {
                throw new RuntimeException('Pattern is required.');
            }

            $stmt = $pdo->prepare("
                UPDATE payee_patterns
                SET match_pattern = ?, priority = ?
                WHERE id = ?
            ");
            $stmt->execute([$pattern, $priority, $patternId]);

            flash_and_redirect("✅ Pattern updated.");
        }

        if ($action === 'delete_pattern') {
            $patternId = (int)($_POST['pattern_id'] ?? 0);
            if ($patternId <= 0) {
                throw new RuntimeException('Invalid pattern.');
            }

            $stmt = $pdo->prepare("DELETE FROM payee_patterns WHERE id = ?");
            $stmt->execute([$patternId]);

            flash_and_redirect("🗑️ Pattern deleted.");
        }

        if ($action === 'test_match') {
            $testDescription = trim((string)($_POST['test_description'] ?? ''));
            if ($testDescription !== '') {
                $testResult = resolve_best_payee_match($pdo, $testDescription);
                $testNoMatch = $testResult === null;
            }
        }
    } catch (Throwable $e) {
        if ($action === 'test_match') {
            $testResult = null;
            $testNoMatch = false;
            $_SESSION['payees_flash'] = [
                'message' => '❌ Pattern test failed: ' . $e->getMessage(),
                'type' => 'danger',
            ];
        } else {
            flash_and_redirect('❌ ' . $e->getMessage(), 'danger');
        }
    }
}

$payeesStmt = $pdo->query("
    SELECT
        p.id,
        p.name,
        COUNT(DISTINCT pp.id) AS pattern_count,
        COUNT(DISTINCT t.id) AS transaction_count,
        MAX(t.date) AS last_used
    FROM payees p
    LEFT JOIN payee_patterns pp ON pp.payee_id = p.id
    LEFT JOIN transactions t ON t.payee_id = p.id
    GROUP BY p.id, p.name
    ORDER BY p.name ASC
");
$payees = $payeesStmt->fetchAll(PDO::FETCH_ASSOC);

$patternsStmt = $pdo->query("
    SELECT
        pp.id,
        pp.payee_id,
        pp.match_pattern,
        pp.priority
    FROM payee_patterns pp
    JOIN payees p ON p.id = pp.payee_id
    ORDER BY
        p.name ASC,
        pp.priority DESC,
        CHAR_LENGTH(REPLACE(REPLACE(pp.match_pattern, '%', ''), '_', '')) DESC,
        pp.match_pattern ASC
");
$patterns = $patternsStmt->fetchAll(PDO::FETCH_ASSOC);

$patternsByPayee = [];
foreach ($patterns as $pattern) {
    $patternsByPayee[(int)$pattern['payee_id']][] = $pattern;
}

include '../layout/header.php';

if (isset($_SESSION['payees_flash'])) {
    $flash = $_SESSION['payees_flash'];
    unset($_SESSION['payees_flash']);

    $type = htmlspecialchars((string)($flash['type'] ?? 'success'));
    $message = htmlspecialchars((string)($flash['message'] ?? ''));
    echo "<div class='alert alert-{$type}'>{$message}</div>";
}
?>

<h1 class="mb-4">🏷️ Payees & Patterns</h1>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Add New Payee</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="create_payee">
                    <div class="mb-3">
                        <label class="form-label">Payee Name</label>
                        <input type="text" name="name" class="form-control" maxlength="100" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Payee</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Pattern Test</div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="test_match">
                    <div class="mb-3">
                        <label class="form-label">Sample Description</label>
                        <input type="text" name="test_description" class="form-control" value="<?= htmlspecialchars($testDescription) ?>">
                    </div>
                    <button type="submit" class="btn btn-outline-primary">Test Match</button>
                </form>

                <?php if ($testResult): ?>
                    <div class="alert alert-success mb-0">
                        <div><strong>Matched Payee:</strong> <?= htmlspecialchars($testResult['payee_name']) ?></div>
                        <div><strong>Pattern:</strong> <code><?= htmlspecialchars($testResult['match_pattern']) ?></code></div>
                        <div><strong>Priority:</strong> <?= (int)$testResult['priority'] ?></div>
                    </div>
                <?php elseif ($testNoMatch): ?>
                    <div class="alert alert-warning mb-0">
                        No payee pattern matched that description.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info">
    Matching precedence is now explicit: higher <strong>priority</strong> wins first, then more specific patterns.
</div>

<?php if (empty($payees)): ?>
    <div class="alert alert-warning">No payees exist yet.</div>
<?php else: ?>
    <?php foreach ($payees as $payee): ?>
        <?php
            $payeeId = (int)$payee['id'];
            $lastUsed = $payee['last_used'] ? (new DateTime($payee['last_used']))->format('j M Y') : '—';
            $payeePatterns = $patternsByPayee[$payeeId] ?? [];
            $canDelete = (int)$payee['transaction_count'] === 0;
        ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong><?= htmlspecialchars($payee['name']) ?></strong>
                    <span class="text-muted ms-2">
                        <?= (int)$payee['pattern_count'] ?> pattern<?= (int)$payee['pattern_count'] !== 1 ? 's' : '' ?>,
                        <?= (int)$payee['transaction_count'] ?> transaction<?= (int)$payee['transaction_count'] !== 1 ? 's' : '' ?>,
                        last used <?= htmlspecialchars($lastUsed) ?>
                    </span>
                </div>
            </div>

            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <h5>Edit Payee</h5>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="action" value="rename_payee">
                            <input type="hidden" name="payee_id" value="<?= $payeeId ?>">
                            <div class="mb-2">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" maxlength="100" value="<?= htmlspecialchars($payee['name']) ?>" required>
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-sm">Save Name</button>
                        </form>

                        <form method="post" onsubmit="return confirm('Delete this payee? Patterns will also be removed.');">
                            <input type="hidden" name="action" value="delete_payee">
                            <input type="hidden" name="payee_id" value="<?= $payeeId ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" <?= $canDelete ? '' : 'disabled' ?>>
                                Delete Payee
                            </button>
                            <?php if (!$canDelete): ?>
                                <div class="form-text">Cannot delete a payee that is already used on transactions.</div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="col-lg-7">
                        <h5>Add Pattern</h5>
                        <form method="post" class="row g-2 mb-4">
                            <input type="hidden" name="action" value="add_pattern">
                            <input type="hidden" name="payee_id" value="<?= $payeeId ?>">
                            <div class="col-md-7">
                                <label class="form-label">Pattern</label>
                                <input type="text" name="match_pattern" class="form-control" maxlength="255" placeholder="%TESCO%" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Priority</label>
                                <input type="number" name="priority" class="form-control" value="0" required>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-success btn-sm w-100">Add</button>
                            </div>
                        </form>

                        <h5>Patterns</h5>
                        <?php if (empty($payeePatterns)): ?>
                            <p class="text-muted mb-0">No patterns yet for this payee.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Pattern</th>
                                            <th>Priority</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payeePatterns as $pattern): ?>
                                            <tr>
                                                <td><?= (int)$pattern['id'] ?></td>
                                                <td colspan="3">
                                                    <form method="post" class="row g-2 align-items-center">
                                                        <input type="hidden" name="action" value="update_pattern">
                                                        <input type="hidden" name="pattern_id" value="<?= (int)$pattern['id'] ?>">

                                                        <div class="col-md-7">
                                                            <input type="text" name="match_pattern" class="form-control form-control-sm" maxlength="255" value="<?= htmlspecialchars($pattern['match_pattern']) ?>" required>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <input type="number" name="priority" class="form-control form-control-sm" value="<?= (int)$pattern['priority'] ?>" required>
                                                        </div>
                                                        <div class="col-md-3 d-flex gap-2">
                                                            <button type="submit" class="btn btn-outline-primary btn-sm">Save</button>
                                                    </form>
                                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this pattern?');">
                                                                <input type="hidden" name="action" value="delete_pattern">
                                                                <input type="hidden" name="pattern_id" value="<?= (int)$pattern['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                            </form>
                                                        </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>
