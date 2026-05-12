<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

final class Crypto
{
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        return base64_encode($iv . $mac . $ciphertext);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false || strlen($decoded) < 48) {
            return null;
        }

        $key = self::key();
        $iv = substr($decoded, 0, 16);
        $mac = substr($decoded, 16, 32);
        $ciphertext = substr($decoded, 48);
        $calculatedMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        if (! hash_equals($mac, $calculatedMac)) {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? null : $plaintext;
    }

    private static function key(): string
    {
        $configured = (string) config('app.key', '');

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            if ($decoded !== false && strlen($decoded) >= 32) {
                return substr($decoded, 0, 32);
            }
        }

        if (strlen($configured) >= 32) {
            return substr($configured, 0, 32);
        }

        throw new RuntimeException('Application encryption key is not configured correctly.');
    }
}
