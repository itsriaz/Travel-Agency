<?php

declare(strict_types=1);

namespace App\Helpers;

final class PasswordPolicy
{
    public static function validate(string $password, ?string $confirmation = null): array
    {
        $minLength = (int) config('security.password.min_length', 12);
        $maxLength = (int) config('security.password.max_length', 128);
        $errors = [];

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        $length = mb_strlen($password);

        if ($length < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters.";
        }

        if ($length > $maxLength) {
            $errors[] = "Password must not exceed {$maxLength} characters.";
        }

        if ($confirmation !== null && ! hash_equals($password, $confirmation)) {
            $errors[] = 'Password confirmation does not match.';
        }

        return $errors;
    }
}
