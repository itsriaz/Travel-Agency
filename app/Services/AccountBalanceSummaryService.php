<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AccountBalanceSummaryService
{
    /** Build each account's current position once, before selecting one account or totaling all accounts. */
    public function positions(array $accountSummaryRows, array $links, array $linkedPositions): array
    {
        $linkByAccount = [];
        $accountByLink = [];
        foreach ($links as $link) {
            $accountId = (int) ($link['business_source_id'] ?? 0);
            $linkId = (int) ($link['id'] ?? 0);
            if ($accountId <= 0 || $linkId <= 0) {
                continue;
            }
            if (isset($linkByAccount[$accountId]) && $linkByAccount[$accountId] !== $linkId) {
                throw new RuntimeException('An account has more than one linked supplier.');
            }
            $linkByAccount[$accountId] = $linkId;
            $accountByLink[$linkId] = $accountId;
        }

        $positions = [];
        foreach ($accountSummaryRows as $row) {
            $accountId = (int) ($row['business_source_id'] ?? 0);
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency === '') {
                continue;
            }
            $positions[$accountId][$currency] ??= $this->emptyPosition($accountId, $currency, $linkByAccount[$accountId] ?? 0);
            $positions[$accountId][$currency]['invoice_outstanding'] = round(
                $positions[$accountId][$currency]['invoice_outstanding']
                    + (float) ($row['raw_outstanding_amount'] ?? 0),
                2
            );
        }

        foreach ($linkedPositions as $linked) {
            $linkId = (int) ($linked['link_id'] ?? 0);
            $accountId = $accountByLink[$linkId] ?? 0;
            $currency = strtoupper(trim((string) ($linked['currency'] ?? '')));
            if ($accountId <= 0 || $currency === '') {
                continue;
            }
            $positions[$accountId][$currency] ??= $this->emptyPosition($accountId, $currency, $linkId);
            if ($positions[$accountId][$currency]['linked_position_seen']) {
                throw new RuntimeException('A linked account has duplicate currency positions.');
            }
            $positions[$accountId][$currency]['linked_position_seen'] = true;
            $positions[$accountId][$currency]['balance'] = $linked['net_balance'] === null
                ? null
                : round((float) $linked['net_balance'], 2);
        }

        $result = [];
        foreach ($positions as $currencyPositions) {
            foreach ($currencyPositions as $position) {
                if ($position['link_id'] > 0
                    && ! $position['linked_position_seen']
                    && abs($position['invoice_outstanding']) < 0.005) {
                    continue;
                }
                if ($position['link_id'] === 0) {
                    // An ordinary sales account may contain unrelated customers; only an explicit link permits netting roles.
                    $position['balance'] = $position['invoice_outstanding'];
                } elseif (! $position['linked_position_seen']) {
                    // A missing linked statement must never silently turn into a gross invoice balance.
                    $position['balance'] = null;
                }
                unset($position['linked_position_seen']);
                $result[] = $position;
            }
        }

        return $result;
    }

    public function cards(array $positions, int $selectedAccountId, string $currencyFilter = ''): array
    {
        $totals = [];
        $currencyFilter = strtoupper(trim($currencyFilter));
        foreach ($positions as $position) {
            if ($selectedAccountId > 0 && (int) $position['business_source_id'] !== $selectedAccountId) {
                continue;
            }
            $currency = (string) $position['currency'];
            if ($currencyFilter !== '' && $currency !== $currencyFilter) {
                continue;
            }
            $totals[$currency] ??= ['balance' => 0.0, 'unavailable' => false];
            if ($position['balance'] === null) {
                $totals[$currency]['unavailable'] = true;
            } else {
                $totals[$currency]['balance'] = round(
                    $totals[$currency]['balance'] + (float) $position['balance'],
                    2
                );
            }
        }

        $currencyOrder = ['AED' => 0, 'PKR' => 1, 'USD' => 2];
        uksort($totals, static fn (string $left, string $right): int =>
            ($currencyOrder[$left] ?? 3) <=> ($currencyOrder[$right] ?? 3)
                ?: strcmp($left, $right)
        );

        $cards = [];
        foreach ($totals as $currency => $total) {
            $balance = (float) $total['balance'];
            $direction = $balance > 0.005 ? 'Receivable' : ($balance < -0.005 ? 'Payable' : 'Settled');
            $cards[] = [
                'label' => ($selectedAccountId > 0 ? 'Current Net ' : 'Total Current Net ') . $direction . ' / ' . $currency,
                'value' => $total['unavailable']
                    ? 'Unavailable'
                    : $currency . ' ' . number_format(abs($balance), 2),
                'group' => 'account_summary',
                'group_title' => 'Account Summary',
                'note' => $total['unavailable']
                    ? 'A linked balance could not be verified; gross invoices were not substituted.'
                    : '',
            ];
        }

        return $cards;
    }

    private function emptyPosition(int $accountId, string $currency, int $linkId): array
    {
        return [
            'business_source_id' => $accountId,
            'currency' => $currency,
            'link_id' => $linkId,
            'invoice_outstanding' => 0.0,
            'balance' => null,
            'linked_position_seen' => false,
        ];
    }
}
