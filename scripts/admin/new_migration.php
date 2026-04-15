<?php
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
