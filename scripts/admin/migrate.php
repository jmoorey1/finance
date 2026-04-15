<?php
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
