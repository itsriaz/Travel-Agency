<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\CustomerReceiptCorrectionRepository;
use App\Repositories\TreasuryRepository;
use PDO;
use RuntimeException;

final class CustomerReceiptCurrencyCorrectionService extends Service
{
    public function correctReleasedReceipt(array $input, int $actorUserId): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can correct a receipt currency entry.');
        $receiptId = (int) ($input['receipt_id'] ?? 0);
        $newCurrency = strtoupper(trim((string) ($input['new_currency'] ?? '')));
        $treatment = (string) ($input['treatment'] ?? 'reverse_only');
        $reason = trim((string) ($input['reason'] ?? ''));
        $targetReceivableId = (int) ($input['target_receivable_id'] ?? 0);
        $newBranchId = (int) ($input['new_branch_id'] ?? 0);
        $newTreasuryAccountId = (int) ($input['new_treasury_account_id'] ?? 0);

        if ($receiptId <= 0 || ! in_array($newCurrency, ['PKR', 'AED', 'USD'], true)) {
            throw new RuntimeException('A valid receipt and corrected currency are required.');
        }
        if (! in_array($treatment, ['reverse_only', 'migrate_and_allocate'], true)) {
            throw new RuntimeException('Unsupported wrong-currency receipt treatment.');
        }
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('A clear wrong-currency correction reason is required.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $paymentRepository = new CustomerPaymentRepository($this->app);
            $correctionRepository = new CustomerReceiptCorrectionRepository($this->app);
            $accountingRepository = new AccountingRepository($this->app);
            $existingCorrection = $correctionRepository->findCurrencyCorrection($receiptId);
            if ($existingCorrection !== null) {
                if ($startedTransaction) {
                    $db->commit();
                }
                return ['action' => 'already_corrected'] + $existingCorrection;
            }

            $receipt = $paymentRepository->findReceiptById($receiptId);
            if ($receipt === null) {
                throw new RuntimeException('The wrong-currency receipt could not be found.');
            }
            $oldCurrency = strtoupper((string) ($receipt['currency'] ?? ''));
            $amount = round((float) ($receipt['received_amount'] ?? 0), 2);
            if ($oldCurrency === $newCurrency) {
                throw new RuntimeException('The corrected receipt currency must differ from the original currency.');
            }
            if (strtolower((string) ($receipt['status'] ?? '')) === 'void') {
                throw new RuntimeException('The wrong-currency receipt is already void without a correction audit record.');
            }
            if (abs((float) ($receipt['allocated_amount'] ?? 0)) > 0.005
                || abs((float) ($receipt['unallocated_amount'] ?? 0) - $amount) > 0.005
                || abs((float) ($receipt['returned_amount'] ?? 0)) > 0.005
                || $correctionRepository->allocationCount($receiptId) !== 0) {
                throw new RuntimeException('Receipt currency can only be migrated after all old-currency allocations have been released and no refund exists.');
            }
            $oldTreasuryAccountId = (int) ($receipt['treasury_account_id'] ?? 0);
            if ($oldTreasuryAccountId <= 0) {
                throw new RuntimeException('The original receipt has no treasury account; automatic migration is unsafe.');
            }

            $reversalReference = 'CURR-ERR-VOID-' . (string) $receipt['receipt_no'];
            $reversalJournalId = $accountingRepository->postWrongCurrencyCustomerReceiptReversal([
                'branch_id' => (int) $receipt['branch_id'],
                'booking_reference' => (string) $receipt['booking_reference'],
                'customer_receipt_id' => $receiptId,
                'source_reference' => $reversalReference,
                'entry_date' => (string) ($input['entry_date'] ?? date('Y-m-d')),
                'currency' => $oldCurrency,
                'amount' => $amount,
                'payment_method' => (string) $receipt['payment_method'],
                'treasury_account_id' => $oldTreasuryAccountId,
                'narration' => 'Wrong currency receipt reversed: ' . $reason,
                'actor_user_id' => $actorUserId,
            ]);
            $paymentRepository->voidReceipt($receiptId, $reason, $actorUserId, $reversalReference, $reversalJournalId);

            $replacementReceiptId = null;
            $replacementReceiptNo = null;
            if ($treatment === 'migrate_and_allocate') {
                if ($newBranchId <= 0 || $targetReceivableId <= 0) {
                    throw new RuntimeException('Target branch and corrected receivable are required to recreate this receipt.');
                }
                $receivable = $paymentRepository->findReceivableById($targetReceivableId);
                if ($receivable === null
                    || strtoupper((string) $receivable['currency']) !== $newCurrency
                    || (string) $receivable['booking_reference'] !== (string) $receipt['booking_reference']) {
                    throw new RuntimeException('The target receivable does not match the corrected booking currency.');
                }
                if ($newTreasuryAccountId <= 0) {
                    $treasury = (new TreasuryRepository($this->app))->defaultTreasuryAccountForPayment(
                        $newBranchId,
                        $newCurrency,
                        (string) $receipt['payment_method']
                    );
                    $newTreasuryAccountId = (int) ($treasury['id'] ?? 0);
                }
                if ($newTreasuryAccountId <= 0) {
                    throw new RuntimeException('No active target treasury account exists for the corrected receipt currency.');
                }

                $replacementReceiptNo = $paymentRepository->nextReceiptNumber();
                $replacementReceiptId = $paymentRepository->createReceipt([
                    'branch_id' => $newBranchId,
                    'traveler_id' => $receipt['traveler_id'] ?? null,
                    'receipt_purpose' => $receipt['receipt_purpose'] ?? 'booking_payment',
                    'booking_reference' => (string) $receipt['booking_reference'],
                    'receipt_no' => $replacementReceiptNo,
                    'receipt_date' => (string) $receipt['receipt_date'],
                    'currency' => $newCurrency,
                    'received_amount' => $amount,
                    'tendered_amount' => $amount,
                    'returned_amount' => 0,
                    'payment_method' => (string) $receipt['payment_method'],
                    'reference_number' => $receipt['reference_number'] ?? null,
                    'bank_card_detail' => $receipt['bank_card_detail'] ?? null,
                    'charges_amount' => (float) ($receipt['charges_amount'] ?? 0),
                    'status' => 'received',
                    'treasury_account_id' => $newTreasuryAccountId,
                    'remarks' => 'Corrected-currency replacement for ' . (string) $receipt['receipt_no'] . '. ' . $reason,
                    'actor_user_id' => $actorUserId,
                ]);
                $accountingRepository->postCustomerReceiptRecorded([
                    'branch_id' => $newBranchId,
                    'booking_reference' => (string) $receipt['booking_reference'],
                    'customer_receipt_id' => $replacementReceiptId,
                    'receipt_no' => $replacementReceiptNo,
                    'received_amount' => $amount,
                    'charges_amount' => (float) ($receipt['charges_amount'] ?? 0),
                    'payment_method' => (string) $receipt['payment_method'],
                    'treasury_account_id' => $newTreasuryAccountId,
                    'entry_date' => (string) $receipt['receipt_date'],
                    'currency' => $newCurrency,
                    'actor_user_id' => $actorUserId,
                ]);

                $settleAmount = round(min($amount, (float) ($receivable['outstanding_amount'] ?? 0)), 2);
                if ($settleAmount > 0.005) {
                    $allocation = $paymentRepository->allocateReceipt($replacementReceiptId, $targetReceivableId, $settleAmount, null, 'Corrected wrong-currency receipt allocation', $actorUserId);
                    $accountingRepository->postCustomerReceiptAllocation([
                        'branch_id' => $newBranchId,
                        'booking_reference' => (string) $receipt['booking_reference'],
                        'source_reference' => $replacementReceiptNo . '-ALLOC-' . (int) $allocation['allocation_id'],
                        'service_line_reference' => $receivable['service_line_reference'] ?? null,
                        'customer_receivable_item_id' => $targetReceivableId,
                        'customer_receipt_id' => $replacementReceiptId,
                        'allocated_amount' => $settleAmount,
                        'entry_date' => (string) $receipt['receipt_date'],
                        'currency' => $newCurrency,
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            $correctionId = $correctionRepository->recordCurrencyCorrection([
                'original_receipt_id' => $receiptId,
                'replacement_receipt_id' => $replacementReceiptId,
                'booking_reference' => (string) $receipt['booking_reference'],
                'service_line_reference' => $input['service_line_reference'] ?? null,
                'old_branch_id' => (int) $receipt['branch_id'],
                'new_branch_id' => $newBranchId > 0 ? $newBranchId : null,
                'old_currency' => $oldCurrency,
                'new_currency' => $newCurrency,
                'old_treasury_account_id' => $oldTreasuryAccountId,
                'new_treasury_account_id' => $newTreasuryAccountId > 0 ? $newTreasuryAccountId : null,
                'corrected_amount' => $amount,
                'treatment' => $treatment,
                'reversal_journal_entry_id' => $reversalJournalId,
                'reason' => $reason,
                'created_by_user_id' => $actorUserId,
            ]);
            AuditLog::record($this->app, 'customer.receipt.currency_corrected', [
                'user_id' => $actorUserId,
                'correction_id' => $correctionId,
                'original_receipt_id' => $receiptId,
                'replacement_receipt_id' => $replacementReceiptId,
                'old_currency' => $oldCurrency,
                'new_currency' => $newCurrency,
                'amount' => $amount,
            ]);

            if ($startedTransaction) {
                $db->commit();
            }
            return [
                'action' => 'corrected',
                'correction_id' => $correctionId,
                'original_receipt_id' => $receiptId,
                'replacement_receipt_id' => $replacementReceiptId,
                'replacement_receipt_no' => $replacementReceiptNo,
                'amount' => $amount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    public function recognizeFxGain(
        int $receiptId,
        float $amount,
        string $reason,
        int $actorUserId,
        ?string $entryDate = null,
        bool $automaticExchangeSettlement = false
    ): array
    {
        if (! $automaticExchangeSettlement) {
            $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can recognize customer-credit FX gain manually.');
        }
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }
        try {
            $repository = new CustomerReceiptCorrectionRepository($this->app);
            $existing = $repository->findIncomeRecognition($receiptId);
            if ($existing !== null) {
                if ($startedTransaction) {
                    $db->commit();
                }
                return ['action' => 'already_recognized'] + $existing;
            }
            $receipt = (new CustomerPaymentRepository($this->app))->findReceiptById($receiptId);
            if ($receipt === null || strtolower((string) $receipt['status']) === 'void') {
                throw new RuntimeException('The customer receipt is unavailable for FX-gain recognition.');
            }
            $amount = round($amount, 2);
            $journalId = (new AccountingRepository($this->app))->postCustomerCreditFxGain([
                'branch_id' => (int) $receipt['branch_id'],
                'booking_reference' => $receipt['booking_reference'] ?? null,
                'customer_receipt_id' => $receiptId,
                'source_reference' => (string) $receipt['receipt_no'] . '-FX-GAIN',
                'entry_date' => $entryDate ?? date('Y-m-d'),
                'currency' => (string) $receipt['currency'],
                'amount' => $amount,
                'narration' => $reason,
                'actor_user_id' => $actorUserId,
            ]);
            $updated = $repository->consumeReceiptCreditAsIncome($receiptId, $amount);
            $recognitionId = $repository->recordIncomeRecognition([
                'customer_receipt_id' => $receiptId,
                'booking_reference' => $receipt['booking_reference'] ?? null,
                'currency' => (string) $receipt['currency'],
                'amount' => $amount,
                'income_account_code' => 'FX_GAIN',
                'journal_entry_id' => $journalId,
                'reason' => $reason,
                'created_by_user_id' => $actorUserId,
            ]);
            if ($startedTransaction) {
                $db->commit();
            }
            return ['action' => 'recognized', 'recognition_id' => $recognitionId, 'receipt' => $updated];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}
