<?php

declare(strict_types=1);

namespace App\Services;

final class InvoiceSaveHttpTrace
{
    private static ?self $active = null;

    private float $startedAt;
    private string $stage = 'php_entered';
    private bool $finished = false;

    private function __construct(
        private readonly string $basePath,
        private readonly string $requestId,
        private readonly int $bookingId,
        private readonly int $serviceId
    ) {
        $this->startedAt = microtime(true);
    }

    public static function beginForCurrentRequest(string $basePath): ?self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || ! is_string($path)
            || ! str_ends_with($path, '/workspace/services/financial-correction')) {
            return null;
        }

        $trace = new self(
            $basePath,
            app_request_id(),
            (int) ($_POST['booking_id'] ?? 0),
            (int) ($_POST['service_id'] ?? 0)
        );
        self::$active = $trace;
        $trace->write('started');
        register_shutdown_function([$trace, 'finish']);

        return $trace;
    }

    public static function checkpointActive(string $stage): void
    {
        self::$active?->checkpoint($stage);
    }

    public function checkpoint(string $stage): void
    {
        $this->stage = $stage;
        $this->write('checkpoint');
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $fatal = is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)
            ? $lastError
            : null;
        $status = http_response_code();
        $this->write('finished', [
            'http_status' => is_int($status) ? $status : null,
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'fatal_type' => $fatal['type'] ?? null,
            'fatal_file' => isset($fatal['file']) ? basename((string) $fatal['file']) : null,
            'fatal_line' => $fatal['line'] ?? null,
        ]);
    }

    private function write(string $event, array $details = []): void
    {
        $record = array_merge([
            'time_utc' => gmdate('c'),
            'request_id' => $this->requestId,
            'booking_id' => $this->bookingId,
            'service_id' => $this->serviceId,
            'event' => $event,
            'stage' => $this->stage,
        ], $details);
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            return;
        }

        try {
            $directory = $this->basePath . '/storage/logs';
            if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Invoice diagnostic log directory is unavailable.');
            }
            $path = $directory . '/invoice-save-http-' . gmdate('Ymd') . '.jsonl';
            $isNewFile = ! is_file($path);
            if (file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Invoice diagnostic log file is unavailable.');
            }
            if ($isNewFile) {
                try {
                    chmod($path, 0640);
                } catch (\Throwable) {
                    // Permission hardening must never affect the invoice request.
                }
            }
        } catch (\Throwable) {
            try {
                error_log('[invoice-save-http-log-write-failed] ' . $encoded);
            } catch (\Throwable) {
                // Logging is diagnostic only and must not affect financial writes.
            }
        }
    }
}
