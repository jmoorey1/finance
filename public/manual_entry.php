<?php
require_once '../config/db.php';
require_once '../scripts/lib/transfer_group_helpers.php';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$success = null;
$error = null;

$singleForm = [
    'date' => date('Y-m-d'),
    'account_id' => '',
    'category_id' => '',
    'amount' => '',
    'description' => '',
];

$transferForm = [
    'date' => date('Y-m-d'),
    'from_account_id' => '',
    'to_account_id' => '',
    'amount' => '',
    'description' => '',
];

// Fetch accounts and categories
$accounts = $pdo->query("
    SELECT id, name, type
    FROM accounts
    WHERE active = 1
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("
    SELECT c.id, c.name, c.type, c.parent_id, p.name AS parent_name
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    WHERE c.type IN ('income', 'expense')
    ORDER BY c.type, COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string)($_POST['form_type'] ?? '');

    try {
        $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
        $description = trim((string)($_POST['description'] ?? ''));
        $amountRaw = trim((string)($_POST['amount'] ?? ''));

        if ($formType === 'single') {
            $singleForm = [
                'date' => $date,
                'account_id' => (string)($_POST['account_id'] ?? ''),
                'category_id' => (string)($_POST['category_id'] ?? ''),
                'amount' => $amountRaw,
                'description' => $description,
            ];
        } elseif ($formType === 'transfer') {
            $transferForm = [
                'date' => $date,
                'from_account_id' => (string)($_POST['from_account_id'] ?? ''),
                'to_account_id' => (string)($_POST['to_account_id'] ?? ''),
                'amount' => $amountRaw,
                'description' => $description,
            ];
        }

        if ($formType !== 'single' && $formType !== 'transfer') {
            throw new RuntimeException('Invalid form submission.');
        }

        try {
            $parsedDate = new DateTimeImmutable($date);
            $date = $parsedDate->format('Y-m-d');
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid date.');
        }

        if ($description === '') {
            throw new RuntimeException('Description is required.');
        }

        if ($amountRaw === '' || !is_numeric($amountRaw)) {
            throw new RuntimeException('Amount must be a valid number.');
        }

        $amount = round((float)$amountRaw, 2);

        $pdo->beginTransaction();

        if ($formType === 'single') {
            $accountId = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
            $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

            if ($accountId <= 0) {
                throw new RuntimeException('Account is required.');
            }
            if ($categoryId <= 0) {
                throw new RuntimeException('Category is required.');
            }
            if ($amount == 0.0) {
                throw new RuntimeException('Amount cannot be zero.');
            }

            $stmt = $pdo->prepare("
                SELECT type
                FROM accounts
                WHERE id = ?
                  AND active = 1
                LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $accountType = $stmt->fetchColumn();

            if ($accountType === false) {
                throw new RuntimeException('Selected account does not exist or is inactive.');
            }

            $stmt = $pdo->prepare("
                SELECT type
                FROM categories
                WHERE id = ?
                  AND type IN ('income', 'expense')
                LIMIT 1
            ");
            $stmt->execute([$categoryId]);
            $categoryType = $stmt->fetchColumn();

            if ($categoryType === false) {
                throw new RuntimeException('Selected category does not exist.');
            }

            $transactionType = match ((string)$accountType) {
                'credit' => ($amount > 0 ? 'credit' : 'charge'),
                default => ($amount > 0 ? 'deposit' : 'withdrawal'),
            };

            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    account_id,
                    date,
                    description,
                    amount,
                    type,
                    category_id
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $accountId,
                $date,
                $description,
                $amount,
                $transactionType,
                $categoryId,
            ]);
        }

        if ($formType === 'transfer') {
            $fromId = isset($_POST['from_account_id']) ? (int)$_POST['from_account_id'] : 0;
            $toId = isset($_POST['to_account_id']) ? (int)$_POST['to_account_id'] : 0;
            $transferAmount = abs($amount);

            if ($fromId <= 0 || $toId <= 0) {
                throw new RuntimeException('Both transfer accounts are required.');
            }
            if ($fromId === $toId) {
                throw new RuntimeException('Cannot transfer between the same account.');
            }
            if ($transferAmount == 0.0) {
                throw new RuntimeException('Transfer amount must be greater than zero.');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM accounts
                WHERE id = ?
                  AND active = 1
            ");
            $stmt->execute([$fromId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('From account does not exist or is inactive.');
            }

            $stmt->execute([$toId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('To account does not exist or is inactive.');
            }

            $groupId = finance_create_transfer_group(
                $pdo,
                'Manual transfer: ' . $description,
                $fromId,
                $toId,
                $transferAmount,
                $date,
                'complete'
            );

            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    account_id,
                    date,
                    description,
                    amount,
                    type,
                    category_id,
                    transfer_group_id
                ) VALUES (?, ?, ?, ?, 'transfer', NULL, ?)
            ");

            // From side
            $stmt->execute([
                $fromId,
                $date,
                $description,
                -$transferAmount,
                $groupId,
            ]);

            // To side
            $stmt->execute([
                $toId,
                $date,
                $description,
                $transferAmount,
                $groupId,
            ]);
        }

        $pdo->commit();
        $success = 'Transaction successfully added.';

        $singleForm = [
            'date' => date('Y-m-d'),
            'account_id' => '',
            'category_id' => '',
            'amount' => '',
            'description' => '',
        ];

        $transferForm = [
            'date' => date('Y-m-d'),
            'from_account_id' => '',
            'to_account_id' => '',
            'amount' => '',
            'description' => '',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error: ' . $e->getMessage();
    }
}

include '../layout/header.php';
?>

<h1>Manually Add Transactions</h1>

<?php if ($success): ?>
    <div class="message success"><?= h($success) ?></div>
<?php elseif ($error): ?>
    <div class="message error"><?= h($error) ?></div>
<?php endif; ?>

<!-- Single Transaction Form -->
<form method="POST" class="form-section">
    <?= csrf_input() ?>
    <input type="hidden" name="form_type" value="single" />
    <h2>Add Single Transaction</h2>

    <div class="form-group">
        <label for="single_date">Date</label>
        <input id="single_date" type="date" name="date" value="<?= h($singleForm['date']) ?>" required />
    </div>

    <div class="form-group">
        <label for="account_id">Account</label>
        <select id="account_id" name="account_id" required>
            <option value="">Select account</option>
            <?php foreach ($accounts as $acct): ?>
                <option value="<?= (int)$acct['id'] ?>" <?= ((string)$acct['id'] === (string)$singleForm['account_id']) ? 'selected' : '' ?>>
                    <?= h($acct['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required>
            <option value="">Select category</option>
            <?php
            $lastType = null;
            foreach ($categories as $cat):
                if ($cat['type'] !== $lastType):
                    if ($lastType !== null) echo "</optgroup>";
                    echo '<optgroup label="' . h(ucfirst((string)$cat['type']) . ' Categories') . '">';
                    $lastType = $cat['type'];
                endif;

                $label = $cat['parent_id']
                    ? ((string)$cat['parent_name'] . ' : ' . (string)$cat['name'])
                    : (string)$cat['name'];
            ?>
                <option value="<?= (int)$cat['id'] ?>" <?= ((string)$cat['id'] === (string)$singleForm['category_id']) ? 'selected' : '' ?>>
                    <?= h($label) ?>
                </option>
            <?php endforeach; ?>
            <?php if ($lastType !== null): ?>
                </optgroup>
            <?php endif; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="single_amount">Amount</label>
        <input id="single_amount" type="number" name="amount" step="0.01" value="<?= h($singleForm['amount']) ?>" required />
    </div>

    <div class="form-group">
        <label for="single_description">Description</label>
        <input id="single_description" type="text" name="description" value="<?= h($singleForm['description']) ?>" required />
    </div>

    <button class="submit-btn" type="submit">Add Transaction</button>
</form>

<!-- Transfer Form -->
<form method="POST" class="form-section">
    <?= csrf_input() ?>
    <input type="hidden" name="form_type" value="transfer" />
    <h2>Add Transfer Between Accounts</h2>

    <div class="form-group">
        <label for="transfer_date">Date</label>
        <input id="transfer_date" type="date" name="date" value="<?= h($transferForm['date']) ?>" required />
    </div>

    <div class="form-group">
        <label for="from_account_id">From Account</label>
        <select id="from_account_id" name="from_account_id" required>
            <option value="">Select account</option>
            <?php foreach ($accounts as $acct): ?>
                <option value="<?= (int)$acct['id'] ?>" <?= ((string)$acct['id'] === (string)$transferForm['from_account_id']) ? 'selected' : '' ?>>
                    <?= h($acct['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="to_account_id">To Account</label>
        <select id="to_account_id" name="to_account_id" required>
            <option value="">Select account</option>
            <?php foreach ($accounts as $acct): ?>
                <option value="<?= (int)$acct['id'] ?>" <?= ((string)$acct['id'] === (string)$transferForm['to_account_id']) ? 'selected' : '' ?>>
                    <?= h($acct['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="transfer_amount">Amount</label>
        <input id="transfer_amount" type="number" name="amount" step="0.01" value="<?= h($transferForm['amount']) ?>" required />
    </div>

    <div class="form-group">
        <label for="transfer_description">Description</label>
        <input id="transfer_description" type="text" name="description" value="<?= h($transferForm['description']) ?>" required />
    </div>

    <button class="submit-btn" type="submit">Add Transfer</button>
</form>

<?php include '../layout/footer.php'; ?>
