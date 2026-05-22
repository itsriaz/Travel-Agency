<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;
use Throwable;

final class ServiceWorkspaceService extends Service
{
    private const ALLOWED_SERVICE_TYPES = ['air ticket', 'visa', 'umrah', 'hotel', 'transport', 'tourism', 'other'];
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_STATUSES = ['Booked', 'Docs Pending', 'Reserved', 'Open', 'Delivered', 'Cancelled'];
    private const ALLOWED_PAYMENT_METHODS = ['cash', 'bank_transfer', 'debit_card', 'credit_card'];

    public function serviceState(?int $bookingId, array $accessibleBranchIds): array
    {
        $services = [];
        $suppliers = [];

        $suppliers = (new SupplierRepository($this->app))->activeSuppliersForBranches($accessibleBranchIds);

        if ($bookingId !== null && $bookingId > 0) {
            $bookingRepository = new BookingRepository($this->app);
            if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected booking is outside your accessible branches.');
            }

            $services = (new BookingServiceRepository($this->app))->servicesForBooking($bookingId);
            $eventRepository = new BookingServiceEventRepository($this->app);
            foreach ($services as &$serviceRow) {
                $serviceId = (int) ($serviceRow['id'] ?? 0);
                if ($serviceId <= 0) {
                    continue;
                }

                $latestRefundEvent = $eventRepository->latestPostedEvent($serviceId, 'refund');
                $serviceRow['latest_refund_event_id'] = (int) ($latestRefundEvent['id'] ?? 0);
                $serviceRow['latest_refund_event_date'] = (string) ($latestRefundEvent['event_date'] ?? '');
                $serviceRow['latest_refund_customer_amount'] = (float) ($latestRefundEvent['customer_refund_amount'] ?? 0);
                $serviceRow['latest_refund_supplier_amount'] = (float) ($latestRefundEvent['supplier_refund_amount'] ?? 0);
            }
            unset($serviceRow);
        }

