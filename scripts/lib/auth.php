<?php

if (!function_exists('auth_is_enabled')) {
    function auth_is_enabled(): bool
    {
        return feature_enabled('enable_auth', false);
    }
}

if (!function_exists('auth_session_start')) {
    function auth_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        session_name('finance_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

if (!function_exists('auth_current_request_uri')) {
    function auth_current_request_uri(): string
    {
        return (string)($_SERVER['REQUEST_URI'] ?? '/finance/public/index.php');
    }
}

if (!function_exists('auth_current_request_path')) {
    function auth_current_request_path(): string
    {
        $uri = auth_current_request_uri();
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/finance/public/index.php';
    }
}

if (!function_exists('auth_current_username')) {
    function auth_current_username(): ?string
    {
        auth_session_start();

        $username = $_SESSION['auth_username'] ?? null;
        return is_string($username) && $username !== '' ? $username : null;
    }
}

if (!function_exists('auth_is_logged_in')) {
    function auth_is_logged_in(): bool
    {
        return auth_current_username() !== null;
    }
}

if (!function_exists('auth_expected_username')) {
    function auth_expected_username(): string
    {
        return (string)env_value('FINANCE_AUTH_USERNAME', 'john');
    }
}

if (!function_exists('auth_expected_password_hash')) {
    function auth_expected_password_hash(): string
    {
        return (string)env_value('FINANCE_AUTH_PASSWORD_HASH', '');
    }
}

if (!function_exists('auth_attempt_login')) {
    function auth_attempt_login(string $username, string $password): bool
    {
        $expectedUsername = auth_expected_username();
        $expectedHash = auth_expected_password_hash();

        if ($expectedHash === '') {
            throw new RuntimeException('FINANCE_AUTH_PASSWORD_HASH is not configured.');
        }

        if (!hash_equals($expectedUsername, $username)) {
            return false;
        }

        if (!password_verify($password, $expectedHash)) {
            return false;
        }

        auth_session_start();
        session_regenerate_id(true);
        $_SESSION['auth_username'] = $expectedUsername;
        $_SESSION['auth_logged_in_at'] = time();

        return true;
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void
    {
        auth_session_start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();
    }
}

if (!function_exists('auth_login_url')) {
    function auth_login_url(): string
    {
        return '/finance/public/login.php';
    }
}

if (!function_exists('auth_logout_url')) {
    function auth_logout_url(): string
    {
        return '/finance/public/logout.php';
    }
}

if (!function_exists('auth_is_allowlisted_path')) {
    function auth_is_allowlisted_path(string $requestPath): bool
    {
        $allowPaths = [
            '/finance/public/login.php',
            '/finance/public/logout.php',
            '/finance/public/healthcheck.php',
        ];

        return in_array($requestPath, $allowPaths, true);
    }
}

if (!function_exists('auth_safe_next_url')) {
    function auth_safe_next_url(?string $next): string
    {
        $default = '/finance/public/index.php';
        $next = trim((string)$next);

        if ($next === '') {
            return $default;
        }

        if (!str_starts_with($next, '/finance/')) {
            return $default;
        }

        $path = parse_url($next, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return $default;
        }

        if (auth_is_allowlisted_path($path)) {
            return $default;
        }

        return $next;
    }
}

if (!function_exists('auth_redirect_to_login')) {
    function auth_redirect_to_login(): void
    {
        $path = auth_current_request_path();
        if (auth_is_allowlisted_path($path)) {
            return;
        }

        $requestUri = auth_current_request_uri();
        $target = auth_login_url() . '?next=' . urlencode($requestUri);
        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('auth_require_login')) {
    function auth_require_login(): void
    {
        if (!auth_is_enabled()) {
            return;
        }

        $path = auth_current_request_path();
        if (auth_is_allowlisted_path($path)) {
            return;
        }

        auth_session_start();

        if (!auth_is_logged_in()) {
            auth_redirect_to_login();
        }
    }
}