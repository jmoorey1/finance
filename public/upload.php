<?php
$upload_dir = realpath(__DIR__ . '/../uploads') . '/';
$parser_script = realpath(__DIR__ . '/../scripts/parse_ofx.py');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ofx_file'])) {
    $filename = basename($_FILES['ofx_file']['name']);
    $target_path = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['ofx_file']['tmp_name'], $target_path)) {
        $escaped_path = escapeshellarg($target_path);
        $output = shell_exec("python3 $parser_script $escaped_path 2>&1");
        echo "<pre>Parser output:\n$output</pre>";
    } else {
        echo "File upload failed.";
    }
}
?>

<h2>Upload OFX File</h2>
<form method="post" enctype="multipart/form-data">
  <input type="file" name="ofx_file" accept=".ofx" required>
  <button type="submit">Upload & Parse</button>
</form>
