<?php
require_once __DIR__ . '/job_expense_report_builder.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_run_logger.php';

function jer_default_recipients(): array
{
    return normalize_email_recipients(app_config('weekly_email.recipients', []));
}

function jer_subject(array $report): string
{
    return 'Job Expense Report – ' . (string)$report['selection']['label'] . ' – ' . (string)$report['range']['label'];
}

function jer_email_html(array $report): string
{
    $html = '<h2 style="font-family:sans-serif;">Job Expense Report</h2>';
    $html .= '<p style="font-family:sans-serif;">Selection: <strong>' . jer_h((string)$report['selection']['label']) . '</strong><br>';
    $html .= 'Range: <strong>' . jer_h((string)$report['range']['label']) . '</strong></p>';

    $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%; margin-bottom: 24px;">';
    $html .= '<tr style="background:#333; color:#fff;"><th align="left" colspan="4">' . jer_h((string)$report['summary_label']) . '</th></tr>';
    $html .= '<tr>';
    $html .= '<td style="border:1px solid #ccc;">Outgoings<br><strong>' . jer_money((float)$report['combined']['total_outgoing']) . '</strong></td>';
    $html .= '<td style="border:1px solid #ccc;">Incoming offsets<br><strong>' . jer_money((float)$report['combined']['total_incoming']) . '</strong></td>';
    $html .= '<td style="border:1px solid #ccc;">Net position<br><strong>' . jer_money((float)$report['combined']['net_position']) . '</strong></td>';
    $html .= '<td style="border:1px solid #ccc;">Transactions<br><strong>' . (int)$report['combined']['transaction_count'] . '</strong></td>';
    $html .= '</tr>';
    $html .= '</table>';

    foreach ($report['sections'] as $section) {
        $html .= '<h3 style="font-family:sans-serif; margin-top: 24px;">' . jer_h((string)$section['label']) . '</h3>';

        $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 14px; width: 100%; margin-bottom: 12px;">';
        $html .= '<tr style="background:#f2f2f2;">';
        $html .= '<td style="border:1px solid #ccc;">Outgoings<br><strong>' . jer_money((float)$section['total_outgoing']) . '</strong></td>';
        $html .= '<td style="border:1px solid #ccc;">Incoming offsets<br><strong>' . jer_money((float)$section['total_incoming']) . '</strong></td>';
        $html .= '<td style="border:1px solid #ccc;">Net position<br><strong>' . jer_money((float)$section['net_position']) . '</strong></td>';
        $html .= '<td style="border:1px solid #ccc;">Transactions<br><strong>' . (int)$section['transaction_count'] . '</strong></td>';
        $html .= '</tr>';
        $html .= '</table>';

        $html .= '<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; font-size: 13px; width: 100%; margin-bottom: 20px;">';
        $html .= '<tr style="background:#333; color:#fff;">';
        $html .= '<th align="left">Date</th>';
        $html .= '<th align="left">Description</th>';
        $html .= '<th align="left">Account</th>';
        $html .= '<th align="right">Amount</th>';
        $html .= '<th align="right">Running Net</th>';
        $html .= '<th align="left">Source</th>';
        $html .= '</tr>';

        if (!empty($section['rows'])) {
            foreach ($section['rows'] as $row) {
                $html .= '<tr>';
                $html .= '<td style="border:1px solid #ccc;">' . jer_h((string)$row['line_date']) . '</td>';
                $html .= '<td style="border:1px solid #ccc;">' . jer_h((string)$row['description']) . '</td>';
                $html .= '<td style="border:1px solid #ccc;">' . jer_h((string)$row['account_name']) . '</td>';
                $html .= '<td align="right" style="border:1px solid #ccc;">' . jer_money((float)$row['amount']) . '</td>';
                $html .= '<td align="right" style="border:1px solid #ccc;">' . jer_money((float)$row['running_net']) . '</td>';
                $html .= '<td style="border:1px solid #ccc;">' . jer_h((string)$row['source']) . '</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="6" style="border:1px solid #ccc; color:#777;">No transactions in range.</td></tr>';
        }

        $html .= '</table>';
    }

    return $html;
}

function jer_email_text(array $report): string
{
    $lines = [];
    $lines[] = 'Job Expense Report';
    $lines[] = 'Selection: ' . (string)$report['selection']['label'];
    $lines[] = 'Range: ' . (string)$report['range']['label'];
    $lines[] = '';
    $lines[] = (string)$report['summary_label'];
    $lines[] = str_repeat('-', strlen((string)$report['summary_label']));
    $lines[] = 'Outgoings: ' . jer_money((float)$report['combined']['total_outgoing']);
    $lines[] = 'Incoming offsets: ' . jer_money((float)$report['combined']['total_incoming']);
    $lines[] = 'Net position: ' . jer_money((float)$report['combined']['net_position']);
    $lines[] = 'Transactions: ' . (int)$report['combined']['transaction_count'];
    $lines[] = '';

    foreach ($report['sections'] as $section) {
        $lines[] = (string)$section['label'];
        $lines[] = str_repeat('-', strlen((string)$section['label']));
        $lines[] = 'Outgoings: ' . jer_money((float)$section['total_outgoing']);
        $lines[] = 'Incoming offsets: ' . jer_money((float)$section['total_incoming']);
        $lines[] = 'Net position: ' . jer_money((float)$section['net_position']);
        $lines[] = 'Transactions: ' . (int)$section['transaction_count'];
        $lines[] = '';

        foreach ($section['rows'] as $row) {
            $lines[] =
                (string)$row['line_date']
                . ' | ' . (string)$row['description']
                . ' | ' . (string)$row['account_name']
                . ' | ' . jer_money((float)$row['amount'])
                . ' | running net ' . jer_money((float)$row['running_net'])
                . ' | ' . (string)$row['source'];
        }

        if (empty($section['rows'])) {
            $lines[] = 'No transactions in range.';
        }

        $lines[] = '';
    }

    return trim(implode(PHP_EOL, $lines)) . PHP_EOL;
}

function jer_send_email(PDO $pdo, array $report, array $recipients): void
{
    $subject = jer_subject($report);
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
}
