<?php

declare(strict_types=1);

namespace App\Helpers;

final class Flash
{
    public static function put(string $type, string $message): void
    {
        Session::put('_flash', [
            'type' => $type,
            'message' => $message,
        ]);
    }

    public static function error(string $message): void
    {
        self::put('danger', $message);
    }

    public static function success(string $message): void
    {
        self::put('success', $message);
    }

    public static function consume(): ?array
    {
        $flash = Session::get('_flash');
        Session::forget('_flash');
        return is_array($flash) ? $flash : null;
    }
}
