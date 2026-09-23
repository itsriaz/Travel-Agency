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
        $branchId = (int) ($input['branch_id'] ?? 0);
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        [$accountAllocations, $payableAllocations] = $this->requestedAllocations($input);
        $accountTotal = round(array_sum($accountAllocations), 2);
        $payableTotal = round(array_sum($payableAllocations), 2);
        if ($accountAllocations === [] || $payableAllocations === [] || $accountTotal <= 0.005 || $payableTotal <= 0.005) {
            throw new RuntimeException('Enter a positive adjustment amount beside at least one account-holder invoice and one supplier invoice.');
        }
        if (abs($accountTotal - $payableTotal) > 0.005) {
            throw new RuntimeException('Account-holder and supplier adjustment totals must be exactly equal.');
        }
        $offsetDate = $this->validDate((string) ($input['offset_date'] ?? ''));
        $reference = $this->optionalText($input['reference_no'] ?? null, 100, 'Reference');
        $remarks = $this->optionalText($input['remarks'] ?? null, 2000, 'Remarks');

        if ($linkId <= 0 || $branchId <= 0 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Please select a linked party, branch, and currency.');
        }
        $allowedBranches = array_values(array_unique(array_filter(array_map('intval', $accessibleBranchIds))));
        if ($allowedBranches !== [] && ! in_array($branchId, $allowedBranches, true)) {
            throw new RuntimeException('You do not have access to the selected branch.');
        }

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

            $accountPositions = $repository->openAccountPositions((int) $link['business_source_id'], $branchId, $currency, true);
            $payables = $repository->openPayables((int) $link['supplier_id'], $branchId, $currency, true);
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
            $amount = $accountTotal;

            $offsetNo = 'OFFSET-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $offsetId = $repository->createOffset([
                'offset_no' => $offsetNo,
                'link_id' => $linkId,
                'business_source_id' => (int) $link['business_source_id'],
                'supplier_id' => (int) $link['supplier_id'],
                'branch_id' => $branchId,
                'currency' => $currency,
                'offset_date' => $offsetDate,
                'amount' => $amount,
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
            $journalId = $accounting->postJournalEntry([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'source_type' => 'linked_party_offset',
                'source_reference' => $offsetNo,
                'entry_date' => $offsetDate,
                'currency' => $currency,
                'narration' => 'Linked-party'
                    . ' account-holder recovery and supplier payable settled without cash movement',
                'actor_user_id' => $actorUserId,
            ], [[
                'account_code' => 'AP_CONTROL',
                'line_description' => 'Supplier payable settled against linked account-holder recovery',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ], [
                'account_code' => 'AR_CONTROL',
                'line_description' => 'Account-holder recovery applied against linked supplier payable',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ]]);
            $repository->setJournalEntry($offsetId, $journalId);

            AuditLog::record($this->app, 'counterparty.offset.posted', [
                'user_id' => $actorUserId, 'offset_id' => $offsetId, 'offset_no' => $offsetNo,
                'link_id' => $linkId, 'branch_id' => $branchId, 'currency' => $currency, 'amount' => $amount,
                'journal_entry_id' => $journalId, 'automatic' => false,
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
            return $repository->find($offsetId) ?? ['id' => $offsetId, 'offset_no' => $offsetNo, 'amount' => $amount];
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
            if ($allowedBranches !== [] && ! in_array((int) $offset['branch_id'], $allowedBranches, true)) {
                throw new RuntimeException('You do not have access to this settlement branch.');
            }
            $remainingAmount = $repository->validatedRemainingReversalAmount($offset);
            // Prior releases already reversed part of the original journal. Reverse only what remains.
            $reversalId = $remainingAmount > 0.005 ? $accounting->postJournalEntry([
                'branch_id' => (int) $offset['branch_id'],
                'source_type' => 'linked_party_offset_void',
                'source_reference' => 'VOID-' . (string) $offset['offset_no'],
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $offset['currency'],
                'narration' => 'Void linked-party settlement ' . (string) $offset['offset_no'],
                'actor_user_id' => $actorUserId,
            ], [[
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
            $repository->voidOffset($offsetId, $actorUserId, $reason, $reversalId);
            AuditLog::record($this->app, 'counterparty.offset.voided', [
                'user_id' => $actorUserId, 'offset_id' => $offsetId, 'offset_no' => $offset['offset_no'],
                'reason' => $reason, 'reversal_journal_entry_id' => $reversalId,
                'reversed_remaining_amount' => $remainingAmount,
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
