<?php
/**
 * BKL-005 — Prediction job runner with lock + throttle
 *
 * - Prevents concurrent runs via flock()
 * - Prevents repeated runs via a JSON state file (min interval)
 * - Streams python output to a log file
 *
 * Key fix:
 * - Do NOT trust proc_close() return code (can be -1 on success).
 * - Use proc_get_status()['exitcode'] captured after the process ends.
 */

require_once __DIR__ . '/../config/db.php';

function _pi_logs_dir(): string {
    $dir = app_config('logging.dir', __DIR__ . '/../logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return rtrim($dir, '/');
}

function _pi_paths(): array {
    $logs = _pi_logs_dir();
    return [
        'lock'   => $logs . '/predict_instances.lock',
        'state'  => $logs . '/predict_instances_state.json',
        'output' => $logs . '/predict_instances_output.log',
    ];
}

function _pi_now_iso(): string {
    $tzName = app_config('timezone', 'UTC');
    try {
        return (new DateTime('now', new DateTimeZone($tzName)))->format('c');
    } catch (Throwable $e) {
        return date('c');
    }
}

function _pi_read_state(): array {
    $paths = _pi_paths();
    if (!file_exists($paths['state'])) return [];
    $raw = @file_get_contents($paths['state']);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function _pi_write_state(array $state): void {
    $paths = _pi_paths();
    $tmp = $paths['state'] . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT);
    if ($json === false) return;
    @file_put_contents($tmp, $json);
    @rename($tmp, $paths['state']);
}

function _pi_tail_lines(string $filePath, int $lines = 80): string {
    if (!file_exists($filePath)) return '';
    $fp = @fopen($filePath, 'rb');
    if (!$fp) return '';

    $buffer = '';
    $chunkSize = 4096;
    $pos = -1;
    $lineCount = 0;

    fseek($fp, 0, SEEK_END);
    $fileSize = ftell($fp);

    while ($fileSize + $pos > 0 && $lineCount <= $lines) {
        $seek = max($fileSize + $pos - $chunkSize, 0);
        $read = ($fileSize + $pos) - $seek;
        fseek($fp, $seek);
        $chunk = fread($fp, $read);
        if ($chunk === false) break;

        $buffer = $chunk . $buffer;
        $lineCount = substr_count($buffer, "\n");
        $pos -= $chunkSize;
    }

    fclose($fp);

    $all = preg_split("/\r\n|\n|\r/", $buffer);
    if (!is_array($all)) return '';
    $tail = array_slice($all, max(count($all) - $lines, 0));
    return trim(implode("\n", $tail));
}

function get_predict_instances_output_tail(int $lines = 80): string {
    $paths = _pi_paths();
    return _pi_tail_lines($paths['output'], $lines);
}

function get_predict_instances_state(): array {
    $state = _pi_read_state();
    return array_merge([
        'job_name' => 'predict_instances',
        'last_start' => null,
        'last_end' => null,
        'last_status' => null,      // success|failed|running
        'last_exit_code' => null,
        'last_trigger' => null,     // auto|manual|cli
        'last_message' => null,
        'last_runtime_seconds' => null,
    ], $state);
}

/**
 * Returns:
 *  [
 *    'ran' => bool,
 *    'status' => 'success'|'failed'|'skipped'|'running',
 *    'message' => string,
 *    'exit_code' => int|null,
 *    'output_tail' => string,
 *    'state' => array
 *  ]
 */
function run_predict_instances_job(bool $force = false, string $trigger = 'manual'): array {
    $paths = _pi_paths();
    $state = get_predict_instances_state();

    // Throttle settings
    $minSuccessMins = (int) app_config('jobs.predictions.min_interval_minutes', 360);            // 6 hours
    $minFailedMins  = (int) app_config('jobs.predictions.min_interval_failed_minutes', 10);     // 10 minutes
    $tailLines      = (int) app_config('jobs.predictions.output_tail_lines', 120);

    // Throttle check (based on last_end + last_status)
    if (!$force && !empty($state['last_end'])) {
        $status = $state['last_status'] ?? null;
        $intervalMins = ($status === 'failed') ? $minFailedMins : $minSuccessMins;

        try {
            $lastEnd = new DateTime($state['last_end']);
            $now = new DateTime(_pi_now_iso());
            $diffSeconds = $now->getTimestamp() - $lastEnd->getTimestamp();

            if ($diffSeconds >= 0 && $diffSeconds < ($intervalMins * 60)) {
                $minsAgo = (int) floor($diffSeconds / 60);
                return [
                    'ran' => false,
                    'status' => 'skipped',
                    'message' => "Skipped: forecast engine last ran {$minsAgo} minute(s) ago (min interval {$intervalMins}m).",
                    'exit_code' => null,
                    'output_tail' => '',
                    'state' => $state
                ];
            }
        } catch (Throwable $e) {
            // If parsing fails, continue to lock + run
        }
    }

    // Lock (prevents concurrent runs)
    $lockFp = @fopen($paths['lock'], 'c+');
    if (!$lockFp) {
        app_log("BKL-005: Unable to open lock file: {$paths['lock']}", "ERROR");
        return [
            'ran' => false,
            'status' => 'failed',
            'message' => "Error: Unable to open lock file.",
            'exit_code' => null,
            'output_tail' => '',
            'state' => $state
        ];
    }

    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        return [
            'ran' => false,
            'status' => 'running',
            'message' => "A forecast run is already in progress. Please try again in a minute.",
            'exit_code' => null,
            'output_tail' => '',
            'state' => $state
        ];
    }

    @set_time_limit(0);

    $startIso = _pi_now_iso();
    $runningState = [
        'job_name' => 'predict_instances',
        'last_start' => $startIso,
        'last_end' => null,
        'last_status' => 'running',
        'last_exit_code' => null,
        'last_trigger' => $trigger,
        'last_message' => 'Running…',
        'last_runtime_seconds' => null,
    ];
    _pi_write_state($runningState);

    @file_put_contents(
        $paths['output'],
        "=== predict_instances.py run started {$startIso} (trigger={$trigger}, force=" . ($force ? "1" : "0") . ") ===\n"
    );

    $scriptPath = realpath(__DIR__ . '/predict_instances.py');
    if (!$scriptPath || !file_exists($scriptPath)) {
        $endIso = _pi_now_iso();
        $failState = $runningState;
        $failState['last_end'] = $endIso;
        $failState['last_status'] = 'failed';
        $failState['last_exit_code'] = 127;
        $failState['last_message'] = 'predict_instances.py not found.';
        _pi_write_state($failState);

        flock($lockFp, LOCK_UN);
        fclose($lockFp);

        return [
            'ran' => false,
            'status' => 'failed',
            'message' => "Error: predict_instances.py not found.",
            'exit_code' => 127,
            'output_tail' => _pi_tail_lines($paths['output'], $tailLines),
            'state' => $failState
        ];
    }

    $cmd = "python3 " . escapeshellarg($scriptPath);

    $descriptorspec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $cwd = dirname($scriptPath);
    $process = @proc_open($cmd, $descriptorspec, $pipes, $cwd);

    $exitCode = 1;

    if (!is_resource($process)) {
        @file_put_contents($paths['output'], "ERROR: Unable to start process: {$cmd}\n", FILE_APPEND);
        $exitCode = 126;
    } else {
        $outFp = @fopen($paths['output'], 'ab');

        // IMPORTANT: capture exitcode from proc_get_status after completion
        $exitCodeFromStatus = null;

        if ($outFp) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $status = proc_get_status($process);
                $running = $status['running'];

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);

                if ($stdout !== false && $stdout !== '') fwrite($outFp, $stdout);
                if ($stderr !== false && $stderr !== '') fwrite($outFp, $stderr);

                if (!$running) {
                    // Drain once more
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    if ($stdout !== false && $stdout !== '') fwrite($outFp, $stdout);
                    if ($stderr !== false && $stderr !== '') fwrite($outFp, $stderr);

                    $exitCodeFromStatus = $status['exitcode'];
                    break;
                }

                usleep(200000); // 200ms
            }

            fclose($outFp);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        // proc_close is still useful to clean up, but its return code is unreliable
        @proc_close($process);

        // Use the exitcode from proc_get_status. If it's null/-1, fall back to 1.
        if ($exitCodeFromStatus !== null && (int)$exitCodeFromStatus >= 0) {
            $exitCode = (int)$exitCodeFromStatus;
        } else {
            $exitCode = 1;
        }
    }

    $endIso = _pi_now_iso();
    $runtimeSeconds = null;
    try {
        $runtimeSeconds = (new DateTime($endIso))->getTimestamp() - (new DateTime($startIso))->getTimestamp();
    } catch (Throwable $e) {}

    $ok = ((int)$exitCode === 0);

    @file_put_contents(
        $paths['output'],
        "\n=== predict_instances.py run ended {$endIso} | exit_code={$exitCode} ===\n",
        FILE_APPEND
    );

    $finalState = [
        'job_name' => 'predict_instances',
        'last_start' => $startIso,
        'last_end' => $endIso,
        'last_status' => $ok ? 'success' : 'failed',
        'last_exit_code' => (int)$exitCode,
        'last_trigger' => $trigger,
        'last_message' => $ok ? 'Completed successfully.' : 'Completed with errors.',
        'last_runtime_seconds' => $runtimeSeconds,
    ];
    _pi_write_state($finalState);

    app_log(
        "BKL-005: predict_instances run complete (trigger={$trigger}, force=" . ($force ? "1" : "0") . ", exit={$exitCode}, runtime=" . ($runtimeSeconds ?? 'n/a') . "s)",
        $ok ? "INFO" : "ERROR"
    );

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    $tail = _pi_tail_lines($paths['output'], $tailLines);

    return [
        'ran' => true,
        'status' => $ok ? 'success' : 'failed',
        'message' => $ok ? "✅ Reforecasting complete." : "❌ Reforecast failed (exit code {$exitCode}).",
        'exit_code' => (int)$exitCode,
        'output_tail' => $tail,
        'state' => $finalState
    ];
}

// CLI convenience
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $force = in_array('--force', $argv ?? [], true);
    $res = run_predict_instances_job($force, 'cli');
    echo $res['message'] . PHP_EOL;
    if (!empty($res['output_tail'])) {
        echo "---- output tail ----" . PHP_EOL;
        echo $res['output_tail'] . PHP_EOL;
    }
    exit(($res['status'] ?? '') === 'success' ? 0 : 1);
}
