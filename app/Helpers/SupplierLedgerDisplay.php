<?php

declare(strict_types=1);

namespace App\Helpers;

final class SupplierLedgerDisplay
{
    public static function formatAmount(string $value): string
    {
        if (preg_match('/^(\s*(?:[A-Z]{3}\s+)?)(-?\d[\d,]*(?:\.\d+)?)(.*)$/', $value, $matches) !== 1) {
            return $value;
        }

        return $matches[1]
            . number_format((float) str_replace(',', '', $matches[2]), 0)
            . $matches[3];
    }

    public static function formatRows(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['debit_amount', 'credit_amount', 'balance_amount'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = self::formatAmount((string) $row[$key]);
                }
            }
            if (isset($row['trace'])) {
                $row['trace'] = preg_replace_callback(
                    '/(Original amount (?:PKR|AED|USD) )(\d[\d,]*(?:\.\d+)?)/',
                    static fn (array $matches): string => $matches[1] . self::formatAmount($matches[2]),
                    (string) $row['trace']
                );
            }
        }
        unset($row);

        return $rows;
    }

    public static function formatCards(array $cards): array
    {
        foreach ($cards as &$card) {
            if (isset($card['value'])) {
                $card['value'] = self::formatAmount((string) $card['value']);
            }
        }
        unset($card);

        return $cards;
    }
}
