<?php

declare(strict_types=1);

require_once '../config/db.php';

function review_page_start_session(): void
{
    if (function_exists('auth_session_start')) {
        auth_session_start();
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function review_page_take_flash(): ?array
{
    review_page_start_session();

    $flash = $_SESSION['review_flash'] ?? null;
    unset($_SESSION['review_flash']);

    if (is_array($flash) && isset($flash['type'], $flash['message'])) {
        return [
            'type' => (string)$flash['type'],
            'message' => (string)$flash['message'],
        ];
    }

    if ((string)($_GET['success'] ?? '') === '1') {
        return [
            'type' => 'success',
            'message' => 'Review action completed.',
        ];
    }

    return null;
}

function review_page_flash_html(array $flash): string
{
    $type = $flash['type'] === 'success' ? 'success' : 'error';
    $message = htmlspecialchars((string)$flash['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<div class="message ' . $type . '">' . $message . '</div>';
}

$reviewFlash = review_page_take_flash();

ob_start();
require __DIR__ . '/review_view.php';
$page = (string)ob_get_clean();

if ($reviewFlash !== null) {
    $flashHtml = review_page_flash_html($reviewFlash);
    $page = preg_replace(
        '/(<h1>Review Staging Transactions<\/h1>)/',
        '$1' . "\n\n" . $flashHtml,
        $page,
        1,
        $count
    ) ?? $page;

    if (($count ?? 0) === 0) {
        $page = $flashHtml . "\n" . $page;
    }
}

echo $page;
