<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/finance_periods.php';
require_once __DIR__ . '/lib/weekly_summary_builder.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/email_run_logger.php';

if (!is_cli_request()) {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

function weekly_email_acquire_lock(PDO $pdo, string $lockName): bool
{
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
    $stmt->execute([$lockName]);
    return (int)$stmt->fetchColumn() === 1;
}

function weekly_email_release_lock(PDO $pdo, string $lockName): void
{
    try {
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);
    } catch (Throwable $e) {
        // Do not mask the main job result with lock-release issues.
    }
}

$options = getopt('', ['dry-run', 'date:', 'to:']);
$dryRun = array_key_exists('dry-run', $options);

try {
    $effectiveDate = isset($options['date'])
        ? new DateTimeImmutable((string)$options['date'])
        : new DateTimeImmutable('now');
} catch (Throwable $e) {
    fwrite(STDERR, "Invalid --date value.\n");
    exit(1);
}

if (!$dryRun && !app_config('weekly_email.enabled', true)) {
    echo "Weekly email is disabled in config.\n";
    exit(0);
}

$lockName = (string)app_config('weekly_email.lock_name', 'finance:weekly_email_summary');
if (!weekly_email_acquire_lock($pdo, $lockName)) {
    app_log('Weekly email skipped because another run already holds the lock.', 'WARNING');
    fwrite(STDERR, "Weekly email is already running.\n");
    exit(2);
}

$runId = null;

try {
    $configuredRecipients = app_config('weekly_email.recipients', []);
    $recipients = isset($options['to'])
        ? normalize_email_recipients((string)$options['to'])
        : normalize_email_recipients($configuredRecipients);

    $subject = (string)app_config('weekly_email.subject', 'Weekly Budget Summary – Variable Expenses');
    $fromName = (string)app_config('weekly_email.from_name', 'Home Finances');
    $fromAddress = (string)app_config('weekly_email.from_address', 'no-reply@moorey.uk.com');

    $period = get_weekly_digest_reporting_range($effectiveDate);

    $runId = email_run_start($pdo, [
        'job_name' => 'weekly_budget_summary',
        'run_mode' => $dryRun ? 'dry_run' : 'live',
        'effective_date' => $effectiveDate->format('Y-m-d'),
        'summary_period_start' => $period['start']->format('Y-m-d'),
        'summary_period_end' => $period['end']->format('Y-m-d'),
        'recipients' => implode(', ', $recipients),
        'subject' => $subject,
    ]);

    $summary = weekly_summary_build($pdo, $effectiveDate);
    $htmlBody = weekly_summary_render_html($summary);
    $textBody = weekly_summary_render_text($summary);

    if ($dryRun) {
        echo "DRY RUN — no email sent.\n";
        echo "To: " . implode(', ', $recipients) . "\n";
        echo "Subject: {$subject}\n";
        echo "Period: " . $summary['start_month']->format('Y-m-d') . " to " . $summary['end_month']->format('Y-m-d') . "\n\n";
        echo $textBody;

        email_run_finish($pdo, $runId, 'success', null);
        app_log('Weekly summary dry-run completed successfully.', 'INFO');
        exit(0);
    }

    send_html_email(
        $recipients,
        $subject,
        $htmlBody,
        $textBody,
        $fromName,
        $fromAddress
    );

    email_run_finish($pdo, $runId, 'success', null);
    app_log('Weekly summary email sent successfully.', 'INFO');

    echo "Weekly summary sent successfully.\n";
    exit(0);
} catch (Throwable $e) {
    $message = $e->getMessage();

    if ($runId !== null) {
        email_run_finish($pdo, $runId, 'failed', $message);
    }

    app_log('Weekly summary failed: ' . $message, 'ERROR');
    fwrite(STDERR, "Weekly summary failed: {$message}\n");
    exit(1);
} finally {
    weekly_email_release_lock($pdo, $lockName);
}
