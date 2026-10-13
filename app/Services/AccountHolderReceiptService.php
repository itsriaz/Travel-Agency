<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\CounterpartyLinkRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\TreasuryRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class AccountHolderReceiptService extends Service
{
    private const RECEIPT_PURPOSE = 'account_holder_receipt';
    private const BOOKING_REFERENCE = 'ACCOUNT-HOLDER';
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_METHODS = ['cash', 'bank_transfer'];
    private const ALLOWED_PAYER_ROLES = ['account_holder', 'supplier'];

    public function record(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only a financial administrator can record an account-holder receipt.');

        $linkId = (int) ($input['link_id'] ?? 0);
        $currency = $this->currency((string) ($input['currency'] ?? ''));
        $amount = $this->positiveMoney($input['amount'] ?? null);
        $receiptDate = $this->date((string) ($input['receipt_date'] ?? ''));
        $paymentMethod = $this->paymentMethod((string) ($input['payment_method'] ?? ''));
        $payerRole = (string) ($input['payer_role'] ?? 'account_holder');
        if (! in_array($payerRole, self::ALLOWED_PAYER_ROLES, true)) {
            throw new RuntimeException('Please select who made this linked-party payment.');
        }
        $treasuryAccountId = (int) ($input['treasury_account_id'] ?? 0);
        $selectedAccount = (new TreasuryRepository($this->app))->findAccount($treasuryAccountId, $accessibleBranchIds);
        if ($selectedAccount === null) {
            throw new RuntimeException('Please select a cash or bank account in an accessible branch.');
        }
        $branchId = (int) $selectedAccount['branch_id'];
        $submittedBranchId = (int) ($input['branch_id'] ?? 0);
        if ($submittedBranchId > 0 && $submittedBranchId !== $branchId) {
            throw new RuntimeException('The selected cash or bank account does not belong to the submitted branch.');
        }
        $referenceNumber = $this->optionalText($input['reference_number'] ?? null, 100);
        $remarks = $this->optionalText($input['remarks'] ?? null, 4000);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $link = $this->activeLink($linkId, true);
            $treasuryAccount = (new TreasuryRepository($this->app))->validatePaymentTreasuryAccount(
                $treasuryAccountId,
                $branchId,
                $currency,
                $paymentMethod,
                false,
                false,
                false
            );

            $paymentRepository = new CustomerPaymentRepository($this->app);
            $receiptNo = $paymentRepository->nextReceiptNumber();
            $receiptId = $paymentRepository->createReceipt([
                'branch_id' => $branchId,
                'traveler_id' => null,
                'business_source_id' => (int) $link['business_source_id'],
                'receipt_purpose' => self::RECEIPT_PURPOSE,
                'linked_payer_role' => $payerRole,
                'booking_reference' => self::BOOKING_REFERENCE,
                'receipt_no' => $receiptNo,
                'receipt_date' => $receiptDate,
                'currency' => $currency,
                'tendered_amount' => $amount,
                'received_amount' => $amount,
                'returned_amount' => 0.00,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => (int) $treasuryAccount['id'],
                'reference_number' => $referenceNumber,
                'bank_card_detail' => null,
                'charges_amount' => 0.00,
                'status' => 'received',
                'exchange_rate_to_booking' => null,
                'remarks' => $remarks,
                'actor_user_id' => $actorUserId,
            ]);

            $journalEntryId = (new AccountingRepository($this->app))->postCustomerReceiptRecorded([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'customer_receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'received_amount' => $amount,
                'charges_amount' => 0.00,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => (int) $treasuryAccount['id'],
                'entry_date' => $receiptDate,
                'currency' => $currency,
                'actor_user_id' => $actorUserId,
                'narration' => 'Linked-party payment received from ' . (string) ($payerRole === 'supplier' ? $link['supplier_name'] : $link['business_source_name']),
            ]);

            AuditLog::record($this->app, 'counterparty.account_holder_receipt.recorded', [
                'user_id' => $actorUserId,
                'link_id' => $linkId,
                'business_source_id' => (int) $link['business_source_id'],
                'supplier_id' => (int) $link['supplier_id'],
                'payer_role' => $payerRole,
                'payer_name' => (string) ($payerRole === 'supplier' ? $link['supplier_name'] : $link['business_source_name']),
                'customer_receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'journal_entry_id' => $journalEntryId,
                'branch_id' => $branchId,
                'currency' => $currency,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => (int) $treasuryAccount['id'],
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'journal_entry_id' => $journalEntryId,
                'amount' => $amount,
                'currency' => $currency,
                'payer_role' => $payerRole,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('The account-holder receipt could not be recorded.', 0, $exception);
        }
    }

    public function void(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only a financial administrator can void an account-holder receipt.');

        $linkId = (int) ($input['link_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        $voidReason = $this->voidReason($input['void_reason'] ?? null);
        if ($receiptId <= 0) {
            throw new RuntimeException('Select a valid account-holder receipt to void.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $link = $this->activeLink($linkId, true);
            $paymentRepository = new CustomerPaymentRepository($this->app);
            $receipt = $paymentRepository->findReceiptById($receiptId);
            if ($receipt === null
                || (string) ($receipt['receipt_purpose'] ?? '') !== self::RECEIPT_PURPOSE
                || (int) ($receipt['business_source_id'] ?? 0) !== (int) $link['business_source_id']
            ) {
                throw new RuntimeException('The selected receipt is not an account-holder receipt for this linked party.');
            }
            if (! in_array((int) ($receipt['branch_id'] ?? 0), array_map('intval', $accessibleBranchIds), true)) {
                throw new RuntimeException('You cannot void a receipt outside your accessible branches.');
            }
            if (round((float) ($receipt['allocated_amount'] ?? 0), 2) > 0.005
                || abs((float) ($receipt['received_amount'] ?? 0) - (float) ($receipt['unallocated_amount'] ?? 0)) > 0.005
            ) {
                throw new RuntimeException('This account-holder receipt has been allocated and cannot be voided from this screen.');
            }

            $reversalReference = 'VOID-' . (string) ($receipt['receipt_no'] ?? $receiptId);
            $voidResult = $paymentRepository->voidReceipt(
                $receiptId,
                $voidReason,
                $actorUserId,
                $reversalReference,
                null
            );
            $reversalJournalEntryId = (new AccountingRepository($this->app))->postCustomerReceiptVoidReversal([
                'branch_id' => (int) $voidResult['branch_id'],
                'booking_reference' => null,
                'customer_receipt_id' => $receiptId,
                'source_reference' => $reversalReference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $voidResult['currency'],
                'narration' => 'Account-holder receipt void reversal for ' . (string) $voidResult['receipt_no'],
                'actor_user_id' => $actorUserId,
            ]);
            if ($reversalJournalEntryId === null || $reversalJournalEntryId <= 0) {
                throw new RuntimeException('The receipt reversal journal could not be created.');
            }
            $paymentRepository->attachReceiptReversalJournalEntry($receiptId, $reversalJournalEntryId);

            AuditLog::record($this->app, 'counterparty.account_holder_receipt.voided', [
                'user_id' => $actorUserId,
                'link_id' => $linkId,
                'business_source_id' => (int) $link['business_source_id'],
                'customer_receipt_id' => $receiptId,
                'receipt_no' => (string) $voidResult['receipt_no'],
                'void_reason' => $voidReason,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_id' => $receiptId,
                'receipt_no' => (string) $voidResult['receipt_no'],
                'reversal_journal_entry_id' => $reversalJournalEntryId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('The account-holder receipt could not be voided.', 0, $exception);
        }
    }

    private function activeLink(int $linkId, bool $forUpdate): array
    {
        $link = $linkId > 0 ? (new CounterpartyLinkRepository($this->app))->findById($linkId, $forUpdate) : null;
        if ($link === null
            || (int) ($link['business_source_is_active'] ?? 0) !== 1
            || (int) ($link['supplier_is_active'] ?? 0) !== 1
        ) {
            throw new RuntimeException('Please select an active linked account holder and supplier.');
        }

        return $link;
    }

    private function currency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid receipt currency.');
        }

        return $currency;
    }

    private function paymentMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new RuntimeException('Account-holder receipts must use cash or bank transfer.');
        }

        return $method;
    }

    private function positiveMoney(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new RuntimeException('Receipt amount must be a valid number.');
        }
        $amount = round((float) $value, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Receipt amount must be greater than zero.');
        }

        return $amount;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new RuntimeException('Receipt date is required.');
        }

        return $date->format('Y-m-d');
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('One of the receipt values exceeds the allowed length.');
        }

        return $text;
    }

    private function voidReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Void reason must be at least 5 characters.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('Void reason cannot exceed 1000 characters.');
        }

        return $reason;
    }
}
