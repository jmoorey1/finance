<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/watcher_engine.php';

if (!is_cli_request()) {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

if (!watcher_is_enabled()) {
    echo "Watcher is disabled in config.\n";
    exit(0);
}

try {
    $result = watcher_run_analysis($pdo);

    echo "Watcher analysis completed (funding-health aligned).\n";
    echo "Detected: " . (int)$result['detected'] . "\n";
    echo "Created: " . (int)$result['created'] . "\n";
    echo "Updated: " . (int)$result['updated'] . "\n";
    echo "Reopened: " . (int)$result['reopened'] . "\n";
    echo "Resolved: " . (int)$result['resolved'] . "\n";

    if (!empty($result['by_type'])) {
        echo "By type:\n";
        foreach ($result['by_type'] as $type => $count) {
            echo "  - " . $type . ": " . (int)$count . "\n";
        }
    }

    foreach (($result['alerts'] ?? []) as $alert) {
        echo "- [" . strtoupper((string)$alert['severity']) . "] " . $alert['title'] . "\n";
    }

    exit(0);
} catch (Throwable $e) {
    app_log('Watcher analysis failed: ' . $e->getMessage(), 'ERROR');
    fwrite(STDERR, "Watcher analysis failed: " . $e->getMessage() . "\n");
    exit(1);
}
