<?php

function email_run_start(PDO $pdo, array $payload): int
{
    $stmt = $pdo->prepare("
        INSERT INTO email_runs (
            job_name,
            run_mode,
            status,
            effective_date,
            summary_period_start,
            summary_period_end,
            recipients,
            subject
        ) VALUES (?, ?, 'running', ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (string)($payload['job_name'] ?? 'unknown_job'),
        (string)($payload['run_mode'] ?? 'live'),
        $payload['effective_date'] ?? null,
        $payload['summary_period_start'] ?? null,
        $payload['summary_period_end'] ?? null,
        $payload['recipients'] ?? null,
        $payload['subject'] ?? null,
    ]);

    return (int)$pdo->lastInsertId();
}

function email_run_finish(PDO $pdo, int $runId, string $status, ?string $errorMessage = null): void
{
    $stmt = $pdo->prepare("
        UPDATE email_runs
        SET status = ?,
            error_message = ?,
            finished_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $status,
        $errorMessage,
        $runId,
    ]);
}
