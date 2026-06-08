<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\View;
use App\Helpers\AuditLog;

final class LauncherGateMiddleware extends Middleware
{
    public function handle(): void
    {
        if (! (bool) config('security.launcher_gate.enabled', false)) {
            return;
        }

        $signatureEnabled = (bool) config('security.launcher_gate.signature_enabled', false);
        $allowLegacyToken = (bool) config('security.launcher_gate.allow_legacy_token', true);

        if ($signatureEnabled) {
            $verification = $this->verifySignedRequest();
            if (($verification['valid'] ?? false) === true) {
                return;
            }

            if ($verification['error'] ?? false) {
                $this->failClosed(503, 'security.launcher_gate.signature_unavailable', [
                    'reason' => $verification['reason'] ?? 'signature_verification_unavailable',
                    'path' => $this->requestPath(),
                ], 'Server Error');
            }

            if ($allowLegacyToken && $this->legacyTokenMatches()) {
                return;
            }

            $this->deny([
                'reason' => $verification['reason'] ?? 'signature_invalid',
                'signature_present' => (bool) ($verification['signature_present'] ?? false),
                'legacy_token_allowed' => $allowLegacyToken,
                'legacy_token_present' => $this->headerValue((string) config('security.launcher_gate.header_name', 'X-Travel-Launcher-Token')) !== '',
            ]);
        }

        if ($this->legacyTokenMatches()) {
            return;
        }

        $this->deny([
            'reason' => 'legacy_token_invalid',
            'signature_enabled' => false,
            'header_present' => $this->headerValue((string) config('security.launcher_gate.header_name', 'X-Travel-Launcher-Token')) !== '',
        ]);
    }

    private function legacyTokenMatches(): bool
    {
        $expectedToken = trim((string) config('security.launcher_gate.token', ''));
        if ($expectedToken === '') {
            $this->failClosed(503, 'security.launcher_gate.missing_token', [
                'url' => $_SERVER['REQUEST_URI'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            ], 'Server Error');
        }

        $headerName = trim((string) config('security.launcher_gate.header_name', 'X-Travel-Launcher-Token'));
        $providedToken = $this->headerValue($headerName);

        return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
    }

    /**
     * @return array{valid:bool,error?:bool,reason:string,signature_present?:bool}
     */
    private function verifySignedRequest(): array
    {
        if (! extension_loaded('openssl')) {
            return [
                'valid' => false,
                'error' => true,
                'reason' => 'openssl_extension_missing',
            ];
        }

        $publicKeyPem = $this->configuredPublicKey();
        if ($publicKeyPem === '') {
            return [
                'valid' => false,
                'error' => true,
                'reason' => 'launcher_public_key_missing',
            ];
        }

        $signatureHeaderName = (string) config('security.launcher_gate.signature_header_name', 'X-Travel-Launcher-Signature');
        $timestampHeaderName = (string) config('security.launcher_gate.timestamp_header_name', 'X-Travel-Launcher-Timestamp');
        $nonceHeaderName = (string) config('security.launcher_gate.nonce_header_name', 'X-Travel-Launcher-Nonce');
        $keyIdHeaderName = (string) config('security.launcher_gate.key_id_header_name', 'X-Travel-Launcher-Key-Id');

        $signature = $this->headerValue($signatureHeaderName);
        $timestamp = $this->headerValue($timestampHeaderName);
        $nonce = $this->headerValue($nonceHeaderName);
        $keyId = $this->headerValue($keyIdHeaderName);
        $signaturePresent = $signature !== '' || $timestamp !== '' || $nonce !== '' || $keyId !== '';

        if (! $signaturePresent) {
            return [
                'valid' => false,
                'reason' => 'signature_headers_missing',
                'signature_present' => false,
            ];
        }

        if ($signature === '' || $timestamp === '' || $nonce === '' || $keyId === '') {
            return [
                'valid' => false,
                'reason' => 'signature_headers_incomplete',
                'signature_present' => true,
            ];
        }

        $expectedKeyId = trim((string) config('security.launcher_gate.key_id', 'travel-launcher-1'));
        if ($expectedKeyId !== '' && ! hash_equals($expectedKeyId, $keyId)) {
            return [
                'valid' => false,
                'reason' => 'launcher_key_id_mismatch',
                'signature_present' => true,
            ];
        }

        if (! ctype_digit($timestamp)) {
            return [
                'valid' => false,
                'reason' => 'launcher_timestamp_invalid',
                'signature_present' => true,
            ];
        }

        $timestampValue = (int) $timestamp;
        $ttlSeconds = max(30, (int) config('security.launcher_gate.signature_ttl_seconds', 90));
        if (abs(time() - $timestampValue) > $ttlSeconds) {
            return [
                'valid' => false,
                'reason' => 'launcher_signature_expired',
                'signature_present' => true,
            ];
        }

        if (preg_match('/^[A-Fa-f0-9]{16,128}$/', $nonce) !== 1) {
            return [
                'valid' => false,
                'reason' => 'launcher_nonce_invalid',
                'signature_present' => true,
            ];
        }

        $signatureBytes = base64_decode($signature, true);
        if (! is_string($signatureBytes) || $signatureBytes === '') {
            return [
                'valid' => false,
                'reason' => 'launcher_signature_not_base64',
                'signature_present' => true,
            ];
        }

        $payload = $this->signaturePayload($keyId, $this->requestMethod(), $this->requestTarget(), $timestamp, $nonce);
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return [
                'valid' => false,
                'error' => true,
                'reason' => 'launcher_public_key_unreadable',
            ];
        }

        try {
            $verified = openssl_verify($payload, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256) === 1;
        } finally {
            if (is_resource($publicKey)) {
                openssl_free_key($publicKey);
            }
        }

        if (! $verified) {
            return [
                'valid' => false,
                'reason' => 'launcher_signature_invalid',
                'signature_present' => true,
            ];
        }

        if (! $this->reserveNonce($nonce, $timestampValue + $ttlSeconds)) {
            return [
                'valid' => false,
                'reason' => 'launcher_nonce_replayed',
                'signature_present' => true,
            ];
        }

        return [
            'valid' => true,
            'reason' => 'ok',
            'signature_present' => true,
        ];
    }

