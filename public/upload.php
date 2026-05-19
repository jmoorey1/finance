<?php
require_once '../config/db.php';
require_once '../scripts/import_run_helpers.php';

// Fetch accounts to allow user selection for .csv uploads
$accounts = $pdo->query("SELECT id, name FROM accounts WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$accountId = $_POST['account_id'] ?? null;
$uploadStatus = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ofxfile'])) {
    $file = $_FILES['ofxfile'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $filename = basename($file['name']);
        $targetPath = '../uploads/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $cmd = '';
            $parser = '';
            $requestedAccountId = null;

            if ($ext === 'ofx') {
                $parser = 'parse_ofx.py';
                $cmd = escapeshellcmd("python3 ../scripts/parse_ofx.py") . ' ' . escapeshellarg($targetPath);
            } elseif ($ext === 'csv') {
                if (!$accountId) {
                    $uploadStatus = "Please select an account for CSV uploads.";
                } else {
                    $parser = 'parse_csv.py';
                    $requestedAccountId = (int)$accountId;
                    $cmd = escapeshellcmd("python3 ../scripts/parse_csv.py") . ' ' .
                        escapeshellarg($targetPath) . ' ' . (int)$accountId;
                }
            } else {
                $uploadStatus = "Unsupported file type: $ext";
            }

            if (empty($uploadStatus)) {
                $runId = 0;

                try {
                    $runId = start_import_run(
                        $pdo,
                        $filename,
                        $ext,
                        $parser,
                        $requestedAccountId
                    );

                    $outputLines = [];
                    exec($cmd . ' 2>&1', $outputLines, $exitCode);
                    $rawOutput = trim(implode(PHP_EOL, $outputLines));
                    $summary = extract_import_summary_from_output($rawOutput);

                    complete_import_run($pdo, $runId, $exitCode, $rawOutput, $summary);

                    $displayOutput = htmlspecialchars(
                        strip_import_summary_marker($rawOutput),
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    );

                    if ($exitCode === 0) {
                        $uploadStatus = "✅ Import logged as run #{$runId}.<br><pre>{$displayOutput}</pre>";
                    } else {
                        $uploadStatus = "❌ Error running script (run #{$runId}).<br><pre>{$displayOutput}</pre>";
                    }
                } catch (Throwable $e) {
                    if ($runId > 0) {
                        fail_import_run($pdo, $runId, $e->getMessage());
                    }

                    $safeMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $uploadStatus = "❌ Upload processing failed after parser execution.<br><pre>{$safeMessage}</pre>";
                }
            }
        } else {
            $uploadStatus = "Error moving uploaded file.";
        }
    } else {
        $uploadStatus = "Upload error code: " . $file['error'];
    }
}

$recentRuns = get_recent_import_runs($pdo, 20);

include '../layout/header.php';
?>

<h1>Upload Transaction File</h1>

<form method="post" enctype="multipart/form-data">
    <label for="ofxfile">Select .ofx or .csv file:</label><br>
    <input type="file" name="ofxfile" id="ofxfile" required><br><br>

    <label for="account_id">Account (required for .csv):</label><br>
    <select name="account_id" id="account_id">
        <option value="">-- select account --</option>
        <?php foreach ($accounts as $acct): ?>
            <option value="<?= $acct['id'] ?>" <?= $accountId == $acct['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($acct['name']) ?>
            </option>
        <?php endforeach; ?>
    </select><br><br>

    <button type="submit">Upload</button>
</form>

<?php if ($uploadStatus): ?>
    <hr>
    <div>
        <p><strong>Status:</strong><br><?= $uploadStatus ?></p>
        <p><a href="review.php">To Review →</a></p>
    </div>
<?php endif; ?>

<hr>

<h2>Recent Import Runs</h2>

<?php if (empty($recentRuns)): ?>
    <p class="text-muted">No import runs logged yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Started</th>
                    <th>File</th>
                    <th>Type</th>
                    <th>Parser</th>
                    <th>Accounts</th>
                    <th>Status</th>
                    <th>Summary</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRuns as $run): ?>
                    <?php
                        $badge = 'bg-secondary';
                        if ($run['status'] === 'success') $badge = 'bg-success';
                        if ($run['status'] === 'failed') $badge = 'bg-danger';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($run['created_at']) ?></td>
                        <td><?= htmlspecialchars($run['filename']) ?></td>
                        <td><?= htmlspecialchars(strtoupper($run['file_type'])) ?></td>
                        <td><?= htmlspecialchars($run['parser']) ?></td>
                        <td><?= htmlspecialchars($run['account_names'] ?: ($run['requested_account_name'] ?? '—')) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($run['status']) ?></span></td>
                        <td><?= htmlspecialchars(format_import_run_summary($run), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include '../layout/footer.php'; ?>