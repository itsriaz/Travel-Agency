<?php

declare(strict_types=1);

namespace App\Services;

final class SalaryAccountMatcher
{
    public static function groupKeys(array $accounts): array
    {
        $candidates = [];
        $counts = [];

        foreach ($accounts as $account) {
            $id = (int) ($account['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $name = mb_strtoupper(trim((string) ($account['name'] ?? '')));
            preg_match_all('/(?<![\p{L}\p{N}])(?:PKR|AED|USD)(?![\p{L}\p{N}])/u', $name, $matches);
            if (count($matches[0]) !== 1) {
                continue;
            }

            $currency = $matches[0][0];
            $baseName = preg_replace('/(?<![\p{L}\p{N}])(?:PKR|AED|USD)(?![\p{L}\p{N}])/u', ' ', $name);
            $baseName = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $baseName));
            if ($baseName === '') {
                continue;
            }

            $candidates[$id] = [$baseName, $currency];
            $counts[$baseName][$currency] = ($counts[$baseName][$currency] ?? 0) + 1;
        }

        $keys = [];
        foreach ($accounts as $account) {
            $id = (int) ($account['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            [$baseName] = $candidates[$id] ?? [null];
            $unambiguous = $baseName !== null && max($counts[$baseName]) === 1;
            $keys[$id] = $unambiguous ? 'currency-name:' . $baseName : 'account:' . $id;
        }

        return $keys;
    }
}
