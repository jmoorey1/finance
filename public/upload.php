<?php include '../layout/header.php'; ?>

<?php
require_once('../config/db.php');

// Fetch accounts to allow user selection for .csv uploads
$accounts = $pdo->query("SELECT id, name FROM accounts where active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

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

            if ($ext === 'ofx') {
                $cmd = escapeshellcmd("python3 ../scripts/parse_ofx.py") . ' ' . escapeshellarg($targetPath);
            } elseif ($ext === 'csv') {
                if (!$accountId) {
                    $uploadStatus = "Please select an account for CSV uploads.";
                } else {
                    $cmd = escapeshellcmd("python3 ../scripts/parse_csv.py") . ' ' .
                        escapeshellarg($targetPath) . ' ' . (int)$accountId;
                }
            } else {
                $uploadStatus = "Unsupported file type: $ext";
            }

            if (empty($uploadStatus)) {
                ob_start();
                passthru($cmd, $exitCode);
                $output = ob_get_clean();
                $uploadStatus = $exitCode === 0 ? "<pre>$output</pre>" : "❌ Error running script.<br><pre>$output</pre>";
            }
        } else {
            $uploadStatus = "Error moving uploaded file.";
        }
    } else {
        $uploadStatus = "Upload error code: " . $file['error'];
    }
}
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
    <div><p><strong>Status:</strong><br><?= $uploadStatus ?></p>
    <p><a href="review.php">To Review →</a></p></div>
<?php endif; ?>


<?php include '../layout/footer.php'; ?>
