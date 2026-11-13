<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupplierAccountBalanceRepository;
use RuntimeException;

/**
 * Supplier account position from the existing supplier-ledger presentation.
 * A positive net amount is an advance; a negative amount is payable.
 * This service does not alter the supplier ledger's calculation or its rows.
 */
final class SupplierAccountBalanceService extends Service
{
    /** @return array<int, array<string, array{net: float, bills: float, payments: float}>> */
    public function summaries(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $currency = ''
    ): array {
        if ($branchIds === []) {
            throw new RuntimeException('Supplier account balance requires a branch scope.');
        }

        $nameIds = [];
        foreach ((new SupplierAccountBalanceRepository($this->app))->supplierNames() as $supplier) {
            $name = trim((string) $supplier['name']);
            $nameIds[$name][] = (int) $supplier['id'];
        }

        $ledgerService = new SupplierLedgerService($this->app);
        $ledger = $ledgerService->report([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'currency' => strtoupper(trim($currency)),
        ], $branchIds);
        $positions = [];
        $duplicateNames = [];
        foreach ($ledger['rows'] as $row) {
            $name = trim((string) ($row['supplier_name'] ?? ''));
            $ids = $nameIds[$name] ?? [];
            if ($ids === []) {
                throw new RuntimeException('Supplier account balance cannot identify ledger supplier: ' . $name);
            }
            if (count($ids) > 1) {
                $duplicateNames[$name] = $ids;
                continue;
            }
            $this->accumulate($positions, $ids[0], $row);
        }
        // Names can be shared by branch-specific supplier records. The ledger
        // already filters by supplier ID; use that filter instead of attributing
        // a shared-name row to an arbitrary record.
        foreach ($duplicateNames as $ids) {
            foreach ($ids as $id) {
                $supplierRows = $ledgerService->report([
                    'supplierId' => $id,
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'currency' => strtoupper(trim($currency)),
                ], $branchIds);
                foreach ($supplierRows['rows'] as $row) {
                    $this->accumulate($positions, $id, $row);
                }
            }
        }

        return $positions;
    }

    private function accumulate(array &$positions, int $supplierId, array $row): void
    {
        $rowCurrency = strtoupper((string) ($row['currency'] ?? ''));
        if ($rowCurrency === '') {
            throw new RuntimeException('Supplier ledger row has no currency.');
        }
        $positions[$supplierId][$rowCurrency] ??= ['net' => 0.0, 'bills' => 0.0, 'payments' => 0.0];
        $debit = (float) ($row['raw_debit_amount'] ?? 0);
        $credit = (float) ($row['raw_credit_amount'] ?? 0);
        $positions[$supplierId][$rowCurrency]['net'] = round(
            $positions[$supplierId][$rowCurrency]['net'] + $credit - $debit, 2
        );
        $sourceType = (string) ($row['source_entry_type'] ?? '');
        if (in_array($sourceType, [
            'Payable Created', 'Payable Adjustment', 'Payable Transfer',
            'Payable Reversed on Cancellation', 'Supplier Penalty Retained',
            'Prior-Period Supplier Charge',
        ], true)) {
            $positions[$supplierId][$rowCurrency]['bills'] = round(
                $positions[$supplierId][$rowCurrency]['bills'] + $debit - $credit, 2
            );
        }
        if (in_array($sourceType, [
            'Supplier Payment', 'Supplier Advance / Overpayment', 'Customer Paid Supplier',
        ], true)) {
            $positions[$supplierId][$rowCurrency]['payments'] = round(
                $positions[$supplierId][$rowCurrency]['payments'] + $credit, 2
            );
        }
    }

    /** @return array<int, array<string, float>> */
    public function positions(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $currency = ''
    ): array {
        $positions = [];
        foreach ($this->summaries($branchIds, $dateFrom, $dateTo, $currency) as $supplierId => $byCurrency) {
            foreach ($byCurrency as $code => $summary) {
                $positions[$supplierId][$code] = $summary['net'];
            }
        }
        return $positions;
    }

    public function allBranchIds(): array
    {
        return (new SupplierAccountBalanceRepository($this->app))->allBranchIds();
    }

    /** @return array<string, float> */
    public function pooledPositions(int $supplierId, array $branchIds): array
    {
        $repo = new SupplierAccountBalanceRepository($this->app);
        $all = $this->positions($branchIds);
        $pooled = [];
        foreach ($repo->sameNameSupplierIds($supplierId) as $id) {
            foreach ($all[$id] ?? [] as $currency => $amount) {
                $pooled[$currency] = round(($pooled[$currency] ?? 0.0) + $amount, 2);
            }
        }
        return $pooled;
    }
}
