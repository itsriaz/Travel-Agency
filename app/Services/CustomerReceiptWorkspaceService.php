<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TreasuryRepository;
use PDO;
use RuntimeException;

final class CustomerReceiptWorkspaceService extends Service
{
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_STATUSES = ['received', 'void'];
    private const ALLOWED_RECEIPT_SCOPES = ['whole_invoice', 'passenger_specific'];
    private const DIRECT_SUPPLIER_PAYMENT_METHOD = 'customer_paid_supplier';

    public function saveSettlementExchangeRate(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $payload = $this->validatedSettlementRatePayload($input, $accessibleBranchIds);
        $exchangeRateRepository = new ExchangeRateRepository($this->app);

        $exchangeRateRepository->upsertDailyRate(
            $payload['rate_from_currency'],
            $payload['rate_to_currency'],
            $payload['exchange_rate_effective_date'],
            $payload['exchange_rate'],
            $payload['branch_id'],
            $actorUserId
        );

        return $payload;
    }

    public function saveReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertNoPostedReceiptEditAttempt($input);

        if (($input['settlement_mode'] ?? 'normal') === 'exchange') {
            return $this->saveExchangeSettlement($input, $actorUserId, $accessibleBranchIds);
        }

        $receiptAction = strtolower(trim((string) ($input['receipt_action'] ?? 'save')));
        $skipReceiptCreation = $receiptAction === 'no_receipt';

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot record receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $payload = $this->validatedReceiptPayload($input);
        if ($this->isDirectSupplierPaymentMethod((string) $payload['payment_method'])) {
            return $this->saveDirectSupplierReceipt($input, $booking, $payload, $actorUserId, $accessibleBranchIds);
        }
        if (round((float) ($payload['received_amount'] ?? 0), 2) <= 0.005) {
            $skipReceiptCreation = true;
            $receiptAction = 'no_receipt';
        }
        $payload['treasury_account_id'] = $skipReceiptCreation
            ? null
            : $this->resolveReceiptTreasuryAccountId(
                $input,
                $payload,
                (int) $booking['branch_id']
            );
        if ($payload['status'] === 'void') {
            $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can create a void customer receipt.');
        }

        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $originalReceivedAmount = (float) ($payload['received_amount'] ?? 0);
            $payload['received_amount'] = round($originalReceivedAmount, 2);
            $payload['tendered_amount'] = $originalReceivedAmount;
            $payload['returned_amount'] = 0.00;
            $traceReceivables = $this->sameCurrencyBookingReceivables(
                $repository,
                $booking,
                (string) $payload['currency']
            );

            app_write_log('workspace.receipt.save_payload', 'Customer receipt payload prepared before insert.', array_merge(app_request_log_context(), [
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'currency' => (string) $payload['currency'],
                'original_received_amount' => round($originalReceivedAmount, 2),
                'stored_received_amount' => (float) $payload['received_amount'],
                'tendered_amount' => (float) $payload['tendered_amount'],
                'returned_amount' => (float) $payload['returned_amount'],
                'open_receivable_count' => count($traceReceivables),
                'open_receivable_total' => round(array_sum(array_map(
                    static fn (array $receivable): float => max(0.0, (float) ($receivable['outstanding_amount'] ?? 0)),
                    $traceReceivables
                )), 2),
                'open_receivable_ids' => array_values(array_map(
                    static fn (array $receivable): int => (int) ($receivable['id'] ?? 0),
                    $traceReceivables
                )),
            ]));

            $receiptId = 0;
            $receiptNo = '';
            if (! $skipReceiptCreation) {
                $receiptNo = $repository->nextReceiptNumber();

                $receiptId = $repository->createReceipt(array_merge($payload, [
                    'branch_id' => (int) $booking['branch_id'],
                    'traveler_id' => (int) ($booking['lead_traveler_id'] ?? 0) ?: null,
                    'receipt_purpose' => 'booking_payment',
                    'booking_reference' => (string) $booking['booking_reference'],
                    'receipt_no' => $receiptNo,
                    'actor_user_id' => $actorUserId,
                ]));
            }

            if (! $skipReceiptCreation && $payload['status'] !== 'void') {
                $receiptScope = $this->normalizeReceiptScope((string) ($input['receipt_scope'] ?? 'whole_invoice'));
                $targetReceivableId = $this->resolveReceiptAllocationTargetId(
                    $input,
                    $repository,
                    $booking,
                    (string) $payload['currency'],
                    $receiptScope
                );

                if ($this->shouldPostReceiptAccounting($payload)) {
                    $accountingRepository->postCustomerReceiptRecorded([
                        'branch_id' => (int) $booking['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'customer_receipt_id' => $receiptId,
                        'receipt_no' => $receiptNo,
                        'received_amount' => $payload['received_amount'],
                        'charges_amount' => $payload['charges_amount'],
                        'payment_method' => $payload['payment_method'],
                        'treasury_account_id' => $payload['treasury_account_id'],
                        'entry_date' => $payload['receipt_date'],
                        'currency' => $payload['currency'],
                        'actor_user_id' => $actorUserId,
                    ]);

                    $this->autoAllocateCurrentCurrencyReceipt(
                        $receiptId,
                        $receiptNo,
                        $booking,
                        $accessibleBranchIds,
                        $payload['currency'],
                        $payload['receipt_date'],
                        $actorUserId,
                        $repository,
                        $accountingRepository,
                        [],
                        $targetReceivableId !== null ? [$targetReceivableId] : null,
                        $receiptScope === 'passenger_specific'
                            ? 'Automatically applied to selected passenger due.'
                            : 'Automatically applied to current invoice.'
                    );
                }
            }

            $advanceApplyResult = $this->applyCustomerAdvanceToCurrentInvoice(
                $input,
                $booking,
                (string) $payload['currency'],
                (string) $payload['receipt_date'],
                $actorUserId,
                $repository,
                $accountingRepository
            );

            if ($skipReceiptCreation) {
                app_write_log('workspace.receipt.save_result', 'Customer receipt workflow saved current invoice/service without creating a receipt.', array_merge(app_request_log_context(), [
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                    'receipt_action' => $receiptAction,
                    'currency' => (string) $payload['currency'],
                    'stored_received_amount' => 0.00,
                    'stored_allocated_amount' => 0.00,
                    'stored_unallocated_amount' => 0.00,
                    'stored_returned_amount' => 0.00,
                    'stored_status' => 'no_receipt',
                    'advance_allocated_amount' => round((float) ($advanceApplyResult['allocated_amount'] ?? 0), 2),
                    'advance_allocation_count' => (int) ($advanceApplyResult['allocation_count'] ?? 0),
                ]));
            } else {
                $savedReceiptTrace = $repository->findReceiptById($receiptId);
                app_write_log('workspace.receipt.save_result', 'Customer receipt saved and current-invoice allocation attempted.', array_merge(app_request_log_context(), [
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                    'receipt_id' => $receiptId,
                    'receipt_no' => $receiptNo,
                    'currency' => (string) $payload['currency'],
                    'stored_received_amount' => round((float) ($savedReceiptTrace['received_amount'] ?? 0), 2),
                    'stored_allocated_amount' => round((float) ($savedReceiptTrace['allocated_amount'] ?? 0), 2),
                    'stored_unallocated_amount' => round((float) ($savedReceiptTrace['unallocated_amount'] ?? 0), 2),
                    'stored_returned_amount' => round((float) ($savedReceiptTrace['returned_amount'] ?? 0), 2),
                    'stored_status' => (string) ($savedReceiptTrace['status'] ?? ''),
                    'advance_allocated_amount' => round((float) ($advanceApplyResult['allocated_amount'] ?? 0), 2),
                    'advance_allocation_count' => (int) ($advanceApplyResult['allocation_count'] ?? 0),
                ]));
            }

            if ($payload['due_date'] !== null) {
                $bookingRepository->updateBookingDueDate(
                    $bookingId,
                    $payload['due_date'],
                    $actorUserId
                );
                $repository->updateOpenReceivableDueDates(
                    (string) $booking['booking_reference'],
                    $payload['due_date'],
                    $actorUserId
                );
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $receiptId > 0 ? $repository->findReceiptById($receiptId) : null,
                'booking_id' => $bookingId,
                'returned_amount' => (float) $payload['returned_amount'],
                'advance_applied' => $advanceApplyResult,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer receipt could not be saved.', 0, $exception);
        }
    }

