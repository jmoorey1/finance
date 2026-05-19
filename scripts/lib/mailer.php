<?php

function normalize_email_recipients($input): array
{
    $items = is_array($input) ? $input : preg_split('/[;,]+/', (string)$input);
    $out = [];

    foreach ($items as $item) {
        $email = trim((string)$item);
        if ($email === '') {
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email recipient: {$email}");
        }

        $out[] = strtolower($email);
    }

    $out = array_values(array_unique($out));

    if (empty($out)) {
        throw new InvalidArgumentException('No valid email recipients were provided.');
    }

    return $out;
}

function mail_header_safe_text(string $text): string
{
    $text = str_replace(["\r", "\n"], ' ', $text);
    $text = trim($text);

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
    }

    return $text;
}

function send_html_email(
    array $recipients,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $fromName,
    string $fromAddress
): void {
    $recipients = normalize_email_recipients($recipients);

    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Invalid from address: {$fromAddress}");
    }

    $fromName = str_replace(["\r", "\n"], ' ', trim($fromName));
    $subject = trim($subject);

    $encodedSubject = mail_header_safe_text($subject);
    $encodedFromName = mail_header_safe_text($fromName);

    $boundary = '=_FinanceBoundary_' . md5((string)microtime(true));

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = "From: {$encodedFromName} <{$fromAddress}>";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--\r\n";

    $toHeader = implode(', ', $recipients);
    $ok = @mail($toHeader, $encodedSubject, $body, implode("\r\n", $headers));

    if (!$ok) {
        throw new RuntimeException('mail() returned false.');
    }
}
