<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LinkedSettlementHistoryRepository;

final class LinkedSettlementHistoryService extends Service
{
    public function report(array $branchIds, array $filters, string $side): array
    {
        $rows = [];
        foreach ((new LinkedSettlementHistoryRepository($this->app))->reversed($branchIds, $filters, $side) as $offset) {
            $original = $offset['original_amount'] === null ? null : (float) $offset['original_amount'];
            $remaining = (float) $offset['remaining_at_void'];
            $reversal = $offset['reversal_amount'] === null ? null : (float) $offset['reversal_amount'];
            $detail = 'Remaining at void: ' . number_format($remaining, 0) . ' ' . $offset['currency'] . '.';
            if ($original !== null && $original - $remaining > 0.005) {
                $detail .= ' Released before void: ' . number_format($original - $remaining, 0) . ' ' . $offset['currency'] . ' (combined earlier releases).';
            }
            if ($offset['reversal_journal_entry_id'] === null && abs($remaining) < 0.005) {
                $reversal = 0.0;
                $detail .= ' Already fully released; no additional void journal.';
            } elseif ($reversal === null) {
                $detail .= ' Reversal journal unavailable; verify accounting history.';
            } elseif (abs($reversal - $remaining) > 0.005) {
                $detail .= ' Void journal differs from remaining allocation; review associated correction journals.';
            }
            $common = [
                'offset_id' => (int) $offset['id'],
                'reference' => (string) $offset['offset_no'],
                'branch' => (string) $offset['branch_name'],
                'account' => (string) $offset['account_name'],
                'supplier' => (string) $offset['supplier_name'],
                'currency' => (string) $offset['currency'],
            ];
            $rows[] = $common + [
                'date' => (string) $offset['offset_date'], 'event' => 'Original adjustment (now void)',
                'amount' => $original === null ? 'Unavailable' : number_format($original, 0),
                'journal' => $offset['journal_entry_id'] ? '#' . $offset['journal_entry_id'] : 'Unavailable',
                'detail' => $detail,
            ];
            $rows[] = $common + [
                'date' => (string) ($offset['void_date'] ?? ''), 'event' => 'Void / reversal',
                'amount' => $reversal === null ? 'Unavailable' : number_format($reversal, 0),
                'journal' => $offset['reversal_journal_entry_id'] ? '#' . $offset['reversal_journal_entry_id'] : 'None',
                'detail' => (string) ($offset['void_reason'] ?? '') . ' ' . $detail,
            ];
        }
        return [
            'rows' => $rows,
            'columns' => array_map(static fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
                ['date', 'reference', 'branch', 'account', 'supplier', 'currency', 'event', 'amount', 'journal', 'detail'],
                ['Date', 'Adjustment', 'Branch', 'Account', 'Supplier', 'Curr.', 'Event', 'Amount', 'Journal', 'Detail']),
        ];
    }
}
