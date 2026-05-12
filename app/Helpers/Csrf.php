<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf_token');

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf_token', $token);
        }

        return $token;
    }

    public static function input(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verifyOrFail(?string $token): void
    {
        $sessionToken = Session::get('_csrf_token');

        if (! is_string($sessionToken) || ! is_string($token) || ! hash_equals($sessionToken, $token)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }
}
