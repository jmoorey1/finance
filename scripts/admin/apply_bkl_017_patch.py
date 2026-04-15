#!/usr/bin/env python3
from pathlib import Path
import shutil
import sys

ROOT = Path(__file__).resolve().parents[2]
BACKUP_SUFFIX = ".bak_bkl_017"


def backup(path: Path) -> None:
    if not path.exists():
        return
    backup_path = path.with_name(path.name + BACKUP_SUFFIX)
    if not backup_path.exists():
        shutil.copy2(path, backup_path)


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def write_text(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        backup(path)
    path.write_text(content, encoding="utf-8")


def replace_once(rel_path: str, old: str, new: str) -> None:
    path = ROOT / rel_path
    text = read_text(path)
    count = text.count(old)
    if count != 1:
        raise RuntimeError(
            f"{rel_path}: expected exactly 1 match for replacement, found {count}."
        )
    backup(path)
    path.write_text(text.replace(old, new, 1), encoding="utf-8")
    print(f"UPDATED  {rel_path}")


def append_line_if_missing(rel_path: str, line: str) -> None:
    path = ROOT / rel_path
    text = read_text(path)
    lines = text.splitlines()
    if line not in lines:
        backup(path)
        if not text.endswith("\n"):
            text += "\n"
        text += line + "\n"
        path.write_text(text, encoding="utf-8")
        print(f"UPDATED  {rel_path}")
    else:
        print(f"OK       {rel_path} (line already present)")


def delete_file(rel_path: str) -> None:
    path = ROOT / rel_path
    if path.exists():
        backup(path)
        path.unlink()
        print(f"DELETED  {rel_path}")
    else:
        print(f"OK       {rel_path} (already absent)")


def main() -> None:
    # 1. Keep accidental recommit of old local config out of git
    append_line_if_missing(".gitignore", "config/config.php")

    # 2. Write committed example env file
    write_text(
        ROOT / ".env.example",
        """# Home Finances System — local secrets
# Copy this file to .env and replace the values.
# .env is gitignored and must NOT be committed.

FINANCE_DB_HOST='localhost'
FINANCE_DB_NAME='accounts'
FINANCE_DB_USER='john'
FINANCE_DB_PASSWORD='replace_me'
FINANCE_DB_CHARSET='utf8mb4'
""",
    )
    print("WRITTEN  .env.example")

    # 3. Shared PHP env loader
    write_text(
        ROOT / "config/env.php",
        """<?php
/**
 * Minimal .env loader for Home Finances.
 *
 * - Loads repo-root /.env if present
 * - Does not override already-set process environment variables
 * - Exposes env_value() helper for PHP runtime use
 */

if (!function_exists('load_finance_env')) {
    function load_finance_env(?string $path = null): void {
        static $loaded = [];

        $path = $path ?? dirname(__DIR__) . '/.env';
        if (isset($loaded[$path])) {
            return;
        }
        $loaded[$path] = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '') {
                continue;
            }

            $hasEnv = array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false;
            if ($hasEnv) {
                continue;
            }

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last  = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

load_finance_env();
""",
    )
    print("WRITTEN  config/env.php")

    # 4. Overwrite DB bootstrap to use env-backed secrets
    write_text(
        ROOT / "config/db.php",
        """<?php
/**
 * Home Finances System — DB Bootstrap (BKL-001, BKL-017)
 *
 * - Loads app config (feature flags, maintenance mode, logging, timezone)
 * - Loads local environment from /.env via config/env.php
 * - Sets up safe error handling toggles
 * - Provides get_db_connection() and global $pdo for backward compatibility
 */

require_once __DIR__ . '/env.php';
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
        $host    = env_value('FINANCE_DB_HOST', 'localhost');
        $db      = env_value('FINANCE_DB_NAME', 'accounts');
        $user    = env_value('FINANCE_DB_USER', 'john');
        $pass    = env_value('FINANCE_DB_PASSWORD', null);
        $charset = env_value('FINANCE_DB_CHARSET', 'utf8mb4');

        if ($pass === null || $pass === '') {
            $msg = 'Missing FINANCE_DB_PASSWORD. Set it in /.env or the process environment.';
            app_log($msg, 'ERROR');
            throw new RuntimeException($msg);
        }

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\\PDOException $e) {
            app_log("DB connection failed: " . $e->getMessage(), "ERROR");
            throw new \\PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    return $pdo;
}

$pdo = get_db_connection();
""",
    )
    print("WRITTEN  config/db.php")

    # 5. Shared Python env loader
    write_text(
        ROOT / "scripts/finance_env.py",
        """from pathlib import Path
import os


def _repo_root() -> Path:
    return Path(__file__).resolve().parents[1]


def load_dotenv(env_path: Path | None = None) -> None:
    env_path = env_path or (_repo_root() / ".env")
    if not env_path.is_file():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()

        if not line or line.startswith("#"):
            continue

        if line.startswith("export "):
            line = line[7:].strip()

        if "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()

        if not key:
            continue

        if key in os.environ:
            continue

        if len(value) >= 2 and (
            (value[0] == '"' and value[-1] == '"') or
            (value[0] == "'" and value[-1] == "'")
        ):
            value = value[1:-1]

        os.environ[key] = value


def get_db_config() -> dict:
    load_dotenv()

    host = os.getenv("FINANCE_DB_HOST", "localhost")
    user = os.getenv("FINANCE_DB_USER", "john")
    database = os.getenv("FINANCE_DB_NAME", "accounts")
    charset = os.getenv("FINANCE_DB_CHARSET", "utf8mb4")
    password = os.getenv("FINANCE_DB_PASSWORD")

    if password is None or password == "":
        raise RuntimeError(
            "Missing FINANCE_DB_PASSWORD. Set it in /.env or the process environment."
        )

    return {
        "host": host,
        "user": user,
        "password": password,
        "database": database,
        "charset": charset,
    }
""",
    )
    print("WRITTEN  scripts/finance_env.py")

    # 6. Patch Python import scripts
    replace_once(
        "scripts/parse_ofx.py",
        """from ofxparse import OfxParser
from datetime import timedelta

# ---------- CONFIG ----------
DB_CONFIG = {
    'host': 'localhost',
    'user': 'john',
    'password': 'Thebluemole01',
    'database': 'accounts'
}
""",
        """from ofxparse import OfxParser
from datetime import timedelta
from finance_env import get_db_config

# ---------- CONFIG ----------
DB_CONFIG = get_db_config()
""",
    )

    replace_once(
        "scripts/parse_csv.py",
        """from decimal import Decimal

DB_CONFIG = {
    'user': 'john',
    'password': 'Thebluemole01',
    'host': 'localhost',
    'database': 'accounts'
}
""",
        """from decimal import Decimal
from finance_env import get_db_config

DB_CONFIG = get_db_config()
""",
    )

    replace_once(
        "scripts/predict_instances.py",
        """from dateutil.relativedelta import relativedelta
""",
        """from dateutil.relativedelta import relativedelta
from finance_env import get_db_config
""",
    )

    replace_once(
        "scripts/predict_instances.py",
        """def main():
    host = os.getenv("FINANCE_DB_HOST", "localhost")
    user = os.getenv("FINANCE_DB_USER", "john")
    database = os.getenv("FINANCE_DB_NAME", "accounts")

    password = os.getenv("FINANCE_DB_PASSWORD", "Thebluemole01")

    try:
        if password:
            db = mysql.connector.connect(host=host, user=user, password=password, database=database)
        else:
            db = mysql.connector.connect(host=host, user=user, database=database)
    except mysql.connector.Error as e:
        raise SystemExit(
            "DB connection failed. If you use password auth, set FINANCE_DB_PASSWORD in your shell/cron environment.\\n"
            f"Host={host} User={user} DB={database}\\n"
            f"MySQL error: {e}"
        )

    cursor = db.cursor(dictionary=True)
""",
        """def main():
    try:
        db = mysql.connector.connect(**get_db_config())
    except Exception as e:
        raise SystemExit(f"DB connection failed: {e}")

    cursor = db.cursor(dictionary=True)
""",
    )

    replace_once(
        "scripts/forecast_balance_timeline.py",
        """import mysql.connector
from datetime import datetime, timedelta
from decimal import Decimal
from collections import defaultdict
""",
        """import mysql.connector
from datetime import datetime, timedelta
from decimal import Decimal
from collections import defaultdict
from finance_env import get_db_config
""",
    )

    replace_once(
        "scripts/forecast_balance_timeline.py",
        """db = mysql.connector.connect(
    host="localhost",
    user="john",
    password="Thebluemole01",
    database="accounts"
)
cursor = db.cursor(dictionary=True)
""",
        """db = mysql.connector.connect(**get_db_config())
cursor = db.cursor(dictionary=True)
""",
    )

    # 7. Overwrite export_schema.sh to use .env
    write_text(
        ROOT / "scripts/admin/export_schema.sh",
        """#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${PROJECT_ROOT}/.env"
OUT_SQL="${PROJECT_ROOT}/config/schema.sql"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}. Create it from .env.example first."
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

DB_HOST="${FINANCE_DB_HOST:-localhost}"
DB_NAME="${FINANCE_DB_NAME:-accounts}"
DB_USER="${FINANCE_DB_USER:-john}"
DB_PASS="${FINANCE_DB_PASSWORD:-}"

if [[ -z "${DB_PASS}" ]]; then
  echo "Missing FINANCE_DB_PASSWORD in ${ENV_FILE}."
  exit 1
fi

MYSQL_PWD="${DB_PASS}" mysqldump \\
  --host="${DB_HOST}" \\
  --user="${DB_USER}" \\
  --no-tablespaces \\
  --no-data \\
  --skip-comments \\
  --single-transaction \\
  "${DB_NAME}" > "${OUT_SQL}"

unset MYSQL_PWD

echo "Exported schema to: ${OUT_SQL}"
""",
    )
    print("WRITTEN  scripts/admin/export_schema.sh")

    # 8. Remove committed secret file
    delete_file("config/config.php")

    print("")
    print("BKL-017 patch applied successfully.")
    print("Now review with: git status && git diff -- . ':(exclude).env'")
    print("Then create your local .env from .env.example and test the app/scripts.")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        sys.exit(1)
