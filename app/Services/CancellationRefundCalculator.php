<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class CancellationRefundCalculator
{
    /** All amounts must already be in the customer invoice currency. */
    public static function customerSummary(array $input): array
    {
        $amounts = [];
        foreach (['invoice', 'settled', 'expected', 'penalty', 'fee_refund', 'paid'] as $key) {
            $value = $input[$key] ?? 0;
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                throw new RuntimeException('Cancellation calculation requires valid non-negative amounts in one currency.');
            }
            $amounts[$key] = round((float) $value, 2);
        }

        $charge = round(max($amounts['invoice'] - $amounts['expected'] + $amounts['penalty'] - $amounts['fee_refund'], 0), 2);
        $entitlement = round(max($amounts['settled'] - $charge, 0), 2);
        return [
            'final_charge' => $charge,
            'entitlement' => $entitlement,
            'paid' => $amounts['paid'],
            'remaining' => round(max($entitlement - $amounts['paid'], 0), 2),
            'overpaid' => round(max($amounts['paid'] - $entitlement, 0), 2),
        ];
    }
}
