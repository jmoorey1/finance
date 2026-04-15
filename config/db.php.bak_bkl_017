<?php
/**
 * Home Finances System — DB Bootstrap (BKL-001)
 *
 * - Loads app config (feature flags, maintenance mode, logging, timezone)
 * - Sets up safe error handling toggles
 * - Provides get_db_connection() and global $pdo for backward compatibility
 */

require_once __DIR__ . '/app.php';

if (!function_exists('is_cli_request')) {
    function is_cli_request(): bool {
        return (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
    }
}

/**
 * Apply timezone early so DateTime defaults are consistent.
 */
$tz = app_config('timezone', 'UTC');
@date_default_timezone_set($tz);

/**
 * Error visibility/logging controlled via config/app.php
 * (Safe defaults: hide errors in browser, log them instead.)
 */
$displayErrors = app_config('debug.display_errors', false) ? '1' : '0';
$logErrors     = app_config('debug.log_errors', true) ? '1' : '0';

@ini_set('display_errors', $displayErrors);
@ini_set('log_errors', $logErrors);

if (app_config('debug.log_errors', true)) {
    // Direct PHP errors to a file under /finance/logs if possible
    $phpErrorLogDir = app_config('logging.dir', __DIR__ . '/../logs');
    if (!is_dir($phpErrorLogDir)) {
        @mkdir($phpErrorLogDir, 0775, true);
    }
    $phpErrorLogPath = rtrim($phpErrorLogDir, '/') . '/php_errors.log';
    @ini_set('error_log', $phpErrorLogPath);
}

/**
 * Optional Maintenance Mode
 * - Only affects web requests (not CLI scripts)
 * - Healthcheck is allowlisted by default
 */
if (!is_cli_request() && app_config('maintenance.enabled', false)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $allowPaths = app_config('maintenance.allow_paths', []);

    $allowed = false;
    if (is_array($allowPaths)) {
        foreach ($allowPaths as $p) {
            if ($p !== '' && strpos($requestUri, $p) === 0) {
                $allowed = true;
                break;
            }
        }
    }

    if (!$allowed) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        $msg = htmlspecialchars(app_config('maintenance.message', 'Down for maintenance.'), ENT_QUOTES, 'UTF-8');

        echo "<!doctype html><html lang='en'><head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1'>
                <title>Maintenance</title>
                <style>
                    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f8f9fa;margin:0;padding:40px;}
                    .card{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,0.05);}
                    h1{margin:0 0 12px 0;font-size:22px;}
                    p{margin:0;color:#444;line-height:1.4;}
                    .meta{margin-top:16px;color:#777;font-size:13px;}
                </style>
              </head><body>
                <div class='card'>
                  <h1>Home Finances — Maintenance</h1>
                  <p>{$msg}</p>
                  <div class='meta'>HTTP 503 • " . htmlspecialchars(app_config('app_version', ''), ENT_QUOTES, 'UTF-8') . "</div>
                </div>
              </body></html>";
        exit;
    }
}

function get_db_connection() {
    static $pdo = null;

    if ($pdo === null) {
        // Current static config (BKL-017 will move secrets out of repo)
        $host = 'localhost';
        $db   = 'accounts';
        $user = 'john';
        $pass = 'Thebluemole01'; // TODO (BKL-017): move to env var
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            // Log a safe message (no secrets)
            app_log("DB connection failed: " . $e->getMessage(), "ERROR");
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    return $pdo;
}

// Backward compatibility: most pages expect $pdo from require_once '../config/db.php'
$pdo = get_db_connection();
