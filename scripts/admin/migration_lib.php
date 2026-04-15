<?php
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
    if (!preg_match('/^(\\d{8}_\\d{6})_([a-z0-9_]+)\\.sql$/', $filename, $m)) {
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
    $sql = preg_replace('/^\\xEF\\xBB\\xBF/', '', $sql);
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
