<?php
/**
 * Healthcheck endpoint (BKL-001)
 *
 * - Basic runtime + DB connectivity checks
 * - Optional JSON output: /healthcheck.php?format=json
 *
 * IMPORTANT: This page is allowlisted during maintenance mode by default.
 */

require_once '../config/db.php';

$format = $_GET['format'] ?? 'html';
$isJson = (strtolower($format) === 'json');

$status = [
    'ok' => true,
    'app' => [
        'name'    => app_config('app_name', 'Home Finances'),
        'env'     => app_config('app_env', 'production'),
        'version' => app_config('app_version', ''),
        'time'    => date('Y-m-d H:i:s'),
        'timezone'=> app_config('timezone', 'UTC'),
        'maintenance_enabled' => (bool)app_config('maintenance.enabled', false),
    ],
    'runtime' => [
        'php_version' => PHP_VERSION,
        'sapi'        => PHP_SAPI,
    ],
    'checks' => [],
];

function add_check(array &$status, string $name, bool $ok, string $detail = ''): void {
    $status['checks'][] = [
        'name'   => $name,
        'ok'     => $ok,
        'detail' => $detail,
    ];
    if (!$ok) {
        $status['ok'] = false;
    }
}

// Check: DB connection + trivial query
try {
    $pdo->query("SELECT 1")->fetchColumn();
    add_check($status, 'db_connectivity', true, 'Connected and SELECT 1 succeeded');
} catch (Throwable $e) {
    add_check($status, 'db_connectivity', false, $e->getMessage());
}

// Optional: table sanity checks (don’t hard-fail the page if a table is missing)
$tableChecks = [
    'accounts'            => "SELECT COUNT(*) FROM accounts",
    'transactions'        => "SELECT COUNT(*) FROM transactions",
    'staging_transactions'=> "SELECT COUNT(*) FROM staging_transactions",
    'predicted_instances' => "SELECT COUNT(*) FROM predicted_instances",
];

foreach ($tableChecks as $label => $sql) {
    try {
        $count = $pdo->query($sql)->fetchColumn();
        add_check($status, "table_count:{$label}", true, "Rows: {$count}");
    } catch (Throwable $e) {
        add_check($status, "table_count:{$label}", false, $e->getMessage());
    }
}

// Check: uploads dir exists and writable (used by your ingestion flow)
$uploadsDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
add_check(
    $status,
    'uploads_dir_writable',
    (is_dir($uploadsDir) && is_writable($uploadsDir)),
    "Path: {$uploadsDir}"
);

// Check: logs dir exists and writable (recommended)
$logsDir = app_config('logging.dir', __DIR__ . '/../logs');
$logsWritable = (is_dir($logsDir) && is_writable($logsDir));
add_check(
    $status,
    'logs_dir_writable',
    $logsWritable,
    "Path: {$logsDir}"
);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($status, JSON_PRETTY_PRINT);
    exit;
}

// HTML output (minimal, does not include site header to avoid dependencies)
http_response_code($status['ok'] ? 200 : 500);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Healthcheck — <?= htmlspecialchars($status['app']['name']) ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f8f9fa;margin:0;padding:32px;}
    .card{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
    .ok{color:#198754;font-weight:700;}
    .bad{color:#dc3545;font-weight:700;}
    table{width:100%;border-collapse:collapse;margin-top:16px;}
    th,td{border-top:1px solid #eee;padding:10px;text-align:left;vertical-align:top;font-size:14px;}
    th{background:#fafafa;}
    .meta{color:#666;font-size:13px;margin-top:6px;}
    code{background:#f1f3f5;padding:2px 6px;border-radius:6px;}
  </style>
</head>
<body>
  <div class="card">
    <h1 style="margin:0 0 8px 0;">Healthcheck</h1>
    <div class="meta">
      <?= htmlspecialchars($status['app']['name']) ?> • v<?= htmlspecialchars($status['app']['version']) ?> •
      env=<code><?= htmlspecialchars($status['app']['env']) ?></code> •
      <?= htmlspecialchars($status['app']['time']) ?> (<?= htmlspecialchars($status['app']['timezone']) ?>)
    </div>

    <p style="margin:14px 0 0 0;">
      Status:
      <?php if ($status['ok']): ?>
        <span class="ok">OK</span>
      <?php else: ?>
        <span class="bad">FAIL</span>
      <?php endif; ?>
      • Maintenance:
      <code><?= $status['app']['maintenance_enabled'] ? 'enabled' : 'disabled' ?></code>
      • JSON:
      <code>?format=json</code>
    </p>

    <table>
      <thead>
        <tr>
          <th style="width:260px;">Check</th>
          <th style="width:80px;">OK?</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($status['checks'] as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td><?= $c['ok'] ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>' ?></td>
          <td><?= htmlspecialchars($c['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p class="meta" style="margin-top:16px;">
      Runtime: PHP <?= htmlspecialchars($status['runtime']['php_version']) ?> • SAPI <?= htmlspecialchars($status['runtime']['sapi']) ?>
    </p>
  </div>
</body>
</html>
