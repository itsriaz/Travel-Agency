<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountHolderBalanceRepository;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRepository;
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

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            if ($payload['booking_id'] > 0) {
                $exchangeRateRepository->upsertBookingRate(
                    $payload['booking_id'],
                    $payload['branch_id'],
                    $payload['rate_from_currency'],
                    $payload['rate_to_currency'],
                    $payload['exchange_rate_effective_date'],
                    $payload['exchange_rate'],
                    $actorUserId
                );
            }
            // Allocation validation intentionally verifies the dated market quote.
            // The booking copy controls reuse; the daily copy validates any
            // cross-currency receipt/allocation posted with the same quote.
            $exchangeRateRepository->upsertDailyRate(
                $payload['rate_from_currency'],
                $payload['rate_to_currency'],
                $payload['exchange_rate_effective_date'],
                $payload['exchange_rate'],
                $payload['branch_id'],
                $actorUserId
            );

            $payload['updated_services'] = 0;
            if ($payload['booking_id'] > 0 && (int) ($input['synchronize_booking_pricing'] ?? 0) === 1) {
                $syncResult = (new ServiceWorkspaceService($this->app))->synchronizeBookingExchangeRate(
                    $payload['booking_id'],
                    $payload['rate_from_currency'],
                    $payload['rate_to_currency'],
                    $actorUserId,
                    $accessibleBranchIds
                );
                $payload['updated_services'] = (int) ($syncResult['updated_services'] ?? 0);
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        return $payload;
    }

    public function saveReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertNoPostedReceiptEditAttempt($input);

        if (($input['settlement_mode'] ?? 'normal') === 'exchange') {
            $exchangeBookingId = (int) ($input['booking_id'] ?? 0);
            if (! (new BookingRepository($this->app))->bookingExistsInBranches($exchangeBookingId, $accessibleBranchIds)) {
                throw new RuntimeException('You cannot record receipts for a booking outside your accessible branches.');
            }
            if ($this->hasSettledCancellation($exchangeBookingId)) {
                throw new RuntimeException('A cross-currency receipt after cancellation needs financial review before it can be posted. No money was recorded.');
            }
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
        $cancelledCharge = null;
        if ($this->isDirectSupplierPaymentMethod((string) $payload['payment_method'])) {
            if ($this->hasSettledCancellation($bookingId)) {
                throw new RuntimeException('Direct supplier payment after cancellation needs financial review before it can be posted. No money was recorded.');
            }
            return $this->saveDirectSupplierReceipt($input, $booking, $payload, $actorUserId, $accessibleBranchIds);
        }
        if (! $skipReceiptCreation && (float) ($payload['received_amount'] ?? 0) > 0.005 && $payload['status'] !== 'void') {
            $cancelledCharge = $this->settledCancellationChargeCollection($booking, $payload, $input);
            if ($cancelledCharge === null) {
                $lateCancellation = $this->lateCancellationForReceipt($booking, $payload, $input, $actorUserId);
                if ($lateCancellation !== null) {
                    return $this->saveReceiptAfterCancellation($input, $booking, $payload, $lateCancellation, $actorUserId, $accessibleBranchIds);
                }
            }
        }
        $accountHolderFunding = $this->resolveAccountHolderBalanceFunding(
            $input,
            (int) ($booking['branch_id'] ?? 0),
            (string) ($payload['currency'] ?? 'PKR'),
            round((float) ($payload['received_amount'] ?? 0), 2),
            (int) ($booking['business_source_id'] ?? 0)
        );
        if (round((float) ($payload['received_amount'] ?? 0), 2) <= 0.005) {
            $skipReceiptCreation = true;
            $receiptAction = 'no_receipt';
        }
        if ($accountHolderFunding['enabled']) {
            $payload['payment_method'] = 'account_holder_balance';
            $payload['treasury_account_id'] = null;
        } else {
            $payload['treasury_account_id'] = $skipReceiptCreation
                ? null
                : $this->resolveReceiptTreasuryAccountId(
                    $input,
                    $payload,
                    (int) $booking['branch_id']
                );
        }
        if ($payload['status'] === 'void') {
            $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can create a void customer receipt.');
        }

        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $accountHolderBalanceRepository = new AccountHolderBalanceRepository($this->app);
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        } elseif ($cancelledCharge !== null) {
            $db->exec('SAVEPOINT cancelled_charge_receipt');
        }

        try {
            if ($cancelledCharge !== null) {
                if (! $bookingRepository->lockBookingForFinancialWrite($bookingId)) {
                    throw new RuntimeException('The booking changed before its cancelled charge could be collected. No money was posted.');
                }
                $freshCharge = $this->settledCancellationChargeCollection($booking, $payload, $input);
                if ($freshCharge === null
                    || (int) $freshCharge['receivable_id'] !== (int) $cancelledCharge['receivable_id']
                    || (int) $freshCharge['cancellation_event_id'] !== (int) $cancelledCharge['cancellation_event_id']) {
                    throw new RuntimeException('The cancelled charge changed before payment. Reload and try again; no money was posted.');
                }
                $cancelledCharge = $freshCharge;
            }
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
                    'business_source_id' => $accountHolderFunding['enabled']
                        ? (int) ($accountHolderFunding['business_source_id'] ?? 0)
                        : null,
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

            if ($accountHolderFunding['enabled'] && $receiptId > 0 && $payload['status'] !== 'void') {
                $accountHolderBalanceRepository->recordApplication([
                    'branch_id' => (int) ($booking['branch_id'] ?? 0),
                    'business_source_id' => (int) ($accountHolderFunding['business_source_id'] ?? 0),
                    'customer_receipt_id' => $receiptId,
                    'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                    'source_currency' => (string) ($accountHolderFunding['source_currency'] ?? $payload['currency']),
                    'target_currency' => (string) ($payload['currency'] ?? 'PKR'),
                    'source_amount' => (float) ($accountHolderFunding['source_amount'] ?? 0),
                    'target_amount' => (float) ($payload['received_amount'] ?? 0),
                    'exchange_rate' => (float) ($accountHolderFunding['exchange_rate'] ?? 1),
                    'applied_date' => (string) ($payload['receipt_date'] ?? date('Y-m-d')),
                    'remarks' => 'Account-holder balance applied to booking receipt.',
                    'actor_user_id' => $actorUserId,
                ]);
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
            $customerCreditApplyResult = $this->applyBookingCreditToCurrentInvoice(
                $input,
                $booking,
                (string) $payload['currency'],
                (string) $payload['receipt_date'],
                $actorUserId,
                $repository,
                $accountingRepository
            );

            if ($cancelledCharge !== null) {
                $savedReceipt = $repository->findReceiptById($receiptId);
                $settledItem = $repository->findReceivableByServiceLine(
                    (string) $booking['booking_reference'],
                    (string) $cancelledCharge['service_line_reference']
                );
                $creditApplied = round((float) ($customerCreditApplyResult['allocated_amount'] ?? 0), 2);
                $expectedCredit = round((float) ($cancelledCharge['credit_amount'] ?? 0), 2);
                $cashAmount = round((float) $payload['received_amount'], 2);
                $expectedOutstanding = round((float) $cancelledCharge['outstanding_amount'] - $cashAmount - $expectedCredit, 2);
                if ($savedReceipt === null || $settledItem === null
                    || abs((float) $savedReceipt['allocated_amount'] - $cashAmount) > 0.005
                    || abs((float) $savedReceipt['unallocated_amount']) > 0.005
                    || abs($creditApplied - $expectedCredit) > 0.005
                    || abs((float) $settledItem['outstanding_amount'] - $expectedOutstanding) > 0.005) {
                    throw new RuntimeException('The cancelled charge payment did not allocate exactly. All changes were rolled back.');
                }
                AuditLog::recordRequired($this->app, 'customer.receipt.cancelled_charge_collected', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) $booking['booking_reference'],
                    'cancellation_event_id' => (int) $cancelledCharge['cancellation_event_id'],
                    'customer_receivable_item_id' => (int) $cancelledCharge['receivable_id'],
                    'customer_receipt_id' => $receiptId,
                    'currency' => (string) $payload['currency'],
                    'cash_received' => $cashAmount,
                    'customer_credit_applied' => $creditApplied,
                    'outstanding_before' => (float) $cancelledCharge['outstanding_amount'],
                    'outstanding_after' => (float) $settledItem['outstanding_amount'],
                ]);
            }

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
                    'customer_credit_allocated_amount' => round((float) ($customerCreditApplyResult['allocated_amount'] ?? 0), 2),
                    'customer_credit_allocation_count' => (int) ($customerCreditApplyResult['allocation_count'] ?? 0),
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
                    'customer_credit_allocated_amount' => round((float) ($customerCreditApplyResult['allocated_amount'] ?? 0), 2),
                    'customer_credit_allocation_count' => (int) ($customerCreditApplyResult['allocation_count'] ?? 0),
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
            } elseif ($cancelledCharge !== null && $db->inTransaction()) {
                $db->exec('RELEASE SAVEPOINT cancelled_charge_receipt');
            }

            return [
                'receipt' => $receiptId > 0 ? $repository->findReceiptById($receiptId) : null,
                'booking_id' => $bookingId,
                'returned_amount' => (float) $payload['returned_amount'],
                'advance_applied' => $advanceApplyResult,
                'customer_credit_applied' => $customerCreditApplyResult,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            } elseif ($cancelledCharge !== null && $db->inTransaction()) {
                $db->exec('ROLLBACK TO SAVEPOINT cancelled_charge_receipt');
                $db->exec('RELEASE SAVEPOINT cancelled_charge_receipt');
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer receipt could not be saved.', 0, $exception);
        }
    }

    private function hasSettledCancellation(int $bookingId): bool
    {
        if ($bookingId <= 0) {
            return false;
        }
        $events = new BookingServiceEventRepository($this->app);
        foreach ((new BookingServiceRepository($this->app))->servicesForBooking($bookingId) as $service) {
            if ((int) ($service['is_active'] ?? 0) !== 1) {
                continue;
            }
            if ($events->hasPostedCancellationSettlement((int) $service['id'])) {
                return true;
            }
        }
        return false;
    }

    private function settledCancellationChargeCollection(array $booking, array $payload, array $input): ?array
    {
        $services = array_values(array_filter(
            (new BookingServiceRepository($this->app))->servicesForBooking((int) $booking['id']),
            static fn (array $service): bool => (int) ($service['is_active'] ?? 0) === 1
        ));
        if (count($services) !== 1) {
            return null;
        }

        $service = $services[0];
        $events = new BookingServiceEventRepository($this->app);
        $cancellation = $events->latestPostedEvent((int) $service['id'], 'cancel');
        if ($cancellation === null || ! $events->hasPostedCancellationSettlement((int) $service['id'])) {
            return null;
        }

        $details = json_decode((string) $cancellation['payload_json'], true);
        $currency = (string) ($service['currency'] ?? '');
        $cashAmount = round((float) ($payload['received_amount'] ?? 0), 2);
        $creditAmount = $this->nonNegativeMoneyValue($input['customer_credit_apply_amount'] ?? 0, 'Customer credit used');
        $creditReceiptId = (int) ($input['customer_credit_receipt_id'] ?? 0);
        $scope = $this->normalizeReceiptScope((string) ($input['receipt_scope'] ?? 'whole_invoice'));
        $payments = new CustomerPaymentRepository($this->app);
        $item = $payments->findReceivableByServiceLine(
            (string) $booking['booking_reference'], (string) $service['line_reference']
        );
        if (! is_array($details)
            || strtolower(trim((string) ($service['service_status'] ?? ''))) !== 'cancelled'
            || (string) ($payload['receipt_date'] ?? '') < (string) ($cancellation['event_date'] ?? '')
            || (string) ($payload['currency'] ?? '') !== $currency
            || ! in_array((string) ($payload['payment_method'] ?? ''), ['cash', 'bank_transfer', 'debit_card', 'credit_card'], true)
            || (string) ($payload['status'] ?? '') !== 'received'
            || abs((float) ($payload['charges_amount'] ?? 0)) > 0.005
            || str_replace(' ', '_', mb_strtolower(trim((string) ($input['account_balance_mode'] ?? '')))) === 'account_holder_balance'
            || (float) ($input['advance_apply_amount'] ?? 0) > 0.005
            || $events->latestPostedEvent((int) $service['id'], 'refund') !== null
            || abs((float) ($details['target_customer_refund_credit'] ?? 0)) > 0.005
            || abs((float) ($details['released_customer_credit'] ?? 0)) > 0.005
            || $payments->bookingUnallocatedCreditTotal((string) $booking['booking_reference'], $currency) > 0.005
            || $item === null
            || (string) $item['currency'] !== $currency
            || ! in_array((string) $item['status'], ['open', 'partially_paid'], true)
            || abs((float) $item['due_amount'] - (float) ($details['customer_final_charge_amount'] ?? -1)) > 0.005
            || abs((float) $item['outstanding_amount'] - ((float) $item['due_amount'] - (float) $item['allocated_amount'])) > 0.005
            || (float) $item['outstanding_amount'] <= 0.005
            || $cashAmount <= 0.005
            || $cashAmount + $creditAmount > (float) $item['outstanding_amount'] + 0.005
            || ($scope === 'passenger_specific' && (int) ($input['target_receivable_item_id'] ?? 0) !== (int) $item['id'])
            || ($creditAmount > 0.005 && $creditReceiptId <= 0)) {
            return null;
        }

        return [
            'cancellation_event_id' => (int) $cancellation['id'],
            'service_line_reference' => (string) $service['line_reference'],
            'receivable_id' => (int) $item['id'],
            'outstanding_amount' => round((float) $item['outstanding_amount'], 2),
            'credit_amount' => round($creditAmount, 2),
        ];
    }

    private function lateCancellationForReceipt(array $booking, array $payload, array $input, int $actorUserId): ?array
    {
        $services = array_values(array_filter(
            (new BookingServiceRepository($this->app))->servicesForBooking((int) $booking['id']),
            static fn (array $service): bool => (int) ($service['is_active'] ?? 0) === 1
        ));
        $events = new BookingServiceEventRepository($this->app);
        $settled = [];
        foreach ($services as $service) {
            $event = $events->latestPostedEvent((int) $service['id'], 'cancel');
            if ($event !== null && $events->hasPostedCancellationSettlement((int) $service['id'])) {
                $details = json_decode((string) $event['payload_json'], true);
                $settled[] = ['service' => $service, 'event' => $event, 'details' => $details];
            }
        }
        if ($settled === []) {
            return null;
        }

        if (count($services) !== 1 || count($settled) !== 1) {
            if (($input['receipt_scope'] ?? '') === 'passenger_specific') {
                $targetId = (int) ($input['target_receivable_item_id'] ?? 0);
                $payments = new CustomerPaymentRepository($this->app);
                foreach ($services as $service) {
                    if (in_array((int) $service['id'], array_map(
                        static fn (array $row): int => (int) $row['service']['id'], $settled
                    ), true)) {
                        continue;
                    }
                    $item = $payments->findReceivableByServiceLine(
                        (string) $booking['booking_reference'], (string) $service['line_reference']
                    );
                    if (strtolower((string) ($service['service_status'] ?? '')) !== 'cancelled'
                        && $item !== null && (int) $item['id'] === $targetId
                        && (string) $item['currency'] === (string) $payload['currency']) {
                        return null;
                    }
                }
            }
            throw new RuntimeException('This booking has a settled cancellation and multiple services. Select an ordinary service due, or ask a financial admin to review the cancelled ticket before recording its payment. No money was posted.');
        }

        $context = $settled[0];
        $service = $context['service'];
        $details = $context['details'];
        $payments = new CustomerPaymentRepository($this->app);
        $reference = (string) $booking['booking_reference'];
        $item = $payments->findReceivableByServiceLine($reference, (string) $service['line_reference']);
        $supplier = (new SupplierRepository($this->app))->findObligationByServiceLine($reference, (string) $service['line_reference']);
        $watermark = $details['customer_receipt_id_watermark'] ?? null;
        $releasedCredit = round((float) ($details['released_customer_credit'] ?? 0), 2);
        $targetCredit = round((float) ($details['target_customer_refund_credit'] ?? 0), 2);
        if (strtolower(trim((string) ($service['service_status'] ?? ''))) !== 'cancelled'
            || (string) ($payload['receipt_date'] ?? '') < (string) ($context['event']['event_date'] ?? '')
            || (string) ($payload['currency'] ?? '') !== (string) ($service['currency'] ?? '')
            || ! in_array((string) ($payload['payment_method'] ?? ''), ['cash', 'bank_transfer', 'debit_card', 'credit_card'], true)
            || abs((float) ($payload['charges_amount'] ?? 0)) > 0.005
            || str_replace(' ', '_', mb_strtolower(trim((string) ($input['account_balance_mode'] ?? '')))) === 'account_holder_balance'
            || (float) ($input['advance_apply_amount'] ?? 0) > 0.005
            || (float) ($input['customer_credit_apply_amount'] ?? 0) > 0.005
            || ! is_numeric($watermark)
            || $payments->activeReceiptCountAfterId($reference, (int) $watermark) > 0
            || $events->latestPostedEvent((int) $service['id'], 'refund') !== null
            || abs($targetCredit - $releasedCredit) > 0.005
            || abs($payments->bookingUnallocatedCreditTotal($reference, (string) $payload['currency']) - $releasedCredit) > 0.005
            || $item === null
            || abs((float) $item['due_amount'] - (float) ($details['customer_final_charge_amount'] ?? -1)) > 0.005
            || abs((float) $item['allocated_amount'] - min(
                (float) ($details['customer_received_amount'] ?? 0), (float) $item['due_amount']
            )) > 0.005
            || $supplier === null
            || abs((float) $supplier['gross_amount'] - (float) $supplier['net_payable_amount']) > 0.005
            || abs((float) ($details['released_supplier_credit_source_amount'] ?? 0)) > 0.005
            || ! isset($details['expected_supplier_refund_source_amount'], $details['prior_customer_due_amount'])) {
            throw new RuntimeException('This cancelled booking has existing payments, refunds, mixed currency, or incomplete settlement history. A financial admin must reconcile it before another receipt is recorded; no money was posted.');
        }

        $this->assertFinancialAdminActor($actorUserId, 'Only a financial admin can record a late receipt against a cancelled ticket. No money was posted.');
        $context['supplier'] = $supplier;
        return $context;
    }

    private function saveReceiptAfterCancellation(
        array $input,
        array $booking,
        array $payload,
        array $context,
        int $actorUserId,
        array $accessibleBranchIds
    ): array {
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        } else {
            $db->exec('SAVEPOINT late_cancellation_receipt');
        }
        try {
            if (! (new BookingRepository($this->app))->lockBookingForFinancialWrite((int) $booking['id'])) {
                throw new RuntimeException('The booking changed before its late receipt could be recorded.');
            }
            $freshContext = $this->lateCancellationForReceipt($booking, $payload, $input, $actorUserId);
            if ($freshContext === null || (int) $freshContext['event']['id'] !== (int) $context['event']['id']) {
                throw new RuntimeException('The cancellation changed before its late receipt could be recorded. Reload and review it.');
            }
            $context = $freshContext;
            $service = $context['service'];
            $before = $context['details'];
            $serviceId = (int) $service['id'];
            $workspace = new ServiceWorkspaceService($this->app);
            $events = new BookingServiceEventRepository($this->app);
            $workspace->reverseCancellationSettlement([
                'booking_id' => (int) $booking['id'],
                'service_id' => $serviceId,
                'settlement_reverse_reason' => 'Reconcile a later customer receipt before refreshing the cancellation refund basis.',
            ], $actorUserId, $accessibleBranchIds);

            $result = $this->saveReceipt($input, $actorUserId, $accessibleBranchIds);
            $receipt = $result['receipt'] ?? null;
            if (! is_array($receipt) || (int) ($receipt['id'] ?? 0) <= 0) {
                throw new RuntimeException('The late customer receipt was not created; cancellation reconciliation was rolled back.');
            }

            $settlement = [
                'booking_id' => (int) $booking['id'],
                'service_id' => $serviceId,
                'settlement_reason' => 'Refresh cancellation after later receipt ' . $receipt['receipt_no'],
                'settlement_event_date' => date('Y-m-d'),
                'customer_penalty_amount' => (float) ($before['customer_penalty_source_amount'] ?? $before['customer_penalty_amount'] ?? 0),
                'customer_penalty_currency' => (string) ($before['customer_penalty_currency'] ?? $service['currency']),
                'customer_penalty_exchange_rate' => (float) ($before['customer_penalty_exchange_rate'] ?? 1),
                'agency_fee_refund_amount' => (float) ($before['agency_fee_refund_amount'] ?? 0),
                'agency_fee_refund_currency' => (string) $service['currency'],
                'expected_supplier_refund_amount' => (float) $before['expected_supplier_refund_source_amount'],
                'expected_supplier_refund_currency' => (string) ($before['expected_supplier_refund_currency'] ?? $service['cost_currency']),
                'expected_supplier_refund_exchange_rate' => (float) ($before['expected_supplier_refund_exchange_rate'] ?? 1),
                'expected_supplier_refund_rate_date' => (string) ($before['expected_supplier_refund_rate_date'] ?? $context['event']['event_date']),
                'settlement_currency' => (string) ($before['settlement_currency'] ?? $service['currency']),
                'settlement_exchange_rate' => (float) ($before['expected_supplier_refund_exchange_rate'] ?? 1),
            ];
            if (isset($before['expected_supplier_refund_equivalent_amount'])) {
                $settlement['supplier_refund_conversion_mode'] = 'manual_equivalent';
                $settlement['expected_supplier_refund_equivalent_amount'] = (float) $before['expected_supplier_refund_equivalent_amount'];
            }
            $workspace->settleCancellationFinancials($settlement, $actorUserId, $accessibleBranchIds);

            $after = json_decode((string) ($events->latestPostedEvent($serviceId, 'cancel')['payload_json'] ?? ''), true);
            $supplierAfter = (new SupplierRepository($this->app))->findObligationByServiceLine(
                (string) $booking['booking_reference'], (string) $service['line_reference']
            );
            if (! is_array($after)
                || abs((float) ($after['customer_final_charge_amount'] ?? -1) - (float) $before['customer_final_charge_amount']) > 0.005
                || abs((float) ($after['expected_supplier_refund_booking_amount'] ?? -1) - (float) ($before['expected_supplier_refund_booking_amount'] ?? -1)) > 0.005
                || abs((float) ($after['supplier_penalty_source_amount'] ?? -1) - (float) ($before['supplier_penalty_source_amount'] ?? -1)) > 0.005
                || $supplierAfter === null
                || (string) $supplierAfter['currency'] !== (string) $context['supplier']['currency']
                || abs((float) $supplierAfter['gross_amount'] - (float) $context['supplier']['gross_amount']) > 0.005
                || abs((float) $supplierAfter['net_payable_amount'] - (float) $context['supplier']['net_payable_amount']) > 0.005) {
                throw new RuntimeException('Cancellation terms changed during the late receipt. All changes were rolled back for financial review.');
            }
            AuditLog::recordRequired($this->app, 'customer.receipt.late_cancellation_reconciled', [
                'user_id' => $actorUserId,
                'booking_id' => (int) $booking['id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'service_id' => $serviceId,
                'customer_receipt_id' => (int) $receipt['id'],
                'receipt_no' => (string) $receipt['receipt_no'],
                'currency' => (string) $payload['currency'],
                'received_amount' => (float) $receipt['received_amount'],
                'refund_entitlement_before' => (float) ($before['target_customer_refund_credit'] ?? 0),
                'refund_entitlement_after' => (float) ($after['target_customer_refund_credit'] ?? 0),
            ]);
            $result['receipt'] = (new CustomerPaymentRepository($this->app))->findReceiptById((int) $receipt['id']);
            if ($startedTransaction) { $db->commit(); }
            else { $db->exec('RELEASE SAVEPOINT late_cancellation_receipt'); }
            return $result;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                if ($startedTransaction) { $db->rollBack(); }
                else {
                    $db->exec('ROLLBACK TO SAVEPOINT late_cancellation_receipt');
                    $db->exec('RELEASE SAVEPOINT late_cancellation_receipt');
                }
            }
            throw $exception;
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
            $supplierSettlement = $this->resolveDirectSupplierSettlementPlan(
                $input,
                $supplierRepository,
                $bookingReference,
                $branchId,
                $currency
            );
            $obligation = $supplierSettlement['primary_obligation'];
            $supplierObligationId = (int) ($obligation['id'] ?? 0);
            $supplierObligations = $supplierSettlement['obligations'];
            $supplierOutstanding = round((float) ($supplierSettlement['total_outstanding'] ?? 0), 2);

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
                'branch_id' => (int) ($obligation['branch_id'] ?? $branchId),
                'booking_reference' => (string) ($obligation['booking_reference'] ?? $bookingReference),
                'payment_scope' => count($supplierObligations) > 1 ? 'global' : 'booking',
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

            $supplierAppliedAmount = round(min($directAmount, $supplierOutstanding), 2);
            $supplierAdvanceAmount = round(max($directAmount - $supplierAppliedAmount, 0), 2);
            $supplierAllocationIds = [];
            $supplierAllocationLines = [];
            $remainingSupplierAmount = $supplierAppliedAmount;
            foreach ($supplierObligations as $supplierObligation) {
                if ($remainingSupplierAmount <= 0.005) {
                    break;
                }

                $obligationBalance = round((float) ($supplierObligation['net_payable_amount'] ?? 0), 2);
                if ($obligationBalance <= 0.005) {
                    continue;
                }

                $allocationAmount = round(min($remainingSupplierAmount, $obligationBalance), 2);
                if ($allocationAmount <= 0.005) {
                    continue;
                }

                $allocationId = $supplierRepository->allocateSupplierPayment(
                    $supplierPaymentId,
                    (int) ($supplierObligation['id'] ?? 0),
                    $allocationAmount,
                    1.0,
                    'Customer paid supplier directly',
                    $actorUserId
                );
                $supplierAllocationIds[] = $allocationId;
                $supplierAllocationLines[] = [
                    'supplier_obligation_id' => (int) ($supplierObligation['id'] ?? 0),
                    'service_line_reference' => (string) ($supplierObligation['service_line_reference'] ?? '') !== ''
                        ? (string) $supplierObligation['service_line_reference']
                        : null,
                    'allocated_amount' => $allocationAmount,
                ];
                $remainingSupplierAmount = round($remainingSupplierAmount - $allocationAmount, 2);
            }

            $remainingAmount = $directAmount;
            $customerAllocationLines = [];
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
                $customerAllocationLines[] = [
                    'customer_receivable_item_id' => (int) $receivable['id'],
                    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== ''
                        ? (string) $receivable['service_line_reference']
                        : null,
                    'allocated_amount' => $allocatedAmount,
                ];

                $remainingAmount = round($remainingAmount - $allocatedAmount, 2);
            }

            if ($remainingAmount > 0.005) {
                throw new RuntimeException('Direct supplier payment could not be fully allocated to customer receivables.');
            }

            $journalEntryId = $accountingRepository->postCustomerDirectSupplierPayment([
                'branch_id' => $branchId,
                'booking_reference' => $bookingReference,
                'source_reference' => $receiptNo . '/' . $paymentNo . '-DIRECT',
                'supplier_obligation_id' => $supplierObligationId,
                'supplier_payment_id' => $supplierPaymentId,
                'customer_receipt_id' => $receiptId,
                'customer_allocations' => $customerAllocationLines,
                'supplier_allocations' => $supplierAllocationLines,
                'supplier_allocated_amount' => $supplierAppliedAmount,
                'supplier_advance_amount' => $supplierAdvanceAmount,
                'entry_date' => $payload['receipt_date'],
                'currency' => $currency,
                'actor_user_id' => $actorUserId,
            ]);

            $supplierAdvanceId = null;
            if ($supplierAdvanceAmount > 0.005) {
                $supplierAdvanceId = $supplierRepository->registerAdvance([
                    'supplier_id' => (int) ($obligation['supplier_id'] ?? 0),
                    'branch_id' => (int) ($obligation['branch_id'] ?? $branchId),
                    'currency' => $currency,
                    'deposit_amount' => $supplierAdvanceAmount,
                    'available_amount' => $supplierAdvanceAmount,
                    'reference_no' => trim((string) ($payload['reference_number'] ?? '')) !== ''
                        ? (string) $payload['reference_number']
                        : $paymentNo,
                    'remarks' => 'Customer paid supplier directly; excess converted to supplier advance. Customer receipt: ' . $receiptNo,
                    'received_at' => $payload['receipt_date'],
                    'payment_method' => self::DIRECT_SUPPLIER_PAYMENT_METHOD,
                    'treasury_account_id' => null,
                    'journal_entry_id' => $journalEntryId,
                    'actor_user_id' => $actorUserId,
                    'source_supplier_payment_id' => $supplierPaymentId,
                ]);
                $supplierRepository->convertSupplierPaymentExcessToAdvance(
                    $supplierPaymentId,
                    $supplierAdvanceId,
                    $supplierAdvanceAmount,
                    $actorUserId
                );
            }

            AuditLog::record($this->app, 'workspace.customer_direct_supplier_payment.posted', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'customer_receipt_id' => $receiptId,
                'customer_receipt_no' => $receiptNo,
                'supplier_payment_id' => $supplierPaymentId,
                'supplier_payment_no' => $paymentNo,
                'supplier_payment_allocation_id' => $supplierAllocationIds[0] ?? 0,
                'supplier_payment_allocation_ids' => $supplierAllocationIds,
                'supplier_obligation_id' => $supplierObligationId,
                'currency' => $currency,
                'amount' => $directAmount,
                'supplier_allocated_amount' => $supplierAppliedAmount,
                'supplier_advance_amount' => $supplierAdvanceAmount,
                'supplier_advance_id' => $supplierAdvanceId,
            ]);

            app_write_log('workspace.receipt.direct_supplier_payment_posted', 'Customer direct-to-supplier payment posted.', array_merge(app_request_log_context(), [
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'receipt_id' => $receiptId,
                'receipt_no' => $receiptNo,
                'supplier_payment_id' => $supplierPaymentId,
                'supplier_payment_no' => $paymentNo,
                'supplier_obligation_id' => $supplierObligationId,
                'supplier_obligation_count' => count($supplierObligations),
                'currency' => $currency,
                'amount' => $directAmount,
                'supplier_allocated_amount' => $supplierAppliedAmount,
                'supplier_advance_amount' => $supplierAdvanceAmount,
                'supplier_advance_id' => $supplierAdvanceId,
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
        if ($this->isDirectSupplierPaymentMethod((string) ($payload['payment_method'] ?? ''))) {
            return $this->recordGlobalDirectSupplierPayment(
                $input,
                $actorUserId,
                $branchId,
                $travelerId,
                $selectedReceivableIds,
                $payload
            );
        }
        $currency = (string) ($payload['currency'] ?? 'PKR');
        $accountHolderFunding = $this->resolveAccountHolderBalanceFunding(
            $input,
            $branchId,
            $currency,
            round((float) ($payload['received_amount'] ?? 0), 2)
        );
        if ($accountHolderFunding['enabled']) {
            $payload['payment_method'] = 'account_holder_balance';
            $payload['treasury_account_id'] = null;
        } else {
            $payload['treasury_account_id'] = $this->resolveReceiptTreasuryAccountId($input, $payload, $branchId);
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $accountHolderBalanceRepository = new AccountHolderBalanceRepository($this->app);
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
                'business_source_id' => $accountHolderFunding['enabled']
                    ? (int) ($accountHolderFunding['business_source_id'] ?? 0)
                    : null,
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

            if ($accountHolderFunding['enabled']) {
                $accountHolderBalanceRepository->recordApplication([
                    'branch_id' => $branchId,
                    'business_source_id' => (int) ($accountHolderFunding['business_source_id'] ?? 0),
                    'customer_receipt_id' => $receiptId,
                    'booking_reference' => 'GLOBAL',
                    'source_currency' => (string) ($accountHolderFunding['source_currency'] ?? $currency),
                    'target_currency' => $currency,
                    'source_amount' => (float) ($accountHolderFunding['source_amount'] ?? 0),
                    'target_amount' => (float) ($payload['received_amount'] ?? 0),
                    'exchange_rate' => (float) ($accountHolderFunding['exchange_rate'] ?? 1),
                    'applied_date' => (string) ($payload['receipt_date'] ?? date('Y-m-d')),
                    'remarks' => 'Account-holder balance applied to global customer payment.',
                    'actor_user_id' => $actorUserId,
                ]);
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

    private function recordGlobalDirectSupplierPayment(
        array $input,
        int $actorUserId,
        int $branchId,
        int $travelerId,
        array $selectedReceivableIds,
        array $payload
    ): array {
        $directAmount = round((float) ($payload['received_amount'] ?? 0), 2);
        if ($directAmount <= 0.005) {
            throw new RuntimeException('Please enter the amount the customer paid directly to the supplier.');
        }

        $currency = (string) ($payload['currency'] ?? 'PKR');
        $supplierId = max(0, (int) ($input['direct_supplier_id'] ?? 0));
        if ($supplierId <= 0) {
            throw new RuntimeException('Please select the supplier the customer paid directly.');
        }

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
            $receivables = $paymentRepository->openGlobalReceivablesForSettlement(
                $selectedReceivableIds,
                $branchId,
                $travelerId,
                $currency
            );
            if (count($receivables) !== count($selectedReceivableIds)) {
                throw new RuntimeException('One or more selected customer receivable rows are no longer available.');
            }

            $customerOutstanding = round(array_sum(array_map(
                static fn (array $receivable): float => max(0.0, (float) ($receivable['outstanding_amount'] ?? 0)),
                $receivables
            )), 2);
            if ($customerOutstanding <= 0.005) {
                throw new RuntimeException('No open customer receivable is available for this direct supplier payment.');
            }
            if ($directAmount > $customerOutstanding + 0.005) {
                throw new RuntimeException('Direct supplier payment exceeds the selected customer outstanding balance.');
            }

            $supplierSettlement = $this->resolveDirectSupplierSettlementPlan(
                ['direct_supplier_id' => $supplierId],
                $supplierRepository,
                'GLOBAL',
                $branchId,
                $currency
            );
            $obligation = $supplierSettlement['primary_obligation'];
            $supplierObligations = $supplierSettlement['obligations'];
            $supplierOutstanding = round((float) ($supplierSettlement['total_outstanding'] ?? 0), 2);

            $customerName = (string) ($receivables[0]['customer_name'] ?? 'Customer');
            $receiptNo = $paymentRepository->nextReceiptNumber();
            $receiptId = $paymentRepository->createReceipt(array_merge($payload, [
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'receipt_purpose' => 'global_payment',
                'booking_reference' => 'GLOBAL',
                'receipt_no' => $receiptNo,
                'treasury_account_id' => null,
                'actor_user_id' => $actorUserId,
                'remarks' => trim((string) ($payload['remarks'] ?? '')) !== ''
                    ? $payload['remarks']
                    : 'Customer paid supplier; supplier payable settled first and only any excess moved to supplier advance.',
            ]));

            $paymentNo = $supplierRepository->nextSupplierPaymentNumber();
            $supplierPaymentId = $supplierRepository->createSupplierPayment([
                'supplier_id' => (int) ($obligation['supplier_id'] ?? $supplierId),
                'branch_id' => $branchId,
                'booking_reference' => 'GLOBAL',
                'payment_scope' => 'global',
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
                'remarks' => 'Customer paid supplier on agency instruction. Supplier payable settled first. Customer receipt: ' . $receiptNo,
                'paid_amount' => $directAmount,
                'actor_user_id' => $actorUserId,
            ]);

            $supplierAppliedAmount = round(min($directAmount, $supplierOutstanding), 2);
            $supplierAdvanceAmount = round(max($directAmount - $supplierAppliedAmount, 0), 2);
            $supplierAllocationIds = [];
            $supplierAllocationLines = [];
            $remainingSupplierAmount = $supplierAppliedAmount;
            foreach ($supplierObligations as $supplierObligation) {
                if ($remainingSupplierAmount <= 0.005) {
                    break;
                }

                $obligationBalance = round((float) ($supplierObligation['net_payable_amount'] ?? 0), 2);
                if ($obligationBalance <= 0.005) {
                    continue;
                }

                $allocationAmount = round(min($remainingSupplierAmount, $obligationBalance), 2);
                if ($allocationAmount <= 0.005) {
                    continue;
                }

                $allocationId = $supplierRepository->allocateSupplierPayment(
                    $supplierPaymentId,
                    (int) ($supplierObligation['id'] ?? 0),
                    $allocationAmount,
                    1.0,
                    'Customer paid supplier; payable settled first',
                    $actorUserId
                );
                $supplierAllocationIds[] = $allocationId;
                $supplierAllocationLines[] = [
                    'supplier_obligation_id' => (int) ($supplierObligation['id'] ?? 0),
                    'service_line_reference' => (string) ($supplierObligation['service_line_reference'] ?? '') !== ''
                        ? (string) $supplierObligation['service_line_reference']
                        : null,
                    'allocated_amount' => $allocationAmount,
                ];
                $remainingSupplierAmount = round($remainingSupplierAmount - $allocationAmount, 2);
            }

            $remainingAmount = $directAmount;
            $allocationCount = 0;
            $allocatedAmount = 0.0;
            $affectedBookingReferences = [];
            $customerAllocationLines = [];
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
                    'allocation_note' => 'Customer paid supplier on agency instruction.',
                    'actor_user_id' => $actorUserId,
                ]);

                $allocatedReceiptAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
                if ($allocatedReceiptAmount <= 0.005) {
                    continue;
                }

                $customerAllocationLines[] = [
                    'customer_receivable_item_id' => (int) $receivable['id'],
                    'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== ''
                        ? (string) $receivable['service_line_reference']
                        : null,
                    'allocated_amount' => $allocatedReceiptAmount,
                ];
                $affectedBookingReferences[(string) ($receivable['booking_reference'] ?? '')] = true;
                $remainingAmount = round($remainingAmount - $allocatedReceiptAmount, 2);
                $allocatedAmount = round($allocatedAmount + $allocatedReceiptAmount, 2);
                $allocationCount++;
            }

            if ($remainingAmount > 0.005) {
                throw new RuntimeException('Direct supplier payment could not be fully allocated to customer receivables.');
            }

            $journalEntryId = $accountingRepository->postCustomerDirectSupplierPayment([
                'branch_id' => $branchId,
                'booking_reference' => null,
                'source_reference' => $receiptNo . '/' . $paymentNo . '-DIRECT-GLOBAL',
                'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                'supplier_payment_id' => $supplierPaymentId,
                'customer_receipt_id' => $receiptId,
                'customer_allocations' => $customerAllocationLines,
                'supplier_allocations' => $supplierAllocationLines,
                'supplier_allocated_amount' => $supplierAppliedAmount,
                'supplier_advance_amount' => $supplierAdvanceAmount,
                'entry_date' => $payload['receipt_date'],
                'currency' => $currency,
                'narration' => 'Customer paid supplier; supplier payable settled first',
                'actor_user_id' => $actorUserId,
            ]);

            $supplierAdvanceId = null;
            if ($supplierAdvanceAmount > 0.005) {
                $supplierAdvanceId = $supplierRepository->registerAdvance([
                    'supplier_id' => (int) ($obligation['supplier_id'] ?? $supplierId),
                    'branch_id' => $branchId,
                    'currency' => $currency,
                    'deposit_amount' => $supplierAdvanceAmount,
                    'available_amount' => $supplierAdvanceAmount,
                    'reference_no' => trim((string) ($payload['reference_number'] ?? '')) !== ''
                        ? (string) $payload['reference_number']
                        : $paymentNo,
                    'remarks' => 'Customer paid supplier; remaining excess moved to supplier advance. Customer receipt: ' . $receiptNo,
                    'received_at' => $payload['receipt_date'],
                    'payment_method' => self::DIRECT_SUPPLIER_PAYMENT_METHOD,
                    'treasury_account_id' => null,
                    'journal_entry_id' => $journalEntryId,
                    'actor_user_id' => $actorUserId,
                    'source_supplier_payment_id' => $supplierPaymentId,
                ]);
                $supplierRepository->convertSupplierPaymentExcessToAdvance(
                    $supplierPaymentId,
                    $supplierAdvanceId,
                    $supplierAdvanceAmount,
                    $actorUserId
                );
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $paymentRepository->findReceiptById($receiptId),
                'receipt_no' => $receiptNo,
                'branch_id' => $branchId,
                'customer_name' => $customerName,
                'currency' => $currency,
                'received_amount' => $directAmount,
                'allocated_amount' => $allocatedAmount,
                'unallocated_amount' => 0.0,
                'returned_amount' => 0.0,
                'allocation_count' => $allocationCount,
                'booking_count' => count(array_filter(array_keys($affectedBookingReferences))),
                'supplier_payment_id' => $supplierPaymentId,
                'supplier_payment_no' => $paymentNo,
                'supplier_advance_amount' => $supplierAdvanceAmount,
                'supplier_advance_id' => $supplierAdvanceId,
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

    public function recordCustomerAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        if ($travelerId <= 0) {
            throw new RuntimeException('Please select a valid customer.');
        }
        $businessSourceId = (int) ($input['business_source_id'] ?? 0);
        $businessSource = $businessSourceId > 0
            ? (new MasterDataRepository($this->app))->find('business_sources', $businessSourceId)
            : null;
        if ($businessSource === null || (int) ($businessSource['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Please select a valid Account Holder for this customer advance.');
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
                'business_source_id' => $businessSourceId,
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

            $advanceApplication = [
                'allocated_amount' => 0.0,
                'allocation_count' => 0,
            ];

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt_no' => $receiptNo,
                'receipt_id' => $receiptId,
                'customer_name' => $repository->customerNameByTraveler($travelerId),
                'currency' => $currency,
                'received_amount' => (float) $payload['received_amount'],
                'allocated_amount' => (float) ($advanceApplication['allocated_amount'] ?? 0),
                'allocation_count' => (int) ($advanceApplication['allocation_count'] ?? 0),
                'unallocated_amount' => round(
                    (float) $payload['received_amount'] - (float) ($advanceApplication['allocated_amount'] ?? 0),
                    2
                ),
                'business_source_id' => $businessSourceId,
                'business_source_name' => (string) ($businessSource['name'] ?? 'Account Holder'),
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
                $currency,
                (int) ($advance['business_source_id'] ?? 0)
            );
            if (count($receivables) !== count($selectedReceivableIds)) {
                throw new RuntimeException('One or more selected customer receivable rows are no longer available.');
            }

            $remainingAmount = round((float) ($advance['unallocated_amount'] ?? 0), 2);
            $allocatedAmount = 0.0;
            $allocationCount = 0;

            $allocationDate = $this->normalizeOptionalDate((string) ($input['allocation_date'] ?? '')) ?? date('Y-m-d');
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
                    'exchange_rate_effective_date' => $allocationDate,
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
                    'entry_date' => $allocationDate,
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
                || (int) ($receipt['traveler_id'] ?? 0) !== $travelerId
            ) {
                throw new RuntimeException('The selected customer advance does not match this customer.');
            }
            $originalBranchId = (int) ($receipt['branch_id'] ?? 0);
            if ($originalBranchId <= 0 || ! in_array($originalBranchId, $accessibleBranchIds, true)) {
                throw new RuntimeException('You cannot correct a customer advance from a branch outside your access.');
            }
            $oldCurrency = $this->normalizeCurrency((string) ($receipt['currency'] ?? 'PKR'));

            $oldJournalId = $repository->latestCustomerAdvanceReceiptJournalId($receiptId, (string) ($receipt['receipt_no'] ?? ''));
            $reversalJournalId = $oldJournalId !== null
                ? $accountingRepository->reverseJournalEntry($oldJournalId, [
                    'branch_id' => $originalBranchId,
                    'booking_reference' => null,
                    'source_type' => 'customer_advance_correction_reversal',
                    'source_reference' => (string) ($receipt['receipt_no'] ?? 'ADV') . '-ADV-REV-' . date('YmdHis'),
                    'entry_date' => $entryDate,
                    'currency' => $oldCurrency,
                    'narration' => 'Customer advance correction reversal',
                    'actor_user_id' => $actorUserId,
                    'line_description_prefix' => 'Correction reversal: ',
                ])
                : null;

            $corrected = $repository->correctCustomerAdvanceReceipt([
                'customer_receipt_id' => $receiptId,
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'entry_date' => $entryDate,
                'currency' => $currency,
                'amount' => $amount,
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
                'old_currency' => (string) ($old['currency'] ?? $oldCurrency),
                'new_currency' => $currency,
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
                || (string) ($refund['receipt_purpose'] ?? '') !== 'customer_advance'
                || (string) ($refund['receipt_status'] ?? '') === 'void'
                || (int) ($refund['receipt_branch_id'] ?? 0) !== $branchId
                || (int) ($refund['receipt_traveler_id'] ?? 0) !== $travelerId
                || strtoupper((string) ($refund['receipt_currency'] ?? '')) !== $currency
            ) {
                throw new RuntimeException('The selected returned advance does not match this customer, branch, and currency.');
            }

            $receiptId = (int) ($refund['customer_receipt_id'] ?? 0);
            $oldJournalId = $repository->latestCustomerAdvanceRefundJournalId($refundId);
            $restoredAccountName = trim((string) ($refund['treasury_account_name'] ?? ''));
            if ($restoredAccountName === '') {
                $restoredAccountName = 'Cash/Bank';
            }
            $reversalJournalId = $oldJournalId !== null
                ? $accountingRepository->reverseJournalEntry($oldJournalId, [
                    'booking_reference' => null,
                    'source_type' => 'customer_advance_return_correction_reversal',
                    'source_reference' => (string) ($refund['receipt_no'] ?? 'ADV') . '-REF-REV-' . date('YmdHis'),
                    'entry_date' => $entryDate,
                    'narration' => 'Customer advance return correction reversal',
                    'actor_user_id' => $actorUserId,
                    'line_description_prefix' => 'Correction reversal: ',
                    'line_description_overrides' => [
                        'Cash or bank returned to customer' => 'Correction reversal: Funds restored to ' . $restoredAccountName,
                    ],
                ])
                : null;

            $corrected = $repository->correctCustomerAdvanceRefund([
                'customer_advance_refund_id' => $refundId,
                'customer_receipt_id' => $receiptId,
                'branch_id' => $branchId,
                'traveler_id' => $travelerId,
                'entry_date' => $entryDate,
                'currency' => $currency,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'reference_number' => $this->optionalText($input['reference_number'] ?? $input['advance_refund_reference_number'] ?? null, 100),
                'reason' => $reason,
                'remarks' => $this->optionalText($input['remarks'] ?? $input['advance_refund_remarks'] ?? null, 4000),
            ]);

            $newJournalId = null;
            if ($amount > 0) {
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
            }
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
                'old_currency' => (string) ($old['currency'] ?? $currency),
                'new_currency' => $currency,
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
        if ((string) ($input['no_money_received_confirmed'] ?? '') !== '1') {
            throw new RuntimeException(
                'Void reverses the recorded cash/bank receipt. Confirm that no money was actually received for this entry. '
                . 'If money was received, correct the receipt instead of voiding it.'
            );
        }

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
            $reversalJournalEntryIds = $accountingRepository->postCorrectedReceiptVoidReversals([
                'branch_id' => (int) ($voidResult['branch_id'] ?? (int) ($booking['branch_id'] ?? 0)),
                'booking_reference' => (string) ($voidResult['booking_reference'] ?? (string) ($booking['booking_reference'] ?? '')),
                'customer_receipt_id' => $receiptId,
                'source_reference' => $reversalReference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) ($voidResult['currency'] ?? (string) ($receipt['currency'] ?? 'PKR')),
                'narration' => 'Customer receipt void reversal for ' . (string) ($voidResult['receipt_no'] ?? $receiptId),
                'actor_user_id' => $actorUserId,
            ]);
            $reversalJournalEntryId = $reversalJournalEntryIds[0] ?? null;

            if ($reversalJournalEntryId !== null && $reversalJournalEntryId > 0) {
                $paymentRepository->attachReceiptReversalJournalEntry($receiptId, $reversalJournalEntryId);
            }

            AuditLog::record($this->app, 'customer.receipt.voided', [
                'user_id' => $actorUserId,
                'customer_receipt_id' => $receiptId,
                'receipt_no' => (string) ($voidResult['receipt_no'] ?? ''),
                'booking_reference' => (string) ($voidResult['booking_reference'] ?? ''),
                'void_reason' => $voidReason,
                'no_money_received_confirmed' => true,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'reversal_journal_entry_ids' => $reversalJournalEntryIds,
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

    public function correctReceiptAmount(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can correct customer receipts.');
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        $reason = $this->requiredVoidReason($input['correction_reason'] ?? null);
        $expectedAmount = $this->nonNegativeMoneyValue($input['expected_received_amount'] ?? '', 'Original amount');
        $correctedAmount = $this->nonNegativeMoneyValue($input['corrected_received_amount'] ?? '', 'Corrected amount');
        $bookings = new BookingRepository($this->app);
        if ($bookingId <= 0 || ! $bookings->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('Booking is outside your accessible branches.');
        }
        $booking = $bookings->findBookingById($bookingId);
        $payments = new CustomerPaymentRepository($this->app);
        $receipt = $payments->findReceiptById($receiptId);
        if ($booking === null || $receipt === null
            || (string) $receipt['booking_reference'] !== (string) $booking['booking_reference']
            || (int) $receipt['branch_id'] !== (int) $booking['branch_id']) {
            throw new RuntimeException('Receipt does not belong to this booking and branch.');
        }
        if ((int) ($receipt['treasury_account_id'] ?? 0) <= 0) {
            throw new RuntimeException('Original cash/bank account is missing. Review this receipt before correcting its amount.');
        }
        $method = (string) ($receipt['payment_method'] ?? '');
        if (! in_array($method, ['cash', 'bank_transfer'], true)) {
            throw new RuntimeException('This amount correction supports cash and bank receipts only. Review other payment methods separately.');
        }
        $treasury = (new TreasuryRepository($this->app))->findAccount(
            (int) $receipt['treasury_account_id'], $accessibleBranchIds
        );
        if ($treasury === null || (int) ($treasury['is_active'] ?? 0) !== 1
            || strtoupper((string) ($treasury['currency'] ?? '')) !== strtoupper((string) $receipt['currency'])
            || (string) ($treasury['account_type'] ?? '') !== ($method === 'cash' ? 'cash' : 'bank')) {
            throw new RuntimeException('Original cash/bank account no longer matches this receipt. Review it before correcting the amount.');
        }
        $db = $this->app->get('db');
        $started = ! $db->inTransaction();
        if ($started) { $db->beginTransaction(); }
        try {
            $amounts = $payments->correctReceiptAmount($receiptId, $expectedAmount, $correctedAmount);
            $reference = 'AMEND-' . (string) $receipt['receipt_no'] . '-' . strtoupper(bin2hex(random_bytes(4)));
            $journalData = [
                'branch_id' => (int) $receipt['branch_id'],
                'booking_branch_id' => (int) $booking['branch_id'],
                'account_branch_id' => (int) $treasury['branch_id'],
                'booking_reference' => (string) $receipt['booking_reference'],
                'customer_receipt_id' => $receiptId,
                'source_reference' => $reference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $receipt['currency'],
                'payment_method' => (string) $receipt['payment_method'],
                'treasury_account_id' => (int) ($receipt['treasury_account_id'] ?? 0),
                'original_amount' => $amounts['before'],
                'corrected_amount' => $amounts['after'],
                'narration' => 'Customer receipt amount corrected: ' . $reason,
                'actor_user_id' => $actorUserId,
            ];
            $accounting = new AccountingRepository($this->app);
            $journalIds = (int) $treasury['branch_id'] === (int) $booking['branch_id']
                ? [$accounting->postCustomerReceiptAmountCorrection($journalData)]
                : $accounting->postCrossBranchCustomerReceiptAmountCorrection($journalData);
            AuditLog::recordRequired($this->app, 'customer.receipt.amount_corrected', [
                'user_id' => $actorUserId, 'booking_id' => $bookingId,
                'booking_reference' => (string) $receipt['booking_reference'],
                'customer_receipt_id' => $receiptId, 'receipt_no' => (string) $receipt['receipt_no'],
                'currency' => (string) $receipt['currency'], 'original_amount' => $amounts['before'],
                'corrected_amount' => $amounts['after'], 'allocated_unchanged' => $amounts['allocated'],
                'unallocated_after' => $amounts['unallocated'], 'journal_entry_id' => $journalIds[0],
                'journal_ids' => $journalIds,
                'reason' => $reason,
            ]);
            if ($started) { $db->commit(); }
            return ['booking_id' => $bookingId, 'receipt_id' => $receiptId, 'journal_entry_id' => $journalIds[0]];
        } catch (\Throwable $exception) {
            if ($started && $db->inTransaction()) { $db->rollBack(); }
            throw $exception;
        }
    }

    public function correctReceiptAccount(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor($actorUserId, 'Only super admin or branch admin can correct customer receipt accounts.');
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        $expectedAccountId = (int) ($input['expected_treasury_account_id'] ?? 0);
        $targetAccountId = (int) ($input['corrected_treasury_account_id'] ?? 0);
        $reason = $this->requiredVoidReason($input['correction_reason'] ?? null);
        $bookings = new BookingRepository($this->app);
        if ($bookingId <= 0 || ! $bookings->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('Booking is outside your accessible branches.');
        }
        $booking = $bookings->findBookingById($bookingId);
        $payments = new CustomerPaymentRepository($this->app);
        $receipt = $payments->findReceiptById($receiptId);
        if ($booking === null || $receipt === null
            || (string) $receipt['booking_reference'] !== (string) $booking['booking_reference']
            || (int) $receipt['branch_id'] !== (int) $booking['branch_id']
            || (string) $receipt['status'] === 'void'
        ) {
            throw new RuntimeException('An active receipt for this booking is required.');
        }
        if ($expectedAccountId <= 0 || $targetAccountId <= 0 || $expectedAccountId === $targetAccountId
            || (int) $receipt['treasury_account_id'] !== $expectedAccountId
        ) {
            throw new RuntimeException('Choose a different account for the current receipt. Reload if its account changed.');
        }
        if (! in_array((string) $receipt['payment_method'], ['cash', 'bank_transfer'], true)
            || round((float) ($receipt['returned_amount'] ?? 0), 2) > 0
        ) {
            throw new RuntimeException('This receipt has a return or unsupported funding method; review it before correcting its account.');
        }
        $treasury = new TreasuryRepository($this->app);
        $source = $treasury->findAccount($expectedAccountId, $accessibleBranchIds);
        $target = $treasury->findAccount($targetAccountId, $accessibleBranchIds);
        if ($source === null || $target === null
            || (int) $source['is_active'] !== 1 || (int) $target['is_active'] !== 1
            || ! in_array((string) $source['account_type'], ['cash', 'bank'], true)
            || ! in_array((string) $target['account_type'], ['cash', 'bank'], true)
            || (string) $source['account_type'] !== ((string) $receipt['payment_method'] === 'cash' ? 'cash' : 'bank')
            || (string) $source['currency'] !== (string) $receipt['currency']
            || (string) $target['currency'] !== (string) $receipt['currency']
        ) {
            throw new RuntimeException('Both accessible accounts must be active cash/bank accounts in the receipt currency.');
        }
        $netCash = round((float) $receipt['received_amount'] - (float) ($receipt['charges_amount'] ?? 0), 2);
        if ($netCash <= 0 || (int) ($source['linked_account_id'] ?? 0) <= 0) {
            throw new RuntimeException('Receipt has no verified cash/bank balance to correct.');
        }
        $db = $this->app->get('db');
        $started = ! $db->inTransaction();
        if ($started) {
            $db->beginTransaction();
        }
        try {
            $accounting = new AccountingRepository($this->app);
            $actualCash = $accounting->receiptAssetBalance(
                $receiptId,
                (int) $source['linked_account_id'],
                (int) $source['branch_id'],
                (string) $receipt['currency']
            );
            if (abs($actualCash - $netCash) > 0.005) {
                throw new RuntimeException('Receipt funding journal does not match the recorded account; review it before correction.');
            }
            $method = (string) $target['account_type'] === 'cash' ? 'cash' : 'bank_transfer';
            $reference = (string) $receipt['receipt_no'] . '-ACCOUNT-CORR-' . strtoupper(bin2hex(random_bytes(4)));
            $journalIds = $accounting->postCustomerReceiptAccountCorrection([
                'booking_reference' => (string) $receipt['booking_reference'],
                'customer_receipt_id' => $receiptId,
                'from_treasury_account_id' => $expectedAccountId,
                'to_treasury_account_id' => $targetAccountId,
                'source_reference' => $reference,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $receipt['currency'],
                'amount' => $netCash,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
            ]);
            $payments->correctReceiptAccount(
                $receiptId,
                $expectedAccountId,
                $targetAccountId,
                $method,
                (int) $receipt['branch_id'],
                (float) $receipt['received_amount'],
                (float) ($receipt['charges_amount'] ?? 0)
            );
            AuditLog::recordRequired($this->app, 'customer.receipt.account_corrected', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $receipt['booking_reference'],
                'customer_receipt_id' => $receiptId,
                'from_treasury_account_id' => $expectedAccountId,
                'to_treasury_account_id' => $targetAccountId,
                'currency' => (string) $receipt['currency'],
                'amount' => $netCash,
                'journal_ids' => $journalIds,
                'reason' => $reason,
            ]);
            if ($started) {
                $db->commit();
            }

            return ['booking_id' => $bookingId, 'receipt_id' => $receiptId, 'journal_ids' => $journalIds];
        } catch (\Throwable $exception) {
            if ($started && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
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

    private function applyBookingCreditToCurrentInvoice(
        array $input,
        array $booking,
        string $currency,
        string $entryDate,
        int $actorUserId,
        CustomerPaymentRepository $paymentRepository,
        AccountingRepository $accountingRepository
    ): array {
        $creditReceiptId = max(0, (int) ($input['customer_credit_receipt_id'] ?? 0));
        $requestedAmount = $this->nonNegativeMoneyValue(
            $input['customer_credit_apply_amount'] ?? 0,
            'Customer credit used'
        );

        if ($creditReceiptId <= 0 || $requestedAmount <= 0.005) {
            return [
                'receipt_id' => $creditReceiptId,
                'source_booking_reference' => '',
                'target_booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'allocated_amount' => 0.00,
                'allocation_count' => 0,
                'allocation_ids' => [],
            ];
        }

        $targetBookingReference = trim((string) ($booking['booking_reference'] ?? ''));
        $branchId = (int) ($booking['branch_id'] ?? 0);
        $travelerId = (int) ($booking['lead_traveler_id'] ?? 0);
        $normalizedCurrency = $this->normalizeCurrency($currency);
        $creditReceipt = $paymentRepository->findReceiptById($creditReceiptId);

        if ($creditReceipt === null) {
            throw new RuntimeException('The selected customer credit could not be found.');
        }

        $sourceBookingReference = trim((string) ($creditReceipt['booking_reference'] ?? ''));
        $receiptPurpose = strtolower(trim((string) ($creditReceipt['receipt_purpose'] ?? 'booking_payment')));
        $receiptStatus = strtolower(str_replace(' ', '_', trim((string) ($creditReceipt['status'] ?? ''))));
        if ($receiptPurpose === 'customer_advance') {
            throw new RuntimeException('Use the Customer Advance field for formal customer advances.');
        }
        if ($sourceBookingReference === '' || strcasecmp($sourceBookingReference, $targetBookingReference) === 0) {
            throw new RuntimeException('Select unused customer credit from another booking.');
        }
        if ($receiptStatus === 'void') {
            throw new RuntimeException('The selected customer credit receipt is void.');
        }
        $sourceBooking = (new BookingRepository($this->app))->findBookingByReference($sourceBookingReference, [$branchId]);
        $sourceTravelerId = (int) ($creditReceipt['traveler_id'] ?? 0);
        if ($sourceTravelerId <= 0) {
            $sourceTravelerId = (int) ($sourceBooking['lead_traveler_id'] ?? 0);
        }
        if ($sourceBooking === null
            || (int) ($sourceBooking['branch_id'] ?? 0) !== (int) ($creditReceipt['branch_id'] ?? 0)
            || (int) ($creditReceipt['branch_id'] ?? 0) !== $branchId
            || $sourceTravelerId <= 0
            || $sourceTravelerId !== $travelerId
            || strtoupper((string) ($creditReceipt['currency'] ?? '')) !== $normalizedCurrency
        ) {
            throw new RuntimeException('The selected credit does not match this booking customer, branch, and currency.');
        }

        $availableCredit = round((float) ($creditReceipt['unallocated_amount'] ?? 0), 2);
        if ($availableCredit <= 0.005) {
            throw new RuntimeException('The selected customer credit has already been used or returned.');
        }
        if ($requestedAmount > $availableCredit + 0.005) {
            throw new RuntimeException('Customer credit used cannot exceed its available balance.');
        }

        $receiptScope = $this->normalizeReceiptScope((string) ($input['receipt_scope'] ?? 'whole_invoice'));
        $targetReceivableId = $this->resolveReceiptAllocationTargetId(
            $input,
            $paymentRepository,
            $booking,
            $normalizedCurrency,
            $receiptScope
        );
        $openReceivables = $this->sameCurrencyBookingReceivables(
            $paymentRepository,
            $booking,
            $normalizedCurrency,
            [],
            $targetReceivableId !== null ? [$targetReceivableId] : null
        );
        $openTotal = round(array_sum(array_map(
            static fn (array $receivable): float => max(0.0, (float) ($receivable['outstanding_amount'] ?? 0)),
            $openReceivables
        )), 2);
        if ($openTotal <= 0.005) {
            throw new RuntimeException('There is no open current invoice balance for this customer credit.');
        }
        if ($requestedAmount > $openTotal + 0.005) {
            throw new RuntimeException('Customer credit used cannot exceed the current invoice balance.');
        }

        $remainingToApply = $requestedAmount;
        $allocatedTotal = 0.00;
        $allocationIds = [];

        foreach ($openReceivables as $receivable) {
            if ($remainingToApply <= 0.005) {
                break;
            }

            $creditReceipt = $paymentRepository->findReceiptById($creditReceiptId);
            if ($creditReceipt === null) {
                throw new RuntimeException('The selected customer credit could not be reloaded.');
            }

            $remainingCredit = round((float) ($creditReceipt['unallocated_amount'] ?? 0), 2);
            $remainingReceivable = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
            $allocationAmount = round(min($remainingToApply, $remainingCredit, $remainingReceivable), 2);
            if ($allocationAmount <= 0.005) {
                continue;
            }

            $allocationResult = $paymentRepository->allocateReceiptExplicit([
                'receipt_id' => $creditReceiptId,
                'receivable_item_id' => (int) $receivable['id'],
                'receivable_amount_to_settle' => $allocationAmount,
                'payment_currency' => $normalizedCurrency,
                'rate_from_currency' => $normalizedCurrency,
                'rate_to_currency' => $normalizedCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $entryDate,
                'allocation_note' => 'Customer credit transferred from ' . $sourceBookingReference
                    . ' to ' . $targetBookingReference . '.',
                'actor_user_id' => $actorUserId,
            ]);

            $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
            $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
            if ($allocationId <= 0 || $allocatedAmount <= 0.005) {
                throw new RuntimeException('Customer credit could not be applied to the invoice.');
            }

            $accountingRepository->postCustomerReceiptAllocation([
                'branch_id' => (int) ($receivable['branch_id'] ?? $branchId),
                'booking_reference' => (string) ($receivable['booking_reference'] ?? $targetBookingReference),
                'source_reference' => (string) ($creditReceipt['receipt_no'] ?? 'CREDIT') . '-XFER-' . $allocationId,
                'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== ''
                    ? (string) $receivable['service_line_reference']
                    : null,
                'customer_receivable_item_id' => (int) $receivable['id'],
                'customer_receipt_id' => $creditReceiptId,
                'allocated_amount' => $allocatedAmount,
                'entry_date' => $entryDate,
                'currency' => $normalizedCurrency,
                'narration' => 'Customer credit transferred from ' . $sourceBookingReference
                    . ' to ' . $targetBookingReference,
                'actor_user_id' => $actorUserId,
            ]);

            $remainingToApply = round(max(0, $remainingToApply - $allocatedAmount), 2);
            $allocatedTotal = round($allocatedTotal + $allocatedAmount, 2);
            $allocationIds[] = $allocationId;
        }

        AuditLog::record($this->app, 'customer.booking_credit.transferred', [
            'user_id' => $actorUserId,
            'customer_receipt_id' => $creditReceiptId,
            'source_booking_reference' => $sourceBookingReference,
            'target_booking_reference' => $targetBookingReference,
            'currency' => $normalizedCurrency,
            'allocated_amount' => $allocatedTotal,
            'allocation_ids' => $allocationIds,
        ]);

        return [
            'receipt_id' => $creditReceiptId,
            'source_booking_reference' => $sourceBookingReference,
            'target_booking_reference' => $targetBookingReference,
            'allocated_amount' => $allocatedTotal,
            'allocation_count' => count($allocationIds),
            'allocation_ids' => $allocationIds,
        ];
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
                $paymentMethod,
                false,
                true,
                true
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

    private function resolveAccountHolderBalanceFunding(
        array $input,
        int $branchId,
        string $targetCurrency,
        float $targetAmount,
        int $fallbackBusinessSourceId = 0
    ): array {
        $mode = str_replace(' ', '_', mb_strtolower(trim((string) ($input['account_balance_mode'] ?? ''))));
        $sourceCurrencyRaw = trim((string) ($input['account_balance_source_currency'] ?? ''));
        if ($mode !== 'account_holder_balance' || $sourceCurrencyRaw === '') {
            return [
                'enabled' => false,
                'business_source_id' => 0,
                'business_source_name' => '',
                'source_currency' => '',
                'source_amount' => 0.0,
                'exchange_rate' => 1.0,
            ];
        }
        $sourceCurrency = $this->normalizeCurrency($sourceCurrencyRaw);

        if ($targetAmount <= 0.005) {
            throw new RuntimeException('Enter a payment amount before using account-holder balance.');
        }

        $businessSourceId = max(0, (int) ($input['account_balance_business_source_id'] ?? 0));
        if ($businessSourceId <= 0) {
            $businessSourceId = $fallbackBusinessSourceId;
        }
        if ($businessSourceId <= 0) {
            throw new RuntimeException('Please select the Account Holder whose balance should fund this payment.');
        }

        $businessSource = (new MasterDataRepository($this->app))->find('business_sources', $businessSourceId);
        if ($businessSource === null || (int) ($businessSource['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('The selected Account Holder is not valid anymore.');
        }

        $exchangeRate = 1.0;
        if ($sourceCurrency !== $targetCurrency) {
            $exchangeRate = $this->nonNegativeMoneyValue(
                $input['account_balance_exchange_rate'] ?? 0,
                'Account-holder FX rate'
            );
            if ($exchangeRate <= 0.00000001) {
                throw new RuntimeException('Account-holder FX rate must be greater than zero.');
            }
        }

        $sourceAmount = $sourceCurrency === $targetCurrency
            ? round($targetAmount, 2)
            : round($targetAmount / $exchangeRate, 2);
        if ($sourceAmount <= 0.005) {
            throw new RuntimeException('Account-holder source amount must be greater than zero.');
        }

        $balanceRepository = new AccountHolderBalanceRepository($this->app);
        $availableAmount = $balanceRepository->availableAmount($branchId, $businessSourceId, $sourceCurrency);
        if ($sourceAmount > $availableAmount + 0.005) {
            throw new RuntimeException(
                'Selected account-holder balance is not enough. Available: '
                . number_format($availableAmount, 2)
                . ' '
                . $sourceCurrency
                . '.'
            );
        }

        return [
            'enabled' => true,
            'business_source_id' => $businessSourceId,
            'business_source_name' => (string) ($businessSource['name'] ?? 'Account Holder'),
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'exchange_rate' => $exchangeRate,
        ];
    }

    private function isDirectSupplierPaymentMethod(string $paymentMethod): bool
    {
        return $paymentMethod === self::DIRECT_SUPPLIER_PAYMENT_METHOD;
    }

    private function resolveDirectSupplierSettlementPlan(
        array $input,
        SupplierRepository $supplierRepository,
        string $bookingReference,
        int $branchId,
        string $currency
    ): array {
        $normalizedCurrency = strtoupper(trim($currency));
        $postedSupplierId = max(0, (int) ($input['direct_supplier_id'] ?? 0));
        if ($postedSupplierId > 0) {
            $supplier = $supplierRepository->findSupplierById($postedSupplierId);
            if ($supplier === null || (int) ($supplier['branch_id'] ?? 0) !== $branchId || (int) ($supplier['is_active'] ?? 1) !== 1) {
                throw new RuntimeException('The selected supplier could not be found in this branch.');
            }

            $obligations = $supplierRepository->globalOpenObligations($branchId, $postedSupplierId, $normalizedCurrency);
            if ($obligations === []) {
                return [
                    'primary_obligation' => [
                        'id' => 0,
                        'supplier_id' => $postedSupplierId,
                        'branch_id' => $branchId,
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => null,
                        'currency' => $normalizedCurrency,
                        'net_payable_amount' => 0.0,
                        'supplier_name' => (string) ($supplier['name'] ?? 'Supplier'),
                    ],
                    'obligations' => [],
                    'total_outstanding' => 0.0,
                ];
            }

            return [
                'primary_obligation' => $obligations[0],
                'obligations' => $obligations,
                'total_outstanding' => round(array_sum(array_map(
                    static fn (array $row): float => max(0.0, (float) ($row['net_payable_amount'] ?? 0)),
                    $obligations
                )), 2),
            ];
        }

        $supplierObligationId = $this->resolveDirectSupplierObligationId(
            $input,
            $supplierRepository,
            $bookingReference,
            $normalizedCurrency
        );
        $obligation = $supplierRepository->findObligationById($supplierObligationId);
        if ($obligation === null) {
            throw new RuntimeException('The selected supplier payable could not be found.');
        }

        if ((int) ($obligation['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('Paid Supplier can settle only supplier payables from the same branch as this customer invoice.');
        }

        $obligationStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($obligation['status'] ?? 'open'))));
        if (! in_array($obligationStatus, ['open', 'partially_covered'], true)) {
            throw new RuntimeException('The selected supplier payable is no longer open.');
        }

        if ((string) ($obligation['currency'] ?? '') !== $normalizedCurrency) {
            throw new RuntimeException('The customer payment currency must match the selected supplier payable currency.');
        }

        return [
            'primary_obligation' => $obligation,
            'obligations' => [$obligation],
            'total_outstanding' => round((float) ($obligation['net_payable_amount'] ?? 0), 2),
        ];
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
                    $exactRate = $exchangeRateRepository->getBookingRate(
                        $bookingId,
                        $settlement['rate_from_currency'],
                        $settlement['rate_to_currency']
                    );
                    $exactRate ??= $exchangeRateRepository->getExactRate(
                        $settlement['rate_from_currency'],
                        $settlement['rate_to_currency'],
                        $settlement['exchange_rate_effective_date']
                    );
                    if ($exactRate === null) {
                        throw new RuntimeException('FX_RATE_EXPIRED_OR_MISSING: Today\'s exchange rate is required.');
                    }

                    $settlement['exchange_rate'] = (float) ($exactRate['exchange_rate'] ?? 0);
                }

                $exchangeRateRepository->upsertBookingRate(
                    $bookingId,
                    (int) $booking['branch_id'],
                    $settlement['rate_from_currency'],
                    $settlement['rate_to_currency'],
                    $settlement['exchange_rate_effective_date'],
                    $settlement['exchange_rate'],
                    $actorUserId
                );
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

                if ($settlement['payment_currency'] !== $settlement['target_currency']) {
                    $settledReceipt = $repository->findReceiptById($receiptId);
                    $fxSurplusAmount = round((float) ($settledReceipt['unallocated_amount'] ?? 0), 2);
                    if ($fxSurplusAmount > 0.005) {
                        (new CustomerReceiptCurrencyCorrectionService($this->app))->recognizeFxGain(
                            $receiptId,
                            $fxSurplusAmount,
                            sprintf(
                                'Automatic foreign-exchange surplus from %s to %s settlement.',
                                $settlement['payment_currency'],
                                $settlement['target_currency']
                            ),
                            $actorUserId,
                            $payload['receipt_date'],
                            true
                        );
                    }
                }
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
        if (abs($exchangeRate - 1.0) <= 0.00000001) {
            throw new RuntimeException('A 1:1 exchange rate cannot be saved for different currencies. Confirm the actual rate.');
        }

        return [
            'booking_id' => $bookingId,
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
        $codes[] = 'account_holder_balance';

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
