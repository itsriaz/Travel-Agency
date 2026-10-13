<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CounterpartyLinkRepository;
use App\Repositories\CounterpartyOffsetRepository;

final class LinkedPartyPositionService extends Service
{
    public function positions(array $accessibleBranchIds, bool $includeInactive = false): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $repository = new CounterpartyOffsetRepository($this->app);
        $invoicePositions = [];
        foreach ($repository->positions($accessibleBranchIds) as $row) {
            $invoicePositions[(int) $row['link_id']][(string) $row['currency']] = $row;
        }

        $onAccountCredits = [];
        $accountPaidDirect = [];
        foreach ($repository->unallocatedAccountHolderReceipts($accessibleBranchIds) as $row) {
            $onAccountCredits[(int) $row['link_id']][(string) $row['currency']] = (float) $row['on_account_credit'];
            $accountPaidDirect[(int) $row['link_id']][(string) $row['currency']] = (float) ($row['account_paid_direct'] ?? 0);
        }

        $positions = [];
        foreach ((new CounterpartyLinkRepository($this->app))->listLinks() as $link) {
            if (! $includeInactive && ((int) ($link['business_source_is_active'] ?? 0) !== 1
                || (int) ($link['supplier_is_active'] ?? 0) !== 1)) {
                continue;
            }

            $linkId = (int) $link['id'];
            $accountRows = (new AccountLedgerService($this->app))->report(
                ['businessSourceId' => (int) $link['business_source_id']],
                $accessibleBranchIds
            )['rows'];
            $supplierRows = (new SupplierLedgerService($this->app))->report(
                ['supplierId' => (int) $link['supplier_id']],
                $accessibleBranchIds
            )['rows'];
            $statement = (new LinkedPartyLedgerService($this->app))->buildReport(
                $accountRows,
                $supplierRows,
                null,
                null
            );
            $balances = [];
            foreach ($statement['footerRows'] as $footer) {
                $balances[(string) $footer['currency']] = (float) $footer['raw_balance_amount'];
            }

            $currencies = array_unique(array_merge(
                array_keys($invoicePositions[$linkId] ?? []),
                array_keys($onAccountCredits[$linkId] ?? []),
                array_keys($balances)
            ));
            sort($currencies);
            foreach ($currencies as $currency) {
                $invoice = $invoicePositions[$linkId][$currency] ?? [];
                $balance = $balances[$currency] ?? null;
                $positions[] = [
                    'link_id' => $linkId,
                    'business_source_id' => (int) $link['business_source_id'],
                    'supplier_id' => (int) $link['supplier_id'],
                    'business_source_name' => (string) $link['business_source_name'],
                    'supplier_name' => (string) $link['supplier_name'],
                    'currency' => $currency,
                    'receivable' => round((float) ($invoice['receivable'] ?? 0), 2),
                    'payable' => round((float) ($invoice['payable'] ?? 0), 2),
                    'on_account_credit' => round((float) ($onAccountCredits[$linkId][$currency] ?? 0), 2),
                    'account_paid_direct' => round((float) ($accountPaidDirect[$linkId][$currency] ?? 0), 2),
                    'net_balance' => $balance !== null ? round($balance, 2) : null,
                ];
            }
        }

        return $positions;
    }
}