        return [
            'services' => $services,
            'supplierOptions' => $suppliers,
        ];
    }

    public function saveService(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $repository = new BookingServiceRepository($this->app);
        $payload = $this->validatedPayload($input, $accessibleBranchIds, (int) $booking['branch_id'], $bookingId, $actorUserId, $repository);

        if ($serviceId > 0) {
            if (! $repository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected service line is outside your accessible branches.');
            }

            $existingService = $repository->findServiceById($serviceId);
            if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
                throw new RuntimeException('The selected service line does not belong to this booking.');
            }

            $repository->updateService(
                $serviceId,
                array_merge($payload['master'], ['actor_user_id' => $actorUserId]),
                $payload['subtype']
            );
            (new CommercialObligationSyncService($this->app))->syncForServiceId($serviceId, $actorUserId);
            $savedService = $repository->findServiceById($serviceId);

            AuditLog::record($this->app, 'service.updated', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $booking['booking_reference'],
                'service_id' => $serviceId,
                'service_type' => $payload['master']['service_type'],
                'currency' => $payload['master']['currency'],
            ]);

            return [
                'service' => $savedService,
                'action' => 'updated',
                'booking_id' => $bookingId,
                'debug' => [
                    'resolvedPayload' => $payload,
                    'savedService' => $savedService,
                ],
            ];
        }

        $newServiceId = $repository->createService(
            array_merge($payload['master'], [
                'booking_id' => $bookingId,
                'branch_id' => (int) $booking['branch_id'],
                'actor_user_id' => $actorUserId,
            ]),
            $payload['subtype']
        );
        (new CommercialObligationSyncService($this->app))->syncForServiceId($newServiceId, $actorUserId);
        $savedService = $repository->findServiceById($newServiceId);

        AuditLog::record($this->app, 'service.created', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'service_id' => $newServiceId,
            'service_type' => $payload['master']['service_type'],
            'currency' => $payload['master']['currency'],
        ]);

        return [
            'service' => $savedService,
            'action' => 'created',
            'booking_id' => $bookingId,
            'debug' => [
                'resolvedPayload' => $payload,
                'savedService' => $savedService,
            ],
        ];
    }

    public function deactivateService(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot deactivate services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be deactivated.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $serviceRepository->deactivateService($serviceId, $actorUserId);
            (new CommercialObligationSyncService($this->app))->syncForServiceId($serviceId, $actorUserId);

            AuditLog::record($this->app, 'service.deactivated', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $booking['booking_reference'],
                'service_id' => $serviceId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        return $bookingId;
    }

    public function cancelService(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['cancel_reason'] ?? null, 1000, 'Cancellation reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['cancel_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot cancel services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be cancelled.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        if ((int) ($existingService['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Inactive service lines cannot be cancelled here.');
        }

        if ((string) ($existingService['service_status'] ?? '') === 'Cancelled') {
            throw new RuntimeException('This service line is already cancelled.');
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        if ($eventRepository->postedEventExists($serviceId, 'cancel')) {
            throw new RuntimeException('A posted cancellation already exists for this service line.');
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $eventId = $eventRepository->createEvent([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_id' => $bookingId,
                'booking_service_id' => $serviceId,
                'booking_reference' => (string) $booking['booking_reference'],
                'service_line_reference' => (string) $existingService['line_reference'],
                'event_type' => 'cancel',
                'event_status' => 'posted',
                'event_date' => $eventDate,
                'currency' => (string) ($existingService['currency'] ?? 'PKR'),
                'original_ticket_number' => $existingService['ticket_number'] ?? null,
                'original_pnr' => $existingService['pnr'] ?? null,
                'reason' => $reason,
                'notes' => $this->optionalText($input['cancel_notes'] ?? null, 4000),
                'payload_json' => [
                    'mode' => 'operational_cancel_only',
                    'financial_effect_pending' => true,
                    'prior_service_status' => (string) ($existingService['service_status'] ?? ''),
                ],
                'actor_user_id' => $actorUserId,
            ]);

            $serviceRepository->updateServiceStatus($serviceId, 'Cancelled', $actorUserId);

            AuditLog::record($this->app, 'service.cancelled', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $booking['booking_reference'],
                'service_id' => $serviceId,
                'service_event_id' => $eventId,
                'service_line_reference' => (string) $existingService['line_reference'],
                'reason' => $reason,
                'financial_effect_pending' => true,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        return $bookingId;
    }

    public function refundService(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['refund_reason'] ?? null, 1000, 'Refund reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['refund_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $paymentMethod = $this->normalizePaymentMethod((string) ($input['refund_payment_method'] ?? 'bank_transfer'));
        $customerRefundAmount = $this->moneyValue($input['customer_refund_amount'] ?? 0);
        $supplierRefundAmount = $this->moneyValue($input['supplier_refund_amount'] ?? 0);

        if ($customerRefundAmount <= 0 && $supplierRefundAmount <= 0) {
            throw new RuntimeException('Enter a customer refund or supplier refund amount.');
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot refund services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be refunded.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        $bookingReference = (string) $booking['booking_reference'];
        $currency = (string) ($existingService['currency'] ?? 'PKR');

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        $accountingRepository = new AccountingRepository($this->app);

        try {
            $customerCreditBalance = $accountingRepository->accountNetBalanceForBooking($bookingReference, $currency, 'CUSTOMER_CREDIT');
            $supplierAdvanceBalance = $accountingRepository->accountNetBalanceForBooking($bookingReference, $currency, 'SUPPLIER_ADVANCES');

            if ($customerRefundAmount > $customerCreditBalance + 0.005) {
                throw new RuntimeException('Customer refund exceeds available customer credit for this booking and currency.');
            }

            if ($supplierRefundAmount > $supplierAdvanceBalance + 0.005) {
                throw new RuntimeException('Supplier refund exceeds available supplier advance/credit for this booking and currency.');
            }

            $eventRepository = new BookingServiceEventRepository($this->app);
            $eventId = $eventRepository->createEvent([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_id' => $bookingId,
                'booking_service_id' => $serviceId,
                'booking_reference' => $bookingReference,
                'service_line_reference' => (string) $existingService['line_reference'],
                'event_type' => 'refund',
                'event_status' => 'posted',
                'event_date' => $eventDate,
                'currency' => $currency,
                'original_ticket_number' => $existingService['ticket_number'] ?? null,
                'original_pnr' => $existingService['pnr'] ?? null,
                'customer_refund_amount' => $customerRefundAmount,
                'supplier_refund_amount' => $supplierRefundAmount,
                'reason' => $reason,
                'notes' => $this->optionalText($input['refund_notes'] ?? null, 4000),
                'payload_json' => [
                    'payment_method' => $paymentMethod,
                    'customer_credit_balance_before' => $customerCreditBalance,
                    'supplier_advance_balance_before' => $supplierAdvanceBalance,
                ],
                'actor_user_id' => $actorUserId,
            ]);

            $journalEntryId = $accountingRepository->postServiceRefund([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_reference' => $bookingReference,
                'service_line_reference' => (string) $existingService['line_reference'],
                'source_reference' => 'REFUND-EVT-' . $eventId,
                'entry_date' => $eventDate,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'customer_refund_amount' => $customerRefundAmount,
                'supplier_refund_amount' => $supplierRefundAmount,
                'narration' => 'Service refund posted for ' . (string) $existingService['line_reference'],
                'actor_user_id' => $actorUserId,
            ]);
            $eventRepository->attachJournalEntry($eventId, $journalEntryId);

            AuditLog::record($this->app, 'service.refund.posted', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_event_id' => $eventId,
                'journal_entry_id' => $journalEntryId,
                'service_line_reference' => (string) $existingService['line_reference'],
                'currency' => $currency,
                'customer_refund_amount' => $customerRefundAmount,
                'supplier_refund_amount' => $supplierRefundAmount,
                'payment_method' => $paymentMethod,
                'reason' => $reason,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        return $bookingId;
    }

    public function settleCancellationFinancials(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['settlement_reason'] ?? null, 1000, 'Cancellation settlement reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['settlement_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $customerPenaltyAmount = $this->moneyValue($input['customer_penalty_amount'] ?? 0);
        $supplierPenaltyAmount = $this->moneyValue($input['supplier_penalty_amount'] ?? 0);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot settle cancellations for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be settled.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        if ((string) ($existingService['service_status'] ?? '') !== 'Cancelled') {
            throw new RuntimeException('Cancel the service line before settling cancellation financials.');
        }

        $bookingReference = (string) $booking['booking_reference'];
        $lineReference = (string) $existingService['line_reference'];
        $currency = (string) ($existingService['currency'] ?? 'PKR');
        $customerRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);

        $allocatedCustomerAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);

        $supplierSettledAmount = 0.0;
        if ($obligation !== null) {
            $supplierSettledAmount = round((float) ($obligation['gross_amount'] ?? 0) - (float) ($obligation['net_payable_amount'] ?? 0), 2);
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $accountingRepository = new AccountingRepository($this->app);
            $releasedCustomerCredit = 0.0;
            $releasedSupplierCredit = 0.0;
            if ($receivable !== null && $customerPenaltyAmount < $allocatedCustomerAmount - 0.005) {
                $release = $customerRepository->releaseAllocatedCreditForReceivable(
                    (int) $receivable['id'],
                    $customerPenaltyAmount,
                    $actorUserId,
                    'Cancellation settlement release for ' . $lineReference
                );
                $releasedCustomerCredit = round((float) ($release['released_payment_amount'] ?? 0), 2);
            }
            if ($obligation !== null && $supplierPenaltyAmount < $supplierSettledAmount - 0.005) {
                $release = $supplierRepository->releaseSettledCreditForObligation(
                    (int) $obligation['id'],
                    $supplierPenaltyAmount,
                    $actorUserId,
                    'Cancellation settlement release for ' . $lineReference
                );
                $releasedSupplierCredit = round((float) ($release['released_amount'] ?? 0), 2);
            }

            $customerSync = $customerRepository->syncReceivableItem([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'due_group' => 'service_sale',
                'currency' => $currency,
                'due_amount' => $customerPenaltyAmount,
                'due_date' => $existingService['due_date'] ?? null,
                'status' => $customerPenaltyAmount > 0 ? 'open' : 'cancelled',
                'remarks' => 'Cancellation settlement customer penalty [' . $lineReference . ']',
                'actor_user_id' => $actorUserId,
            ]);

            $supplierSync = null;
            if (! empty($existingService['supplier_id']) || $supplierPenaltyAmount <= 0) {
                $supplierSync = $supplierRepository->syncObligation([
                    'supplier_id' => ! empty($existingService['supplier_id']) ? (int) $existingService['supplier_id'] : null,
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'obligation_group' => 'service_cost',
                    'currency' => $currency,
                    'gross_amount' => $supplierPenaltyAmount,
                    'due_date' => $existingService['due_date'] ?? null,
                    'remarks' => 'Cancellation settlement supplier penalty [' . $lineReference . ']',
                    'actor_user_id' => $actorUserId,
                ]);
            } else {
                throw new RuntimeException('Supplier is required when supplier cancellation penalty is greater than zero.');
            }

            $journalIds = [];
            $customerDelta = round((float) ($customerSync['delta_amount'] ?? 0), 2);
            if ($customerDelta !== 0.0) {
                $journalIds[] = $accountingRepository->postReceivableAdjusted([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'customer_receivable_item_id' => $customerSync['record']['id'] ?? null,
                    'adjustment_amount' => $customerDelta,
                    'entry_date' => $eventDate,
                    'currency' => $currency,
                    'narration' => 'Cancellation customer penalty adjustment for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            if ($releasedCustomerCredit > 0.0) {
                $journalIds[] = $accountingRepository->postCustomerReceiptAllocationRelease([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'customer_receivable_item_id' => $customerSync['record']['id'] ?? null,
                    'released_amount' => $releasedCustomerCredit,
                    'source_reference' => $lineReference . '-CANCEL-RELEASE',
                    'entry_date' => $eventDate,
                    'currency' => $currency,
                    'narration' => 'Cancellation settlement released customer credit for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $supplierDelta = round((float) ($supplierSync['delta_amount'] ?? 0), 2);
            if ($releasedSupplierCredit > 0.0) {
                $journalIds[] = $accountingRepository->postSupplierSettlementRelease([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'supplier_obligation_id' => $supplierSync['record']['id'] ?? null,
                    'released_amount' => $releasedSupplierCredit,
                    'source_reference' => $lineReference . '-SUPPLIER-CANCEL-RELEASE',
                    'entry_date' => $eventDate,
                    'currency' => $currency,
                    'narration' => 'Cancellation settlement released supplier credit for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);
            }
            if ($supplierDelta !== 0.0) {
                $journalIds[] = $accountingRepository->postPayableAdjusted([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'supplier_obligation_id' => $supplierSync['record']['id'] ?? null,
                    'adjustment_amount' => $supplierDelta,
                    'entry_date' => $eventDate,
                    'currency' => $currency,
                    'narration' => 'Cancellation supplier penalty adjustment for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $eventRepository = new BookingServiceEventRepository($this->app);
            $event = $eventRepository->latestPostedEvent($serviceId, 'cancel');
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId <= 0) {
                $eventId = $eventRepository->createEvent([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_id' => $bookingId,
                    'booking_service_id' => $serviceId,
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'event_type' => 'cancel',
                    'event_status' => 'posted',
                    'event_date' => $eventDate,
                    'currency' => $currency,
                    'original_ticket_number' => $existingService['ticket_number'] ?? null,
                    'original_pnr' => $existingService['pnr'] ?? null,
                    'reason' => $reason,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $eventRepository->updateCancellationFinancials($eventId, [
                'penalty_amount' => $customerPenaltyAmount,
                'customer_credit_amount' => $releasedCustomerCredit,
                'supplier_credit_amount' => $releasedSupplierCredit,
                'journal_entry_id' => $journalIds[0] ?? null,
                'reason' => $reason,
                'notes' => $this->optionalText($input['settlement_notes'] ?? null, 4000),
                'payload_json' => [
                    'customer_penalty_amount' => $customerPenaltyAmount,
                    'supplier_penalty_amount' => $supplierPenaltyAmount,
                    'released_customer_credit' => $releasedCustomerCredit,
                    'released_supplier_credit' => $releasedSupplierCredit,
                    'customer_delta' => $customerDelta,
                    'supplier_delta' => $supplierDelta,
                    'journal_entry_ids' => $journalIds,
                    'mode' => 'cancellation_financial_adjustment',
                ],
            ]);

            AuditLog::record($this->app, 'service.cancellation_financials.settled', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_event_id' => $eventId,
                'service_line_reference' => $lineReference,
                'currency' => $currency,
                'customer_penalty_amount' => $customerPenaltyAmount,
                'supplier_penalty_amount' => $supplierPenaltyAmount,
                'released_customer_credit' => $releasedCustomerCredit,
                'released_supplier_credit' => $releasedSupplierCredit,
                'customer_delta' => $customerDelta,
                'supplier_delta' => $supplierDelta,
                'journal_entry_ids' => $journalIds,
                'reason' => $reason,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        return $bookingId;
    }

    public function reissueService(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['reissue_reason'] ?? null, 1000, 'Reissue reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['reissue_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $newTicketNumber = $this->requiredText($input['new_ticket_number'] ?? null, 50, 'New ticket number is required.');
        $newPnr = $this->optionalText($input['new_pnr'] ?? null, 50);
        $fareDifferenceAmount = $this->moneyValue($input['fare_difference_amount'] ?? 0);
        $serviceFeeAmount = $this->moneyValue($input['reissue_service_fee_amount'] ?? 0);
        $supplierCostDifferenceAmount = $this->moneyValue($input['supplier_cost_difference_amount'] ?? 0);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot reissue services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be reissued.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        if ((string) ($existingService['service_type'] ?? '') !== 'air ticket') {
            throw new RuntimeException('Only air ticket service lines can be reissued.');
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $bookingReference = (string) $booking['booking_reference'];
            $lineReference = (string) $existingService['line_reference'];
            $currency = (string) ($existingService['currency'] ?? 'PKR');
            $customerDelta = round($fareDifferenceAmount + $serviceFeeAmount, 2);
            $supplierDelta = $supplierCostDifferenceAmount;
            $accountingRepository = new AccountingRepository($this->app);
            $customerRepository = new CustomerPaymentRepository($this->app);
            $supplierRepository = new SupplierRepository($this->app);

            $eventRepository = new BookingServiceEventRepository($this->app);
            $eventId = $eventRepository->createEvent([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_id' => $bookingId,
                'booking_service_id' => $serviceId,
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'event_type' => 'reissue',
                'event_status' => 'posted',
                'event_date' => $eventDate,
                'currency' => $currency,
                'original_ticket_number' => $existingService['ticket_number'] ?? null,
                'new_ticket_number' => $newTicketNumber,
                'original_pnr' => $existingService['pnr'] ?? null,
                'new_pnr' => $newPnr,
                'fare_difference_amount' => $fareDifferenceAmount,
                'service_fee_amount' => $serviceFeeAmount,
                'reason' => $reason,
                'notes' => $this->optionalText($input['reissue_notes'] ?? null, 4000),
                'payload_json' => [
                    'customer_delta' => $customerDelta,
                    'supplier_delta' => $supplierDelta,
                    'supplier_cost_difference_amount' => $supplierCostDifferenceAmount,
                ],
                'actor_user_id' => $actorUserId,
            ]);

            $journalIds = [];
            if ($customerDelta > 0) {
                $receivable = $customerRepository->syncReceivableItem([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'due_group' => 'service_sale',
                    'currency' => $currency,
                    'due_amount' => round((float) ($existingService['final_sale_price'] ?? 0) + $customerDelta, 2),
                    'due_date' => $existingService['due_date'] ?? null,
                    'status' => 'open',
                    'remarks' => 'Reissue customer difference [' . $lineReference . ']',
                    'actor_user_id' => $actorUserId,
                ]);
                $delta = round((float) ($receivable['delta_amount'] ?? 0), 2);
                if ($delta !== 0.0) {
                    $journalIds[] = $accountingRepository->postReceivableAdjusted([
                        'branch_id' => (int) $existingService['branch_id'],
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => $lineReference,
                        'customer_receivable_item_id' => $receivable['record']['id'] ?? null,
                        'adjustment_amount' => $delta,
                        'entry_date' => $eventDate,
                        'currency' => $currency,
                        'narration' => 'Reissue customer difference for ' . $lineReference,
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            if ($supplierDelta > 0) {
                if (empty($existingService['supplier_id'])) {
                    throw new RuntimeException('Supplier is required when supplier reissue cost difference is greater than zero.');
                }

                $obligation = $supplierRepository->syncObligation([
                    'supplier_id' => (int) $existingService['supplier_id'],
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'obligation_group' => 'service_cost',
                    'currency' => $currency,
                    'gross_amount' => round((float) ($existingService['purchase_cost'] ?? 0) + $supplierDelta, 2),
                    'due_date' => $existingService['due_date'] ?? null,
                    'remarks' => 'Reissue supplier difference [' . $lineReference . ']',
                    'actor_user_id' => $actorUserId,
                ]);
                $delta = round((float) ($obligation['delta_amount'] ?? 0), 2);
                if ($delta !== 0.0) {
                    $journalIds[] = $accountingRepository->postPayableAdjusted([
                        'branch_id' => (int) $existingService['branch_id'],
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => $lineReference,
                        'supplier_obligation_id' => $obligation['record']['id'] ?? null,
                        'adjustment_amount' => $delta,
                        'entry_date' => $eventDate,
                        'currency' => $currency,
                        'narration' => 'Reissue supplier difference for ' . $lineReference,
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            if ($journalIds !== []) {
                $eventRepository->attachJournalEntry($eventId, (int) $journalIds[0]);
            }

            $serviceRepository->updateAirTicketReissueDetails($serviceId, $newTicketNumber, $newPnr, $actorUserId);

            AuditLog::record($this->app, 'service.reissued', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_event_id' => $eventId,
                'service_line_reference' => $lineReference,
                'old_ticket_number' => (string) ($existingService['ticket_number'] ?? ''),
                'new_ticket_number' => $newTicketNumber,
                'old_pnr' => (string) ($existingService['pnr'] ?? ''),
                'new_pnr' => (string) ($newPnr ?? ''),
                'customer_delta' => $customerDelta,
                'supplier_delta' => $supplierDelta,
                'journal_entry_ids' => $journalIds,
                'reason' => $reason,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        return $bookingId;
    }

    private function validatedPayload(array $input, array $accessibleBranchIds, int $defaultBranchId, int $bookingId, int $actorUserId, BookingServiceRepository $repository): array
    {
        $serviceType = $this->normalizeServiceType((string) ($input['service_type'] ?? 'air ticket'));
        $currency = $this->normalizeCurrency((string) ($input['currency'] ?? 'PKR'));
        $status = $this->normalizeStatus((string) ($input['service_status'] ?? 'Open'));
        $supplier = $this->resolveSupplier(
            (string) ($input['supplier_name'] ?? ''),
            $accessibleBranchIds,
            $defaultBranchId,
            $currency,
            $actorUserId
        );
        $passenger = $this->resolvePassenger($input, $bookingId, $defaultBranchId, $actorUserId, $repository);

        $master = [
            'branch_id' => $defaultBranchId,
            'service_type' => $serviceType,
            'supplier_id' => $supplier['supplier_id'],
            'supplier_name_snapshot' => $supplier['supplier_name_snapshot'],
            'traveler_id' => $passenger['traveler_id'],
            'passenger_name_snapshot' => $passenger['passenger_name_snapshot'],
            'currency' => $currency,
            'sale_price' => $this->moneyValue($input['sale_price'] ?? 0),
            'purchase_cost' => $this->moneyValue($input['purchase_cost'] ?? 0),
            'taxes' => $this->moneyValue($input['taxes'] ?? 0),
            'other_fare' => $this->moneyValue($input['other_fare'] ?? 0),
            'soto_fare' => $this->moneyValue($input['soto_fare'] ?? 0),
            'spyi_amount' => $this->moneyValue($input['spyi_amount'] ?? 0),
            'aq_yr_pk_amount' => $this->moneyValue($input['aq_yr_pk_amount'] ?? 0),
            'yq_amount' => $this->moneyValue($input['yq_amount'] ?? 0),
            'oth_amount' => $this->moneyValue($input['oth_amount'] ?? 0),
            'vat_input' => $this->moneyValue($input['vat_input'] ?? 0),
            'vat' => $this->moneyValue($input['vat'] ?? 0),
            'commission' => $this->moneyValue($input['commission'] ?? 0),
            'service_charge' => $this->moneyValue($input['service_charge'] ?? 0),
            'discount_amount' => $this->moneyValue($input['discount_amount'] ?? 0),
            'due_date' => $this->normalizeOptionalDate((string) ($input['due_date'] ?? '')),
            'service_status' => $status,
            'remarks' => $this->optionalText($input['remarks'] ?? null, 4000),
        ];

        if ($serviceType === 'air ticket') {
            $master['purchase_cost'] = $this->airTicketPayableAmount($master);
        }

        $master['final_sale_price'] = $this->finalSalePriceAmount($master, $input);
        $master['net_profit_loss'] = $this->profitAmount($master);

        $profit = $master['net_profit_loss'];
        $lossReason = $this->optionalText($input['loss_reason'] ?? null, 4000);
        if ($profit < 0 && $lossReason === null) {
            throw new RuntimeException('Reason for loss is required when a service line is sold below cost.');
        }
        if ($profit >= 0) {
            $lossReason = null;
        }

        return [
            'master' => [
                'branch_id' => $master['branch_id'],
                'service_type' => $master['service_type'],
                'supplier_id' => $master['supplier_id'],
                'supplier_name_snapshot' => $master['supplier_name_snapshot'],
                'traveler_id' => $master['traveler_id'],
                'passenger_name_snapshot' => $master['passenger_name_snapshot'],
                'currency' => $master['currency'],
                'sale_price' => $master['sale_price'],
                'purchase_cost' => $master['purchase_cost'],
                'taxes' => $master['taxes'],
                'other_fare' => $master['other_fare'],
                'soto_fare' => $master['soto_fare'],
                'spyi_amount' => $master['spyi_amount'],
                'aq_yr_pk_amount' => $master['aq_yr_pk_amount'],
                'yq_amount' => $master['yq_amount'],
                'oth_amount' => $master['oth_amount'],
                'vat_input' => $master['vat_input'],
                'vat' => $master['vat'],
                'commission' => $master['commission'],
                'service_charge' => $master['service_charge'],
                'discount_amount' => $master['discount_amount'],
                'final_sale_price' => $master['final_sale_price'],
                'net_profit_loss' => $master['net_profit_loss'],
                'due_date' => $master['due_date'],
                'service_status' => $master['service_status'],
                'remarks' => $master['remarks'],
                'loss_reason' => $lossReason,
                'loss_reason_recorded_at' => $lossReason !== null ? date('Y-m-d H:i:s') : null,
            ],
            'subtype' => $this->validatedSubtypePayload($input, $serviceType, $master),
        ];
    }

    private function validatedSubtypePayload(array $input, string $serviceType, array $master): array
    {
        return match ($serviceType) {
            'air ticket' => $this->validatedAirTicketPayload($input, $serviceType, $master),
            'visa' => [
                'visa_country' => $this->optionalText($input['visa_country'] ?? null, 120),
                'visa_type' => $this->optionalText($input['visa_type'] ?? null, 120),
                'application_reference' => $this->optionalText($input['visa_application_reference'] ?? null, 120),
                'passport_number' => $this->optionalText($input['visa_passport_number'] ?? null, 80),
                'submission_date' => $this->normalizeOptionalDate((string) ($input['visa_submission_date'] ?? '')),
                'issue_date' => $this->normalizeOptionalDate((string) ($input['visa_issue_date'] ?? '')),
                'expiry_date' => $this->normalizeOptionalDate((string) ($input['visa_expiry_date'] ?? '')),
                'visa_status' => $this->optionalText($input['visa_status'] ?? null, 80),
                'remarks' => $this->optionalText($input['visa_remarks'] ?? null, 4000),
            ],
            'umrah' => [
                'package_name' => $this->optionalText($input['umrah_package_name'] ?? null, 190),
                'mofa_reference' => $this->optionalText($input['umrah_mofa_reference'] ?? null, 120),
                'departure_date' => $this->normalizeOptionalDate((string) ($input['umrah_departure_date'] ?? '')),
                'return_date' => $this->normalizeOptionalDate((string) ($input['umrah_return_date'] ?? '')),
                'hotel_name' => $this->optionalText($input['umrah_hotel_name'] ?? null, 190),
                'transport_notes' => $this->optionalText($input['umrah_transport_notes'] ?? null, 255),
                'remarks' => $this->optionalText($input['umrah_remarks'] ?? null, 4000),
            ],
            'hotel' => [
                'hotel_name' => $this->optionalText($input['hotel_name'] ?? null, 190),
                'city' => $this->optionalText($input['hotel_city'] ?? null, 120),
                'confirmation_number' => $this->optionalText($input['hotel_confirmation_number'] ?? null, 120),
                'check_in_date' => $this->normalizeOptionalDate((string) ($input['hotel_check_in_date'] ?? '')),
                'check_out_date' => $this->normalizeOptionalDate((string) ($input['hotel_check_out_date'] ?? '')),
                'room_type' => $this->optionalText($input['hotel_room_type'] ?? null, 120),
                'guest_count' => $this->positiveIntegerOrNull($input['hotel_guest_count'] ?? null),
                'remarks' => $this->optionalText($input['hotel_remarks'] ?? null, 4000),
            ],
            'transport' => [
                'transport_mode' => $this->optionalText($input['transport_mode'] ?? null, 120),
                'vehicle_type' => $this->optionalText($input['transport_vehicle_type'] ?? null, 120),
                'pickup_date' => $this->normalizeOptionalDate((string) ($input['transport_pickup_date'] ?? '')),
                'pickup_location' => $this->optionalText($input['transport_pickup_location'] ?? null, 190),
                'dropoff_location' => $this->optionalText($input['transport_dropoff_location'] ?? null, 190),
                'driver_detail' => $this->optionalText($input['transport_driver_detail'] ?? null, 190),
                'route_notes' => $this->optionalText($input['transport_route_notes'] ?? null, 190),
                'remarks' => $this->optionalText($input['transport_remarks'] ?? null, 4000),
            ],
            'tourism' => [
                'tour_name' => $this->optionalText($input['tour_name'] ?? null, 190),
                'destination' => $this->optionalText($input['tour_destination'] ?? null, 120),
                'confirmation_number' => $this->optionalText($input['tour_confirmation_number'] ?? null, 120),
                'start_date' => $this->normalizeOptionalDate((string) ($input['tour_start_date'] ?? '')),
                'end_date' => $this->normalizeOptionalDate((string) ($input['tour_end_date'] ?? '')),
                'inclusions' => $this->optionalText($input['tour_inclusions'] ?? null, 255),
                'remarks' => $this->optionalText($input['tour_remarks'] ?? null, 4000),
            ],
            default => [
                'label' => $this->optionalText($input['other_label'] ?? null, 190),
                'reference_number' => $this->optionalText($input['other_reference_number'] ?? null, 120),
                'service_date' => $this->normalizeOptionalDate((string) ($input['other_service_date'] ?? '')),
                'provider_name' => $this->optionalText($input['other_provider_name'] ?? null, 190),
                'remarks' => $this->optionalText($input['other_remarks'] ?? null, 4000),
            ],
        };
    }

    private function validatedAirTicketPayload(array $input, string $serviceType, array $master): array
    {
        $departureDate = $this->normalizeOptionalDate((string) ($input['ticket_departure_date'] ?? ''));
        $returnDate = $this->normalizeOptionalDate((string) ($input['ticket_return_date'] ?? ''));

        if ($serviceType === 'air ticket' && $departureDate !== null && $returnDate !== null && $returnDate < $departureDate) {
            throw new RuntimeException('Air ticket return date cannot be earlier than departure date.');
        }

        return [
            'pnr' => $this->optionalText($input['ticket_pnr'] ?? null, 50),
            'ticket_number' => $this->optionalText($input['ticket_number'] ?? null, 50),
            'airline' => $this->optionalText($input['ticket_airline'] ?? null, 120),
            'sector_from' => $this->optionalText($input['ticket_sector_from'] ?? null, 120),
            'sector_to' => $this->optionalText($input['ticket_sector_to'] ?? null, 120),
            'departure_date' => $departureDate,
            'return_date' => $returnDate,
            'travel_class' => $this->optionalText($input['ticket_class'] ?? null, 50),
            'fare' => $this->moneyValue($input['ticket_fare'] ?? 0),
            'ticket_tax' => $this->moneyValue($input['ticket_tax'] ?? 0),
            'ticket_vat' => $this->moneyValue($input['ticket_vat'] ?? 0),
            'ticket_commission' => $this->moneyValue($input['ticket_commission'] ?? 0),
            // Air-ticket subtype keeps mirrored commercial values so ticket reports
            // and the booking ledger share one financial truth.
            'supplier_cost' => (float) ($master['purchase_cost'] ?? 0),
            'sale_amount' => $serviceType === 'air ticket'
                ? (float) ($master['final_sale_price'] ?? 0)
                : (float) ($master['sale_price'] ?? 0),
            'ticket_remarks' => $this->optionalText($input['ticket_remarks'] ?? null, 4000),
        ];
    }

    private function finalSalePriceAmount(array $master, array $input): float
    {
        $rawFinalSalePrice = $input['final_sale_price'] ?? null;
        if ($rawFinalSalePrice !== null && trim((string) $rawFinalSalePrice) !== '') {
            return $this->moneyValue($rawFinalSalePrice);
        }

        if (($master['service_type'] ?? '') === 'air ticket') {
            return round(
                (float) ($master['purchase_cost'] ?? 0)
                + (float) ($master['service_charge'] ?? 0)
                + (float) ($master['vat'] ?? 0)
                - (float) ($master['discount_amount'] ?? 0),
                2
            );
        }

        return round(
            (float) ($master['sale_price'] ?? 0)
            + (float) ($master['service_charge'] ?? 0)
            + (float) ($master['vat'] ?? 0)
            - (float) ($master['discount_amount'] ?? 0),
            2
        );
    }

    private function airTicketPayableAmount(array $master): float
    {
        return round(
            (float) ($master['sale_price'] ?? 0)
            + (float) ($master['spyi_amount'] ?? 0)
            + (float) ($master['aq_yr_pk_amount'] ?? 0)
            + (float) ($master['yq_amount'] ?? 0)
            + (float) ($master['oth_amount'] ?? 0)
            + (float) ($master['vat_input'] ?? 0)
            + (float) ($master['taxes'] ?? 0),
            2
        );
    }

    private function profitAmount(array $master): float
    {
        return round(
            (float) ($master['final_sale_price'] ?? 0)
            - (float) ($master['purchase_cost'] ?? 0),
            2
        );
    }

    private function resolveSupplier(
        string $supplierName,
        array $accessibleBranchIds,
        int $branchId,
        string $currency,
        int $actorUserId
    ): array
    {
        $name = trim($supplierName);
        if ($name === '') {
            return [
                'supplier_id' => null,
                'supplier_name_snapshot' => null,
            ];
        }

        $supplier = (new SupplierRepository($this->app))->findAccessibleSupplierByName($name, $accessibleBranchIds);
        if ($supplier === null) {
            $supplierRepository = new SupplierRepository($this->app);
            $supplierId = $supplierRepository->registerSupplier([
                'branch_id' => $branchId,
                'code' => $supplierRepository->nextSupplierCode(),
                'name' => $name,
                'supplier_mode' => 'normal_payable',
                'default_currency' => $currency,
                'notes' => 'Auto-created from invoice service entry.',
                'actor_user_id' => $actorUserId,
            ]);
            $supplier = $supplierRepository->findSupplierById($supplierId);
        }

        return [
            'supplier_id' => $supplier['id'] ?? null,
            'supplier_name_snapshot' => $name,
        ];
    }

    private function resolvePassenger(array $input, int $bookingId, int $branchId, int $actorUserId, BookingServiceRepository $repository): array
    {
        $travelerId = (int) ($input['service_traveler_id'] ?? 0);
        $passengerName = trim((string) ($input['service_passenger_name'] ?? ''));
        $travelerRepository = new TravelerRepository($this->app);

        if ($travelerId > 0) {
            $traveler = $repository->travelerForBooking($bookingId, $travelerId);
            if ($traveler === null) {
                $existingTraveler = $travelerRepository->findTravelerById($travelerId);
                if ($existingTraveler === null) {
                    throw new RuntimeException('Please select a valid passenger for this invoice.');
                }

                $role = $travelerRepository->bookingHasTravelers($bookingId) ? 'additional' : 'lead';
                $travelerRepository->attachTravelerToBooking($bookingId, $travelerId, $role, $actorUserId);
                $traveler = $repository->travelerForBooking($bookingId, $travelerId);
            }

            if ($traveler === null) {
                throw new RuntimeException('Please select a passenger attached to this invoice.');
            }

            return [
                'traveler_id' => $travelerId,
                'passenger_name_snapshot' => (string) $traveler['full_name'],
            ];
        }

        if ($passengerName !== '') {
            $existingTraveler = $travelerRepository->findTravelerForBookingByName($bookingId, $passengerName);
            if ($existingTraveler !== null) {
                return [
                    'traveler_id' => (int) $existingTraveler['id'],
                    'passenger_name_snapshot' => (string) $existingTraveler['full_name'],
                ];
            }

            $newTravelerId = $travelerRepository->createTraveler([
                'branch_id' => $branchId,
                'first_name' => $this->optionalText($passengerName, 100),
                'last_name' => null,
                'full_name' => $this->optionalText($passengerName, 190),
                'passport_number' => null,
                'nationality' => null,
                'date_of_birth' => null,
                'gender' => 'unspecified',
                'passport_expiry' => null,
                'mobile' => null,
                'address' => null,
                'permanent_residence' => null,
                'current_residence' => null,
                'occupation' => null,
                'village' => null,
                'district' => null,
                'family_id' => null,
                'color_tag' => null,
                'notes' => 'Added from invoice service entry.',
                'actor_user_id' => $actorUserId,
            ]);
            $travelerRepository->attachTravelerToBooking($bookingId, $newTravelerId, 'additional', $actorUserId);

            AuditLog::record($this->app, 'traveler.created', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'traveler_id' => $newTravelerId,
                'traveler_role' => 'additional',
                'mode' => 'service_passenger_entry',
            ]);
            AuditLog::record($this->app, 'traveler.attached', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'traveler_id' => $newTravelerId,
                'traveler_role' => 'additional',
                'mode' => 'service_passenger_entry',
            ]);

            return [
                'traveler_id' => $newTravelerId,
                'passenger_name_snapshot' => $this->optionalText($passengerName, 190),
            ];
        }

        return [
            'traveler_id' => null,
            'passenger_name_snapshot' => null,
        ];
    }

    private function normalizeServiceType(string $value): string
    {
        $type = mb_strtolower(trim($value));
        if (! in_array($type, self::ALLOWED_SERVICE_TYPES, true)) {
            throw new RuntimeException('Please select a valid service type.');
        }

        return $type;
    }

    private function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid service currency.');
        }

        return $currency;
    }

    private function normalizePaymentMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new RuntimeException('Please select a valid refund payment method.');
        }

        return $method;
    }

    private function assertCanPostServiceEvent(int $actorUserId): void
    {
        $this->assertFinancialAdminActor(
            $actorUserId,
            'Only super admin or branch admin can post service cancellation, refund, or reissue actions.'
        );
    }

    private function normalizeStatus(string $value): string
    {
        $status = trim($value);
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new RuntimeException('Please select a valid service status.');
        }

        return $status;
    }

    private function moneyValue(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        if ($number < 0) {
            throw new RuntimeException('Service monetary values cannot be negative.');
        }

        return round($number, 2);
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (! ctype_digit($raw)) {
            throw new RuntimeException('One of the service counts is invalid.');
        }

        return max(0, (int) $raw);
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('One of the service values exceeds the allowed length.');
        }

        return $text;
    }

    private function requiredText(mixed $value, int $maxLength, string $message): string
    {
        $text = $this->optionalText($value, $maxLength);
        if ($text === null) {
            throw new RuntimeException($message);
        }

        return $text;
    }

    private function normalizeOptionalDate(string $value): ?string
    {
        $date = trim($value);
        if ($date === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('One of the service dates is invalid.');
        }

        return $date;
    }
}