    private function saveDirectSupplierReceipt(
        array $input,
        array $booking,
        array $payload,
        int $actorUserId,
        array $accessibleBranchIds
    ): array {
        $directAmount = round((float) ($payload['received_amount'] ?? 0), 2);
        if ($directAmount <= 0.005) {
            throw new RuntimeException('Please enter the amount the customer paid directly to the supplier.');
        }

        $bookingId = (int) ($booking['id'] ?? 0);
        $bookingReference = (string) ($booking['booking_reference'] ?? '');
        $branchId = (int) ($booking['branch_id'] ?? 0);
        $currency = (string) ($payload['currency'] ?? 'PKR');

        $receiptScope = $this->normalizeReceiptScope((string) ($input['receipt_scope'] ?? 'whole_invoice'));

        /** @var PDO $db */
        $db = $this->app->get('db');
        $paymentRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $supplierObligationId = $this->resolveDirectSupplierObligationId(
                $input,
                $supplierRepository,
                $bookingReference,
                $currency
            );
            $obligations = $supplierRepository->openObligationsForSettlement([$supplierObligationId], $bookingReference);
            $obligation = $obligations[0] ?? null;
            if ($obligation === null) {
                throw new RuntimeException('The selected supplier payable is no longer open for this booking.');
            }

            if ((string) ($obligation['currency'] ?? '') !== $currency) {
                throw new RuntimeException('The customer payment currency must match the selected supplier payable currency.');
            }

            $supplierOutstanding = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
            if ($directAmount > $supplierOutstanding + 0.005) {
                throw new RuntimeException('Direct supplier payment exceeds the selected supplier payable balance.');
            }

            $targetReceivableId = $this->resolveReceiptAllocationTargetId(
                $input,
                $paymentRepository,
                $booking,
                $currency,
                $receiptScope
            );
            $receivables = $this->sameCurrencyBookingReceivables(
                $paymentRepository,
                $booking,
                $currency,
                [],
                $targetReceivableId !== null ? [$targetReceivableId] : null
            );
            $customerOutstanding = round(array_sum(array_map(
                static fn (array $receivable): float => max(0.0, (float) ($receivable['outstanding_amount'] ?? 0)),
                $receivables
            )), 2);
            if ($receivables === [] || $customerOutstanding <= 0.005) {
                throw new RuntimeException('No open customer receivable is available for this direct supplier payment.');
            }
            if ($directAmount > $customerOutstanding + 0.005) {
                throw new RuntimeException('Direct supplier payment exceeds the selected customer outstanding balance.');
            }

            $receiptNo = $paymentRepository->nextReceiptNumber();
            $receiptId = $paymentRepository->createReceipt(array_merge($payload, [
                'branch_id' => $branchId,
                'traveler_id' => (int) ($booking['lead_traveler_id'] ?? 0) ?: null,
                'receipt_purpose' => 'booking_payment',
                'booking_reference' => $bookingReference,
                'receipt_no' => $receiptNo,
                'treasury_account_id' => null,
                'actor_user_id' => $actorUserId,
                'remarks' => trim((string) ($payload['remarks'] ?? '')) !== ''
                    ? $payload['remarks']
                    : 'Customer paid supplier directly.',
            ]));

            $paymentNo = $supplierRepository->nextSupplierPaymentNumber();
            $supplierPaymentId = $supplierRepository->createSupplierPayment([
                'supplier_id' => (int) ($obligation['supplier_id'] ?? 0),
                'branch_id' => $branchId,
                'booking_reference' => $bookingReference,
                'payment_no' => $paymentNo,
                'payment_date' => $payload['receipt_date'],
                'currency' => $currency,
                'payment_method' => self::DIRECT_SUPPLIER_PAYMENT_METHOD,
                'treasury_account_id' => null,
                'reference_number' => $payload['reference_number'] ?? null,
                'bank_card_detail' => $payload['bank_card_detail'] ?? null,
                'charges_amount' => 0.0,
                'status' => 'paid',
                'exchange_rate_to_booking' => null,
                'remarks' => 'Customer paid supplier directly. Customer receipt: ' . $receiptNo,
                'paid_amount' => $directAmount,
                'actor_user_id' => $actorUserId,
            ]);

            $supplierAllocationId = $supplierRepository->allocateSupplierPayment(
                $supplierPaymentId,
                $supplierObligationId,
                $directAmount,
                1.0,
                'Customer paid supplier directly',
                $actorUserId
            );

            $remainingAmount = $directAmount;
            foreach ($receivables as $receivable) {
                if ($remainingAmount <= 0.005) {
                    break;
                }

                $receivableBalance = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
                if ($receivableBalance <= 0.005) {
                    continue;
                }

                $allocationAmount = round(min($remainingAmount, $receivableBalance), 2);
                if ($allocationAmount <= 0.005) {
                    continue;
                }

                $allocationResult = $paymentRepository->allocateReceiptExplicit([
                    'receipt_id' => $receiptId,
                    'receivable_item_id' => (int) $receivable['id'],
                    'receivable_amount_to_settle' => $allocationAmount,
                    'payment_currency' => $currency,
                    'rate_from_currency' => $currency,
                    'rate_to_currency' => $currency,
                    'exchange_rate' => 1.0,
                    'exchange_rate_effective_date' => $payload['receipt_date'],
                    'allocation_note' => 'Customer paid supplier directly.',
                    'actor_user_id' => $actorUserId,
                ]);

                $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
                if ($allocatedAmount <= 0.005) {
                    continue;
                }

                $accountingRepository->postCustomerDirectSupplierPayment([
                    'branch_id' => $branchId,
                    'booking_reference' => $bookingReference,
                    'source_reference' => $receiptNo . '/' . $paymentNo . '-DIRECT-' . (int) ($allocationResult['allocation_id'] ?? 0),
                    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== ''
                        ? (string) $receivable['service_line_reference']
                        : ((string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null),
                    'supplier_obligation_id' => $supplierObligationId,
                    'supplier_payment_id' => $supplierPaymentId,
                    'customer_receivable_item_id' => (int) $receivable['id'],
                    'customer_receipt_id' => $receiptId,
                    'amount' => $allocatedAmount,
                    'entry_date' => $payload['receipt_date'],
                    'currency' => $currency,
                    'actor_user_id' => $actorUserId,
                ]);

                $remainingAmount = round($remainingAmount - $allocatedAmount, 2);
            }

            if ($remainingAmount > 0.005) {
                throw new RuntimeException('Direct supplier payment could not be fully allocated to customer receivables.');
            }

            AuditLog::record($this->app, 'workspace.customer_direct_supplier_payment.posted', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'customer_receipt_id' => $receiptId,
                'customer_receipt_no' => $receiptNo,
                'supplier_payment_id' => $supplierPaymentId,
                'supplier_payment_no' => $paymentNo,
                'supplier_payment_allocation_id' => $supplierAllocationId,
                'supplier_obligation_id' => $supplierObligationId,
                'currency' => $currency,
                'amount' => $directAmount,
            ]);

            app_write_log('workspace.receipt.direct_supplier_payment_posted', 'Customer direct-to-supplier payment posted.', array_merge(app_request_log_context(), [
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'supplier_payment_id' => $supplierPaymentId,
                'supplier_payment_no' => $paymentNo,
                'supplier_obligation_id' => $supplierObligationId,
                'currency' => $currency,
                'amount' => $directAmount,
            ]));

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $paymentRepository->findReceiptById($receiptId),
                'booking_id' => $bookingId,
                'returned_amount' => 0.0,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Direct supplier payment could not be saved.', 0, $exception);
        }
    }

    public function recordGlobalCustomerPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }

        $selectedReceivableIds = $this->normalizedSelectedReceivableIds($input['global_customer_receivable_id'] ?? []);
        if ($selectedReceivableIds === []) {
            throw new RuntimeException('Please select at least one customer receivable.');
        }

        $payload = $this->validatedReceiptPayload($input);
        $currency = (string) ($payload['currency'] ?? 'PKR');
        $payload['treasury_account_id'] = $this->resolveReceiptTreasuryAccountId($input, $payload, $branchId);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $receivables = $repository->openGlobalReceivablesForSettlement(
                $selectedReceivableIds,
                $branchId,
                $travelerId,
                $currency
            );
            if (count($receivables) !== count($selectedReceivableIds)) {
                throw new RuntimeException('One or more selected customer receivable rows are no longer available.');
            }

            $originalReceivedAmount = (float) ($payload['received_amount'] ?? 0);
            $payload['received_amount'] = round($originalReceivedAmount, 2);
            $payload['tendered_amount'] = $originalReceivedAmount;
            $payload['returned_amount'] = 0.00;

            $receiptNo = $repository->nextReceiptNumber();
            $receiptId = $repository->createReceipt(array_merge($payload, [
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'receipt_purpose' => 'global_payment',
                'booking_reference' => 'GLOBAL',
                'receipt_no' => $receiptNo,
                'actor_user_id' => $actorUserId,
            ]));

            if ($this->shouldPostReceiptAccounting($payload)) {
                $accountingRepository->postCustomerReceiptRecorded([
                    'branch_id' => $branchId,
                    'booking_reference' => null,
                    'customer_receipt_id' => $receiptId,
                    'receipt_no' => $receiptNo,
                    'received_amount' => $payload['received_amount'],
                    'charges_amount' => $payload['charges_amount'],
                    'payment_method' => $payload['payment_method'],
                    'treasury_account_id' => $payload['treasury_account_id'],
                    'entry_date' => $payload['receipt_date'],
                    'currency' => $currency,
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Global customer payment recorded',
                ]);
            }

            $remainingAmount = round((float) ($payload['received_amount'] ?? 0), 2);
            $allocationCount = 0;
            $allocatedAmount = 0.0;
            $affectedBookingReferences = [];
            $customerName = 'Customer';

            foreach ($receivables as $receivable) {
                if ($remainingAmount <= 0.005) {
                    break;
                }

                $customerName = (string) ($receivable['customer_name'] ?? $customerName);
                $receivableBalance = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
                if ($receivableBalance <= 0.005) {
                    continue;
                }

                $allocationAmount = round(min($remainingAmount, $receivableBalance), 2);
                if ($allocationAmount <= 0.005) {
                    continue;
                }

                $allocationResult = $repository->allocateReceiptExplicit([
                    'receipt_id' => $receiptId,
                    'receivable_item_id' => (int) $receivable['id'],
                    'receivable_amount_to_settle' => $allocationAmount,
                    'payment_currency' => $currency,
                    'rate_from_currency' => $currency,
                    'rate_to_currency' => $currency,
                    'exchange_rate' => 1.0,
                    'exchange_rate_effective_date' => $payload['receipt_date'],
                    'allocation_note' => 'Global customer payment auto-allocation',
                    'actor_user_id' => $actorUserId,
                ]);
                $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
                $allocatedReceiptAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
                if ($allocationId <= 0 || $allocatedReceiptAmount <= 0) {
                    throw new RuntimeException('Receipt auto allocation could not be completed.');
                }

                $bookingReference = (string) ($receivable['booking_reference'] ?? '');
                $accountingRepository->postCustomerReceiptAllocation([
                    'branch_id' => (int) ($receivable['branch_id'] ?? $branchId),
                    'booking_reference' => $bookingReference,
                    'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                    'customer_receivable_item_id' => (int) $receivable['id'],
                    'customer_receipt_id' => $receiptId,
                    'allocated_amount' => $allocatedReceiptAmount,
                    'entry_date' => $payload['receipt_date'],
                    'currency' => $currency,
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Global customer payment allocated to receivable',
                ]);

                $affectedBookingReferences[$bookingReference] = true;
                $remainingAmount = round($remainingAmount - $allocatedReceiptAmount, 2);
                $allocatedAmount = round($allocatedAmount + $allocatedReceiptAmount, 2);
                $allocationCount++;
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $repository->findReceiptById($receiptId),
                'receipt_no' => $receiptNo,
                'branch_id' => $branchId,
                'customer_name' => $customerName,
                'currency' => $currency,
                'received_amount' => (float) $payload['received_amount'],
                'allocated_amount' => $allocatedAmount,
                'unallocated_amount' => round($remainingAmount, 2),
                'returned_amount' => (float) $payload['returned_amount'],
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

            throw new RuntimeException('Global customer payment could not be saved.', 0, $exception);
        }
    }

    public function recordCustomerAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }

        $payload = $this->validatedReceiptPayload($input);
        if ((float) ($payload['received_amount'] ?? 0) <= 0) {
            throw new RuntimeException('Customer advance amount must be greater than zero.');
        }
        $currency = (string) ($payload['currency'] ?? 'PKR');
        $payload['treasury_account_id'] = $this->resolveReceiptTreasuryAccountId($input, $payload, $branchId);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $receiptNo = $repository->nextReceiptNumber();
            $receiptId = $repository->createReceipt(array_merge($payload, [
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'receipt_purpose' => 'customer_advance',
                'booking_reference' => 'ADVANCE',
                'receipt_no' => $receiptNo,
                'tendered_amount' => (float) $payload['received_amount'],
                'returned_amount' => 0.00,
                'actor_user_id' => $actorUserId,
            ]));

            $accountingRepository->postCustomerReceiptRecorded([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'customer_receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'received_amount' => $payload['received_amount'],
                'charges_amount' => $payload['charges_amount'],
                'payment_method' => $payload['payment_method'],
                'treasury_account_id' => $payload['treasury_account_id'],
                'entry_date' => $payload['receipt_date'],
                'currency' => $currency,
                'actor_user_id' => $actorUserId,
                'narration' => 'Customer advance received',
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_no' => $receiptNo,
                'receipt_id' => $receiptId,
                'customer_name' => $repository->customerNameByTraveler($travelerId),
                'currency' => $currency,
                'received_amount' => (float) $payload['received_amount'],
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer advance could not be saved.', 0, $exception);
        }
    }

    public function applyCustomerAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }

        $currency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? 'PKR'));
        $advanceReceiptId = (int) ($input['advance_receipt_id'] ?? 0);
        if ($advanceReceiptId <= 0) {
            throw new RuntimeException('Please select the customer advance to apply.');
        }

        $selectedReceivableIds = $this->normalizedSelectedReceivableIds($input['global_customer_receivable_id'] ?? []);
        if ($selectedReceivableIds === []) {
            throw new RuntimeException('Please select at least one receivable to apply the customer advance.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $advance = $repository->findReceiptById($advanceReceiptId);
            if ($advance === null
                || (int) ($advance['branch_id'] ?? 0) !== $branchId
                || (int) ($advance['traveler_id'] ?? 0) !== $travelerId
                || strtoupper((string) ($advance['currency'] ?? '')) !== $currency
                || ! in_array((string) ($advance['receipt_purpose'] ?? 'booking_payment'), ['customer_advance', 'global_payment', 'booking_payment'], true)
                || (float) ($advance['unallocated_amount'] ?? 0) <= 0
            ) {
                throw new RuntimeException('The selected customer advance is not available for this customer and currency.');
            }

            $receivables = $repository->openGlobalReceivablesForSettlement(
                $selectedReceivableIds,
                $branchId,
                $travelerId,
                $currency
            );
            if (count($receivables) !== count($selectedReceivableIds)) {
                throw new RuntimeException('One or more selected customer receivable rows are no longer available.');
            }

            $remainingAmount = round((float) ($advance['unallocated_amount'] ?? 0), 2);
            $allocatedAmount = 0.0;
            $allocationCount = 0;

            foreach ($receivables as $receivable) {
                if ($remainingAmount <= 0.005) {
                    break;
                }

                $receivableBalance = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
                $allocationAmount = round(min($remainingAmount, $receivableBalance), 2);
                if ($allocationAmount <= 0.005) {
                    continue;
                }

                $allocationResult = $repository->allocateReceiptExplicit([
                    'receipt_id' => $advanceReceiptId,
                    'receivable_item_id' => (int) $receivable['id'],
                    'receivable_amount_to_settle' => $allocationAmount,
                    'payment_currency' => $currency,
                    'rate_from_currency' => $currency,
                    'rate_to_currency' => $currency,
                    'exchange_rate' => 1.0,
                    'exchange_rate_effective_date' => date('Y-m-d'),
                    'allocation_note' => 'Customer advance applied to receivable',
                    'actor_user_id' => $actorUserId,
                ]);
                $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
                $allocatedReceiptAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
                if ($allocationId <= 0 || $allocatedReceiptAmount <= 0) {
                    throw new RuntimeException('Customer advance allocation could not be completed.');
                }

                $accountingRepository->postCustomerReceiptAllocation([
                    'branch_id' => (int) ($receivable['branch_id'] ?? $branchId),
                    'booking_reference' => (string) ($receivable['booking_reference'] ?? ''),
                    'source_reference' => (string) ($advance['receipt_no'] ?? 'ADVANCE') . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                    'customer_receivable_item_id' => (int) $receivable['id'],
                    'customer_receipt_id' => $advanceReceiptId,
                    'allocated_amount' => $allocatedReceiptAmount,
                    'entry_date' => date('Y-m-d'),
                    'currency' => $currency,
                    'actor_user_id' => $actorUserId,
                    'narration' => 'Customer advance applied to invoice',
                ]);

                $remainingAmount = round($remainingAmount - $allocatedReceiptAmount, 2);
                $allocatedAmount = round($allocatedAmount + $allocatedReceiptAmount, 2);
                $allocationCount++;
            }

            if ($allocationCount <= 0) {
                throw new RuntimeException('No customer advance amount could be applied to the selected receivables.');
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_no' => (string) ($advance['receipt_no'] ?? ''),
                'customer_name' => $repository->customerNameByTraveler($travelerId),
                'currency' => $currency,
                'allocated_amount' => $allocatedAmount,
                'allocation_count' => $allocationCount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer advance could not be applied.', 0, $exception);
        }
    }

    public function refundCustomerAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }

        $currency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? 'PKR'));
        $paymentMethod = $this->normalizeMethod((string) ($input['advance_refund_method'] ?? 'cash'));
        $payload = [
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'treasury_account_id' => max(0, (int) ($input['advance_refund_treasury_account_id'] ?? 0)) ?: null,
        ];
        $payload['treasury_account_id'] = $this->resolveReceiptTreasuryAccountId([
            'treasury_account_id' => $payload['treasury_account_id'],
        ], $payload, $branchId);

        $amount = $this->nonNegativeMoneyValue($input['advance_refund_amount'] ?? 0, 'Advance refund amount');
        if ($amount <= 0) {
            throw new RuntimeException('Advance refund amount must be greater than zero.');
        }
        $reason = $this->optionalText($input['advance_refund_reason'] ?? null, 1000);
        if ($reason === null || trim($reason) === '') {
            throw new RuntimeException('Please enter a reason for refunding the customer advance.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $refund = $repository->refundCustomerAdvance([
                'customer_receipt_id' => (int) ($input['advance_refund_receipt_id'] ?? 0),
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'refund_date' => $this->normalizeDate((string) ($input['advance_refund_date'] ?? ''), 'Refund date'),
                'currency' => $currency,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $payload['treasury_account_id'],
                'reference_number' => $this->optionalText($input['advance_refund_reference_number'] ?? null, 100),
                'reason' => $reason,
                'remarks' => $this->optionalText($input['advance_refund_remarks'] ?? null, 4000),
                'actor_user_id' => $actorUserId,
            ]);

            $journalEntryId = $accountingRepository->postCustomerAdvanceRefund([
                'branch_id' => $branchId,
                'customer_receipt_id' => (int) $refund['receipt_id'],
                'source_reference' => (string) ($refund['receipt_no'] ?? 'ADVANCE') . '-REFUND-' . (int) $refund['refund_id'],
                'entry_date' => (string) $refund['refund_date'],
                'currency' => $currency,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $payload['treasury_account_id'],
                'actor_user_id' => $actorUserId,
                'narration' => 'Customer advance refund',
            ]);
            $repository->attachCustomerAdvanceRefundJournalEntry((int) $refund['refund_id'], $journalEntryId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_no' => (string) ($refund['receipt_no'] ?? ''),
                'customer_name' => $repository->customerNameByTraveler($travelerId),
                'currency' => $currency,
                'amount' => $amount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer advance refund could not be saved.', 0, $exception);
        }
    }

    public function correctCustomerAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        if ($receiptId <= 0) {
            throw new RuntimeException('Please select a valid customer advance entry to edit.');
        }

        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }

        $currency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? $input['currency'] ?? 'PKR'));
        $paymentMethod = $this->normalizeMethod((string) ($input['advance_method'] ?? $input['payment_method'] ?? 'cash'));
        $amount = $this->nonNegativeMoneyValue($input['advance_amount'] ?? $input['amount'] ?? 0, 'Advance amount');
        if ($amount <= 0) {
            throw new RuntimeException('Customer advance amount must be greater than zero.');
        }

        $reason = $this->optionalText($input['correction_reason'] ?? $input['reason'] ?? null, 1000);
        if ($reason === null || trim($reason) === '') {
            throw new RuntimeException('Please enter a reason for correcting the customer advance.');
        }

        $entryDate = $this->normalizeDate((string) ($input['advance_date'] ?? $input['entry_date'] ?? ''), 'Advance date');
        $treasuryPayload = [
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'treasury_account_id' => max(0, (int) ($input['treasury_account_id'] ?? $input['advance_treasury_account_id'] ?? 0)) ?: null,
        ];
        $treasuryAccountId = $this->resolveReceiptTreasuryAccountId([
            'treasury_account_id' => $treasuryPayload['treasury_account_id'],
        ], $treasuryPayload, $branchId);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $receipt = $repository->findReceiptById($receiptId);
            if ($receipt === null
                || (string) ($receipt['receipt_purpose'] ?? '') !== 'customer_advance'
                || (int) ($receipt['branch_id'] ?? 0) !== $branchId
                || (int) ($receipt['traveler_id'] ?? 0) !== $travelerId
                || strtoupper((string) ($receipt['currency'] ?? '')) !== $currency
            ) {
                throw new RuntimeException('The selected customer advance does not match this customer, branch, and currency.');
            }

            $oldJournalId = $repository->latestCustomerAdvanceReceiptJournalId($receiptId, (string) ($receipt['receipt_no'] ?? ''));
            $reversalJournalId = $oldJournalId !== null
                ? $accountingRepository->reverseJournalEntry($oldJournalId, [
                    'branch_id' => $branchId,
                    'booking_reference' => null,
                    'source_type' => 'customer_advance_correction_reversal',
                    'source_reference' => (string) ($receipt['receipt_no'] ?? 'ADV') . '-ADV-REV-' . date('YmdHis'),
                    'entry_date' => $entryDate,
                    'currency' => $currency,
                    'narration' => 'Customer advance correction reversal',
                    'actor_user_id' => $actorUserId,
                    'line_description_prefix' => 'Correction reversal: ',
                ])
                : null;

            $corrected = $repository->correctCustomerAdvanceReceipt([
                'customer_receipt_id' => $receiptId,
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'receipt_date' => $entryDate,
                'currency' => $currency,
                'received_amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'reference_number' => $this->optionalText($input['reference_number'] ?? $input['advance_reference_number'] ?? null, 100),
                'remarks' => $this->optionalText($input['remarks'] ?? $input['advance_remarks'] ?? null, 4000),
            ]);

            $newJournalId = $accountingRepository->postCustomerReceiptRecorded([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'customer_receipt_id' => $receiptId,
                'receipt_no' => (string) ($receipt['receipt_no'] ?? 'ADVANCE'),
                'source_reference' => (string) ($receipt['receipt_no'] ?? 'ADV') . '-ADV-CORR-' . date('YmdHis'),
                'received_amount' => $amount,
                'charges_amount' => 0.0,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'entry_date' => $entryDate,
                'currency' => $currency,
                'actor_user_id' => $actorUserId,
                'narration' => 'Customer advance corrected',
            ]);

            $old = (array) ($corrected['old'] ?? $receipt);
            $repository->recordCustomerAdvanceCorrection([
                'correction_type' => 'advance_received',
                'customer_receipt_id' => $receiptId,
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'old_entry_date' => (string) ($old['receipt_date'] ?? ''),
                'new_entry_date' => $entryDate,
                'currency' => $currency,
                'old_amount' => (float) ($old['received_amount'] ?? 0),
                'new_amount' => $amount,
                'old_payment_method' => (string) ($old['payment_method'] ?? ''),
                'new_payment_method' => $paymentMethod,
                'old_treasury_account_id' => isset($old['treasury_account_id']) ? (int) $old['treasury_account_id'] : null,
                'new_treasury_account_id' => $treasuryAccountId,
                'old_reference_number' => $old['reference_number'] ?? null,
                'new_reference_number' => $input['reference_number'] ?? $input['advance_reference_number'] ?? null,
                'old_remarks' => $old['remarks'] ?? null,
                'new_remarks' => $input['remarks'] ?? $input['advance_remarks'] ?? null,
                'reason' => $reason,
                'old_journal_entry_id' => $oldJournalId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'new_journal_entry_id' => $newJournalId,
                'created_by_user_id' => $actorUserId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
                'customer_name' => $repository->customerNameByTraveler($travelerId),
                'currency' => $currency,
                'amount' => $amount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer advance correction could not be saved.', 0, $exception);
        }
    }

    public function correctCustomerAdvanceRefund(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $refundId = (int) ($input['customer_advance_refund_id'] ?? 0);
        if ($refundId <= 0) {
            throw new RuntimeException('Please select a valid returned advance entry to edit.');
        }

        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }

        $currency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? $input['currency'] ?? 'PKR'));
        $paymentMethod = $this->normalizeMethod((string) ($input['advance_refund_method'] ?? $input['payment_method'] ?? 'cash'));
        $amount = $this->nonNegativeMoneyValue($input['advance_refund_amount'] ?? $input['amount'] ?? 0, 'Returned advance amount');
        if ($amount <= 0) {
            throw new RuntimeException('Returned advance amount must be greater than zero.');
        }

        $reason = $this->optionalText($input['correction_reason'] ?? $input['reason'] ?? null, 1000);
        if ($reason === null || trim($reason) === '') {
            throw new RuntimeException('Please enter a reason for correcting the returned advance.');
        }

        $entryDate = $this->normalizeDate((string) ($input['advance_refund_date'] ?? $input['entry_date'] ?? ''), 'Refund date');
        $treasuryPayload = [
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'treasury_account_id' => max(0, (int) ($input['treasury_account_id'] ?? $input['advance_refund_treasury_account_id'] ?? 0)) ?: null,
        ];
        $treasuryAccountId = $this->resolveReceiptTreasuryAccountId([
            'treasury_account_id' => $treasuryPayload['treasury_account_id'],
        ], $treasuryPayload, $branchId);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $refund = $repository->findCustomerAdvanceRefundById($refundId);
            if ($refund === null
                || (int) ($refund['branch_id'] ?? 0) !== $branchId
                || (int) ($refund['traveler_id'] ?? 0) !== $travelerId
                || strtoupper((string) ($refund['currency'] ?? '')) !== $currency
            ) {
                throw new RuntimeException('The selected returned advance does not match this customer, branch, and currency.');
            }

            $receiptId = (int) ($refund['customer_receipt_id'] ?? 0);
            $oldJournalId = $repository->latestCustomerAdvanceRefundJournalId($refundId);
            $reversalJournalId = $oldJournalId !== null
                ? $accountingRepository->reverseJournalEntry($oldJournalId, [
                    'branch_id' => $branchId,
                    'booking_reference' => null,
                    'source_type' => 'customer_advance_return_correction_reversal',
                    'source_reference' => (string) ($refund['receipt_no'] ?? 'ADV') . '-REF-REV-' . date('YmdHis'),
                    'entry_date' => $entryDate,
                    'currency' => $currency,
                    'narration' => 'Customer advance return correction reversal',
                    'actor_user_id' => $actorUserId,
                    'line_description_prefix' => 'Correction reversal: ',
                ])
                : null;

            $corrected = $repository->correctCustomerAdvanceRefund([
                'customer_advance_refund_id' => $refundId,
                'customer_receipt_id' => $receiptId,
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'refund_date' => $entryDate,
                'currency' => $currency,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'reference_number' => $this->optionalText($input['reference_number'] ?? $input['advance_refund_reference_number'] ?? null, 100),
                'reason' => $reason,
                'remarks' => $this->optionalText($input['remarks'] ?? $input['advance_refund_remarks'] ?? null, 4000),
            ]);

            $newJournalId = $accountingRepository->postCustomerAdvanceRefund([
                'branch_id' => $branchId,
                'customer_receipt_id' => $receiptId,
                'source_reference' => (string) ($refund['receipt_no'] ?? 'ADVANCE') . '-REFUND-CORR-' . $refundId . '-' . date('YmdHis'),
                'entry_date' => $entryDate,
                'currency' => $currency,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'actor_user_id' => $actorUserId,
                'narration' => 'Customer advance return corrected',
            ]);
            $repository->attachCustomerAdvanceRefundJournalEntry($refundId, $newJournalId);

            $old = (array) ($corrected['old'] ?? $refund);
            $repository->recordCustomerAdvanceCorrection([
                'correction_type' => 'advance_returned',
                'customer_receipt_id' => $receiptId,
                'customer_advance_refund_id' => $refundId,
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'old_entry_date' => (string) ($old['refund_date'] ?? ''),
                'new_entry_date' => $entryDate,
                'currency' => $currency,
                'old_amount' => (float) ($old['amount'] ?? 0),
                'new_amount' => $amount,
                'old_payment_method' => (string) ($old['payment_method'] ?? ''),
                'new_payment_method' => $paymentMethod,
                'old_treasury_account_id' => isset($old['treasury_account_id']) ? (int) $old['treasury_account_id'] : null,
                'new_treasury_account_id' => $treasuryAccountId,
                'old_reference_number' => $old['reference_number'] ?? null,
                'new_reference_number' => $input['reference_number'] ?? $input['advance_refund_reference_number'] ?? null,
                'old_remarks' => $old['remarks'] ?? null,
                'new_remarks' => $input['remarks'] ?? $input['advance_refund_remarks'] ?? null,
                'reason' => $reason,
                'old_journal_entry_id' => $oldJournalId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'new_journal_entry_id' => $newJournalId,
                'created_by_user_id' => $actorUserId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_no' => (string) ($refund['receipt_no'] ?? ''),
                'customer_name' => $repository->customerNameByTraveler($travelerId),
                'currency' => $currency,
                'amount' => $amount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer advance return correction could not be saved.', 0, $exception);
        }
    }

    private function assertNoPostedReceiptEditAttempt(array $input): void
    {
        $existingReceiptId = max(
            (int) ($input['customer_receipt_id'] ?? 0),
            (int) ($input['receipt_id'] ?? 0)
        );
        if ($existingReceiptId > 0) {
            $existingReceipt = (new CustomerPaymentRepository($this->app))->findReceiptById($existingReceiptId);
            if ($existingReceipt !== null) {
                throw new RuntimeException('Saved customer receipt financial values cannot be edited. Void the receipt and create a new one.');
            }
        }

        if (trim((string) ($input['receipt_no'] ?? '')) !== '') {
            throw new RuntimeException('Receipt number is system-generated. Saved customer receipt financial values cannot be edited here. Void the receipt and create a new one.');
        }
    }

    public function allocateReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot allocate receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $paymentRepository = new CustomerPaymentRepository($this->app);
        $receipt = $paymentRepository->findReceiptById($receiptId);
        if ($receipt === null || (string) $receipt['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected receipt does not belong to this booking.');
        }

        $allocationLines = $this->normalizedAllocationLines($input);
        if ($allocationLines === []) {
            throw new RuntimeException('Enter at least one allocation amount greater than zero.');
        }

        $accountingRepository = new AccountingRepository($this->app);
        $allocationCount = 0;
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            foreach ($allocationLines as $line) {
                $receivable = $paymentRepository->findReceivableById((int) $line['receivable_id']);
                if ($receivable === null || (string) $receivable['booking_reference'] !== (string) $booking['booking_reference']) {
                    throw new RuntimeException('One of the selected due items does not belong to this booking.');
                }

                $allocationPayload = $this->buildExplicitAllocationPayload($receipt, $receivable, $line);
                $allocationPayload['receipt_id'] = $receiptId;
                $allocationPayload['receivable_item_id'] = (int) $line['receivable_id'];
                $allocationPayload['receivable_amount_to_settle'] = (float) $line['amount'];
                $allocationPayload['allocation_note'] = $line['note'];
                $allocationPayload['actor_user_id'] = $actorUserId;

                $allocationResult = $paymentRepository->allocateReceiptExplicit($allocationPayload);
                $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
                $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
                if ($allocationId <= 0 || $allocatedAmount <= 0) {
                    throw new RuntimeException('Receipt allocation could not be completed.');
                }

                $accountingRepository->postCustomerReceiptAllocation([
                    'branch_id' => (int) $booking['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'source_reference' => (string) $receipt['receipt_no'] . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                    'customer_receivable_item_id' => (int) $line['receivable_id'],
                    'customer_receipt_id' => $receiptId,
                    'allocated_amount' => $allocatedAmount,
                    'entry_date' => (string) $receipt['receipt_date'],
                    'currency' => (string) ($receivable['currency'] ?? $receipt['currency']),
                    'actor_user_id' => $actorUserId,
                ]);

                $allocationCount++;
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => $bookingId,
                'allocation_count' => $allocationCount,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Receipt allocation could not be saved.', 0, $exception);
        }
    }

    public function voidReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can void customer receipts.');

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        $voidReason = $this->requiredVoidReason($input['void_reason'] ?? null);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot void receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        if ($receiptId <= 0) {
            throw new RuntimeException('Select a valid receipt to void.');
        }

        $paymentRepository = new CustomerPaymentRepository($this->app);
        $receipt = $paymentRepository->findReceiptById($receiptId);
        if ($receipt === null) {
            throw new RuntimeException('The selected receipt could not be found.');
        }

        if (! in_array((int) ($receipt['branch_id'] ?? 0), $accessibleBranchIds, true)) {
            throw new RuntimeException('You cannot void a receipt outside your accessible branches.');
        }

        if ((string) ($receipt['booking_reference'] ?? '') !== (string) ($booking['booking_reference'] ?? '')) {
            throw new RuntimeException('The selected receipt does not belong to this booking.');
        }

        $receiptStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($receipt['status'] ?? ''))));
        if ($receiptStatus === 'void') {
            throw new RuntimeException('This receipt is already void.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $reversalReference = 'VOID-' . (string) ($receipt['receipt_no'] ?? ('R-' . $receiptId));
            $voidResult = $paymentRepository->voidReceipt(
                $receiptId,
                $voidReason,
                $actorUserId,
                $reversalReference,
                null
            );

            $accountingRepository = new AccountingRepository($this->app);
            $reversalJournalEntryId = $accountingRepository->postCustomerReceiptVoidReversal([
                'branch_id' => (int) ($voidResult['branch_id'] ?? (int) ($booking['branch_id'] ?? 0)),
                'booking_reference' => (string) ($voidResult['booking_reference'] ?? (string) ($booking['booking_reference'] ?? '')),
                'customer_receipt_id' => $receiptId,
                'source_reference' => $reversalReference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) ($voidResult['currency'] ?? (string) ($receipt['currency'] ?? 'PKR')),
                'narration' => 'Customer receipt void reversal for ' . (string) ($voidResult['receipt_no'] ?? $receiptId),
                'actor_user_id' => $actorUserId,
            ]);

            if ($reversalJournalEntryId !== null && $reversalJournalEntryId > 0) {
                $paymentRepository->attachReceiptReversalJournalEntry($receiptId, $reversalJournalEntryId);
            }

            AuditLog::record($this->app, 'customer.receipt.voided', [
                'user_id' => $actorUserId,
                'customer_receipt_id' => $receiptId,
                'receipt_no' => (string) ($voidResult['receipt_no'] ?? ''),
                'booking_reference' => (string) ($voidResult['booking_reference'] ?? ''),
                'void_reason' => $voidReason,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'allocation_count_reversed' => (int) ($voidResult['allocation_count_reversed'] ?? 0),
                'total_receivable_amount_reversed' => (float) ($voidResult['total_receivable_amount_reversed'] ?? 0),
                'total_payment_amount_reversed' => (float) ($voidResult['total_payment_amount_reversed'] ?? 0),
                'affected_receivable_item_ids' => $voidResult['affected_receivable_item_ids'] ?? [],
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => $bookingId,
                'receipt_id' => $receiptId,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer receipt could not be voided.', 0, $exception);
        }
    }

    public function updateReceiptMetadata(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot update receipt notes for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        if ($receiptId <= 0) {
            throw new RuntimeException('Select a valid receipt to update.');
        }

        $repository = new CustomerPaymentRepository($this->app);
        $receipt = $repository->findReceiptById($receiptId);
        if ($receipt === null) {
            throw new RuntimeException('The selected receipt could not be found.');
        }

        if (! in_array((int) ($receipt['branch_id'] ?? 0), $accessibleBranchIds, true)) {
            throw new RuntimeException('You cannot update a receipt outside your accessible branches.');
        }

        if ((string) ($receipt['booking_reference'] ?? '') !== (string) ($booking['booking_reference'] ?? '')) {
            throw new RuntimeException('The selected receipt does not belong to this booking.');
        }

        $receiptStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($receipt['status'] ?? ''))));
        if ($receiptStatus === 'void') {
            throw new RuntimeException('Payment notes cannot be changed after the receipt has been voided.');
        }

        $referenceNumber = $this->optionalText($input['receipt_reference_number'] ?? null, 100);
        $bankCardDetail = $this->optionalText($input['receipt_bank_card_detail'] ?? null, 190);
        $remarks = $this->optionalText($input['receipt_remarks'] ?? null, 4000);

        $repository->updateReceiptMetadata($receiptId, [
            'reference_number' => $referenceNumber,
            'bank_card_detail' => $bankCardDetail,
            'remarks' => $remarks,
        ], $actorUserId);

        return [
            'receipt' => $repository->findReceiptById($receiptId),
            'booking_id' => $bookingId,
        ];
    }

    private function validatedReceiptPayload(array $input): array
    {
        $currency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? 'PKR'));
        $paymentMethod = $this->normalizeMethod((string) ($input['payment_method'] ?? 'cash'));
        $status = $this->normalizeStatus((string) ($input['receipt_status'] ?? 'received'));
        $receivedAmount = $this->nonNegativeMoneyValue($input['received_amount'] ?? 0, 'Received amount');
        $chargesAmount = $this->nonNegativeMoneyValue($input['charges_amount'] ?? 0, 'Charges');
        $exchangeRate = $this->normalizeOptionalExchangeRate((string) ($input['exchange_rate_to_booking'] ?? ''));

        return [
            'receipt_date' => $this->normalizeDate((string) ($input['receipt_date'] ?? ''), 'Receipt date'),
            'currency' => $currency,
            'received_amount' => $receivedAmount,
            'due_date' => $this->normalizeOptionalDate((string) ($input['due_date'] ?? '')),
            'payment_method' => $paymentMethod,
            'treasury_account_id' => max(0, (int) ($input['treasury_account_id'] ?? 0)) ?: null,
            'reference_number' => $this->optionalText($input['reference_number'] ?? null, 100),
            'bank_card_detail' => $this->optionalText($input['bank_card_detail'] ?? null, 190),
            'charges_amount' => $chargesAmount,
            'status' => $status,
            'exchange_rate_to_booking' => $exchangeRate,
            'remarks' => $this->optionalText($input['receipt_remarks'] ?? null, 4000),
        ];
    }

    private function shouldPostReceiptAccounting(array $payload): bool
    {
        $receivedAmount = round((float) ($payload['received_amount'] ?? 0), 2);
        $chargesAmount = round((float) ($payload['charges_amount'] ?? 0), 2);

        return ($receivedAmount + $chargesAmount) > 0;
    }

    private function applyCustomerAdvanceToCurrentInvoice(
        array $input,
        array $booking,
        string $currency,
        string $entryDate,
        int $actorUserId,
        CustomerPaymentRepository $paymentRepository,
        AccountingRepository $accountingRepository
    ): array {
        $advanceReceiptId = max(0, (int) ($input['advance_receipt_id'] ?? 0));
        $requestedAmount = $this->nonNegativeMoneyValue($input['advance_apply_amount'] ?? 0, 'Customer advance used');

        if ($advanceReceiptId <= 0 || $requestedAmount <= 0.005) {
            return [
                'receipt_id' => $advanceReceiptId,
                'allocated_amount' => 0.00,
                'allocation_count' => 0,
                'allocation_ids' => [],
            ];
        }

        $bookingReference = (string) ($booking['booking_reference'] ?? '');
        $branchId = (int) ($booking['branch_id'] ?? 0);
        $travelerId = (int) ($booking['lead_traveler_id'] ?? 0);
        $receiptCurrency = $this->normalizeCurrency($currency);
        $advanceReceipt = $paymentRepository->findReceiptById($advanceReceiptId);

        if ($advanceReceipt === null) {
            throw new RuntimeException('The selected customer advance could not be found.');
        }

        if ((int) ($advanceReceipt['branch_id'] ?? 0) !== $branchId
            || (int) ($advanceReceipt['traveler_id'] ?? 0) !== $travelerId
            || strtoupper((string) ($advanceReceipt['currency'] ?? '')) !== $receiptCurrency
        ) {
            throw new RuntimeException('The selected customer advance does not match this booking customer, branch, and currency.');
        }

        $availableAdvance = round((float) ($advanceReceipt['unallocated_amount'] ?? 0), 2);
        if ($availableAdvance <= 0.005) {
            throw new RuntimeException('The selected customer advance has no available balance.');
        }

        if ($requestedAmount > $availableAdvance + 0.005) {
            throw new RuntimeException('Customer advance used cannot exceed the available advance balance.');
        }

        $receiptScope = $this->normalizeReceiptScope((string) ($input['receipt_scope'] ?? 'whole_invoice'));
        $targetReceivableId = $this->resolveReceiptAllocationTargetId(
            $input,
            $paymentRepository,
            $booking,
            $receiptCurrency,
            $receiptScope
        );

        $openReceivables = $this->sameCurrencyBookingReceivables(
            $paymentRepository,
            $booking,
            $receiptCurrency,
            [],
            $targetReceivableId !== null ? [$targetReceivableId] : null
        );

        $openTotal = round(array_sum(array_map(
            static fn (array $receivable): float => max(0.0, (float) ($receivable['outstanding_amount'] ?? 0)),
            $openReceivables
        )), 2);
        if ($openTotal <= 0.005) {
            throw new RuntimeException('There is no open current invoice balance for this customer advance.');
        }

        $remainingToApply = round(min($requestedAmount, $openTotal), 2);
        $allocatedTotal = 0.00;
        $allocationIds = [];

        app_write_log('workspace.receipt.advance_apply_started', 'Customer advance allocation to current invoice started.', array_merge(app_request_log_context(), [
            'booking_id' => (int) ($booking['id'] ?? 0),
            'booking_reference' => $bookingReference,
            'advance_receipt_id' => $advanceReceiptId,
            'currency' => $receiptCurrency,
            'requested_amount' => $requestedAmount,
            'available_advance' => $availableAdvance,
            'current_invoice_open_total' => $openTotal,
            'receipt_scope' => $receiptScope,
            'target_receivable_id' => $targetReceivableId,
        ]));

        foreach ($openReceivables as $receivable) {
            if ($remainingToApply <= 0.005) {
                break;
            }

            $advanceReceipt = $paymentRepository->findReceiptById($advanceReceiptId);
            if ($advanceReceipt === null) {
                throw new RuntimeException('The selected customer advance could not be reloaded.');
            }

            $remainingAdvance = round((float) ($advanceReceipt['unallocated_amount'] ?? 0), 2);
            $remainingReceivable = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
            $allocationAmount = round(min($remainingToApply, $remainingAdvance, $remainingReceivable), 2);
            if ($allocationAmount <= 0.005) {
                continue;
            }

            $allocationResult = $paymentRepository->allocateReceiptExplicit([
                'receipt_id' => $advanceReceiptId,
                'receivable_item_id' => (int) $receivable['id'],
                'receivable_amount_to_settle' => $allocationAmount,
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $receiptCurrency,
                'rate_to_currency' => $receiptCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $entryDate,
                'allocation_note' => $receiptScope === 'passenger_specific'
                    ? 'Customer advance applied to selected passenger due.'
                    : 'Customer advance applied to current invoice.',
                'actor_user_id' => $actorUserId,
            ]);

            $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
            $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
            if ($allocationId <= 0 || $allocatedAmount <= 0.005) {
                throw new RuntimeException('Customer advance could not be applied to the invoice.');
            }

            $accountingRepository->postCustomerReceiptAllocation([
                'branch_id' => (int) ($receivable['branch_id'] ?? $branchId),
                'booking_reference' => (string) ($receivable['booking_reference'] ?? $bookingReference),
                'source_reference' => (string) ($advanceReceipt['receipt_no'] ?? 'ADVANCE') . '-ADV-ALLOC-' . $allocationId,
                'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                'customer_receivable_item_id' => (int) $receivable['id'],
                'customer_receipt_id' => $advanceReceiptId,
                'allocated_amount' => $allocatedAmount,
                'entry_date' => $entryDate,
                'currency' => $receiptCurrency,
                'narration' => 'Customer advance applied to invoice',
                'actor_user_id' => $actorUserId,
            ]);

            $remainingToApply = round(max(0, $remainingToApply - $allocatedAmount), 2);
            $allocatedTotal = round($allocatedTotal + $allocatedAmount, 2);
            $allocationIds[] = $allocationId;
        }

        app_write_log('workspace.receipt.advance_apply_posted', 'Customer advance allocation to current invoice completed.', array_merge(app_request_log_context(), [
            'booking_id' => (int) ($booking['id'] ?? 0),
            'booking_reference' => $bookingReference,
            'advance_receipt_id' => $advanceReceiptId,
            'currency' => $receiptCurrency,
            'allocated_amount' => $allocatedTotal,
            'allocation_count' => count($allocationIds),
            'allocation_ids' => $allocationIds,
        ]));

        return [
            'receipt_id' => $advanceReceiptId,
            'allocated_amount' => $allocatedTotal,
            'allocation_count' => count($allocationIds),
            'allocation_ids' => $allocationIds,
        ];
    }

    private function normalizeReceiptScope(string $value): string
    {
        $scope = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($scope, self::ALLOWED_RECEIPT_SCOPES, true)) {
            throw new RuntimeException('Please select a valid payment allocation scope.');
        }

        return $scope;
    }

    private function resolveReceiptAllocationTargetId(
        array $input,
        CustomerPaymentRepository $paymentRepository,
        array $booking,
        string $receiptCurrency,
        string $receiptScope
    ): ?int {
        if ($receiptScope !== 'passenger_specific') {
            return null;
        }

        $targetReceivableId = max(0, (int) ($input['target_receivable_item_id'] ?? 0));
        if ($targetReceivableId <= 0) {
            throw new RuntimeException('Please select the passenger/service due row for this payment.');
        }

        $eligibleReceivables = $this->sameCurrencyBookingReceivables(
            $paymentRepository,
            $booking,
            $receiptCurrency,
            []
        );

        foreach ($eligibleReceivables as $receivable) {
            if ((int) ($receivable['id'] ?? 0) === $targetReceivableId) {
                return $targetReceivableId;
            }
        }

        throw new RuntimeException('The selected passenger receivable is no longer available for this invoice/currency.');
    }

    private function resolveReceiptTreasuryAccountId(array $input, array $payload, int $branchId): ?int
    {
        $paymentMethod = (string) ($payload['payment_method'] ?? '');
        if (! $this->paymentMethodRequiresTreasuryAccount($paymentMethod)) {
            return null;
        }

        $treasuryRepository = new TreasuryRepository($this->app);
        $currency = (string) ($payload['currency'] ?? 'PKR');
        $submittedTreasuryAccountId = max(
            0,
            (int) ($input['treasury_account_id'] ?? 0),
            (int) ($payload['treasury_account_id'] ?? 0)
        );

        if ($submittedTreasuryAccountId > 0) {
            return (int) ($treasuryRepository->validatePaymentTreasuryAccount(
                $submittedTreasuryAccountId,
                $branchId,
                $currency,
                $paymentMethod
            )['id'] ?? 0);
        }

        $defaultAccount = $treasuryRepository->defaultTreasuryAccountForPayment($branchId, $currency, $paymentMethod);
        if ($defaultAccount !== null) {
            return (int) ($defaultAccount['id'] ?? 0);
        }

        $eligibleAccounts = $treasuryRepository->eligiblePaymentTreasuryAccounts($branchId, $currency, $paymentMethod);
        if ($eligibleAccounts === []) {
            throw new RuntimeException('Please configure/select a cash or bank account for this payment.');
        }

        if ($paymentMethod === 'cash') {
            return (int) ($eligibleAccounts[0]['id'] ?? 0);
        }

        throw new RuntimeException('Please configure/select a cash or bank account for this payment.');
    }

    private function resolveAccessibleBranchId(int $branchId, array $accessibleBranchIds): int
    {
        if ($branchId <= 0 || ! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch.');
        }

        return $branchId;
    }

    private function normalizedSelectedReceivableIds(mixed $value): array
    {
        $rawIds = is_array($value) ? $value : [$value];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn (int $id): bool => $id > 0
        )));
        sort($ids);

        return $ids;
    }

    private function paymentMethodRequiresTreasuryAccount(string $paymentMethod): bool
    {
        return in_array($paymentMethod, ['cash', 'bank_transfer'], true);
    }

    private function isDirectSupplierPaymentMethod(string $paymentMethod): bool
    {
        return $paymentMethod === self::DIRECT_SUPPLIER_PAYMENT_METHOD;
    }

    private function resolveDirectSupplierObligationId(
        array $input,
        SupplierRepository $supplierRepository,
        string $bookingReference,
        string $currency
    ): int {
        $postedValue = trim((string) ($input['direct_supplier_obligation_id'] ?? ''));
        if (ctype_digit($postedValue) && (int) $postedValue > 0) {
            return (int) $postedValue;
        }

        $currency = strtoupper(trim($currency));
        $serviceLineReference = trim((string) ($input['direct_supplier_service_line_reference'] ?? ''));
        $openObligations = $supplierRepository->openObligationsForBooking($bookingReference);
        $sameCurrency = array_values(array_filter(
            $openObligations,
            static function (array $row) use ($currency): bool {
                return strtoupper(trim((string) ($row['currency'] ?? ''))) === $currency
                    && round((float) ($row['net_payable_amount'] ?? 0), 2) > 0.005;
            }
        ));

        if ($serviceLineReference !== '') {
            $sameService = array_values(array_filter(
                $sameCurrency,
                static function (array $row) use ($serviceLineReference): bool {
                    return trim((string) ($row['service_line_reference'] ?? '')) === $serviceLineReference;
                }
            ));
            if (count($sameService) === 1) {
                return (int) ($sameService[0]['id'] ?? 0);
            }
        }

        if (count($sameCurrency) === 1) {
            return (int) ($sameCurrency[0]['id'] ?? 0);
        }

        app_write_log('workspace.receipt.direct_supplier_obligation_unresolved', 'Direct supplier payment could not resolve a unique payable.', [
            'booking_reference' => $bookingReference,
            'currency' => $currency,
            'service_line_reference' => $serviceLineReference,
            'posted_value' => $postedValue,
            'candidate_count' => count($sameCurrency),
            'candidate_ids' => json_encode(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $sameCurrency)),
        ]);

        throw new RuntimeException('Please select the supplier payable that the customer paid directly.');
    }

    private function normalizedAllocationLines(array $input): array
    {
        $receivableIds = $input['allocation_receivable_id'] ?? [];
        $amounts = $input['allocation_amount'] ?? [];
        $notes = $input['allocation_note'] ?? [];
        $rates = $input['allocation_exchange_rate'] ?? [];
        $currencies = $input['allocation_receivable_currency'] ?? [];
        $rateFromCurrencies = $input['allocation_rate_from_currency'] ?? [];
        $rateToCurrencies = $input['allocation_rate_to_currency'] ?? [];
        $rateDates = $input['allocation_exchange_rate_effective_date'] ?? [];

        if (! is_array($receivableIds) || ! is_array($amounts)) {
            return [];
        }

        $lines = [];
        foreach ($receivableIds as $index => $receivableId) {
            $amount = is_array($amounts) ? ($amounts[$index] ?? null) : null;
            if (! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $lines[] = [
                'receivable_id' => (int) $receivableId,
                'amount' => round((float) $amount, 2),
                'note' => $this->optionalText($notes[$index] ?? null, 190),
                'exchange_rate' => $this->normalizeOptionalExchangeRate((string) ($rates[$index] ?? '')),
                'receivable_currency' => is_array($currencies) ? (string) ($currencies[$index] ?? '') : '',
                'rate_from_currency' => is_array($rateFromCurrencies) ? strtoupper(trim((string) ($rateFromCurrencies[$index] ?? ''))) : '',
                'rate_to_currency' => is_array($rateToCurrencies) ? strtoupper(trim((string) ($rateToCurrencies[$index] ?? ''))) : '',
                'exchange_rate_effective_date' => is_array($rateDates) ? $this->normalizeOptionalDate((string) ($rateDates[$index] ?? '')) : null,
            ];
        }

        return $lines;
    }

    private function saveExchangeSettlement(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot record receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $payload = $this->validatedReceiptPayload($input);
        $payload['treasury_account_id'] = $this->resolveReceiptTreasuryAccountId(
            $input,
            $payload,
            (int) $booking['branch_id']
        );
        if ($payload['status'] === 'void') {
            $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can create a void customer receipt.');
        }

        $settlement = $this->validatedExchangeSettlementPayload($input, $payload);
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $exchangeRateRepository = new ExchangeRateRepository($this->app);
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $targetReceivable = $this->findAccessibleSettlementTarget(
                $repository,
                $booking,
                $accessibleBranchIds,
                $settlement['target_receivable_id']
            );

            if ($payload['currency'] !== $settlement['payment_currency']) {
                throw new RuntimeException('PAYMENT_CURRENCY_MISMATCH: Payment currency does not match the settlement request.');
            }

            if ($settlement['payment_currency'] !== $settlement['target_currency']) {
                if ($settlement['exchange_rate'] === null) {
                    $exactRate = $exchangeRateRepository->getExactRate(
                        $settlement['rate_from_currency'],
                        $settlement['rate_to_currency'],
                        $settlement['exchange_rate_effective_date']
                    );
                    if ($exactRate === null) {
                        throw new RuntimeException('FX_RATE_EXPIRED_OR_MISSING: Today\'s exchange rate is required.');
                    }

                    $settlement['exchange_rate'] = (float) ($exactRate['exchange_rate'] ?? 0);
                }

                $exchangeRateRepository->upsertDailyRate(
                    $settlement['rate_from_currency'],
                    $settlement['rate_to_currency'],
                    $settlement['exchange_rate_effective_date'],
                    $settlement['exchange_rate'],
                    (int) $booking['branch_id'],
                    $actorUserId
                );
            } else {
                $settlement['exchange_rate'] = 1.0;
            }

            $originalReceivedAmount = (float) ($payload['received_amount'] ?? 0);
            $payload['received_amount'] = round($originalReceivedAmount, 2);
            $payload['tendered_amount'] = $originalReceivedAmount;
            $payload['returned_amount'] = 0.00;

            $receiptNo = $repository->nextReceiptNumber();
            $receiptId = $repository->createReceipt(array_merge($payload, [
                'branch_id' => (int) $booking['branch_id'],
                'traveler_id' => (int) ($booking['lead_traveler_id'] ?? 0) ?: null,
                'receipt_purpose' => 'booking_payment',
                'booking_reference' => (string) $booking['booking_reference'],
                'receipt_no' => $receiptNo,
                'actor_user_id' => $actorUserId,
            ]));

            if ($payload['status'] !== 'void') {
                if ($this->shouldPostReceiptAccounting($payload)) {
                    $accountingRepository->postCustomerReceiptRecorded([
                        'branch_id' => (int) $booking['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'customer_receipt_id' => $receiptId,
                        'receipt_no' => $receiptNo,
                        'received_amount' => $payload['received_amount'],
                        'charges_amount' => $payload['charges_amount'],
                        'payment_method' => $payload['payment_method'],
                        'treasury_account_id' => $payload['treasury_account_id'],
                        'entry_date' => $payload['receipt_date'],
                        'currency' => $payload['currency'],
                        'actor_user_id' => $actorUserId,
                    ]);
                }

                $targetAllocation = $repository->allocateReceiptExplicit([
                    'receipt_id' => $receiptId,
                    'receivable_item_id' => (int) $targetReceivable['id'],
                    'receivable_amount_to_settle' => $settlement['target_receivable_amount_to_settle'],
                    'payment_amount_to_consume' => $settlement['target_payment_amount_to_consume'],
                    'payment_currency' => $settlement['payment_currency'],
                    'rate_from_currency' => $settlement['rate_from_currency'],
                    'rate_to_currency' => $settlement['rate_to_currency'],
                    'exchange_rate' => $settlement['exchange_rate'],
                    'exchange_rate_effective_date' => $settlement['exchange_rate_effective_date'],
                    'allocation_note' => 'Exchange settlement target allocation.',
                    'actor_user_id' => $actorUserId,
                ]);
                $allocationId = (int) ($targetAllocation['allocation_id'] ?? 0);
                $allocatedAmount = round((float) ($targetAllocation['allocated_amount'] ?? 0), 2);
                if ($allocationId <= 0 || $allocatedAmount <= 0) {
                    throw new RuntimeException('Exchange settlement target could not be allocated.');
                }

                $accountingRepository->postCustomerReceiptAllocation([
                    'branch_id' => (int) ($targetReceivable['branch_id'] ?? $booking['branch_id']),
                    'booking_reference' => (string) ($targetReceivable['booking_reference'] ?? $booking['booking_reference']),
                    'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($targetReceivable['service_line_reference'] ?? '') !== '' ? (string) $targetReceivable['service_line_reference'] : null,
                    'customer_receivable_item_id' => (int) $targetReceivable['id'],
                    'customer_receipt_id' => $receiptId,
                    'allocated_amount' => $allocatedAmount,
                    'entry_date' => $payload['receipt_date'],
                    'currency' => (string) ($targetReceivable['currency'] ?? $payload['currency']),
                    'actor_user_id' => $actorUserId,
                ]);

                $this->autoAllocateCurrentCurrencyReceipt(
                    $receiptId,
                    $receiptNo,
                    $booking,
                    $accessibleBranchIds,
                    $payload['currency'],
                    $payload['receipt_date'],
                    $actorUserId,
                    $repository,
                    $accountingRepository,
                    [(int) $targetReceivable['id']]
                );
            }

            if ($payload['due_date'] !== null) {
                $bookingRepository->updateBookingDueDate($bookingId, $payload['due_date'], $actorUserId);
                $repository->updateOpenReceivableDueDates(
                    (string) $booking['booking_reference'],
                    $payload['due_date'],
                    $actorUserId
                );
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $repository->findReceiptById($receiptId),
                'booking_id' => $bookingId,
                'returned_amount' => (float) $payload['returned_amount'],
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Exchange settlement could not be saved.', 0, $exception);
        }
    }

    private function validatedExchangeSettlementPayload(array $input, array $receiptPayload): array
    {
        $targetReceivableId = (int) ($input['settlement_target_receivable_id'] ?? 0);
        if ($targetReceivableId <= 0) {
            throw new RuntimeException('Please choose a settlement target.');
        }

        $paymentCurrency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? 'PKR'));
        $targetCurrency = $this->normalizeCurrency((string) ($input['settlement_target_currency'] ?? 'PKR'));
        $rateDate = $this->normalizeDate(
            (string) ($input['settlement_exchange_rate_effective_date'] ?? $receiptPayload['receipt_date']),
            'Settlement rate date'
        );

        $rateFromCurrency = strtoupper(trim((string) ($input['settlement_rate_from_currency'] ?? '')));
        $rateToCurrency = strtoupper(trim((string) ($input['settlement_rate_to_currency'] ?? '')));
        $exchangeRate = $this->normalizeOptionalExchangeRate((string) ($input['settlement_exchange_rate'] ?? ''));
        $targetReceivableAmount = $this->moneyValue(
            $input['settlement_target_receivable_amount'] ?? 0,
            'Settlement target amount'
        );
        $targetPaymentAmount = $this->moneyValue(
            $input['settlement_target_payment_amount'] ?? 0,
            'Settlement payment amount'
        );

        if ($paymentCurrency === $targetCurrency) {
            $rateFromCurrency = $targetCurrency;
            $rateToCurrency = $paymentCurrency;
            $exchangeRate = 1.0;
        } elseif ($rateFromCurrency === '' || $rateToCurrency === '') {
            throw new RuntimeException('CROSS_CURRENCY_REQUIRES_EXPLICIT_ALLOCATION: Settlement target and exchange rate are required.');
        }

        return [
            'target_receivable_id' => $targetReceivableId,
            'target_currency' => $targetCurrency,
            'payment_currency' => $paymentCurrency,
            'target_receivable_amount_to_settle' => $targetReceivableAmount,
            'target_payment_amount_to_consume' => $targetPaymentAmount,
            'rate_from_currency' => $rateFromCurrency,
            'rate_to_currency' => $rateToCurrency,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_effective_date' => $rateDate,
        ];
    }

    private function validatedSettlementRatePayload(array $input, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $branchId = (int) ($input['branch_id'] ?? 0);

        if ($branchId <= 0 && $bookingId > 0) {
            $booking = (new BookingRepository($this->app))->findBookingById($bookingId);
            $branchId = (int) ($booking['branch_id'] ?? 0);
        }

        if ($branchId <= 0 || ! in_array($branchId, $accessibleBranchIds, true)) {
            throw new RuntimeException('Please select a valid branch before saving today\'s exchange rate.');
        }

        $rateDate = $this->normalizeDate(
            (string) ($input['settlement_exchange_rate_effective_date'] ?? date('Y-m-d')),
            'Settlement rate date'
        );
        $rateFromCurrency = $this->normalizeCurrency((string) ($input['settlement_rate_from_currency'] ?? ''));
        $rateToCurrency = $this->normalizeCurrency((string) ($input['settlement_rate_to_currency'] ?? ''));

        if ($rateFromCurrency === $rateToCurrency) {
            throw new RuntimeException('Today\'s exchange rate is only needed when the currencies are different.');
        }

        $exchangeRate = $this->normalizeOptionalExchangeRate((string) ($input['settlement_exchange_rate'] ?? ''));
        if ($exchangeRate === null) {
            throw new RuntimeException('Today\'s exchange rate is required.');
        }

        return [
            'branch_id' => $branchId,
            'rate_from_currency' => $rateFromCurrency,
            'rate_to_currency' => $rateToCurrency,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_effective_date' => $rateDate,
        ];
    }

    private function findAccessibleSettlementTarget(
        CustomerPaymentRepository $repository,
        array $booking,
        array $accessibleBranchIds,
        int $targetReceivableId
    ): array {
        $leadTravelerId = (int) ($booking['lead_traveler_id'] ?? 0);
        if ($leadTravelerId <= 0) {
            throw new RuntimeException('The booking customer could not be resolved for settlement.');
        }

        $receivables = $repository->openReceivablesForLeadTraveler(
            $leadTravelerId,
            $accessibleBranchIds,
            [
                'booking_id' => (int) ($booking['id'] ?? 0),
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'booking_date' => (string) ($booking['booking_date'] ?? ''),
            ]
        );

        foreach ($receivables as $receivable) {
            if ((int) ($receivable['id'] ?? 0) === $targetReceivableId) {
                if ((string) ($receivable['booking_reference'] ?? '') !== (string) ($booking['booking_reference'] ?? '')) {
                    throw new RuntimeException('Phase 1 exchange settlement only supports the current invoice.');
                }

                return $receivable;
            }
        }

        throw new RuntimeException('The selected settlement target is not available for this customer.');
    }

    private function maxRetainableBookingReceiptAmount(
        CustomerPaymentRepository $paymentRepository,
        array $booking,
        array $accessibleBranchIds,
        string $receiptCurrency,
        array $skipReceivableIds = []
    ): float {
        $openReceivables = $this->sameCurrencyBookingReceivables(
            $paymentRepository,
            $booking,
            $receiptCurrency,
            $skipReceivableIds
        );

        return round(array_sum(array_map(
            static fn (array $receivable): float => max(0, round((float) ($receivable['outstanding_amount'] ?? 0), 2)),
            $openReceivables
        )), 2);
    }

    private function sameCurrencyBookingReceivables(
        CustomerPaymentRepository $paymentRepository,
        array $booking,
        string $receiptCurrency,
        array $skipReceivableIds = [],
        ?array $onlyReceivableIds = null
    ): array {
        $currentBookingReference = (string) ($booking['booking_reference'] ?? '');
        if ($currentBookingReference === '') {
            return [];
        }

        $currentBookingOpenReceivables = $paymentRepository->openReceivablesForBooking($currentBookingReference);
        $bookingReceivablesById = [];

        foreach ($currentBookingOpenReceivables as $receivable) {
            $receivableId = (int) ($receivable['id'] ?? 0);
            if ($receivableId <= 0 || in_array($receivableId, $skipReceivableIds, true)) {
                continue;
            }

            if (is_array($onlyReceivableIds) && $onlyReceivableIds !== [] && ! in_array($receivableId, $onlyReceivableIds, true)) {
                continue;
            }

            if ((string) ($receivable['currency'] ?? '') !== $receiptCurrency) {
                continue;
            }

            $bookingReceivablesById[$receivableId] = $receivable;
        }

        $bookingReceivables = array_values($bookingReceivablesById);
        usort($bookingReceivables, static function (array $left, array $right): int {
            $leftDueDate = (string) ($left['due_date'] ?? '');
            $rightDueDate = (string) ($right['due_date'] ?? '');

            if ($leftDueDate !== $rightDueDate) {
                if ($leftDueDate === '') {
                    return 1;
                }

                if ($rightDueDate === '') {
                    return -1;
                }

                return strcmp($leftDueDate, $rightDueDate);
            }

            return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
        });

        return $bookingReceivables;
    }

    private function autoAllocateCurrentCurrencyReceipt(
        int $receiptId,
        string $receiptNo,
        array $booking,
        array $accessibleBranchIds,
        string $receiptCurrency,
        string $receiptDate,
        int $actorUserId,
        CustomerPaymentRepository $paymentRepository,
        AccountingRepository $accountingRepository,
        array $skipReceivableIds = [],
        ?array $onlyReceivableIds = null,
        string $allocationNote = 'Automatically applied to current invoice.'
    ): void {
        $openReceivables = $this->sameCurrencyBookingReceivables(
            $paymentRepository,
            $booking,
            $receiptCurrency,
            $skipReceivableIds,
            $onlyReceivableIds
        );

        app_write_log('workspace.receipt.auto_allocate_candidates', 'Customer receipt current-invoice allocation candidates loaded.', array_merge(app_request_log_context(), [
            'booking_id' => (int) ($booking['id'] ?? 0),
            'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'receipt_id' => $receiptId,
            'receipt_no' => $receiptNo,
            'currency' => $receiptCurrency,
            'candidate_count' => count($openReceivables),
            'candidate_ids' => array_values(array_map(
                static fn (array $receivable): int => (int) ($receivable['id'] ?? 0),
                $openReceivables
            )),
            'candidate_total' => round(array_sum(array_map(
                static fn (array $receivable): float => max(0.0, (float) ($receivable['outstanding_amount'] ?? 0)),
                $openReceivables
            )), 2),
        ]));

        foreach ($openReceivables as $receivable) {
            $receipt = $paymentRepository->findReceiptById($receiptId);
            if ($receipt === null) {
                throw new RuntimeException('The saved receipt could not be reloaded for auto allocation.');
            }

            $remainingReceiptAmount = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
            $remainingReceivableAmount = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
            if ($remainingReceiptAmount <= 0.005 || $remainingReceivableAmount <= 0.005) {
                app_write_log('workspace.receipt.auto_allocate_skipped', 'Customer receipt allocation candidate skipped because receipt or receivable has no remaining balance.', array_merge(app_request_log_context(), [
                    'booking_id' => (int) ($booking['id'] ?? 0),
                    'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                    'receipt_id' => $receiptId,
                    'receipt_no' => $receiptNo,
                    'receivable_id' => (int) ($receivable['id'] ?? 0),
                    'remaining_receipt_amount' => $remainingReceiptAmount,
                    'remaining_receivable_amount' => $remainingReceivableAmount,
                ]));
                continue;
            }

            $allocationAmount = round(min($remainingReceiptAmount, $remainingReceivableAmount), 2);
            if ($allocationAmount <= 0.005) {
                continue;
            }

            $allocationResult = $paymentRepository->allocateReceiptExplicit([
                'receipt_id' => $receiptId,
                'receivable_item_id' => (int) $receivable['id'],
                'receivable_amount_to_settle' => $allocationAmount,
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $receiptCurrency,
                'rate_to_currency' => $receiptCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $receiptDate,
                'allocation_note' => $allocationNote,
                'actor_user_id' => $actorUserId,
            ]);
            $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
            $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
            if ($allocationId <= 0 || $allocatedAmount <= 0) {
                throw new RuntimeException('Receipt auto allocation could not be completed.');
            }

            $accountingRepository->postCustomerReceiptAllocation([
                'branch_id' => (int) ($receivable['branch_id'] ?? $booking['branch_id']),
                'booking_reference' => (string) ($receivable['booking_reference'] ?? $booking['booking_reference']),
                'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
                'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                'customer_receivable_item_id' => (int) $receivable['id'],
                'customer_receipt_id' => $receiptId,
                'allocated_amount' => $allocatedAmount,
                'entry_date' => $receiptDate,
                'currency' => (string) ($receivable['currency'] ?? $receiptCurrency),
                'actor_user_id' => $actorUserId,
            ]);

            app_write_log('workspace.receipt.auto_allocate_posted', 'Customer receipt allocation posted to current invoice.', array_merge(app_request_log_context(), [
                'booking_id' => (int) ($booking['id'] ?? 0),
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'allocation_id' => $allocationId,
                'receivable_id' => (int) ($receivable['id'] ?? 0),
                'allocated_amount' => $allocatedAmount,
                'receipt_unallocated_after' => round((float) ($allocationResult['receipt_unallocated_amount'] ?? 0), 2),
                'receivable_outstanding_after' => round((float) ($allocationResult['receivable_outstanding_amount'] ?? 0), 2),
            ]));
        }
    }

    private function buildExplicitAllocationPayload(array $receipt, array $receivable, array $line): array
    {
        $receiptCurrency = strtoupper(trim((string) ($receipt['currency'] ?? '')));
        $receivableCurrency = strtoupper(trim((string) ($receivable['currency'] ?? '')));
        $rateDate = (string) ($line['exchange_rate_effective_date'] ?? '') !== ''
            ? (string) $line['exchange_rate_effective_date']
            : (string) ($receipt['receipt_date'] ?? date('Y-m-d'));

        if ($receiptCurrency === $receivableCurrency) {
            return [
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $receivableCurrency,
                'rate_to_currency' => $receiptCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $rateDate,
            ];
        }

        $rateFromCurrency = trim((string) ($line['rate_from_currency'] ?? ''));
        $rateToCurrency = trim((string) ($line['rate_to_currency'] ?? ''));
        $exchangeRate = $line['exchange_rate'] ?? null;

        if ($rateFromCurrency !== '' && $rateToCurrency !== '' && $exchangeRate !== null) {
            return [
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $rateFromCurrency,
                'rate_to_currency' => $rateToCurrency,
                'exchange_rate' => (float) $exchangeRate,
                'exchange_rate_effective_date' => $rateDate,
            ];
        }

        $exactRate = (new ExchangeRateRepository($this->app))->requireExactRate(
            $receivableCurrency,
            $receiptCurrency,
            $rateDate
        );

        return [
            'payment_currency' => $receiptCurrency,
            'rate_from_currency' => (string) ($exactRate['from_currency'] ?? $receivableCurrency),
            'rate_to_currency' => (string) ($exactRate['to_currency'] ?? $receiptCurrency),
            'exchange_rate' => (float) ($exactRate['exchange_rate'] ?? 0),
            'exchange_rate_effective_date' => (string) ($exactRate['effective_date'] ?? $rateDate),
        ];
    }

    private function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid receipt currency.');
        }

        return $currency;
    }

    private function normalizeMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, $this->activePaymentMethodCodes(), true)) {
            throw new RuntimeException('Please select a valid payment method.');
        }

        return $method;
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
            throw new RuntimeException('Please select a valid receipt status.');
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
            throw new RuntimeException('Exchange rate must be greater than zero.');
        }

        return round((float) $trimmed, 8);
    }

    private function normalizeOptionalDate(string $value): ?string
    {
        $date = trim($value);
        if ($date === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Due date is invalid.');
        }

        return $date;
    }

    private function moneyValue(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException($label . ' must be greater than zero.');
        }

        return round((float) $value, 2);
    }

    private function nonNegativeMoneyValue(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new RuntimeException($label . ' cannot be negative.');
        }

        return round((float) $value, 2);
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

    private function requiredVoidReason(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new RuntimeException('Void reason is required.');
        }

        if (mb_strlen($text) < 5) {
            throw new RuntimeException('Void reason must be at least 5 characters.');
        }

        if (mb_strlen($text) > 1000) {
            throw new RuntimeException('Void reason cannot exceed 1000 characters.');
        }

        return $text;
    }
}
