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

        // Weekly email digest configuration (non-secret)
        'weekly_email' => [
            'enabled' => true,
            'recipients' => [
                'john@moorey.uk.com',
                'india@moorey.uk.com',
                'indiamo@amazon.co.uk',
            ],
            'from_name' => 'Home Finances',
            'from_address' => 'no-reply@moorey.uk.com',
            'subject' => 'Weekly Budget Summary – Variable Expenses',
            'lock_name' => 'finance:weekly_email_summary',
        ],

        // Watcher / analyst baseline configuration
        'watcher' => [
            'enabled' => true,
            'forecast_days' => 90,
            'shortfall_window_days' => 31,
            'index_alert_limit' => 5,
            'forecast_quality' => [
                'rule_history_limit' => 6,
                'date_drift_min_fulfilled' => 4,
                'date_drift_consistency_ratio' => 0.75,
                'amount_drift_min_fulfilled' => 4,
                'amount_drift_percent_threshold' => 0.15,
                'amount_drift_abs_threshold' => 10.00,
                'amount_drift_cluster_ratio' => 0.75,
                'missing_pattern_lookback_days' => 180,
                'missing_pattern_min_occurrences' => 4,
                'missing_pattern_amount_consistency_pct' => 0.20,
                'prediction_miss_count_threshold' => 3,
                'prediction_miss_count_critical' => 5,
                'review_backlog_min_count' => 5,
                'review_backlog_age_days' => 3,
                'review_backlog_critical_count' => 15,
                'review_backlog_critical_age_days' => 7,
            ],
            'budget_quality' => [
                'burn_ratio_threshold' => 0.85,
                'burn_month_progress_cap' => 0.80,
                'burn_min_budget_amount' => 50.00,
                'unrealistic_overrun_pct' => 0.20,
                'unrealistic_overrun_abs' => 50.00,
                'timing_future_budget_months' => 3,
                'timing_future_budget_min' => 100.00,
                'timing_current_gap_abs' => 75.00,
            ],
        ],

        // Feature flags for phased deployment later
        'features' => [
            // Reserved for later backlog items — default OFF unless already live behaviour.
            'show_env_banner'       => false,
            'enable_auth'           => true,
            'enforce_csrf'          => true,

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
