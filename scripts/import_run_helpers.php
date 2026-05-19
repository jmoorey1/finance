<?php

function start_import_run(PDO $pdo, string $filename, string $fileType, string $parser, ?int $requestedAccountId): int
{
    $stmt = $pdo->prepare("
        INSERT INTO import_runs (filename, file_type, parser, requested_account_id, status)
        VALUES (?, ?, ?, ?, 'running')
    ");
    $stmt->execute([$filename, $fileType, $parser, $requestedAccountId]);
    return (int)$pdo->lastInsertId();
}

function extract_import_summary_from_output(string $output): ?array
{
    $lines = preg_split('/\R/', $output);
    if (!$lines) {
        return null;
    }

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string)$lines[$i]);
        if (str_starts_with($line, 'IMPORT_SUMMARY_JSON:')) {
            $json = substr($line, strlen('IMPORT_SUMMARY_JSON:'));
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        }
    }

    return null;
}

function strip_import_summary_marker(string $output): string
{
    $lines = preg_split('/\R/', $output);
    if (!$lines) {
        return $output;
    }

    $clean = [];
    foreach ($lines as $line) {
        if (!str_starts_with(trim((string)$line), 'IMPORT_SUMMARY_JSON:')) {
            $clean[] = $line;
        }
    }

    return trim(implode(PHP_EOL, $clean));
}

function sanitize_import_text(?string $text): string
{
    $text = (string)($text ?? '');

    if ($text === '') {
        return '';
    }

    // Remove invalid UTF-8 byte sequences while keeping valid text.
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    // If mbstring is available, force valid UTF-8 with substitution.
    if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
    }

    // Strip control chars except tab/newline/carriage return.
    $text = preg_replace('/[^\P{C}\t\n\r]/u', '', $text) ?? $text;

    return trim($text);
}

function summary_int(?array $summary, string $key): ?int
{
    if (!is_array($summary) || !array_key_exists($key, $summary) || $summary[$key] === null || $summary[$key] === '') {
        return null;
    }

    return (int)$summary[$key];
}

function append_import_run_warning(PDO $pdo, int $runId, string $warning): void
{
    $warning = sanitize_import_text($warning);

    $stmt = $pdo->prepare("
        UPDATE import_runs
        SET output_text = CONCAT(COALESCE(output_text, ''), ?)
        WHERE id = ?
    ");
    $stmt->execute([PHP_EOL . '[warning] ' . $warning, $runId]);
}

function complete_import_run(PDO $pdo, int $runId, int $exitCode, string $output, ?array $summary): void
{
    $status = $exitCode === 0 ? 'success' : 'failed';
    $cleanOutput = sanitize_import_text(strip_import_summary_marker($output));

    $stmt = $pdo->prepare("
        UPDATE import_runs
        SET status = ?,
            exit_code = ?,
            output_text = ?,
            rows_parsed = ?,
            rows_new = ?,
            rows_predictions = ?,
            rows_potential_duplicates = ?,
            rows_exact_suppressed = ?,
            rows_repaired = ?,
            rows_malformed = ?,
            rows_non_billed = ?,
            rows_unresolved_accounts = ?,
            finished_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $status,
        $exitCode,
        $cleanOutput,
        summary_int($summary, 'rows_parsed'),
        summary_int($summary, 'rows_new'),
        summary_int($summary, 'rows_predictions'),
        summary_int($summary, 'rows_potential_duplicates'),
        summary_int($summary, 'rows_exact_suppressed'),
        summary_int($summary, 'rows_repaired'),
        summary_int($summary, 'rows_malformed'),
        summary_int($summary, 'rows_non_billed'),
        summary_int($summary, 'rows_unresolved_accounts'),
        $runId,
    ]);

    if (!is_array($summary) || empty($summary['account_ids']) || !is_array($summary['account_ids'])) {
        return;
    }

    try {
        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO import_run_accounts (import_run_id, account_id)
            VALUES (?, ?)
        ");

        foreach ($summary['account_ids'] as $accountId) {
            $accountId = (int)$accountId;
            if ($accountId > 0) {
                $insertStmt->execute([$runId, $accountId]);
            }
        }
    } catch (Throwable $e) {
        append_import_run_warning($pdo, $runId, 'import_run_accounts logging failed: ' . $e->getMessage());
    }
}

function fail_import_run(PDO $pdo, int $runId, string $message, ?int $exitCode = null): void
{
    try {
        $message = sanitize_import_text($message);

        $stmt = $pdo->prepare("
            UPDATE import_runs
            SET status = 'failed',
                exit_code = COALESCE(?, exit_code),
                output_text = CONCAT(COALESCE(output_text, ''), ?),
                finished_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $exitCode,
            PHP_EOL . '[fatal] ' . $message,
            $runId,
        ]);
    } catch (Throwable $e) {
        // Last-ditch fallback: do not throw again while handling an upload failure.
    }
}

function format_import_run_summary(array $run): string
{
    $parts = [];

    if ($run['rows_parsed'] !== null) {
        $parts[] = 'parsed ' . (int)$run['rows_parsed'];
    }
    if ($run['rows_new'] !== null) {
        $parts[] = 'new ' . (int)$run['rows_new'];
    }
    if ($run['rows_predictions'] !== null) {
        $parts[] = 'predictions ' . (int)$run['rows_predictions'];
    }
    if ($run['rows_potential_duplicates'] !== null) {
        $parts[] = 'potential dupes ' . (int)$run['rows_potential_duplicates'];
    }
    if ($run['rows_exact_suppressed'] !== null) {
        $parts[] = 'exact suppressed ' . (int)$run['rows_exact_suppressed'];
    }
    if ($run['rows_repaired'] !== null && (int)$run['rows_repaired'] > 0) {
        $parts[] = 'repaired ' . (int)$run['rows_repaired'];
    }
    if ($run['rows_malformed'] !== null && (int)$run['rows_malformed'] > 0) {
        $parts[] = 'malformed ' . (int)$run['rows_malformed'];
    }
    if ($run['rows_non_billed'] !== null && (int)$run['rows_non_billed'] > 0) {
        $parts[] = 'non-billed ' . (int)$run['rows_non_billed'];
    }
    if ($run['rows_unresolved_accounts'] !== null && (int)$run['rows_unresolved_accounts'] > 0) {
        $parts[] = 'unresolved accounts ' . (int)$run['rows_unresolved_accounts'];
    }

    return empty($parts) ? 'No parser summary captured' : implode(' • ', $parts);
}

function get_recent_import_runs(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    $sql = "
        SELECT
            ir.*,
            req.name AS requested_account_name,
            GROUP_CONCAT(DISTINCT a.name ORDER BY a.name SEPARATOR ', ') AS account_names
        FROM import_runs ir
        LEFT JOIN accounts req ON req.id = ir.requested_account_id
        LEFT JOIN import_run_accounts ira ON ira.import_run_id = ir.id
        LEFT JOIN accounts a ON a.id = ira.account_id
        GROUP BY ir.id
        ORDER BY ir.created_at DESC
        LIMIT {$limit}
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}