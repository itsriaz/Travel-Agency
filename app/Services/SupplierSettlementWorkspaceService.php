<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TreasuryRepository;
use PDO;
use RuntimeException;

final class SupplierSettlementWorkspaceService extends Service
{
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_STATUSES = ['paid', 'void'];

    public function recordSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertNoPostedSupplierPaymentEditAttempt($input);

        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $payload = $this->validatedPaymentPayload($input);
        $payload['treasury_account_id'] = $this->resolveSupplierPaymentTreasuryAccountId(
            $payload,
            (int) $booking['branch_id'],
            (int) ($input['supplier_treasury_account_id'] ?? 0)
        );
        if ($payload['status'] === 'void') {
            $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can create a void supplier payment.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new SupplierRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $paymentNo = $repository->nextSupplierPaymentNumber();
            $paymentId = $repository->createSupplierPayment(array_merge($payload, [
                'supplier_id' => (int) $supplier['id'],
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'payment_no' => $paymentNo,
                'actor_user_id' => $actorUserId,
            ]));

            if ($payload['status'] !== 'void') {
                $accounting->postSupplierPaymentRecorded([
                    'branch_id' => (int) $booking['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'payment_no' => $paymentNo,
                    'supplier_payment_id' => $paymentId,
                    'paid_amount' => $payload['paid_amount'],
                    'charges_amount' => $payload['charges_amount'],
                    'payment_method' => $payload['payment_method'],
                    'treasury_account_id' => $payload['treasury_account_id'],
                    'entry_date' => $payload['payment_date'],
                    'currency' => $payload['currency'],
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $payment = $repository->findSupplierPaymentById($paymentId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'payment' => $payment,
                'booking_id' => (int) $booking['id'],
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier payment could not be saved.', 0, $exception);
        }
    }

    public function recordSimplePostpaidSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertNoPostedSupplierPaymentEditAttempt($input);

        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $selectedObligationIds = $this->normalizedSelectedObligationIds($input['simple_supplier_obligation_id'] ?? []);
        if ($selectedObligationIds === []) {
            throw new RuntimeException('Please select at least one supplier payable.');
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $repository = new SupplierRepository($this->app);
        $accounting = new AccountingRepository($this->app);

        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $result = (function () use (
                $booking,
                $selectedObligationIds,
                $input,
                $actorUserId,
                $repository,
                $accounting
            ): array {
                $obligations = $repository->openObligationsForSettlement(
                    $selectedObligationIds,
                    (string) $booking['booking_reference']
                );

                if (count($obligations) !== count($selectedObligationIds)) {
                    throw new RuntimeException('One or more selected supplier payable rows are no longer available.');
                }

                $currencies = array_values(array_unique(array_map(
                    static fn (array $row): string => (string) ($row['currency'] ?? ''),
                    $obligations
                )));
                if (count($currencies) !== 1) {
                    throw new RuntimeException('Please select payable rows with the same currency.');
                }

                $selectedCurrency = (string) $currencies[0];
                $postedCurrency = $this->normalizeCurrency((string) ($input['supplier_payment_currency'] ?? $selectedCurrency));
                if ($postedCurrency !== $selectedCurrency) {
                    throw new RuntimeException('Payment currency must match the selected supplier payable currency.');
                }

                $paidAmount = $this->positiveMoney($input['supplier_paid_amount'] ?? 0, 'Supplier paid amount');
                $selectedOutstandingTotal = round(array_sum(array_map(
                    static fn (array $row): float => (float) ($row['net_payable_amount'] ?? 0),
                    $obligations
                )), 2);

                $selectedSupplierIds = array_values(array_filter(array_unique(array_map(
                    static fn (array $row): int => (int) ($row['supplier_id'] ?? 0),
                    $obligations
                ))));
                if ($paidAmount > $selectedOutstandingTotal + 0.005 && count($selectedSupplierIds) !== 1) {
                    throw new RuntimeException('A supplier payment must belong to one supplier. Use Supplier Payment for that supplier; any excess will become supplier advance automatically.');
                }

                $paymentPayload = [
                    'payment_date' => $this->normalizeDate((string) ($input['supplier_payment_date'] ?? ''), 'Supplier payment date'),
                    'currency' => $selectedCurrency,
                    'payment_method' => $this->normalizeMethod((string) ($input['supplier_payment_method'] ?? 'cash')),
                    'treasury_account_id' => null,
                    'reference_number' => $this->optionalText($input['supplier_reference_number'] ?? null, 100),
                    'bank_card_detail' => $this->optionalText($input['supplier_bank_card_detail'] ?? null, 190),
                    'charges_amount' => 0.0,
                    'status' => 'paid',
                    'exchange_rate_to_booking' => null,
                    'remarks' => $this->optionalText($input['supplier_payment_remarks'] ?? null, 4000),
                ];
                $paymentPayload['treasury_account_id'] = $this->resolveSupplierPaymentTreasuryAccountId(
                    array_merge($paymentPayload, ['paid_amount' => $paidAmount]),
                    (int) $booking['branch_id'],
                    (int) ($input['supplier_treasury_account_id'] ?? 0)
                );

                $remainingAmount = $paidAmount;
                $allocationPlans = [];
                $allocatedAmount = 0.00;

                foreach ($obligations as $obligation) {
                    if ($remainingAmount <= 0) {
                        break;
                    }

                    $obligationBalance = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
                    if ($obligationBalance <= 0) {
                        continue;
                    }

                    $allocationAmount = min($remainingAmount, $obligationBalance);
                    if ($allocationAmount <= 0) {
                        continue;
                    }

                    $allocationPlans[] = [
                        'supplier_id' => (int) $obligation['supplier_id'],
                        'obligation_id' => (int) $obligation['id'],
                        'service_line_reference' => (string) ($obligation['service_line_reference'] ?? ''),
                        'amount' => $allocationAmount,
                    ];

                    $remainingAmount = round($remainingAmount - $allocationAmount, 2);
                    $allocatedAmount = round($allocatedAmount + $allocationAmount, 2);
                }

                $unallocatedAmount = round(max($remainingAmount, 0), 2);

                $allocationsBySupplier = [];
                foreach ($allocationPlans as $plan) {
                    $supplierId = (int) $plan['supplier_id'];
                    if (! isset($allocationsBySupplier[$supplierId])) {
                        $allocationsBySupplier[$supplierId] = [];
                    }

                    $allocationsBySupplier[$supplierId][] = $plan;
                }

                $paymentCount = 0;
                $allocationCount = 0;
                $payments = [];

                foreach ($allocationsBySupplier as $supplierId => $supplierPlans) {
                    $supplierAllocatedAmount = round(array_sum(array_map(
                        static fn (array $plan): float => (float) $plan['amount'],
                        $supplierPlans
                    )), 2);
                    if ($supplierAllocatedAmount <= 0) {
                        continue;
                    }

                    $paymentAmount = $supplierAllocatedAmount;
                    if ($unallocatedAmount > 0.005 && count($allocationsBySupplier) === 1) {
                        $paymentAmount = round($paymentAmount + $unallocatedAmount, 2);
                    }

                    $paymentNo = $repository->nextSupplierPaymentNumber();
                    $paymentId = $repository->createSupplierPayment(array_merge($paymentPayload, [
                        'supplier_id' => $supplierId,
                        'branch_id' => (int) $booking['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'payment_no' => $paymentNo,
                        'paid_amount' => $paymentAmount,
                        'actor_user_id' => $actorUserId,
                    ]));

                    $accounting->postSupplierPaymentRecorded([
                        'branch_id' => (int) $booking['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'payment_no' => $paymentNo,
                        'supplier_payment_id' => $paymentId,
                        'paid_amount' => $paymentAmount,
                        'charges_amount' => 0,
                        'payment_method' => $paymentPayload['payment_method'],
                        'treasury_account_id' => $paymentPayload['treasury_account_id'],
                        'entry_date' => $paymentPayload['payment_date'],
                        'currency' => $paymentPayload['currency'],
                        'actor_user_id' => $actorUserId,
                    ]);

                    foreach ($supplierPlans as $plan) {
                        $allocationId = $repository->allocateSupplierPayment(
                            $paymentId,
                            (int) $plan['obligation_id'],
                            (float) $plan['amount'],
                            null,
                            'Simple postpaid auto-allocation',
                            $actorUserId
                        );

                        $accounting->postSupplierPaymentAllocation([
                            'branch_id' => (int) $booking['branch_id'],
                            'booking_reference' => (string) $booking['booking_reference'],
                            'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
                            'service_line_reference' => $plan['service_line_reference'] !== '' ? $plan['service_line_reference'] : null,
                            'supplier_obligation_id' => (int) $plan['obligation_id'],
                            'supplier_payment_id' => $paymentId,
                            'allocated_amount' => (float) $plan['amount'],
                            'entry_date' => $paymentPayload['payment_date'],
                            'currency' => $selectedCurrency,
                            'actor_user_id' => $actorUserId,
                        ]);
                        $allocationCount++;
                    }

                    $payments[] = $repository->findSupplierPaymentById($paymentId);
                    $paymentCount++;
                }

                return [
                    'payments' => $payments,
                    'booking_id' => (int) $booking['id'],
                    'payment_count' => $paymentCount,
                    'allocation_count' => $allocationCount,
                    'allocated_amount' => $allocatedAmount,
                    'unallocated_amount' => $unallocatedAmount,
                    'currency' => $selectedCurrency,
                ];
            })();

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Records one supplier-account payment. Open payables are reconciled in
     * the background and any excess is retained as reusable supplier advance.
     */
    public function recordSupplierAccountPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $this->assertSupplierActive($supplier);
        $supplierId = (int) ($supplier['id'] ?? 0);
        $currency = $this->normalizeCurrency((string) ($input['supplier_payment_currency'] ?? 'PKR'));
        $paidAmount = $this->positiveMoney($input['supplier_paid_amount'] ?? 0, 'Supplier paid amount');
        $paymentPayload = [
            'payment_date' => $this->normalizeDate((string) ($input['supplier_payment_date'] ?? ''), 'Supplier payment date'),
            'currency' => $currency,
            'payment_method' => $this->normalizeMethod((string) ($input['supplier_payment_method'] ?? 'cash')),
            'treasury_account_id' => null,
            'reference_number' => $this->optionalText($input['supplier_reference_number'] ?? null, 100),
            'bank_card_detail' => $this->optionalText($input['supplier_bank_card_detail'] ?? null, 190),
            'charges_amount' => 0.0,
            'status' => 'paid',
            'exchange_rate_to_booking' => null,
            'remarks' => $this->optionalText($input['supplier_payment_remarks'] ?? null, 4000),
        ];
        $paymentPayload['treasury_account_id'] = $this->resolveSupplierPaymentTreasuryAccountId(
            array_merge($paymentPayload, ['paid_amount' => $paidAmount]),
            $branchId,
            (int) ($input['supplier_treasury_account_id'] ?? 0)
        );

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new SupplierRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $obligations = $repository->openSupplierAccountObligationsForSettlement(
                $branchId,
                $supplierId,
                $currency
            );
            $paymentNo = $repository->nextSupplierPaymentNumber();
            $paymentId = $repository->createSupplierPayment(array_merge($paymentPayload, [
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'booking_reference' => 'GLOBAL',
                'payment_scope' => 'global',
                'payment_no' => $paymentNo,
                'paid_amount' => $paidAmount,
                'actor_user_id' => $actorUserId,
            ]));

            $accounting->postSupplierPaymentRecorded([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'payment_no' => $paymentNo,
                'supplier_payment_id' => $paymentId,
                'paid_amount' => $paidAmount,
                'charges_amount' => 0,
                'payment_method' => $paymentPayload['payment_method'],
                'treasury_account_id' => $paymentPayload['treasury_account_id'],
                'entry_date' => $paymentPayload['payment_date'],
                'currency' => $currency,
                'actor_user_id' => $actorUserId,
                'narration' => 'Supplier payment recorded',
            ]);

            $remainingAmount = $paidAmount;
            $allocationCount = 0;
            $allocatedAmount = 0.0;
            $affectedBookingReferences = [];
            foreach ($obligations as $obligation) {
                if ($remainingAmount <= 0) {
                    break;
                }

                $obligationBalance = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
                if ($obligationBalance <= 0) {
                    continue;
                }

                $allocationAmount = min($remainingAmount, $obligationBalance);
                if ($allocationAmount <= 0) {
                    continue;
                }

                $allocationId = $repository->allocateSupplierPayment(
                    $paymentId,
                    (int) $obligation['id'],
                    $allocationAmount,
                    null,
                    'Internal supplier-account reconciliation',
                    $actorUserId
                );

                $bookingReference = (string) ($obligation['booking_reference'] ?? '');
                $accounting->postSupplierPaymentAllocation([
                    'branch_id' => $branchId,
                    'booking_reference' => $bookingReference,
                    'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null,
                    'supplier_obligation_id' => (int) $obligation['id'],
                    'supplier_payment_id' => $paymentId,
                    'allocated_amount' => $allocationAmount,
                    'entry_date' => $paymentPayload['payment_date'],
                    'currency' => $currency,
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Supplier account payment reconciled internally',
                ]);

                $affectedBookingReferences[$bookingReference] = true;
                $remainingAmount = round($remainingAmount - $allocationAmount, 2);
                $allocatedAmount = round($allocatedAmount + $allocationAmount, 2);
                $allocationCount++;
            }

            $unallocatedAmount = round(max($remainingAmount, 0), 2);
            $convertedAdvanceId = null;
            $convertedAdvanceAmount = 0.0;
            if ($unallocatedAmount > 0.005) {
                $convertedAdvanceAmount = $unallocatedAmount;
                $convertedAdvanceId = $repository->registerAdvance([
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                    'currency' => $currency,
                    'deposit_amount' => $convertedAdvanceAmount,
                    'available_amount' => $convertedAdvanceAmount,
                    'reference_no' => $paymentNo,
                    'remarks' => 'Extra supplier payment converted to supplier advance.',
                    'received_at' => $paymentPayload['payment_date'],
                    'actor_user_id' => $actorUserId,
                    'source_supplier_payment_id' => $paymentId,
                ]);
                $repository->convertSupplierPaymentExcessToAdvance(
                    $paymentId,
                    $convertedAdvanceId,
                    $convertedAdvanceAmount,
                    $actorUserId
                );
                $unallocatedAmount = 0.0;
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'payment' => $repository->findSupplierPaymentById($paymentId),
                'payment_no' => $paymentNo,
                'branch_id' => $branchId,
                'supplier_name' => (string) ($supplier['name'] ?? 'Supplier'),
                'currency' => $currency,
                'allocated_amount' => $allocatedAmount,
                'unallocated_amount' => $unallocatedAmount,
                'advance_amount' => $convertedAdvanceAmount,
                'advance_id' => $convertedAdvanceId,
                'allocation_count' => $allocationCount,
                'booking_count' => count(array_filter(array_keys($affectedBookingReferences))),
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier payment could not be saved.', 0, $exception);
        }
    }

    /**
     * Backward-compatible alias for older callers and deployed integrations.
     */
    public function recordGlobalPostpaidSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        return $this->recordSupplierAccountPayment($input, $actorUserId, $accessibleBranchIds);
    }

    public function voidGlobalSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can void supplier payments.');

        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);
        $voidReason = $this->requiredVoidReason($input['void_reason'] ?? null);
        if ($paymentId <= 0) {
            throw new RuntimeException('Select a valid supplier payment.');
        }

        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException('The selected supplier payment could not be found.');
        }

        $accessibleIds = array_map('intval', $accessibleBranchIds);
        if (! in_array((int) ($payment['branch_id'] ?? 0), $accessibleIds, true)) {
            throw new RuntimeException('You cannot correct a supplier payment outside your accessible branches.');
        }

        $paymentScope = str_replace(' ', '_', mb_strtolower(trim((string) ($payment['payment_scope'] ?? ''))));
        if ($paymentScope !== 'global' && strtoupper(trim((string) ($payment['booking_reference'] ?? ''))) !== 'GLOBAL') {
            throw new RuntimeException('This correction screen can reverse only bulk supplier payments. Open a booking-linked payment from its booking.');
        }

        if (str_replace(' ', '_', mb_strtolower(trim((string) ($payment['status'] ?? '')))) === 'void') {
            throw new RuntimeException('This supplier payment is already void.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $neutralizedAdvance = $repository->neutralizeUnusedConvertedAdvanceForPaymentVoid($paymentId, $actorUserId);
            $reversalReference = 'VOID-' . (string) ($payment['payment_no'] ?? ('P-' . $paymentId));
            $reversalJournalEntryId = (new AccountingRepository($this->app))->postSupplierPaymentVoidReversal([
                'branch_id' => (int) ($payment['branch_id'] ?? 0),
                'booking_reference' => null,
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($payment['payment_no'] ?? ''),
                'source_reference' => $reversalReference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) ($payment['currency'] ?? 'PKR'),
                'narration' => 'Supplier payment void reversal for ' . (string) ($payment['payment_no'] ?? $paymentId),
                'actor_user_id' => $actorUserId,
            ]);

            $voidResult = $repository->voidSupplierPayment(
                $paymentId,
                $voidReason,
                $actorUserId,
                $reversalReference,
                $reversalJournalEntryId
            );

            AuditLog::record($this->app, 'supplier.global_payment.voided', [
                'user_id' => $actorUserId,
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($voidResult['payment_no'] ?? ''),
                'supplier_id' => (int) ($voidResult['supplier_id'] ?? 0),
                'branch_id' => (int) ($voidResult['branch_id'] ?? 0),
                'void_reason' => $voidReason,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'allocation_count_reversed' => (int) ($voidResult['allocation_count_reversed'] ?? 0),
                'total_allocated_amount_reversed' => (float) ($voidResult['total_allocated_amount_reversed'] ?? 0),
                'affected_supplier_obligation_ids' => $voidResult['affected_supplier_obligation_ids'] ?? [],
                'neutralized_supplier_advance' => $neutralizedAdvance,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return array_merge($voidResult, [
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'neutralized_supplier_advance' => $neutralizedAdvance,
            ]);
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier payment could not be voided.', 0, $exception);
        }
    }

    public function allocateSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);
        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null || (string) $payment['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected supplier payment does not belong to this booking.');
        }

        $allocationLines = $this->normalizedAllocationLines($input);
        if ($allocationLines === []) {
            throw new RuntimeException('Enter at least one supplier allocation amount greater than zero.');
        }

        $accounting = new AccountingRepository($this->app);
        $allocationCount = 0;
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            foreach ($allocationLines as $line) {
                $obligation = $repository->findObligationById((int) $line['obligation_id']);
                if ($obligation === null || (string) $obligation['booking_reference'] !== (string) $booking['booking_reference']) {
                    throw new RuntimeException('One of the selected supplier obligations does not belong to this booking.');
                }

                $allocationId = $repository->allocateSupplierPayment(
                    $paymentId,
                    (int) $line['obligation_id'],
                    (float) $line['amount'],
                    $line['exchange_rate'],
                    $line['note'],
                    $actorUserId
                );

                $accounting->postSupplierPaymentAllocation([
                    'branch_id' => (int) $booking['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'source_reference' => (string) $payment['payment_no'] . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null,
                    'supplier_obligation_id' => (int) $line['obligation_id'],
                    'supplier_payment_id' => $paymentId,
                    'allocated_amount' => (float) $line['amount'],
                    'entry_date' => (string) $payment['payment_date'],
                    'currency' => (string) ($obligation['currency'] ?? $payment['currency']),
                    'actor_user_id' => $actorUserId,
                ]);

                $allocationCount++;
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => (int) $booking['id'],
                'allocation_count' => $allocationCount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier payment allocation could not be saved.', 0, $exception);
        }
    }

    public function voidSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can void supplier payments.');

        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);
        $voidReason = $this->requiredVoidReason($input['void_reason'] ?? null);

        if ($paymentId <= 0) {
            throw new RuntimeException('Select a valid supplier payment to void.');
        }

        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException('The selected supplier payment could not be found.');
        }