    private function configuredPublicKey(): string
    {
        $inline = trim((string) config('security.launcher_gate.public_key', ''));
        if ($inline !== '') {
            return str_replace('\n', "\n", $inline);
        }

        $configuredPath = trim((string) config('security.launcher_gate.public_key_path', ''));
        if ($configuredPath === '') {
            return '';
        }

        $absolutePath = preg_match('#^[A-Za-z]:[\\\\/]#', $configuredPath) === 1
            ? $configuredPath
            : base_path($configuredPath);

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return '';
        }

        $contents = file_get_contents($absolutePath);

        return is_string($contents) ? trim($contents) : '';
    }

    private function signaturePayload(string $keyId, string $method, string $requestTarget, string $timestamp, string $nonce): string
    {
        return implode("\n", [
            $keyId,
            strtoupper($method),
            $requestTarget,
            $timestamp,
            $nonce,
        ]);
    }

    private function requestMethod(): string
    {
        return strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    }

    private function requestTarget(): string
    {
        $requestUri = trim((string) ($_SERVER['REQUEST_URI'] ?? '/'));

        return $requestUri !== '' ? $requestUri : '/';
    }

    private function requestPath(): string
    {
        return parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    }

    private function reserveNonce(string $nonce, int $expiresAt): bool
    {
        $configuredPath = trim((string) config('security.launcher_gate.nonce_cache_path', 'storage/runtime/launcher_gate_nonces.json'));
        $cachePath = preg_match('#^[A-Za-z]:[\\\\/]#', $configuredPath) === 1
            ? $configuredPath
            : base_path($configuredPath);

        $directory = dirname($cachePath);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return false;
        }

        $handle = fopen($cachePath, 'c+');
        if ($handle === false) {
            return false;
        }

        $nonceKey = hash('sha256', $nonce);
        $now = time();

        try {
            if (! flock($handle, LOCK_EX)) {
                fclose($handle);
                return false;
            }

            $contents = stream_get_contents($handle);
            $cache = json_decode(is_string($contents) && $contents !== '' ? $contents : '{}', true);
            if (! is_array($cache)) {
                $cache = [];
            }

            foreach ($cache as $cachedNonce => $cachedExpiresAt) {
                if (! is_numeric($cachedExpiresAt) || (int) $cachedExpiresAt < $now) {
                    unset($cache[$cachedNonce]);
                }
            }

            if (isset($cache[$nonceKey]) && (int) $cache[$nonceKey] >= $now) {
                flock($handle, LOCK_UN);
                fclose($handle);
                return false;
            }

            $cache[$nonceKey] = $expiresAt;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            return true;
        } catch (\Throwable) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
    }

    private function deny(array $context): void
    {
        AuditLog::record($this->app, 'security.launcher_gate.denied', array_merge([
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'path' => $this->requestPath(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
        ], $context));

        http_response_code(403);
        echo View::make($this->app->basePath('/app/Views/errors/403.php'), ['title' => 'Access Restricted']);
        exit;
    }

    private function failClosed(int $statusCode, string $channel, array $context, string $title): void
    {
        app_write_log($channel, 'Launcher gate is enabled but cannot complete verification.', $context);

        http_response_code($statusCode);
        echo View::make($this->app->basePath('/app/Views/errors/500.php'), ['title' => $title]);
        exit;
    }

    private function headerValue(string $headerName): string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));

        return trim((string) ($_SERVER[$normalized] ?? ''));
    }
}
