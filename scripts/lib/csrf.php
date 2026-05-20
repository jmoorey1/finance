<?php

if (!function_exists('csrf_is_enabled')) {
    function csrf_is_enabled(): bool
    {
        return feature_enabled('enforce_csrf', false);
    }
}

if (!function_exists('csrf_session_start')) {
    function csrf_session_start(): void
    {
        if (function_exists('auth_session_start')) {
            auth_session_start();
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        csrf_session_start();

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_input')) {
    function csrf_input(): string
    {
        $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }
}

if (!function_exists('csrf_current_request_path')) {
    function csrf_current_request_path(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }
}

if (!function_exists('csrf_request_token')) {
    function csrf_request_token(): ?string
    {
        $post = $_POST['_csrf'] ?? null;
        if (is_string($post) && $post !== '') {
            return $post;
        }

        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return null;
    }
}

if (!function_exists('csrf_is_exempt_path')) {
    function csrf_is_exempt_path(string $path): bool
    {
        $exempt = [
            '/finance/public/healthcheck.php',
        ];

        return in_array($path, $exempt, true);
    }
}

if (!function_exists('csrf_validate_request')) {
    function csrf_validate_request(): bool
    {
        csrf_session_start();

        $submitted = csrf_request_token();
        $stored = $_SESSION['csrf_token'] ?? null;

        return is_string($submitted)
            && is_string($stored)
            && $submitted !== ''
            && $stored !== ''
            && hash_equals($stored, $submitted);
    }
}

if (!function_exists('csrf_require_valid_post')) {
    function csrf_require_valid_post(): void
    {
        if (!csrf_is_enabled()) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $path = csrf_current_request_path();
        if (csrf_is_exempt_path($path)) {
            return;
        }

        if (!csrf_validate_request()) {
            app_log('CSRF validation failed for path: ' . $path, 'WARNING');
            http_response_code(419);
            header('Content-Type: text/plain; charset=utf-8');
            echo "CSRF validation failed.";
            exit;
        }
    }
}
