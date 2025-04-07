<?php
require_once('../config/db.php');

$upload_dir = realpath(__DIR__ . '/../uploads') . '/';
$parser_script = realpath(__DIR__ . '/../scripts/parse_ofx.py');

// Fetch account list for manual selection
$accounts = $pdo->query("SELECT id, name FROM accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ofx_file'])) {
    $filename = basename($_FILES['ofx_file']['name']);
    $target_path = $upload_dir . $filename;
    $manual_account_id = $_POST['account_id'] ?? '';

    if (move_uploaded_file($_FILES['ofx_file']['tmp_name'], $target_path)) {
        $escaped_path = escapeshellarg($target_path);
        $escaped_acct = escapeshellarg($manual_account_id);
        $output = shell_exec("python3 $parser_script $escaped_path $escaped_acct 2>&1");
        echo "<pre>Parser output:\n$output</pre>";
    } else {
        echo "File upload failed.";
    }
}
?>

<h2>Upload OFX File</h2>
<form method="post" enctype="multipart/form-data">
  <label for="ofx_file">Select OFX file:</label>
  <input type="file" name="ofx_file" accept=".ofx" required><br><br>

  <label for="account_id">Optional: Select account manually</label>
  <select name="account_id">
    <option value="">-- auto-detect from file --</option>
    <?php foreach ($accounts as $acct): ?>
      <option value="<?= htmlspecialchars($acct['id']) ?>"><?= htmlspecialchars($acct['name']) ?></option>
    <?php endforeach; ?>
  </select><br><br>

  <button type="submit">Upload & Parse</button>
</form>
