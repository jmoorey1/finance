<?php
/**
 * Home Finances System — App Configuration (BKL-001)
 *
 * Goal: Safe single-prod deployments.
 * - Feature flags for phased rollouts
 * - Optional maintenance mode
 * - Centralised logging + debug toggles
 *
 * NOTE: This file contains *no secrets*. Keep creds in config/db.php for now.
 */

if (!isset($APP_CONFIG) || !is_array($APP_CONFIG)) {
    $APP_CONFIG = [
        'app_name'    => 'Home Finances',
        'app_env'     => 'production',      // change if you ever add dev/uat
        'app_version' => '2026.01.21',       // bump when you deploy meaningful changes

        // Used later if we want to build links consistently (optional)
        'base_url_path' => '/finance',

        // Make all DateTime usage consistent
        'timezone' => 'Europe/London',

        // Optional maintenance mode (OFF by default)
        'maintenance' => [
            'enabled' => false,
            'message' => 'Home Finances is temporarily down for maintenance. Please try again in a few minutes.',
            // allowlist pages that should still work during maintenance
            'allow_paths' => [
                '/finance/public/healthcheck.php',
            ],
        ],

        // Debug behaviour (safe defaults)
        'debug' => [
            'display_errors' => false, // set true temporarily when you’re debugging
            'log_errors'     => true,
        ],

        // App logging (separate from PHP error_log)
        'logging' => [
            'dir'  => __DIR__ . '/../logs',
            'file' => 'app.log',
        ],

        // Feature flags for phased deployment later
        'features' => [
            // Reserved for later backlog items — default OFF unless already live behaviour.
            'show_env_banner'       => false,
            'enable_auth'           => false,
            'enforce_csrf'          => false,

            // This reflects current behaviour (index.php can trigger reforecast)
            // We’ll harden/throttle it in BKL-005.
            'prediction_job_on_ui'  => true,
        ],
    ];
}

if (!function_exists('app_config')) {
    /**
     * Fetch config with optional dot-notation:
     *  app_config('maintenance.enabled')
     */
    function app_config(?string $key = null, $default = null) {
        global $APP_CONFIG;

        if ($key === null) {
            return $APP_CONFIG;
        }

        $parts = explode('.', $key);
        $value = $APP_CONFIG;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return $default;
            }
        }

        return $value;
    }
}

if (!function_exists('feature_enabled')) {
    function feature_enabled(string $flag, bool $default = false): bool {
        $features = app_config('features', []);
        if (!is_array($features)) {
            return $default;
        }
        return array_key_exists($flag, $features) ? (bool)$features[$flag] : $default;
    }
}

if (!function_exists('app_log')) {
    /**
     * Lightweight app log. Safe in production: failures are silent.
     */
    function app_log(string $message, string $level = 'INFO'): void {
        $logDir  = app_config('logging.dir', __DIR__ . '/../logs');
        $logFile = app_config('logging.file', 'app.log');
        $path    = rtrim($logDir, '/').'/'.$logFile;

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $tzName = app_config('timezone', 'UTC');
        try {
            $ts = (new DateTime('now', new DateTimeZone($tzName)))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $ts = date('Y-m-d H:i:s');
        }

        $line = sprintf("[%s] [%s] %s\n", $ts, $level, $message);
        @file_put_contents($path, $line, FILE_APPEND);
    }
}
