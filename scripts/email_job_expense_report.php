<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/job_expense_report_builder.php';
require_once __DIR__ . '/lib/job_expense_report_delivery.php';

if (!is_cli_request()) {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

function job_expense_email_acquire_lock(PDO $pdo, string $lockName): bool
{
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
    $stmt->execute([$lockName]);
    return (int)$stmt->fetchColumn() === 1;
}

function job_expense_email_release_lock(PDO $pdo, string $lockName): void
{
    try {
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);
    } catch (Throwable $e) {
        // Do not mask the main job result.
    }
}

$options = getopt('', ['dry-run', 'preset:', 'from:', 'to-date:', 'person:', 'to:', 'subject:']);
$dryRun = array_key_exists('dry-run', $options);

$input = [
    'preset' => isset($options['preset']) ? (string)$options['preset'] : '12m',
    'from' => isset($options['from']) ? (string)$options['from'] : '',
    'to' => isset($options['to-date']) ? (string)$options['to-date'] : '',
    'person' => isset($options['person']) ? (string)$options['person'] : 'both',
];

$lockName = 'finance:job_expense_report_email';
if (!job_expense_email_acquire_lock($pdo, $lockName)) {
    app_log('Job expense report skipped because another run already holds the lock.', 'WARNING');
    fwrite(STDERR, "Job expense report is already running.\n");
    exit(2);
}

try {
    $report = jer_build_report($pdo, $input);

    $recipients = isset($options['to'])
        ? normalize_email_recipients((string)$options['to'])
        : jer_default_recipients();

    $subject = isset($options['subject']) && trim((string)$options['subject']) !== ''
        ? trim((string)$options['subject'])
        : jer_subject($report);

    if ($dryRun) {
        echo "DRY RUN — no email sent.\n";
        echo "To: " . implode(', ', $recipients) . "\n";
        echo "Subject: {$subject}\n";
        echo "Selection: " . $report['selection']['label'] . "\n";
        echo "Range: " . $report['range']['label'] . "\n\n";
        echo jer_email_text($report);
        exit(0);
    }

    // Override only the subject if explicitly provided.
    if ($subject !== jer_subject($report)) {
        $fromName = (string)app_config('weekly_email.from_name', 'Home Finances');
        $fromAddress = (string)app_config('weekly_email.from_address', 'no-reply@moorey.uk.com');

        $runId = email_run_start($pdo, [
            'job_name' => 'job_expense_report',
            'run_mode' => 'live',
            'effective_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'summary_period_start' => $report['range']['from']->format('Y-m-d'),
            'summary_period_end' => $report['range']['to']->format('Y-m-d'),
            'recipients' => implode(', ', $recipients),
            'subject' => $subject,
        ]);

        try {
            send_html_email(
                $recipients,
                $subject,
                jer_email_html($report),
                jer_email_text($report),
                $fromName,
                $fromAddress
            );
            email_run_finish($pdo, $runId, 'success', null);
        } catch (Throwable $e) {
            email_run_finish($pdo, $runId, 'failed', $e->getMessage());
            throw $e;
        }
    } else {
        jer_send_email($pdo, $report, $recipients);
    }

    app_log('Job expense report email sent successfully.', 'INFO');
    echo "Job expense report sent successfully.\n";
    exit(0);
} catch (Throwable $e) {
    app_log('Job expense report failed: ' . $e->getMessage(), 'ERROR');
    fwrite(STDERR, "Job expense report failed: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    job_expense_email_release_lock($pdo, $lockName);
}
