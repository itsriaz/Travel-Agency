<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\CounterpartyLinkRepository;
use App\Repositories\CounterpartyOffsetRepository;
use PDO;
use RuntimeException;

final class CounterpartyOffsetService extends Service
{
    public function settle(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only a financial administrator can settle linked-party balances.');

        return $this->postSettlement($input, $actorUserId, $accessibleBranchIds);
    }

    public function autoSettleForFinancialContext(
        int $businessSourceId,
        int $supplierId,
        int $branchId,
        array $currencies,
        string $entryDate,
        int $actorUserId
    ): array {
        // Compatibility entry points: old callers must never settle balances automatically.
        return [];
    }

    public function autoSettleLink(int $linkId, string $entryDate, int $actorUserId): array
    {
        return [];
    }

    public function autoSettleExisting(array $accessibleBranchIds, string $entryDate, int $actorUserId): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only a financial administrator can reconcile linked-party balances.');
        return [];
    }

    private function postSettlement(
        array $input,
        int $actorUserId,
        array $accessibleBranchIds
    ): array {
        $linkId = (int) ($input['link_id'] ?? 0);
        [$accountAllocations, $payableAllocations] = $this->requestedAllocations($input);
        $accountTotal = round(array_sum($accountAllocations), 2);
        $payableTotal = round(array_sum($payableAllocations), 2);
        if ($accountAllocations === [] || $payableAllocations === [] || $accountTotal <= 0.005 || $payableTotal <= 0.005) {
            throw new RuntimeException('Enter a positive adjustment amount beside at least one account-holder invoice and one supplier invoice.');
        }
        $offsetDate = $this->validDate((string) ($input['offset_date'] ?? ''));
        $rateDate = $this->validDate((string) ($input['exchange_rate_effective_date'] ?? $offsetDate));
        $reference = $this->optionalText($input['reference_no'] ?? null, 100, 'Reference');
        $remarks = $this->optionalText($input['remarks'] ?? null, 2000, 'Remarks');

        if ($linkId <= 0) throw new RuntimeException('Please select a linked account holder and supplier.');
        $allowedBranches = array_values(array_unique(array_filter(array_map('intval', $accessibleBranchIds))));

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CounterpartyOffsetRepository($this->app);
        $linkRepository = new CounterpartyLinkRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $link = $linkRepository->findById($linkId, true);
            if ($link === null) {
                throw new RuntimeException('The account holder and supplier are no longer linked.');
            }

            $accountPositions = $repository->openAccountPositionsForLink((int) $link['business_source_id'], $allowedBranches, true);
            $payables = $repository->openPayablesForLink((int) $link['supplier_id'], $allowedBranches, true);
            $this->validateRequestedAllocations(
                $accountAllocations,
                $accountPositions,
                'available_account_amount',
                'account-holder'
            );
            $this->validateRequestedAllocations(
                $payableAllocations,
                $payables,
                'net_payable_amount',
                'supplier'
            );
            [$accountBranchId, $accountCurrency] = $this->selectedDimension($accountAllocations, $accountPositions, 'account-holder');
            [$supplierBranchId, $supplierCurrency] = $this->selectedDimension($payableAllocations, $payables, 'supplier');
            $legacyBranchId = (int) ($input['branch_id'] ?? 0);
            $legacyCurrency = strtoupper(trim((string) ($input['currency'] ?? '')));
            if (($legacyBranchId > 0 && $legacyBranchId !== $accountBranchId)
                || ($legacyCurrency !== '' && $legacyCurrency !== $accountCurrency)) {
                throw new RuntimeException('The selected account-holder invoices do not match the submitted branch and currency.');
            }
            foreach ([$accountBranchId, $supplierBranchId] as $selectedBranchId) {
                if ($allowedBranches !== [] && ! in_array($selectedBranchId, $allowedBranches, true)) {
                    throw new RuntimeException('You do not have access to one of the selected invoice branches.');
                }
            }

            $sameCurrency = $accountCurrency === $supplierCurrency;
            $exchangeRate = $sameCurrency ? 1.0 : $this->positiveRate($input['exchange_rate'] ?? null);
            $expectedSupplierTotal = round($accountTotal * $exchangeRate, 2);
            if (abs($expectedSupplierTotal - $payableTotal) > 0.005) {
                throw new RuntimeException(
                    $sameCurrency
                        ? 'Account-holder and supplier adjustment totals must be exactly equal.'
                        : 'Supplier total must equal account-holder total multiplied by the entered exchange rate.'
                );
            }

            $offsetNo = 'OFFSET-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $offsetId = $repository->createOffset([
                'offset_no' => $offsetNo,
                'link_id' => $linkId,
                'business_source_id' => (int) $link['business_source_id'],
                'supplier_id' => (int) $link['supplier_id'],
                'branch_id' => $accountBranchId,
                'currency' => $accountCurrency,
                'account_branch_id' => $accountBranchId,
                'supplier_branch_id' => $supplierBranchId,
                'account_currency' => $accountCurrency,
                'supplier_currency' => $supplierCurrency,
                'offset_date' => $offsetDate,
                'amount' => $accountTotal,
                'account_amount' => $accountTotal,
                'supplier_amount' => $payableTotal,
                'exchange_rate' => $exchangeRate,
                'exchange_rate_effective_date' => $rateDate,
                'reference_no' => $reference,
                'remarks' => $remarks,
                'created_by_user_id' => $actorUserId,
            ]);

            foreach ($accountAllocations as $receivableId => $allocationAmount) {
                $repository->allocateAccountPosition($offsetId, $receivableId, $allocationAmount);
            }
            foreach ($payableAllocations as $obligationId => $allocationAmount) {
                $repository->allocatePayable($offsetId, $obligationId, $allocationAmount);
            }
            $sameDimension = $accountBranchId === $supplierBranchId && $sameCurrency;
            $journalId = $accounting->postJournalEntry([
                'branch_id' => $accountBranchId,
                'booking_reference' => null,
                'source_type' => 'linked_party_offset',
                'source_reference' => $offsetNo,
                'entry_date' => $offsetDate,
                'currency' => $accountCurrency,
                'narration' => 'Linked-party'
                    . ' account-holder recovery and supplier payable settled without cash movement',
                'actor_user_id' => $actorUserId,
            ], $sameDimension ? [[
                'account_code' => 'AP_CONTROL', 'line_description' => 'Supplier payable settled against linked account-holder recovery',
                'debit_amount' => $accountTotal, 'credit_amount' => 0,
            ], [
                'account_code' => 'AR_CONTROL',
                'line_description' => 'Account-holder recovery applied against linked supplier payable',
                'debit_amount' => 0, 'credit_amount' => $accountTotal,
            ]] : [[
                'account_code' => 'LINKED_PARTY_CLEARING', 'line_description' => 'Linked supplier value received for account-holder recovery',
                'debit_amount' => $accountTotal, 'credit_amount' => 0,
            ], [
                'account_code' => 'AR_CONTROL', 'line_description' => 'Account-holder recovery applied in linked settlement',
                'debit_amount' => 0, 'credit_amount' => $accountTotal,
            ]]);

            $supplierJournalId = null;
            if (! $sameDimension) {
                $supplierJournalId = $accounting->postJournalEntry([
                    'branch_id' => $supplierBranchId,
                    'booking_reference' => null,
                    'source_type' => 'linked_party_offset_supplier',
                    'source_reference' => $offsetNo,
                    'entry_date' => $offsetDate,
                    'currency' => $supplierCurrency,
                    'narration' => 'Linked supplier payable settled without cash movement',
                    'actor_user_id' => $actorUserId,
                ], [[
                    'account_code' => 'AP_CONTROL', 'line_description' => 'Linked supplier payable settled',
                    'debit_amount' => $payableTotal, 'credit_amount' => 0,
                ], [
                    'account_code' => 'LINKED_PARTY_CLEARING', 'line_description' => 'Account-holder value applied to linked supplier payable',
                    'debit_amount' => 0, 'credit_amount' => $payableTotal,
                ]]);
            }
            $repository->setSettlementJournals($offsetId, $journalId, $supplierJournalId);

            AuditLog::record($this->app, 'counterparty.offset.posted', [
                'user_id' => $actorUserId, 'offset_id' => $offsetId, 'offset_no' => $offsetNo,
                'link_id' => $linkId, 'branch_id' => $accountBranchId, 'currency' => $accountCurrency, 'amount' => $accountTotal,
                'account_branch_id' => $accountBranchId, 'supplier_branch_id' => $supplierBranchId,
                'account_currency' => $accountCurrency, 'supplier_currency' => $supplierCurrency,
                'account_amount' => $accountTotal, 'supplier_amount' => $payableTotal,
                'exchange_rate' => $exchangeRate, 'journal_entry_id' => $journalId,
                'supplier_journal_entry_id' => $supplierJournalId, 'automatic' => false,
                'customer_receivable_item_id' => count($accountAllocations) === 1 ? array_key_first($accountAllocations) : null,
                'supplier_obligation_id' => count($payableAllocations) === 1 ? array_key_first($payableAllocations) : null,
                'customer_receivable_item_ids' => array_keys($accountAllocations),
                'supplier_obligation_ids' => array_keys($payableAllocations),
                'account_allocations' => $accountAllocations,
                'payable_allocations' => $payableAllocations,
            ]);
            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
            return $repository->find($offsetId) ?? ['id' => $offsetId, 'offset_no' => $offsetNo, 'amount' => $accountTotal];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('The linked-party settlement could not be posted.', 0, $exception);
        }
    }

    public function void(int $offsetId, string $reason, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only a financial administrator can void linked-party settlements.');
        $reason = trim($reason);
        if ($offsetId <= 0 || $reason === '') {
            throw new RuntimeException('Please provide a settlement and a reason for voiding it.');
        }
        if (mb_strlen($reason) > 2000) {
            throw new RuntimeException('The void reason cannot exceed 2,000 characters.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CounterpartyOffsetRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }
        try {
            $offset = $repository->find($offsetId, true);
            if ($offset === null || (string) $offset['status'] !== 'posted') {
                throw new RuntimeException('This linked-party settlement is unavailable or already void.');
            }
            $allowedBranches = array_values(array_unique(array_filter(array_map('intval', $accessibleBranchIds))));
            $accountBranchId = (int) ($offset['account_branch_id'] ?? $offset['branch_id']);
            $supplierBranchId = (int) ($offset['supplier_branch_id'] ?? $offset['branch_id']);
            if ($allowedBranches !== [] && (! in_array($accountBranchId, $allowedBranches, true)
                || ! in_array($supplierBranchId, $allowedBranches, true))) {
                throw new RuntimeException('You do not have access to both settlement branches.');
            }
            $accountAmount = round((float) ($offset['account_amount'] ?? $offset['amount']), 2);
            $supplierAmount = round((float) ($offset['supplier_amount'] ?? $offset['amount']), 2);
            $accountAllocations = $repository->allocations($offsetId, 'account');
            if ($accountAllocations === []) $accountAllocations = $repository->allocations($offsetId, 'receivable');
            $payableAllocations = $repository->allocations($offsetId, 'payable');
            if (abs(array_sum(array_column($accountAllocations, 'allocated_amount')) - $accountAmount) > 0.005
                || abs(array_sum(array_column($payableAllocations, 'allocated_amount')) - $supplierAmount) > 0.005) {
                throw new RuntimeException('The remaining settlement allocations do not reconcile. Review them before reversal.');
            }
            $crossDimension = $accountBranchId !== $supplierBranchId
                || (string) ($offset['account_currency'] ?? $offset['currency']) !== (string) ($offset['supplier_currency'] ?? $offset['currency']);
            $remainingAmount = $crossDimension ? $accountAmount : $repository->validatedRemainingReversalAmount($offset);
            // Prior releases already reversed part of the original journal. Reverse only what remains.
            $reversalId = $remainingAmount > 0.005 ? $accounting->postJournalEntry([
                'branch_id' => $accountBranchId,
                'source_type' => 'linked_party_offset_void',
                'source_reference' => 'VOID-' . (string) $offset['offset_no'],
                'entry_date' => date('Y-m-d'),
                'currency' => (string) ($offset['account_currency'] ?? $offset['currency']),
                'narration' => 'Void linked-party settlement ' . (string) $offset['offset_no'],
                'actor_user_id' => $actorUserId,
            ], $crossDimension ? [[
                'account_code' => 'AR_CONTROL', 'line_description' => 'Remaining linked account-holder recovery restored',
                'debit_amount' => $accountAmount, 'credit_amount' => 0,
            ], [
                'account_code' => 'LINKED_PARTY_CLEARING', 'line_description' => 'Linked settlement clearing reversed',
                'debit_amount' => 0, 'credit_amount' => $accountAmount,
            ]] : [[
                'account_code' => 'AR_CONTROL',
                'line_description' => 'Remaining linked account-holder recovery restored',
                'debit_amount' => $remainingAmount,
                'credit_amount' => 0,
            ], [
                'account_code' => 'AP_CONTROL',
                'line_description' => 'Remaining linked supplier payable restored',
                'debit_amount' => 0,
                'credit_amount' => $remainingAmount,
            ]]) : null;
            $supplierReversalId = null;
            if ($crossDimension && $supplierAmount > 0.005) {
                $supplierReversalId = $accounting->postJournalEntry([
                    'branch_id' => $supplierBranchId,
                    'source_type' => 'linked_party_offset_supplier_void',
                    'source_reference' => 'VOID-' . (string) $offset['offset_no'],
                    'entry_date' => date('Y-m-d'),
                    'currency' => (string) ($offset['supplier_currency'] ?? $offset['currency']),
                    'narration' => 'Void linked supplier settlement ' . (string) $offset['offset_no'],
                    'actor_user_id' => $actorUserId,
                ], [[
                    'account_code' => 'LINKED_PARTY_CLEARING', 'line_description' => 'Linked settlement clearing reversed',
                    'debit_amount' => $supplierAmount, 'credit_amount' => 0,
                ], [
                    'account_code' => 'AP_CONTROL', 'line_description' => 'Remaining linked supplier payable restored',
                    'debit_amount' => 0, 'credit_amount' => $supplierAmount,
                ]]);
            }
            $repository->voidOffset($offsetId, $actorUserId, $reason, $reversalId, $supplierReversalId);
            AuditLog::record($this->app, 'counterparty.offset.voided', [
                'user_id' => $actorUserId, 'offset_id' => $offsetId, 'offset_no' => $offset['offset_no'],
                'reason' => $reason, 'reversal_journal_entry_id' => $reversalId,
                'supplier_reversal_journal_entry_id' => $supplierReversalId,
                'reversed_remaining_amount' => $remainingAmount, 'reversed_supplier_amount' => $supplierAmount,
            ]);
            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
            return $repository->find($offsetId) ?? $offset;
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('The linked-party settlement could not be voided.', 0, $exception);
        }
    }

    /**
     * Accept the new itemized form while preserving the legacy one-invoice API used by repair scripts.
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function requestedAllocations(array $input): array
    {
        if (array_key_exists('account_allocations', $input) || array_key_exists('payable_allocations', $input)) {
            return [
                $this->allocationMap($input['account_allocations'] ?? null, 'Account-holder allocations'),
                $this->allocationMap($input['payable_allocations'] ?? null, 'Supplier allocations'),
            ];
        }

        $amount = $this->positiveAmount($input['amount'] ?? null, 'Adjustment amount');
        $receivableId = (int) ($input['customer_receivable_item_id'] ?? 0);
        $obligationId = (int) ($input['supplier_obligation_id'] ?? 0);
        if ($receivableId <= 0 || $obligationId <= 0 || $amount <= 0.005) {
            throw new RuntimeException('Select an account-holder invoice, a supplier invoice, and a positive adjustment amount.');
        }

        return [[$receivableId => $amount], [$obligationId => $amount]];
    }

    /** @return array<int, float> */
    private function allocationMap(mixed $rawAllocations, string $label): array
    {
        if (! is_array($rawAllocations)) {
            throw new RuntimeException($label . ' must be submitted as invoice amounts.');
        }
        $allocations = [];
        foreach ($rawAllocations as $rawId => $rawAmount) {
            if ($rawAmount === '' || $rawAmount === null) {
                continue;
            }
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new RuntimeException($label . ' contains an invalid invoice.');
            }
            $amount = $this->positiveAmount($rawAmount, $label . ' amount');
            if ($amount <= 0.005) {
                continue;
            }
            $allocations[(int) $id] = $amount;
            if (count($allocations) > 500) {
                throw new RuntimeException($label . ' cannot contain more than 500 selected invoices.');
            }
        }

        return $allocations;
    }

    private function positiveAmount(mixed $rawAmount, string $label): float
    {
        if (is_array($rawAmount) || is_object($rawAmount) || ! is_numeric($rawAmount)) {
            throw new RuntimeException($label . ' must be a valid number.');
        }
        $amount = round((float) $rawAmount, 2);
        if (! is_finite($amount) || $amount < 0) {
            throw new RuntimeException($label . ' must be a positive number.');
        }
        return $amount;
    }

    private function positiveRate(mixed $rawRate): float
    {
        if (is_array($rawRate) || is_object($rawRate) || ! is_numeric($rawRate)) {
            throw new RuntimeException('Enter the supplier amount per 1 account currency.');
        }
        $rate = round((float) $rawRate, 8);
        if (! is_finite($rate) || $rate <= 0) throw new RuntimeException('Exchange rate must be greater than zero.');
        return $rate;
    }

    /** @return array{0:int,1:string} */
    private function selectedDimension(array $requested, array $rows, string $side): array
    {
        $rowsById = [];
        foreach ($rows as $row) $rowsById[(int) $row['id']] = $row;
        $dimensions = [];
        foreach (array_keys($requested) as $id) {
            $row = $rowsById[$id] ?? null;
            if ($row === null) throw new RuntimeException('A selected ' . $side . ' invoice is unavailable.');
            $key = (int) $row['branch_id'] . '|' . strtoupper((string) $row['currency']);
            $dimensions[$key] = [(int) $row['branch_id'], strtoupper((string) $row['currency'])];
        }
        if (count($dimensions) !== 1) {
            throw new RuntimeException('Select ' . $side . ' invoices from one branch and currency per settlement.');
        }
        return array_values($dimensions)[0];
    }

    /** @param array<int, float> $requested */
    private function validateRequestedAllocations(
        array $requested,
        array $availableRows,
        string $balanceKey,
        string $side
    ): void
    {
        $availableById = [];
        foreach ($availableRows as $row) {
            $availableById[(int) $row['id']] = round((float) ($row[$balanceKey] ?? 0), 2);
        }
        foreach ($requested as $itemId => $amount) {
            if (! isset($availableById[$itemId]) || $availableById[$itemId] <= 0.005) {
                throw new RuntimeException('A selected ' . $side . ' invoice is unavailable for this linked party, branch, and currency.');
            }
            if ($amount - $availableById[$itemId] > 0.005) {
                throw new RuntimeException(
                    'The ' . $side . ' adjustment for invoice #' . $itemId . ' cannot exceed '
                    . number_format($availableById[$itemId], 2) . '.'
                );
            }
        }
    }

    private function validDate(string $date): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : date('Y-m-d');
    }

    private function optionalText(mixed $value, int $max, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (mb_strlen($value) > $max) throw new RuntimeException($label . ' cannot exceed ' . number_format($max) . ' characters.');
        return $value;
    }
}
