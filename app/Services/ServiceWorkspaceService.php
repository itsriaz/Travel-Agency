<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRefundDetailRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\RefundRecoveryRepository;
use App\Repositories\ServiceFinancialCorrectionRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TreasuryRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;
use Throwable;

final class ServiceWorkspaceService extends Service
{
    private array $recoveryMoneyBudgets = [];
    private bool $completeRecoveryInProgress = false;
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_STATUSES = ['Booked', 'Docs Pending', 'Reserved', 'Open', 'Delivered', 'Cancelled'];
    private const FALLBACK_SERVICE_TYPE_MAP = [
        'AIR' => 'air ticket',
        'VISA' => 'visa',
        'UMR' => 'umrah',
        'HOT' => 'hotel',
        'TRN' => 'transport',
        'TOUR' => 'tourism',
        'OTH' => 'other',
    ];

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
            $booking = $bookingRepository->findBookingById($bookingId);
            $bookingReference = (string) ($booking['booking_reference'] ?? '');

            $services = (new BookingServiceRepository($this->app))->servicesForBooking($bookingId);
            $eventRepository = new BookingServiceEventRepository($this->app);
            $supplierRepository = new SupplierRepository($this->app);
            $customerRepository = new CustomerPaymentRepository($this->app);
            foreach ($services as &$serviceRow) {
                $serviceId = (int) ($serviceRow['id'] ?? 0);
                if ($serviceId <= 0) {
                    continue;
                }

                $postedServiceEvents = $eventRepository->postedEventsForService($serviceId);
                $serviceRow['reissue_events'] = array_values(array_filter(
                    $postedServiceEvents,
                    static fn (array $event): bool => (string) ($event['event_type'] ?? '') === 'reissue'
                ));
                $latestRefundEvent = $eventRepository->latestPostedEvent($serviceId, 'refund');
                $latestCustomerRefundEvent = null;
                $latestSupplierRefundEvent = null;
                foreach (array_reverse($postedServiceEvents) as $serviceEventRow) {
                    if ((string) ($serviceEventRow['event_type'] ?? '') !== 'refund') {
                        continue;
                    }
                    if ($latestCustomerRefundEvent === null && round((float) ($serviceEventRow['customer_refund_amount'] ?? 0), 2) > 0.0) {
                        $latestCustomerRefundEvent = $serviceEventRow;
                    }
                    if ($latestSupplierRefundEvent === null && round((float) ($serviceEventRow['supplier_refund_amount'] ?? 0), 2) > 0.0) {
                        $latestSupplierRefundEvent = $serviceEventRow;
                    }
                    if ($latestCustomerRefundEvent !== null && $latestSupplierRefundEvent !== null) {
                        break;
                    }
                }
                $serviceRow['latest_refund_event_id'] = (int) ($latestRefundEvent['id'] ?? 0);
                $serviceRow['latest_refund_event_date'] = (string) ($latestRefundEvent['event_date'] ?? '');
                $serviceRow['latest_refund_customer_amount'] = (float) ($latestRefundEvent['customer_refund_amount'] ?? 0);
                $serviceRow['latest_refund_supplier_amount'] = (float) ($latestRefundEvent['supplier_refund_amount'] ?? 0);
                $serviceRow['latest_customer_refund_event_id'] = (int) ($latestCustomerRefundEvent['id'] ?? 0);
                $serviceRow['latest_customer_refund_amount_only'] = (float) ($latestCustomerRefundEvent['customer_refund_amount'] ?? 0);
                $serviceRow['latest_supplier_refund_event_id'] = (int) ($latestSupplierRefundEvent['id'] ?? 0);
                $serviceRow['latest_supplier_refund_amount_only'] = (float) ($latestSupplierRefundEvent['supplier_refund_amount'] ?? 0);
                $latestCustomerRefundPayload = json_decode((string) ($latestCustomerRefundEvent['payload_json'] ?? ''), true);
                if (! is_array($latestCustomerRefundPayload)) {
                    $latestCustomerRefundPayload = [];
                }
                $latestSupplierRefundPayload = json_decode((string) ($latestSupplierRefundEvent['payload_json'] ?? ''), true);
                if (! is_array($latestSupplierRefundPayload)) {
                    $latestSupplierRefundPayload = [];
                }
                $serviceRow['latest_customer_refund_detail'] = (array) (
                    $latestCustomerRefundPayload['customer_refund_detail']
                    ?? $latestCustomerRefundPayload['refund_detail']
                    ?? []
                );
                $serviceRow['latest_supplier_refund_detail'] = (array) (
                    $latestSupplierRefundPayload['supplier_refund_detail']
                    ?? $latestSupplierRefundPayload['refund_detail']
                    ?? []
                );
                $serviceRow['latest_customer_refund_payment_method'] = (string) (
                    $latestCustomerRefundPayload['payment_method']
                    ?? $serviceRow['latest_customer_refund_detail']['refund_payment_method']
                    ?? 'cash'
                );
                $serviceRow['latest_supplier_refund_payment_method'] = (string) (
                    $latestSupplierRefundPayload['supplier_refund_payment_method']
                    ?? $serviceRow['latest_supplier_refund_detail']['refund_payment_method']
                    ?? 'cash'
                );
                $latestCancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
                $latestCancelPayload = json_decode((string) ($latestCancelEvent['payload_json'] ?? ''), true);
                if (! is_array($latestCancelPayload)) {
                    $latestCancelPayload = [];
                }
                $serviceRow['latest_cancel_financially_settled'] = (
                    (string) ($latestCancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
                    || array_key_exists('customer_final_charge_amount', $latestCancelPayload)
                    || array_key_exists('expected_supplier_refund_amount', $latestCancelPayload)
                    || array_key_exists('supplier_penalty_amount', $latestCancelPayload)
                );
                $serviceRow['latest_cancel_customer_settlement_pending'] =
                    ($latestCancelPayload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true;
                $serviceRow['latest_cancel_customer_offset_version'] = (int) ($latestCancelPayload['customer_offset_version'] ?? 0);
                $serviceRow['latest_cancel_event_id'] = (int) ($latestCancelEvent['id'] ?? 0);
                $recoveryHistory = (new RefundRecoveryRepository($this->app))->history($serviceId, $bookingId);
                $serviceRow['refund_recovery_history'] = $recoveryHistory;
                $latestRecovery = $recoveryHistory[0] ?? [];
                $serviceRow['refund_recovery_audit_id'] = ($latestRecovery['event_name'] ?? '') === 'service.refund.complete_undo'
                    && (int) ($latestRecovery['recovery_version'] ?? 0) === 1 ? (int) $latestRecovery['id'] : 0;
                $serviceRow['latest_cancel_customer_penalty_amount'] = (float) ($latestCancelPayload['customer_penalty_amount'] ?? $latestCancelEvent['penalty_amount'] ?? 0);
                $serviceRow['latest_cancel_customer_penalty_currency'] = (string) ($latestCancelPayload['customer_penalty_currency'] ?? $serviceRow['currency'] ?? 'PKR');
                $serviceRow['latest_cancel_customer_penalty_source_amount'] = (float) ($latestCancelPayload['customer_penalty_source_amount'] ?? $serviceRow['latest_cancel_customer_penalty_amount']);
                $serviceRow['latest_cancel_customer_penalty_exchange_rate'] = (float) ($latestCancelPayload['customer_penalty_exchange_rate'] ?? 1);
                $serviceRow['latest_cancel_agency_fee_refund_amount'] = (float) ($latestCancelPayload['agency_fee_refund_amount'] ?? 0);
                $serviceRow['latest_cancel_supplier_penalty_amount'] = (float) ($latestCancelPayload['supplier_penalty_amount'] ?? 0);
                $serviceRow['latest_cancel_supplier_penalty_currency'] = (string) ($latestCancelPayload['supplier_penalty_currency'] ?? $serviceRow['cost_currency'] ?? $serviceRow['currency'] ?? 'PKR');
                $serviceRow['latest_cancel_supplier_penalty_source_amount'] = (float) ($latestCancelPayload['supplier_penalty_source_amount'] ?? $serviceRow['latest_cancel_supplier_penalty_amount']);
                $serviceRow['latest_cancel_supplier_penalty_exchange_rate'] = (float) ($latestCancelPayload['supplier_penalty_exchange_rate'] ?? 1);
                $serviceRow['latest_cancel_expected_supplier_refund_amount'] = (float) (
                    $latestCancelPayload['expected_supplier_refund_amount']
                    ?? $latestCancelPayload['expected_supplier_refund_credit']
                    ?? $latestCancelEvent['supplier_credit_amount']
                    ?? 0
                );
                $serviceRow['latest_cancel_expected_supplier_refund_currency'] = (string) ($latestCancelPayload['expected_supplier_refund_currency'] ?? $serviceRow['cost_currency'] ?? $serviceRow['currency'] ?? 'PKR');
                $serviceRow['latest_cancel_expected_supplier_refund_source_amount'] = (float) ($latestCancelPayload['expected_supplier_refund_source_amount'] ?? $serviceRow['latest_cancel_expected_supplier_refund_amount']);
                $serviceRow['latest_cancel_expected_supplier_refund_exchange_rate'] = (float) ($latestCancelPayload['expected_supplier_refund_exchange_rate'] ?? 0);
                $serviceRow['latest_cancel_expected_supplier_refund_rate_date'] = (string) ($latestCancelPayload['expected_supplier_refund_rate_date'] ?? $latestCancelEvent['event_date'] ?? '');
                $serviceRow['latest_cancel_expected_supplier_refund_equivalent_amount'] = $latestCancelPayload['expected_supplier_refund_equivalent_amount'] ?? null;
                $serviceRow['latest_cancel_expected_supplier_refund_equivalent_currency'] = (string) ($latestCancelPayload['expected_supplier_refund_equivalent_currency'] ?? '');
                $serviceRow['latest_cancel_expected_supplier_refund_conversion_unverified'] =
                    $latestCancelEvent !== null
                    && $serviceRow['latest_cancel_expected_supplier_refund_currency'] !== (string) $serviceRow['currency']
                    && (string) ($latestCancelPayload['settlement_currency'] ?? '') !== (string) ($latestCancelEvent['currency'] ?? '')
                    && ! array_key_exists('expected_supplier_refund_booking_amount', $latestCancelPayload)
                    && ! array_key_exists('expected_supplier_refund_equivalent_amount', $latestCancelPayload);
                $serviceRow['latest_cancel_customer_final_charge_amount'] = (float) ($latestCancelPayload['customer_final_charge_amount'] ?? 0);
                $serviceRow['latest_cancel_customer_refund_basis_amount'] = (float) ($latestCancelPayload['customer_received_amount'] ?? 0);
                $serviceRow['latest_cancel_prior_customer_due_amount'] = (float) ($latestCancelPayload['prior_customer_due_amount'] ?? 0);
                $serviceRow['latest_cancel_released_customer_credit_amount'] = (float) (
                    $latestCancelPayload['available_customer_refund_credit']
                    ?? $latestCancelPayload['released_customer_credit']
                    ?? $latestCancelEvent['customer_credit_amount']
                    ?? 0
                );
                $serviceRow['latest_cancel_released_supplier_credit_amount'] = (float) ($latestCancelPayload['released_supplier_credit'] ?? $latestCancelEvent['supplier_credit_amount'] ?? 0);
                $serviceRow['latest_cancel_reason'] = (string) ($latestCancelEvent['reason'] ?? '');
                $serviceRow['latest_cancel_notes'] = (string) ($latestCancelEvent['notes'] ?? '');
                $serviceRow['latest_cancel_event_date'] = (string) ($latestCancelEvent['event_date'] ?? '');
                $serviceLineReference = (string) ($serviceRow['line_reference'] ?? '');
                $serviceReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $serviceLineReference);
                $serviceRow['current_customer_invoice_amount'] = (float) ($serviceReceivable['due_amount'] ?? 0);
                $serviceRow['current_customer_received_amount'] = (float) ($serviceReceivable['allocated_amount'] ?? 0);
                if (! array_key_exists('customer_received_amount', $latestCancelPayload)) {
                    $serviceRow['latest_cancel_customer_refund_basis_amount'] = $serviceRow['latest_cancel_financially_settled']
                        ? (float) ($latestCancelPayload['released_customer_credit'] ?? 0)
                            + (float) ($serviceReceivable['allocated_amount'] ?? 0)
                        : (float) ($serviceReceivable['allocated_amount'] ?? 0);
                }
                $serviceObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $serviceLineReference);
                $supplierTruthCurrency = strtoupper(trim((string) (
                    $serviceRow['latest_supplier_refund_detail']['refund_currency']
                    ?? $serviceObligation['currency']
                    ?? $serviceRow['cost_currency']
                    ?? $serviceRow['currency']
                    ?? 'PKR'
                ))) ?: 'PKR';
                $serviceRow['supplier_truth_currency'] = $supplierTruthCurrency;
                if (trim((string) ($latestCancelPayload['supplier_penalty_currency'] ?? '')) === '') {
                    $serviceRow['latest_cancel_supplier_penalty_currency'] = $supplierTruthCurrency;
                } else {
                    $serviceRow['latest_cancel_supplier_penalty_currency'] = strtoupper(trim((string) $serviceRow['latest_cancel_supplier_penalty_currency'])) ?: $supplierTruthCurrency;
                }
                if (trim((string) ($latestCancelPayload['expected_supplier_refund_currency'] ?? '')) === '') {
                    $serviceRow['latest_cancel_expected_supplier_refund_currency'] = $supplierTruthCurrency;
                } else {
                    $serviceRow['latest_cancel_expected_supplier_refund_currency'] = strtoupper(trim((string) $serviceRow['latest_cancel_expected_supplier_refund_currency'])) ?: $supplierTruthCurrency;
                }
                $supplierRefundState = $this->supplierRecoveryStateForService(
                    $serviceId,
                    $bookingReference,
                    (string) ($serviceRow['currency'] ?? 'PKR'),
                    $serviceObligation,
                    $eventRepository,
                    $serviceRow
                );
                $customerRefundTruthCurrency = $this->resolveCustomerRefundTruthCurrency($latestCancelPayload, (string) ($serviceRow['currency'] ?? 'PKR'), (string) ($latestCancelEvent['currency'] ?? ''));
                $customerRefundState = $this->customerRefundStateForService(
                    $serviceId,
                    $bookingReference,
                    $customerRefundTruthCurrency,
                    $latestCancelPayload,
                    $latestCancelEvent,
                    $eventRepository,
                    $customerRepository,
                    $supplierRefundState
                );
                $serviceRow['customer_refund_truth_currency'] = $customerRefundTruthCurrency;
                $serviceRow['supplier_refundable_credit_amount'] = $supplierRefundState['remaining_expected_supplier_refund'];
                $serviceRow['supplier_credit_recorded_source_amount'] = $supplierRefundState['supplier_credit_recorded_source_amount'];
                $serviceRow['supplier_payable_reduced_source_amount'] = (float) ($latestCancelPayload['supplier_payable_reduced_source_amount'] ?? 0);
                $serviceRow['supplier_credit_auto_recording'] = array_key_exists('supplier_credit_recording_version', $latestCancelPayload);
                $serviceRow['customer_refundable_credit_amount'] = $customerRefundState['remaining_customer_refund_credit'];
                $serviceRow['customer_refund_received_amount'] = $customerRefundState['customer_refund_received_amount'];
                $serviceRow['customer_refund_summary'] = [
                    'currency' => $customerRefundTruthCurrency,
                    'final_charge' => (float) ($serviceReceivable['due_amount'] ?? 0),
                    'settled' => (float) ($serviceReceivable['allocated_amount'] ?? 0),
                    'outstanding' => (float) ($serviceReceivable['outstanding_amount'] ?? 0),
                    'applied_to_outstanding' => max(
                        (float) ($latestCancelPayload['prior_customer_due_amount'] ?? $serviceReceivable['due_amount'] ?? 0)
                            - (float) ($serviceReceivable['allocated_amount'] ?? 0)
                            - (float) ($serviceReceivable['outstanding_amount'] ?? 0),
                        0
                    ),
                    'supplier_applied_to_outstanding' => max(
                        (float) ($latestCancelPayload['prior_customer_due_amount'] ?? $serviceReceivable['due_amount'] ?? 0)
                            + (float) ($latestCancelPayload['customer_penalty_amount'] ?? 0)
                            - (float) ($latestCancelPayload['agency_fee_refund_amount'] ?? 0)
                            - (float) ($serviceReceivable['allocated_amount'] ?? 0)
                            - (float) ($serviceReceivable['outstanding_amount'] ?? 0),
                        0
                    ),
                    'entitlement' => $customerRefundState['released_customer_credit'],
                    'paid' => $customerRefundState['customer_refund_received_amount'],
                    'remaining' => $customerRefundState['remaining_customer_refund_credit'],
                    'basis_verified' => array_key_exists('customer_received_amount', $latestCancelPayload)
                        && array_key_exists('prior_customer_due_amount', $latestCancelPayload),
                ];
            }
            unset($serviceRow);
        }

        return [
            'services' => $services,
            'supplierOptions' => $suppliers,
        ];
    }

    public function saveService(array $input, int $actorUserId, array $accessibleBranchIds, bool $lockSectionA = false): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        app_write_log('workspace.save_service.currency_entry', 'Service save entered for currency tracing.', array_merge(
            app_request_log_context(),
            [
                'booking_id' => $bookingId,
                'service_id' => $serviceId,
                'currency' => (string) ($input['currency'] ?? ''),
                'cost_currency' => (string) ($input['cost_currency'] ?? ''),
                'service_charge_currency' => (string) ($input['service_charge_currency'] ?? ''),
                'sale_price' => (string) ($input['sale_price'] ?? ''),
                'purchase_cost' => (string) ($input['purchase_cost'] ?? ''),
                'pricing_exchange_rate' => (string) ($input['pricing_exchange_rate'] ?? ''),
                'service_charge_exchange_rate' => (string) ($input['service_charge_exchange_rate'] ?? ''),
                'service_status' => (string) ($input['service_status'] ?? ''),
                'service_updated_at' => (string) ($input['service_updated_at'] ?? $input['updated_at'] ?? ''),
            ]
        ));

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $repository = new BookingServiceRepository($this->app);
        $existingService = null;

        if ($serviceId > 0) {
            if (! $repository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected service line is outside your accessible branches.');
            }

            $existingService = $repository->findServiceById($serviceId);
            if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
                throw new RuntimeException('The selected service line does not belong to this booking.');
            }
        }

        if ($lockSectionA) {
            $input = $this->preserveLockedSectionAFields($input, $existingService);
        }

        if ($existingService !== null) {
            $this->assertServiceUpdateIsFresh($input, $existingService);
            $this->assertNoProtectedFinancialChangeOnSavedService($input, $existingService);
        }

        BookingEditTraceService::checkpoint('ServiceWorkspaceService::validatedPayload', 'started', ['service_id' => $serviceId]);
        $payload = $this->validatedPayload(
            $input,
            $accessibleBranchIds,
            (int) $booking['branch_id'],
            $bookingId,
            $actorUserId,
            $repository,
            $existingService
        );
        BookingEditTraceService::checkpoint('ServiceWorkspaceService::validatedPayload', 'completed', [
            'invoice_currency' => (string) ($payload['master']['currency'] ?? ''),
            'cost_currency' => (string) ($payload['master']['cost_currency'] ?? ''),
            'supplier_cost' => (string) ($payload['master']['purchase_cost'] ?? ''),
            'customer_total' => (string) ($payload['master']['final_sale_price'] ?? ''),
        ]);
        app_write_log('workspace.save_service.currency_payload', 'Service payload normalized for currency tracing.', array_merge(
            app_request_log_context(),
            [
                'booking_id' => $bookingId,
                'service_id' => $serviceId,
                'existing_currency' => (string) ($existingService['currency'] ?? ''),
                'existing_cost_currency' => (string) ($existingService['cost_currency'] ?? ''),
                'existing_service_charge_currency' => (string) ($existingService['service_charge_currency'] ?? ''),
                'resolved_currency' => (string) ($payload['master']['currency'] ?? ''),
                'resolved_cost_currency' => (string) ($payload['master']['cost_currency'] ?? ''),
                'resolved_service_charge_currency' => (string) ($payload['master']['service_charge_currency'] ?? ''),
                'resolved_purchase_cost' => round((float) ($payload['master']['purchase_cost'] ?? 0), 2),
                'resolved_sale_price' => round((float) ($payload['master']['sale_price'] ?? 0), 2),
                'resolved_service_charge' => round((float) ($payload['master']['service_charge'] ?? 0), 2),
                'resolved_pricing_exchange_rate' => (float) ($payload['master']['pricing_exchange_rate'] ?? 0),
                'resolved_service_charge_exchange_rate' => (float) ($payload['master']['service_charge_exchange_rate'] ?? 0),
            ]
        ));

        if ($serviceId > 0) {
            $this->assertSettledCancellationSaveIsOperational($existingService, $payload['master']);
            $protectedRefundSnapshot = $this->postedRefundProtectionSnapshot($serviceId);

            /** @var \PDO $db */
            $db = $this->app->get('db');
            $startedTransaction = ! $db->inTransaction();
            if ($startedTransaction) {
                $db->beginTransaction();
            }

            try {
                BookingEditTraceService::checkpoint('ServiceWorkspaceService::prepareSupplierCorrection', 'started', ['service_id' => $serviceId]);
                $supplierCorrection = $this->prepareSupplierCorrection(
                    $existingService,
                    $payload['master'],
                    $booking,
                    $actorUserId
                );
                BookingEditTraceService::checkpoint('ServiceWorkspaceService::prepareSupplierCorrection', 'completed', $supplierCorrection);

                BookingEditTraceService::checkpoint('BookingServiceRepository::updateService', 'started', ['service_id' => $serviceId]);
                $repository->updateService(
                    $serviceId,
                    array_merge($payload['master'], ['actor_user_id' => $actorUserId]),
                    $payload['subtype']
                );
                BookingEditTraceService::checkpoint('BookingServiceRepository::updateService', 'completed', ['service_id' => $serviceId]);
                BookingEditTraceService::checkpoint('CommercialObligationSyncService::syncForServiceId', 'started', ['service_id' => $serviceId]);
                (new CommercialObligationSyncService($this->app))->syncForServiceId($serviceId, $actorUserId);
                BookingEditTraceService::checkpoint('CommercialObligationSyncService::syncForServiceId', 'completed', ['service_id' => $serviceId]);
                $savedService = $repository->findServiceById($serviceId);
                app_write_log('workspace.save_service.currency_saved', 'Existing service saved for currency tracing.', array_merge(
                    app_request_log_context(),
                    [
                        'booking_id' => $bookingId,
                        'service_id' => $serviceId,
                        'saved_currency' => (string) ($savedService['currency'] ?? ''),
                        'saved_cost_currency' => (string) ($savedService['cost_currency'] ?? ''),
                        'saved_service_charge_currency' => (string) ($savedService['service_charge_currency'] ?? ''),
                        'saved_purchase_cost' => round((float) ($savedService['purchase_cost'] ?? 0), 2),
                        'saved_sale_price' => round((float) ($savedService['sale_price'] ?? 0), 2),
                        'saved_service_charge' => round((float) ($savedService['service_charge'] ?? 0), 2),
                        'saved_pricing_exchange_rate' => (float) ($savedService['pricing_exchange_rate'] ?? 0),
                        'saved_service_charge_exchange_rate' => (float) ($savedService['service_charge_exchange_rate'] ?? 0),
                    ]
                ));

                AuditLog::record($this->app, 'service.updated', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_id' => $serviceId,
                    'service_type' => $payload['master']['service_type'],
                    'currency' => $payload['master']['currency'],
                    'supplier_correction' => $supplierCorrection,
                ]);

                if (($supplierCorrection['changed'] ?? false) === true) {
                    AuditLog::record($this->app, 'service.supplier_corrected', [
                        'user_id' => $actorUserId,
                        'booking_id' => $bookingId,
                        'booking_reference' => (string) $booking['booking_reference'],
                        'service_id' => $serviceId,
                        'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                        'prior_supplier_id' => $supplierCorrection['prior_supplier_id'] ?? null,
                        'prior_supplier_name' => $supplierCorrection['prior_supplier_name'] ?? null,
                        'new_supplier_id' => $supplierCorrection['new_supplier_id'] ?? null,
                        'new_supplier_name' => $supplierCorrection['new_supplier_name'] ?? null,
                        'released_settlement_amount' => $supplierCorrection['released_settlement_amount'] ?? 0,
                        'released_payment_amount' => $supplierCorrection['released_payment_amount'] ?? 0,
                        'released_advance_amount' => $supplierCorrection['released_advance_amount'] ?? 0,
                    ]);
                }

                $this->assertPostedRefundProtectionSnapshot($serviceId, $protectedRefundSnapshot);

                if ($startedTransaction && $db->inTransaction()) {
                    $db->commit();
                }
            } catch (Throwable $exception) {
                if ($startedTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }

                throw $exception;
            }

            return [
                'service' => $savedService,
                'action' => 'updated',
                'booking_id' => $bookingId,
                'debug' => [
                    'resolvedPayload' => $payload,
                    'savedService' => $savedService,
                    'supplierCorrection' => $supplierCorrection,
                ],
            ];
        }

        BookingEditTraceService::checkpoint('BookingServiceRepository::createService', 'started');
        $newServiceId = $repository->createService(
            array_merge($payload['master'], [
                'booking_id' => $bookingId,
                'branch_id' => (int) $booking['branch_id'],
                'actor_user_id' => $actorUserId,
            ]),
            $payload['subtype']
        );
        BookingEditTraceService::checkpoint('BookingServiceRepository::createService', 'completed', ['service_id' => $newServiceId]);
        BookingEditTraceService::checkpoint('CommercialObligationSyncService::syncForServiceId', 'started', ['service_id' => $newServiceId]);
        (new CommercialObligationSyncService($this->app))->syncForServiceId($newServiceId, $actorUserId);
        BookingEditTraceService::checkpoint('CommercialObligationSyncService::syncForServiceId', 'completed', ['service_id' => $newServiceId]);
        $savedService = $repository->findServiceById($newServiceId);
        app_write_log('workspace.save_service.currency_saved', 'New service saved for currency tracing.', array_merge(
            app_request_log_context(),
            [
                'booking_id' => $bookingId,
                'service_id' => $newServiceId,
                'saved_currency' => (string) ($savedService['currency'] ?? ''),
                'saved_cost_currency' => (string) ($savedService['cost_currency'] ?? ''),
                'saved_service_charge_currency' => (string) ($savedService['service_charge_currency'] ?? ''),
                'saved_purchase_cost' => round((float) ($savedService['purchase_cost'] ?? 0), 2),
                'saved_sale_price' => round((float) ($savedService['sale_price'] ?? 0), 2),
                'saved_service_charge' => round((float) ($savedService['service_charge'] ?? 0), 2),
                'saved_pricing_exchange_rate' => (float) ($savedService['pricing_exchange_rate'] ?? 0),
                'saved_service_charge_exchange_rate' => (float) ($savedService['service_charge_exchange_rate'] ?? 0),
            ]
        ));

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

    private function preserveLockedSectionAFields(array $input, ?array $existingService): array
    {
        $fields = [
            'ticket_fare' => 'fare',
            'other_fare' => 'other_fare',
            'spyi_amount' => 'spyi_amount',
            'yq_amount' => 'yq_amount',
            'vat_input' => 'vat_input',
            'soto_fare' => 'soto_fare',
            'aq_yr_pk_amount' => 'aq_yr_pk_amount',
            'oth_amount' => 'oth_amount',
            'taxes' => 'taxes',
        ];

        foreach ($fields as $inputField => $storedField) {
            $input[$inputField] = (string) ($existingService[$storedField] ?? '0');
        }

        return $input;
    }

    private function assertServiceUpdateIsFresh(array $input, array $existingService): void
    {
        $postedUpdatedAt = trim((string) ($input['service_updated_at'] ?? $input['updated_at'] ?? ''));
        $currentUpdatedAt = trim((string) ($existingService['updated_at'] ?? ''));

        if ($currentUpdatedAt === '') {
            return;
        }

        if ($postedUpdatedAt === '' || $postedUpdatedAt !== $currentUpdatedAt) {
            $postedSupplierName = trim((string) ($input['supplier_name'] ?? ''));
            $currentSupplierName = trim((string) ($existingService['supplier_name_snapshot'] ?? $existingService['supplier_name'] ?? ''));
            if (
                $postedUpdatedAt === ''
                && $postedSupplierName !== ''
                && $postedSupplierName !== $currentSupplierName
                && $this->isSupplierOnlyWorkspaceCorrection($input, $existingService)
            ) {
                app_write_log('workspace.service.blank_token_supplier_correction_allowed', 'Allowed supplier-only service correction with blank workspace version token.', array_merge(
                    app_request_log_context(),
                    [
                        'service_id' => (int) ($existingService['id'] ?? 0),
                        'booking_id' => (int) ($existingService['booking_id'] ?? 0),
                        'current_updated_at' => $currentUpdatedAt,
                        'posted_supplier_name' => $postedSupplierName,
                        'current_supplier_name' => $currentSupplierName,
                    ]
                ));
                return;
            }

            if ($this->isBenignDuplicateServiceSubmit($input, $existingService)) {
                app_write_log('workspace.service.stale_submit_ignored', 'Ignored stale service token because the submitted values already match the saved service state.', array_merge(
                    app_request_log_context(),
                    [
                        'service_id' => (int) ($existingService['id'] ?? 0),
                        'booking_id' => (int) ($existingService['booking_id'] ?? 0),
                        'posted_updated_at' => $postedUpdatedAt,
                        'current_updated_at' => $currentUpdatedAt,
                    ]
                ));
                return;
            }

            app_write_log('workspace.service.stale_submit_blocked', 'Blocked stale service submit because the payload no longer matches the current saved service state.', array_merge(
                app_request_log_context(),
                [
                    'service_id' => (int) ($existingService['id'] ?? 0),
                    'booking_id' => (int) ($existingService['booking_id'] ?? 0),
                    'posted_updated_at' => $postedUpdatedAt,
                    'current_updated_at' => $currentUpdatedAt,
                    'posted_supplier_name' => $postedSupplierName,
                    'current_supplier_name' => $currentSupplierName,
                ]
            ));
            throw new RuntimeException('This service line was updated elsewhere. Reload the booking and save again.');
        }
    }

    private function isSupplierOnlyWorkspaceCorrection(array $input, array $existingService): bool
    {
        $postedSupplierName = trim((string) ($input['supplier_name'] ?? ''));
        $currentSupplierName = trim((string) ($existingService['supplier_name_snapshot'] ?? $existingService['supplier_name'] ?? ''));
        if ($postedSupplierName === '' || $postedSupplierName === $currentSupplierName) {
            return false;
        }

        $blankWorkspaceToken = trim((string) ($input['service_updated_at'] ?? $input['updated_at'] ?? '')) === '';
        if (! $blankWorkspaceToken) {
            return false;
        }

        return true;
    }

    private function isBenignDuplicateServiceSubmit(array $input, array $existingService): bool
    {
        if ($this->hasPricingChange($input, $existingService)) {
            return false;
        }

        $postedSupplierName = trim((string) ($input['supplier_name'] ?? ''));
        $currentSupplierName = trim((string) ($existingService['supplier_name_snapshot'] ?? $existingService['supplier_name'] ?? ''));
        if ($postedSupplierName !== '' && $postedSupplierName !== $currentSupplierName) {
            return false;
        }

        $fieldComparisons = [
            ['input' => 'service_type', 'current' => (string) ($existingService['service_type'] ?? 'air ticket')],
            ['input' => 'service_status', 'current' => (string) ($existingService['service_status'] ?? 'Open')],
            ['input' => 'due_date', 'current' => (string) ($existingService['due_date'] ?? '')],
            ['input' => 'remarks', 'current' => (string) ($existingService['remarks'] ?? '')],
            ['input' => 'service_passenger_name', 'current' => (string) ($existingService['passenger_name_snapshot'] ?? $existingService['passenger_name'] ?? '')],
            ['input' => 'service_traveler_id', 'current' => (string) ((int) ($existingService['traveler_id'] ?? 0))],
            ['input' => 'ticket_number', 'current' => (string) ($existingService['ticket_number'] ?? '')],
            ['input' => 'ticket_pnr', 'current' => (string) ($existingService['pnr'] ?? '')],
            ['input' => 'ticket_airline', 'current' => (string) ($existingService['airline'] ?? '')],
        ];

        foreach ($fieldComparisons as $comparison) {
            if (! array_key_exists($comparison['input'], $input)) {
                continue;
            }

            $posted = trim((string) ($input[$comparison['input']] ?? ''));
            $current = trim((string) ($comparison['current'] ?? ''));
            if ($posted !== $current) {
                return false;
            }
        }

        return true;
    }

    public function correctFinancials(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertFinancialAdminActor(
            $actorUserId,
            'Only super admin or branch admin can correct saved service financial values.'
        );

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        if ($bookingId <= 0 || $serviceId <= 0) {
            throw new RuntimeException('Select a saved invoice service before posting a financial correction.');
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot correct services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $serviceRepository = new BookingServiceRepository($this->app);
        if (! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line is outside your accessible branches.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        if ((new BookingServiceEventRepository($this->app))->hasPostedCancellationSettlement($serviceId)) {
            throw new RuntimeException('This service has a posted cancellation settlement. Use the cancellation/refund correction workflow, not Quick Financial Edit.');
        }

        $currentSnapshot = $this->serviceFinancialSnapshot($existingService);
        app_write_log('service.financial_correction.request_trace', 'Evaluating saved-service financial correction request.', array_merge(
            app_request_log_context(),
            [
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'current_snapshot' => $currentSnapshot,
                'submitted_payload' => [
                    'corrected_invoice_currency' => $input['corrected_invoice_currency'] ?? null,
                    'corrected_cost_currency' => $input['corrected_cost_currency'] ?? null,
                    'corrected_service_charge_currency' => $input['corrected_service_charge_currency'] ?? null,
                    'corrected_cost_basis' => $input['corrected_cost_basis'] ?? null,
                    'corrected_service_charge' => $input['corrected_service_charge'] ?? null,
                    'corrected_discount_amount' => $input['corrected_discount_amount'] ?? null,
                    'corrected_final_sale_price' => $input['corrected_final_sale_price'] ?? null,
                    'corrected_pricing_exchange_rate' => $input['corrected_pricing_exchange_rate'] ?? null,
                    'corrected_aed_to_pkr_exchange_rate' => $input['corrected_aed_to_pkr_exchange_rate'] ?? null,
                    'corrected_service_charge_exchange_rate' => $input['corrected_service_charge_exchange_rate'] ?? null,
                    'financial_correction_date' => $input['financial_correction_date'] ?? null,
                    'financial_correction_reason' => $input['financial_correction_reason'] ?? null,
                ],
            ]
        ));
        BookingEditTraceService::checkpoint('ServiceWorkspaceService::validatedFinancialCorrectionPayload', 'started', ['service_id' => $serviceId]);
        $correction = $this->validatedFinancialCorrectionPayload($input, $existingService, $currentSnapshot);
        app_write_log('service.financial_correction.derived_trace', 'Derived saved-service financial correction values.', array_merge(
            app_request_log_context(),
            [
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'has_changes' => (bool) ($correction['has_changes'] ?? false),
                'derived_financial' => $correction['financial'] ?? [],
                'correction_date' => $correction['correction_date'] ?? null,
                'reason' => $correction['reason'] ?? null,
            ]
        ));

        if (! $correction['has_changes']) {
            BookingEditTraceService::checkpoint('ServiceWorkspaceService::validatedFinancialCorrectionPayload', 'no_change', [
                'current' => $currentSnapshot,
                'derived' => $correction['financial'],
            ]);
            app_write_log('workspace.service_financial_correction.no_change', 'Financial correction payload matched the selected service values.', array_merge(
                app_request_log_context(),
                [
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                    'service_id' => $serviceId,
                    'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                    'current' => $currentSnapshot,
                    'submitted' => [
                        'corrected_cost_basis' => $input['corrected_cost_basis'] ?? null,
                        'corrected_service_charge' => $input['corrected_service_charge'] ?? null,
                        'corrected_discount_amount' => $input['corrected_discount_amount'] ?? null,
                        'corrected_final_sale_price' => $input['corrected_final_sale_price'] ?? null,
                    ],
                ]
            ));
            throw new RuntimeException('No financial change was detected for this service line.');
        }

        $bookingReference = (string) ($booking['booking_reference'] ?? '');
        $lineReference = (string) ($existingService['line_reference'] ?? '');
        $invoiceCurrency = (string) ($existingService['currency'] ?? 'PKR');
        $costCurrency = (string) ($existingService['cost_currency'] ?? $invoiceCurrency);
        $newInvoiceCurrency = (string) ($correction['financial']['currency'] ?? $invoiceCurrency);
        $newCostCurrency = (string) ($correction['financial']['cost_currency'] ?? $costCurrency);
        BookingEditTraceService::checkpoint('ServiceWorkspaceService::validatedFinancialCorrectionPayload', 'changed', [
            'current' => $currentSnapshot,
            'derived' => $correction['financial'],
        ]);
        $protectedRefundSnapshot = $this->postedRefundProtectionSnapshot($serviceId);

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $customerRepository = new CustomerPaymentRepository($this->app);
            $supplierRepository = new SupplierRepository($this->app);
            $accountingRepository = new AccountingRepository($this->app);

            $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
            $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);

            if ($obligation !== null && strtoupper($costCurrency) !== strtoupper($newCostCurrency)) {
                $costBalances = $accountingRepository->serviceCostBalances($bookingReference, $lineReference);
                $expectedCost = round((float) ($obligation['gross_amount'] ?? 0), 2);
                $priorCost = round((float) ($costBalances[strtoupper($costCurrency)] ?? 0), 2);
                $costPreflightFailed = strtoupper((string) ($obligation['currency'] ?? '')) !== strtoupper($costCurrency)
                    || abs($priorCost - $expectedCost) > 0.005;
                foreach ($costBalances as $currency => $amount) {
                    if ($currency !== strtoupper($costCurrency) && abs($amount) > 0.005) {
                        $costPreflightFailed = true;
                    }
                }
                if ($costPreflightFailed) {
                    app_write_log('service.financial_correction.cost_preflight_failed', 'Supplier cost journals do not match the saved obligation.', [
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => $lineReference,
                        'obligation_id' => (int) ($obligation['id'] ?? 0),
                        'obligation_currency' => (string) ($obligation['currency'] ?? ''),
                        'prior_cost_currency' => $costCurrency,
                        'expected_cost' => $expectedCost,
                        'cost_balances' => $costBalances,
                    ]);
                    throw new RuntimeException('This service needs an accounting review before its supplier currency can be changed.');
                }
            }

            $releasedCustomerCreditAmount = 0.0;
            $releasedSupplierCreditAmount = 0.0;

            if ($receivable !== null) {
                $allocatedAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);
                $linkedOffsetAmount = $customerRepository->linkedPartyOffsetAmountForReceivableItem((int) ($receivable['id'] ?? 0));
                $receivableCurrencyChanged = strtoupper((string) ($receivable['currency'] ?? $invoiceCurrency)) !== strtoupper($newInvoiceCurrency);
                $targetReceivableAmount = $receivableCurrencyChanged ? 0.0 : $correction['financial']['final_sale_price'];
                app_write_log('service.financial_correction.receivable_trace', 'Prepared receivable-side correction state.', array_merge(
                    app_request_log_context(),
                    [
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => $lineReference,
                        'receivable_id' => (int) ($receivable['id'] ?? 0),
                        'receivable_currency' => (string) ($receivable['currency'] ?? $invoiceCurrency),
                        'new_invoice_currency' => $newInvoiceCurrency,
                        'allocated_amount' => $allocatedAmount,
                        'linked_offset_amount' => $linkedOffsetAmount,
                        'target_receivable_amount' => $targetReceivableAmount,
                        'receivable_currency_changed' => $receivableCurrencyChanged,
                    ]
                ));
                if ($receivableCurrencyChanged || $targetReceivableAmount < max($allocatedAmount, $linkedOffsetAmount) - 0.005) {
                    $release = $customerRepository->releaseAllocatedCreditForReceivable(
                        (int) $receivable['id'],
                        $targetReceivableAmount,
                        $actorUserId,
                        'Service financial correction [' . $lineReference . ']'
                    );
                    $releasedCustomerCreditAmount = round((float) ($release['released_payment_amount'] ?? 0), 2);

                    if ($releasedCustomerCreditAmount > 0.0) {
                        $accountingRepository->postCustomerReceiptAllocationRelease([
                            'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                            'booking_reference' => $bookingReference,
                            'source_reference' => $lineReference . '-CORR-CREDIT-REL',
                            'service_line_reference' => $lineReference !== '' ? $lineReference : null,
                            'customer_receivable_item_id' => (int) ($receivable['id'] ?? 0),
                            'released_amount' => $releasedCustomerCreditAmount,
                            'entry_date' => $correction['correction_date'],
                            'currency' => $invoiceCurrency,
                            'narration' => 'Customer allocation released by service financial correction',
                            'actor_user_id' => $actorUserId,
                        ]);
                    }
                }
            }

            if ($obligation !== null) {
                $grossAmount = round((float) ($obligation['gross_amount'] ?? 0), 2);
                $netPayableAmount = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
                $currentSettledAmount = round(max($grossAmount - $netPayableAmount, 0), 2);
                $obligationCurrencyChanged = strtoupper((string) ($obligation['currency'] ?? $costCurrency)) !== strtoupper($newCostCurrency);
                $targetSupplierSettledAmount = $obligationCurrencyChanged ? 0.0 : $correction['financial']['purchase_cost'];
                app_write_log('service.financial_correction.obligation_trace', 'Prepared supplier-obligation correction state.', array_merge(
                    app_request_log_context(),
                    [
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => $lineReference,
                        'obligation_id' => (int) ($obligation['id'] ?? 0),
                        'obligation_currency' => (string) ($obligation['currency'] ?? $costCurrency),
                        'new_cost_currency' => $newCostCurrency,
                        'gross_amount' => $grossAmount,
                        'net_payable_amount' => $netPayableAmount,
                        'current_settled_amount' => $currentSettledAmount,
                        'target_supplier_settled_amount' => $targetSupplierSettledAmount,
                        'obligation_currency_changed' => $obligationCurrencyChanged,
                    ]
                ));
                if ($obligationCurrencyChanged || $targetSupplierSettledAmount < $currentSettledAmount - 0.005) {
                    $release = $supplierRepository->releaseSettledCreditForObligation(
                        (int) $obligation['id'],
                        $targetSupplierSettledAmount,
                        $actorUserId,
                        'Service financial correction [' . $lineReference . ']'
                    );
                    $releasedSupplierCreditAmount = round((float) ($release['released_amount'] ?? 0), 2);

                    if ($releasedSupplierCreditAmount > 0.0) {
                        $accountingRepository->postSupplierSettlementRelease([
                            'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                            'booking_reference' => $bookingReference,
                            'source_reference' => $lineReference . '-CORR-SUP-REL',
                            'service_line_reference' => $lineReference !== '' ? $lineReference : null,
                            'supplier_obligation_id' => (int) ($obligation['id'] ?? 0),
                            'released_amount' => $releasedSupplierCreditAmount,
                            'entry_date' => $correction['correction_date'],
                            'currency' => $costCurrency,
                            'narration' => 'Supplier settlement released by service financial correction',
                            'actor_user_id' => $actorUserId,
                        ]);
                    }
                }
            }

            BookingEditTraceService::checkpoint('BookingServiceRepository::updateServiceFinancialFields', 'started', ['service_id' => $serviceId]);
            $serviceRepository->updateServiceFinancialFields(
                $serviceId,
                array_merge($correction['financial'], [
                    'service_type' => (string) ($existingService['service_type'] ?? 'air ticket'),
                    'remarks' => (string) ($existingService['remarks'] ?? ''),
                    'actor_user_id' => $actorUserId,
                ])
            );
            BookingEditTraceService::checkpoint('BookingServiceRepository::updateServiceFinancialFields', 'completed', ['service_id' => $serviceId]);

            BookingEditTraceService::checkpoint('CommercialObligationSyncService::syncForServiceId', 'started', ['service_id' => $serviceId]);
            (new CommercialObligationSyncService($this->app))->syncForServiceId($serviceId, $actorUserId, [
                'financial_correction' => true,
                'entry_date' => $correction['correction_date'],
                'prior_invoice_currency' => $invoiceCurrency,
                'prior_cost_currency' => $costCurrency,
            ]);
            BookingEditTraceService::checkpoint('CommercialObligationSyncService::syncForServiceId', 'completed', ['service_id' => $serviceId]);


            BookingEditTraceService::checkpoint('ServiceFinancialCorrectionRepository::recordCorrection', 'started', ['service_id' => $serviceId]);
            $correctionId = (new ServiceFinancialCorrectionRepository($this->app))->recordCorrection([
                'booking_service_id' => $serviceId,
                'booking_id' => $bookingId,
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'service_type' => (string) ($existingService['service_type'] ?? 'air ticket'),
                'correction_date' => $correction['correction_date'],
                'correction_reason' => $correction['reason'],
                'correction_note' => $correction['note'],
                'prior_invoice_currency' => $currentSnapshot['currency'],
                'new_invoice_currency' => $correction['financial']['currency'],
                'prior_cost_currency' => $currentSnapshot['cost_currency'],
                'new_cost_currency' => $correction['financial']['cost_currency'],
                'prior_pricing_exchange_rate' => $currentSnapshot['pricing_exchange_rate'],
                'new_pricing_exchange_rate' => $correction['financial']['pricing_exchange_rate'],
                'prior_pricing_rate_effective_date' => $currentSnapshot['pricing_rate_effective_date'] !== '' ? $currentSnapshot['pricing_rate_effective_date'] : null,
                'new_pricing_rate_effective_date' => $correction['financial']['pricing_rate_effective_date'],
                'prior_service_charge_currency' => $currentSnapshot['service_charge_currency'],
                'new_service_charge_currency' => $correction['financial']['service_charge_currency'],
                'prior_service_charge_exchange_rate' => $currentSnapshot['service_charge_exchange_rate'],
                'new_service_charge_exchange_rate' => $correction['financial']['service_charge_exchange_rate'],
                'prior_service_charge_rate_effective_date' => $currentSnapshot['service_charge_rate_effective_date'] !== '' ? $currentSnapshot['service_charge_rate_effective_date'] : null,
                'new_service_charge_rate_effective_date' => $correction['financial']['service_charge_rate_effective_date'],
                'prior_sale_price' => $currentSnapshot['sale_price'],
                'new_sale_price' => $correction['financial']['sale_price'],
                'prior_purchase_cost' => $currentSnapshot['purchase_cost'],
                'new_purchase_cost' => $correction['financial']['purchase_cost'],
                'prior_service_charge' => $currentSnapshot['service_charge'],
                'new_service_charge' => $correction['financial']['service_charge'],
                'prior_discount_amount' => $currentSnapshot['discount_amount'],
                'new_discount_amount' => $correction['financial']['discount_amount'],
                'prior_vat_amount' => $currentSnapshot['vat'],
                'new_vat_amount' => $correction['financial']['vat'],
                'prior_final_sale_price' => $currentSnapshot['final_sale_price'],
                'new_final_sale_price' => $correction['financial']['final_sale_price'],
                'released_customer_credit_amount' => $releasedCustomerCreditAmount,
                'released_supplier_credit_amount' => $releasedSupplierCreditAmount,
                'created_by_user_id' => $actorUserId,
            ]);
            BookingEditTraceService::checkpoint('ServiceFinancialCorrectionRepository::recordCorrection', 'completed', ['correction_id' => $correctionId]);

            $updatedService = $serviceRepository->findServiceById($serviceId);

            $this->assertPostedRefundProtectionSnapshot($serviceId, $protectedRefundSnapshot);

            AuditLog::record($this->app, 'service.financial_corrected', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_line_reference' => $lineReference,
                'correction_id' => $correctionId,
                'correction_date' => $correction['correction_date'],
                'reason' => $correction['reason'],
                'released_customer_credit_amount' => $releasedCustomerCreditAmount,
                'released_supplier_credit_amount' => $releasedSupplierCreditAmount,
                'prior_sale_price' => $currentSnapshot['sale_price'],
                'new_sale_price' => $correction['financial']['sale_price'],
                'prior_purchase_cost' => $currentSnapshot['purchase_cost'],
                'new_purchase_cost' => $correction['financial']['purchase_cost'],
                'prior_invoice_currency' => $currentSnapshot['currency'],
                'new_invoice_currency' => $correction['financial']['currency'],
                'prior_cost_currency' => $currentSnapshot['cost_currency'],
                'new_cost_currency' => $correction['financial']['cost_currency'],
                'prior_pricing_exchange_rate' => $currentSnapshot['pricing_exchange_rate'],
                'new_pricing_exchange_rate' => $correction['financial']['pricing_exchange_rate'],
                'prior_pricing_rate_effective_date' => $currentSnapshot['pricing_rate_effective_date'],
                'new_pricing_rate_effective_date' => $correction['financial']['pricing_rate_effective_date'],
                'prior_service_charge_currency' => $currentSnapshot['service_charge_currency'],
                'new_service_charge_currency' => $correction['financial']['service_charge_currency'],
                'prior_service_charge_exchange_rate' => $currentSnapshot['service_charge_exchange_rate'],
                'new_service_charge_exchange_rate' => $correction['financial']['service_charge_exchange_rate'],
                'prior_final_sale_price' => $currentSnapshot['final_sale_price'],
                'new_final_sale_price' => $correction['financial']['final_sale_price'],
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'booking_id' => $bookingId,
                'service_id' => $serviceId,
                'correction_id' => $correctionId,
                'service' => $updatedService,
                'currency' => $newInvoiceCurrency,
                'cost_currency' => $newCostCurrency,
                'released_customer_credit_amount' => $releasedCustomerCreditAmount,
                'released_supplier_credit_amount' => $releasedSupplierCreditAmount,
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function synchronizeBookingExchangeRate(
        int $bookingId,
        string $fromCurrency,
        string $toCurrency,
        int $actorUserId,
        array $accessibleBranchIds
    ): array {
        if ($bookingId <= 0) {
            return ['updated_services' => 0];
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot change exchange rates for a booking outside your accessible branches.');
        }

        $services = (new BookingServiceRepository($this->app))->servicesForBooking($bookingId);
        if ($services === []) {
            return ['updated_services' => 0];
        }

        $fromCurrency = $this->normalizeCurrency($fromCurrency);
        $toCurrency = $this->normalizeCurrency($toCurrency);
        $exchangeRateRepository = new ExchangeRateRepository($this->app);
        $editedRate = $exchangeRateRepository->getBookingRate($bookingId, $fromCurrency, $toCurrency);
        if ($editedRate === null || (float) ($editedRate['exchange_rate'] ?? 0) <= 0) {
            throw new RuntimeException('The saved booking exchange rate could not be resolved.');
        }
        $pairMatches = static function (
            string $leftCurrency,
            string $rightCurrency,
            string $storedFrom,
            string $storedTo
        ): bool {
            return ($leftCurrency === $storedFrom && $rightCurrency === $storedTo)
                || ($leftCurrency === $storedTo && $rightCurrency === $storedFrom);
        };
        $updatedServices = 0;
        $eventRepository = new BookingServiceEventRepository($this->app);

        foreach ($services as $service) {
            if ((int) ($service['is_active'] ?? 1) !== 1) {
                continue;
            }
            if ($eventRepository->hasPostedCancellationSettlement((int) $service['id'])) {
                continue;
            }

            $invoiceCurrency = $this->normalizeCurrency((string) ($service['currency'] ?? 'PKR'));
            $costCurrency = $this->normalizeCurrency((string) ($service['cost_currency'] ?? $invoiceCurrency));
            $serviceChargeCurrency = $this->normalizeCurrency((string) ($service['service_charge_currency'] ?? $invoiceCurrency));
            $costPairRelevant = $costCurrency !== $invoiceCurrency
                && $pairMatches($costCurrency, $invoiceCurrency, $fromCurrency, $toCurrency);
            $serviceChargePairRelevant = $serviceChargeCurrency !== $invoiceCurrency
                && $pairMatches($serviceChargeCurrency, $invoiceCurrency, $fromCurrency, $toCurrency);
            if (! $costPairRelevant && ! $serviceChargePairRelevant) {
                continue;
            }

            $costRate = $costPairRelevant
                ? $exchangeRateRepository->getBookingRate($bookingId, $costCurrency, $invoiceCurrency)
                : null;
            $serviceChargeRate = $serviceChargePairRelevant
                ? $exchangeRateRepository->getBookingRate($bookingId, $serviceChargeCurrency, $invoiceCurrency)
                : null;

            $newCostRate = $costRate !== null ? round((float) ($costRate['exchange_rate'] ?? 0), 8) : 0.0;
            $newServiceChargeRate = $serviceChargeRate !== null ? round((float) ($serviceChargeRate['exchange_rate'] ?? 0), 8) : 0.0;
            $costPairChanged = $costCurrency !== $invoiceCurrency
                && $newCostRate > 0
                && abs($newCostRate - (float) ($service['pricing_exchange_rate'] ?? 0)) > 0.00000001;
            $serviceChargePairChanged = $serviceChargeCurrency !== $invoiceCurrency
                && $newServiceChargeRate > 0
                && abs($newServiceChargeRate - (float) ($service['service_charge_exchange_rate'] ?? 0)) > 0.00000001;

            if (! $costPairChanged && ! $serviceChargePairChanged) {
                continue;
            }

            $this->assertFinancialAdminActor(
                $actorUserId,
                'Only super admin or branch admin can change a saved booking exchange rate.'
            );

            $serviceType = (string) ($service['service_type'] ?? 'air ticket');
            $costBasis = $serviceType === 'air ticket'
                ? (float) ($service['sale_price'] ?? 0)
                : (float) ($service['purchase_cost'] ?? 0);
            $rateDate = (string) (
                ($costPairChanged ? ($costRate['effective_date'] ?? null) : null)
                ?? ($serviceChargeRate['effective_date'] ?? null)
                ?? date('Y-m-d')
            );
            $correctionInput = [
                'booking_id' => $bookingId,
                'service_id' => (int) ($service['id'] ?? 0),
                'financial_correction_date' => date('Y-m-d'),
                'financial_correction_reason' => sprintf(
                    'Booking exchange rate corrected: 1 %s = %s %s.',
                    $fromCurrency,
                    rtrim(rtrim(number_format(
                        (float) ($editedRate['exchange_rate'] ?? 0),
                        8,
                        '.',
                        ''
                    ), '0'), '.'),
                    $toCurrency
                ),
                'corrected_invoice_currency' => $invoiceCurrency,
                'corrected_cost_currency' => $costCurrency,
                'corrected_service_charge_currency' => $serviceChargeCurrency,
                'corrected_cost_basis' => $costBasis,
                'corrected_service_charge' => (float) ($service['service_charge'] ?? 0),
                'corrected_discount_amount' => (float) ($service['discount_amount'] ?? 0),
            ];
            if ($costPairChanged) {
                $correctionInput['corrected_pricing_exchange_rate'] = $newCostRate;
                $correctionInput['corrected_pricing_rate_effective_date'] = $rateDate;
            }
            if ($serviceChargePairChanged) {
                $correctionInput['corrected_service_charge_exchange_rate'] = $newServiceChargeRate;
                $correctionInput['corrected_service_charge_rate_effective_date'] = $rateDate;
            }

            $this->correctFinancials($correctionInput, $actorUserId, $accessibleBranchIds);
            $updatedServices++;
        }

        return ['updated_services' => $updatedServices];
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

        if ((new BookingServiceEventRepository($this->app))->hasPostedCancellationSettlement($serviceId)) {
            throw new RuntimeException('A settled cancellation cannot be deactivated. Use the controlled cancellation/refund reversal workflow.');
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

        if ($this->isCancelledStatus((string) ($existingService['service_status'] ?? ''))) {
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

    public function refundService(
        array $input,
        int $actorUserId,
        array $accessibleBranchIds,
        bool $trustedExistingRefundCorrection = false
    ): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['refund_reason'] ?? null, 1000, 'Refund reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['refund_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $paymentMethod = $this->normalizePaymentMethod((string) ($input['refund_payment_method'] ?? 'bank_transfer'));
        $supplierRefundPaymentMethod = $this->normalizeSupplierRefundPaymentMethod((string) (
            $input['supplier_refund_payment_method']
            ?? $input['refund_payment_method']
            ?? 'bank_transfer'
        ));
        $supplierRefundRetainedAsCredit = $supplierRefundPaymentMethod === 'supplier_credit';
        $refundEditScope = strtolower(trim((string) ($input['refund_edit_scope'] ?? '')));
        $refundEditMode = in_array($refundEditScope, ['customer', 'supplier'], true);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot refund services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        $customerPaymentRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $customerRepository = new CustomerPaymentRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be refunded.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
        $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
        if (! is_array($cancelPayload)) {
            $cancelPayload = [];
        }
        $cancellationFinanciallySettled = $cancelEvent !== null && (
            (string) ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
            || array_key_exists('customer_final_charge_amount', $cancelPayload)
            || array_key_exists('expected_supplier_refund_amount', $cancelPayload)
            || array_key_exists('supplier_penalty_amount', $cancelPayload)
        );
        if (! $this->isCancelledStatus((string) ($existingService['service_status'] ?? '')) || ! $cancellationFinanciallySettled) {
            throw new RuntimeException('Settle the service cancellation before posting a customer or supplier refund.');
        }

        $bookingReference = (string) $booking['booking_reference'];
        $currency = (string) ($existingService['currency'] ?? 'PKR');
        if ($trustedExistingRefundCorrection) {
            $currencyOverride = strtoupper(trim((string) ($input['_refund_booking_currency_override'] ?? '')));
            if (in_array($currencyOverride, self::ALLOWED_CURRENCIES, true)) {
                $currency = $currencyOverride;
            }
        }
        $customerRefundCurrency = $this->resolveCustomerRefundTruthCurrency($cancelPayload, $currency, (string) ($cancelEvent['currency'] ?? ''));
        $customerRefundAmount = $this->customerRefundAmountInBookingCurrency($input, 'customer_refund_amount');
        if ($customerRefundAmount > 0.005) {
            $this->assertCustomerReceivableCurrencyMatchesInvoice(
                $customerRepository->findReceivableByServiceLine(
                    $bookingReference,
                    (string) ($existingService['line_reference'] ?? '')
                ),
                $currency
            );
        }
        if (! $trustedExistingRefundCorrection && ($input['supplier_refund_amount_basis'] ?? '') === 'expected_source_derived') {
            throw new RuntimeException('Invalid supplier refund amount basis. Refresh this booking before posting.');
        }
        if (! $trustedExistingRefundCorrection && ($input['supplier_refund_amount_basis'] ?? '') === 'expected_source') {
            $input = $this->normalizeExpectedSourceSupplierRefundInput(
                $input,
                $cancelPayload,
                $currency,
                (string) ($existingService['cost_currency'] ?? $currency)
            );
        }
        $supplierRefundAmount = $this->refundAmountInBookingCurrency($input, 'supplier_refund_amount', $currency);
        if ($supplierRefundAmount > 0.005) {
            $this->assertSupplierObligationCurrencyMatchesCost(
                $supplierRepository->findObligationByServiceLine(
                    $bookingReference,
                    (string) ($existingService['line_reference'] ?? '')
                ),
                $this->resolveSupplierSettlementTruthCurrency($existingService, $currency)
            );
        }
        if ($supplierRefundAmount > 0.005 && ! $trustedExistingRefundCorrection) {
            $expectedSupplierCurrency = $this->normalizeCurrency((string) (
                $cancelPayload['expected_supplier_refund_currency']
                ?? $existingService['cost_currency']
                ?? $currency
            ));
            $actualSupplierCurrency = $this->normalizeCurrency((string) ($input['supplier_refund_currency'] ?? $currency));
            if ($actualSupplierCurrency !== $expectedSupplierCurrency) {
                throw new RuntimeException('Supplier refund must use the saved expected currency. Correct the cancellation expectation first if the supplier actually used another currency.');
            }
            if ($expectedSupplierCurrency !== $currency
                && ($input['supplier_refund_amount_basis'] ?? '') !== 'expected_source_derived') {
                throw new RuntimeException('Refresh this booking and enter the supplier refund in the expected supplier currency.');
            }
        }

        if (! $refundEditMode && ! $trustedExistingRefundCorrection && $customerRefundAmount > 0.005) {
            $customerRefundTreatment = strtolower(trim((string) ($input['customer_refund_treatment'] ?? '')));
            if ($customerRefundTreatment !== 'pay_now') {
                throw new RuntimeException('Select Pay Customer Now before posting money as returned to the customer. Otherwise keep it as available customer credit.');
            }
        }

        if ($refundEditMode) {
            if ($refundEditScope === 'customer') {
                $supplierRefundAmount = 0.0;
            } else {
                $customerRefundAmount = 0.0;
            }
        }

        BookingEditTraceService::checkpoint('ServiceWorkspaceService::refundAmounts', 'resolved', [
            'service_id' => $serviceId,
            'refund_edit_scope' => $refundEditScope,
            'customer_truth_currency' => $customerRefundCurrency,
            'customer_booking_amount' => $customerRefundAmount,
            'customer_payout_currency' => (string) ($input['customer_refund_currency'] ?? $customerRefundCurrency),
            'supplier_booking_amount' => $supplierRefundAmount,
            'supplier_source_currency' => (string) ($input['supplier_refund_currency'] ?? $currency),
            'supplier_method' => $supplierRefundPaymentMethod,
        ]);

        if ($customerRefundAmount <= 0 && $supplierRefundAmount <= 0) {
            throw new RuntimeException('Enter a customer refund or supplier refund amount.');
        }

        $customerRefundDetailPayload = $this->normalizedRefundDetailPayload(
            $input,
            $paymentMethod,
            (int) ($existingService['branch_id'] ?? 0),
            $customerRefundCurrency,
            $customerRefundAmount,
            'refund_treasury_account_id',
            true,
            null,
            null,
            null
        );
        $supplierRefundInput = $input;
        if (! array_key_exists('supplier_refund_treasury_account_id', $supplierRefundInput)
            && array_key_exists('refund_treasury_account_id', $supplierRefundInput)
        ) {
            $supplierRefundInput['supplier_refund_treasury_account_id'] = $supplierRefundInput['refund_treasury_account_id'];
        }
        $supplierRefundDetailPayload = $this->normalizedRefundDetailPayload(
            $supplierRefundInput,
            $supplierRefundPaymentMethod,
            (int) ($existingService['branch_id'] ?? 0),
            $currency,
            $supplierRefundAmount,
            'supplier_refund_treasury_account_id',
            false
        );
        if ($supplierRefundAmount > 0.005 && ! $supplierRefundRetainedAsCredit
            && ($supplierRefundDetailPayload['refund_currency'] ?? $currency) !== $currency
            && ! in_array($supplierRefundPaymentMethod, ['cash', 'bank_transfer'], true)) {
            throw new RuntimeException('A cross-currency supplier money refund requires a cash or bank account in the actual refund currency.');
        }
        if ($supplierRefundAmount > 0.005 && ! $supplierRefundRetainedAsCredit
            && in_array($supplierRefundPaymentMethod, ['cash', 'bank_transfer'], true)) {
            $refundTreasury = (array) ($supplierRefundDetailPayload['treasury_account_snapshot'] ?? []);
            if (strtoupper((string) ($refundTreasury['currency'] ?? '')) !== strtoupper((string) ($supplierRefundDetailPayload['refund_currency'] ?? ''))
                || (int) ($refundTreasury['branch_id'] ?? 0) !== (int) ($existingService['branch_id'] ?? 0)) {
                throw new RuntimeException('Supplier cash refund account must match the actual refund currency and booking branch. No refund was posted.');
            }
        }
        $supplierId = (int) ($existingService['supplier_id'] ?? 0);

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        } else {
            $db->exec('SAVEPOINT service_refund_post');
        }

        $accountingRepository = new AccountingRepository($this->app);

        try {
            if ($refundEditMode) {
                $targetRefundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, $refundEditScope);
                if ($targetRefundEvent === null) {
                    throw new RuntimeException($refundEditScope === 'customer'
                        ? 'There is no posted customer refund to edit for this service.'
                        : 'There is no posted supplier refund received to edit for this service.');
                }

                $this->reverseRefundMutation(
                    $bookingId,
                    $serviceId,
                    $targetRefundEvent,
                    $refundEditScope,
                    $reason,
                    $eventDate,
                    $actorUserId,
                    $bookingReference,
                    $existingService
                );
            }

            $customerCreditBalance = $accountingRepository->accountNetBalanceForBooking($bookingReference, $customerRefundCurrency, 'CUSTOMER_CREDIT');
            $supplierAdvanceBalance = $accountingRepository->accountNetBalanceForBooking($bookingReference, $currency, 'SUPPLIER_ADVANCES');
            $serviceObligation = $supplierRepository->findObligationByServiceLine(
                $bookingReference,
                (string) ($existingService['line_reference'] ?? '')
            );
            $supplierRefundState = $this->supplierRecoveryStateForService(
                $serviceId,
                $bookingReference,
                $currency,
                $serviceObligation,
                new BookingServiceEventRepository($this->app),
                $existingService
            );
            $customerRefundState = $this->customerRefundStateForService(
                $serviceId,
                $bookingReference,
                $customerRefundCurrency,
                $cancelPayload,
                $cancelEvent,
                $eventRepository,
                $customerRepository,
                $supplierRefundState
            );
            $customerRefundableBalance = round(max(
                (float) ($customerRefundState['service_remaining_customer_refund_credit'] ?? 0),
                0
            ), 0);
            $customerReceiptCreditCurrency = $this->resolveCustomerRefundReceiptCreditCurrency($existingService, $cancelPayload);
            $customerReceiptCreditAvailable = round(max(
                $customerRepository->bookingUnallocatedCreditTotal($bookingReference, $customerReceiptCreditCurrency),
                0
            ), 2);
            $customerReceiptCreditConsumptionAmount = $this->determineCustomerRefundReceiptCreditConsumptionAmount(
                $customerRefundAmount,
                (float) ($customerRefundState['service_remaining_customer_refund_credit'] ?? 0),
                $customerReceiptCreditAvailable
            );
            $allowSyntheticSettlementCredit = $customerRefundAmount > 0.005
                && $customerReceiptCreditConsumptionAmount <= 0.005
                && (float) ($customerRefundState['service_remaining_customer_refund_credit'] ?? 0) > 0.005;
            $supplierRefundableBalance = $supplierRefundState['remaining_expected_supplier_refund'];
            if ($supplierRefundAmount > 0.005 && array_key_exists('supplier_credit_recording_version', $cancelPayload)) {
                throw new RuntimeException('Supplier refund was settled as payable reduction or reusable credit when cancellation was saved. Do not record it again.');
            }

            if ($customerRefundAmount > $customerRefundableBalance + 0.005) {
                app_write_log('service.refund.customer_credit_exceeded', 'Customer refund was blocked because the requested amount exceeded the available customer refund credit.', array_merge(
                    app_request_log_context(),
                    [
                        'booking_id' => $bookingId,
                        'booking_reference' => $bookingReference,
                        'service_id' => $serviceId,
                        'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                        'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                        'currency' => $currency,
                        'refund_event_date' => $eventDate,
                        'payment_method' => $paymentMethod,
                        'requested_customer_refund' => $customerRefundAmount,
                        'customer_refundable_balance' => $customerRefundableBalance,
                        'customer_credit_balance_ledger' => $customerCreditBalance,
                        'customer_booking_credit_available' => (float) ($customerRefundState['remaining_customer_refund_credit'] ?? 0),
                        'customer_released_credit' => (float) ($customerRefundState['released_customer_credit'] ?? 0),
                        'customer_already_refunded_amount' => (float) ($customerRefundState['customer_refund_received_amount'] ?? 0),
                        'customer_service_refund_remaining' => (float) ($customerRefundState['service_remaining_customer_refund_credit'] ?? 0),
                        'customer_receipt_credit_currency' => $customerReceiptCreditCurrency,
                        'customer_receipt_credit_available' => $customerReceiptCreditAvailable,
                        'customer_receipt_credit_consumption_amount' => $customerReceiptCreditConsumptionAmount,
                        'customer_receipt_credit_synthetic' => $allowSyntheticSettlementCredit,
                        'cancel_event_id' => (int) ($cancelEvent['id'] ?? 0),
                        'cancel_event_customer_credit_amount' => (float) ($cancelEvent['customer_credit_amount'] ?? 0),
                        'cancel_payload_available_customer_refund_credit' => (float) ($cancelPayload['available_customer_refund_credit'] ?? 0),
                        'cancel_payload_released_customer_credit' => (float) ($cancelPayload['released_customer_credit'] ?? 0),
                        'cancel_payload_customer_refund_basis' => (float) ($cancelPayload['customer_refund_basis'] ?? 0),
                        'cancel_payload_customer_received_amount' => (float) ($cancelPayload['customer_received_amount'] ?? 0),
                        'cancel_payload_customer_final_charge_amount' => (float) ($cancelPayload['customer_final_charge_amount'] ?? 0),
                        'cancel_payload_customer_penalty_amount' => (float) ($cancelPayload['customer_penalty_amount'] ?? 0),
                        'cancel_payload_target_customer_refund_credit' => (float) ($cancelPayload['target_customer_refund_credit'] ?? 0),
                        'raw_customer_refund_amount_input' => (string) ($input['customer_refund_amount'] ?? ''),
                        'raw_customer_refund_currency_input' => (string) ($input['customer_refund_currency'] ?? ''),
                        'raw_customer_refund_source_amount_input' => (string) ($input['customer_refund_source_amount'] ?? ''),
                        'raw_customer_refund_exchange_rate_input' => (string) ($input['customer_refund_exchange_rate'] ?? ''),
                        'trusted_existing_refund_correction' => $trustedExistingRefundCorrection,
                        'refund_edit_mode' => $refundEditMode,
                        'refund_edit_scope' => $refundEditScope,
                    ]
                ));
                throw new RuntimeException('Customer refund exceeds available customer credit for this booking and currency.');
            }

            AuditLog::record($this->app, 'service.refund.credit_snapshot', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'currency' => $customerRefundCurrency,
                'customer_credit_balance' => $customerCreditBalance,
                'customer_refundable_balance' => $customerRefundableBalance,
                'customer_booking_credit_available' => $customerRefundState['remaining_customer_refund_credit'],
                'customer_released_credit' => $customerRefundState['released_customer_credit'],
                'customer_already_refunded_amount' => $customerRefundState['customer_refund_received_amount'],
                'customer_service_refund_remaining' => $customerRefundState['service_remaining_customer_refund_credit'],
                'supplier_advance_ledger_balance' => $supplierAdvanceBalance,
                'supplier_refundable_repository_balance' => $supplierRefundState['repository_credit_balance'],
                'supplier_expected_refund_amount' => $supplierRefundState['expected_supplier_refund'],
                'supplier_expected_refund_remaining' => $supplierRefundState['remaining_expected_supplier_refund'],
                'supplier_settled_amount' => $supplierRefundState['supplier_settled_amount'],
                'supplier_already_refunded_amount' => $supplierRefundState['supplier_refund_received_amount'],
                'requested_customer_refund' => $customerRefundAmount,
                'requested_supplier_refund' => $supplierRefundAmount,
            ]);

            if ($supplierRefundAmount > $supplierRefundableBalance + 0.005) {
                throw new RuntimeException('Supplier refund received exceeds available supplier refundable amount for this service.');
            }

            $refundPayloadBase = [
                'payment_method' => $paymentMethod,
                'supplier_refund_payment_method' => $supplierRefundPaymentMethod,
                'customer_credit_balance_before' => $customerCreditBalance,
                'customer_refundable_balance_before' => $customerRefundableBalance,
                'customer_booking_credit_available_before' => $customerRefundState['remaining_customer_refund_credit'],
                'customer_receipt_credit_currency' => $customerReceiptCreditCurrency,
                'customer_receipt_credit_available_before' => $customerReceiptCreditAvailable,
                'customer_receipt_credit_consumption_amount' => $customerReceiptCreditConsumptionAmount,
                'customer_receipt_credit_synthetic' => $allowSyntheticSettlementCredit,
                'supplier_advance_balance_before' => $supplierAdvanceBalance,
                'supplier_refundable_balance_before' => $supplierRefundableBalance,
                'supplier_expected_refund_amount' => $supplierRefundState['expected_supplier_refund'],
                'supplier_expected_refund_remaining' => $supplierRefundState['remaining_expected_supplier_refund'],
                'supplier_settled_amount' => $supplierRefundState['supplier_settled_amount'],
                'supplier_refund_received_amount_before' => $supplierRefundState['supplier_refund_received_amount'],
                'customer_refund_detail' => $customerRefundDetailPayload,
                'supplier_refund_detail' => $supplierRefundDetailPayload,
                'refund_edit_mode' => $refundEditMode,
                'refund_edit_scope' => $refundEditMode ? $refundEditScope : null,
            ];
            $createdRefundEvents = [];

            if ($customerRefundAmount > 0) {
                $customerEventId = $eventRepository->createEvent([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_id' => $bookingId,
                    'booking_service_id' => $serviceId,
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => (string) $existingService['line_reference'],
                    'event_type' => 'refund',
                    'event_status' => 'posted',
                    'event_date' => $eventDate,
                    'currency' => $customerRefundCurrency,
                    'original_ticket_number' => $existingService['ticket_number'] ?? null,
                    'original_pnr' => $existingService['pnr'] ?? null,
                    'customer_refund_amount' => $customerRefundAmount,
                    'supplier_refund_amount' => 0,
                    'reason' => $reason,
                    'notes' => $this->optionalText($input['refund_notes'] ?? null, 4000),
                    'payload_json' => $refundPayloadBase + [
                        'refund_scope' => 'customer',
                        'refund_detail' => $customerRefundDetailPayload,
                    ],
                    'actor_user_id' => $actorUserId,
                ]);
                (new BookingServiceRefundDetailRepository($this->app))->upsertForServiceEvent($customerEventId, array_merge(
                    $customerRefundDetailPayload,
                    ['actor_user_id' => $actorUserId]
                ));
                $customerJournalData = [
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => (string) $existingService['line_reference'],
                    'source_reference' => 'REFUND-EVT-' . $customerEventId,
                    'entry_date' => $eventDate,
                    'currency' => $customerRefundCurrency,
                    'payment_method' => $paymentMethod,
                    'treasury_account_id' => $customerRefundDetailPayload['treasury_account_id'] ?? null,
                    'customer_refund_amount' => $customerRefundAmount,
                    'refund_currency' => $customerRefundDetailPayload['refund_currency'],
                    'refund_source_amount' => $customerRefundDetailPayload['refund_source_amount'],
                    'supplier_refund_amount' => 0,
                    'narration' => 'Customer refund posted for ' . (string) $existingService['line_reference'],
                    'actor_user_id' => $actorUserId,
                ];
                if ($customerRefundDetailPayload['refund_currency'] !== $customerRefundCurrency) {
                    $journals = $accountingRepository->postCrossCurrencyCustomerRefund($customerJournalData);
                    $customerJournalEntryId = $journals['credit_journal_id'];
                    $eventRepository->mergePayload($customerEventId, [
                        'customer_refund_treasury_journal_id' => $journals['cash_journal_id'],
                    ]);
                } else {
                    $customerJournalEntryId = $accountingRepository->postServiceRefund($customerJournalData);
                }
                $eventRepository->attachJournalEntry($customerEventId, $customerJournalEntryId);
                $createdRefundEvents[] = ['id' => $customerEventId, 'journal_entry_id' => $customerJournalEntryId, 'scope' => 'customer'];
            }

            if ($supplierRefundAmount > 0) {
                $supplierEventId = $eventRepository->createEvent([
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
                    'customer_refund_amount' => 0,
                    'supplier_refund_amount' => $supplierRefundAmount,
                    'reason' => $reason,
                    'notes' => $this->optionalText($input['refund_notes'] ?? null, 4000),
                    'payload_json' => $refundPayloadBase + [
                        'refund_scope' => 'supplier',
                        'refund_detail' => $supplierRefundDetailPayload,
                    ],
                    'actor_user_id' => $actorUserId,
                ]);
                (new BookingServiceRefundDetailRepository($this->app))->upsertForServiceEvent($supplierEventId, array_merge(
                    $supplierRefundDetailPayload,
                    ['actor_user_id' => $actorUserId]
                ));
                $supplierJournalEntryId = null;
                if (! $supplierRefundRetainedAsCredit) {
                    $supplierRefundConsumptionCurrency = strtoupper((string) ($supplierRefundDetailPayload['refund_currency'] ?? $currency));
                    $supplierRefundConsumptionAmount = $supplierRefundConsumptionCurrency === $currency
                        ? $supplierRefundAmount
                        : round((float) ($supplierRefundDetailPayload['refund_source_amount'] ?? 0), 2);
                    if ($supplierRefundConsumptionAmount <= 0.005) {
                        throw new RuntimeException('Supplier refund requires a positive amount in the actual supplier refund currency.');
                    }
                    $supplierRefundRepositoryAvailable = $supplierRepository->availableRefundableCreditForService(
                        $supplierId,
                        $bookingReference,
                        $supplierRefundConsumptionCurrency,
                        (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
                    );
                    if ($supplierRefundConsumptionCurrency !== $currency
                        && $supplierRefundRepositoryAvailable + 0.005 < $supplierRefundConsumptionAmount) {
                        throw new RuntimeException('The supplier cash refund is not backed by released payment credit in '
                            . $supplierRefundConsumptionCurrency . '. No refund was posted.');
                    }
                    $supplierRefundConsumptionApplied = round(min(
                        $supplierRefundConsumptionAmount,
                        max($supplierRefundRepositoryAvailable, 0)
                    ), 2);

                    $supplierJournalEntryId = $accountingRepository->postServiceRefund([
                        'branch_id' => (int) $existingService['branch_id'],
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => (string) $existingService['line_reference'],
                        'source_reference' => 'REFUND-EVT-' . $supplierEventId,
                        'entry_date' => $eventDate,
                        'currency' => $supplierRefundConsumptionCurrency,
                        'payment_method' => $supplierRefundPaymentMethod,
                        'treasury_account_id' => $supplierRefundDetailPayload['treasury_account_id'] ?? null,
                        'customer_refund_amount' => 0,
                        'supplier_refund_amount' => $supplierRefundConsumptionAmount,
                        'narration' => 'Supplier refund received posted for ' . (string) $existingService['line_reference'],
                        'actor_user_id' => $actorUserId,
                    ]);
                    $eventRepository->attachJournalEntry($supplierEventId, $supplierJournalEntryId);
                    $cashRefundCreditConsumption = $supplierRefundConsumptionApplied > 0.005
                        ? $supplierRepository->consumeRefundableCreditForService(
                            $supplierId,
                            $bookingReference,
                            $supplierRefundConsumptionCurrency,
                            $supplierRefundConsumptionApplied,
                            $actorUserId,
                            $reason,
                            (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
                        )
                        : [
                            'consumed_amount' => 0.0,
                            'consumed_payment_amount' => 0.0,
                            'consumed_advance_amount' => 0.0,
                            'payment_consumptions' => [],
                            'advance_consumptions' => [],
                            'affected_payment_ids' => [],
                            'affected_advance_ids' => [],
                        ];
                    $eventRepository->updateRefundFinancials($supplierEventId, [
                        'payload_patch' => [
                            'cash_refund_credit_consumption' => $cashRefundCreditConsumption,
                            'cash_refund_credit_consumption_currency' => $supplierRefundConsumptionCurrency,
                            'cash_refund_credit_consumption_amount' => $supplierRefundConsumptionApplied,
                            'cash_refund_credit_consumption_requested_amount' => $supplierRefundConsumptionAmount,
                            'cash_refund_credit_repository_available_before' => $supplierRefundRepositoryAvailable,
                        ],
                    ]);
                } else {
                    $retainedCreditCurrency = (string) ($supplierRefundDetailPayload['refund_currency'] ?? $currency);
                    $retainedCreditSourceAmount = $retainedCreditCurrency === $currency
                        ? $supplierRefundAmount
                        : round((float) ($supplierRefundDetailPayload['refund_source_amount'] ?? 0), 2);
                    $crossCurrencyRetainedCredit = $retainedCreditCurrency !== $currency;
                    $retainedCreditConversion = $supplierRepository->convertRefundablePaymentCreditToAdvance(
                        $supplierId,
                        (int) ($existingService['branch_id'] ?? 0),
                        $bookingReference,
                        $retainedCreditCurrency,
                        $retainedCreditSourceAmount,
                        $eventDate,
                        'REFUND-EVT-' . $supplierEventId,
                        $actorUserId,
                        $reason,
                        (array) ($cancelPayload['released_supplier_payment_ids'] ?? []),
                        $crossCurrencyRetainedCredit
                    );
                    if ($crossCurrencyRetainedCredit
                        && abs((float) ($retainedCreditConversion['converted_payment_credit_amount'] ?? 0) - $retainedCreditSourceAmount) > 0.005) {
                        throw new RuntimeException('The supplier refund is not fully backed by released payment credit in ' . $retainedCreditCurrency . '. No refund was posted.');
                    }
                    $retainedCreditConversion['currency'] = $retainedCreditCurrency;
                    $retainedCreditConversion['booking_equivalent_amount'] = $supplierRefundAmount;
                    $retainedCreditConversion['automatic_applications'] = [];
                    $retainedCreditConversion['automatic_applied_amount'] = 0.0;
                    $eventRepository->updateRefundFinancials($supplierEventId, [
                        'payload_patch' => ['retained_credit_conversion' => $retainedCreditConversion],
                    ]);
                }
                $createdRefundEvents[] = ['id' => $supplierEventId, 'journal_entry_id' => $supplierJournalEntryId, 'scope' => 'supplier'];
            }

            if ($customerRefundAmount > 0) {
                if ($customerReceiptCreditConsumptionAmount > 0.005) {
                    $appliedCustomerCredit = $customerPaymentRepository->applyRefundAgainstBookingReceiptCredit(
                        $bookingReference,
                        $customerReceiptCreditCurrency,
                        $customerReceiptCreditConsumptionAmount,
                        $actorUserId,
                        $reason,
                        false
                    );
                    $eventRepository->mergePayload($customerEventId, [
                        'customer_receipt_credit_consumptions' => $appliedCustomerCredit['receipt_consumptions'] ?? [],
                    ]);
                    app_write_log('service.refund.customer_receipt_credit_consumed', 'Applied released booking credit against the posted customer refund.', array_merge(
                        app_request_log_context(),
                        [
                            'booking_id' => $bookingId,
                            'booking_reference' => $bookingReference,
                            'service_id' => $serviceId,
                            'refund_currency' => $customerRefundCurrency,
                            'refund_amount' => $customerRefundAmount,
                            'receipt_credit_currency' => $customerReceiptCreditCurrency,
                            'receipt_credit_consumption_amount' => $customerReceiptCreditConsumptionAmount,
                            'receipt_credit_available_before' => $customerReceiptCreditAvailable,
                            'affected_receipt_ids' => $appliedCustomerCredit['affected_receipt_ids'] ?? [],
                        ]
                    ));
                } elseif ($allowSyntheticSettlementCredit) {
                    app_write_log('service.refund.customer_receipt_credit_synthetic', 'Customer refund relied on historical synthetic credit because no released booking credit remained to consume.', array_merge(
                        app_request_log_context(),
                        [
                            'booking_id' => $bookingId,
                            'booking_reference' => $bookingReference,
                            'service_id' => $serviceId,
                            'refund_currency' => $customerRefundCurrency,
                            'refund_amount' => $customerRefundAmount,
                            'receipt_credit_currency' => $customerReceiptCreditCurrency,
                            'receipt_credit_available_before' => $customerReceiptCreditAvailable,
                        ]
                    ));
                }
            }

            if ($supplierRefundAmount > 0) {
                AuditLog::record($this->app, 'service.refund.supplier_receivable_received', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'service_id' => $serviceId,
                        'service_event_id' => $createdRefundEvents[0]['id'] ?? null,
                    'supplier_id' => $supplierId,
                    'currency' => $currency,
                    'supplier_refund_amount' => $supplierRefundAmount,
                    'supplier_expected_refund_remaining_before' => $supplierRefundableBalance,
                    'supplier_expected_refund_remaining_after' => round(max($supplierRefundableBalance - $supplierRefundAmount, 0), 2),
                ]);
            }

            AuditLog::record($this->app, 'service.refund.posted', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_event_id' => $createdRefundEvents[0]['id'] ?? null,
                'service_event_ids' => array_map(static fn (array $row): int => (int) $row['id'], $createdRefundEvents),
                'journal_entry_id' => $createdRefundEvents[0]['journal_entry_id'] ?? null,
                'journal_entry_ids' => array_map(static fn (array $row): int => (int) $row['journal_entry_id'], $createdRefundEvents),
                'service_line_reference' => (string) $existingService['line_reference'],
                'currency' => $customerRefundCurrency,
                'customer_refund_amount' => $customerRefundAmount,
                'supplier_refund_amount' => $supplierRefundAmount,
                'payment_method' => $paymentMethod,
                'supplier_refund_payment_method' => $supplierRefundPaymentMethod,
                'supplier_refund_retained_as_credit' => $supplierRefundRetainedAsCredit,
                'treasury_account_id' => $customerRefundDetailPayload['treasury_account_id'] ?? null,
                'supplier_refund_treasury_account_id' => $supplierRefundDetailPayload['treasury_account_id'] ?? null,
                'customer_refund_detail' => $customerRefundDetailPayload,
                'supplier_refund_detail' => $supplierRefundDetailPayload,
                'refund_edit_mode' => $refundEditMode,
                'refund_edit_scope' => $refundEditMode ? $refundEditScope : null,
                'reason' => $reason,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            } elseif ($db->inTransaction()) {
                $db->exec('RELEASE SAVEPOINT service_refund_post');
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            } elseif ($db->inTransaction()) {
                $db->exec('ROLLBACK TO SAVEPOINT service_refund_post');
                $db->exec('RELEASE SAVEPOINT service_refund_post');
            }

            throw $exception;
        }

        return $bookingId;
    }

    private function latestRefundEventForScope(
        BookingServiceEventRepository $eventRepository,
        int $serviceId,
        string $refundScope
    ): ?array {
        foreach (array_reverse($eventRepository->postedEventsForService($serviceId)) as $serviceEventRow) {
            if ((string) ($serviceEventRow['event_type'] ?? '') !== 'refund') {
                continue;
            }

            if (
                $refundScope === 'customer'
                && round((float) ($serviceEventRow['customer_refund_amount'] ?? 0), 2) > 0.0
            ) {
                return $serviceEventRow;
            }

            if (
                $refundScope === 'supplier'
                && round((float) ($serviceEventRow['supplier_refund_amount'] ?? 0), 2) > 0.0
            ) {
                return $serviceEventRow;
            }
        }

        return null;
    }

    private function refundCorrectionScopeChanged(
        array $input,
        ?array $refundEvent,
        BookingServiceRefundDetailRepository $detailRepository,
        string $refundScope,
        float $requestedAmount,
        int $branchId,
        string $accountingCurrency
    ): bool {
        if ($refundEvent === null) {
            return false;
        }

        $amountField = $refundScope === 'customer' ? 'customer_refund_amount' : 'supplier_refund_amount';
        $existingAmount = round((float) ($refundEvent[$amountField] ?? 0), 2);
        if (abs($existingAmount - $requestedAmount) > 0.005) {
            return true;
        }

        $currentDetail = $detailRepository->findByServiceEventId((int) ($refundEvent['id'] ?? 0));
        if ($currentDetail === null) {
            return true;
        }

        if ($refundScope === 'customer') {
            $paymentMethod = $this->normalizePaymentMethod((string) (
                $input['refund_payment_method']
                ?? $currentDetail['refund_payment_method']
                ?? 'bank_transfer'
            ));
            $desiredDetail = $this->normalizedRefundDetailPayload(
                $input,
                $paymentMethod,
                $branchId,
                $accountingCurrency,
                $requestedAmount,
                'refund_treasury_account_id',
                true,
                null,
                null,
                null
            );
        } else {
            $paymentMethod = $this->normalizeSupplierRefundPaymentMethod((string) (
                $input['supplier_refund_payment_method']
                ?? $currentDetail['refund_payment_method']
                ?? 'bank_transfer'
            ));
            $desiredDetail = $this->normalizedRefundDetailPayload(
                $input,
                $paymentMethod,
                $branchId,
                $accountingCurrency,
                $requestedAmount,
                'supplier_refund_treasury_account_id',
                false
            );
        }

        return ! $this->refundDetailsMatch($currentDetail, $desiredDetail);
    }

    private function refundDetailsMatch(array $current, array $desired): bool
    {
        foreach (['treasury_account_id'] as $field) {
            if ((int) ($current[$field] ?? 0) !== (int) ($desired[$field] ?? 0)) {
                return false;
            }
        }

        foreach (['refund_source_amount', 'charges'] as $field) {
            if (abs((float) ($current[$field] ?? 0) - (float) ($desired[$field] ?? 0)) > 0.005) {
                return false;
            }
        }

        if (abs((float) ($current['refund_exchange_rate_to_booking'] ?? 0) - (float) ($desired['refund_exchange_rate_to_booking'] ?? 0)) > 0.00000001) {
            return false;
        }

        foreach ([
            'refund_payment_method',
            'refund_currency',
            'customer_bank_name',
            'customer_bank_account_title',
            'customer_bank_account_no',
            'customer_bank_iban',
            'transfer_reference',
        ] as $field) {
            if (trim((string) ($current[$field] ?? '')) !== trim((string) ($desired[$field] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function postedRefundProtectionSnapshot(int $serviceId): array
    {
        $eventRepository = new BookingServiceEventRepository($this->app);
        $detailRepository = new BookingServiceRefundDetailRepository($this->app);
        $snapshot = [];

        foreach ($eventRepository->postedEventsForService($serviceId) as $event) {
            if ((string) ($event['event_type'] ?? '') !== 'refund') {
                continue;
            }

            $eventId = (int) ($event['id'] ?? 0);
            $detail = $detailRepository->findByServiceEventId($eventId) ?? [];
            $snapshot[] = [
                'id' => $eventId,
                'currency' => (string) ($event['currency'] ?? ''),
                'customer_refund_amount' => round((float) ($event['customer_refund_amount'] ?? 0), 2),
                'supplier_refund_amount' => round((float) ($event['supplier_refund_amount'] ?? 0), 2),
                'journal_entry_id' => (int) ($event['journal_entry_id'] ?? 0),
                'reversal_journal_entry_id' => (int) ($event['reversal_journal_entry_id'] ?? 0),
                'treasury_account_id' => (int) ($detail['treasury_account_id'] ?? 0),
                'refund_payment_method' => (string) ($detail['refund_payment_method'] ?? ''),
                'refund_currency' => (string) ($detail['refund_currency'] ?? ''),
                'refund_source_amount' => round((float) ($detail['refund_source_amount'] ?? 0), 2),
                'refund_exchange_rate_to_booking' => round((float) ($detail['refund_exchange_rate_to_booking'] ?? 0), 8),
            ];
        }

        return $snapshot;
    }

    private function assertPostedRefundProtectionSnapshot(int $serviceId, array $before): void
    {
        if ($before !== $this->postedRefundProtectionSnapshot($serviceId)) {
            throw new RuntimeException('Posted refund history is protected and could not be changed by this service or currency edit.');
        }
    }

    private function reverseRefundMutation(
        int $bookingId,
        int $serviceId,
        array $refundEvent,
        string $refundScope,
        string $reason,
        string $eventDate,
        int $actorUserId,
        string $bookingReference,
        array $existingService
    ): void {
        $refundEventId = (int) ($refundEvent['id'] ?? 0);
        if ($refundEventId <= 0) {
            throw new RuntimeException('The selected refund event could not be found.');
        }

        $currency = (string) ($refundEvent['currency'] ?? $existingService['currency'] ?? 'PKR');
        $customerRefundAmount = round((float) ($refundEvent['customer_refund_amount'] ?? 0), 2);
        $supplierRefundAmount = round((float) ($refundEvent['supplier_refund_amount'] ?? 0), 2);
        $hasCustomerRefund = $customerRefundAmount > 0.005;
        $hasSupplierRefund = $supplierRefundAmount > 0.005;
        $refundPayload = json_decode((string) ($refundEvent['payload_json'] ?? ''), true);
        if (! is_array($refundPayload)) {
            $refundPayload = [];
        }
        $partialReversals = is_array($refundPayload['partial_reversals'] ?? null)
            ? $refundPayload['partial_reversals']
            : [];
        $isLegacyCombinedRefund = ($hasCustomerRefund && $hasSupplierRefund)
            || isset($partialReversals['customer'])
            || isset($partialReversals['supplier']);
        $restoreCustomerCreditCurrency = $this->normalizeCurrency((string) (
            $refundPayload['customer_receipt_credit_currency']
            ?? $existingService['currency']
            ?? $currency
        ));
        $restoreCustomerCreditAmount = round((float) ($refundPayload['customer_receipt_credit_consumption_amount'] ?? 0), 2);
        $syntheticCustomerCreditOnly = (bool) ($refundPayload['customer_receipt_credit_synthetic'] ?? false);
        if ($restoreCustomerCreditAmount <= 0.005 && $hasCustomerRefund && $this->refundPayloadUsedSyntheticCustomerCredit($refundPayload)) {
            $syntheticCustomerCreditOnly = true;
        }
        $allowSyntheticSettlementCredit = $hasCustomerRefund
            && (
                ((bool) ($refundPayload['customer_credit_balance_before'] ?? 0) === false)
                || (
                    (float) ($refundPayload['customer_credit_balance_before'] ?? 0) <= 0.005
                    && (float) ($refundPayload['customer_refundable_balance_before'] ?? 0) > 0.005
                )
            );
        $supplierRefundMethod = (string) (
            $refundPayload['supplier_refund_payment_method']
            ?? $refundPayload['refund_detail']['refund_payment_method']
            ?? ''
        );
        $supplierRefundRetainedAsCredit = $hasSupplierRefund && $supplierRefundMethod === 'supplier_credit';

        if ($refundScope === 'customer' && ! $hasCustomerRefund) {
            throw new RuntimeException('The selected refund event does not contain a posted customer refund.');
        }
        if ($refundScope === 'supplier' && ! $hasSupplierRefund) {
            throw new RuntimeException('The selected refund event does not contain a posted supplier refund received.');
        }
        if ($refundScope === 'customer' && $hasCustomerRefund) {
            $postedDetail = (new BookingServiceRefundDetailRepository($this->app))->findByServiceEventId($refundEventId);
            $actualCurrency = strtoupper((string) ($postedDetail['refund_currency'] ?? $currency));
            if ($actualCurrency !== strtoupper($currency)
                && (int) ($refundPayload['customer_refund_treasury_journal_id'] ?? 0) <= 0) {
                throw new RuntimeException('The cross-currency customer payout has no linked cash journal. No correction was made.');
            }
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        $latestScopeEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, $refundScope);
        $latestScopeEventId = (int) ($latestScopeEvent['id'] ?? 0);
        if ($latestScopeEventId > 0 && $latestScopeEventId !== $refundEventId) {
            throw new RuntimeException($refundScope === 'customer'
                ? 'Reverse the latest posted customer refund for this service first.'
                : 'Reverse the latest posted supplier refund received for this service first.');
        }

        if (isset($this->recoveryMoneyBudgets[$refundScope]) && ! $supplierRefundRetainedAsCredit) {
            if ($isLegacyCombinedRefund) {
                throw new RuntimeException('This older refund combines customer and supplier money. Review its payment references before complete reversal.');
            }
            if ((int) ($refundEvent['journal_entry_id'] ?? 0) <= 0) {
                throw new RuntimeException('The actual refund has no linked journal. Review its money record before reversing.');
            }
            $eventRepository->voidEvent($refundEventId, $reason, $actorUserId,
                'RECOVERY-RETAIN-MONEY-' . $refundEventId, null, [
                    'actual_money_preserved' => true, 'preserved_money_scope' => $refundScope,
                    'preserved_journal_entry_id' => (int) $refundEvent['journal_entry_id'],
                    'reversal_reason' => $reason,
                ]);
            return;
        }

        $customerPaymentRepository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $restoredCustomerCredit = [
            'restored_amount' => 0.0,
            'affected_receipt_ids' => [],
        ];

        if ($refundScope === 'supplier' && $supplierRefundRetainedAsCredit) {
            (new SupplierRepository($this->app))->reverseRetainedRefundAdvanceConversion(
                (array) ($refundPayload['retained_credit_conversion']['supplier_advance_ids'] ?? []),
                $actorUserId,
                $reason
            );
        } elseif ($refundScope === 'supplier' && $hasSupplierRefund) {
            $cashRefundConsumption = (array) ($refundPayload['cash_refund_credit_consumption'] ?? []);
            (new SupplierRepository($this->app))->restoreConsumedRefundableCredit(
                (array) ($cashRefundConsumption['payment_consumptions'] ?? []),
                (array) ($cashRefundConsumption['advance_consumptions'] ?? []),
                $actorUserId,
                $reason
            );
        }

        if ($isLegacyCombinedRefund) {
            $selectedCustomerRefundAmount = $refundScope === 'customer' ? $customerRefundAmount : 0.0;
            $selectedSupplierRefundAmount = $refundScope === 'supplier' && ! $supplierRefundRetainedAsCredit
                ? $supplierRefundAmount
                : 0.0;

            if ($selectedCustomerRefundAmount > 0 && $restoreCustomerCreditAmount > 0.005) {
                $restoredCustomerCredit = $customerPaymentRepository->restoreRefundToBookingReceiptCredit(
                    $bookingReference,
                    $restoreCustomerCreditCurrency,
                    $restoreCustomerCreditAmount,
                    $actorUserId,
                    $reason,
                    false,
                    (array) ($refundPayload['customer_receipt_credit_consumptions'] ?? [])
                );
            } elseif ($selectedCustomerRefundAmount > 0 && $syntheticCustomerCreditOnly && $allowSyntheticSettlementCredit) {
                $restoredCustomerCredit = [
                    'restored_amount' => 0.0,
                    'affected_receipt_ids' => [],
                ];
            }

            $payload = $refundPayload;

            $reversalReference = 'REV-REFUND-EVT-' . $refundEventId . '-' . strtoupper($refundScope);
            $reversalJournalEntryId = $accountingRepository->postServiceRefundComponentReversal([
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ('SV-' . $serviceId)),
                'source_reference' => $reversalReference,
                'entry_date' => $eventDate,
                'currency' => $currency,
                'payment_method' => (string) ($payload['payment_method'] ?? 'cash'),
                'treasury_account_id' => $payload['refund_detail']['treasury_account_id'] ?? null,
                'customer_refund_amount' => $selectedCustomerRefundAmount,
                'supplier_refund_amount' => $selectedSupplierRefundAmount,
                'narration' => 'Partial service refund reversal for ' . (string) ($existingService['line_reference'] ?? ('SV-' . $serviceId)),
                'actor_user_id' => $actorUserId,
            ]);

            $remainingCustomerRefundAmount = $refundScope === 'customer' ? 0.0 : $customerRefundAmount;
            $remainingSupplierRefundAmount = $refundScope === 'supplier' ? 0.0 : $supplierRefundAmount;
            $eventRepository->updateRefundFinancials($refundEventId, [
                'customer_refund_amount' => $remainingCustomerRefundAmount,
                'supplier_refund_amount' => $remainingSupplierRefundAmount,
                'payload_patch' => [
                    'partial_reversals' => [
                        $refundScope => [
                            'reversed_at' => date('c'),
                            'reversed_by_user_id' => $actorUserId,
                            'reason' => $reason,
                            'reversal_reference' => $reversalReference,
                            'reversal_journal_entry_id' => $reversalJournalEntryId,
                        ],
                    ],
                ],
            ]);

            $combinedEventCompleted = $remainingCustomerRefundAmount <= 0.005
                && $remainingSupplierRefundAmount <= 0.005;
            $voidResult = null;
            if ($combinedEventCompleted) {
                $completionReference = 'VOID-REFUND-EVT-' . $refundEventId . '-COMPONENTS';
                $completionJournalEntryId = (int) ($reversalJournalEntryId ?? 0);
                if ($completionJournalEntryId <= 0) {
                    $completionJournalEntryId = (int) ($refundEvent['reversal_journal_entry_id'] ?? 0);
                }
                if ($completionJournalEntryId <= 0) {
                    foreach (['supplier', 'customer'] as $completedScope) {
                        $completionJournalEntryId = (int) ($partialReversals[$completedScope]['reversal_journal_entry_id'] ?? 0);
                        if ($completionJournalEntryId > 0) {
                            break;
                        }
                    }
                }
                $voidResult = $eventRepository->voidEvent(
                    $refundEventId,
                    $reason,
                    $actorUserId,
                    $completionReference,
                    $completionJournalEntryId > 0 ? $completionJournalEntryId : null,
                    [
                        'reversal_reason' => $reason,
                        'refund_scope' => 'combined',
                        'component_reversal_complete' => true,
                        'component_reversal_completed_at' => date('c'),
                    ]
                );

                app_write_log('service.refund.legacy_combined_reversal_completed', 'Legacy combined refund event was retired after both components were reversed.', array_merge(
                    app_request_log_context(),
                    [
                        'booking_id' => $bookingId,
                        'booking_reference' => $bookingReference,
                        'service_id' => $serviceId,
                        'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                        'refund_event_id' => $refundEventId,
                        'completion_reference' => $completionReference,
                    ]
                ));
            }

            AuditLog::record($this->app, 'service.refund.partial_reversed', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'refund_event_id' => $refundEventId,
                'refund_scope' => $refundScope,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'currency' => $currency,
                'customer_refund_amount' => $selectedCustomerRefundAmount,
                'supplier_refund_amount' => $selectedSupplierRefundAmount,
                'restored_customer_credit_amount' => (float) ($restoredCustomerCredit['restored_amount'] ?? 0),
                'restored_customer_credit_receipt_ids' => $restoredCustomerCredit['affected_receipt_ids'] ?? [],
                'combined_event_completed' => $combinedEventCompleted,
                'void_result' => $voidResult,
                'reason' => $reason,
            ]);

            return;
        }

        if ($customerRefundAmount > 0 && $restoreCustomerCreditAmount > 0.005) {
            $restoredCustomerCredit = $customerPaymentRepository->restoreRefundToBookingReceiptCredit(
                $bookingReference,
                $restoreCustomerCreditCurrency,
                $restoreCustomerCreditAmount,
                $actorUserId,
                $reason,
                false,
                (array) ($refundPayload['customer_receipt_credit_consumptions'] ?? [])
            );
        } elseif ($customerRefundAmount > 0 && $syntheticCustomerCreditOnly && $allowSyntheticSettlementCredit) {
            $restoredCustomerCredit = [
                'restored_amount' => 0.0,
                'affected_receipt_ids' => [],
            ];
        }

        $reversalReference = 'VOID-REFUND-EVT-' . $refundEventId;
        $reversalJournalEntryId = $accountingRepository->postServiceRefundReversal([
            'branch_id' => (int) ($existingService['branch_id'] ?? 0),
            'booking_reference' => $bookingReference,
            'journal_entry_id' => (int) ($refundEvent['journal_entry_id'] ?? 0),
            'source_reference' => $reversalReference,
            'entry_date' => $eventDate,
            'currency' => $currency,
            'narration' => 'Service refund reversal for ' . (string) ($existingService['line_reference'] ?? ('SV-' . $serviceId)),
            'actor_user_id' => $actorUserId,
        ]);
        if ($refundScope === 'customer' && $reversalJournalEntryId === null) {
            throw new RuntimeException('The customer credit journal could not be reversed. No correction was made.');
        }
        $cashJournalEntryId = (int) ($refundPayload['customer_refund_treasury_journal_id'] ?? 0);
        $cashReversalJournalEntryId = null;
        if ($refundScope === 'customer' && $cashJournalEntryId > 0) {
            $refundDetail = (new BookingServiceRefundDetailRepository($this->app))->findByServiceEventId($refundEventId);
            $cashCurrency = $this->normalizeCurrency((string) ($refundDetail['refund_currency'] ?? ''));
            if ($cashCurrency === $currency) {
                throw new RuntimeException('Cross-currency refund cash journal does not match the recorded payout currency.');
            }
            $cashReversalJournalEntryId = $accountingRepository->postServiceRefundReversal([
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'journal_entry_id' => $cashJournalEntryId,
                'original_source_type' => 'service_refund_treasury_posted',
                'source_reference' => $reversalReference . '-CASH',
                'entry_date' => $eventDate,
                'currency' => $cashCurrency,
                'narration' => 'Actual customer refund cash reversal',
                'actor_user_id' => $actorUserId,
            ]);
            if ($cashReversalJournalEntryId === null) {
                throw new RuntimeException('Cross-currency refund cash journal could not be reversed.');
            }
        }

        $voidResult = $eventRepository->voidEvent(
            $refundEventId,
            $reason,
            $actorUserId,
            $reversalReference,
            $reversalJournalEntryId,
            [
                'reversal_reason' => $reason,
                'refund_scope' => $refundScope,
                'reversed_customer_refund_amount' => $customerRefundAmount,
                'reversed_supplier_refund_amount' => $supplierRefundAmount,
                'restored_customer_credit_amount' => (float) ($restoredCustomerCredit['restored_amount'] ?? 0),
                'restored_customer_credit_receipt_ids' => $restoredCustomerCredit['affected_receipt_ids'] ?? [],
                'customer_refund_cash_reversal_journal_id' => $cashReversalJournalEntryId,
            ]
        );

        if ($reversalJournalEntryId !== null && $reversalJournalEntryId > 0) {
            $eventRepository->attachReversalJournalEntry($refundEventId, $reversalJournalEntryId);
        }

        AuditLog::record($this->app, 'service.refund.reversed', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'service_id' => $serviceId,
            'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
            'refund_event_id' => $refundEventId,
            'refund_scope' => $refundScope,
            'reversal_reference' => $reversalReference,
            'reversal_journal_entry_id' => $reversalJournalEntryId,
            'currency' => $currency,
            'customer_refund_amount' => $customerRefundAmount,
            'supplier_refund_amount' => $supplierRefundAmount,
            'restored_customer_credit_amount' => (float) ($restoredCustomerCredit['restored_amount'] ?? 0),
            'restored_customer_credit_receipt_ids' => $restoredCustomerCredit['affected_receipt_ids'] ?? [],
            'reason' => $reason,
            'void_result' => $voidResult,
        ]);
    }

    public function undoMistakenRefund(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $cancelId = (int) ($input['cancel_event_id'] ?? 0);
        $reason = $this->requiredText($input['undo_reason'] ?? null, 1000, 'Enter a reason for undoing the mistaken refund.');
        if ((string) ($input['undo_mistake_confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Confirm the complete refund and cancellation reversal.');
        }
        $bookings = new BookingRepository($this->app);
        if (! $bookings->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot undo a refund outside your accessible branches.');
        }
        $services = new BookingServiceRepository($this->app);
        $events = new BookingServiceEventRepository($this->app);
        $db = $this->app->get('db');
        $started = ! $db->inTransaction();
        if ($started) { $db->beginTransaction(); }
        else { $db->exec('SAVEPOINT complete_refund_undo'); }
        try {
            $service = $services->lockForWorkflow($serviceId);
            if ($service === null || (int) $service['booking_id'] !== $bookingId || $cancelId <= 0) {
                throw new RuntimeException('The selected cancellation does not belong to this booking.');
            }
            if (! in_array((int) $service['branch_id'], array_map('intval', $accessibleBranchIds), true)) {
                throw new RuntimeException('The selected service belongs to a branch you cannot access.');
            }
            $history = $events->lockEventsForService($serviceId);
            $selected = null;
            foreach ($history as $event) {
                if ((int) $event['id'] === $cancelId && $event['event_type'] === 'cancel') { $selected = $event; }
            }
            if ($selected === null) { throw new RuntimeException('The selected cancellation was not found.'); }
            $payload = json_decode((string) $selected['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if ($selected['event_status'] === 'voided' && ! empty($payload['cancel_reopened'])
                && ! $this->isCancelledStatus((string) $service['service_status'])) {
                if ($started) { $db->commit(); } else { $db->exec('RELEASE SAVEPOINT complete_refund_undo'); }
                return $bookingId;
            }
            $latestCancel = $events->latestPostedEvent($serviceId, 'cancel');
            if (! $this->isCancelledStatus((string) $service['service_status'])
                || (int) ($latestCancel['id'] ?? 0) !== $cancelId) {
                throw new RuntimeException('The cancellation changed. Reload the booking before undoing it.');
            }
            $latestRefund = $events->latestPostedEvent($serviceId, 'refund');
            if ((int) ($latestRefund['id'] ?? 0) !== (int) ($input['undo_last_refund_event_id'] ?? 0)) {
                throw new RuntimeException('Refund history changed. Reload the booking before undoing it.');
            }
            foreach ($history as $event) {
                if ($event['event_status'] === 'posted' && (int) $event['id'] > $cancelId && $event['event_type'] === 'reissue') {
                    throw new RuntimeException('A later ticket reissue exists. Review it before restoring this invoice.');
                }
            }
            $recovery = new RefundRecoveryRepository($this->app);
            $reference = (string) $bookings->findBookingById($bookingId)['booking_reference'];
            $before = $recovery->snapshot($service, $reference);
            $recovery->assertTreasuryBranches($before, $accessibleBranchIds);
            $this->assertCompleteRecoveryBasis($service, $selected, $payload, $before);
            $postedCancels = array_filter($history, static fn (array $event): bool => $event['event_type'] === 'cancel' && $event['event_status'] === 'posted');
            if (count($postedCancels) !== 1) { throw new RuntimeException('More than one active cancellation exists. Review this booking history before complete reversal.'); }
            $linkedJournals = (array) ($payload['journal_entry_ids'] ?? []);
            foreach ($history as $event) {
                if ($event['event_type'] !== 'refund' || $event['event_status'] !== 'posted') { continue; }
                $eventPayload = json_decode((string) $event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                if ((float) $event['customer_refund_amount'] > 0.005 && empty($eventPayload['customer_receipt_credit_consumptions'])) {
                    $currency = (string) ($eventPayload['customer_receipt_credit_currency'] ?? $service['currency']);
                    $returnedReceipts = array_filter($before['customer_receipts'], static fn (array $row): bool =>
                        $row['currency'] === $currency && (float) ($row['returned_amount'] ?? 0) > 0.005);
                    if (count($returnedReceipts) > 1) {
                        throw new RuntimeException('This older refund has no exact receipt matching and several receipts contain returned money. Review its receipt references first.');
                    }
                }
                $creditOnly = (float) $event['customer_refund_amount'] <= 0.005
                    && ($eventPayload['supplier_refund_payment_method'] ?? '') === 'supplier_credit';
                if (! $creditOnly) {
                    $linkedJournals[] = (int) ($event['journal_entry_id'] ?? 0);
                }
            }
            $recovery->assertJournals($before, $linkedJournals, (string) $service['line_reference']);
            $this->recoveryMoneyBudgets = [];
            $this->completeRecoveryInProgress = true;
            $reversed = [];
            foreach (['customer', 'supplier'] as $scope) {
                $remaining = count($history) + 1;
                while (($refund = $this->latestRefundEventForScope($events, $serviceId, $scope)) !== null) {
                    if (--$remaining < 0 || (int) $refund['id'] < $cancelId) {
                        throw new RuntimeException('Refund history needs review before a complete undo.');
                    }
                    $this->reverseRefundService([
                        'booking_id' => $bookingId, 'service_id' => $serviceId,
                        'refund_event_id' => $refund['id'], 'refund_scope' => $scope, 'refund_reverse_reason' => $reason,
                    ], $actorUserId, $accessibleBranchIds);
                    $reversed[] = ['event_id' => (int) $refund['id'], 'scope' => $scope];
                }
            }
            if ($events->hasPostedCancellationSettlement($serviceId)) {
                $this->reverseCancellationSettlement([
                    'booking_id' => $bookingId, 'service_id' => $serviceId, 'settlement_reverse_reason' => $reason,
                ], $actorUserId, $accessibleBranchIds);
            }
            $this->reopenCancelledService([
                'booking_id' => $bookingId, 'service_id' => $serviceId, 'cancel_reopen_reason' => $reason,
            ], $actorUserId, $accessibleBranchIds);
            $after = $recovery->snapshot($service, $reference);
            $recovery->assertAccessibleChanges($before, $after, $accessibleBranchIds);
            AuditLog::recordRequired($this->app, 'service.refund.complete_undo', [
                'user_id' => $actorUserId, 'booking_id' => $bookingId, 'service_id' => $serviceId,
                'booking_reference' => $reference, 'service_line_reference' => $service['line_reference'],
                'cancel_event_id' => $cancelId, 'reversed_refunds' => $reversed, 'reason' => $reason,
                'reversal_mode' => 'recorded_transactions',
                'recovery_version' => 1, 'before' => $before, 'after' => $after,
                'summary' => $this->refundRecoverySummary($before, $after, (string) $service['line_reference']),
                'after_fingerprint' => RefundRecoveryRepository::fingerprint($after), 'cancellation_before' => $selected,
                'restored_service_status' => $services->findServiceById($serviceId)['service_status'],
            ]);
            if ($started) { $db->commit(); } else { $db->exec('RELEASE SAVEPOINT complete_refund_undo'); }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                if ($started) { $db->rollBack(); }
                else { $db->exec('ROLLBACK TO SAVEPOINT complete_refund_undo'); $db->exec('RELEASE SAVEPOINT complete_refund_undo'); }
            }
            if ($exception instanceof \PDOException || $exception instanceof \JsonException) {
                app_write_log('service.refund.recovery.failed', 'Financial reversal failed and was rolled back.',
                    ['booking_id' => $bookingId, 'service_id' => $serviceId, 'error' => $exception->getMessage()]);
                throw new RuntimeException('The reversal could not be saved safely, including its required audit. No changes were saved. Please reload and review the database/audit error.', 0, $exception);
            }
            throw $exception;
        } finally {
            $this->recoveryMoneyBudgets = [];
            $this->completeRecoveryInProgress = false;
        }
        return $bookingId;
    }

    private function assertCompleteRecoveryBasis(array $service, array $cancel, array $payload, array $snapshot): void
    {
        if (($payload['mode'] ?? '') === 'operational_cancel_only') { return; }
        if (! isset($payload['prior_customer_due_amount'], $payload['supplier_cost_basis'], $payload['prior_service_status'])) {
            throw new RuntimeException('This older cancellation is missing its original invoice/cost/status record. Complete reversal needs a verified historical reconciliation; nothing was changed.');
        }
        $prior = (float) $payload['prior_customer_due_amount'];
        $cost = (float) $payload['supplier_cost_basis'];
        if (! is_finite($prior) || $prior < 0 || ! in_array($payload['prior_service_status'], self::ALLOWED_STATUSES, true)
            || $payload['prior_service_status'] === 'Cancelled' || ! is_finite($cost) || $cost < 0) {
            throw new RuntimeException('The original cancellation record is inconsistent. Nothing was changed.');
        }
        if ((float) ($payload['released_customer_credit'] ?? 0) > 0.005 && empty($payload['released_customer_receipt_ids'])) {
            throw new RuntimeException('The cancellation has no original receipt allocation references. Historical matching is required before complete reversal.');
        }
        $releasedSupplier = (float) ($payload['released_supplier_credit_source_amount'] ?? $payload['released_supplier_credit'] ?? 0);
        $releasedSources = (float) ($payload['released_supplier_payment_credit'] ?? 0) + (float) ($payload['released_supplier_advance_credit'] ?? 0);
        if ($releasedSupplier > $releasedSources + 0.005) {
            throw new RuntimeException('The cancellation released linked-party or historical supplier credit without complete source matching. Review those allocations first; no unrelated credit will be used.');
        }
        foreach ($snapshot['customer_receivable_items'] as $ar) {
            if ($ar['service_line_reference'] !== $service['line_reference']) { continue; }
            if ($ar['currency'] !== $service['currency'] || (float) $ar['allocated_amount'] > $prior + 0.005) {
                throw new RuntimeException('The original invoice currency/amount conflicts with current allocations. Review the historical correction before complete reversal.');
            }
        }
        $supplierCurrency = $this->resolveSupplierRestoreCurrency($payload, $service, (string) $service['currency']);
        foreach ($snapshot['supplier_obligations'] as $ap) {
            if ($ap['service_line_reference'] === $service['line_reference'] && $ap['currency'] !== $supplierCurrency) {
                throw new RuntimeException('The supplier cost currency changed after cancellation. Review its historical journal before complete reversal.');
            }
        }
        $journalIds = array_map('intval', $payload['journal_entry_ids'] ?? []);
        $journalRows = array_column($snapshot['journal_entries'], null, 'id');
        foreach ($journalIds as $id) {
            if (! isset($journalRows[$id]) || (int) $journalRows[$id]['branch_id'] !== (int) $service['branch_id']) {
                throw new RuntimeException('A cancellation journal is missing or belongs to another branch. Nothing was changed.');
            }
        }
        if ($journalIds === [] && (abs((float) ($payload['customer_delta'] ?? 0)) > 0.005
            || abs((float) ($payload['supplier_delta'] ?? 0)) > 0.005)) {
            throw new RuntimeException('Cancellation amounts have no linked journals. Nothing was changed.');
        }
    }

    private function refundRecoverySummary(array $before, array $after, string $line): array
    {
        $summary = [];
        foreach (['customer_receivable_items' => ['due_amount', 'allocated_amount', 'outstanding_amount'],
            'supplier_obligations' => ['gross_amount', 'advance_applied_amount', 'net_payable_amount']] as $table => $fields) {
            $oldRows = array_column($before[$table], null, 'id');
            foreach ($after[$table] as $row) {
                if ($row['service_line_reference'] !== $line) { continue; }
                foreach ($fields as $field) {
                    $summary[] = ['label' => ucwords(str_replace('_', ' ', $field)), 'currency' => $row['currency'],
                        'before' => (float) ($oldRows[$row['id']][$field] ?? 0), 'after' => (float) $row[$field]];
                }
            }
        }
        return $summary;
    }

    public function restoreReversedRefund(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);
        $bookingId = (int) ($input['booking_id'] ?? 0); $serviceId = (int) ($input['service_id'] ?? 0);
        $operationId = (int) ($input['reversal_audit_id'] ?? 0);
        $reason = $this->requiredText($input['restore_reason'] ?? null, 1000, 'Enter a reason for restoring the reversed refund.');
        if ((string) ($input['restore_confirmed'] ?? '') !== '1') { throw new RuntimeException('Confirm refund restoration.'); }
        $bookings = new BookingRepository($this->app);
        if (! $bookings->bookingExistsInBranches($bookingId, $accessibleBranchIds)) { throw new RuntimeException('This booking is outside your accessible branches.'); }
        $db = $this->app->get('db'); $started = ! $db->inTransaction();
        if ($started) { $db->beginTransaction(); } else { $db->exec('SAVEPOINT complete_refund_restore'); }
        try {
            $service = (new BookingServiceRepository($this->app))->lockForWorkflow($serviceId);
            if ($service === null || (int) $service['booking_id'] !== $bookingId) { throw new RuntimeException('The service does not belong to this booking.'); }
            if (! in_array((int) $service['branch_id'], array_map('intval', $accessibleBranchIds), true)) { throw new RuntimeException('The service is outside your accessible branches.'); }
            $recovery = new RefundRecoveryRepository($this->app);
            $operation = $recovery->operation($operationId); $data = $operation['payload'] ?? [];
            if ((int) ($data['booking_id'] ?? 0) !== $bookingId || (int) ($data['service_id'] ?? 0) !== $serviceId
                || (int) ($data['recovery_version'] ?? 0) !== 1) {
                throw new RuntimeException('This reversal has no verified recovery record for the selected booking.');
            }
            if (! $recovery->restorationExists($operationId)) {
                $reference = (string) $bookings->findBookingById($bookingId)['booking_reference'];
                $current = $recovery->snapshot($service, $reference);
                if (RefundRecoveryRepository::fingerprint($current) !== ($data['after_fingerprint'] ?? '')) {
                    throw new RuntimeException('Transactions or booking details changed after reversal. Automatic restoration stopped to protect those changes; nothing was changed.');
                }
                $recovery->assertAccessibleChanges($data['after'], $data['before'], $accessibleBranchIds);
                $recovery->assertTreasuryBranches($data['before'], $accessibleBranchIds);
                $beforeJournalIds = array_column($data['before']['journal_entries'], 'id');
                $journalIds = [];
                $accounting = new AccountingRepository($this->app);
                foreach (array_reverse($data['after']['journal_entries']) as $journal) {
                    if (in_array($journal['id'], $beforeJournalIds, true)) { continue; }
                    if (! in_array((int) $journal['branch_id'], array_map('intval', $accessibleBranchIds), true)) {
                        throw new RuntimeException('A restoration journal belongs to an inaccessible branch.');
                    }
                    $id = $accounting->reverseJournalEntry((int) $journal['id'], [
                        'source_type' => 'service_refund_recovery_restored',
                        'source_reference' => 'RESTORE-' . $operationId . '-' . $journal['id'],
                        'entry_date' => date('Y-m-d'), 'actor_user_id' => $actorUserId,
                        'narration' => 'Restore reversed refund ' . $reference . ' / ' . $service['line_reference'] . ': ' . $reason,
                    ]);
                    if (! $id) { throw new RuntimeException('A reversal journal could not be restored. Nothing was changed.'); }
                    $journalIds[] = $id;
                }
                $recovery->restoreRows($data['before'], $data['after']);
                AuditLog::recordRequired($this->app, 'service.refund.complete_restored', [
                    'user_id' => $actorUserId, 'booking_id' => $bookingId, 'booking_reference' => $reference,
                    'service_id' => $serviceId, 'service_line_reference' => $service['line_reference'],
                    'reversal_audit_id' => $operationId, 'reason' => $reason, 'recovery_version' => 1,
                    'journal_entry_ids' => $journalIds, 'before_fingerprint' => RefundRecoveryRepository::fingerprint($current),
                    'restored_snapshot_fingerprint' => RefundRecoveryRepository::fingerprint($data['before']),
                ]);
            }
            if ($started) { $db->commit(); } else { $db->exec('RELEASE SAVEPOINT complete_refund_restore'); }
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                if ($started) { $db->rollBack(); } else { $db->exec('ROLLBACK TO SAVEPOINT complete_refund_restore'); $db->exec('RELEASE SAVEPOINT complete_refund_restore'); }
            }
            if ($error instanceof \PDOException || $error instanceof \JsonException) {
                app_write_log('service.refund.recovery.failed', 'Financial restoration failed and was rolled back.',
                    ['booking_id' => $bookingId, 'service_id' => $serviceId, 'error' => $error->getMessage()]);
                throw new RuntimeException('The restoration could not be saved safely, including its required audit. No changes were saved. Please reload and review the database/audit error.', 0, $error);
            }
            throw $error;
        }
        return $bookingId;
    }

    public function reverseRefundService(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $refundEventId = (int) ($input['refund_event_id'] ?? 0);
        $reason = $this->requiredText($input['refund_reverse_reason'] ?? null, 1000, 'Reverse refund reason is required.');
        $eventDate = date('Y-m-d');

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot reverse refunds for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be loaded for refund reversal.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        $refundEvent = $eventRepository->findPostedEventById($refundEventId, 'refund');
        if ($refundEvent === null || (int) ($refundEvent['booking_service_id'] ?? 0) !== $serviceId) {
            throw new RuntimeException('The selected refund event could not be found.');
        }

        $refundScope = strtolower(trim((string) ($input['refund_scope'] ?? '')));
        $bookingReference = (string) ($booking['booking_reference'] ?? '');

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            if ($refundScope === '') {
                $reversedAnyRefund = false;
                foreach (['customer', 'supplier'] as $scopeToReverse) {
                    $scopeRefundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, $scopeToReverse);
                    if ($scopeRefundEvent === null) {
                        continue;
                    }

                    $this->reverseRefundMutation(
                        $bookingId,
                        $serviceId,
                        $scopeRefundEvent,
                        $scopeToReverse,
                        $reason,
                        $eventDate,
                        $actorUserId,
                        $bookingReference,
                        $existingService
                    );
                    $reversedAnyRefund = true;
                }

                if (! $reversedAnyRefund) {
                    throw new RuntimeException('No posted customer refund or supplier refund received exists for this service.');
                }
            } else {
                if (! in_array($refundScope, ['customer', 'supplier'], true)) {
                    throw new RuntimeException('Select whether you want to reverse the customer refund or supplier refund received.');
                }

                $this->reverseRefundMutation(
                    $bookingId,
                    $serviceId,
                    $refundEvent,
                    $refundScope,
                    $reason,
                    $eventDate,
                    $actorUserId,
                    $bookingReference,
                    $existingService
                );
            }

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

    private function correctRepairedCancellationCustomerBalance(
        array $input,
        int $actorUserId,
        array $booking,
        array $service,
        array $cancelEvent,
        array $cancelPayload,
        string $eventDate,
        string $reason
    ): int {
        $bookingId = (int) $booking['id'];
        $serviceId = (int) $service['id'];
        $bookingReference = (string) $booking['booking_reference'];
        $lineReference = (string) $service['line_reference'];
        $currency = $this->normalizeCurrency((string) $service['currency']);
        $eventRepository = new BookingServiceEventRepository($this->app);
        $lockedEvent = $eventRepository->lockPostedEventById((int) $cancelEvent['id'], 'cancel');
        if ($lockedEvent === null || (string) $lockedEvent['payload_json'] !== (string) $cancelEvent['payload_json']) {
            throw new RuntimeException('This cancellation changed while you were editing it. Reload the booking and review the latest values.');
        }
        $expectedSourceCurrency = $this->normalizeCurrency((string) ($cancelPayload['expected_supplier_refund_currency'] ?? ''));
        $submittedSourceCurrency = $this->normalizeCurrency((string) ($input['expected_supplier_refund_currency'] ?? ''));
        $expectedSourceAmount = $this->moneyValue($cancelPayload['expected_supplier_refund_source_amount'] ?? 0);
        $submittedSourceAmount = $this->moneyValue($input['expected_supplier_refund_amount'] ?? 0);
        if ($expectedSourceCurrency !== $submittedSourceCurrency
            || abs($expectedSourceAmount - $submittedSourceAmount) > 0.005
            || $expectedSourceAmount <= 0.005) {
            throw new RuntimeException('This correction can change the customer-side equivalent only. The saved supplier refund and currency must remain unchanged.');
        }
        if ($this->normalizeCurrency((string) ($input['expected_supplier_refund_equivalent_currency'] ?? '')) !== $currency) {
            throw new RuntimeException('Enter the supplier refund equivalent in the customer invoice currency before saving.');
        }
        $supplierPenaltySource = $this->moneyValue($cancelPayload['supplier_penalty_source_amount'] ?? 0);
        if (abs($supplierPenaltySource - $this->moneyValue($input['supplier_penalty_amount'] ?? $supplierPenaltySource)) > 0.005) {
            throw new RuntimeException('This customer balance correction cannot change the supplier penalty.');
        }
        $supplierObligation = (new SupplierRepository($this->app))->lockObligationByServiceLine($bookingReference, $lineReference);
        $supplierCost = $this->moneyValue($cancelPayload['supplier_cost_basis'] ?? $service['purchase_cost'] ?? 0);
        if ($supplierObligation === null
            || $this->normalizeCurrency((string) $supplierObligation['currency']) !== $expectedSourceCurrency
            || abs((float) $supplierObligation['gross_amount'] - $supplierPenaltySource) > 0.005
            || abs((float) $supplierObligation['net_payable_amount'] - $supplierPenaltySource) > 0.005
            || (float) ($supplierObligation['advance_applied_amount'] ?? 0) > 0.005
            || abs($supplierCost - $supplierPenaltySource - $expectedSourceAmount) > 0.005) {
            throw new RuntimeException('The saved supplier payable no longer matches the recorded supplier refund reduction. No customer adjustment was posted.');
        }
        if ($eventRepository->latestPostedEvent($serviceId, 'refund') !== null) {
            throw new RuntimeException('Reverse the posted refund before correcting this customer balance.');
        }
        $customerRepository = new CustomerPaymentRepository($this->app);
        $receivable = $customerRepository->lockReceivableByServiceLine($bookingReference, $lineReference);
        $this->assertCustomerReceivableCurrencyMatchesInvoice($receivable, $currency);
        if ($receivable === null
            || abs((float) $receivable['due_amount'] - (float) ($cancelPayload['customer_final_charge_amount'] ?? -1)) > 0.005) {
            throw new RuntimeException('The saved customer balance differs from this cancellation. Inspect its allocations before correcting it.');
        }
        $originalInvoice = $this->moneyValue($cancelPayload['prior_customer_due_amount'] ?? 0);
        $settled = $this->moneyValue($cancelPayload['customer_refund_basis'] ?? 0);
        $previouslyReleased = $this->moneyValue($cancelPayload['customer_offset_released_amount'] ?? 0);
        if ($originalInvoice <= 0 || abs((float) $receivable['allocated_amount'] + $previouslyReleased - $settled) > 0.005) {
            throw new RuntimeException('Customer receipt allocations changed since the cancellation. Review them before applying the supplier refund.');
        }
        $feeRefund = $this->settlementAmountInBookingCurrency($input, 'agency_fee_refund_amount', $currency);
        if ($feeRefund > (float) ($service['service_charge'] ?? 0) + 0.005) {
            throw new RuntimeException('Agency fee refund cannot exceed the service fee charged.');
        }
        $equivalent = $this->moneyValue($input['expected_supplier_refund_equivalent_amount'] ?? 0);
        if ($equivalent <= 0) {
            throw new RuntimeException('Enter a positive supplier refund equivalent in ' . $currency . '.');
        }
        $customerPenalty = $this->settlementAmountInBookingCurrency($input, 'customer_penalty_amount', $currency);
        $summary = CancellationRefundCalculator::customerSummary([
            'invoice' => $originalInvoice,
            'settled' => $settled,
            'expected' => $equivalent,
            'penalty' => $customerPenalty,
            'fee_refund' => $feeRefund,
        ]);
        $newDue = $summary['final_charge'];
        $targetAllocated = round(min($settled, $newDue), 2);
        $currentAllocated = round((float) $receivable['allocated_amount'], 2);
        $accountingRepository = new AccountingRepository($this->app);
        $journalIds = [];
        $releasedReceiptIds = array_values(array_unique(array_map('intval', (array) ($cancelPayload['customer_offset_released_receipt_ids'] ?? []))));
        if ($targetAllocated < $currentAllocated - 0.005) {
            $release = $customerRepository->releaseAllocatedCreditForReceivable(
                (int) $receivable['id'], $targetAllocated, $actorUserId,
                'Supplier refund applied to customer balance for ' . $lineReference
            );
            if ((float) ($release['released_linked_offset_amount'] ?? 0) > 0.005) {
                throw new RuntimeException('A linked-party offset is present. Its customer balance cannot be adjusted through this refund.');
            }
            $released = round((float) ($release['released_payment_amount'] ?? 0), 2);
            if (abs($released - ($currentAllocated - $targetAllocated)) > 0.005) {
                throw new RuntimeException('Customer receipt release did not match the cancellation adjustment.');
            }
            $releasedReceiptIds = array_values(array_unique(array_merge(
                $releasedReceiptIds, array_map('intval', (array) ($release['affected_receipt_ids'] ?? []))
            )));
            if ($released > 0.005) {
                $journalIds[] = $accountingRepository->postCustomerReceiptAllocationRelease([
                    'branch_id' => (int) $service['branch_id'], 'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference, 'customer_receivable_item_id' => (int) $receivable['id'],
                    'released_amount' => $released, 'source_reference' => 'CANCEL-CUSTOMER-' . (int) $cancelEvent['id'],
                    'entry_date' => $eventDate, 'currency' => $currency,
                    'narration' => 'Customer credit released after supplier refund adjustment', 'actor_user_id' => $actorUserId,
                ]);
            }
        }
        $sync = $customerRepository->syncReceivableItem([
            'branch_id' => (int) $service['branch_id'], 'booking_reference' => $bookingReference,
            'service_line_reference' => $lineReference, 'due_group' => 'service_sale',
            'currency' => $currency, 'due_amount' => $newDue, 'due_date' => $eventDate,
            'status' => $newDue > 0 ? 'open' : 'cancelled',
            'remarks' => 'Supplier refund customer balance adjustment [' . $lineReference . ']',
            'actor_user_id' => $actorUserId,
        ]);
        $delta = round((float) ($sync['delta_amount'] ?? 0), 2);
        if (abs($delta) > 0.005) {
            $journalIds[] = $accountingRepository->postReceivableAdjusted([
                'branch_id' => (int) $service['branch_id'], 'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference, 'customer_receivable_item_id' => (int) $receivable['id'],
                'adjustment_amount' => $delta, 'entry_date' => $eventDate, 'currency' => $currency,
                'narration' => 'Supplier refund equivalent applied to customer invoice [' . $lineReference . ']',
                'actor_user_id' => $actorUserId,
            ]);
        }
        if ($targetAllocated > $currentAllocated + 0.005) {
            $remaining = round($targetAllocated - $currentAllocated, 2);
            foreach ($customerRepository->availableReceiptCreditsForBooking($bookingReference, $currency) as $receipt) {
                $receiptId = (int) ($receipt['id'] ?? 0);
                if ($remaining <= 0.005 || ! in_array($receiptId, $releasedReceiptIds, true)) {
                    continue;
                }
                $amount = round(min($remaining, (float) ($receipt['unallocated_amount'] ?? 0)), 2);
                if ($amount <= 0.005) {
                    continue;
                }
                $customerRepository->allocateReceiptExplicit([
                    'receipt_id' => $receiptId, 'receivable_item_id' => (int) $receivable['id'],
                    'payment_currency' => $currency, 'receivable_amount_to_settle' => $amount,
                    'payment_amount_to_consume' => $amount, 'rate_from_currency' => $currency,
                    'rate_to_currency' => $currency, 'exchange_rate' => 1,
                    'exchange_rate_effective_date' => $eventDate,
                    'allocation_note' => 'Customer balance adjustment corrected for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);
                $journalIds[] = $accountingRepository->postCustomerReceiptAllocation([
                    'branch_id' => (int) $service['branch_id'], 'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference, 'customer_receivable_item_id' => (int) $receivable['id'],
                    'customer_receipt_id' => $receiptId, 'allocated_amount' => $amount,
                    'source_reference' => 'CANCEL-CUSTOMER-' . (int) $cancelEvent['id'],
                    'entry_date' => $eventDate, 'currency' => $currency,
                    'narration' => 'Previously released customer credit restored to invoice', 'actor_user_id' => $actorUserId,
                ]);
                $remaining = round($remaining - $amount, 2);
            }
            if ($remaining > 0.005) {
                throw new RuntimeException('Previously released customer credit was used elsewhere. No correction was saved.');
            }
        }
        $after = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        if ($after === null || abs((float) $after['due_amount'] - $newDue) > 0.005
            || abs((float) $after['allocated_amount'] - $targetAllocated) > 0.005
            || abs((float) $after['outstanding_amount'] - $summary['outstanding']) > 0.005) {
            throw new RuntimeException('Customer receivable did not reconcile after applying the supplier refund.');
        }
        $payload = $cancelPayload;
        $payload['currency_correction']['customer_cancellation_settlement_pending'] = false;
        $payload['customer_offset_version'] = 1;
        $payload['customer_penalty_amount'] = $customerPenalty;
        $payload['customer_penalty_currency'] = $this->normalizeCurrency((string) ($input['customer_penalty_currency'] ?? $currency));
        $payload['customer_penalty_source_amount'] = $this->moneyValue($input['customer_penalty_amount'] ?? 0);
        $payload['customer_penalty_exchange_rate'] = (float) ($input['customer_penalty_exchange_rate'] ?? 1);
        $payload['agency_fee_refund_amount'] = $feeRefund;
        $payload['agency_fee_retained_amount'] = round(max((float) $service['service_charge'] - $feeRefund, 0), 2);
        $payload['expected_supplier_refund_amount'] = $equivalent;
        $payload['expected_supplier_refund_booking_amount'] = $equivalent;
        $payload['expected_supplier_refund_equivalent_amount'] = $equivalent;
        $payload['expected_supplier_refund_equivalent_currency'] = $currency;
        $payload['expected_supplier_refund_exchange_rate'] = (float) ($input['expected_supplier_refund_exchange_rate'] ?? 0);
        $payload['expected_supplier_refund_conversion_basis'] = 'manual_equivalent';
        $payload['supplier_credit_recording_version'] = 1;
        $payload['supplier_credit_recorded_currency'] = $expectedSourceCurrency;
        $payload['supplier_credit_recorded_source_amount'] = 0;
        $payload['supplier_credit_recorded_booking_amount'] = 0;
        $payload['supplier_payable_reduced_source_amount'] = $expectedSourceAmount;
        $payload['customer_final_charge_amount'] = $newDue;
        $payload['available_customer_refund_credit'] = $summary['entitlement'];
        $payload['available_customer_refund_credit_currency'] = $currency;
        $payload['target_customer_refund_credit'] = $summary['entitlement'];
        $payload['target_customer_refund_credit_currency'] = $currency;
        $payload['released_customer_credit'] = round($settled - $targetAllocated, 2);
        $payload['released_customer_receipt_ids'] = $releasedReceiptIds;
        $payload['customer_offset_released_amount'] = round($settled - $targetAllocated, 2);
        $payload['customer_offset_released_receipt_ids'] = $releasedReceiptIds;
        $payload['customer_offset_journal_ids'] = array_merge((array) ($payload['customer_offset_journal_ids'] ?? []), $journalIds);
        $payload['journal_entry_ids'] = array_merge((array) ($payload['journal_entry_ids'] ?? []), $journalIds);
        $payload['customer_delta'] = round($newDue - $originalInvoice, 2);
        $eventRepository->updateCancellationFinancials((int) $cancelEvent['id'], [
            'penalty_amount' => $customerPenalty,
            'customer_credit_amount' => $summary['entitlement'],
            'supplier_credit_amount' => (float) ($cancelEvent['supplier_credit_amount'] ?? 0),
            'journal_entry_id' => $journalIds[0] ?? $cancelEvent['journal_entry_id'] ?? null,
            'reason' => $reason,
            'payload_json' => $payload,
        ]);
        AuditLog::recordRequired($this->app, 'service.cancellation.customer_supplier_refund_offset', [
            'user_id' => $actorUserId, 'booking_id' => $bookingId,
            'booking_reference' => $bookingReference, 'service_id' => $serviceId,
            'cancel_event_id' => (int) $cancelEvent['id'], 'reason' => $reason,
            'supplier_refund' => [$expectedSourceCurrency => $expectedSourceAmount],
            'customer_equivalent' => [$currency => $equivalent],
            'customer_due_before' => (float) $receivable['due_amount'],
            'customer_due_after' => $newDue,
            'customer_outstanding_after' => $summary['outstanding'],
            'customer_refund_due' => $summary['entitlement'],
            'journal_ids' => $journalIds, 'cash_movement' => false,
        ]);
        BookingEditTraceService::checkpoint('ServiceWorkspaceService::customerSupplierRefundOffset', 'completed', [
            'service_id' => $serviceId, 'supplier_source_currency' => $expectedSourceCurrency,
            'supplier_source_amount' => $expectedSourceAmount, 'customer_currency' => $currency,
            'customer_equivalent' => $equivalent, 'customer_outstanding_after' => $summary['outstanding'],
            'customer_refund_due' => $summary['entitlement'], 'journal_ids' => $journalIds,
        ]);
        return $bookingId;
    }

    public function correctPenaltyRefundWorkflow(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['correction_reason'] ?? null, 1000, 'Correction reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['correction_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot correct this service for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be loaded for correction.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }
        $bookingCurrency = (string) ($existingService['currency'] ?? 'PKR');
        $enteredCustomerEquivalent = $input['expected_supplier_refund_equivalent_amount'] ?? null;
        $input = $this->normalizeSupplierRefundEquivalentInput($input, $bookingCurrency, $existingService);
        $customerRefundAmount = $this->customerRefundAmountInBookingCurrency($input, 'customer_refund_amount');

        $eventRepository = new BookingServiceEventRepository($this->app);
        $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
        if ($cancelEvent === null) {
            throw new RuntimeException('Cancel and settle the service before correcting penalty or refund values.');
        }

        $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
        if (! is_array($cancelPayload)) {
            $cancelPayload = [];
        }
        $isFinanciallySettled = (string) ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
            || array_key_exists('customer_final_charge_amount', $cancelPayload)
            || array_key_exists('expected_supplier_refund_amount', $cancelPayload)
            || array_key_exists('supplier_penalty_amount', $cancelPayload);
        if (! $isFinanciallySettled) {
            throw new RuntimeException('Settle the cancellation before correcting penalty or refund values.');
        }
        if ((($cancelPayload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true
                || (int) ($cancelPayload['customer_offset_version'] ?? 0) > 0)
            && (! is_numeric($enteredCustomerEquivalent) || (float) $enteredCustomerEquivalent <= 0)) {
            throw new RuntimeException('Enter the confirmed supplier refund equivalent in the customer invoice currency. No automatic rate is used for this correction.');
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $bookingReference = (string) ($booking['booking_reference'] ?? '');
            if (($cancelPayload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true
                || (int) ($cancelPayload['customer_offset_version'] ?? 0) > 0) {
                $result = $this->correctRepairedCancellationCustomerBalance(
                    $input, $actorUserId, $booking, $existingService, $cancelEvent,
                    $cancelPayload, $eventDate, $reason
                );
                if ($startedTransaction && $db->inTransaction()) {
                    $db->commit();
                }
                return $result;
            }
            $supplierTruthCurrency = $this->resolveSupplierSettlementTruthCurrency($existingService, $bookingCurrency);
            $supplierTruthRateToBooking = $this->resolveServiceCurrencyRateToBooking($existingService, $supplierTruthCurrency, $bookingCurrency);
            $supplierCostBasisBooking = $this->convertSettlementTruthAmountToBookingCurrency(
                round((float) ($existingService['purchase_cost'] ?? 0), 2),
                $supplierTruthCurrency,
                $bookingCurrency,
                $supplierTruthRateToBooking
            );
            $oldCustomerPenaltyAmount = $this->moneyValue($cancelPayload['customer_penalty_amount'] ?? $cancelEvent['penalty_amount'] ?? 0);
            $oldAgencyFeeRefundAmount = $this->moneyValue($cancelPayload['agency_fee_refund_amount'] ?? 0);
            $oldExpectedSupplierRefundAmount = $this->moneyValue(
                $cancelPayload['expected_supplier_refund_booking_amount']
                ?? $cancelPayload['expected_supplier_refund_amount']
                ?? $cancelPayload['expected_supplier_refund_credit']
                ?? $cancelEvent['supplier_credit_amount']
                ?? 0
            );
            $oldSupplierPenaltyAmount = $this->moneyValue(
                $cancelPayload['supplier_penalty_booking_amount']
                ?? $cancelPayload['supplier_penalty_amount']
                ?? 0
            );
            if ($oldExpectedSupplierRefundAmount <= 0.005
                && ! array_key_exists('expected_supplier_refund_booking_amount', $cancelPayload)
                && ! array_key_exists('expected_supplier_refund_amount', $cancelPayload)) {
                $oldExpectedSupplierRefundAmount = round(max(
                    $supplierCostBasisBooking - $oldSupplierPenaltyAmount,
                    0
                ), 2);
            }
            $newCustomerPenaltyAmount = $this->settlementAmountInBookingCurrency($input, 'customer_penalty_amount', $bookingCurrency);
            $newAgencyFeeRefundAmount = $this->settlementAmountInBookingCurrency($input, 'agency_fee_refund_amount', $bookingCurrency);
            $newExpectedSupplierRefundAmount = $this->settlementAmountInBookingCurrency($input, 'expected_supplier_refund_amount', $bookingCurrency);
            $oldExpectedSupplierRefundCurrency = $this->normalizeCurrency((string) (
                $cancelPayload['expected_supplier_refund_currency'] ?? $supplierTruthCurrency
            ));
            $newExpectedSupplierRefundCurrency = $this->normalizeCurrency((string) (
                $input['expected_supplier_refund_currency'] ?? $supplierTruthCurrency
            ));
            $oldExpectedSupplierRefundSourceAmount = $this->moneyValue(
                $cancelPayload['expected_supplier_refund_source_amount'] ?? $oldExpectedSupplierRefundAmount
            );
            $newExpectedSupplierRefundSourceAmount = $this->moneyValue($input['expected_supplier_refund_amount'] ?? 0);
            $supplierExpectationChanged = $oldExpectedSupplierRefundCurrency !== $newExpectedSupplierRefundCurrency
                || abs($oldExpectedSupplierRefundSourceAmount - $newExpectedSupplierRefundSourceAmount) > 0.005;
            $oldExpectedSupplierRefundRate = (float) ($cancelPayload['expected_supplier_refund_exchange_rate'] ?? 1);
            $newExpectedSupplierRefundRate = (float) ($input['expected_supplier_refund_exchange_rate'] ?? $oldExpectedSupplierRefundRate);
            $supplierRateChanged = $newExpectedSupplierRefundCurrency !== $bookingCurrency
                && abs($oldExpectedSupplierRefundRate - $newExpectedSupplierRefundRate) > 0.00000001;
            if (($supplierExpectationChanged || $supplierRateChanged)
                && $oldExpectedSupplierRefundCurrency !== $bookingCurrency
                && ! array_key_exists('expected_supplier_refund_booking_amount', $cancelPayload)) {
                throw new RuntimeException(
                    'This older cancellation has no verified invoice-currency equivalent. Review its conversion before changing the supplier refund amount, currency or exchange rate.'
                );
            }
            $settlementValuesChanged = abs($oldCustomerPenaltyAmount - $newCustomerPenaltyAmount) > 0.005
                || abs($oldAgencyFeeRefundAmount - $newAgencyFeeRefundAmount) > 0.005
                || $supplierExpectationChanged
                || $supplierRateChanged;

            $latestCustomerRefundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, 'customer');
            $latestSupplierRefundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, 'supplier');
            $supplierRefundAccountingCurrency = (string) ($latestSupplierRefundEvent['currency'] ?? $bookingCurrency);
            // The correction form already stores this hidden value in the refund event's accounting currency.
            $supplierRefundAmount = $this->refundPrecisionValue($input['supplier_refund_amount'] ?? 0);
            if ($latestCustomerRefundEvent === null && $customerRefundAmount > 0.005) {
                throw new RuntimeException('Customer refund has not been paid yet. Use Pay Customer Refund to record the payment first.');
            }
            if ($latestSupplierRefundEvent === null && $supplierRefundAmount > 0.005) {
                throw new RuntimeException('Supplier refund has not been received yet. Use Record Supplier Refund to post it first.');
            }
            $oldCustomerRefundAmount = $this->moneyValue($latestCustomerRefundEvent['customer_refund_amount'] ?? 0);
            $oldSupplierRefundAmount = $this->moneyValue($latestSupplierRefundEvent['supplier_refund_amount'] ?? 0);
            $hasPostedRefund = $latestCustomerRefundEvent !== null || $latestSupplierRefundEvent !== null;

            if ($hasPostedRefund && $settlementValuesChanged) {
                throw new RuntimeException(
                    'Posted refunds are protected. Reverse the specific refund explicitly before changing cancellation settlement values.'
                );
            }

            $refundDetailRepository = new BookingServiceRefundDetailRepository($this->app);
            $customerRefundChanged = $this->refundCorrectionScopeChanged(
                $input,
                $latestCustomerRefundEvent,
                $refundDetailRepository,
                'customer',
                $customerRefundAmount,
                (int) ($existingService['branch_id'] ?? 0),
                (string) ($latestCustomerRefundEvent['currency'] ?? $bookingCurrency)
            );
            $supplierRefundChanged = $this->refundCorrectionScopeChanged(
                $input,
                $latestSupplierRefundEvent,
                $refundDetailRepository,
                'supplier',
                $supplierRefundAmount,
                (int) ($existingService['branch_id'] ?? 0),
                $supplierRefundAccountingCurrency
            );

            $legacyCombinedRefund = $latestCustomerRefundEvent !== null
                && $latestSupplierRefundEvent !== null
                && (int) ($latestCustomerRefundEvent['id'] ?? 0) === (int) ($latestSupplierRefundEvent['id'] ?? 0);
            if ($legacyCombinedRefund) {
                $customerRefundChanged = true;
                $supplierRefundChanged = true;
            }

            foreach (['customer', 'supplier'] as $refundScope) {
                $scopeChanged = $refundScope === 'customer' ? $customerRefundChanged : $supplierRefundChanged;
                if (! $scopeChanged) {
                    continue;
                }
                $refundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, $refundScope);
                if ($refundEvent !== null) {
                    $this->reverseRefundMutation(
                        $bookingId,
                        $serviceId,
                        $refundEvent,
                        $refundScope,
                        'Correction reset: ' . $reason,
                        $eventDate,
                        $actorUserId,
                        $bookingReference,
                        $existingService
                    );
                }
            }

            if ($settlementValuesChanged) {
                app_write_log('service.penalty_refund.settlement_rebuild_requested', 'Penalty/refund correction will rebuild settlement because settlement values changed.', array_merge(app_request_log_context(), [
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'service_id' => $serviceId,
                    'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                    'old_customer_penalty_amount' => $oldCustomerPenaltyAmount,
                    'new_customer_penalty_amount' => $newCustomerPenaltyAmount,
                    'old_expected_supplier_refund_amount' => $oldExpectedSupplierRefundAmount,
                    'new_expected_supplier_refund_amount' => $newExpectedSupplierRefundAmount,
                    'customer_refund_amount' => $customerRefundAmount,
                    'supplier_refund_amount' => $supplierRefundAmount,
                ]));
                $supplierSettlementCurrency = (string) ($existingService['cost_currency'] ?? $bookingCurrency);
                $this->settleCancellationFinancials([
                    'booking_id' => $bookingId,
                    'service_id' => $serviceId,
                    'settlement_edit_mode' => 1,
                    'settlement_event_date' => $eventDate,
                    'customer_penalty_amount' => $input['customer_penalty_amount'] ?? 0,
                    'customer_penalty_currency' => $input['customer_penalty_currency'] ?? $bookingCurrency,
                    'customer_penalty_exchange_rate' => $input['customer_penalty_exchange_rate'] ?? 1,
                    'expected_supplier_refund_amount' => $input['expected_supplier_refund_amount'] ?? 0,
                    'expected_supplier_refund_currency' => $input['expected_supplier_refund_currency'] ?? $supplierSettlementCurrency,
                    'expected_supplier_refund_exchange_rate' => $input['expected_supplier_refund_exchange_rate'] ?? 1,
                    'expected_supplier_refund_rate_date' => $input['expected_supplier_refund_rate_date'] ?? $eventDate,
                    'supplier_refund_conversion_mode' => $input['supplier_refund_conversion_mode'] ?? '',
                    'expected_supplier_refund_equivalent_amount' => $input['expected_supplier_refund_equivalent_amount'] ?? null,
                    'agency_fee_refund_amount' => $input['agency_fee_refund_amount'] ?? 0,
                    'supplier_penalty_amount' => $input['supplier_penalty_amount'] ?? 0,
                    'supplier_penalty_currency' => $input['supplier_penalty_currency'] ?? $supplierSettlementCurrency,
                    'supplier_penalty_exchange_rate' => $input['supplier_penalty_exchange_rate'] ?? 1,
                    'settlement_currency' => $input['settlement_currency'] ?? $bookingCurrency,
                    'settlement_exchange_rate' => $input['settlement_exchange_rate'] ?? 1,
                    'settlement_reason' => $reason,
                    'settlement_notes' => 'Penalty/refund correction saved by user.',
                ], $actorUserId, $accessibleBranchIds);
            } else {
                $expectationCurrency = $this->normalizeCurrency((string) ($input['expected_supplier_refund_currency'] ?? $input['settlement_currency'] ?? $bookingCurrency));
                $expectationSourceAmount = $this->moneyValue($input['expected_supplier_refund_amount'] ?? 0);
                $expectationRate = $expectationCurrency === $bookingCurrency ? 1.0
                    : $this->normalizeOptionalExchangeRate((string) ($input['expected_supplier_refund_exchange_rate'] ?? $input['settlement_exchange_rate'] ?? ''));
                $expectationRateDate = $this->normalizeOptionalDate((string) ($input['expected_supplier_refund_rate_date']
                    ?? $cancelPayload['expected_supplier_refund_rate_date'] ?? $cancelEvent['event_date'] ?? $eventDate)) ?? $eventDate;
                $expectationChanged = $expectationCurrency !== (string) ($cancelPayload['expected_supplier_refund_currency'] ?? $bookingCurrency)
                    || abs($expectationSourceAmount - (float) ($cancelPayload['expected_supplier_refund_source_amount'] ?? $oldExpectedSupplierRefundAmount)) > 0.005
                    || abs((float) $expectationRate - (float) ($cancelPayload['expected_supplier_refund_exchange_rate'] ?? 1)) > 0.00000001
                    || ($expectationCurrency !== $bookingCurrency && $expectationRateDate !== (string) ($cancelPayload['expected_supplier_refund_rate_date'] ?? $cancelEvent['event_date'] ?? ''))
                    || ($this->supplierRefundEquivalentMetadata($input) !== []
                        && array_intersect_key($cancelPayload, $this->supplierRefundEquivalentMetadata($input)) != $this->supplierRefundEquivalentMetadata($input));
                if ($expectationChanged) {
                    if ($latestSupplierRefundEvent !== null) {
                        throw new RuntimeException('A supplier refund is already posted. Its expectation currency cannot be relabeled through a settlement-only correction.');
                    }
                    $sourceInSupplierCurrency = $this->settlementAmountInTruthCurrency(
                        $input, 'expected_supplier_refund_amount', $bookingCurrency, $supplierTruthCurrency, $supplierTruthRateToBooking
                    );
                    if ($sourceInSupplierCurrency > (float) ($existingService['purchase_cost'] ?? 0) + 0.005) {
                        throw new RuntimeException('Expected supplier refund exceeds the original supplier cost. Review the currency and rate.');
                    }
                    $expectation = [
                        'expected_supplier_refund_amount' => $newExpectedSupplierRefundAmount,
                        'expected_supplier_refund_booking_amount' => $newExpectedSupplierRefundAmount,
                        'expected_supplier_refund_source_amount' => $expectationSourceAmount,
                        'expected_supplier_refund_currency' => $expectationCurrency,
                        'expected_supplier_refund_exchange_rate' => $expectationRate ?? 0,
                        'expected_supplier_refund_rate_date' => $expectationRateDate,
                        ...$this->supplierRefundEquivalentMetadata($input),
                    ];
                    $eventRepository->updateSupplierRefundExpectation((int) $cancelEvent['id'], (string) $cancelEvent['payload_json'], $expectation);
                    AuditLog::record($this->app, 'service.cancellation_supplier_expectation.corrected', [
                        'user_id' => $actorUserId, 'booking_id' => $bookingId,
                        'booking_reference' => $bookingReference, 'service_id' => $serviceId,
                        'service_event_id' => (int) $cancelEvent['id'],
                        'before' => array_intersect_key($cancelPayload, $expectation),
                        'after' => $expectation, 'reason' => $reason,
                    ]);
                }
                AuditLog::record($this->app, 'service.penalty_refund.settlement_unchanged', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'service_id' => $serviceId,
                    'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                    'currency' => (string) ($existingService['currency'] ?? 'PKR'),
                    'customer_penalty_amount' => $oldCustomerPenaltyAmount,
                    'agency_fee_refund_amount' => $oldAgencyFeeRefundAmount,
                    'expected_supplier_refund_amount' => $oldExpectedSupplierRefundAmount,
                    'reason' => $reason,
                ]);
            }

            foreach (['customer', 'supplier'] as $refundScope) {
                $scopeChanged = $refundScope === 'customer' ? $customerRefundChanged : $supplierRefundChanged;
                $scopeAmount = $refundScope === 'customer' ? $customerRefundAmount : $supplierRefundAmount;
                if (! $scopeChanged || $scopeAmount <= 0.005) {
                    continue;
                }

                $refundInput = $input;
                $refundInput['refund_event_date'] = $eventDate;
                $refundInput['refund_reason'] = $reason;
                $refundInput['refund_notes'] = 'Penalty/refund correction saved by user.';
                $refundInput['_refund_booking_currency_override'] = (string) (
                    ($refundScope === 'customer' ? $latestCustomerRefundEvent : $latestSupplierRefundEvent)['currency']
                    ?? $bookingCurrency
                );
                if ($refundScope === 'customer') {
                    $refundInput['supplier_refund_amount'] = 0;
                    $refundInput['supplier_refund_source_amount'] = 0;
                } else {
                    $refundInput['customer_refund_amount'] = 0;
                    $refundInput['customer_refund_source_amount'] = 0;
                }
                $this->refundService($refundInput, $actorUserId, $accessibleBranchIds, true);
            }

            AuditLog::record($this->app, 'service.penalty_refund.corrected', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'currency' => (string) ($existingService['currency'] ?? 'PKR'),
                'customer_penalty_amount' => $newCustomerPenaltyAmount,
                'agency_fee_refund_amount' => $newAgencyFeeRefundAmount,
                'expected_supplier_refund_amount' => $newExpectedSupplierRefundAmount,
                'customer_refund_amount' => $customerRefundAmount,
                'supplier_refund_amount' => $supplierRefundAmount,
                'customer_refund_changed' => $customerRefundChanged,
                'supplier_refund_changed' => $supplierRefundChanged,
                'unchanged_refunds_preserved' => ! $customerRefundChanged || ! $supplierRefundChanged,
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

    public function reverseCancellationSettlement(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['settlement_reverse_reason'] ?? null, 1000, 'Reverse settlement reason is required.');
        $eventDate = date('Y-m-d');

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot reverse cancellation settlement for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be loaded for settlement reversal.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
        if ($cancelEvent === null) {
            throw new RuntimeException('No posted cancellation settlement exists for this service.');
        }

        $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
        if (! is_array($cancelPayload)) {
            $cancelPayload = [];
        }

        $isFinanciallySettled = (string) ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
            || array_key_exists('customer_final_charge_amount', $cancelPayload)
            || array_key_exists('expected_supplier_refund_amount', $cancelPayload)
            || array_key_exists('supplier_penalty_amount', $cancelPayload);
        if (! $isFinanciallySettled) {
            throw new RuntimeException('This cancellation has no posted financial settlement to reverse.');
        }

        $latestRefundEvent = $eventRepository->latestPostedEvent($serviceId, 'refund');
        if ($latestRefundEvent !== null) {
            throw new RuntimeException('Reverse the latest posted refund for this service before reversing the cancellation settlement.');
        }

        $bookingReference = (string) ($booking['booking_reference'] ?? '');
        $lineReference = (string) ($existingService['line_reference'] ?? '');
        $currency = (string) ($existingService['currency'] ?? 'PKR');
        $priorCustomerDueAmount = round((float) ($cancelPayload['prior_customer_due_amount'] ?? 0), 2);
        $releasedCustomerCredit = round((float) ($cancelPayload['released_customer_credit'] ?? $cancelEvent['customer_credit_amount'] ?? 0), 2);
        $releasedSupplierCredit = round((float) (
            $cancelPayload['released_supplier_credit_source_amount']
            ?? $cancelPayload['expected_supplier_refund_credit_source_amount']
            ?? $cancelPayload['released_supplier_credit']
            ?? $cancelPayload['expected_supplier_refund_credit']
            ?? $cancelEvent['supplier_credit_amount']
            ?? 0
        ), 2);
        $supplierCostBasis = round((float) ($cancelPayload['supplier_cost_basis'] ?? $existingService['purchase_cost'] ?? 0), 2);
        $journalEntryIds = array_values(array_filter(array_map('intval', (array) ($cancelPayload['journal_entry_ids'] ?? [])), static fn (int $id): bool => $id > 0));

        $customerRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
        $supplierTruthCurrency = $this->resolveSupplierSettlementTruthCurrency($existingService, $currency);
        $supplierTruthRateToBooking = $this->resolveServiceCurrencyRateToBooking($existingService, $supplierTruthCurrency, $currency);

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $this->reverseCancellationSettlementMutation(
                $bookingId,
                $serviceId,
                $actorUserId,
                $bookingReference,
                $lineReference,
                $currency,
                $existingService,
                $cancelEvent,
                $cancelPayload,
                $eventDate,
                $reason,
                $priorCustomerDueAmount,
                $releasedCustomerCredit,
                $releasedSupplierCredit,
                $supplierCostBasis,
                $journalEntryIds
            );

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

    public function reopenCancelledService(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['cancel_reopen_reason'] ?? null, 1000, 'Reverse cancel reason is required.');

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot reopen a cancelled service for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        if ($booking === null || ! $serviceRepository->serviceBelongsToAccessibleBooking($serviceId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected service line could not be loaded for cancel reversal.');
        }

        $existingService = $serviceRepository->findServiceById($serviceId);
        if ($existingService === null || (int) $existingService['booking_id'] !== $bookingId) {
            throw new RuntimeException('The selected service line does not belong to this booking.');
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
        if ($cancelEvent === null) {
            throw new RuntimeException('No posted cancellation exists for this service.');
        }

        $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
        if (! is_array($cancelPayload)) {
            $cancelPayload = [];
        }

        $latestRefundEvent = $eventRepository->latestPostedEvent($serviceId, 'refund');
        if ($latestRefundEvent !== null) {
            throw new RuntimeException('Reverse the latest posted refund for this service before reopening the cancellation.');
        }

        $isFinanciallySettled = (string) ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
            || array_key_exists('customer_final_charge_amount', $cancelPayload)
            || array_key_exists('expected_supplier_refund_amount', $cancelPayload)
            || array_key_exists('supplier_penalty_amount', $cancelPayload);
        if ($isFinanciallySettled) {
            throw new RuntimeException('Reverse the cancellation settlement before reopening this cancelled service.');
        }

        $priorServiceStatus = trim((string) ($cancelPayload['prior_service_status'] ?? 'Open'));
        if ($priorServiceStatus === '') {
            $priorServiceStatus = 'Open';
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $serviceRepository->updateServiceStatus($serviceId, $priorServiceStatus, $actorUserId);
            $voidResult = $eventRepository->voidEvent(
                (int) $cancelEvent['id'],
                $reason,
                $actorUserId,
                'VOID-CANCEL-EVT-' . (int) $cancelEvent['id'],
                null,
                [
                    'cancel_reopened' => true,
                    'cancel_reopen_reason' => $reason,
                    'restored_service_status' => $priorServiceStatus,
                ]
            );

            AuditLog::record($this->app, 'service.cancel.reopened', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'service_id' => $serviceId,
                'service_event_id' => (int) ($cancelEvent['id'] ?? 0),
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'restored_service_status' => $priorServiceStatus,
                'reason' => $reason,
                'void_result' => $voidResult,
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

    private function reverseCancellationSettlementMutation(
        int $bookingId,
        int $serviceId,
        int $actorUserId,
        string $bookingReference,
        string $lineReference,
        string $currency,
        array $existingService,
        array $cancelEvent,
        array $cancelPayload,
        string $eventDate,
        string $reason,
        float $priorCustomerDueAmount,
        float $releasedCustomerCredit,
        float $releasedSupplierCredit,
        float $supplierCostBasis,
        array $journalEntryIds
    ): void {
        $accountingRepository = new AccountingRepository($this->app);
        $eventRepository = new BookingServiceEventRepository($this->app);
        $customerRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $supplierRestoreCurrency = $this->resolveSupplierRestoreCurrency($cancelPayload, $existingService, $currency);

        $convertedAdvanceIds = (array) ($cancelPayload['supplier_credit_converted_advance_ids'] ?? []);
        if ($convertedAdvanceIds !== []) {
            $supplierRepository->reverseRetainedRefundAdvanceConversion(
                $convertedAdvanceIds,
                $actorUserId,
                'Cancellation settlement reversal for ' . $lineReference
            );
        }

        $reversalJournalEntryIds = [];
        foreach (array_reverse($journalEntryIds) as $journalEntryId) {
            $reversalJournalEntryId = $accountingRepository->reverseJournalEntry($journalEntryId, [
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'source_type' => 'service_cancellation_financials_reversed',
                'source_reference' => $lineReference . '-CANCEL-REV-' . $journalEntryId,
                'entry_date' => $eventDate,
                'narration' => 'Cancellation settlement reversal for ' . $lineReference,
                'actor_user_id' => $actorUserId,
            ]);
            if ($reversalJournalEntryId !== null && $reversalJournalEntryId > 0) {
                $reversalJournalEntryIds[] = $reversalJournalEntryId;
            }
        }

        $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        if ($receivable !== null) {
            $customerRepository->syncReceivableItem([
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'due_group' => 'service_sale',
                'currency' => $currency,
                'due_amount' => $priorCustomerDueAmount,
                'due_date' => $existingService['due_date'] ?? null,
                'status' => $priorCustomerDueAmount > 0 ? 'open' : 'cancelled',
                'remarks' => 'Cancellation settlement reversed [' . $lineReference . ']',
                'actor_user_id' => $actorUserId,
            ]);
            $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        }

        if ($releasedCustomerCredit > 0.005 && $receivable !== null) {
            $remainingCustomerRestore = $releasedCustomerCredit;
            $receiptRows = $customerRepository->availableReceiptCreditsForBooking($bookingReference, $currency);
            foreach ($receiptRows as $receiptRow) {
                if ($remainingCustomerRestore <= 0.005) {
                    break;
                }

                $receiptId = (int) ($receiptRow['id'] ?? 0);
                if ($this->completeRecoveryInProgress && ! in_array($receiptId, array_map('intval', $cancelPayload['released_customer_receipt_ids'] ?? []), true)) { continue; }
                $receiptUnallocated = round((float) ($receiptRow['unallocated_amount'] ?? 0), 2);
                if ($receiptId <= 0 || $receiptUnallocated <= 0.005) {
                    continue;
                }

                $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
                $allocationAmount = round(min($remainingCustomerRestore, $receiptUnallocated, (float) $receivable['outstanding_amount']), 2);
                if ($allocationAmount <= 0.005) {
                    continue;
                }

                $customerRepository->allocateReceiptExplicit([
                    'receipt_id' => $receiptId,
                    'receivable_item_id' => (int) $receivable['id'],
                    'payment_currency' => $currency,
                    'receivable_amount_to_settle' => $allocationAmount,
                    'payment_amount_to_consume' => $allocationAmount,
                    'rate_from_currency' => $currency,
                    'rate_to_currency' => $currency,
                    'exchange_rate' => 1,
                    'exchange_rate_effective_date' => $eventDate,
                    'allocation_note' => 'Cancellation settlement reversal restore for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);

                $remainingCustomerRestore = round($remainingCustomerRestore - $allocationAmount, 2);
            }

            if ($remainingCustomerRestore > ($this->recoveryMoneyBudgets['customer'] ?? 0) + 0.005) {
                throw new RuntimeException('Customer credit released by the cancellation settlement could not be fully restored.');
            }
            if ($remainingCustomerRestore > 0.005) {
                $accountingRepository->postRefundRecoveryOutstanding([
                    'scope' => 'customer', 'amount' => $remainingCustomerRestore,
                    'branch_id' => (int) $existingService['branch_id'], 'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference, 'customer_receivable_item_id' => $receivable['id'],
                    'currency' => $currency, 'entry_date' => $eventDate, 'actor_user_id' => $actorUserId,
                    'source_type' => 'service_refund_recovery_outstanding', 'source_reference' => 'RECOVER-CUSTOMER-' . $cancelEvent['id'],
                    'narration' => 'Original invoice restored; actual customer refund remains recoverable: ' . $reason,
                ]);
            }
        }

        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
        if ($obligation !== null) {
            $supplierSync = $supplierRepository->syncObligation([
                'supplier_id' => ! empty($existingService['supplier_id']) ? (int) $existingService['supplier_id'] : null,
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'obligation_group' => 'service_cost',
                'currency' => $supplierRestoreCurrency,
                'gross_amount' => $supplierCostBasis,
                'due_date' => $existingService['due_date'] ?? null,
                'remarks' => 'Cancellation settlement reversed [' . $lineReference . ']',
                'actor_user_id' => $actorUserId,
            ]);

            $restoredObligationId = (int) ($supplierSync['record']['id'] ?? $obligation['id'] ?? 0);
            $supplierId = (int) ($existingService['supplier_id'] ?? $obligation['supplier_id'] ?? 0);
            if ($releasedSupplierCredit > 0.005 && $restoredObligationId > 0 && $supplierId > 0) {
                $remainingSupplierRestore = $releasedSupplierCredit;
                $paymentRows = $supplierRepository->availablePaymentRowsForBooking(
                    $supplierId,
                    $bookingReference,
                    $supplierRestoreCurrency,
                    $this->completeRecoveryInProgress ? (array) ($cancelPayload['released_supplier_payment_ids'] ?? []) : []
                );
                foreach ($paymentRows as $paymentRow) {
                    if ($remainingSupplierRestore <= 0.005) {
                        break;
                    }

                    $paymentId = (int) ($paymentRow['id'] ?? 0);
                    if ($this->completeRecoveryInProgress && ! in_array($paymentId, array_map('intval', $cancelPayload['released_supplier_payment_ids'] ?? []), true)) { continue; }
                    $paymentUnallocated = round((float) ($paymentRow['unallocated_amount'] ?? 0), 2);
                    if ($paymentId <= 0 || $paymentUnallocated <= 0.005) {
                        continue;
                    }

                    $currentObligation = $supplierRepository->findObligationById($restoredObligationId);
                    $allocationAmount = round(min($remainingSupplierRestore, $paymentUnallocated, (float) $currentObligation['net_payable_amount']), 2);
                    if ($allocationAmount <= 0.005) {
                        continue;
                    }

                    $supplierRepository->allocateSupplierPayment(
                        $paymentId,
                        $restoredObligationId,
                        $allocationAmount,
                        1.0,
                        'Cancellation settlement reversal restore for ' . $lineReference,
                        $actorUserId
                    );
                    $remainingSupplierRestore = round($remainingSupplierRestore - $allocationAmount, 2);
                }

                if ($remainingSupplierRestore > 0.005) {
                    $advanceRows = $supplierRepository->availableAdvanceRowsForSupplierPool($supplierId, $supplierRestoreCurrency);
                    foreach ($advanceRows as $advanceRow) {
                        if ($remainingSupplierRestore <= 0.005) {
                            break;
                        }

                        $advanceId = (int) ($advanceRow['id'] ?? 0);
                        if ($this->completeRecoveryInProgress && ! in_array($advanceId, array_map('intval', $cancelPayload['released_supplier_advance_ids'] ?? []), true)) { continue; }
                        $advanceAvailable = round((float) ($advanceRow['available_amount'] ?? 0), 2);
                        if ($advanceId <= 0 || $advanceAvailable <= 0.005) {
                            continue;
                        }

                        $appliedAmount = $supplierRepository->applyAdvanceToObligation(
                            $advanceId,
                            $restoredObligationId,
                            min($remainingSupplierRestore, $advanceAvailable,
                                (float) $supplierRepository->findObligationById($restoredObligationId)['net_payable_amount']),
                            $actorUserId
                        );
                        $remainingSupplierRestore = round($remainingSupplierRestore - $appliedAmount, 2);
                    }
                }

                if ($remainingSupplierRestore > ($this->recoveryMoneyBudgets['supplier'] ?? 0) + 0.005) {
                    throw new RuntimeException('Supplier credit released by the cancellation settlement could not be fully restored.');
                }
                if ($remainingSupplierRestore > 0.005) {
                    $accountingRepository->postRefundRecoveryOutstanding([
                        'scope' => 'supplier', 'amount' => $remainingSupplierRestore,
                        'branch_id' => (int) $existingService['branch_id'], 'booking_reference' => $bookingReference,
                        'service_line_reference' => $lineReference, 'supplier_obligation_id' => $restoredObligationId,
                        'currency' => $supplierRestoreCurrency, 'entry_date' => $eventDate, 'actor_user_id' => $actorUserId,
                        'source_type' => 'service_refund_recovery_outstanding', 'source_reference' => 'RECOVER-SUPPLIER-' . $cancelEvent['id'],
                        'narration' => 'Original supplier invoice restored; actual supplier return remains payable: ' . $reason,
                    ]);
                }
            }
        }

        $priorServiceStatus = (string) ($cancelPayload['prior_service_status'] ?? 'Open');
        $updatedPayload = [
            'mode' => 'operational_cancel_only',
            'financial_effect_pending' => true,
            'prior_service_status' => $priorServiceStatus !== '' ? $priorServiceStatus : 'Open',
            'settlement_reversed' => true,
            'settlement_reversal_reason' => $reason,
            'settlement_reversal_journal_entry_ids' => $reversalJournalEntryIds,
            'prior_customer_due_amount' => $priorCustomerDueAmount,
            'supplier_cost_basis' => $supplierCostBasis,
        ];
        $eventRepository->updateCancellationFinancials((int) $cancelEvent['id'], [
            'penalty_amount' => 0,
            'customer_credit_amount' => 0,
            'supplier_credit_amount' => 0,
            'journal_entry_id' => null,
            'reason' => (string) ($cancelEvent['reason'] ?? ''),
            'notes' => $this->appendAuditNote((string) ($cancelEvent['notes'] ?? ''), 'Settlement reversed: ' . $reason),
            'payload_json' => $updatedPayload,
        ]);

        AuditLog::record($this->app, 'service.cancellation_financials.reversed', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'service_id' => $serviceId,
            'service_event_id' => (int) ($cancelEvent['id'] ?? 0),
            'service_line_reference' => $lineReference,
            'currency' => $currency,
            'restored_customer_due_amount' => $priorCustomerDueAmount,
            'restored_supplier_cost_amount' => $supplierCostBasis,
            'restored_customer_credit_amount' => $releasedCustomerCredit,
            'restored_supplier_credit_amount' => $releasedSupplierCredit,
            'reversal_journal_entry_ids' => $reversalJournalEntryIds,
            'reason' => $reason,
        ]);
    }

    public function settleCancellationFinancials(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['settlement_reason'] ?? null, 1000, 'Cancellation settlement reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['settlement_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $editMode = (int) ($input['settlement_edit_mode'] ?? 0) === 1;
        $hasExpectedSupplierRefundInput = array_key_exists('expected_supplier_refund_amount', $input)
            && trim((string) $input['expected_supplier_refund_amount']) !== '';

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

        $eventRepository = new BookingServiceEventRepository($this->app);
        if (
            ! $this->isCancelledStatus((string) ($existingService['service_status'] ?? ''))
            && ! $eventRepository->postedEventExists($serviceId, 'cancel')
        ) {
            throw new RuntimeException('Cancel the service line before settling cancellation financials.');
        }

        $bookingReference = (string) $booking['booking_reference'];
        $lineReference = (string) $existingService['line_reference'];
        $currency = (string) ($existingService['currency'] ?? 'PKR');
        $input = $this->normalizeSupplierRefundEquivalentInput($input, $currency, $existingService);
        $settlementCurrency = $this->normalizeCurrency((string) ($input['settlement_currency'] ?? $currency));
        $customerPenaltyAmount = $this->settlementAmountInBookingCurrency($input, 'customer_penalty_amount', $currency);
        $agencyFeeRefundAmount = $this->settlementAmountInBookingCurrency($input, 'agency_fee_refund_amount', $currency);
        $agencyFeeChargedAmount = round(max((float) ($existingService['service_charge'] ?? 0), 0.0), 2);
        if ($agencyFeeRefundAmount > $agencyFeeChargedAmount + 0.005) {
            throw new RuntimeException('Agency fee refund cannot exceed the agency service fee charged on this service.');
        }
        $customerRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
        $supplierTruthCurrency = $this->resolveSupplierSettlementTruthCurrency($existingService, $currency);
        $supplierTruthRateToBooking = $this->resolveServiceCurrencyRateToBooking($existingService, $supplierTruthCurrency, $currency);
        $this->assertCustomerReceivableCurrencyMatchesInvoice($receivable, $currency);
        $this->assertSupplierObligationCurrencyMatchesCost($obligation, $supplierTruthCurrency);

        if ($hasExpectedSupplierRefundInput
            && (float) ($input['expected_supplier_refund_amount'] ?? 0) > 0
            && $supplierTruthCurrency !== $currency
            && $this->normalizeCurrency((string) ($input['expected_supplier_refund_currency'] ?? $input['settlement_currency'] ?? $currency)) === $supplierTruthCurrency) {
            $supplierTruthRateToBooking = $this->normalizeOptionalExchangeRate((string) ($input['expected_supplier_refund_exchange_rate'] ?? $input['settlement_exchange_rate'] ?? ''));
            if ($supplierTruthRateToBooking === null || $supplierTruthRateToBooking <= 0.00000001) {
                throw new RuntimeException('Confirm the supplier refund exchange rate before settling this mixed-currency cancellation.');
            }
        }

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
            $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
            if (! is_array($cancelPayload)) {
                $cancelPayload = [];
            }
            $alreadyFinanciallySettled = (string) ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
                || array_key_exists('customer_final_charge_amount', $cancelPayload)
                || array_key_exists('expected_supplier_refund_amount', $cancelPayload)
                || array_key_exists('supplier_penalty_amount', $cancelPayload);

            if ($editMode) {
                if (! $alreadyFinanciallySettled) {
                    throw new RuntimeException('There is no posted cancellation settlement to edit for this service.');
                }
                if (($cancelPayload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true
                    || (int) ($cancelPayload['customer_offset_version'] ?? 0) > 0) {
                    throw new RuntimeException('This repaired cancellation requires Edit Refund for its customer balance. Its supplier journals cannot be rebuilt through Save Refund.');
                }
                $latestRefundEvent = $eventRepository->latestPostedEvent($serviceId, 'refund');
                if ($latestRefundEvent !== null) {
                    throw new RuntimeException('Reverse posted customer or supplier refunds for this service before editing the cancellation settlement.');
                }

                $this->reverseCancellationSettlementMutation(
                    $bookingId,
                    $serviceId,
                    $actorUserId,
                    $bookingReference,
                    $lineReference,
                    $currency,
                    $existingService,
                    $cancelEvent,
                    $cancelPayload,
                    $eventDate,
                    'Settlement edit reset: ' . $reason,
                    round((float) ($cancelPayload['prior_customer_due_amount'] ?? 0), 2),
                    round((float) ($cancelPayload['released_customer_credit'] ?? $cancelEvent['customer_credit_amount'] ?? 0), 2),
                    round((float) (
                        $cancelPayload['released_supplier_credit_source_amount']
                        ?? $cancelPayload['expected_supplier_refund_credit_source_amount']
                        ?? $cancelPayload['released_supplier_credit']
                        ?? $cancelPayload['expected_supplier_refund_credit']
                        ?? $cancelEvent['supplier_credit_amount']
                        ?? 0
                    ), 2),
                    round((float) ($cancelPayload['supplier_cost_basis'] ?? $existingService['purchase_cost'] ?? 0), 2),
                    array_values(array_filter(array_map('intval', (array) ($cancelPayload['journal_entry_ids'] ?? [])), static fn (int $id): bool => $id > 0))
                );

                $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
                $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
            }

            $supplierNetPayableBeforeSettlement = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
            $allocatedCustomerAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);
            $currentCustomerDueAmount = round((float) ($receivable['due_amount'] ?? 0), 2);
            $serviceCustomerSaleBasis = round((float) (
                $existingService['final_sale_price']
                ?? $existingService['sale_price']
                ?? $currentCustomerDueAmount
            ), 2);
            $linkedCustomerOffsetAmount = $receivable !== null
                ? $customerRepository->linkedPartyOffsetAmountForReceivableItem((int) ($receivable['id'] ?? 0))
                : 0.0;
            $customerSettledAmount = round(max($allocatedCustomerAmount, $linkedCustomerOffsetAmount, 0.0), 2);
            $customerRefundBasis = $customerSettledAmount;
            $supplierCostBasis = round((float) ($existingService['purchase_cost'] ?? 0), 2);
            $supplierCostBasisBooking = $this->convertSettlementTruthAmountToBookingCurrency(
                $supplierCostBasis,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
            if ($hasExpectedSupplierRefundInput) {
                $expectedSupplierRefundSourceAmount = max($this->settlementAmountInTruthCurrency(
                        $input,
                        'expected_supplier_refund_amount',
                        $currency,
                        $supplierTruthCurrency,
                        $supplierTruthRateToBooking
                    ), 0.0);
                if ($expectedSupplierRefundSourceAmount > $supplierCostBasis + 0.005) {
                    throw new RuntimeException('Expected supplier refund exceeds the original supplier cost. Review the amount, currency and exchange rate; no settlement was posted.');
                }
                $supplierPenaltySourceAmount = round(max($supplierCostBasis - $expectedSupplierRefundSourceAmount, 0), 2);
            } else {
                $supplierPenaltySourceAmount = min(
                    max($this->settlementAmountInTruthCurrency(
                        $input,
                        'supplier_penalty_amount',
                        $currency,
                        $supplierTruthCurrency,
                        $supplierTruthRateToBooking
                    ), 0.0),
                    max($supplierCostBasis, 0.0)
                );
                $expectedSupplierRefundSourceAmount = $supplierCostBasis > 0
                    ? round(max($supplierCostBasis - $supplierPenaltySourceAmount, 0), 2)
                    : 0.0;
            }
            $supplierPenaltyAmount = $this->convertSettlementTruthAmountToBookingCurrency(
                $supplierPenaltySourceAmount,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
            $expectedSupplierRefundAmount = $this->convertSettlementTruthAmountToBookingCurrency(
                $expectedSupplierRefundSourceAmount,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
            $retainedAgencyFeeAmount = round(max($agencyFeeChargedAmount - $agencyFeeRefundAmount, 0.0), 2);
            $customerSummary = CancellationRefundCalculator::customerSummary([
                'invoice' => $currentCustomerDueAmount,
                'settled' => $customerSettledAmount,
                'expected' => $expectedSupplierRefundAmount,
                'penalty' => $customerPenaltyAmount,
                'fee_refund' => $agencyFeeRefundAmount,
            ]);
            $customerFinalChargeAmount = $customerSummary['final_charge'];
            $targetCustomerRefundCredit = $customerSummary['entitlement'];

            $supplierSettledAmount = 0.0;
            if ($obligation !== null) {
                $supplierSettledAmount = round((float) ($obligation['gross_amount'] ?? 0) - (float) ($obligation['net_payable_amount'] ?? 0), 2);
            }

            $accountingRepository = new AccountingRepository($this->app);
            $releasedCustomerCredit = 0.0;
            $releasedSupplierCredit = 0.0;
            $customerReleaseResult = null;
            $supplierReleaseResult = null;
            if ($receivable !== null && $customerFinalChargeAmount < max($allocatedCustomerAmount, $linkedCustomerOffsetAmount) - 0.005) {
                $customerReleaseResult = $customerRepository->releaseAllocatedCreditForReceivable(
                    (int) $receivable['id'],
                    $customerFinalChargeAmount,
                    $actorUserId,
                    'Cancellation settlement release for ' . $lineReference
                );
                $releasedCustomerCredit = round((float) ($customerReleaseResult['released_payment_amount'] ?? 0), 2);
                $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
            }
            if ($obligation !== null && $supplierPenaltySourceAmount < $supplierSettledAmount - 0.005) {
                $supplierReleaseResult = $supplierRepository->releaseSettledCreditForObligation(
                    (int) $obligation['id'],
                    $supplierPenaltySourceAmount,
                    $actorUserId,
                    'Cancellation settlement release for ' . $lineReference
                );
                $releasedSupplierCredit = round((float) ($supplierReleaseResult['released_amount'] ?? 0), 2);
                if ((float) ($supplierReleaseResult['released_linked_offset_amount'] ?? 0) > 0.005) {
                    throw new RuntimeException('This supplier cost was settled through a linked-party offset. Review that offset before cancelling; no supplier credit or payable change was saved.');
                }
                $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
            }

            $expectedSupplierRefundCredit = $releasedSupplierCredit;
            $supplierBookingPaymentCredit = 0.0;
            $maxSupplierRefundCredit = $expectedSupplierRefundSourceAmount > 0
                ? $expectedSupplierRefundSourceAmount
                : 0.0;
            if ($obligation !== null && (int) ($obligation['supplier_id'] ?? 0) > 0) {
                $supplierBookingPaymentCredit = $supplierRepository->availableRefundablePaymentCreditForBooking(
                    (int) $obligation['supplier_id'],
                    $bookingReference,
                    $supplierTruthCurrency
                );
            }
            if ($supplierSettledAmount > 0.005) {
                $expectedSupplierRefundCredit = max(
                    $expectedSupplierRefundCredit,
                    round(max($supplierSettledAmount - $supplierPenaltySourceAmount, 0), 2)
                );
            }
            if ($supplierBookingPaymentCredit > 0.005 && $maxSupplierRefundCredit > 0.0) {
                $expectedSupplierRefundCredit = max(
                    $expectedSupplierRefundCredit,
                    min($supplierBookingPaymentCredit, $maxSupplierRefundCredit)
                );
            }
            if (
                $hasExpectedSupplierRefundInput
                && $expectedSupplierRefundSourceAmount > 0.005
                && (
                    $supplierSettledAmount > 0.005
                    || $releasedSupplierCredit > 0.005
                    || $supplierBookingPaymentCredit > 0.005
                )
            ) {
                $expectedSupplierRefundCredit = max($expectedSupplierRefundCredit, $expectedSupplierRefundSourceAmount);
            }
            if ($maxSupplierRefundCredit > 0.0) {
                $expectedSupplierRefundCredit = min($expectedSupplierRefundCredit, $maxSupplierRefundCredit);
            }
            $expectedSupplierRefundCredit = round(max($expectedSupplierRefundCredit, 0), 2);
            $releasedSupplierCreditBooking = $this->convertSettlementTruthAmountToBookingCurrency(
                $releasedSupplierCredit,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
            $expectedSupplierRefundCreditBooking = $this->convertSettlementTruthAmountToBookingCurrency(
                $expectedSupplierRefundCredit,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
            $supplierBookingPaymentCreditBooking = $this->convertSettlementTruthAmountToBookingCurrency(
                $supplierBookingPaymentCredit,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
            $customerCreditBalanceAfterSettlement = round(max(
                $customerRepository->bookingUnallocatedCreditTotal($bookingReference, $currency),
                0
            ), 2);
            $availableCustomerRefundCredit = round(max($targetCustomerRefundCredit, 0), 2);

            $customerSync = $customerRepository->syncReceivableItem([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'due_group' => 'service_sale',
                'currency' => $currency,
                'due_amount' => $customerFinalChargeAmount,
                'due_date' => $eventDate,
                'status' => $customerFinalChargeAmount > 0 ? 'open' : 'cancelled',
                'remarks' => 'Cancellation settlement customer penalty [' . $lineReference . ']',
                'actor_user_id' => $actorUserId,
            ]);

            $supplierSync = null;
            if (! empty($existingService['supplier_id']) || $supplierPenaltySourceAmount <= 0) {
                $supplierSync = $supplierRepository->syncObligation([
                    'supplier_id' => ! empty($existingService['supplier_id']) ? (int) $existingService['supplier_id'] : null,
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'obligation_group' => 'service_cost',
                    'currency' => $supplierTruthCurrency,
                    'gross_amount' => $supplierPenaltySourceAmount,
                    'due_date' => $eventDate,
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
                    'currency' => $supplierTruthCurrency,
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
                    'currency' => $supplierTruthCurrency,
                    'narration' => 'Cancellation supplier penalty adjustment for ' . $lineReference,
                    'actor_user_id' => $actorUserId,
                ]);
            }

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

            $expectedRefundCurrency = $hasExpectedSupplierRefundInput
                ? $this->normalizeCurrency((string) ($input['expected_supplier_refund_currency'] ?? $input['settlement_currency'] ?? $currency))
                : $supplierTruthCurrency;
            $releasedPaymentCredit = round((float) ($supplierReleaseResult['released_payment_amount'] ?? 0), 2);
            $releasedAdvanceCredit = round((float) ($supplierReleaseResult['released_advance_amount'] ?? 0), 2);
            $autoSupplierCredit = $expectedRefundCurrency === $supplierTruthCurrency;
            $supplierPaymentCreditConversion = null;
            if ($autoSupplierCredit && $releasedPaymentCredit > 0.005) {
                $supplierPaymentCreditConversion = $supplierRepository->convertRefundablePaymentCreditToAdvance(
                    (int) ($existingService['supplier_id'] ?? 0),
                    (int) $existingService['branch_id'],
                    $bookingReference,
                    $supplierTruthCurrency,
                    $releasedPaymentCredit,
                    $eventDate,
                    'CANCEL-CREDIT-' . $eventId,
                    $actorUserId,
                    $reason,
                    (array) ($supplierReleaseResult['affected_payment_ids'] ?? []),
                    true
                );
                if (abs((float) ($supplierPaymentCreditConversion['converted_payment_credit_amount'] ?? 0) - $releasedPaymentCredit) > 0.005) {
                    throw new RuntimeException('The released supplier payment could not be fully moved to reusable credit. No cancellation was posted.');
                }
            }
            $supplierCreditRecordedSourceAmount = $autoSupplierCredit
                ? round($releasedAdvanceCredit + $releasedPaymentCredit, 2)
                : 0.0;
            $supplierPayableReducedSourceAmount = $autoSupplierCredit
                ? round(max($supplierNetPayableBeforeSettlement - (float) ($supplierSync['record']['net_payable_amount'] ?? 0), 0), 2)
                : 0.0;
            $supplierCreditRecordedBookingAmount = $this->convertSettlementTruthAmountToBookingCurrency(
                $supplierCreditRecordedSourceAmount,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );

            BookingEditTraceService::checkpoint('ServiceWorkspaceService::cancellationAmounts', 'resolved', [
                'service_id' => $serviceId,
                'customer_currency' => $currency,
                'customer_final_charge' => $customerFinalChargeAmount,
                'customer_penalty' => $customerPenaltyAmount,
                'customer_refund_credit' => $availableCustomerRefundCredit,
                'supplier_truth_currency' => $supplierTruthCurrency,
                'supplier_penalty_source_amount' => $supplierPenaltySourceAmount,
                'expected_supplier_refund_source_amount' => $expectedSupplierRefundSourceAmount,
                'supplier_credit_recorded_source_amount' => $supplierCreditRecordedSourceAmount,
                'supplier_payable_reduced_source_amount' => $supplierPayableReducedSourceAmount,
                'converted_advance_ids' => array_values(array_map('intval', (array) ($supplierPaymentCreditConversion['supplier_advance_ids'] ?? []))),
            ]);

            $supplierSettlementCurrency = $supplierTruthCurrency;
            app_write_log('service.cancellation.supplier_truth_trace', 'Cancellation settlement resolved supplier truth and booking amounts.', array_merge(
                app_request_log_context(),
                [
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'service_id' => $serviceId,
                    'service_line_reference' => $lineReference,
                    'booking_currency' => $currency,
                    'supplier_truth_currency' => $supplierTruthCurrency,
                    'supplier_truth_rate_to_booking' => $supplierTruthRateToBooking,
                    'supplier_cost_basis_truth_amount' => $supplierCostBasis,
                    'supplier_cost_basis_booking_amount' => $supplierCostBasisBooking,
                    'supplier_penalty_truth_amount' => $supplierPenaltySourceAmount,
                    'supplier_penalty_booking_amount' => $supplierPenaltyAmount,
                    'expected_supplier_refund_truth_amount' => $expectedSupplierRefundSourceAmount,
                    'expected_supplier_refund_booking_amount' => $expectedSupplierRefundAmount,
                    'released_supplier_credit_truth_amount' => $releasedSupplierCredit,
                    'released_supplier_credit_booking_amount' => $releasedSupplierCreditBooking,
                    'expected_supplier_refund_credit_truth_amount' => $expectedSupplierRefundCredit,
                    'expected_supplier_refund_credit_booking_amount' => $expectedSupplierRefundCreditBooking,
                ]
            ));
            $expectedRefundRate = $hasExpectedSupplierRefundInput
                ? ($expectedRefundCurrency === $currency ? 1.0
                    : ($this->normalizeOptionalExchangeRate((string) ($input['expected_supplier_refund_exchange_rate'] ?? $input['settlement_exchange_rate'] ?? '')) ?? 0))
                : ($supplierTruthRateToBooking ?? 1);
            $eventRepository->updateCancellationFinancials($eventId, [
                'penalty_amount' => $customerPenaltyAmount,
                'customer_credit_amount' => $availableCustomerRefundCredit,
                'supplier_credit_amount' => $autoSupplierCredit
                    ? $supplierCreditRecordedBookingAmount
                    : $expectedSupplierRefundCreditBooking,
                'journal_entry_id' => $journalIds[0] ?? null,
                'reason' => $reason,
                'notes' => $this->optionalText($input['settlement_notes'] ?? null, 4000),
                'payload_json' => [
                    'prior_service_status' => (string) ($cancelPayload['prior_service_status'] ?? 'Open'),
                    'customer_penalty_amount' => $customerPenaltyAmount,
                    'customer_penalty_currency' => (string) ($input['customer_penalty_currency'] ?? $input['settlement_currency'] ?? $currency),
                    'customer_penalty_source_amount' => $this->moneyValue($input['customer_penalty_amount'] ?? 0),
                    'customer_penalty_exchange_rate' => (float) ($input['customer_penalty_exchange_rate'] ?? $input['settlement_exchange_rate'] ?? 1),
                    'settlement_currency' => $settlementCurrency,
                    'agency_fee_charged_amount' => $agencyFeeChargedAmount,
                    'agency_fee_refund_amount' => $agencyFeeRefundAmount,
                    'agency_fee_retained_amount' => round(max($agencyFeeChargedAmount - $agencyFeeRefundAmount, 0), 2),
                    'supplier_penalty_amount' => $supplierPenaltyAmount,
                    'supplier_penalty_currency' => $supplierSettlementCurrency,
                    'supplier_penalty_source_amount' => $supplierPenaltySourceAmount,
                    'supplier_penalty_exchange_rate' => $supplierTruthRateToBooking ?? 1,
                    'supplier_penalty_booking_amount' => $supplierPenaltyAmount,
                    'expected_supplier_refund_amount' => $expectedSupplierRefundAmount,
                    'expected_supplier_refund_currency' => $expectedRefundCurrency,
                    'expected_supplier_refund_source_amount' => $hasExpectedSupplierRefundInput
                        ? $this->moneyValue($input['expected_supplier_refund_amount']) : $expectedSupplierRefundSourceAmount,
                    'expected_supplier_refund_exchange_rate' => $expectedRefundRate,
                    'expected_supplier_refund_rate_date' => $this->normalizeOptionalDate((string) ($input['expected_supplier_refund_rate_date'] ?? $eventDate)) ?? $eventDate,
                    ...$this->supplierRefundEquivalentMetadata($input),
                    'expected_supplier_refund_booking_amount' => $expectedSupplierRefundAmount,
                    'customer_final_charge_amount' => $customerFinalChargeAmount,
                    'released_customer_credit' => $releasedCustomerCredit,
                    'available_customer_refund_credit' => $availableCustomerRefundCredit,
                    'available_customer_refund_credit_currency' => $currency,
                    'released_supplier_credit' => $releasedSupplierCreditBooking,
                    'released_supplier_credit_source_amount' => $releasedSupplierCredit,
                    'expected_supplier_refund_credit' => $expectedSupplierRefundCreditBooking,
                    'expected_supplier_refund_credit_source_amount' => $expectedSupplierRefundCredit,
                    'supplier_booking_payment_credit' => $supplierBookingPaymentCreditBooking,
                    'supplier_booking_payment_credit_source_amount' => $supplierBookingPaymentCredit,
                    'supplier_cost_basis' => $supplierCostBasis,
                    'supplier_cost_basis_currency' => $supplierTruthCurrency,
                    'supplier_cost_basis_booking_amount' => $supplierCostBasisBooking,
                    'customer_refund_basis' => $customerRefundBasis,
                    'customer_refund_basis_currency' => $currency,
                    'customer_received_amount' => $allocatedCustomerAmount,
                    'customer_receipt_id_watermark' => $customerRepository->latestReceiptIdForBooking($bookingReference),
                    'target_customer_refund_credit' => $targetCustomerRefundCredit,
                    'target_customer_refund_credit_currency' => $currency,
                    'calculation_version' => 2,
                    'customer_delta' => $customerDelta,
                    'supplier_delta' => $supplierDelta,
                    'supplier_delta_booking_amount' => $this->convertSettlementTruthAmountToBookingCurrency(
                        $supplierDelta,
                        $supplierTruthCurrency,
                        $currency,
                        $supplierTruthRateToBooking
                    ),
                    'prior_customer_due_amount' => $currentCustomerDueAmount,
                    'prior_supplier_obligation_gross_amount' => (float) ($obligation['gross_amount'] ?? 0),
                    'prior_supplier_obligation_currency' => (string) ($obligation['currency'] ?? $supplierTruthCurrency),
                    'released_customer_receipt_ids' => array_values(array_map('intval', (array) ($customerReleaseResult['affected_receipt_ids'] ?? []))),
                    'released_supplier_payment_credit' => (float) ($supplierReleaseResult['released_payment_amount'] ?? 0),
                    'released_supplier_advance_credit' => (float) ($supplierReleaseResult['released_advance_amount'] ?? 0),
                    'released_supplier_payment_ids' => array_values(array_map('intval', (array) ($supplierReleaseResult['affected_payment_ids'] ?? []))),
                    'released_supplier_advance_ids' => array_values(array_map('intval', (array) ($supplierReleaseResult['affected_advance_ids'] ?? []))),
                    ...($autoSupplierCredit ? [
                        'supplier_credit_recording_version' => 1,
                        'supplier_credit_recorded_source_amount' => $supplierCreditRecordedSourceAmount,
                        'supplier_payable_reduced_source_amount' => $supplierPayableReducedSourceAmount,
                        'supplier_credit_recorded_booking_amount' => $supplierCreditRecordedBookingAmount,
                        'supplier_credit_recorded_currency' => $supplierTruthCurrency,
                        'supplier_credit_converted_payment_amount' => $releasedPaymentCredit,
                        'supplier_credit_converted_advance_ids' => array_values(array_map('intval', (array) ($supplierPaymentCreditConversion['supplier_advance_ids'] ?? []))),
                    ] : []),
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
                'agency_fee_charged_amount' => $agencyFeeChargedAmount,
                'agency_fee_refund_amount' => $agencyFeeRefundAmount,
                'agency_fee_retained_amount' => round(max($agencyFeeChargedAmount - $agencyFeeRefundAmount, 0), 2),
                'supplier_penalty_amount' => $supplierPenaltyAmount,
                'supplier_penalty_source_amount' => $supplierPenaltySourceAmount,
                'supplier_penalty_currency' => $supplierTruthCurrency,
                'expected_supplier_refund_amount' => $expectedSupplierRefundAmount,
                'expected_supplier_refund_source_amount' => $expectedSupplierRefundSourceAmount,
                'expected_supplier_refund_currency' => $supplierTruthCurrency,
                'customer_final_charge_amount' => $customerFinalChargeAmount,
                'released_customer_credit' => $releasedCustomerCredit,
                'available_customer_refund_credit' => $availableCustomerRefundCredit,
                'released_supplier_credit' => $releasedSupplierCreditBooking,
                'released_supplier_credit_source_amount' => $releasedSupplierCredit,
                'supplier_credit_recorded_source_amount' => $supplierCreditRecordedSourceAmount,
                'supplier_payable_reduced_source_amount' => $supplierPayableReducedSourceAmount,
                'supplier_credit_converted_advance_ids' => array_values(array_map('intval', (array) ($supplierPaymentCreditConversion['supplier_advance_ids'] ?? []))),
                'expected_supplier_refund_credit' => $expectedSupplierRefundCreditBooking,
                'expected_supplier_refund_credit_source_amount' => $expectedSupplierRefundCredit,
                'customer_credit_balance_after_settlement' => $customerCreditBalanceAfterSettlement,
                'supplier_booking_payment_credit' => $supplierBookingPaymentCreditBooking,
                'supplier_booking_payment_credit_source_amount' => $supplierBookingPaymentCredit,
                'supplier_cost_basis' => $supplierCostBasis,
                'supplier_cost_basis_currency' => $supplierTruthCurrency,
                'supplier_cost_basis_booking_amount' => $supplierCostBasisBooking,
                'customer_refund_basis' => $customerRefundBasis,
                'customer_received_amount' => $allocatedCustomerAmount,
                'target_customer_refund_credit' => $targetCustomerRefundCredit,
                'customer_delta' => $customerDelta,
                'supplier_delta' => $supplierDelta,
                'supplier_delta_booking_amount' => $this->convertSettlementTruthAmountToBookingCurrency(
                    $supplierDelta,
                    $supplierTruthCurrency,
                    $currency,
                    $supplierTruthRateToBooking
                ),
                'prior_customer_due_amount' => $currentCustomerDueAmount,
                'journal_entry_ids' => $journalIds,
                'edit_mode' => $editMode,
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

    public function reissueService(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['reissue_reason'] ?? null, 1000, 'Reissue reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['reissue_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $newTicketNumber = $this->requiredText($input['new_ticket_number'] ?? null, 50, 'New ticket number is required.');
        $newPnr = $this->optionalText($input['new_pnr'] ?? null, 50);
        $serviceFeeAmount = $this->moneyValue($input['reissue_service_fee_amount'] ?? 0);
        $supplierCostDifferenceAmount = $this->moneyValue($input['supplier_cost_difference_amount'] ?? 0);
        $simplifiedPricing = (string) ($input['reissue_pricing_mode'] ?? '') === 'supplier_plus_service';
        $fareDifferenceAmount = $simplifiedPricing
            ? $supplierCostDifferenceAmount
            : $this->moneyValue($input['fare_difference_amount'] ?? 0);
        $receivedAmount = $this->moneyValue($input['reissue_received_amount'] ?? 0);
        $customerCreditReceiptId = max(0, (int) ($input['reissue_customer_credit_receipt_id'] ?? 0));
        $customerCreditApplyAmount = $this->moneyValue($input['reissue_customer_credit_apply_amount'] ?? 0);
        $paymentDueDate = $this->normalizeOptionalDate((string) ($input['reissue_due_date'] ?? ''));
        $rateEffectiveDate = $this->normalizeOptionalDate((string) ($input['reissue_rate_effective_date'] ?? $eventDate)) ?? $eventDate;

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

        $invoiceCurrency = $this->normalizeCurrency((string) ($existingService['currency'] ?? 'PKR'));
        $costCurrency = $this->normalizeCurrency((string) ($existingService['cost_currency'] ?? $invoiceCurrency));
        $serviceChargeCurrency = $this->normalizeCurrency((string) ($existingService['service_charge_currency'] ?? $invoiceCurrency));
        $supplierCurrency = $this->normalizeCurrency((string) ($input['reissue_supplier_currency'] ?? $costCurrency));
        $agencyFeeCurrency = $this->normalizeCurrency((string) ($input['reissue_agency_fee_currency'] ?? $serviceChargeCurrency));
        $customerCurrency = $this->normalizeCurrency((string) ($input['reissue_customer_currency'] ?? $invoiceCurrency));
        $receivedCurrency = $this->normalizeCurrency((string) ($input['reissue_received_currency'] ?? $customerCurrency));
        $supplierToCustomerRate = $this->reissueExchangeRate($supplierCurrency, $customerCurrency, $rateEffectiveDate, $supplierCostDifferenceAmount);
        $agencyToCustomerRate = $this->reissueExchangeRate($agencyFeeCurrency, $customerCurrency, $rateEffectiveDate, $serviceFeeAmount);
        $supplierCustomerComponent = $this->moneyValue($supplierCostDifferenceAmount * $supplierToCustomerRate);
        $agencyCustomerComponent = $this->moneyValue($serviceFeeAmount * $agencyToCustomerRate);
        $customerDelta = $this->moneyValue($supplierCustomerComponent + $agencyCustomerComponent);
        $submittedCustomerAmount = $this->moneyValue($input['reissue_customer_amount'] ?? $customerDelta);
        if (abs($submittedCustomerAmount - $customerDelta) > 0.01) {
            throw new RuntimeException('The reissue customer amount changed after exchange conversion. Review the currencies and confirm the rates again.');
        }
        $supplierMirrorIncrement = $this->moneyValue($supplierCostDifferenceAmount * $this->reissueExchangeRate($supplierCurrency, $costCurrency, $rateEffectiveDate, $supplierCostDifferenceAmount));
        $agencyMirrorIncrement = $this->moneyValue($serviceFeeAmount * $this->reissueExchangeRate($agencyFeeCurrency, $serviceChargeCurrency, $rateEffectiveDate, $serviceFeeAmount));
        $customerMirrorIncrement = $this->moneyValue($customerDelta * $this->reissueExchangeRate($customerCurrency, $invoiceCurrency, $rateEffectiveDate, $customerDelta));

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $bookingReference = (string) $booking['booking_reference'];
            $lineReference = (string) $existingService['line_reference'];
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
                'currency' => $customerCurrency,
                'original_ticket_number' => $existingService['ticket_number'] ?? null,
                'new_ticket_number' => $newTicketNumber,
                'original_pnr' => $existingService['pnr'] ?? null,
                'new_pnr' => $newPnr,
                'fare_difference_amount' => $supplierCustomerComponent,
                'service_fee_amount' => $agencyCustomerComponent,
                'reason' => $reason,
                'notes' => $this->optionalText($input['reissue_notes'] ?? null, 4000),
                'payload_json' => [
                    'customer_delta' => $customerDelta,
                    'supplier_delta' => $supplierDelta,
                    'supplier_cost_difference_amount' => $supplierCostDifferenceAmount,
                    'supplier_currency' => $supplierCurrency,
                    'agency_service_fee_amount' => $serviceFeeAmount,
                    'agency_service_fee_currency' => $agencyFeeCurrency,
                    'customer_currency' => $customerCurrency,
                    'received_currency' => $receivedCurrency,
                    'rate_effective_date' => $rateEffectiveDate,
                    'supplier_to_customer_rate' => $supplierToCustomerRate,
                    'agency_to_customer_rate' => $agencyToCustomerRate,
                    'supplier_mirror_increment' => $supplierMirrorIncrement,
                    'agency_mirror_increment' => $agencyMirrorIncrement,
                    'customer_mirror_increment' => $customerMirrorIncrement,
                    'customer_uses_separate_receivable' => $customerCurrency !== $invoiceCurrency,
                    'supplier_uses_separate_obligation' => $supplierCurrency !== $costCurrency,
                    'pricing_mode' => $simplifiedPricing ? 'supplier_plus_service' : 'legacy_customer_extra',
                    'previous_invoice_amount' => round((float) ($existingService['final_sale_price'] ?? 0), 2),
                    'revised_invoice_amount' => round((float) ($existingService['final_sale_price'] ?? 0) + $customerMirrorIncrement, 2),
                    'previous_supplier_cost' => round((float) ($existingService['purchase_cost'] ?? 0), 2),
                    'revised_supplier_cost' => round((float) ($existingService['purchase_cost'] ?? 0) + $supplierMirrorIncrement, 2),
                ],
                'actor_user_id' => $actorUserId,
            ]);

            $journalIds = [];
            $customerDueGroup = $customerCurrency === $invoiceCurrency ? 'service_sale' : 'reissue_customer_' . $eventId;
            $supplierObligationGroup = $supplierCurrency === $costCurrency ? 'service_cost' : 'reissue_supplier_' . $eventId;
            if ($customerDelta > 0) {
                $receivable = $customerRepository->syncReceivableItem([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference,
                    'due_group' => $customerDueGroup,
                    'currency' => $customerCurrency,
                    'due_amount' => $customerCurrency === $invoiceCurrency
                        ? round((float) ($existingService['final_sale_price'] ?? 0) + $customerMirrorIncrement, 2)
                        : $customerDelta,
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
                        'currency' => $customerCurrency,
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
                    'obligation_group' => $supplierObligationGroup,
                    'currency' => $supplierCurrency,
                    'gross_amount' => $supplierCurrency === $costCurrency
                        ? round((float) ($existingService['purchase_cost'] ?? 0) + $supplierMirrorIncrement, 2)
                        : $supplierDelta,
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
                        'currency' => $supplierCurrency,
                        'narration' => 'Reissue supplier difference for ' . $lineReference,
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            if ($journalIds !== []) {
                $eventRepository->attachJournalEntry($eventId, (int) $journalIds[0]);
            }

            $serviceRepository->updateAirTicketReissueDetails(
                $serviceId,
                $newTicketNumber,
                $newPnr,
                $actorUserId,
                $supplierMirrorIncrement,
                $agencyMirrorIncrement,
                $customerMirrorIncrement
            );

            $receiptResult = ['receipt' => null, 'customer_credit_applied' => []];
            if ($receivedAmount > 0.005 || $customerCreditApplyAmount > 0.005 || $paymentDueDate !== null) {
                $currentReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference, $customerDueGroup);
                if ($currentReceivable === null) {
                    throw new RuntimeException('The revised customer invoice could not be loaded for payment allocation.');
                }

                $receiptService = new CustomerReceiptWorkspaceService($this->app);
                $baseReceiptInput = [
                    'booking_id' => $bookingId,
                    'receipt_date' => $eventDate,
                    'due_date' => $paymentDueDate,
                    'payment_method' => (string) ($input['reissue_payment_method'] ?? 'cash'),
                    'treasury_account_id' => (int) ($input['reissue_treasury_account_id'] ?? 0),
                    'reference_number' => $this->optionalText($input['reissue_payment_reference'] ?? null, 190),
                    'charges_amount' => 0,
                    'receipt_status' => 'received',
                    'receipt_remarks' => 'Payment recorded with reissue of ' . $lineReference,
                    'receipt_scope' => 'passenger_specific',
                    'target_receivable_item_id' => (int) $currentReceivable['id'],
                ];

                if ($customerCreditApplyAmount > 0.005 || ($paymentDueDate !== null && $receivedAmount <= 0.005)) {
                    $creditResult = $receiptService->saveReceipt(array_merge($baseReceiptInput, [
                        'receipt_action' => 'no_receipt',
                        'receipt_currency' => $customerCurrency,
                        'received_amount' => 0,
                        'customer_credit_receipt_id' => $customerCreditReceiptId,
                        'customer_credit_apply_amount' => $customerCreditApplyAmount,
                    ]), $actorUserId, $accessibleBranchIds);
                    $receiptResult['customer_credit_applied'] = $creditResult['customer_credit_applied'] ?? [];
                    $currentReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference, $customerDueGroup) ?? $currentReceivable;
                }

                if ($receivedAmount > 0.005) {
                    $cashInput = array_merge($baseReceiptInput, [
                        'receipt_action' => 'save',
                        'receipt_currency' => $receivedCurrency,
                        'received_amount' => $receivedAmount,
                    ]);
                    if ($receivedCurrency !== $customerCurrency) {
                        $receiveToCustomerRate = $this->reissueExchangeRate($receivedCurrency, $customerCurrency, $rateEffectiveDate, $receivedAmount);
                        $customerToReceiveRate = $this->reissueExchangeRate($customerCurrency, $receivedCurrency, $rateEffectiveDate, $receivedAmount);
                        $outstanding = round((float) ($currentReceivable['outstanding_amount'] ?? 0), 2);
                        $targetAmount = $this->moneyValue(min($outstanding, $receivedAmount * $receiveToCustomerRate));
                        $paymentConsumed = $targetAmount >= $outstanding - 0.005
                            ? $this->moneyValue(min($receivedAmount, $targetAmount * $customerToReceiveRate))
                            : $receivedAmount;
                        $cashInput = array_merge($cashInput, [
                            'settlement_mode' => 'exchange',
                            'settlement_target_receivable_id' => (int) $currentReceivable['id'],
                            'settlement_target_currency' => $customerCurrency,
                            'settlement_target_receivable_amount' => $targetAmount,
                            'settlement_target_payment_amount' => $paymentConsumed,
                            'settlement_rate_from_currency' => $customerCurrency,
                            'settlement_rate_to_currency' => $receivedCurrency,
                            'settlement_exchange_rate' => $customerToReceiveRate,
                            'settlement_exchange_rate_effective_date' => $rateEffectiveDate,
                        ]);
                    }
                    $cashResult = $receiptService->saveReceipt($cashInput, $actorUserId, $accessibleBranchIds);
                    $receiptResult['receipt'] = $cashResult['receipt'] ?? null;
                }
            }

            $revisedReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference, $customerDueGroup);
            $revisedObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference, $supplierObligationGroup);
            $eventRepository->mergePayload($eventId, [
                'cash_received' => round((float) ($receiptResult['receipt']['received_amount'] ?? 0), 2),
                'cash_received_currency' => $receivedCurrency,
                'receipt_id' => (int) ($receiptResult['receipt']['id'] ?? 0),
                'receipt_no' => (string) ($receiptResult['receipt']['receipt_no'] ?? ''),
                'customer_credit_applied' => round((float) ($receiptResult['customer_credit_applied']['allocated_amount'] ?? 0), 2),
                'customer_paid_total' => round((float) ($revisedReceivable['allocated_amount'] ?? 0), 2),
                'customer_outstanding' => round((float) ($revisedReceivable['outstanding_amount'] ?? 0), 2),
                'supplier_paid_total' => round(max(0.0, (float) ($revisedObligation['gross_amount'] ?? 0) - (float) ($revisedObligation['net_payable_amount'] ?? 0)), 2),
                'supplier_outstanding' => round((float) ($revisedObligation['net_payable_amount'] ?? 0), 2),
            ]);

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
                'receipt_id' => (int) ($receiptResult['receipt']['id'] ?? 0),
                'cash_received' => round((float) ($receiptResult['receipt']['received_amount'] ?? 0), 2),
                'customer_credit_applied' => round((float) ($receiptResult['customer_credit_applied']['allocated_amount'] ?? 0), 2),
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

        return [
            'booking_id' => $bookingId,
            'service_event_id' => $eventId,
            'receipt' => $receiptResult['receipt'] ?? null,
            'customer_credit_applied' => $receiptResult['customer_credit_applied'] ?? [],
            'receivable' => $revisedReceivable ?? null,
            'obligation' => $revisedObligation ?? null,
        ];
    }

    private function validatedPayload(
        array $input,
        array $accessibleBranchIds,
        int $defaultBranchId,
        int $bookingId,
        int $actorUserId,
        BookingServiceRepository $repository,
        ?array $existingService = null
    ): array
    {
        $serviceType = $this->normalizeServiceType((string) ($input['service_type'] ?? 'air ticket'));
        $existingCurrency = $this->normalizeOptionalPostedCurrency($existingService['currency'] ?? null);
        $existingCostCurrency = $this->normalizeOptionalPostedCurrency($existingService['cost_currency'] ?? null);
        $existingServiceChargeCurrency = $this->normalizeOptionalPostedCurrency($existingService['service_charge_currency'] ?? null);

        $currency = $this->resolvePostedCurrency(
            $input,
            'currency',
            $existingCurrency ?? 'PKR'
        );
        $costCurrency = $this->resolvePostedCurrency(
            $input,
            'cost_currency',
            $existingCostCurrency ?? $currency
        );
        $serviceChargeCurrency = $this->resolvePostedCurrency(
            $input,
            'service_charge_currency',
            $existingServiceChargeCurrency ?? ($serviceType === 'air ticket' ? $costCurrency : $currency)
        );
        $status = $this->normalizeStatus((string) ($input['service_status'] ?? 'Open'));
        if ($existingService !== null
            && (new BookingServiceEventRepository($this->app))->hasPostedCancellationSettlement((int) $existingService['id'])) {
            // Check before supplier/passenger resolution can create supporting records.
            $selectedSupplier = (new SupplierRepository($this->app))->findAccessibleSupplierByName(
                trim((string) ($input['supplier_name'] ?? '')),
                $accessibleBranchIds
            );
            $this->assertSettledCancellationSaveIsOperational($existingService, array_merge($existingService, $input, [
                'supplier_id' => (int) ($selectedSupplier['id'] ?? 0),
                'service_type' => $serviceType,
                'service_status' => $status,
                'currency' => $currency,
                'cost_currency' => $costCurrency,
                'service_charge_currency' => $serviceChargeCurrency,
            ]));
        }
        $supplier = $this->resolveSupplier(
            (string) ($input['supplier_name'] ?? ''),
            $accessibleBranchIds,
            $defaultBranchId,
            $costCurrency,
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
            'cost_currency' => $costCurrency,
            'service_charge_currency' => $serviceChargeCurrency,
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
            'service_charge' => round($this->moneyValue($input['service_charge'] ?? 0), 0),
            'discount_amount' => $this->moneyValue($input['discount_amount'] ?? 0),
            'due_date' => $this->normalizeOptionalDate((string) ($input['due_date'] ?? '')),
            'service_status' => $status,
            'remarks' => $this->optionalText($input['remarks'] ?? null, 4000),
        ];

        if ($serviceType === 'air ticket') {
            $master['purchase_cost'] = $this->airTicketPayableAmount($master);
        } else {
            $master['purchase_cost'] = $master['sale_price'];
        }

        $pricingChanged = $existingService === null || $this->hasPricingChange($input, $existingService);
        $master['pricing_rate_effective_date'] = $this->resolvePricingRateEffectiveDate(
            $input,
            $master['due_date'],
            $pricingChanged ? null : $this->normalizeOptionalDate((string) ($existingService['pricing_rate_effective_date'] ?? ''))
        );
        $master['pricing_exchange_rate'] = $this->resolvePricingExchangeRate($master, $input, $existingService, $pricingChanged);
        $master['service_charge_rate_effective_date'] = $this->resolveServiceChargeRateEffectiveDate(
            $input,
            $master['pricing_rate_effective_date'],
            $pricingChanged ? null : $this->normalizeOptionalDate((string) ($existingService['service_charge_rate_effective_date'] ?? ''))
        );
        $master['service_charge_exchange_rate'] = $this->resolveServiceChargeExchangeRate($master, $input, $existingService, $pricingChanged);
        $this->assertCrossCurrencyPricingRate(
            $costCurrency,
            $currency,
            (float) $master['purchase_cost'],
            (float) $master['pricing_exchange_rate'],
            'supplier cost'
        );
        $this->assertCrossCurrencyPricingRate(
            $serviceChargeCurrency,
            $currency,
            (float) $master['service_charge'] + (float) $master['vat'] - (float) $master['discount_amount'],
            (float) $master['service_charge_exchange_rate'],
            'service amount'
        );
        $master['final_sale_price'] = $this->finalSalePriceAmount($master, $input);
        $master['net_profit_loss'] = $this->profitAmount($master);

        $profit = $master['net_profit_loss'];
        $lossReason = $this->optionalText($input['loss_reason'] ?? null, 4000);
        if ($profit < 0 && $lossReason === null) {
            if ($existingService === null || $this->hasPricingChange($input, $existingService)) {
                throw new RuntimeException('Reason for loss is required when a service line is sold below cost.');
            }

            // Supplier and other operational corrections must not be blocked by a
            // loss that was already accepted when the service price was saved.
            $lossReason = $this->optionalText($existingService['loss_reason'] ?? null, 4000);
        }
        if ($profit >= 0) {
            $lossReason = null;
        }

        $existingLossReason = $this->optionalText($existingService['loss_reason'] ?? null, 4000);
        $lossReasonRecordedAt = $lossReason !== null
            ? (
                $existingService !== null && $lossReason === $existingLossReason
                    ? ($existingService['loss_reason_recorded_at'] ?? date('Y-m-d H:i:s'))
                    : date('Y-m-d H:i:s')
            )
            : null;

        return [
            'master' => [
                'branch_id' => $master['branch_id'],
                'service_type' => $master['service_type'],
                'supplier_id' => $master['supplier_id'],
                'supplier_name_snapshot' => $master['supplier_name_snapshot'],
                'traveler_id' => $master['traveler_id'],
                'passenger_name_snapshot' => $master['passenger_name_snapshot'],
                'currency' => $master['currency'],
                'cost_currency' => $master['cost_currency'],
                'service_charge_currency' => $master['service_charge_currency'],
                'sale_price' => $master['sale_price'],
                'purchase_cost' => $master['purchase_cost'],
                'pricing_exchange_rate' => $master['pricing_exchange_rate'],
                'pricing_rate_effective_date' => $master['pricing_rate_effective_date'],
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
                'service_charge_exchange_rate' => $master['service_charge_exchange_rate'],
                'service_charge_rate_effective_date' => $master['service_charge_rate_effective_date'],
                'discount_amount' => $master['discount_amount'],
                'final_sale_price' => $master['final_sale_price'],
                'net_profit_loss' => $master['net_profit_loss'],
                'due_date' => $master['due_date'],
                'service_status' => $master['service_status'],
                'remarks' => $master['remarks'],
                'loss_reason' => $lossReason,
                'loss_reason_recorded_at' => $lossReasonRecordedAt,
            ],
            'subtype' => $this->validatedSubtypePayload($input, $serviceType, $master),
        ];
    }

    private function hasPricingChange(array $input, array $existingService): bool
    {
        $currencyFields = ['currency', 'cost_currency', 'service_charge_currency'];
        foreach ($currencyFields as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $posted = strtoupper(trim((string) $input[$field]));
            $existing = strtoupper(trim((string) ($existingService[$field] ?? '')));
            if ($posted !== $existing) {
                return true;
            }
        }

        $moneyFields = [
            'sale_price',
            'purchase_cost',
            'taxes',
            'other_fare',
            'soto_fare',
            'spyi_amount',
            'aq_yr_pk_amount',
            'yq_amount',
            'oth_amount',
            'vat_input',
            'vat',
            'commission',
            'service_charge',
            'discount_amount',
            'final_sale_price',
        ];
        foreach ($moneyFields as $field) {
            if (! array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
                continue;
            }

            $posted = $this->moneyValue($input[$field]);
            $existing = round((float) ($existingService[$field] ?? 0), 2);
            if (abs($posted - $existing) > 0.005) {
                return true;
            }
        }

        $rateFields = ['pricing_exchange_rate', 'service_charge_exchange_rate'];
        foreach ($rateFields as $field) {
            if (! array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
                continue;
            }

            $posted = (float) $input[$field];
            $existing = (float) ($existingService[$field] ?? 1);
            if (abs($posted - $existing) > 0.00000001) {
                return true;
            }
        }

        $dateFields = ['pricing_rate_effective_date', 'service_charge_rate_effective_date'];
        foreach ($dateFields as $field) {
            if (! array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
                continue;
            }

            $posted = trim((string) $input[$field]);
            $existing = trim((string) ($existingService[$field] ?? ''));
            if ($posted !== $existing) {
                return true;
            }
        }

        return false;
    }

    private function assertSettledCancellationSaveIsOperational(array $existingService, array $master): void
    {
        if (! (new BookingServiceEventRepository($this->app))->hasPostedCancellationSettlement((int) $existingService['id'])) {
            return;
        }

        $before = $this->serviceFinancialSnapshot($existingService);
        $after = $this->serviceFinancialSnapshot($master);
        foreach ($before as $field => $value) {
            // A default rate date on an unchanged save does not change settlement economics.
            if (in_array($field, ['pricing_rate_effective_date', 'service_charge_rate_effective_date'], true)) {
                continue;
            }
            if ($value !== $after[$field]) {
                throw new RuntimeException('This service has a posted cancellation settlement. Use the cancellation/refund correction workflow to change financial values.');
            }
        }

        foreach (['supplier_id', 'service_status', 'service_type', 'is_active'] as $field) {
            if ((string) ($existingService[$field] ?? '') !== (string) ($master[$field] ?? $existingService[$field] ?? '')) {
                throw new RuntimeException('Supplier and service status are protected after cancellation settlement. Use the controlled cancellation/refund workflow.');
            }
        }
    }

    private function assertNoProtectedFinancialChangeOnSavedService(array $input, array $existingService): void
    {
        if (! $this->serviceHasProtectedFinancialActivity($existingService)) {
            return;
        }

        $watchedFields = [
            'sale_price' => round((float) ($existingService['sale_price'] ?? 0), 2),
            'taxes' => round((float) ($existingService['taxes'] ?? 0), 2),
            'other_fare' => round((float) ($existingService['other_fare'] ?? 0), 2),
            'soto_fare' => round((float) ($existingService['soto_fare'] ?? 0), 2),
            'spyi_amount' => round((float) ($existingService['spyi_amount'] ?? 0), 2),
            'aq_yr_pk_amount' => round((float) ($existingService['aq_yr_pk_amount'] ?? 0), 2),
            'yq_amount' => round((float) ($existingService['yq_amount'] ?? 0), 2),
            'oth_amount' => round((float) ($existingService['oth_amount'] ?? 0), 2),
            'vat_input' => round((float) ($existingService['vat_input'] ?? 0), 2),
            'commission' => round((float) ($existingService['commission'] ?? 0), 2),
            'service_charge' => round((float) ($existingService['service_charge'] ?? 0), 2),
            'discount_amount' => round((float) ($existingService['discount_amount'] ?? 0), 2),
            'vat' => round((float) ($existingService['vat'] ?? 0), 2),
            'final_sale_price' => round((float) ($existingService['final_sale_price'] ?? 0), 2),
        ];

        foreach ($watchedFields as $field => $currentValue) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $postedValue = round(is_numeric($input[$field]) ? (float) $input[$field] : 0.0, 2);
            if (abs($postedValue - $currentValue) > 0.005) {
                app_write_log('workspace.service.protected_financial_change_blocked', 'Saved service financial values must be changed through Quick Financial Edit with a reason.', array_merge(app_request_log_context(), [
                    'booking_id' => (int) ($existingService['booking_id'] ?? 0),
                    'service_id' => (int) ($existingService['id'] ?? 0),
                    'service_type' => (string) ($existingService['service_type'] ?? ''),
                    'field' => $field,
                    'posted_value' => $postedValue,
                    'current_value' => $currentValue,
                ]));
                throw new RuntimeException('Saved service financial values must be changed through Quick Financial Edit with a reason.');
            }
        }

        foreach (['currency', 'cost_currency', 'service_charge_currency'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $postedValue = $this->normalizeCurrency((string) ($input[$field] ?? 'PKR'));
            $currentValue = $this->normalizeCurrency((string) ($existingService[$field] ?? $existingService['currency'] ?? 'PKR'));
            if ($postedValue !== $currentValue) {
                app_write_log('workspace.service.protected_financial_change_blocked', 'Saved service financial values must be changed through Quick Financial Edit with a reason.', array_merge(app_request_log_context(), [
                    'booking_id' => (int) ($existingService['booking_id'] ?? 0),
                    'service_id' => (int) ($existingService['id'] ?? 0),
                    'service_type' => (string) ($existingService['service_type'] ?? ''),
                    'field' => $field,
                    'posted_value' => $postedValue,
                    'current_value' => $currentValue,
                ]));
                throw new RuntimeException('Saved service financial values must be changed through Quick Financial Edit with a reason.');
            }
        }
    }

    /**
     * Move the commercial payable to a corrected supplier without rewriting
     * historical cash. Any amount already settled with the prior supplier is
     * released back to that supplier's reusable credit before ownership moves.
     * The caller owns the surrounding database transaction.
     */
    private function prepareSupplierCorrection(
        array $existingService,
        array $newMasterData,
        array $booking,
        int $actorUserId
    ): array {
        $priorSupplierId = (int) ($existingService['supplier_id'] ?? 0);
        $newSupplierId = (int) ($newMasterData['supplier_id'] ?? 0);
        $priorSupplierName = trim((string) ($existingService['supplier_name_snapshot'] ?? $existingService['supplier_name'] ?? ''));
        $newSupplierName = trim((string) ($newMasterData['supplier_name_snapshot'] ?? ''));

        $result = [
            'changed' => $priorSupplierId !== $newSupplierId,
            'prior_supplier_id' => $priorSupplierId > 0 ? $priorSupplierId : null,
            'prior_supplier_name' => $priorSupplierName !== '' ? $priorSupplierName : null,
            'new_supplier_id' => $newSupplierId > 0 ? $newSupplierId : null,
            'new_supplier_name' => $newSupplierName !== '' ? $newSupplierName : null,
            'released_settlement_amount' => 0.0,
            'released_payment_amount' => 0.0,
            'released_advance_amount' => 0.0,
        ];

        if (! $result['changed']) {
            return $result;
        }

        if ($priorSupplierId > 0 && $newSupplierId <= 0) {
            throw new RuntimeException('Select the replacement supplier before saving this existing invoice.');
        }

        $bookingReference = trim((string) ($booking['booking_reference'] ?? ''));
        $lineReference = trim((string) ($existingService['line_reference'] ?? ''));
        if ($bookingReference === '' || $lineReference === '') {
            throw new RuntimeException('The saved service could not be identified for supplier correction.');
        }

        $supplierRepository = new SupplierRepository($this->app);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
        if ($obligation === null) {
            return $result;
        }

        $obligationSupplierId = (int) ($obligation['supplier_id'] ?? 0);
        if ($obligationSupplierId !== $priorSupplierId && $obligationSupplierId !== $newSupplierId) {
            throw new RuntimeException('The saved supplier payable is inconsistent. No data was changed; run the financial audit before correcting this supplier.');
        }

        // A previous interrupted correction may already have moved the payable.
        if ($obligationSupplierId === $newSupplierId) {
            return $result;
        }

        $grossAmount = round((float) ($obligation['gross_amount'] ?? 0), 2);
        $netPayableAmount = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
        $settledAmount = round(max($grossAmount - $netPayableAmount, 0), 2);
        if ($settledAmount <= 0.005) {
            return $result;
        }

        $release = $supplierRepository->releaseSettledCreditForObligation(
            (int) $obligation['id'],
            0.0,
            $actorUserId,
            sprintf(
                'Supplier corrected from %s to %s for %s',
                $priorSupplierName !== '' ? $priorSupplierName : ('supplier #' . $priorSupplierId),
                $newSupplierName !== '' ? $newSupplierName : ('supplier #' . $newSupplierId),
                $lineReference
            )
        );

        $releasedAmount = round((float) ($release['released_amount'] ?? 0), 2);
        if ($releasedAmount > 0.005) {
            (new AccountingRepository($this->app))->postSupplierSettlementRelease([
                'branch_id' => (int) ($existingService['branch_id'] ?? $booking['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'source_reference' => sprintf(
                    '%s-SUP-%d-%d-%s',
                    $lineReference,
                    $priorSupplierId,
                    $newSupplierId,
                    date('YmdHis')
                ),
                'service_line_reference' => $lineReference,
                'supplier_obligation_id' => (int) $obligation['id'],
                'released_amount' => $releasedAmount,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) ($obligation['currency'] ?? $existingService['cost_currency'] ?? $existingService['currency'] ?? 'PKR'),
                'narration' => sprintf(
                    'Supplier correction: prior settlement retained as credit with %s; payable moved to %s',
                    $priorSupplierName !== '' ? $priorSupplierName : ('supplier #' . $priorSupplierId),
                    $newSupplierName !== '' ? $newSupplierName : ('supplier #' . $newSupplierId)
                ),
                'actor_user_id' => $actorUserId,
            ]);
        }

        $result['released_settlement_amount'] = $releasedAmount;
        $result['released_payment_amount'] = round((float) ($release['released_payment_amount'] ?? 0), 2);
        $result['released_advance_amount'] = round((float) ($release['released_advance_amount'] ?? 0), 2);
        $result['affected_payment_ids'] = array_values(array_map('intval', (array) ($release['affected_payment_ids'] ?? [])));
        $result['affected_advance_ids'] = array_values(array_map('intval', (array) ($release['affected_advance_ids'] ?? [])));

        return $result;
    }

    private function serviceHasProtectedFinancialActivity(array $existingService): bool
    {
        $bookingId = (int) ($existingService['booking_id'] ?? 0);
        $lineReference = trim((string) ($existingService['line_reference'] ?? ''));
        if ($bookingId <= 0 || $lineReference === '') {
            return true;
        }

        $booking = (new BookingRepository($this->app))->findBookingById($bookingId);
        $bookingReference = trim((string) ($booking['booking_reference'] ?? ''));
        if ($bookingReference === '') {
            return true;
        }

        $receivable = (new CustomerPaymentRepository($this->app))->findReceivableByServiceLine($bookingReference, $lineReference);
        if ($receivable !== null && round((float) ($receivable['allocated_amount'] ?? 0), 2) > 0.005) {
            return true;
        }

        $obligation = (new SupplierRepository($this->app))->findObligationByServiceLine($bookingReference, $lineReference);
        if ($obligation !== null) {
            $advanceApplied = round((float) ($obligation['advance_applied_amount'] ?? 0), 2);
            $grossAmount = round((float) ($obligation['gross_amount'] ?? 0), 2);
            $netPayableAmount = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
            $settledAmount = round(max($grossAmount - $netPayableAmount - $advanceApplied, 0), 2);

            if ($advanceApplied > 0.005 || $settledAmount > 0.005) {
                return true;
            }
        }

        return false;
    }

    private function serviceFinancialSnapshot(array $service): array
    {
        return [
            'sale_price' => round((float) ($service['sale_price'] ?? 0), 2),
            'purchase_cost' => round((float) ($service['purchase_cost'] ?? 0), 2),
            'currency' => (string) ($service['currency'] ?? 'PKR'),
            'cost_currency' => (string) ($service['cost_currency'] ?? $service['currency'] ?? 'PKR'),
            'pricing_exchange_rate' => $this->normalizePricingExchangeRate((float) ($service['pricing_exchange_rate'] ?? 1)),
            'pricing_rate_effective_date' => (string) ($service['pricing_rate_effective_date'] ?? ''),
            'service_charge' => round((float) ($service['service_charge'] ?? 0), 2),
            'service_charge_currency' => (string) ($service['service_charge_currency'] ?? $service['currency'] ?? 'PKR'),
            'service_charge_exchange_rate' => $this->normalizePricingExchangeRate((float) ($service['service_charge_exchange_rate'] ?? 1)),
            'service_charge_rate_effective_date' => (string) ($service['service_charge_rate_effective_date'] ?? ''),
            'discount_amount' => round((float) ($service['discount_amount'] ?? 0), 2),
            'vat' => round((float) ($service['vat'] ?? 0), 2),
            'final_sale_price' => round((float) ($service['final_sale_price'] ?? 0), 2),
        ];
    }

    private function validatedFinancialCorrectionPayload(array $input, array $existingService, array $currentSnapshot): array
    {
        $reason = $this->requiredText(
            $input['financial_correction_reason'] ?? null,
            1000,
            'Correction reason is required.'
        );
        $note = $this->optionalText($input['financial_correction_note'] ?? null, 4000);
        $correctionDate = $this->normalizeOptionalDate((string) ($input['financial_correction_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $serviceType = (string) ($existingService['service_type'] ?? 'air ticket');

        $currentCostBasis = $serviceType === 'air ticket'
            ? round((float) ($existingService['sale_price'] ?? 0), 2)
            : round((float) ($existingService['purchase_cost'] ?? 0), 2);
        $newCostBasis = array_key_exists('corrected_cost_basis', $input) && trim((string) $input['corrected_cost_basis']) !== ''
            ? $this->moneyValue($input['corrected_cost_basis'])
            : $currentCostBasis;
        $hasOperationalCorrectionInputs = (array_key_exists('corrected_service_charge', $input) && trim((string) $input['corrected_service_charge']) !== '')
            || (array_key_exists('corrected_discount_amount', $input) && trim((string) $input['corrected_discount_amount']) !== '');

        $financial = [
            'sale_price' => $currentSnapshot['sale_price'],
            'purchase_cost' => $currentSnapshot['purchase_cost'],
            'currency' => array_key_exists('corrected_invoice_currency', $input)
                ? $this->normalizeCurrency((string) $input['corrected_invoice_currency'])
                : $currentSnapshot['currency'],
            'cost_currency' => array_key_exists('corrected_cost_currency', $input)
                ? $this->normalizeCurrency((string) $input['corrected_cost_currency'])
                : $currentSnapshot['cost_currency'],
            'pricing_exchange_rate' => $currentSnapshot['pricing_exchange_rate'],
            'pricing_rate_effective_date' => $currentSnapshot['pricing_rate_effective_date'] !== ''
                ? $currentSnapshot['pricing_rate_effective_date']
                : $correctionDate,
            'commission' => round((float) ($existingService['commission'] ?? 0), 2),
            'service_charge' => $currentSnapshot['service_charge'],
            'service_charge_currency' => array_key_exists('corrected_service_charge_currency', $input)
                ? $this->normalizeCurrency((string) $input['corrected_service_charge_currency'])
                : $currentSnapshot['service_charge_currency'],
            'service_charge_exchange_rate' => $currentSnapshot['service_charge_exchange_rate'],
            'service_charge_rate_effective_date' => $currentSnapshot['service_charge_rate_effective_date'] !== ''
                ? $currentSnapshot['service_charge_rate_effective_date']
                : $correctionDate,
            'discount_amount' => $currentSnapshot['discount_amount'],
            'vat' => $currentSnapshot['vat'],
            'final_sale_price' => $currentSnapshot['final_sale_price'],
        ];
        if (array_key_exists('corrected_pricing_exchange_rate', $input) && trim((string) $input['corrected_pricing_exchange_rate']) !== '') {
            $financial['pricing_exchange_rate'] = $this->normalizePricingExchangeRate((float) $input['corrected_pricing_exchange_rate']);
        }
        if ($financial['cost_currency'] !== $financial['currency']
            && array_key_exists('corrected_aed_to_pkr_exchange_rate', $input)
            && trim((string) $input['corrected_aed_to_pkr_exchange_rate']) !== '') {
            $displayRate = $this->normalizePricingExchangeRate((float) $input['corrected_aed_to_pkr_exchange_rate']);
            $financial['pricing_exchange_rate'] = $financial['cost_currency'] === 'PKR' && $financial['currency'] === 'AED'
                ? $this->normalizePricingExchangeRate(1 / $displayRate)
                : $displayRate;
        }
        if (array_key_exists('corrected_pricing_rate_effective_date', $input) && trim((string) $input['corrected_pricing_rate_effective_date']) !== '') {
            $financial['pricing_rate_effective_date'] = $this->normalizeOptionalDate((string) $input['corrected_pricing_rate_effective_date']) ?? $correctionDate;
        }
        if (array_key_exists('corrected_service_charge_exchange_rate', $input) && trim((string) $input['corrected_service_charge_exchange_rate']) !== '') {
            $financial['service_charge_exchange_rate'] = $this->normalizePricingExchangeRate((float) $input['corrected_service_charge_exchange_rate']);
        }
        if (array_key_exists('corrected_service_charge_rate_effective_date', $input) && trim((string) $input['corrected_service_charge_rate_effective_date']) !== '') {
            $financial['service_charge_rate_effective_date'] = $this->normalizeOptionalDate((string) $input['corrected_service_charge_rate_effective_date']) ?? $correctionDate;
        }

        if ($serviceType === 'air ticket') {
            $financial['sale_price'] = $newCostBasis;
            $financial['purchase_cost'] = round(
                $newCostBasis
                + (float) ($existingService['spyi_amount'] ?? 0)
                + (float) ($existingService['aq_yr_pk_amount'] ?? 0)
                + (float) ($existingService['yq_amount'] ?? 0)
                + (float) ($existingService['oth_amount'] ?? 0)
                + (float) ($existingService['vat_input'] ?? 0)
                + (float) ($existingService['taxes'] ?? 0),
                2
            );
        } else {
            // Non-air service entry uses sale_price as the legacy cost-input mirror.
            // Keep it synchronized so later corrections do not leave conflicting
            // service values while purchase_cost remains the accounting cost truth.
            $financial['sale_price'] = $newCostBasis;
            $financial['purchase_cost'] = $newCostBasis;
        }

        if ($hasOperationalCorrectionInputs) {
            if (array_key_exists('corrected_service_charge', $input) && trim((string) $input['corrected_service_charge']) !== '') {
                $financial['service_charge'] = $this->moneyValue($input['corrected_service_charge']);
            }
            if (array_key_exists('corrected_discount_amount', $input) && trim((string) $input['corrected_discount_amount']) !== '') {
                $financial['discount_amount'] = $this->moneyValue($input['corrected_discount_amount']);
            }
        }

        $financial['pricing_exchange_rate'] = $this->resolveCorrectionPricingExchangeRate($financial);
        $financial['service_charge_exchange_rate'] = $this->resolveCorrectionServiceChargeExchangeRate($financial);
        $this->assertCrossCurrencyPricingRate(
            $financial['cost_currency'],
            $financial['currency'],
            (float) $financial['purchase_cost'],
            (float) $financial['pricing_exchange_rate'],
            'supplier cost'
        );
        $customerBase = $this->customerPricingBaseAmount($financial);
        $hasExplicitCustomerTotal = array_key_exists('corrected_final_sale_price', $input)
            && trim((string) $input['corrected_final_sale_price']) !== '';

        if ($hasExplicitCustomerTotal) {
            // Customer Total is authoritative in the saved-invoice editor. This is
            // deliberately independent of supplier cost so an agency can correct a
            // previously saved sale to a genuine below-cost sale without changing
            // the supplier payable or fabricating a negative service charge.
            $financial['final_sale_price'] = $this->moneyValue($input['corrected_final_sale_price']);
            $invoiceCurrencyAgencyAmount = round($financial['final_sale_price'] - $customerBase, 2);
            $agencyRate = $financial['currency'] === $financial['service_charge_currency']
                ? 1.0
                : $financial['service_charge_exchange_rate'];
            $calculatedServiceCharge = round(
                ($invoiceCurrencyAgencyAmount / $agencyRate) - $financial['vat'] + $financial['discount_amount'],
                2
            );
            $financial['service_charge'] = max($calculatedServiceCharge, 0.0);
        } elseif ($hasOperationalCorrectionInputs) {
            $financial['final_sale_price'] = round($customerBase + $this->customerAgencyComponentAmount($financial), 2);
        } else {
            $newFinalSalePrice = $currentSnapshot['final_sale_price'];
            $financial['final_sale_price'] = $newFinalSalePrice;
            $invoiceCurrencyAgencyAmount = round($newFinalSalePrice - $customerBase, 2);
            $agencyRate = $financial['currency'] === $financial['service_charge_currency']
                ? 1.0
                : $financial['service_charge_exchange_rate'];
            $calculatedServiceCharge = round(
                ($invoiceCurrencyAgencyAmount / $agencyRate) - $financial['vat'] + $financial['discount_amount'],
                2
            );
            $financial['service_charge'] = $calculatedServiceCharge >= 0 ? $calculatedServiceCharge : 0.0;
        }

        $this->assertCrossCurrencyPricingRate(
            $financial['service_charge_currency'],
            $financial['currency'],
            (float) $financial['service_charge'] + (float) $financial['vat'] - (float) $financial['discount_amount'],
            (float) $financial['service_charge_exchange_rate'],
            'service amount'
        );
        $financial['net_profit_loss'] = round(
            $financial['final_sale_price'] - $this->customerPricingBaseAmount($financial),
            2
        );
        $financial['loss_reason'] = $financial['net_profit_loss'] < 0 ? $reason : null;
        $financial['loss_reason_recorded_at'] = $financial['loss_reason'] !== null ? date('Y-m-d H:i:s') : null;

        $hasChanges = abs($financial['sale_price'] - $currentSnapshot['sale_price']) > 0.005
            || abs($financial['purchase_cost'] - $currentSnapshot['purchase_cost']) > 0.005
            || abs($financial['service_charge'] - $currentSnapshot['service_charge']) > 0.005
            || abs($financial['discount_amount'] - $currentSnapshot['discount_amount']) > 0.005
            || abs($financial['final_sale_price'] - $currentSnapshot['final_sale_price']) > 0.005
            || $financial['currency'] !== $currentSnapshot['currency']
            || $financial['cost_currency'] !== $currentSnapshot['cost_currency']
            || $financial['service_charge_currency'] !== $currentSnapshot['service_charge_currency']
            || abs($financial['pricing_exchange_rate'] - $currentSnapshot['pricing_exchange_rate']) > 0.00000001
            || (string) $financial['pricing_rate_effective_date'] !== (string) $currentSnapshot['pricing_rate_effective_date']
            || abs($financial['service_charge_exchange_rate'] - $currentSnapshot['service_charge_exchange_rate']) > 0.00000001
            || (string) $financial['service_charge_rate_effective_date'] !== (string) $currentSnapshot['service_charge_rate_effective_date'];

        return [
            'reason' => $reason,
            'note' => $note,
            'correction_date' => $correctionDate,
            'financial' => $financial,
            'has_changes' => $hasChanges,
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

    private function resolvePricingRateEffectiveDate(array $input, ?string $fallbackDate = null, ?string $existingDate = null): string
    {
        $postedDate = $this->normalizeOptionalDate((string) ($input['pricing_rate_effective_date'] ?? ''));
        if ($postedDate !== null) {
            return $postedDate;
        }

        if ($existingDate !== null && trim($existingDate) !== '') {
            return $existingDate;
        }

        if ($fallbackDate !== null && trim($fallbackDate) !== '') {
            return (string) $fallbackDate;
        }

        return date('Y-m-d');
    }

    private function resolvePricingExchangeRate(array $master, array $input, ?array $existingService = null, bool $pricingChanged = true): float
    {
        $invoiceCurrency = (string) ($master['currency'] ?? 'PKR');
        $costCurrency = (string) ($master['cost_currency'] ?? $invoiceCurrency);
        if ($invoiceCurrency === $costCurrency) {
            return 1.0;
        }

        $purchaseCost = round((float) ($master['purchase_cost'] ?? 0), 2);
        if ($purchaseCost <= 0.0) {
            return 1.0;
        }

        $postedRate = (float) ($input['pricing_exchange_rate'] ?? 0);
        if ($postedRate > 0) {
            return $this->normalizePricingExchangeRate($postedRate);
        }

        if (! $pricingChanged && $existingService !== null) {
            $existingRate = (float) ($existingService['pricing_exchange_rate'] ?? 0);
            if ($existingRate > 0) {
                return $this->normalizePricingExchangeRate($existingRate);
            }
        }

        $effectiveDate = (string) ($master['pricing_rate_effective_date'] ?? date('Y-m-d'));
        $rate = (new ExchangeRateRepository($this->app))->getExactRate($costCurrency, $invoiceCurrency, $effectiveDate);
        if ($rate !== null && (float) ($rate['exchange_rate'] ?? 0) > 0) {
            return $this->normalizePricingExchangeRate((float) $rate['exchange_rate']);
        }

        throw new RuntimeException(sprintf(
            'Set today\'s %s to %s exchange rate or enter the invoice amount manually.',
            $costCurrency,
            $invoiceCurrency
        ));
    }

    private function resolveServiceChargeRateEffectiveDate(array $input, string $fallbackDate, ?string $existingDate = null): string
    {
        $postedDate = $this->normalizeOptionalDate((string) ($input['service_charge_rate_effective_date'] ?? ''));
        if ($postedDate !== null) {
            return $postedDate;
        }

        if ($existingDate !== null && trim($existingDate) !== '') {
            return $existingDate;
        }

        return $fallbackDate;
    }

    private function resolveServiceChargeExchangeRate(array $master, array $input, ?array $existingService = null, bool $pricingChanged = true): float
    {
        $invoiceCurrency = (string) ($master['currency'] ?? 'PKR');
        $serviceChargeCurrency = (string) ($master['service_charge_currency'] ?? $invoiceCurrency);
        if ($invoiceCurrency === $serviceChargeCurrency) {
            return 1.0;
        }

        $agencyComponentAmount = round(
            (float) ($master['service_charge'] ?? 0)
            + (float) ($master['vat'] ?? 0)
            - (float) ($master['discount_amount'] ?? 0),
            2
        );
        if (abs($agencyComponentAmount) <= 0.005) {
            return 1.0;
        }

        $postedRate = (float) ($input['service_charge_exchange_rate'] ?? 0);
        if ($postedRate > 0) {
            return $this->normalizePricingExchangeRate($postedRate);
        }

        if (! $pricingChanged && $existingService !== null) {
            $existingRate = (float) ($existingService['service_charge_exchange_rate'] ?? 0);
            if ($existingRate > 0) {
                return $this->normalizePricingExchangeRate($existingRate);
            }
        }

        $effectiveDate = (string) ($master['service_charge_rate_effective_date'] ?? date('Y-m-d'));
        $rate = (new ExchangeRateRepository($this->app))->getExactRate(
            $serviceChargeCurrency,
            $invoiceCurrency,
            $effectiveDate
        );
        if ($rate !== null && (float) ($rate['exchange_rate'] ?? 0) > 0) {
            return $this->normalizePricingExchangeRate((float) $rate['exchange_rate']);
        }

        throw new RuntimeException(sprintf(
            'Set today\'s %s to %s exchange rate for the agency service amount.',
            $serviceChargeCurrency,
            $invoiceCurrency
        ));
    }

    private function resolveCorrectionPricingExchangeRate(array $financial): float
    {
        $invoiceCurrency = (string) ($financial['currency'] ?? 'PKR');
        $costCurrency = (string) ($financial['cost_currency'] ?? $invoiceCurrency);
        if ($invoiceCurrency === $costCurrency) {
            return 1.0;
        }

        $purchaseCost = round((float) ($financial['purchase_cost'] ?? 0), 2);
        if ($purchaseCost <= 0) {
            return 1.0;
        }

        return $this->normalizePricingExchangeRate((float) ($financial['pricing_exchange_rate'] ?? 1));
    }

    private function resolveCorrectionServiceChargeExchangeRate(array $financial): float
    {
        $invoiceCurrency = (string) ($financial['currency'] ?? 'PKR');
        $serviceChargeCurrency = (string) ($financial['service_charge_currency'] ?? $invoiceCurrency);
        if ($invoiceCurrency === $serviceChargeCurrency) {
            return 1.0;
        }

        $agencyComponentAmount = round(
            (float) ($financial['service_charge'] ?? 0)
            + (float) ($financial['vat'] ?? 0)
            - (float) ($financial['discount_amount'] ?? 0),
            2
        );
        if (abs($agencyComponentAmount) <= 0.005) {
            return 1.0;
        }

        return $this->normalizePricingExchangeRate((float) ($financial['service_charge_exchange_rate'] ?? 0));
    }

    private function normalizePricingExchangeRate(float $rate): float
    {
        if ($rate <= 0) {
            throw new RuntimeException('Pricing exchange rate must be greater than zero.');
        }

        return round($rate, 8);
    }

    private function assertCrossCurrencyPricingRate(
        string $sourceCurrency,
        string $invoiceCurrency,
        float $amount,
        float $rate,
        string $label
    ): void {
        if ($sourceCurrency === $invoiceCurrency || abs($amount) <= 0.005) {
            return;
        }

        if (! is_finite($rate) || $rate <= 0 || abs($rate - 1.0) <= 0.00000001) {
            throw new RuntimeException(sprintf(
                'Confirm the %s exchange rate from %s to %s. A 1:1 rate cannot be used for different currencies.',
                $label,
                $sourceCurrency,
                $invoiceCurrency
            ));
        }
    }

    private function reissueExchangeRate(string $fromCurrency, string $toCurrency, string $effectiveDate, float $amount): float
    {
        if ($amount <= 0.005 || $fromCurrency === $toCurrency) {
            return 1.0;
        }

        $rate = (new ExchangeRateRepository($this->app))->getExactRate($fromCurrency, $toCurrency, $effectiveDate);
        $value = round((float) ($rate['exchange_rate'] ?? 0), 8);
        if ($value <= 0) {
            throw new RuntimeException(sprintf('FX_RATE_REQUIRED: Confirm today\'s %s to %s exchange rate before saving this reissue.', $fromCurrency, $toCurrency));
        }

        return $value;
    }

    private function customerPricingBaseAmount(array $master): float
    {
        $invoiceCurrency = (string) ($master['currency'] ?? 'PKR');
        $costCurrency = (string) ($master['cost_currency'] ?? $invoiceCurrency);
        $purchaseCost = round((float) ($master['purchase_cost'] ?? 0), 2);
        if ($invoiceCurrency === $costCurrency) {
            return $purchaseCost;
        }

        $pricingExchangeRate = $this->normalizePricingExchangeRate((float) ($master['pricing_exchange_rate'] ?? 1));

        return round($purchaseCost * $pricingExchangeRate, 0);
    }

    private function customerAgencyComponentAmount(array $master): float
    {
        $invoiceCurrency = (string) ($master['currency'] ?? 'PKR');
        $serviceChargeCurrency = (string) ($master['service_charge_currency'] ?? $invoiceCurrency);
        $amount = round(
            (float) ($master['service_charge'] ?? 0)
            + (float) ($master['vat'] ?? 0)
            - (float) ($master['discount_amount'] ?? 0),
            2
        );
        if ($invoiceCurrency === $serviceChargeCurrency) {
            return $amount;
        }

        $rate = $this->normalizePricingExchangeRate((float) ($master['service_charge_exchange_rate'] ?? 1));

        return round($amount * $rate, 0);
    }

    private function finalSalePriceAmount(array $master, array $input): float
    {
        $rawFinalSalePrice = $input['final_sale_price'] ?? null;
        if ($rawFinalSalePrice !== null && trim((string) $rawFinalSalePrice) !== '') {
            return round($this->moneyValue($rawFinalSalePrice), 0);
        }

        return round(
            $this->customerPricingBaseAmount($master)
            + $this->customerAgencyComponentAmount($master),
            0
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
            - $this->customerPricingBaseAmount($master),
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
            throw new RuntimeException('Supplier is required for every invoice service.');
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
        $booking = (new BookingRepository($this->app))->findBookingById($bookingId);
        $leadTravelerId = (int) ($booking['lead_traveler_id'] ?? 0);

        if ($leadTravelerId > 0 && ! $travelerRepository->travelerAttachedToBooking($bookingId, $leadTravelerId)) {
            $travelerRepository->attachTravelerToBooking($bookingId, $leadTravelerId, 'lead', $actorUserId);
        }

        if ($travelerId > 0) {
            $traveler = $repository->travelerForBooking($bookingId, $travelerId);
            if ($traveler === null) {
                $existingTraveler = $travelerRepository->findTravelerById($travelerId);
                if ($existingTraveler === null) {
                    throw new RuntimeException('Please select a valid passenger for this invoice.');
                }

                $role = $leadTravelerId > 0 && $travelerId === $leadTravelerId ? 'lead' : 'additional';
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
        $serviceTypeMap = $this->activeServiceTypeMap();

        if (! array_key_exists($type, $serviceTypeMap)) {
            throw new RuntimeException('Please select a valid service type.');
        }

        return $serviceTypeMap[$type];
    }

    private function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid service currency.');
        }

        return $currency;
    }

    private function normalizeOptionalPostedCurrency(mixed $value): ?string
    {
        $currency = strtoupper(trim((string) $value));
        if ($currency === '') {
            return null;
        }

        return $this->normalizeCurrency($currency);
    }

    private function resolvePostedCurrency(array $input, string $field, string $fallback): string
    {
        if (! array_key_exists($field, $input)) {
            return $fallback;
        }

        $posted = $this->normalizeOptionalPostedCurrency($input[$field] ?? null);
        if ($posted === null) {
            return $fallback;
        }

        return $posted;
    }

    private function normalizePaymentMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, $this->activePaymentMethodCodes(), true)) {
            throw new RuntimeException('Please select a valid refund payment method.');
        }

        return $method;
    }

    private function normalizeSupplierRefundPaymentMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if ($method === 'supplier_credit') {
            return $method;
        }

        return $this->normalizePaymentMethod($method);
    }

    private function activeServiceTypeMap(): array
    {
        $rows = (new MasterDataRepository($this->app))->activeRows('service_types');
        $map = [];

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
            $runtimeKey = self::FALLBACK_SERVICE_TYPE_MAP[$code] ?? $this->normalizeServiceTypeName($name);
            if ($runtimeKey === '') {
                continue;
            }

            $map[$runtimeKey] = $runtimeKey;
            if ($code !== '') {
                $map[mb_strtolower($code)] = $runtimeKey;
            }
            if ($name !== '') {
                $map[$name] = $runtimeKey;
            }
        }

        if ($map === []) {
            foreach (self::FALLBACK_SERVICE_TYPE_MAP as $runtimeKey) {
                $map[$runtimeKey] = $runtimeKey;
            }
        }

        return $map;
    }

    private function activePaymentMethodCodes(): array
    {
        $codes = array_map(
            static fn (string $code): string => str_replace(' ', '_', mb_strtolower(trim($code))),
            array_keys((new MasterDataRepository($this->app))->activeCodeLabelMap('payment_methods'))
        );

        return $codes !== [] ? array_values(array_unique($codes)) : ['cash', 'bank_transfer', 'debit_card', 'credit_card'];
    }

    private function normalizeServiceTypeName(string $name): string
    {
        $normalized = str_replace(['-', '_'], ' ', $name);
        $normalized = preg_replace('/\s+/', ' ', $normalized ?? '') ?? '';

        return match (trim($normalized)) {
            'air ticket' => 'air ticket',
            'visa' => 'visa',
            'umrah' => 'umrah',
            'hotel' => 'hotel',
            'transport' => 'transport',
            'tourism' => 'tourism',
            'other package', 'other' => 'other',
            default => '',
        };
    }

    private function normalizedRefundDetailPayload(
        array $input,
        string $paymentMethod,
        int $branchId,
        string $currency,
        float $refundAmount,
        string $treasuryAccountField = 'refund_treasury_account_id',
        bool $requireCustomerBankDestination = true,
        ?string $refundCurrencyField = null,
        ?string $refundSourceAmountField = null,
        ?string $refundExchangeRateField = null,
        bool $forceBookingCurrency = false
    ): array {
        $refundCurrencyField ??= $requireCustomerBankDestination ? 'customer_refund_currency' : 'supplier_refund_currency';
        $refundSourceAmountField ??= $requireCustomerBankDestination ? 'customer_refund_source_amount' : 'supplier_refund_source_amount';
        $refundExchangeRateField ??= $requireCustomerBankDestination ? 'customer_refund_exchange_rate' : 'supplier_refund_exchange_rate';
        $refundVisibleAmountField = $requireCustomerBankDestination ? 'customer_refund_amount' : 'supplier_refund_amount';

        $refundCurrency = $this->normalizeCurrency((string) ($input[$refundCurrencyField] ?? $currency));
        $refundSourceAmount = $this->refundPrecisionValue($input[$refundSourceAmountField] ?? 0);
        $visibleRefundAmount = $this->refundPrecisionValue($input[$refundVisibleAmountField] ?? 0);
        if ($refundSourceAmount < 0) {
            throw new RuntimeException('Refund actual amount cannot be negative.');
        }
        $refundExchangeRateToBooking = $forceBookingCurrency || $refundCurrency === $currency || $refundAmount <= 0.005
            ? null : $this->normalizeOptionalExchangeRate((string) ($input[$refundExchangeRateField] ?? ''));
        if ($refundExchangeRateToBooking !== null && ! is_finite($refundExchangeRateToBooking)) {
            throw new RuntimeException('Enter a finite exchange rate for the selected refund currency.');
        }

        if ($forceBookingCurrency) {
            $refundCurrency = $currency;
            $refundSourceAmount = $refundAmount > 0.005 ? $this->refundPrecisionValue($refundAmount) : 0.0;
            $refundExchangeRateToBooking = $refundAmount > 0.005 ? 1.0 : null;
        } elseif ($refundAmount <= 0.005) {
            $refundCurrency = $currency;
            $refundSourceAmount = 0.0;
            $refundExchangeRateToBooking = null;
        } elseif ($refundCurrency === $currency) {
            if ($requireCustomerBankDestination && $refundSourceAmount > 0.005
                && abs($refundSourceAmount - $refundAmount) > 0.005) {
                throw new RuntimeException('Customer payout amount and settled credit must match when their currency is the same.');
            }
            $refundSourceAmount = $this->refundPrecisionValue($refundAmount);
            $refundExchangeRateToBooking = 1.0;
        } else {
            $visibleIsBookingEquivalent = ! $requireCustomerBankDestination
                && in_array(($input['supplier_refund_amount_basis'] ?? ''), ['booking_equivalent', 'expected_source_derived'], true);
            if ($visibleRefundAmount > 0.005 && ! $requireCustomerBankDestination && ! $visibleIsBookingEquivalent) {
                $refundSourceAmount = $this->refundPrecisionValue($visibleRefundAmount);
            }

            if (! $requireCustomerBankDestination && $refundSourceAmount <= 0.005
                && $refundExchangeRateToBooking !== null && $refundExchangeRateToBooking > 0.00000001) {
                $refundSourceAmount = $this->refundPrecisionValue($refundAmount * $refundExchangeRateToBooking);
            }

            if ($refundSourceAmount <= 0.005) {
                throw new RuntimeException('Enter the actual refund amount in the selected refund currency.');
            }
            if ($requireCustomerBankDestination) {
                $refundExchangeRateToBooking = round($refundSourceAmount / $refundAmount, 8);
            } elseif ($refundExchangeRateToBooking === null || $refundExchangeRateToBooking <= 0.00000001) {
                throw new RuntimeException('Enter a valid exchange rate for the selected actual refund currency.');
            }

            $derivedBookingAmount = $this->refundPrecisionValue($refundSourceAmount / $refundExchangeRateToBooking);
            $allowedBookingVariance = 1.0;
            if (abs($derivedBookingAmount - $refundAmount) > $allowedBookingVariance) {
                throw new RuntimeException('Actual refund amount and exchange rate do not match the booking refund amount.');
            }
        }

        $treasuryAccountId = 0;
        $treasurySnapshot = null;

        if ($refundAmount > 0.005 && in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
            $treasuryAccountId = (int) ($input[$treasuryAccountField] ?? 0);
            $treasurySnapshot = (new TreasuryRepository($this->app))->validatePaymentTreasuryAccount(
                $treasuryAccountId,
                $branchId,
                $refundCurrency,
                $paymentMethod,
                $requireCustomerBankDestination && $paymentMethod === 'cash',
                ! $requireCustomerBankDestination,
                ! $requireCustomerBankDestination
            );
        }

        $customerBankName = $this->optionalText($input['customer_bank_name'] ?? null, 190);
        $customerBankAccountTitle = $this->optionalText($input['customer_bank_account_title'] ?? null, 190);
        $customerBankAccountNo = $this->optionalText($input['customer_bank_account_no'] ?? null, 120);
        $customerBankIban = $this->optionalText($input['customer_bank_iban'] ?? null, 120);
        $transferReference = $this->optionalText($input['transfer_reference'] ?? null, 190);
        $charges = $this->moneyValue($input['refund_charges'] ?? 0);
        $remarks = $this->optionalText($input['refund_notes'] ?? null, 4000);

        if ($requireCustomerBankDestination && $paymentMethod === 'bank_transfer' && $refundAmount > 0) {
            if ($customerBankName === null) {
                throw new RuntimeException('Customer bank name is required for bank refund.');
            }

            if ($customerBankAccountTitle === null && $customerBankAccountNo === null && $customerBankIban === null) {
                throw new RuntimeException('Enter customer bank account title, account number, or IBAN for bank refund.');
            }

            if ($transferReference === null) {
                throw new RuntimeException('Transfer reference is required for bank refund.');
            }
        }

        return [
            'treasury_account_id' => $treasuryAccountId > 0 ? $treasuryAccountId : null,
            'refund_payment_method' => $paymentMethod,
            'booking_currency' => $currency,
            'refund_currency' => $refundCurrency,
            'refund_source_amount' => $refundSourceAmount > 0.005 ? $refundSourceAmount : null,
            'refund_exchange_rate_to_booking' => $refundExchangeRateToBooking,
            // Preserve the existing booking-to-actual rate convention for posted refund details.
            'refund_exchange_rate_direction' => 'booking_to_refund',
            'refund_booking_equivalent' => $refundAmount,
            'refund_rate_date' => $refundAmount > 0.005 && $refundCurrency !== $currency
                ? ($this->normalizeOptionalDate((string) ($input[$requireCustomerBankDestination ? 'customer_refund_rate_date' : 'supplier_refund_rate_date']
                    ?? $input['refund_event_date'] ?? $input['correction_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d'))
                : null,
            'customer_bank_name' => $customerBankName,
            'customer_bank_account_title' => $customerBankAccountTitle,
            'customer_bank_account_no' => $customerBankAccountNo,
            'customer_bank_iban' => $customerBankIban,
            'transfer_reference' => $transferReference,
            'charges' => $charges,
            'remarks' => $remarks,
            'treasury_account_snapshot' => $treasurySnapshot !== null ? [
                'id' => (int) ($treasurySnapshot['id'] ?? 0),
                'branch_id' => (int) ($treasurySnapshot['branch_id'] ?? 0),
                'account_name' => (string) ($treasurySnapshot['account_name'] ?? ''),
                'account_code' => (string) ($treasurySnapshot['account_code'] ?? ''),
                'account_type' => (string) ($treasurySnapshot['account_type'] ?? ''),
                'currency' => (string) ($treasurySnapshot['currency'] ?? $currency),
            ] : null,
        ];
    }

    private function refundAmountInBookingCurrency(array $input, string $field, string $bookingCurrency): float
    {
        $amount = $this->refundPrecisionValue($input[$field] ?? 0);
        $fieldKey = preg_replace('/_amount$/', '', $field) ?: $field;
        $selectedCurrency = $this->normalizeCurrency((string) (($input[$fieldKey . '_currency'] ?? null) ?: $bookingCurrency));
        if ($amount <= 0.005 || $selectedCurrency === $bookingCurrency) {
            return $amount;
        }

        $exchangeRate = $this->normalizeOptionalExchangeRate((string) ($input[$fieldKey . '_exchange_rate'] ?? ''));
        if ($exchangeRate === null || $exchangeRate <= 0.00000001) {
            throw new RuntimeException('Enter a valid exchange rate for the selected refund currency.');
        }

        if (in_array(($input['supplier_refund_amount_basis'] ?? ''), ['booking_equivalent', 'expected_source_derived'], true)) {
            $sourceAmount = $this->refundPrecisionValue($input[$fieldKey . '_source_amount'] ?? 0);
            if ($sourceAmount <= 0.005 || abs($sourceAmount / $exchangeRate - $amount) > 1.0) {
                throw new RuntimeException('Actual supplier refund amount, booking equivalent, and exchange rate do not match.');
            }
            return $amount;
        }

        return $this->refundPrecisionValue($amount / $exchangeRate);
    }

    private function normalizeExpectedSourceSupplierRefundInput(
        array $input,
        array $cancelPayload,
        string $bookingCurrency,
        string $supplierCurrency
    ): array
    {
        $sourceAmount = $this->refundPrecisionValue($input['supplier_refund_amount'] ?? 0);
        if ($sourceAmount <= 0.005) {
            return $input;
        }

        $expectedCurrency = $this->normalizeCurrency((string) ($cancelPayload['expected_supplier_refund_currency'] ?? $supplierCurrency));
        $submittedCurrency = $this->normalizeCurrency((string) ($input['supplier_refund_currency'] ?? $expectedCurrency));
        if ($submittedCurrency !== $expectedCurrency) {
            throw new RuntimeException('Supplier refund currency differs from the saved expected currency. Correct the cancellation expectation first.');
        }

        if ($expectedCurrency === $bookingCurrency) {
            $input['supplier_refund_source_amount'] = $sourceAmount;
            $input['supplier_refund_amount_basis'] = 'expected_source_derived';
            return $input;
        }

        $expectedSourceAmount = $this->refundPrecisionValue($cancelPayload['expected_supplier_refund_source_amount'] ?? 0);
        $equivalentCurrency = strtoupper(trim((string) ($cancelPayload['expected_supplier_refund_equivalent_currency'] ?? '')));
        $expectedBookingAmount = $this->refundPrecisionValue(
            $cancelPayload['expected_supplier_refund_booking_amount']
            ?? ($equivalentCurrency === $bookingCurrency
                ? ($cancelPayload['expected_supplier_refund_equivalent_amount'] ?? 0)
                : 0)
        );
        if ($expectedSourceAmount <= 0.005 || $expectedBookingAmount <= 0.005) {
            throw new RuntimeException('The cancellation has no verified supplier refund equivalent. Correct the cancellation settlement before recording this refund.');
        }
        if ($sourceAmount > $expectedSourceAmount + 0.005) {
            throw new RuntimeException('Supplier refund exceeds the saved expected amount in ' . $expectedCurrency . '.');
        }

        $bookingEquivalent = $sourceAmount === $expectedSourceAmount
            ? $expectedBookingAmount
            : $this->refundPrecisionValue($sourceAmount * $expectedBookingAmount / $expectedSourceAmount);
        if ($bookingEquivalent <= 0.005) {
            throw new RuntimeException('Supplier refund is too small to record in the booking currency.');
        }

        $input['supplier_refund_amount'] = $bookingEquivalent;
        $input['supplier_refund_source_amount'] = $sourceAmount;
        $input['supplier_refund_exchange_rate'] = round($expectedSourceAmount / $expectedBookingAmount, 8);
        $input['supplier_refund_rate_date'] = (string) (
            $cancelPayload['expected_supplier_refund_rate_date']
            ?? $input['refund_event_date']
            ?? date('Y-m-d')
        );
        $input['supplier_refund_amount_basis'] = 'expected_source_derived';

        return $input;
    }

    private function customerRefundAmountInBookingCurrency(array $input, string $field): float
    {
        return $this->moneyValue($input[$field] ?? 0);
    }

    private function refundPrecisionValue(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        if ($number < 0) {
            throw new RuntimeException('Service monetary values cannot be negative.');
        }

        return round($number, 0);
    }

    private function normalizeSupplierRefundEquivalentInput(array $input, string $bookingCurrency, array $service = []): array
    {
        if (($input['supplier_refund_conversion_mode'] ?? '') !== 'manual_equivalent') {
            return $input;
        }
        // The UI service-charge refund is in the customer invoice currency, independently of supplier FX.
        $input['agency_fee_refund_currency'] = $bookingCurrency;
        $input['agency_fee_refund_exchange_rate'] = 1;
        $source = $this->normalizeCurrency((string) ($input['expected_supplier_refund_currency'] ?? $bookingCurrency));
        $target = $this->normalizeCurrency((string) ($input['customer_penalty_currency'] ?? $bookingCurrency));
        if (! is_numeric($input['expected_supplier_refund_amount'] ?? 0)) {
            throw new RuntimeException('Enter a non-negative whole expected supplier refund amount.');
        }
        $amount = (float) ($input['expected_supplier_refund_amount'] ?? 0);
        if (! is_finite($amount) || $amount < 0 || round($amount, 0) !== $amount) {
            throw new RuntimeException('Enter a non-negative whole expected supplier refund amount.');
        }
        $recordedRate = $source !== $target && $target === $bookingCurrency
            && ($service['currency'] ?? '') === $bookingCurrency && ($service['cost_currency'] ?? '') === $source
            && is_numeric($input['customer_penalty_amount'] ?? 0) && (float) ($input['customer_penalty_amount'] ?? 0) === 0.0
            ? (float) ($service['pricing_exchange_rate'] ?? 0) : 0.0;
        $enteredEquivalent = $input['expected_supplier_refund_equivalent_amount'] ?? null;
        $reuseRecordedRate = is_finite($recordedRate) && $recordedRate > 0.00000001
            && (! is_numeric($enteredEquivalent)
                || (float) $enteredEquivalent === 0.0
                || round((float) $enteredEquivalent, 0) === round($amount * $recordedRate, 0));
        if ($reuseRecordedRate) {
            $input['expected_supplier_refund_equivalent_amount'] = round($amount * $recordedRate, 0);
            $input['expected_supplier_refund_rate_date'] = $service['pricing_rate_effective_date']
                ?? $input['expected_supplier_refund_rate_date'] ?? date('Y-m-d');
        }
        $equivalent = $source === $target ? $amount : (float) ($input['expected_supplier_refund_equivalent_amount'] ?? 0);
        if ($amount === 0.0) {
            $equivalent = 0.0;
        } elseif (($source !== $target && ! is_numeric($input['expected_supplier_refund_equivalent_amount'] ?? null))
            || ! is_finite($equivalent) || $equivalent <= 0 || round($equivalent, 0) !== $equivalent) {
            throw new RuntimeException('Please enter the equivalent amount in ' . $target . '.');
        }
        $targetRate = $target === $bookingCurrency ? 1.0
            : (float) ($input['customer_penalty_exchange_rate'] ?? 0);
        // A pair including the invoice currency determines both conversion directions.
        if ($source === $bookingCurrency && $source !== $target && $amount > 0) {
            $targetRate = $amount / $equivalent;
            $input['customer_penalty_exchange_rate'] = round($targetRate, 8);
        }
        if (($amount > 0 || (float) ($input['customer_penalty_amount'] ?? 0) > 0)
            && (! is_finite($targetRate) || $targetRate <= 0.00000001)) {
            throw new RuntimeException('Confirm the customer penalty currency conversion to ' . $bookingCurrency . ' before saving.');
        }
        $rate = $reuseRecordedRate ? $recordedRate : ($source === $bookingCurrency ? 1.0
            : ($amount > 0 ? round(($equivalent / $amount) * $targetRate, 8) : 0.0));
        if ($amount > 0 && (! is_finite($rate) || $rate <= 0.00000001
            || abs(round($amount * $rate, 0) - round($equivalent * $targetRate, 0)) > 0.005)) {
            throw new RuntimeException('The equivalent amount cannot be represented safely. Review the amounts.');
        }
        $input['expected_supplier_refund_exchange_rate'] = $rate;
        $input['expected_supplier_refund_equivalent_amount'] = $equivalent;
        $input['expected_supplier_refund_equivalent_currency'] = $target;
        $input['expected_supplier_refund_conversion_basis'] = $reuseRecordedRate ? 'saved_booking_rate' : 'manual_equivalent';
        return $input;
    }

    private function supplierRefundEquivalentMetadata(array $input): array
    {
        return ($input['supplier_refund_conversion_mode'] ?? '') === 'manual_equivalent' ? [
            'expected_supplier_refund_equivalent_amount' => (float) $input['expected_supplier_refund_equivalent_amount'],
            'expected_supplier_refund_equivalent_currency' => (string) $input['expected_supplier_refund_equivalent_currency'],
            'expected_supplier_refund_conversion_basis' => $input['expected_supplier_refund_conversion_basis'] ?? 'manual_equivalent',
        ] : [];
    }

    private function settlementAmountInBookingCurrency(array $input, string $field, string $bookingCurrency): float
    {
        $amount = $this->moneyValue($input[$field] ?? 0);
        $fieldKey = preg_replace('/_amount$/', '', $field) ?: $field;
        $selectedCurrency = $this->normalizeCurrency((string) (($input[$fieldKey . '_currency'] ?? null) ?: ($input['settlement_currency'] ?? $bookingCurrency)));
        if ($amount <= 0.005 || $selectedCurrency === $bookingCurrency) {
            return $amount;
        }

        $exchangeRate = $this->normalizeOptionalExchangeRate((string) (($input[$fieldKey . '_exchange_rate'] ?? null) ?: ($input['settlement_exchange_rate'] ?? '')));
        if ($exchangeRate === null || $exchangeRate <= 0.00000001) {
            throw new RuntimeException('Enter a valid exchange rate for the selected refund currency.');
        }

        return $this->moneyValue($amount * $exchangeRate);
    }

    private function settlementAmountInTruthCurrency(
        array $input,
        string $field,
        string $bookingCurrency,
        string $truthCurrency,
        ?float $truthRateToBooking
    ): float {
        $bookingAmount = $this->settlementAmountInBookingCurrency($input, $field, $bookingCurrency);
        $fieldKey = preg_replace('/_amount$/', '', $field) ?: $field;
        $selectedCurrency = $this->normalizeCurrency((string) (($input[$fieldKey . '_currency'] ?? null) ?: ($input['settlement_currency'] ?? $bookingCurrency)));
        $sourceAmount = $this->moneyValue($input[$field] ?? 0);

        if ($bookingAmount <= 0.005 || $sourceAmount <= 0.005) {
            return 0.0;
        }

        if ($selectedCurrency === $truthCurrency) {
            return $sourceAmount;
        }

        return $this->convertSettlementBookingAmountToTruthCurrency(
            $bookingAmount,
            $truthCurrency,
            $bookingCurrency,
            $truthRateToBooking
        );
    }

    private function convertSettlementTruthAmountToBookingCurrency(
        float $truthAmount,
        string $truthCurrency,
        string $bookingCurrency,
        ?float $truthRateToBooking
    ): float {
        $normalizedTruthAmount = round(max($truthAmount, 0), 2);
        if ($normalizedTruthAmount <= 0.005) {
            return 0.0;
        }

        if ($truthCurrency === $bookingCurrency) {
            return $this->moneyValue($normalizedTruthAmount);
        }

        if ($truthRateToBooking === null || $truthRateToBooking <= 0.00000001) {
            throw new RuntimeException('Exchange rate is missing. Enter it from the pricing fields or Exchange Settlement, then save again.');
        }

        return $this->moneyValue($normalizedTruthAmount * $truthRateToBooking);
    }

    private function convertSettlementBookingAmountToTruthCurrency(
        float $bookingAmount,
        string $truthCurrency,
        string $bookingCurrency,
        ?float $truthRateToBooking
    ): float {
        $normalizedBookingAmount = round(max($bookingAmount, 0), 2);
        if ($normalizedBookingAmount <= 0.005) {
            return 0.0;
        }

        if ($truthCurrency === $bookingCurrency) {
            return $this->moneyValue($normalizedBookingAmount);
        }

        if ($truthRateToBooking === null || $truthRateToBooking <= 0.00000001) {
            throw new RuntimeException('Exchange rate is missing. Enter it from the pricing fields or Exchange Settlement, then save again.');
        }

        return $this->moneyValue($normalizedBookingAmount / $truthRateToBooking);
    }

    private function resolveSupplierSettlementTruthCurrency(array $existingService, string $bookingCurrency): string
    {
        return $this->normalizeCurrency((string) (
            $existingService['cost_currency']
            ?? $existingService['supplier_truth_currency']
            ?? $bookingCurrency
        ));
    }

    private function assertSupplierObligationCurrencyMatchesCost(?array $obligation, string $costCurrency): void
    {
        if ($obligation === null) {
            return;
        }

        if ($this->normalizeCurrency((string) ($obligation['currency'] ?? '')) !== $costCurrency) {
            throw new RuntimeException(
                'Supplier payable currency differs from the service cost currency. Correct the service financial currency before changing its cancellation or refund.'
            );
        }
    }

    private function assertCustomerReceivableCurrencyMatchesInvoice(?array $receivable, string $invoiceCurrency): void
    {
        if ($receivable === null) {
            return;
        }

        if ($this->normalizeCurrency((string) ($receivable['currency'] ?? '')) !== $invoiceCurrency) {
            throw new RuntimeException(
                'Customer receivable currency differs from the invoice currency. Correct the service financial currency before changing its cancellation or refund.'
            );
        }
    }

    private function resolveSupplierRestoreCurrency(array $cancelPayload, array $existingService, string $bookingCurrency): string
    {
        foreach ([
            $cancelPayload['supplier_cost_basis_currency'] ?? null,
            $cancelPayload['supplier_penalty_currency'] ?? null,
            $cancelPayload['expected_supplier_refund_currency'] ?? null,
            $existingService['cost_currency'] ?? null,
            $existingService['supplier_truth_currency'] ?? null,
            $bookingCurrency,
        ] as $candidate) {
            $normalized = strtoupper(trim((string) $candidate));
            if ($normalized !== '' && in_array($normalized, self::ALLOWED_CURRENCIES, true)) {
                return $normalized;
            }
        }

        return 'PKR';
    }

    private function resolveServiceCurrencyRateToBooking(array $existingService, string $sourceCurrency, string $bookingCurrency): ?float
    {
        $normalizedSource = $this->normalizeCurrency($sourceCurrency);
        $normalizedBooking = $this->normalizeCurrency($bookingCurrency);
        if ($normalizedSource === $normalizedBooking) {
            return 1.0;
        }

        $costCurrency = $this->normalizeCurrency((string) ($existingService['cost_currency'] ?? $normalizedBooking));
        if ($normalizedSource === $costCurrency) {
            $pricingRate = (float) ($existingService['pricing_exchange_rate'] ?? 0);
            if ($pricingRate > 0) {
                return $this->normalizePricingExchangeRate($pricingRate);
            }
        }

        return null;
    }

    /**
     * @return array{
     *   expected_supplier_refund: float,
     *   supplier_refund_received_amount: float,
     *   remaining_expected_supplier_refund: float,
     *   supplier_settled_amount: float,
     *   repository_credit_balance: float
     * }
     */
    private function supplierRecoveryStateForService(
        int $serviceId,
        string $bookingReference,
        string $currency,
        ?array $obligation,
        BookingServiceEventRepository $eventRepository,
        array $serviceRow = []
    ): array {
        $currency = strtoupper(trim($currency));
        $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
        $cancelPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
        if (! is_array($cancelPayload)) {
            $cancelPayload = [];
        }

        $supplierTruthCurrency = $this->resolveSupplierRestoreCurrency($cancelPayload, $serviceRow, $currency);
        $supplierTruthRateToBooking = $this->resolveServiceCurrencyRateToBooking($serviceRow, $supplierTruthCurrency, $currency);
        $supplierSettledAmount = 0.0;
        $supplierPenaltyAmount = 0.0;
        $supplierPenaltySourceAmount = round((float) (
            $cancelPayload['supplier_penalty_source_amount']
            ?? $cancelPayload['supplier_penalty_amount']
            ?? 0
        ), 2);
        $supplierCostBasis = round((float) ($cancelPayload['supplier_cost_basis'] ?? $serviceRow['purchase_cost'] ?? 0), 2);

        if ($obligation !== null) {
            $grossAmount = round((float) ($obligation['gross_amount'] ?? 0), 2);
            $netPayableAmount = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
            $supplierSettledAmount = $this->convertSettlementTruthAmountToBookingCurrency(
                round(max($grossAmount - $netPayableAmount, 0), 2),
                (string) ($obligation['currency'] ?? $supplierTruthCurrency),
                $currency,
                $this->resolveServiceCurrencyRateToBooking($serviceRow, (string) ($obligation['currency'] ?? $supplierTruthCurrency), $currency)
            );
            $supplierPenaltyAmount = round((float) (
                $cancelPayload['supplier_penalty_booking_amount']
                ?? $cancelPayload['supplier_penalty_amount']
                ?? $this->convertSettlementTruthAmountToBookingCurrency($grossAmount, (string) ($obligation['currency'] ?? $supplierTruthCurrency), $currency, $this->resolveServiceCurrencyRateToBooking($serviceRow, (string) ($obligation['currency'] ?? $supplierTruthCurrency), $currency))
            ), 2);
            if ($supplierCostBasis <= 0.005) {
                $supplierCostBasis = $grossAmount;
            }
        } else {
            $supplierPenaltyAmount = round((float) (
                $cancelPayload['supplier_penalty_booking_amount']
                ?? $cancelPayload['supplier_penalty_amount']
                ?? $this->convertSettlementTruthAmountToBookingCurrency($supplierPenaltySourceAmount, $supplierTruthCurrency, $currency, $supplierTruthRateToBooking)
            ), 2);
        }

        $expectedSupplierRefundAmount = round((float) (
            $cancelPayload['expected_supplier_refund_booking_amount']
            ?? $cancelPayload['expected_supplier_refund_amount']
            ?? 0
        ), 2);
        $expectedSupplierRefundSourceAmount = round((float) (
            $cancelPayload['expected_supplier_refund_source_amount']
            ?? $cancelPayload['expected_supplier_refund_amount']
            ?? 0
        ), 2);
        if ($expectedSupplierRefundAmount <= 0.005 && $expectedSupplierRefundSourceAmount > 0.005) {
            $expectedSupplierRefundAmount = $this->convertSettlementTruthAmountToBookingCurrency(
                $expectedSupplierRefundSourceAmount,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
        }
        if ($expectedSupplierRefundAmount <= 0.005 && $supplierCostBasis > 0.005) {
            $expectedSupplierRefundAmount = round(max(
                $this->convertSettlementTruthAmountToBookingCurrency(
                    $supplierCostBasis,
                    $supplierTruthCurrency,
                    $currency,
                    $supplierTruthRateToBooking
                ) - $supplierPenaltyAmount,
                0
            ), 2);
        }

        $releasedSupplierCreditAmount = round((float) (
            $cancelPayload['expected_supplier_refund_credit']
            ?? $cancelPayload['released_supplier_credit']
            ?? 0
        ), 2);
        $releasedSupplierCreditSourceAmount = round((float) (
            $cancelPayload['expected_supplier_refund_credit_source_amount']
            ?? $cancelPayload['released_supplier_credit_source_amount']
            ?? $cancelPayload['expected_supplier_refund_credit']
            ?? $cancelPayload['released_supplier_credit']
            ?? $cancelEvent['supplier_credit_amount']
            ?? 0
        ), 2);
        if ($releasedSupplierCreditAmount <= 0.005 && $releasedSupplierCreditSourceAmount > 0.005) {
            $releasedSupplierCreditAmount = $this->convertSettlementTruthAmountToBookingCurrency(
                $releasedSupplierCreditSourceAmount,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
        }
        if ($releasedSupplierCreditAmount <= 0.005 && $supplierSettledAmount > 0.0) {
            $releasedSupplierCreditAmount = round(max($supplierSettledAmount - $supplierPenaltyAmount, 0), 2);
        }

        $supplierRefundReceivedAmount = 0.0;
        foreach ($eventRepository->postedEventsForService($serviceId) as $eventRow) {
            if ((string) ($eventRow['event_type'] ?? '') !== 'refund') {
                continue;
            }

            $supplierRefundReceivedAmount = round(
                $supplierRefundReceivedAmount + (float) ($eventRow['supplier_refund_amount'] ?? 0),
                2
            );
        }

        $repositoryCreditBalance = 0.0;
        $supplierId = (int) ($obligation['supplier_id'] ?? 0);
        if ($supplierId > 0 && $bookingReference !== '' && $currency !== '') {
            $repositoryCreditBalance = $this->convertSettlementTruthAmountToBookingCurrency(
                (new SupplierRepository($this->app))->availableRefundableCreditForService(
                    $supplierId,
                    $bookingReference,
                    $supplierTruthCurrency,
                    (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
                ),
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
        }

        $hasModernExpectedSupplierRefundTruth =
            array_key_exists('expected_supplier_refund_credit', $cancelPayload)
            || array_key_exists('expected_supplier_refund_amount', $cancelPayload)
            || array_key_exists('supplier_cost_basis', $cancelPayload)
            || (string) ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment';

        $expectedSupplierRefundCandidates = [
            $expectedSupplierRefundAmount,
            $releasedSupplierCreditAmount,
            0.0,
        ];

        if (! $hasModernExpectedSupplierRefundTruth) {
            $expectedSupplierRefundCandidates[] = $repositoryCreditBalance;
        }

        $expectedSupplierRefund = round(max(...$expectedSupplierRefundCandidates), 2);
        $recordedSupplierCreditSourceAmount = array_key_exists('supplier_credit_recorded_source_amount', $cancelPayload)
            ? round((float) $cancelPayload['supplier_credit_recorded_source_amount'], 2)
            : round((float) ($cancelPayload['released_supplier_advance_credit'] ?? 0), 2);
        $recordedSupplierCreditBookingAmount = array_key_exists('supplier_credit_recorded_booking_amount', $cancelPayload)
            ? round((float) $cancelPayload['supplier_credit_recorded_booking_amount'], 2)
            : $this->convertSettlementTruthAmountToBookingCurrency(
                $recordedSupplierCreditSourceAmount,
                $supplierTruthCurrency,
                $currency,
                $supplierTruthRateToBooking
            );
        // A cash/credit refund event can document an already released advance;
        // it is not a second recovery of the same supplier money.
        $remainingExpectedSupplierRefund = array_key_exists('supplier_credit_recording_version', $cancelPayload)
            ? 0.0
            : round(max(
                $expectedSupplierRefund - max($supplierRefundReceivedAmount, $recordedSupplierCreditBookingAmount),
                0
            ), 2);

        return [
            'expected_supplier_refund' => $expectedSupplierRefund,
            'supplier_refund_received_amount' => $supplierRefundReceivedAmount,
            'supplier_credit_recorded_source_amount' => $recordedSupplierCreditSourceAmount,
            'supplier_credit_recorded_booking_amount' => $recordedSupplierCreditBookingAmount,
            'remaining_expected_supplier_refund' => $remainingExpectedSupplierRefund,
            'supplier_settled_amount' => $supplierSettledAmount,
            'repository_credit_balance' => $repositoryCreditBalance,
        ];
    }

    /**
     * @param array<string,mixed> $cancelPayload
     * @param array<string,mixed>|null $cancelEvent
     * @return array{
     *   released_customer_credit: float,
     *   customer_refund_received_amount: float,
     *   remaining_customer_refund_credit: float,
     *   service_remaining_customer_refund_credit: float
     * }
     */
    private function customerRefundStateForService(
        int $serviceId,
        string $bookingReference,
        string $currency,
        array $cancelPayload,
        ?array $cancelEvent,
        BookingServiceEventRepository $eventRepository,
        CustomerPaymentRepository $customerRepository,
        ?array $supplierRefundState = null
    ): array {
        $hasModernAvailableCredit = array_key_exists('available_customer_refund_credit', $cancelPayload);
        $modernRefundTruthBasis = $this->resolveModernCustomerRefundTruthAmount($cancelPayload, $currency);
        $releasedCustomerCreditBasis = round((float) (
            $hasModernAvailableCredit
                ? $modernRefundTruthBasis
                : ($cancelPayload['released_customer_credit']
                    ?? $cancelEvent['customer_credit_amount']
                    ?? 0)
        ), 2);
        $releasedCustomerCredit = max($releasedCustomerCreditBasis, 0);

        if (! $hasModernAvailableCredit) {
            $expectedSupplierRefund = round((float) ($supplierRefundState['expected_supplier_refund'] ?? 0), 2);
            $customerPenalty = round((float) ($cancelPayload['customer_penalty_amount'] ?? $cancelEvent['penalty_amount'] ?? 0), 2);
            $supplierCappedCustomerRefund = round(max($expectedSupplierRefund - $customerPenalty, 0), 2);
            if ($supplierCappedCustomerRefund > 0.005) {
                $releasedCustomerCredit = round(min($releasedCustomerCredit, $supplierCappedCustomerRefund), 2);
            }
        }

        $customerRefundReceivedAmount = 0.0;
        foreach ($eventRepository->postedEventsForService($serviceId) as $eventRow) {
            if ((string) ($eventRow['event_type'] ?? '') !== 'refund') {
                continue;
            }

            $customerRefundReceivedAmount = round(
                $customerRefundReceivedAmount + (float) ($eventRow['customer_refund_amount'] ?? 0),
                2
            );
        }

        // The cancellation payload is historical evidence of what was released.
        // The receipt header is the current truth after cash refunds, transfers to
        // another booking, or later reallocation. Never continue advertising
        // credit that is no longer unallocated on its source booking.
        $currentBookingCredit = max(
            $customerRepository->bookingUnallocatedCreditTotal($bookingReference, $currency),
            0.0
        );
        $unsettledReleasedCredit = round(max($releasedCustomerCredit - $customerRefundReceivedAmount, 0), 2);
        $releasedReceiptIds = array_values(array_filter(array_map(
            'intval',
            (array) ($cancelPayload['released_customer_receipt_ids'] ?? [])
        ), static fn (int $id): bool => $id > 0));
        $hasReleasedReceiptEvidence = $releasedReceiptIds !== [];
        $hasReceiptEvidence = $hasReleasedReceiptEvidence
            || $customerRepository->bookingHasReceiptEvidence($bookingReference, $currency);
        $releasedReceiptCredit = $hasReleasedReceiptEvidence
            ? $customerRepository->releasedReceiptUnallocatedCreditTotal($bookingReference, $currency, $releasedReceiptIds)
            : $currentBookingCredit;
        $backedCreditAvailable = round(min($unsettledReleasedCredit, $currentBookingCredit, $releasedReceiptCredit), 2);
        $remainingCustomerRefundCredit = $hasReceiptEvidence
            ? $backedCreditAvailable
            : ($hasModernAvailableCredit
                ? $unsettledReleasedCredit
                : round(min($unsettledReleasedCredit, $currentBookingCredit), 2));
        $serviceRemainingCustomerRefundCredit = $hasReceiptEvidence
            ? $backedCreditAvailable
            : $unsettledReleasedCredit;

        app_write_log('service.refund.customer_state_trace', 'Computed customer refund state for service refund validation.', array_merge(
            app_request_log_context(),
            [
                'service_id' => $serviceId,
                'booking_reference' => $bookingReference,
                'currency' => $currency,
                'has_modern_available_credit' => $hasModernAvailableCredit,
                'released_customer_credit_basis' => $releasedCustomerCreditBasis,
                'modern_refund_truth_basis' => $modernRefundTruthBasis,
                'released_customer_credit' => $releasedCustomerCredit,
                'customer_refund_received_amount' => $customerRefundReceivedAmount,
                'current_booking_credit' => $currentBookingCredit,
                'released_receipt_ids' => $releasedReceiptIds,
                'released_receipt_credit' => $releasedReceiptCredit,
                'has_receipt_evidence' => $hasReceiptEvidence,
                'unsettled_released_credit' => $unsettledReleasedCredit,
                'remaining_customer_refund_credit' => $remainingCustomerRefundCredit,
                'current_booking_credit_cap_applied' => $hasReceiptEvidence || ! $hasModernAvailableCredit,
                'cancel_event_customer_credit_amount' => (float) ($cancelEvent['customer_credit_amount'] ?? 0),
                'cancel_payload_available_customer_refund_credit' => (float) ($cancelPayload['available_customer_refund_credit'] ?? 0),
                'cancel_payload_available_customer_refund_credit_currency' => (string) ($cancelPayload['available_customer_refund_credit_currency'] ?? ''),
                'cancel_payload_released_customer_credit' => (float) ($cancelPayload['released_customer_credit'] ?? 0),
                'cancel_payload_target_customer_refund_credit' => (float) ($cancelPayload['target_customer_refund_credit'] ?? 0),
                'cancel_payload_target_customer_refund_credit_currency' => (string) ($cancelPayload['target_customer_refund_credit_currency'] ?? ''),
                'cancel_payload_customer_refund_basis' => (float) ($cancelPayload['customer_refund_basis'] ?? 0),
                'cancel_payload_customer_refund_basis_currency' => (string) ($cancelPayload['customer_refund_basis_currency'] ?? ''),
                'cancel_payload_customer_penalty_amount' => (float) ($cancelPayload['customer_penalty_amount'] ?? $cancelEvent['penalty_amount'] ?? 0),
                'cancel_payload_expected_supplier_refund_amount' => (float) ($cancelPayload['expected_supplier_refund_amount'] ?? 0),
                'supplier_state_expected_supplier_refund' => (float) ($supplierRefundState['expected_supplier_refund'] ?? 0),
            ]
        ));

        return [
            'released_customer_credit' => $releasedCustomerCredit,
            'customer_refund_received_amount' => $customerRefundReceivedAmount,
            'service_remaining_customer_refund_credit' => $serviceRemainingCustomerRefundCredit,
            'remaining_customer_refund_credit' => $remainingCustomerRefundCredit,
        ];
    }

    private function resolveCustomerRefundReceiptCreditCurrency(array $existingService, array $cancelPayload): string
    {
        foreach ([
            $cancelPayload['released_customer_credit_booking_currency'] ?? null,
            $cancelPayload['customer_received_currency'] ?? null,
            $existingService['currency'] ?? null,
        ] as $candidate) {
            $normalized = strtoupper(trim((string) $candidate));
            if ($normalized !== '' && in_array($normalized, self::ALLOWED_CURRENCIES, true)) {
                return $normalized;
            }
        }

        return 'PKR';
    }

    private function determineCustomerRefundReceiptCreditConsumptionAmount(
        float $refundAmount,
        float $remainingTruthRefundAmount,
        float $availableReceiptCreditAmount
    ): float {
        $normalizedRefundAmount = round(max($refundAmount, 0), 2);
        $normalizedRemainingTruthAmount = round(max($remainingTruthRefundAmount, 0), 2);
        $normalizedAvailableReceiptCreditAmount = round(max($availableReceiptCreditAmount, 0), 2);

        if (
            $normalizedRefundAmount <= 0.005
            || $normalizedRemainingTruthAmount <= 0.005
            || $normalizedAvailableReceiptCreditAmount <= 0.005
        ) {
            return 0.0;
        }

        if ($normalizedRefundAmount >= $normalizedRemainingTruthAmount - 0.005) {
            return $normalizedAvailableReceiptCreditAmount;
        }

        return round(min(
            $normalizedAvailableReceiptCreditAmount,
            $normalizedAvailableReceiptCreditAmount * ($normalizedRefundAmount / $normalizedRemainingTruthAmount)
        ), 2);
    }

    private function refundPayloadUsedSyntheticCustomerCredit(array $refundPayload): bool
    {
        if ((bool) ($refundPayload['customer_receipt_credit_synthetic'] ?? false)) {
            return true;
        }

        return (float) ($refundPayload['customer_receipt_credit_consumption_amount'] ?? 0) <= 0.005
            && (float) ($refundPayload['customer_refundable_balance_before'] ?? 0) > 0.005
            && (float) ($refundPayload['customer_credit_balance_before'] ?? 0) <= 0.005;
    }

    private function resolveCustomerRefundTruthCurrency(array $cancelPayload, string $fallbackCurrency, string $eventCurrency = ''): string
    {
        // Financial-adjustment amounts belong to the invoice/event ledger, not the supplier currency.
        if (($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
            && strtoupper($eventCurrency) === strtoupper($fallbackCurrency)
            && array_key_exists('customer_received_amount', $cancelPayload)
            && array_key_exists('prior_customer_due_amount', $cancelPayload)) {
            return $this->normalizeCurrency($fallbackCurrency);
        }
        foreach ([
            'available_customer_refund_credit_currency',
            'target_customer_refund_credit_currency',
            'customer_refund_basis_currency',
            'settlement_currency',
            'customer_penalty_currency',
        ] as $key) {
            $candidate = strtoupper(trim((string) ($cancelPayload[$key] ?? '')));
            if ($candidate !== '' && in_array($candidate, self::ALLOWED_CURRENCIES, true)) {
                return $candidate;
            }
        }

        return $this->normalizeCurrency($fallbackCurrency);
    }

    private function resolveModernCustomerRefundTruthAmount(array $cancelPayload, string $currency): float
    {
        $availableAmount = round((float) ($cancelPayload['available_customer_refund_credit'] ?? 0), 2);
        $availableCurrency = strtoupper(trim((string) ($cancelPayload['available_customer_refund_credit_currency'] ?? '')));
        if (
            array_key_exists('available_customer_refund_credit', $cancelPayload)
            && $availableCurrency !== ''
            && $availableCurrency === $currency
        ) {
            return max($availableAmount, 0);
        }

        $targetAmount = round((float) ($cancelPayload['target_customer_refund_credit'] ?? 0), 2);
        $targetCurrency = strtoupper(trim((string) ($cancelPayload['target_customer_refund_credit_currency'] ?? '')));
        if (
            array_key_exists('target_customer_refund_credit', $cancelPayload)
            && $targetCurrency !== ''
            && $targetCurrency === $currency
        ) {
            return max($targetAmount, 0);
        }

        $basisAmount = round((float) ($cancelPayload['customer_refund_basis'] ?? 0), 2);
        $basisCurrency = strtoupper(trim((string) ($cancelPayload['customer_refund_basis_currency'] ?? '')));
        if (
            array_key_exists('customer_refund_basis', $cancelPayload)
            && $basisCurrency !== ''
            && $basisCurrency === $currency
        ) {
            return max($basisAmount, 0);
        }

        $derivedTargetAmount = $this->deriveModernCustomerRefundTruthAmount($cancelPayload, $currency);
        if ($derivedTargetAmount > 0.005) {
            return $derivedTargetAmount;
        }

        if ($availableAmount > 0.005) {
            return $availableAmount;
        }

        return round((float) ($cancelPayload['released_customer_credit'] ?? 0), 2);
    }

    private function deriveModernCustomerRefundTruthAmount(array $cancelPayload, string $currency): float
    {
        $settlementCurrency = strtoupper(trim((string) ($cancelPayload['settlement_currency'] ?? '')));
        $expectedSupplierRefundAmount = round((float) ($cancelPayload['expected_supplier_refund_amount'] ?? 0), 2);
        $expectedSupplierRefundCurrency = strtoupper(trim((string) (
            $cancelPayload['expected_supplier_refund_currency']
            ?? $settlementCurrency
        )));
        $customerPenaltyAmount = round((float) ($cancelPayload['customer_penalty_amount'] ?? 0), 2);
        $customerPenaltyCurrency = strtoupper(trim((string) (
            $cancelPayload['customer_penalty_currency']
            ?? $settlementCurrency
        )));
        $agencyFeeRefundAmount = round((float) ($cancelPayload['agency_fee_refund_amount'] ?? 0), 2);
        $expectedSupplierRefundSourceAmount = round((float) ($cancelPayload['expected_supplier_refund_source_amount'] ?? 0), 2);
        $customerPenaltySourceAmount = round((float) ($cancelPayload['customer_penalty_source_amount'] ?? 0), 2);

        if (
            $expectedSupplierRefundSourceAmount > 0.005
            && ($expectedSupplierRefundCurrency === '' || $expectedSupplierRefundCurrency === $currency)
            && ($customerPenaltyCurrency === '' || $customerPenaltyCurrency === $currency)
        ) {
            return round(max($expectedSupplierRefundSourceAmount - $customerPenaltySourceAmount + $agencyFeeRefundAmount, 0), 2);
        }

        if (
            $expectedSupplierRefundAmount > 0.005
            && ($expectedSupplierRefundCurrency === '' || $expectedSupplierRefundCurrency === $currency)
            && ($customerPenaltyCurrency === '' || $customerPenaltyCurrency === $currency)
            && ($settlementCurrency === '' || $settlementCurrency === $currency)
        ) {
            return round(max($expectedSupplierRefundAmount - $customerPenaltyAmount + $agencyFeeRefundAmount, 0), 2);
        }

        return 0.0;
    }

    private function appendAuditNote(string $existingNotes, string $line): string
    {
        $existingNotes = trim($existingNotes);
        $line = trim($line);
        if ($line === '') {
            return $existingNotes;
        }

        if ($existingNotes === '') {
            return $line;
        }

        return $existingNotes . "\n" . $line;
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

    private function isCancelledStatus(string $value): bool
    {
        return str_replace(' ', '_', mb_strtolower(trim($value))) === 'cancelled';
    }

    private function moneyValue(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        if ($number < 0) {
            throw new RuntimeException('Service monetary values cannot be negative.');
        }

        return round($number, 0);
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

    private function normalizeOptionalExchangeRate(string $value): ?float
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $rate = round((float) $normalized, 8);
        if ($rate <= 0.00000001) {
            throw new RuntimeException('Exchange rate must be greater than zero.');
        }

        return $rate;
    }
}