        if (! in_array((int) ($payment['branch_id'] ?? 0), $accessibleBranchIds, true)) {
            throw new RuntimeException('You cannot void a supplier payment outside your accessible branches.');
        }

        if ((string) ($payment['booking_reference'] ?? '') !== (string) ($booking['booking_reference'] ?? '')) {
            throw new RuntimeException('The selected supplier payment does not belong to this booking.');
        }

        $paymentStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($payment['status'] ?? ''))));
        if ($paymentStatus === 'void') {
            throw new RuntimeException('This supplier payment is already void.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $reversalReference = 'VOID-' . (string) ($payment['payment_no'] ?? ('P-' . $paymentId));
            $accountingRepository = new AccountingRepository($this->app);
            $reversalJournalEntryId = $accountingRepository->postSupplierPaymentVoidReversal([
                'branch_id' => (int) ($payment['branch_id'] ?? (int) ($booking['branch_id'] ?? 0)),
                'booking_reference' => (string) ($payment['booking_reference'] ?? (string) ($booking['booking_reference'] ?? '')),
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($payment['payment_no'] ?? ''),
                'source_reference' => $reversalReference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) ($payment['currency'] ?? 'PKR'),
                'narration' => 'Supplier payment void reversal for ' . (string) ($payment['payment_no'] ?? $paymentId),
                'actor_user_id' => $actorUserId,
            ]);

            $voidResult = $repository->voidSupplierPayment(
                $paymentId,
                $voidReason,
                $actorUserId,
                $reversalReference,
                $reversalJournalEntryId
            );

            AuditLog::record($this->app, 'supplier.payment.voided', [
                'user_id' => $actorUserId,
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($voidResult['payment_no'] ?? ''),
                'booking_reference' => (string) ($voidResult['booking_reference'] ?? ''),
                'supplier_id' => (int) ($voidResult['supplier_id'] ?? 0),
                'void_reason' => $voidReason,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'allocation_count_reversed' => (int) ($voidResult['allocation_count_reversed'] ?? 0),
                'total_allocated_amount_reversed' => (float) ($voidResult['total_allocated_amount_reversed'] ?? 0),
                'affected_supplier_obligation_ids' => $voidResult['affected_supplier_obligation_ids'] ?? [],
                'accounting_reversal_posted' => $reversalJournalEntryId !== null,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => (int) $booking['id'],
                'supplier_payment_id' => $paymentId,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier payment could not be voided.', 0, $exception);
        }
    }

    public function updateSupplierPaymentMetadata(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);

        if ($paymentId <= 0) {
            throw new RuntimeException('Select a valid supplier payment to update.');
        }

        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException('The selected supplier payment could not be found.');
        }

        if (! in_array((int) ($payment['branch_id'] ?? 0), $accessibleBranchIds, true)) {
            throw new RuntimeException('You cannot update a supplier payment outside your accessible branches.');
        }

        if ((string) ($payment['booking_reference'] ?? '') !== (string) ($booking['booking_reference'] ?? '')) {
            throw new RuntimeException('The selected supplier payment does not belong to this booking.');
        }

        $referenceNumber = $this->optionalText($input['supplier_reference_number'] ?? null, 100);
        $bankCardDetail = $this->optionalText($input['supplier_bank_card_detail'] ?? null, 190);
        $remarks = $this->optionalText($input['supplier_payment_remarks'] ?? null, 4000);

        $repository->updateSupplierPaymentMetadata($paymentId, [
            'reference_number' => $referenceNumber,
            'bank_card_detail' => $bankCardDetail,
            'remarks' => $remarks,
        ], $actorUserId);

        return [
            'payment' => $repository->findSupplierPaymentById($paymentId),
            'booking_id' => (int) $booking['id'],
        ];
    }

    public function correctSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can correct supplier payments.');

        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);
        if ($paymentId <= 0) {
            throw new RuntimeException('Select a valid supplier payment.');
        }

        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException('The selected supplier payment could not be found.');
        }

        $accessibleIds = array_values(array_unique(array_map('intval', $accessibleBranchIds)));
        $branchId = (int) ($payment['branch_id'] ?? 0);
        if (! in_array($branchId, $accessibleIds, true)) {
            throw new RuntimeException('You cannot correct a supplier payment outside your accessible branches.');
        }
        if (str_replace(' ', '_', mb_strtolower(trim((string) ($payment['status'] ?? '')))) === 'void') {
            throw new RuntimeException('A void supplier payment cannot be edited.');
        }

        $supplier = $this->resolveSupplier([
            'supplier_id' => (int) ($input['supplier_id'] ?? $payment['supplier_id'] ?? 0),
        ], $accessibleIds);
        $this->assertSupplierActive($supplier);

        $corrected = [
            'supplier_id' => (int) ($supplier['id'] ?? 0),
            'payment_date' => $this->normalizeDate((string) ($input['payment_date'] ?? $payment['payment_date'] ?? ''), 'Supplier payment date'),
            'currency' => $this->normalizeCurrency((string) ($input['currency'] ?? $payment['currency'] ?? 'PKR')),
            'paid_amount' => $this->positiveMoney($input['paid_amount'] ?? $payment['paid_amount'] ?? 0, 'Supplier paid amount'),
            'payment_method' => $this->normalizeMethod((string) ($input['payment_method'] ?? $payment['payment_method'] ?? 'cash')),
            'treasury_account_id' => (int) ($input['treasury_account_id'] ?? $payment['treasury_account_id'] ?? 0),
            'reference_number' => $this->optionalText($input['reference_number'] ?? $payment['reference_number'] ?? null, 100),
            'bank_card_detail' => $this->optionalText($input['bank_card_detail'] ?? $payment['bank_card_detail'] ?? null, 190),
            'remarks' => $this->optionalText($input['remarks'] ?? $payment['remarks'] ?? null, 4000),
        ];
        $reasonInput = trim((string) ($input['correction_reason'] ?? ''));
        $correctionReason = $reasonInput !== ''
            ? $this->requiredVoidReason($reasonInput)
            : 'Supplier payment corrected by user';

        $financialChanged = (int) ($payment['supplier_id'] ?? 0) !== $corrected['supplier_id']
            || (string) ($payment['payment_date'] ?? '') !== $corrected['payment_date']
            || strtoupper((string) ($payment['currency'] ?? '')) !== $corrected['currency']
            || abs((float) ($payment['paid_amount'] ?? 0) - $corrected['paid_amount']) > 0.005
            || str_replace(' ', '_', mb_strtolower((string) ($payment['payment_method'] ?? ''))) !== $corrected['payment_method']
            || (int) ($payment['treasury_account_id'] ?? 0) !== $corrected['treasury_account_id'];
        $supplierChanged = (int) ($payment['supplier_id'] ?? 0) !== $corrected['supplier_id'];
        $otherFinancialChanged = (string) ($payment['payment_date'] ?? '') !== $corrected['payment_date']
            || strtoupper((string) ($payment['currency'] ?? '')) !== $corrected['currency']
            || abs((float) ($payment['paid_amount'] ?? 0) - $corrected['paid_amount']) > 0.005
            || str_replace(' ', '_', mb_strtolower((string) ($payment['payment_method'] ?? ''))) !== $corrected['payment_method']
            || (int) ($payment['treasury_account_id'] ?? 0) !== $corrected['treasury_account_id'];
        $allocationCurrencies = $repository->supplierPaymentAllocationCurrencies($paymentId);
        if ($financialChanged && $allocationCurrencies !== []) {
            if (count($allocationCurrencies) !== 1 || $allocationCurrencies[0] !== $corrected['currency']) {
                $requiredCurrency = count($allocationCurrencies) === 1
                    ? $allocationCurrencies[0]
                    : implode(', ', $allocationCurrencies);
                throw new RuntimeException(
                    'Supplier payment currency must match the currency of its allocated supplier invoices. '
                    . 'This payment is allocated to ' . $requiredCurrency . ' invoices, so '
                    . $corrected['currency'] . ' cannot be selected.'
                );
            }
        }

        if (! $financialChanged) {
            $repository->updateSupplierPaymentMetadata($paymentId, [
                'reference_number' => $corrected['reference_number'],
                'bank_card_detail' => $corrected['bank_card_detail'],
                'remarks' => $corrected['remarks'],
            ], $actorUserId);
            AuditLog::record($this->app, 'supplier.payment.metadata_corrected', [
                'user_id' => $actorUserId,
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($payment['payment_no'] ?? ''),
                'reason' => $correctionReason,
            ]);

            return [
                'mode' => 'metadata',
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($payment['payment_no'] ?? ''),
                'payment' => $repository->findSupplierPaymentById($paymentId),
            ];
        }

        if ($supplierChanged && ! $otherFinancialChanged) {
            /** @var PDO $db */
            $db = $this->app->get('db');
            $startedTransaction = ! $db->inTransaction();
            if ($startedTransaction) {
                $db->beginTransaction();
            }
            try {
                $supplierResult = $this->correctSupplierPaymentSupplier([
                    'supplier_payment_id' => $paymentId,
                    'replacement_supplier_id' => $corrected['supplier_id'],
                    'correction_reason' => $correctionReason,
                ], $actorUserId, $accessibleIds);
                $repository->updateSupplierPaymentMetadata($paymentId, [
                    'reference_number' => $corrected['reference_number'],
                    'bank_card_detail' => $corrected['bank_card_detail'],
                    'remarks' => $corrected['remarks'],
                ], $actorUserId);
                if ($startedTransaction && $db->inTransaction()) {
                    $db->commit();
                }

                return array_merge($supplierResult, [
                    'mode' => 'supplier',
                    'supplier_payment_id' => $paymentId,
                    'payment' => $repository->findSupplierPaymentById($paymentId),
                ]);
            } catch (\Throwable $exception) {
                if ($startedTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                if ($exception instanceof RuntimeException) {
                    throw $exception;
                }

                throw new RuntimeException('Supplier payment supplier could not be corrected.', 0, $exception);
            }
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $originalAllocationIds = $repository->supplierPaymentAllocationObligationIds($paymentId);
            $scope = str_replace(' ', '_', mb_strtolower(trim((string) ($payment['payment_scope'] ?? 'booking'))));
            $isGlobal = $scope === 'global' || strtoupper(trim((string) ($payment['booking_reference'] ?? ''))) === 'GLOBAL';

            $accounting = new AccountingRepository($this->app);
            $linkedAdvanceAmount = round((float) ($payment['converted_advance_amount'] ?? 0), 2);
            if ($originalAllocationIds !== [] || $linkedAdvanceAmount > 0.005) {
                $releaseResult = $repository->correctSupplierPaymentSupplier(
                    $paymentId,
                    (int) ($payment['supplier_id'] ?? 0),
                    $actorUserId,
                    $correctionReason
                );
                foreach ((array) ($releaseResult['released_settlements'] ?? []) as $index => $releasedSettlement) {
                    $releasedAmount = round((float) ($releasedSettlement['released_amount'] ?? 0), 2);
                    if ($releasedAmount <= 0.005) {
                        continue;
                    }
                    $accounting->postSupplierSettlementRelease([
                        'branch_id' => $branchId,
                        'booking_reference' => (string) ($releasedSettlement['booking_reference'] ?? ''),
                        'source_reference' => 'SUPPLIER-PAYMENT-CORRECTION-' . (string) ($payment['payment_no'] ?? $paymentId) . '-RELEASE-' . ($index + 1),
                        'service_line_reference' => (string) ($releasedSettlement['service_line_reference'] ?? '') !== ''
                            ? (string) $releasedSettlement['service_line_reference']
                            : null,
                        'supplier_obligation_id' => (int) ($releasedSettlement['supplier_obligation_id'] ?? 0),
                        'released_amount' => $releasedAmount,
                        'entry_date' => $corrected['payment_date'],
                        'currency' => (string) ($releasedSettlement['currency'] ?? $corrected['currency']),
                        'actor_user_id' => $actorUserId,
                        'narration' => 'Supplier settlement released for payment correction',
                    ]);
                }
            }

            if ($isGlobal) {
                $this->voidGlobalSupplierPayment([
                    'supplier_payment_id' => $paymentId,
                    'void_reason' => $correctionReason,
                ], $actorUserId, $accessibleIds);
            } else {
                $booking = (new BookingRepository($this->app))->findBookingByReference(
                    (string) ($payment['booking_reference'] ?? ''),
                    $accessibleIds
                );
                if ($booking === null) {
                    throw new RuntimeException('The booking linked to this supplier payment could not be found.');
                }
                $this->voidSupplierPayment([
                    'booking_id' => (int) ($booking['id'] ?? 0),
                    'supplier_payment_id' => $paymentId,
                    'void_reason' => $correctionReason,
                ], $actorUserId, $accessibleIds);
            }

            $corrected['treasury_account_id'] = $this->resolveSupplierPaymentTreasuryAccountId(
                [
                    'payment_method' => $corrected['payment_method'],
                    'currency' => $corrected['currency'],
                    'paid_amount' => $corrected['paid_amount'],
                    'charges_amount' => 0,
                ],
                $branchId,
                $corrected['treasury_account_id']
            );

            $targetBookingReference = $isGlobal ? null : (string) ($payment['booking_reference'] ?? '');
            $obligations = $repository->openObligationsForCorrectedSupplierPayment(
                $corrected['supplier_id'],
                $branchId,
                $corrected['currency'],
                $targetBookingReference
            );
            if ($corrected['supplier_id'] === (int) ($payment['supplier_id'] ?? 0) && $originalAllocationIds !== []) {
                $priority = array_flip($originalAllocationIds);
                usort($obligations, static function (array $left, array $right) use ($priority): int {
                    $leftPriority = $priority[(int) ($left['id'] ?? 0)] ?? PHP_INT_MAX;
                    $rightPriority = $priority[(int) ($right['id'] ?? 0)] ?? PHP_INT_MAX;

                    return $leftPriority <=> $rightPriority;
                });
            }

            $paymentNo = $repository->nextSupplierPaymentNumber();
            $replacementId = $repository->createSupplierPayment([
                'supplier_id' => $corrected['supplier_id'],
                'branch_id' => $branchId,
                'booking_reference' => $isGlobal ? 'GLOBAL' : (string) ($payment['booking_reference'] ?? ''),
                'payment_scope' => $isGlobal ? 'global' : 'booking',
                'payment_no' => $paymentNo,
                'payment_date' => $corrected['payment_date'],
                'currency' => $corrected['currency'],
                'paid_amount' => $corrected['paid_amount'],
                'payment_method' => $corrected['payment_method'],
                'treasury_account_id' => $corrected['treasury_account_id'],
                'reference_number' => $corrected['reference_number'],
                'bank_card_detail' => $corrected['bank_card_detail'],
                'charges_amount' => 0,
                'status' => 'paid',
                'exchange_rate_to_booking' => null,
                'remarks' => $corrected['remarks'],
                'actor_user_id' => $actorUserId,
            ]);

            $accounting->postSupplierPaymentRecorded([
                'branch_id' => $branchId,
                'booking_reference' => $isGlobal ? null : (string) ($payment['booking_reference'] ?? ''),
                'payment_no' => $paymentNo,
                'supplier_payment_id' => $replacementId,
                'paid_amount' => $corrected['paid_amount'],
                'charges_amount' => 0,
                'payment_method' => $corrected['payment_method'],
                'treasury_account_id' => $corrected['treasury_account_id'],
                'entry_date' => $corrected['payment_date'],
                'currency' => $corrected['currency'],
                'actor_user_id' => $actorUserId,
                'narration' => 'Corrected supplier payment recorded',
            ]);

            $remainingAmount = $corrected['paid_amount'];
            $allocatedAmount = 0.0;
            $allocationCount = 0;
            foreach ($obligations as $obligation) {
                if ($remainingAmount <= 0.005) {
                    break;
                }
                $obligationBalance = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
                $allocationAmount = min($remainingAmount, $obligationBalance);
                if ($allocationAmount <= 0.005) {
                    continue;
                }
                $allocationId = $repository->allocateSupplierPayment(
                    $replacementId,
                    (int) ($obligation['id'] ?? 0),
                    $allocationAmount,
                    null,
                    'Automatic allocation after supplier payment correction',
                    $actorUserId
                );
                $accounting->postSupplierPaymentAllocation([
                    'branch_id' => $branchId,
                    'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                    'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== ''
                        ? (string) $obligation['service_line_reference']
                        : null,
                    'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                    'supplier_payment_id' => $replacementId,
                    'allocated_amount' => $allocationAmount,
                    'entry_date' => $corrected['payment_date'],
                    'currency' => $corrected['currency'],
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Corrected supplier payment allocated to payable',
                ]);
                $remainingAmount = round($remainingAmount - $allocationAmount, 2);
                $allocatedAmount = round($allocatedAmount + $allocationAmount, 2);
                $allocationCount++;
            }

            $advanceId = null;
            $advanceAmount = round(max($remainingAmount, 0), 2);
            if ($advanceAmount > 0.005) {
                $advanceId = $repository->registerAdvance([
                    'supplier_id' => $corrected['supplier_id'],
                    'branch_id' => $branchId,
                    'currency' => $corrected['currency'],
                    'deposit_amount' => $advanceAmount,
                    'available_amount' => $advanceAmount,
                    'reference_no' => $paymentNo,
                    'remarks' => 'Corrected supplier payment remainder retained as supplier advance.',
                    'received_at' => $corrected['payment_date'],
                    'actor_user_id' => $actorUserId,
                    'source_supplier_payment_id' => $replacementId,
                ]);
                $repository->convertSupplierPaymentExcessToAdvance(
                    $replacementId,
                    $advanceId,
                    $advanceAmount,
                    $actorUserId
                );
            }

            AuditLog::record($this->app, 'supplier.payment.corrected', [
                'user_id' => $actorUserId,
                'original_supplier_payment_id' => $paymentId,
                'original_payment_no' => (string) ($payment['payment_no'] ?? ''),
                'replacement_supplier_payment_id' => $replacementId,
                'replacement_payment_no' => $paymentNo,
                'payment_scope' => $isGlobal ? 'global' : 'booking',
                'reason' => $correctionReason,
                'before' => [
                    'supplier_id' => (int) ($payment['supplier_id'] ?? 0),
                    'payment_date' => (string) ($payment['payment_date'] ?? ''),
                    'currency' => (string) ($payment['currency'] ?? ''),
                    'paid_amount' => round((float) ($payment['paid_amount'] ?? 0), 2),
                    'payment_method' => (string) ($payment['payment_method'] ?? ''),
                    'treasury_account_id' => (int) ($payment['treasury_account_id'] ?? 0),
                ],
                'after' => $corrected,
                'allocated_amount' => $allocatedAmount,
                'advance_amount' => $advanceAmount,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'mode' => 'financial',
                'supplier_payment_id' => $replacementId,
                'original_supplier_payment_id' => $paymentId,
                'payment_no' => $paymentNo,
                'allocated_amount' => $allocatedAmount,
                'advance_amount' => $advanceAmount,
                'allocation_count' => $allocationCount,
                'payment_scope' => $isGlobal ? 'global' : 'booking',
                'payment' => $repository->findSupplierPaymentById($replacementId),
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier payment correction could not be saved.', 0, $exception);
        }
    }

    public function correctSupplierPaymentSupplier(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can correct a supplier payment supplier.');

        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);
        $replacementSupplierId = (int) ($input['replacement_supplier_id'] ?? 0);
        $reasonInput = trim((string) ($input['correction_reason'] ?? $input['void_reason'] ?? ''));
        $correctionReason = $reasonInput === ''
            ? 'Supplier corrected by user'
            : $this->requiredVoidReason($reasonInput);
        if ($paymentId <= 0) {
            throw new RuntimeException('Select a valid supplier payment to correct.');
        }
        if ($replacementSupplierId <= 0) {
            throw new RuntimeException('Select the correct supplier for this payment.');
        }

        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException('The selected supplier payment could not be found.');
        }

        $accessibleIds = array_map('intval', $accessibleBranchIds);
        if (! in_array((int) ($payment['branch_id'] ?? 0), $accessibleIds, true)) {
            throw new RuntimeException('You cannot correct a supplier payment outside your accessible branches.');
        }

        $bookingId = (int) ($input['booking_id'] ?? 0);
        if ($bookingId > 0) {
            $booking = $this->loadBooking($bookingId, $accessibleBranchIds);
            $bookingReference = (string) ($booking['booking_reference'] ?? '');
            $paymentReference = strtoupper(trim((string) ($payment['booking_reference'] ?? '')));
            if ($paymentReference !== 'GLOBAL' && $paymentReference !== strtoupper(trim($bookingReference))) {
                throw new RuntimeException('The selected supplier payment is not linked to this booking.');
            }
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $result = $repository->correctSupplierPaymentSupplier(
                $paymentId,
                $replacementSupplierId,
                $actorUserId,
                $correctionReason
            );

            $accounting = new AccountingRepository($this->app);
            $releaseJournalIds = [];
            foreach ((array) ($result['released_settlements'] ?? []) as $index => $releasedSettlement) {
                $releasedAmount = round((float) ($releasedSettlement['released_amount'] ?? 0), 2);
                if ($releasedAmount <= 0.005) {
                    continue;
                }
                $releaseJournalIds[] = $accounting->postSupplierSettlementRelease([
                    'branch_id' => (int) ($result['branch_id'] ?? 0),
                    'booking_reference' => (string) ($releasedSettlement['booking_reference'] ?? ''),
                    'source_reference' => 'SUPPLIER-CORRECTION-' . (string) ($result['payment_no'] ?? $paymentId) . '-RELEASE-' . ($index + 1),
                    'service_line_reference' => (string) ($releasedSettlement['service_line_reference'] ?? '') !== ''
                        ? (string) $releasedSettlement['service_line_reference']
                        : null,
                    'supplier_obligation_id' => (int) ($releasedSettlement['supplier_obligation_id'] ?? 0),
                    'released_amount' => $releasedAmount,
                    'entry_date' => date('Y-m-d'),
                    'currency' => (string) ($releasedSettlement['currency'] ?? $result['currency'] ?? 'PKR'),
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Supplier payment allocation released for supplier correction',
                ]);
            }

            $paymentScope = str_replace(' ', '_', mb_strtolower(trim((string) ($result['payment_scope'] ?? 'booking'))));
            $targetBookingReference = $paymentScope === 'global'
                ? null
                : (string) ($result['booking_reference'] ?? '');
            $targetObligations = $repository->openObligationsForCorrectedSupplierPayment(
                $replacementSupplierId,
                (int) ($result['branch_id'] ?? 0),
                (string) ($result['currency'] ?? 'PKR'),
                $targetBookingReference
            );

            $remainingAmount = round((float) ($result['reallocatable_amount'] ?? 0), 2);
            $reallocatedAmount = 0.0;
            $newAllocations = [];
            foreach ($targetObligations as $obligation) {
                if ($remainingAmount <= 0.005) {
                    break;
                }
                $obligationBalance = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
                $allocationAmount = min($remainingAmount, $obligationBalance);
                if ($allocationAmount <= 0.005) {
                    continue;
                }
                $allocationId = $repository->allocateSupplierPayment(
                    $paymentId,
                    (int) ($obligation['id'] ?? 0),
                    $allocationAmount,
                    null,
                    'Automatic reallocation after supplier correction',
                    $actorUserId
                );
                $accounting->postSupplierPaymentAllocation([
                    'branch_id' => (int) ($result['branch_id'] ?? 0),
                    'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                    'source_reference' => (string) ($result['payment_no'] ?? $paymentId) . '-REALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== ''
                        ? (string) $obligation['service_line_reference']
                        : null,
                    'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                    'supplier_payment_id' => $paymentId,
                    'allocated_amount' => $allocationAmount,
                    'entry_date' => (string) ($result['payment_date'] ?? date('Y-m-d')),
                    'currency' => (string) ($obligation['currency'] ?? $result['currency'] ?? 'PKR'),
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Corrected supplier payment allocated to payable',
                ]);
                $newAllocations[] = [
                    'allocation_id' => $allocationId,
                    'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                    'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                    'allocated_amount' => $allocationAmount,
                ];
                $remainingAmount = round($remainingAmount - $allocationAmount, 2);
                $reallocatedAmount = round($reallocatedAmount + $allocationAmount, 2);
            }

            $newAdvanceId = null;
            $newAdvanceAmount = round(max($remainingAmount, 0), 2);
            if ($newAdvanceAmount > 0.005) {
                $newAdvanceId = $repository->registerAdvance([
                    'supplier_id' => $replacementSupplierId,
                    'branch_id' => (int) ($result['branch_id'] ?? 0),
                    'currency' => (string) ($result['currency'] ?? 'PKR'),
                    'deposit_amount' => $newAdvanceAmount,
                    'available_amount' => $newAdvanceAmount,
                    'reference_no' => (string) ($result['payment_no'] ?? ''),
                    'remarks' => 'Supplier payment remainder converted to advance after supplier correction.',
                    'received_at' => (string) ($result['payment_date'] ?? date('Y-m-d')),
                    'actor_user_id' => $actorUserId,
                    'source_supplier_payment_id' => $paymentId,
                ]);
                $repository->convertSupplierPaymentExcessToAdvance(
                    $paymentId,
                    $newAdvanceId,
                    $newAdvanceAmount,
                    $actorUserId
                );
            }

            $result['release_journal_entry_ids'] = $releaseJournalIds;
            $result['new_allocations'] = $newAllocations;
            $result['reallocated_amount'] = $reallocatedAmount;
            $result['new_allocation_count'] = count($newAllocations);
            $result['new_advance_id'] = $newAdvanceId;
            $result['new_advance_amount'] = $newAdvanceAmount;

            AuditLog::record($this->app, 'supplier.payment.supplier_corrected', [
                'user_id' => $actorUserId,
                'supplier_payment_id' => $paymentId,
                'payment_no' => (string) ($result['payment_no'] ?? ''),
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($result['booking_reference'] ?? ''),
                'branch_id' => (int) ($result['branch_id'] ?? 0),
                'currency' => (string) ($result['currency'] ?? 'PKR'),
                'old_supplier_id' => (int) ($result['old_supplier_id'] ?? 0),
                'old_supplier_name' => (string) ($result['old_supplier_name'] ?? ''),
                'new_supplier_id' => (int) ($result['new_supplier_id'] ?? 0),
                'new_supplier_name' => (string) ($result['new_supplier_name'] ?? ''),
                'allocation_count_released' => (int) ($result['allocation_count_released'] ?? 0),
                'advance_application_count_released' => (int) ($result['advance_application_count_released'] ?? 0),
                'linked_advance_count_neutralized' => (int) ($result['linked_advance_count_neutralized'] ?? 0),
                'new_allocation_count' => (int) ($result['new_allocation_count'] ?? 0),
                'reallocated_amount' => (float) ($result['reallocated_amount'] ?? 0),
                'new_advance_amount' => (float) ($result['new_advance_amount'] ?? 0),
                'old_payables_reopened' => true,
                'correction_reason' => $correctionReason,
                'cash_movement_changed' => false,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return $result + ['booking_id' => $bookingId];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('The supplier payment supplier could not be corrected.', 0, $exception);
        }
    }

    public function recordSupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $currency = $this->normalizeCurrency((string) ($input['advance_currency'] ?? 'PKR'));
        $depositAmount = $this->positiveMoney($input['advance_amount'] ?? 0, 'Advance amount');
        $advanceDate = $this->normalizeDate((string) ($input['advance_date'] ?? ''), 'Advance date');

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new SupplierRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $advanceId = $repository->registerAdvance([
                'supplier_id' => (int) $supplier['id'],
                'branch_id' => (int) $booking['branch_id'],
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'available_amount' => $depositAmount,
                'reference_no' => $this->optionalText($input['advance_reference_number'] ?? null, 100),
                'remarks' => $this->optionalText($input['advance_remarks'] ?? null, 4000),
                'received_at' => $advanceDate,
                'actor_user_id' => $actorUserId,
            ]);

            $accounting->postSupplierAdvanceDeposit([
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'source_reference' => 'SADV-' . $advanceId,
                'entry_date' => $advanceDate,
                'currency' => $currency,
                'amount' => $depositAmount,
                'actor_user_id' => $actorUserId,
            ]);

            $reconciliation = $this->applyAvailableSupplierAdvancesToOpenPayables(
                [(int) $booking['branch_id']],
                $actorUserId,
                (int) $supplier['id'],
                $currency,
                $advanceDate
            );

            $advance = $repository->findAdvanceById($advanceId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'advance' => $advance,
                'booking_id' => (int) $booking['id'],
                'applied_amount' => (float) ($reconciliation['applied_amount'] ?? 0),
                'application_count' => (int) ($reconciliation['application_count'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier advance could not be saved.', 0, $exception);
        }
    }

    public function recordGlobalSupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $this->assertSupplierActive($supplier);
        $currency = $this->normalizeCurrency((string) ($input['advance_currency'] ?? 'PKR'));
        $paymentMethod = $this->normalizeMethod((string) ($input['advance_payment_method'] ?? 'cash'));
        if (! in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
            throw new RuntimeException('Prepaid supplier payments must use Cash or Bank Transfer.');
        }
        $depositAmount = $this->positiveMoney($input['advance_amount'] ?? 0, 'Advance amount');
        $advanceDate = $this->normalizeDate((string) ($input['advance_date'] ?? ''), 'Advance date');
        $treasuryAccountId = $this->resolveSupplierPaymentTreasuryAccountId([
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'paid_amount' => $depositAmount,
            'charges_amount' => 0,
        ], $branchId, (int) ($input['advance_treasury_account_id'] ?? 0));

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new SupplierRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $advanceId = $repository->registerAdvance([
                'supplier_id' => (int) $supplier['id'],
                'branch_id' => $branchId,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'available_amount' => $depositAmount,
                'reference_no' => $this->optionalText($input['advance_reference_number'] ?? null, 100),
                'remarks' => $this->optionalText($input['advance_remarks'] ?? null, 4000),
                'received_at' => $advanceDate,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'actor_user_id' => $actorUserId,
            ]);

            $journalEntryId = $accounting->postSupplierAdvanceDeposit([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'source_reference' => 'SADV-' . $advanceId,
                'entry_date' => $advanceDate,
                'currency' => $currency,
                'amount' => $depositAmount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'actor_user_id' => $actorUserId,
                'narration' => 'Global prepaid supplier payment recorded',
            ]);
            $repository->attachAdvanceJournal($advanceId, $journalEntryId);

            $reconciliation = $this->applyAvailableSupplierAdvancesToOpenPayables(
                [$branchId],
                $actorUserId,
                (int) $supplier['id'],
                $currency,
                $advanceDate
            );

            $advance = $repository->findAdvanceById($advanceId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'advance' => $advance,
                'branch_id' => $branchId,
                'supplier_name' => (string) ($supplier['name'] ?? 'Supplier'),
                'currency' => $currency,
                'amount' => $depositAmount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'applied_amount' => (float) ($reconciliation['applied_amount'] ?? 0),
                'application_count' => (int) ($reconciliation['application_count'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier advance could not be saved.', 0, $exception);
        }
    }

    public function applyAvailableSupplierAdvancesToOpenPayables(
        array $branchIds,
        int $actorUserId,
        int $supplierId = 0,
        string $currency = '',
        ?string $entryDate = null
    ): array {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        if ($branchIds === []) {
            return ['applied_amount' => 0.0, 'application_count' => 0, 'obligations' => []];
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new SupplierRepository($this->app);
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $obligations = $repository->openObligationsForAdvanceReconciliation(
                $branchIds,
                $supplierId,
                $currency
            );
            $appliedTotal = 0.0;
            $applicationCount = 0;
            $results = [];
            $effectiveDate = $entryDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) === 1
                ? $entryDate
                : date('Y-m-d');

            foreach ($obligations as $obligation) {
                $result = $repository->autoApplyAvailableAdvanceToObligation(
                    (int) ($obligation['id'] ?? 0),
                    $actorUserId
                );
                $delta = round((float) ($result['applied_amount_delta'] ?? 0), 2);
                if ($delta > 0.005) {
                    $accounting->postSupplierAdvanceApplication([
                        'branch_id' => (int) ($obligation['branch_id'] ?? 0),
                        'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                        'source_reference' => 'SADV-AUTO-' . (int) ($obligation['id'] ?? 0),
                        'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== ''
                            ? (string) $obligation['service_line_reference']
                            : null,
                        'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                        'amount' => $delta,
                        'entry_date' => $effectiveDate,
                        'currency' => (string) ($obligation['currency'] ?? 'PKR'),
                        'actor_user_id' => $actorUserId,
                        'narration' => 'Existing supplier advance automatically applied to payable',
                    ]);
                    $appliedTotal = round($appliedTotal + $delta, 2);
                    $applicationCount++;
                } elseif ($delta < -0.005) {
                    $accounting->postSupplierAdvanceApplicationAdjusted([
                        'branch_id' => (int) ($obligation['branch_id'] ?? 0),
                        'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                        'source_reference' => 'SADV-AUTO-ADJUST-' . (int) ($obligation['id'] ?? 0),
                        'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== ''
                            ? (string) $obligation['service_line_reference']
                            : null,
                        'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                        'adjustment_amount' => $delta,
                        'entry_date' => $effectiveDate,
                        'currency' => (string) ($obligation['currency'] ?? 'PKR'),
                        'actor_user_id' => $actorUserId,
                        'narration' => 'Supplier advance application automatically reconciled',
                    ]);
                    $appliedTotal = round($appliedTotal + $delta, 2);
                    $applicationCount++;
                }
                if (abs($delta) > 0.005) {
                    $results[] = [
                        'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                        'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                        'currency' => (string) ($obligation['currency'] ?? ''),
                        'applied_amount_delta' => $delta,
                        'remaining_payable' => round((float) ($result['obligation']['net_payable_amount'] ?? 0), 2),
                    ];
                }
            }

            AuditLog::record($this->app, 'supplier.advance.auto_reconciled', [
                'user_id' => $actorUserId,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : null,
                'branch_ids' => $branchIds,
                'applied_amount' => $appliedTotal,
                'application_count' => $applicationCount,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'applied_amount' => $appliedTotal,
                'application_count' => $applicationCount,
                'obligations' => $results,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier advances could not be reconciled to open payables.', 0, $exception);
        }
    }

    public function correctGlobalSupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $advanceId = (int) ($input['supplier_advance_id'] ?? 0);
        $repository = new SupplierRepository($this->app);
        $old = $repository->findAdvanceById($advanceId);
        if ($old === null) {
            throw new RuntimeException('Please select a valid prepaid supplier payment to edit.');
        }
        if ((int) ($old['source_supplier_payment_id'] ?? 0) > 0) {
            throw new RuntimeException('This supplier credit came from a payment/refund workflow and must be corrected from that original transaction.');
        }

        $branchId = $this->resolveAccessibleBranchId((int) ($old['branch_id'] ?? 0), $accessibleBranchIds);
        $currency = $this->normalizeCurrency((string) ($old['currency'] ?? 'PKR'));
        $oldSupplierId = (int) ($old['supplier_id'] ?? 0);
        $newSupplierId = (int) ($input['supplier_id'] ?? $oldSupplierId);
        $newSupplier = $repository->findSupplierById($newSupplierId);
        if ($newSupplier === null) {
            throw new RuntimeException('Please select a valid supplier for this prepaid payment.');
        }
        $this->assertSupplierActive($newSupplier);
        $paymentMethod = $this->normalizeMethod((string) ($input['advance_payment_method'] ?? 'cash'));
        if (! in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
            throw new RuntimeException('Prepaid supplier payments must use Cash or Bank Transfer.');
        }

        $newAmount = $this->positiveMoney($input['advance_amount'] ?? 0, 'Advance amount');
        $usedAmount = round(
            (float) ($old['deposit_amount'] ?? 0) - (float) ($old['available_amount'] ?? 0),
            2
        );
        if ($newSupplierId !== $oldSupplierId && $usedAmount > 0.005) {
            throw new RuntimeException(
                'The supplier cannot be changed because '
                . $currency . ' ' . number_format($usedAmount, 2)
                . ' has already been applied to supplier invoices. Correct those allocations first.'
            );
        }
        if ($newAmount + 0.005 < $usedAmount) {
            throw new RuntimeException(
                'The corrected amount cannot be less than the '
                . $currency . ' ' . number_format($usedAmount, 2)
                . ' already used against supplier invoices.'
            );
        }

        $paymentDate = $this->normalizeDate((string) ($input['advance_date'] ?? ''), 'Payment date');
        $reason = $this->optionalText($input['correction_reason'] ?? null, 1000);
        if ($reason === null || trim($reason) === '') {
            throw new RuntimeException('Please enter a short reason for this correction.');
        }

        $treasuryAccountId = (int) ($input['advance_treasury_account_id'] ?? 0);
        $treasuryRepository = new TreasuryRepository($this->app);
        $treasuryRepository->validatePaymentTreasuryAccount(
            $treasuryAccountId,
            $branchId,
            $currency,
            $paymentMethod
        );
        $availableBalance = $treasuryRepository->currentBalanceForAccountId($treasuryAccountId);
        if ($treasuryAccountId === (int) ($old['treasury_account_id'] ?? 0)) {
            $availableBalance += (float) ($old['deposit_amount'] ?? 0);
        }
        if (round($availableBalance + 0.005, 2) < $newAmount) {
            throw new RuntimeException(
                'Selected source account does not have enough balance after reversing the old entry. Available: '
                . number_format($availableBalance, 2) . ' ' . $currency . '.'
            );
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $oldJournalEntryId = (int) ($old['journal_entry_id'] ?? 0);
            $reversalJournalEntryId = $oldJournalEntryId > 0
                ? $accounting->reverseJournalEntry($oldJournalEntryId, [
                    'branch_id' => $branchId,
                    'source_type' => 'supplier_advance_correction_reversal',
                    'source_reference' => 'SADV-CORR-REV-' . $advanceId,
                    'entry_date' => $paymentDate,
                    'currency' => $currency,
                    'narration' => 'Reversal before prepaid supplier payment correction: ' . $reason,
                    'actor_user_id' => $actorUserId,
                ])
                : null;

            $newJournalEntryId = $accounting->postSupplierAdvanceDeposit([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'source_reference' => 'SADV-CORR-' . $advanceId,
                'entry_date' => $paymentDate,
                'currency' => $currency,
                'amount' => $newAmount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'actor_user_id' => $actorUserId,
                'narration' => 'Corrected prepaid supplier payment: ' . $reason,
            ]);

            $referenceNo = $this->optionalText($input['advance_reference_number'] ?? null, 100);
            $remarks = $this->optionalText($input['advance_remarks'] ?? null, 4000);
            $repository->correctAdvance([
                'id' => $advanceId,
                'supplier_id' => $newSupplierId,
                'deposit_amount' => $newAmount,
                'available_amount' => round($newAmount - $usedAmount, 2),
                'received_at' => $paymentDate,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'journal_entry_id' => $newJournalEntryId,
                'reference_no' => $referenceNo,
                'remarks' => $remarks,
                'actor_user_id' => $actorUserId,
            ]);
            $repository->recordAdvanceCorrection([
                'supplier_advance_id' => $advanceId,
                'reason' => $reason,
                'old_supplier_id' => $oldSupplierId,
                'new_supplier_id' => $newSupplierId,
                'old_payment_date' => $old['received_at'] ?? null,
                'new_payment_date' => $paymentDate,
                'old_amount' => $old['deposit_amount'] ?? 0,
                'new_amount' => $newAmount,
                'old_payment_method' => $old['payment_method'] ?? null,
                'new_payment_method' => $paymentMethod,
                'old_treasury_account_id' => $old['treasury_account_id'] ?? null,
                'new_treasury_account_id' => $treasuryAccountId,
                'old_reference_no' => $old['reference_no'] ?? null,
                'new_reference_no' => $referenceNo,
                'old_remarks' => $old['remarks'] ?? null,
                'new_remarks' => $remarks,
                'old_journal_entry_id' => $oldJournalEntryId > 0 ? $oldJournalEntryId : null,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'new_journal_entry_id' => $newJournalEntryId,
                'actor_user_id' => $actorUserId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'advance_id' => $advanceId,
                'supplier_id' => $newSupplierId,
                'supplier_name' => (string) ($newSupplier['name'] ?? 'Supplier'),
                'currency' => $currency,
                'amount' => $newAmount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Prepaid supplier payment could not be corrected.', 0, $exception);
        }
    }

    public function applySupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $advanceId = (int) ($input['supplier_advance_id'] ?? 0);
        $obligationId = (int) ($input['supplier_obligation_id'] ?? 0);
        $amount = $this->positiveMoney($input['advance_apply_amount'] ?? 0, 'Advance application amount');

        $repository = new SupplierRepository($this->app);
        $advance = $repository->findAdvanceById($advanceId);
        $obligation = $repository->findObligationById($obligationId);

        if ($advance === null || $obligation === null || (string) $obligation['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected advance application pair is invalid for this booking.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $appliedAmount = $repository->applyAdvanceToObligation($advanceId, $obligationId, $amount, $actorUserId);
            if ($appliedAmount <= 0.005) {
                throw new RuntimeException('No supplier advance amount could be applied to the selected payable.');
            }

            $accounting->postSupplierAdvanceApplication([
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'source_reference' => 'SADV-APPLY-' . $advanceId . '-' . $obligationId,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $advance['currency'],
                'amount' => $appliedAmount,
                'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null,
                'supplier_obligation_id' => $obligationId,
                'actor_user_id' => $actorUserId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => (int) $booking['id'],
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Supplier advance application could not be saved.', 0, $exception);
        }
    }

    public function applyCrossCurrencySupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $advanceId = (int) ($input['supplier_advance_id'] ?? 0);
        $obligationId = (int) ($input['supplier_obligation_id'] ?? 0);
        $amount = $this->positiveMoney($input['advance_apply_amount'] ?? 0, 'Payable amount to cover');
        $rate = (float) ($input['exchange_rate'] ?? 0);
        $rateDate = $this->normalizeDate((string) ($input['exchange_rate_effective_date'] ?? ''), 'Rate date');
        $note = $this->optionalText($input['application_note'] ?? null, 190);

        $repository = new SupplierRepository($this->app);
        $advance = $repository->findAdvanceById($advanceId);
        $obligation = $repository->findObligationById($obligationId);

        if ($advance === null || $obligation === null || (string) $obligation['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected cross-currency advance pair is invalid for this booking.');
        }

        $advanceCurrency = strtoupper((string) ($advance['currency'] ?? ''));
        $obligationCurrency = strtoupper((string) ($obligation['currency'] ?? ''));
        if ($advanceCurrency === $obligationCurrency) {
            throw new RuntimeException('This option is only for different-currency supplier advances.');
        }

        $exchangeRates = new ExchangeRateRepository($this->app);
        $exactRate = $exchangeRates->getExactRate($advanceCurrency, $obligationCurrency, $rateDate);
        if ($exactRate === null) {
            $exchangeRates->upsertDailyRate($advanceCurrency, $obligationCurrency, $rateDate, $rate, (int) $booking['branch_id'], $actorUserId);
            $exactRate = $exchangeRates->getExactRate($advanceCurrency, $obligationCurrency, $rateDate);
        }

        $exactRateValue = round((float) ($exactRate['exchange_rate'] ?? 0), 8);
        if ($exactRateValue <= 0 || abs(round($rate, 8) - $exactRateValue) > 0.0001) {
            throw new RuntimeException('FX_RATE_MISMATCH: Confirmed supplier advance rate does not match the stored exact rate.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $accounting = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $application = $repository->applyCrossCurrencyAdvanceToObligation(
                $advanceId,
                $obligationId,
                $amount,
                $advanceCurrency,
                $obligationCurrency,
                $exactRateValue,
                $rateDate,
                $note,
                $actorUserId
            );

            if ((float) ($application['applied_amount'] ?? 0) <= 0.005) {
                throw new RuntimeException('No supplier advance amount could be applied to the selected payable.');
            }

            $accounting->postSupplierAdvanceApplication([
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'source_reference' => 'SADV-FX-' . $advanceId . '-' . $obligationId,
                'entry_date' => date('Y-m-d'),
                'currency' => $obligationCurrency,
                'amount' => (float) $application['applied_amount'],
                'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null,
                'supplier_obligation_id' => $obligationId,
                'narration' => sprintf(
                    'Supplier advance FX applied: %.2f %s consumed at %.8f to cover %.2f %s',
                    (float) $application['advance_amount_consumed'],
                    $advanceCurrency,
                    $exactRateValue,
                    (float) $application['applied_amount'],
                    $obligationCurrency
                ),
                'actor_user_id' => $actorUserId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => (int) $booking['id'],
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Cross-currency supplier advance application could not be saved.', 0, $exception);
        }
    }

    private function loadBooking(int $bookingId, array $accessibleBranchIds): array
    {
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage supplier settlement for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        return $booking;
    }

    private function resolveAccessibleBranchId(int $branchId, array $accessibleBranchIds): int
    {
        if ($branchId <= 0 || ! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch.');
        }

        return $branchId;
    }

    private function resolveSupplier(array $input, array $accessibleBranchIds): array
    {
        $supplierId = (int) ($input['supplier_id'] ?? 0);
        $supplierName = trim((string) ($input['supplier_name'] ?? ''));
        $repository = new SupplierRepository($this->app);

        if ($supplierId > 0) {
            $supplier = $repository->findSupplierById($supplierId);
            if ($supplier !== null) {
                return $supplier;
            }
        }

        $supplier = $repository->findAccessibleSupplierByName($supplierName, $accessibleBranchIds);
        if ($supplier === null) {
            throw new RuntimeException('Please select a valid accessible supplier.');
        }

        return $supplier;
    }

    private function assertSupplierActive(array $supplier): void
    {
        if ((int) ($supplier['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Please select an active supplier.');
        }
    }

    private function assertNoPostedSupplierPaymentEditAttempt(array $input): void
    {
        $existingPaymentId = max(
            (int) ($input['supplier_payment_id'] ?? 0),
            (int) ($input['payment_id'] ?? 0)
        );
        if ($existingPaymentId > 0) {
            $existingPayment = (new SupplierRepository($this->app))->findSupplierPaymentById($existingPaymentId);
            if ($existingPayment !== null) {
                throw new RuntimeException('Saved supplier payment financial values cannot be edited. Void the payment and create a new one.');
            }
        }

        if (trim((string) ($input['payment_no'] ?? '')) !== '') {
            throw new RuntimeException('Supplier payment number is system-generated. Saved supplier payment financial values cannot be edited here. Void the payment and create a new one.');
        }
    }

    private function validatedPaymentPayload(array $input): array
    {
        return [
            'payment_date' => $this->normalizeDate((string) ($input['supplier_payment_date'] ?? ''), 'Supplier payment date'),
            'currency' => $this->normalizeCurrency((string) ($input['supplier_payment_currency'] ?? 'PKR')),
            'paid_amount' => $this->positiveMoney($input['supplier_paid_amount'] ?? 0, 'Supplier paid amount'),
            'payment_method' => $this->normalizeMethod((string) ($input['supplier_payment_method'] ?? 'cash')),
            'treasury_account_id' => null,
            'reference_number' => $this->optionalText($input['supplier_reference_number'] ?? null, 100),
            'bank_card_detail' => $this->optionalText($input['supplier_bank_card_detail'] ?? null, 190),
            'charges_amount' => $this->nonNegativeMoney($input['supplier_charges_amount'] ?? 0, 'Supplier charges'),
            'status' => $this->normalizeStatus((string) ($input['supplier_payment_status'] ?? 'paid')),
            'exchange_rate_to_booking' => $this->normalizeOptionalExchangeRate((string) ($input['supplier_exchange_rate_to_booking'] ?? '')),
            'remarks' => $this->optionalText($input['supplier_payment_remarks'] ?? null, 4000),
        ];
    }

    private function normalizedAllocationLines(array $input): array
    {
        $ids = $input['supplier_allocation_obligation_id'] ?? [];
        $amounts = $input['supplier_allocation_amount'] ?? [];
        $notes = $input['supplier_allocation_note'] ?? [];
        $rates = $input['supplier_allocation_exchange_rate'] ?? [];
        if (! is_array($ids) || ! is_array($amounts)) {
            return [];
        }

        $lines = [];
        foreach ($ids as $index => $id) {
            $amount = $amounts[$index] ?? null;
            if (! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $lines[] = [
                'obligation_id' => (int) $id,
                'amount' => round((float) $amount, 2),
                'note' => $this->optionalText($notes[$index] ?? null, 190),
                'exchange_rate' => $this->normalizeOptionalExchangeRate((string) ($rates[$index] ?? '')),
            ];
        }

        return $lines;
    }

    private function normalizedSelectedObligationIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($item): int => (int) $item,
            $value
        ), static fn (int $id): bool => $id > 0)));

        return $ids;
    }

    private function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid supplier currency.');
        }

        return $currency;
    }

    private function normalizeMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, $this->activePaymentMethodCodes(), true)) {
            throw new RuntimeException('Please select a valid supplier payment method.');
        }

        return $method;
    }

    private function resolveSupplierPaymentTreasuryAccountId(array $payload, int $branchId, int $selectedTreasuryAccountId): ?int
    {
        $method = (string) ($payload['payment_method'] ?? '');
        if (! in_array($method, ['cash', 'bank_transfer'], true)) {
            return null;
        }

        $currency = (string) ($payload['currency'] ?? 'PKR');
        $amount = round((float) ($payload['paid_amount'] ?? 0) + (float) ($payload['charges_amount'] ?? 0), 2);
        $treasuryRepository = new TreasuryRepository($this->app);
        $account = $treasuryRepository->validatePaymentTreasuryAccount(
            $selectedTreasuryAccountId,
            $branchId,
            $currency,
            $method
        );

        $availableBalance = $treasuryRepository->currentBalanceForAccountId((int) $account['id']);
        if (round($availableBalance + 0.005, 2) < $amount) {
            throw new RuntimeException(
                'Selected supplier payment source account does not have enough balance. Available: '
                . number_format($availableBalance, 2)
                . ' ' . $currency . '.'
            );
        }

        return (int) $account['id'];
    }

    private function activePaymentMethodCodes(): array
    {
        $codes = array_map(
            static fn (string $code): string => str_replace(' ', '_', mb_strtolower(trim($code))),
            array_keys((new MasterDataRepository($this->app))->activeCodeLabelMap('payment_methods'))
        );

        return $codes !== [] ? array_values(array_unique($codes)) : ['cash', 'bank_transfer', 'debit_card', 'credit_card'];
    }

    private function normalizeStatus(string $value): string
    {
        $status = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new RuntimeException('Please select a valid supplier payment status.');
        }

        return $status;
    }

    private function normalizeDate(string $value, string $label): string
    {
        $date = trim($value);
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException($label . ' is required.');
        }

        return $date;
    }

    private function normalizeOptionalExchangeRate(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed) || (float) $trimmed <= 0) {
            throw new RuntimeException('Supplier exchange rate must be greater than zero.');
        }

        return round((float) $trimmed, 8);
    }

    private function positiveMoney(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException($label . ' must be greater than zero.');
        }

        return round((float) $value, 2);
    }

    private function nonNegativeMoney(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new RuntimeException($label . ' cannot be negative.');
        }

        return round((float) $value, 2);
    }

    private function requiredVoidReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if ($reason === '') {
            throw new RuntimeException('Void reason is required.');
        }

        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Void reason must be at least 5 characters.');
        }

        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('Void reason may not exceed 1000 characters.');
        }

        return $reason;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('One of the supplier settlement values exceeds the allowed length.');
        }

        return $text;
    }
}
