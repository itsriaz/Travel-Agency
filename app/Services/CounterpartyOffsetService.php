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

        return $this->postSettlement($input, $actorUserId, $accessibleBranchIds, false);
    }

    public function autoSettleForFinancialContext(
        int $businessSourceId,
        int $supplierId,
        int $branchId,
        array $currencies,
        string $entryDate,
        int $actorUserId
    ): array {
        if ($actorUserId <= 0 || $branchId <= 0) {
            return [];
        }

        $linkRepository = new CounterpartyLinkRepository($this->app);
        $links = [];
        if ($businessSourceId > 0) {
            $link = $linkRepository->findByBusinessSourceId($businessSourceId);
            if ($link !== null) {
                $links[(int) $link['id']] = $link;
            }
        }
        if ($supplierId > 0) {
            $link = $linkRepository->findBySupplierId($supplierId);
            if ($link !== null) {
                $links[(int) $link['id']] = $link;
            }
        }

        $currencyCodes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $currency): string => strtoupper(trim((string) $currency)),
            $currencies
        ), static fn (string $currency): bool => preg_match('/^[A-Z]{3}$/', $currency) === 1)));
        $settlements = [];
        foreach (array_keys($links) as $linkId) {
            foreach ($currencyCodes as $currency) {
                $settlement = $this->postAvailableSettlement(
                    $linkId,
                    $branchId,
                    $currency,
                    $entryDate,
                    $actorUserId,
                    true
                );
                if ($settlement !== null) {
                    $settlements[] = $settlement;
                }
            }
        }

        return $settlements;
    }

    public function autoSettleLink(int $linkId, string $entryDate, int $actorUserId): array
    {
        if ($linkId <= 0 || $actorUserId <= 0) {
            return [];
        }

        $positions = (new CounterpartyOffsetRepository($this->app))->positions();
        $settlements = [];
        foreach ($positions as $position) {
            if ((int) ($position['link_id'] ?? 0) !== $linkId
                || (float) ($position['available_offset'] ?? 0) <= 0.005
            ) {
                continue;
            }

            $settlement = $this->postAvailableSettlement(
                $linkId,
                (int) ($position['branch_id'] ?? 0),
                (string) ($position['currency'] ?? ''),
                $entryDate,
                $actorUserId,
                true
            );
            if ($settlement !== null) {
                $settlements[] = $settlement;
            }
        }

        return $settlements;
    }

    public function autoSettleExisting(array $accessibleBranchIds, string $entryDate, int $actorUserId): array
    {
        $this->assertFinancialAdminActor(
            $actorUserId,
            'Only a financial administrator can reconcile existing linked-party balances.'
        );

        $positions = (new CounterpartyOffsetRepository($this->app))->positions($accessibleBranchIds);
        $settlements = [];
        foreach ($positions as $position) {
            if ((float) ($position['available_offset'] ?? 0) <= 0.005) {
                continue;
            }

            $settlement = $this->postAvailableSettlement(
                (int) ($position['link_id'] ?? 0),
                (int) ($position['branch_id'] ?? 0),
                (string) ($position['currency'] ?? ''),
                $entryDate,
                $actorUserId,
                true
            );
            if ($settlement !== null) {
                $settlements[] = $settlement;
            }
        }

        return $settlements;
    }

    private function postAvailableSettlement(
        int $linkId,
        int $branchId,
        string $currency,
        string $entryDate,
        int $actorUserId,
        bool $automatic
    ): ?array {
        $currency = strtoupper(trim($currency));
        if ($linkId <= 0 || $branchId <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        $repository = new CounterpartyOffsetRepository($this->app);
        $link = (new CounterpartyLinkRepository($this->app))->findById($linkId);
        if ($link === null) {
            return null;
        }
        $accountPositions = $repository->openAccountPositions((int) $link['business_source_id'], $branchId, $currency);
        $payables = $repository->openPayables((int) $link['supplier_id'], $branchId, $currency);
        $maximum = round(min($this->sum($accountPositions, 'available_account_amount'), $this->sum($payables, 'net_payable_amount')), 2);
        if ($maximum <= 0.005) {
            return null;
        }

        return $this->postSettlement([
            'link_id' => $linkId,
            'branch_id' => $branchId,
            'currency' => $currency,
            'offset_date' => $entryDate,
            'amount' => $maximum,
            'reference_no' => $automatic ? 'AUTO' : null,
            'remarks' => $automatic ? 'Automatically adjusted linked account-holder and supplier balances.' : null,
        ], $actorUserId, [$branchId], $automatic);
    }

    private function postSettlement(
        array $input,
        int $actorUserId,
        array $accessibleBranchIds,
        bool $automatic
    ): array {
        $linkId = (int) ($input['link_id'] ?? 0);
        $branchId = (int) ($input['branch_id'] ?? 0);
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $requestedAmount = round((float) ($input['amount'] ?? 0), 2);
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
            $accountPositionTotal = $this->sum($accountPositions, 'available_account_amount');
            $payableTotal = $this->sum($payables, 'net_payable_amount');
            $maximum = round(min($accountPositionTotal, $payableTotal), 2);
            if ($maximum <= 0.005) {
                throw new RuntimeException('No matching account-holder recovery balance and supplier payable are available in this branch and currency.');
            }
            $amount = $requestedAmount > 0 ? $requestedAmount : $maximum;
            if ($amount <= 0 || $amount - $maximum > 0.005) {
                throw new RuntimeException('The settlement cannot exceed ' . $currency . ' ' . number_format($maximum, 2) . '.');
            }

            $offsetNo = ($automatic ? 'AUTO-OFFSET-' : 'OFFSET-') . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
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

            $this->allocateAccountPositions($repository, $offsetId, $accountPositions, $amount);
            $this->allocatePayables($repository, $offsetId, $payables, $amount);
            $journalId = $accounting->postJournalEntry([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'source_type' => 'linked_party_offset',
                'source_reference' => $offsetNo,
                'entry_date' => $offsetDate,
                'currency' => $currency,
                'narration' => ($automatic ? 'Automatic linked-party' : 'Linked-party')
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

            AuditLog::record($this->app, $automatic ? 'counterparty.offset.auto_posted' : 'counterparty.offset.posted', [
                'user_id' => $actorUserId, 'offset_id' => $offsetId, 'offset_no' => $offsetNo,
                'link_id' => $linkId, 'branch_id' => $branchId, 'currency' => $currency, 'amount' => $amount,
                'journal_entry_id' => $journalId, 'automatic' => $automatic,
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
            $reversalId = $accounting->reverseJournalEntry((int) $offset['journal_entry_id'], [
                'branch_id' => (int) $offset['branch_id'],
                'source_type' => 'linked_party_offset_void',
                'source_reference' => 'VOID-' . (string) $offset['offset_no'],
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $offset['currency'],
                'narration' => 'Void linked-party settlement ' . (string) $offset['offset_no'],
                'actor_user_id' => $actorUserId,
            ]);
            if ($reversalId === null) {
                throw new RuntimeException('The settlement journal could not be reversed.');
            }
            $repository->voidOffset($offsetId, $actorUserId, $reason, $reversalId);
            AuditLog::record($this->app, 'counterparty.offset.voided', [
                'user_id' => $actorUserId, 'offset_id' => $offsetId, 'offset_no' => $offset['offset_no'],
                'reason' => $reason, 'reversal_journal_entry_id' => $reversalId,
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

    private function allocateAccountPositions(CounterpartyOffsetRepository $repository, int $offsetId, array $items, float $amount): void
    {
        $remaining = $amount;
        foreach ($items as $item) {
            if ($remaining <= 0.005) break;
            $applied = round(min($remaining, (float) $item['available_account_amount']), 2);
            if ($applied > 0) {
                $repository->allocateAccountPosition($offsetId, (int) $item['id'], $applied);
                $remaining = round($remaining - $applied, 2);
            }
        }
        if ($remaining > 0.005) throw new RuntimeException('Account-holder recovery balance changed. Please retry.');
    }

    private function allocatePayables(CounterpartyOffsetRepository $repository, int $offsetId, array $items, float $amount): void
    {
        $remaining = $amount;
        foreach ($items as $item) {
            if ($remaining <= 0.005) break;
            $applied = round(min($remaining, (float) $item['net_payable_amount']), 2);
            if ($applied > 0) {
                $repository->allocatePayable($offsetId, (int) $item['id'], $applied);
                $remaining = round($remaining - $applied, 2);
            }
        }
        if ($remaining > 0.005) throw new RuntimeException('Supplier payable allocation changed. Please retry.');
    }

    private function sum(array $items, string $key): float
    {
        return round(array_reduce($items, static fn (float $sum, array $item): float => $sum + (float) ($item[$key] ?? 0), 0.0), 2);
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
