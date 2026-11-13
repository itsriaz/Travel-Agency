<?php

declare(strict_types=1);

namespace App\Services;

final class DirectMoneyInOutSummary
{
    /** @return array<string, array{in: float, out: float}> */
    public static function byCurrency(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency === '') {
                continue;
            }

            $type = (string) ($row['transaction_type'] ?? '');
            $group = (string) ($row['source_group'] ?? 'posted');
            if (! in_array($type, ['adjustment_increase', 'adjustment_decrease'], true)
                || ! in_array($group, ['posted', 'reversal'], true)) {
                continue;
            }

            $amount = $type === 'adjustment_increase'
                ? (float) ($group === 'posted' ? ($row['debit_amount'] ?? 0) : ($row['credit_amount'] ?? 0))
                : (float) ($group === 'posted' ? ($row['credit_amount'] ?? 0) : ($row['debit_amount'] ?? 0));
            $key = $type === 'adjustment_increase' ? 'in' : 'out';
            $totals[$currency] ??= ['in' => 0.0, 'out' => 0.0];
            $totals[$currency][$key] += ($group === 'reversal' ? -1.0 : 1.0) * $amount;
        }

        ksort($totals);
        return $totals;
    }
}
