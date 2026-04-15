#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]


def write_file(rel_path: str, content: str) -> None:
    path = ROOT / rel_path
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    print(f"WRITTEN  {rel_path}")


def main() -> None:
    write_file(
        "scripts/admin/migration_lib.php",
        r'''<?php
/**
 * Home Finances System — Migration Helpers (BKL-021)
 *
 * Baseline approach:
 * - Existing live DB is baselined once
 * - Future changes are forward-only SQL files in /migrations
 * - config/schema.sql remains the exported current-state schema snapshot
 */

function hf_migrations_dir(): string
{
    $dir = __DIR__ . '/../../migrations';
    return realpath($dir) ?: $dir;
}

function hf_migration_table_name(): string
{
    return 'schema_migrations';
}

function hf_migration_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW TABLES LIKE '" . hf_migration_table_name() . "'");
    return (bool) $stmt->fetchColumn();
}

function hf_ensure_migration_table(PDO $pdo): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_ms INT UNSIGNED NULL,
            UNIQUE KEY uq_schema_migrations_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql);
}

function hf_parse_migration_filename(string $filename): ?array
{
    if (!preg_match('/^(\d{8}_\d{6})_([a-z0-9_]+)\.sql$/', $filename, $m)) {
        return null;
    }

    return [
        'version'  => $m[1],
        'name'     => $m[2],
        'filename' => $filename,
    ];
}

function hf_list_migration_files(): array
{
    $dir = hf_migrations_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $items = scandir($dir);
    if ($items === false) {
        return [];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $meta = hf_parse_migration_filename($item);
        if ($meta === null) {
            continue;
        }

        $path = $dir . '/' . $item;
        if (!is_file($path)) {
            continue;
        }

        $meta['path'] = $path;
        $meta['checksum'] = hash_file('sha256', $path);
        $files[] = $meta;
    }

    usort($files, function ($a, $b) {
        if ($a['version'] === $b['version']) {
            return strcmp($a['filename'], $b['filename']);
        }
        return strcmp($a['version'], $b['version']);
    });

    return $files;
}

function hf_read_applied_migrations(PDO $pdo): array
{
    if (!hf_migration_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT version, name, filename, checksum, applied_at, execution_ms
        FROM schema_migrations
        ORDER BY version ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[$row['version']] = $row;
    }

    return $out;
}

function hf_db_has_non_migration_tables(PDO $pdo): bool
{
    $stmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name <> '" . hf_migration_table_name() . "'
    ");
    $count = (int) $stmt->fetchColumn();
    return $count > 0;
}

function hf_detect_applied_migration_drift(PDO $pdo): array
{
    $applied = hf_read_applied_migrations($pdo);
    $files = hf_list_migration_files();

    $byVersion = [];
    foreach ($files as $file) {
        $byVersion[$file['version']] = $file;
    }

    $problems = [];
    foreach ($applied as $version => $row) {
        if (!isset($byVersion[$version])) {
            $problems[] = [
                'version' => $version,
                'type'    => 'missing_file',
                'message' => "Applied migration {$version} is missing from /migrations"
            ];
            continue;
        }

        if ($row['checksum'] !== $byVersion[$version]['checksum']) {
            $problems[] = [
                'version' => $version,
                'type'    => 'checksum_mismatch',
                'message' => "Applied migration {$version} has been modified since it was applied"
            ];
        }
    }

    return $problems;
}

function hf_get_pending_migrations(PDO $pdo): array
{
    $files = hf_list_migration_files();
    $applied = hf_read_applied_migrations($pdo);

    $latestAppliedVersion = null;
    if (!empty($applied)) {
        $versions = array_keys($applied);
        $latestAppliedVersion = end($versions);
    }

    $pending = [];
    $outOfOrder = [];

    foreach ($files as $file) {
        if (isset($applied[$file['version']])) {
            continue;
        }

        if ($latestAppliedVersion !== null && strcmp($file['version'], $latestAppliedVersion) < 0) {
            $outOfOrder[] = $file;
        } else {
            $pending[] = $file;
        }
    }

    return [
        'pending' => $pending,
        'out_of_order' => $outOfOrder,
    ];
}

function hf_split_sql_statements(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $len = strlen($sql);

    $statements = [];
    $buffer = '';

    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';
        $prev = ($i > 0) ? $sql[$i - 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($ch === '-' && $next === '-' && (($i + 2 >= $len) || ctype_space($sql[$i + 2]))) {
                $inLineComment = true;
                $i++;
                continue;
            }

            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($ch === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
            $buffer .= $ch;
            continue;
        }

        if ($ch === ';' && !$inSingle && !$inDouble) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function hf_mark_migration_applied(PDO $pdo, array $migration, ?int $executionMs = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO schema_migrations (version, name, filename, checksum, execution_ms)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $migration['version'],
        $migration['name'],
        $migration['filename'],
        $migration['checksum'],
        $executionMs
    ]);
}

function hf_apply_migration(PDO $pdo, array $migration): array
{
    $sql = file_get_contents($migration['path']);
    if ($sql === false) {
        throw new RuntimeException("Unable to read migration file: " . $migration['filename']);
    }

    $statements = hf_split_sql_statements($sql);

    $start = microtime(true);

    foreach ($statements as $statement) {
        $trimmed = trim($statement);
        if ($trimmed === '') {
            continue;
        }
        $pdo->exec($trimmed);
    }

    $executionMs = (int) round((microtime(true) - $start) * 1000);
    hf_mark_migration_applied($pdo, $migration, $executionMs);

    return [
        'filename' => $migration['filename'],
        'execution_ms' => $executionMs,
        'statement_count' => count($statements),
    ];
}
''',
    )

    write_file(
        "scripts/admin/migrate.php",
        r'''<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/migration_lib.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function hf_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/admin/migrate.php status\n";
    echo "  php scripts/admin/migrate.php baseline <migration_filename.sql>\n";
    echo "  php scripts/admin/migrate.php migrate\n";
    echo "\nExamples:\n";
    echo "  php scripts/admin/migrate.php status\n";
    echo "  php scripts/admin/migrate.php baseline 20260415_000000_baseline_current_schema.sql\n";
    echo "  php scripts/admin/migrate.php migrate\n";
}

function hf_print_status(PDO $pdo): int
{
    $tableExists = hf_migration_table_exists($pdo);
    $files = hf_list_migration_files();
    $applied = $tableExists ? hf_read_applied_migrations($pdo) : [];
    $drift = $tableExists ? hf_detect_applied_migration_drift($pdo) : [];
    $pendingInfo = $tableExists ? hf_get_pending_migrations($pdo) : ['pending' => [], 'out_of_order' => []];

    echo "Migration directory: " . hf_migrations_dir() . "\n";
    echo "Migration table: " . ($tableExists ? "present" : "absent") . "\n";
    echo "Migration files in repo: " . count($files) . "\n";
    echo "Applied migrations in DB: " . count($applied) . "\n";

    if (!empty($applied)) {
        $versions = array_keys($applied);
        echo "Latest applied version: " . end($versions) . "\n";
    }

    if (!$tableExists && hf_db_has_non_migration_tables($pdo)) {
        echo "\nWARNING: Database has existing tables but has not yet been baselined.\n";
        echo "Run:\n";
        echo "  php scripts/admin/migrate.php baseline 20260415_000000_baseline_current_schema.sql\n";
    }

    if (!empty($drift)) {
        echo "\nERROR: Applied migration drift detected:\n";
        foreach ($drift as $problem) {
            echo "  - " . $problem['message'] . "\n";
        }
    }

    if (!empty($pendingInfo['out_of_order'])) {
        echo "\nERROR: Out-of-order migration files detected:\n";
        foreach ($pendingInfo['out_of_order'] as $migration) {
            echo "  - " . $migration['filename'] . "\n";
        }
    }

    if (!empty($pendingInfo['pending'])) {
        echo "\nPending migrations:\n";
        foreach ($pendingInfo['pending'] as $migration) {
            echo "  - " . $migration['filename'] . "\n";
        }
    } else {
        echo "\nPending migrations: none\n";
    }

    if (!empty($drift) || !empty($pendingInfo['out_of_order'])) {
        return 1;
    }

    return 0;
}

function hf_run_baseline(PDO $pdo, string $filename): int
{
    hf_ensure_migration_table($pdo);

    $applied = hf_read_applied_migrations($pdo);
    if (!empty($applied)) {
        fwrite(STDERR, "Baseline refused: schema_migrations already contains applied rows.\n");
        return 1;
    }

    $target = basename($filename);
    $files = hf_list_migration_files();
    $migration = null;

    foreach ($files as $file) {
        if ($file['filename'] === $target) {
            $migration = $file;
            break;
        }
    }

    if ($migration === null) {
        fwrite(STDERR, "Baseline file not found in /migrations: {$target}\n");
        return 1;
    }

    hf_mark_migration_applied($pdo, $migration, 0);

    echo "Baseline recorded: {$migration['filename']}\n";
    echo "You should now run your schema export so config/schema.sql includes schema_migrations.\n";
    return 0;
}

function hf_run_migrate(PDO $pdo): int
{
    hf_ensure_migration_table($pdo);

    $applied = hf_read_applied_migrations($pdo);

    if (empty($applied) && hf_db_has_non_migration_tables($pdo)) {
        fwrite(STDERR, "Database is not baselined. Run:\n");
        fwrite(STDERR, "  php scripts/admin/migrate.php baseline 20260415_000000_baseline_current_schema.sql\n");
        return 1;
    }

    $drift = hf_detect_applied_migration_drift($pdo);
    if (!empty($drift)) {
        fwrite(STDERR, "Refusing to migrate because applied migration drift was detected:\n");
        foreach ($drift as $problem) {
            fwrite(STDERR, "  - " . $problem['message'] . "\n");
        }
        return 1;
    }

    $pendingInfo = hf_get_pending_migrations($pdo);

    if (!empty($pendingInfo['out_of_order'])) {
        fwrite(STDERR, "Refusing to migrate because out-of-order migration files were detected:\n");
        foreach ($pendingInfo['out_of_order'] as $migration) {
            fwrite(STDERR, "  - " . $migration['filename'] . "\n");
        }
        return 1;
    }

    if (empty($pendingInfo['pending'])) {
        echo "No pending migrations.\n";
        return 0;
    }

    foreach ($pendingInfo['pending'] as $migration) {
        echo "Applying {$migration['filename']} ... ";
        $result = hf_apply_migration($pdo, $migration);
        echo "done ({$result['statement_count']} statement(s), {$result['execution_ms']} ms)\n";
    }

    echo "All pending migrations applied successfully.\n";
    echo "Run your schema export next so config/schema.sql reflects the new state.\n";
    return 0;
}

$command = $argv[1] ?? 'status';

try {
    switch ($command) {
        case 'status':
            exit(hf_print_status($pdo));

        case 'baseline':
            $filename = $argv[2] ?? '';
            if ($filename === '') {
                hf_usage();
                exit(1);
            }
            exit(hf_run_baseline($pdo, $filename));

        case 'migrate':
            exit(hf_run_migrate($pdo));

        case 'help':
        case '--help':
        case '-h':
            hf_usage();
            exit(0);

        default:
            fwrite(STDERR, "Unknown command: {$command}\n\n");
            hf_usage();
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Migration runner error: " . $e->getMessage() . "\n");
    exit(1);
}
''',
    )

    write_file(
        "scripts/admin/new_migration.php",
        r'''<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$rawName = $argv[1] ?? '';
if ($rawName === '') {
    fwrite(STDERR, "Usage: php scripts/admin/new_migration.php <migration_name>\n");
    fwrite(STDERR, "Example: php scripts/admin/new_migration.php add_index_on_transactions\n");
    exit(1);
}

$name = strtolower(trim($rawName));
$name = preg_replace('/[^a-z0-9]+/', '_', $name);
$name = trim($name, '_');

if ($name === '') {
    fwrite(STDERR, "Migration name resolved to empty after sanitising.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

$migrationsDir = $root . '/migrations';
if (!is_dir($migrationsDir) && !mkdir($migrationsDir, 0775, true)) {
    fwrite(STDERR, "Unable to create migrations directory: {$migrationsDir}\n");
    exit(1);
}

$timestamp = date('Ymd_His');
$filename = $timestamp . '_' . $name . '.sql';
$path = $migrationsDir . '/' . $filename;

if (file_exists($path)) {
    fwrite(STDERR, "Migration already exists: {$filename}\n");
    exit(1);
}

$content = "-- Migration: {$name}\n"
         . "-- Created: " . date('Y-m-d H:i:s') . "\n"
         . "-- Write forward-only SQL here. Do not edit a migration after it has been applied.\n\n";

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Failed to write migration file: {$path}\n");
    exit(1);
}

echo "Created migration: {$path}\n";
''',
    )

    write_file(
        "migrations/20260415_000000_baseline_current_schema.sql",
        r'''-- Baseline marker for introducing tracked migrations (BKL-021)
--
-- This file is intentionally comment-only.
-- It represents the current live schema state at the point migrations were introduced.
--
-- Existing live database:
--   php scripts/admin/migrate.php baseline 20260415_000000_baseline_current_schema.sql
--
-- Fresh database bootstrap:
--   1. Load config/schema.sql
--   2. Mark this baseline as applied
--   3. Apply later migrations with:
--        php scripts/admin/migrate.php migrate
''',
    )

    write_file(
        "docs/MIGRATIONS.md",
        r'''# Database Migrations

## Purpose

This repo now supports **tracked, forward-only SQL migrations**.

This was introduced as a **baseline migration system** for an existing live database.  
It does **not** attempt to replay the whole historic schema build from scratch.

`config/schema.sql` remains the **exported current-state schema snapshot**.

---

## Files

- `migrations/*.sql` — forward-only migration files
- `scripts/admin/migrate.php` — migration runner
- `scripts/admin/new_migration.php` — helper to create a new migration file
- `scripts/admin/export_schema.sh` — export the live schema back to `config/schema.sql`

---

## First-time setup on the existing live database

Check status:

```bash
php scripts/admin/migrate.php status
