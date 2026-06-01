<?php

declare(strict_types=1);

function review_action_start_session(): void
{
    if (function_exists('auth_session_start')) {
        auth_session_start();
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function review_action_clean_message(string $message): string
{
    $message = trim(strip_tags($message));
    $message = preg_replace('/^[^A-Za-z0-9]+/', '', $message) ?? $message;
    $message = trim($message);

    return $message !== '' ? $message : 'Review action failed.';
}

function review_action_set_flash(string $type, string $message): void
{
    review_action_start_session();
    $_SESSION['review_flash'] = [
        'type' => $type,
        'message' => review_action_clean_message($message),
    ];
}

function review_action_redirect_error(string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    review_action_set_flash('error', $message);
    http_response_code(303);
    header('Location: review.php');
    exit;
}

ob_start();

register_shutdown_function(function (): void {
    $output = '';
    if (ob_get_level() > 0) {
        $output = (string)ob_get_clean();
    }

    if (trim($output) === '') {
        return;
    }

    if (headers_sent()) {
        echo $output;
        return;
    }

    review_action_set_flash('error', $output);
    http_response_code(303);
    header('Location: review.php');
});

try {
    require __DIR__ . '/review_actions_handler.php';
} catch (Throwable $e) {
    review_action_redirect_error('Review action failed: ' . $e->getMessage());
}
