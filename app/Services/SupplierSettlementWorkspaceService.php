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

                if ($paidAmount > $selectedOutstandingTotal) {
                    throw new RuntimeException('Payment exceeds selected supplier payable. Reduce the amount or use Prepaid Supplier Payment.');
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

                if ($remainingAmount > 0) {
                    throw new RuntimeException('Selected supplier payable changed before allocation could complete. Please review and try again.');
                }

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

                    $paymentNo = $repository->nextSupplierPaymentNumber();
                    $paymentId = $repository->createSupplierPayment(array_merge($paymentPayload, [
                        'supplier_id' => $supplierId,
                        'branch_id' => (int) $booking['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'payment_no' => $paymentNo,
                        'paid_amount' => $supplierAllocatedAmount,
                        'actor_user_id' => $actorUserId,
                    ]));

                    $accounting->postSupplierPaymentRecorded([
                        'branch_id' => (int) $booking['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'payment_no' => $paymentNo,
                        'supplier_payment_id' => $paymentId,
                        'paid_amount' => $supplierAllocatedAmount,
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

    public function recordGlobalPostpaidSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $this->assertSupplierActive($supplier);
        $supplierId = (int) ($supplier['id'] ?? 0);
        $selectedObligationIds = $this->normalizedSelectedObligationIds($input['global_supplier_obligation_id'] ?? []);
        if ($selectedObligationIds === []) {
            throw new RuntimeException('Please select at least one supplier payable.');
        }

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
            $obligations = $repository->openGlobalObligationsForSettlement(
                $selectedObligationIds,
                $branchId,
                $supplierId,
                $currency
            );
            if (count($obligations) !== count($selectedObligationIds)) {
                throw new RuntimeException('One or more selected supplier payable rows are no longer available.');
            }

            $selectedOutstandingTotal = round(array_sum(array_map(
                static fn (array $row): float => (float) ($row['net_payable_amount'] ?? 0),
                $obligations
            )), 2);
            if ($paidAmount > $selectedOutstandingTotal) {
                throw new RuntimeException('Payment exceeds selected supplier payable. Reduce the amount or use Prepaid Supplier Payment.');
            }

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
                'narration' => 'Global supplier payment recorded',
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
                    'Global supplier settlement auto-allocation',
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
                    'narration' => 'Global supplier payment allocated to payable',
                ]);

                $affectedBookingReferences[$bookingReference] = true;
                $remainingAmount = round($remainingAmount - $allocationAmount, 2);
                $allocatedAmount = round($allocatedAmount + $allocationAmount, 2);
                $allocationCount++;
            }

            if ($remainingAmount > 0.005) {
                throw new RuntimeException('Selected supplier payable changed before allocation could complete. Please review and try again.');
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

            throw new RuntimeException('Global supplier payment could not be saved.', 0, $exception);
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

            $advance = $repository->findAdvanceById($advanceId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'advance' => $advance,
                'booking_id' => (int) $booking['id'],
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
                'branch_id' => $branchId,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'available_amount' => $depositAmount,
                'reference_no' => $this->optionalText($input['advance_reference_number'] ?? null, 100),
                'remarks' => $this->optionalText($input['advance_remarks'] ?? null, 4000),
                'received_at' => $advanceDate,
                'actor_user_id' => $actorUserId,
            ]);

            $accounting->postSupplierAdvanceDeposit([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'source_reference' => 'SADV-' . $advanceId,
                'entry_date' => $advanceDate,
                'currency' => $currency,
                'amount' => $depositAmount,
                'actor_user_id' => $actorUserId,
                'narration' => 'Global prepaid supplier payment recorded',
            ]);

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
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Global supplier advance could not be saved.', 0, $exception);
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
