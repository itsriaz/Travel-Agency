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
use App\Repositories\ServiceFinancialCorrectionRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TreasuryRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;
use Throwable;

final class ServiceWorkspaceService extends Service
{
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
                $serviceRow['latest_cancel_event_id'] = (int) ($latestCancelEvent['id'] ?? 0);
                $serviceRow['latest_cancel_customer_penalty_amount'] = (float) ($latestCancelPayload['customer_penalty_amount'] ?? $latestCancelEvent['penalty_amount'] ?? 0);
                $serviceRow['latest_cancel_agency_fee_refund_amount'] = (float) ($latestCancelPayload['agency_fee_refund_amount'] ?? 0);
                $serviceRow['latest_cancel_supplier_penalty_amount'] = (float) ($latestCancelPayload['supplier_penalty_amount'] ?? 0);
                $serviceRow['latest_cancel_expected_supplier_refund_amount'] = (float) (
                    $latestCancelPayload['expected_supplier_refund_amount']
                    ?? $latestCancelPayload['expected_supplier_refund_credit']
                    ?? $latestCancelEvent['supplier_credit_amount']
                    ?? 0
                );
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
                $supplierRefundState = $this->supplierRecoveryStateForService(
                    $serviceId,
                    $bookingReference,
                    (string) ($serviceRow['currency'] ?? 'PKR'),
                    $serviceObligation,
                    $eventRepository,
                    $serviceRow
                );
                $customerRefundState = $this->customerRefundStateForService(
                    $serviceId,
                    $bookingReference,
                    (string) ($serviceRow['currency'] ?? 'PKR'),
                    $latestCancelPayload,
                    $latestCancelEvent,
                    $eventRepository,
                    $customerRepository,
                    $supplierRefundState
                );
                $serviceRow['supplier_refundable_credit_amount'] = $supplierRefundState['remaining_expected_supplier_refund'];
                $serviceRow['customer_refundable_credit_amount'] = $customerRefundState['remaining_customer_refund_credit'];
                $serviceRow['customer_refund_received_amount'] = $customerRefundState['customer_refund_received_amount'];
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

            $this->assertNoProtectedFinancialChangeOnSavedService($input, $existingService);

            /** @var \PDO $db */
            $db = $this->app->get('db');
            $startedTransaction = ! $db->inTransaction();
            if ($startedTransaction) {
                $db->beginTransaction();
            }

            try {
                $supplierCorrection = $this->prepareSupplierCorrection(
                    $existingService,
                    $payload['master'],
                    $booking,
                    $actorUserId
                );

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

        $currentSnapshot = $this->serviceFinancialSnapshot($existingService);
        $correction = $this->validatedFinancialCorrectionPayload($input, $existingService, $currentSnapshot);

        if (! $correction['has_changes']) {
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

            $releasedCustomerCreditAmount = 0.0;
            $releasedSupplierCreditAmount = 0.0;

            if ($receivable !== null) {
                $allocatedAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);
                $receivableCurrencyChanged = strtoupper((string) ($receivable['currency'] ?? $invoiceCurrency)) !== strtoupper($newInvoiceCurrency);
                $targetReceivableAmount = $receivableCurrencyChanged ? 0.0 : $correction['financial']['final_sale_price'];
                if ($receivableCurrencyChanged || $targetReceivableAmount < $allocatedAmount - 0.005) {
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

            $serviceRepository->updateServiceFinancialFields(
                $serviceId,
                array_merge($correction['financial'], [
                    'service_type' => (string) ($existingService['service_type'] ?? 'air ticket'),
                    'remarks' => (string) ($existingService['remarks'] ?? ''),
                    'actor_user_id' => $actorUserId,
                ])
            );

            (new CommercialObligationSyncService($this->app))->syncForServiceId($serviceId, $actorUserId, [
                'financial_correction' => true,
                'entry_date' => $correction['correction_date'],
                'prior_invoice_currency' => $invoiceCurrency,
                'prior_cost_currency' => $costCurrency,
            ]);


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

            $updatedService = $serviceRepository->findServiceById($serviceId);

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

        foreach ($services as $service) {
            if ((int) ($service['is_active'] ?? 1) !== 1) {
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
        $customerRefundAmount = $this->moneyValue($input['customer_refund_amount'] ?? 0);
        $supplierRefundAmount = $this->moneyValue($input['supplier_refund_amount'] ?? 0);
        $refundEditScope = strtolower(trim((string) ($input['refund_edit_scope'] ?? '')));
        $refundEditMode = in_array($refundEditScope, ['customer', 'supplier'], true);

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

        if ($customerRefundAmount <= 0 && $supplierRefundAmount <= 0) {
            throw new RuntimeException('Enter a customer refund or supplier refund amount.');
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot refund services for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $serviceRepository = new BookingServiceRepository($this->app);
        $customerPaymentRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
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
        $customerRefundDetailPayload = $this->normalizedRefundDetailPayload(
            $input,
            $paymentMethod,
            (int) ($existingService['branch_id'] ?? 0),
            $currency,
            $customerRefundAmount,
            'refund_treasury_account_id',
            true
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
        $supplierId = (int) ($existingService['supplier_id'] ?? 0);

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
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

            $customerCreditBalance = $accountingRepository->accountNetBalanceForBooking($bookingReference, $currency, 'CUSTOMER_CREDIT');
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
            $supplierRefundableBalance = $supplierRefundState['remaining_expected_supplier_refund'];

            if ($customerRefundAmount > $customerCreditBalance + 0.005) {
                throw new RuntimeException('Customer refund exceeds available customer credit for this booking and currency.');
            }

            AuditLog::record($this->app, 'service.refund.credit_snapshot', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'currency' => $currency,
                'customer_credit_balance' => $customerCreditBalance,
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
                    'currency' => $currency,
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
                $customerJournalEntryId = $accountingRepository->postServiceRefund([
                    'branch_id' => (int) $existingService['branch_id'],
                    'booking_reference' => $bookingReference,
                    'service_line_reference' => (string) $existingService['line_reference'],
                    'source_reference' => 'REFUND-EVT-' . $customerEventId,
                    'entry_date' => $eventDate,
                    'currency' => $currency,
                    'payment_method' => $paymentMethod,
                    'treasury_account_id' => $customerRefundDetailPayload['treasury_account_id'] ?? null,
                    'customer_refund_amount' => $customerRefundAmount,
                    'supplier_refund_amount' => 0,
                    'narration' => 'Customer refund posted for ' . (string) $existingService['line_reference'],
                    'actor_user_id' => $actorUserId,
                ]);
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
                    $supplierJournalEntryId = $accountingRepository->postServiceRefund([
                        'branch_id' => (int) $existingService['branch_id'],
                        'booking_reference' => $bookingReference,
                        'service_line_reference' => (string) $existingService['line_reference'],
                        'source_reference' => 'REFUND-EVT-' . $supplierEventId,
                        'entry_date' => $eventDate,
                        'currency' => $currency,
                        'payment_method' => $supplierRefundPaymentMethod,
                        'treasury_account_id' => $supplierRefundDetailPayload['treasury_account_id'] ?? null,
                        'customer_refund_amount' => 0,
                        'supplier_refund_amount' => $supplierRefundAmount,
                        'narration' => 'Supplier refund received posted for ' . (string) $existingService['line_reference'],
                        'actor_user_id' => $actorUserId,
                    ]);
                    $eventRepository->attachJournalEntry($supplierEventId, $supplierJournalEntryId);
                    $cashRefundCreditConsumption = $supplierRepository->consumeRefundableCreditForService(
                        $supplierId,
                        $bookingReference,
                        $currency,
                        $supplierRefundAmount,
                        $actorUserId,
                        $reason,
                        (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
                    );
                    $eventRepository->updateRefundFinancials($supplierEventId, [
                        'payload_patch' => [
                            'cash_refund_credit_consumption' => $cashRefundCreditConsumption,
                        ],
                    ]);
                } else {
                    $retainedCreditConversion = $supplierRepository->convertRefundablePaymentCreditToAdvance(
                        $supplierId,
                        (int) ($existingService['branch_id'] ?? 0),
                        $bookingReference,
                        $currency,
                        $supplierRefundAmount,
                        $eventDate,
                        'REFUND-EVT-' . $supplierEventId,
                        $actorUserId,
                        $reason,
                        (array) ($cancelPayload['released_supplier_payment_ids'] ?? [])
                    );
                    $retainedCreditApplications = $this->applyRetainedSupplierCreditToOpenObligations(
                        $supplierRepository,
                        $accountingRepository,
                        (array) ($retainedCreditConversion['supplier_advance_ids'] ?? []),
                        $supplierId,
                        $currency,
                        $accessibleBranchIds,
                        $bookingReference,
                        $eventDate,
                        $supplierEventId,
                        $actorUserId
                    );
                    $retainedCreditConversion['automatic_applications'] = $retainedCreditApplications;
                    $retainedCreditConversion['automatic_applied_amount'] = round(array_sum(array_map(
                        static fn (array $row): float => (float) ($row['applied_amount'] ?? 0),
                        $retainedCreditApplications
                    )), 2);
                    $eventRepository->updateRefundFinancials($supplierEventId, [
                        'payload_patch' => ['retained_credit_conversion' => $retainedCreditConversion],
                    ]);
                }
                $createdRefundEvents[] = ['id' => $supplierEventId, 'journal_entry_id' => $supplierJournalEntryId, 'scope' => 'supplier'];
            }

            if ($customerRefundAmount > 0) {
                $customerPaymentRepository->applyRefundAgainstBookingReceiptCredit(
                    $bookingReference,
                    $currency,
                    $customerRefundAmount,
                    $actorUserId,
                    $reason
                );
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
                'currency' => $currency,
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
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
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

        $eventRepository = new BookingServiceEventRepository($this->app);
        $latestScopeEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, $refundScope);
        $latestScopeEventId = (int) ($latestScopeEvent['id'] ?? 0);
        if ($latestScopeEventId > 0 && $latestScopeEventId !== $refundEventId) {
            throw new RuntimeException($refundScope === 'customer'
                ? 'Reverse the latest posted customer refund for this service first.'
                : 'Reverse the latest posted supplier refund received for this service first.');
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

        if ($hasCustomerRefund && $hasSupplierRefund) {
            $selectedCustomerRefundAmount = $refundScope === 'customer' ? $customerRefundAmount : 0.0;
            $selectedSupplierRefundAmount = $refundScope === 'supplier' && ! $supplierRefundRetainedAsCredit
                ? $supplierRefundAmount
                : 0.0;

            if ($selectedCustomerRefundAmount > 0) {
                $restoredCustomerCredit = $customerPaymentRepository->restoreRefundToBookingReceiptCredit(
                    $bookingReference,
                    $currency,
                    $selectedCustomerRefundAmount,
                    $actorUserId,
                    $reason
                );
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

            $eventRepository->updateRefundFinancials($refundEventId, [
                'customer_refund_amount' => $refundScope === 'customer' ? 0 : $customerRefundAmount,
                'supplier_refund_amount' => $refundScope === 'supplier' ? 0 : $supplierRefundAmount,
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
                'reason' => $reason,
            ]);

            return;
        }

        if ($customerRefundAmount > 0) {
            $restoredCustomerCredit = $customerPaymentRepository->restoreRefundToBookingReceiptCredit(
                $bookingReference,
                $currency,
                $customerRefundAmount,
                $actorUserId,
                $reason
            );
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

    public function correctPenaltyRefundWorkflow(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $this->assertCanPostServiceEvent($actorUserId);

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $reason = $this->requiredText($input['correction_reason'] ?? null, 1000, 'Correction reason is required.');
        $eventDate = $this->normalizeOptionalDate((string) ($input['correction_event_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
        $customerRefundAmount = $this->moneyValue($input['customer_refund_amount'] ?? 0);
        $supplierRefundAmount = $this->moneyValue($input['supplier_refund_amount'] ?? 0);

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

        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $bookingReference = (string) ($booking['booking_reference'] ?? '');
            $oldCustomerPenaltyAmount = $this->moneyValue($cancelPayload['customer_penalty_amount'] ?? $cancelEvent['penalty_amount'] ?? 0);
            $oldAgencyFeeRefundAmount = $this->moneyValue($cancelPayload['agency_fee_refund_amount'] ?? 0);
            $oldExpectedSupplierRefundAmount = $this->moneyValue(
                $cancelPayload['expected_supplier_refund_amount']
                ?? $cancelPayload['expected_supplier_refund_credit']
                ?? $cancelEvent['supplier_credit_amount']
                ?? 0
            );
            $oldSupplierPenaltyAmount = $this->moneyValue($cancelPayload['supplier_penalty_amount'] ?? 0);
            if ($oldExpectedSupplierRefundAmount <= 0.005) {
                $oldExpectedSupplierRefundAmount = round(max(
                    (float) ($existingService['purchase_cost'] ?? 0) - $oldSupplierPenaltyAmount,
                    0
                ), 2);
            }
            $newCustomerPenaltyAmount = $this->moneyValue($input['customer_penalty_amount'] ?? 0);
            $newAgencyFeeRefundAmount = $this->moneyValue($input['agency_fee_refund_amount'] ?? 0);
            $newExpectedSupplierRefundAmount = $this->moneyValue($input['expected_supplier_refund_amount'] ?? 0);
            $settlementValuesChanged = abs($oldCustomerPenaltyAmount - $newCustomerPenaltyAmount) > 0.005
                || abs($oldAgencyFeeRefundAmount - $newAgencyFeeRefundAmount) > 0.005
                || abs($oldExpectedSupplierRefundAmount - $newExpectedSupplierRefundAmount) > 0.005;

            $latestCustomerRefundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, 'customer');
            $latestSupplierRefundEvent = $this->latestRefundEventForScope($eventRepository, $serviceId, 'supplier');
            if ($latestCustomerRefundEvent === null && $customerRefundAmount > 0.005) {
                throw new RuntimeException('Customer refund has not been paid yet. Use Pay Customer Refund to record the payment first.');
            }
            if ($latestSupplierRefundEvent === null && $supplierRefundAmount > 0.005) {
                throw new RuntimeException('Supplier refund has not been received yet. Use Record Supplier Refund to post it first.');
            }
            $oldCustomerRefundAmount = $this->moneyValue($latestCustomerRefundEvent['customer_refund_amount'] ?? 0);
            $oldSupplierRefundAmount = $this->moneyValue($latestSupplierRefundEvent['supplier_refund_amount'] ?? 0);
            $refundValuesChanged = abs($oldCustomerRefundAmount - $customerRefundAmount) > 0.005
                || abs($oldSupplierRefundAmount - $supplierRefundAmount) > 0.005;
            $hasPostedRefund = $latestCustomerRefundEvent !== null || $latestSupplierRefundEvent !== null;
            if ($hasPostedRefund && $refundValuesChanged) {
                app_write_log('service.penalty_refund.refund_edit_mode_forced', 'Posted refund correction detected; settlement rebuild suppressed so only refund values are corrected.', array_merge(app_request_log_context(), [
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'service_id' => $serviceId,
                    'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                    'old_customer_penalty_amount' => $oldCustomerPenaltyAmount,
                    'new_customer_penalty_amount' => $newCustomerPenaltyAmount,
                    'old_expected_supplier_refund_amount' => $oldExpectedSupplierRefundAmount,
                    'new_expected_supplier_refund_amount' => $newExpectedSupplierRefundAmount,
                    'old_customer_refund_amount' => $oldCustomerRefundAmount,
                    'new_customer_refund_amount' => $customerRefundAmount,
                    'old_supplier_refund_amount' => $oldSupplierRefundAmount,
                    'new_supplier_refund_amount' => $supplierRefundAmount,
                ]));
                $settlementValuesChanged = false;
            }

            foreach (['customer', 'supplier'] as $refundScope) {
                $refundEvent = $refundScope === 'customer' ? $latestCustomerRefundEvent : $latestSupplierRefundEvent;
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
                $this->settleCancellationFinancials([
                    'booking_id' => $bookingId,
                    'service_id' => $serviceId,
                    'settlement_edit_mode' => 1,
                    'settlement_event_date' => $eventDate,
                    'customer_penalty_amount' => $input['customer_penalty_amount'] ?? 0,
                    'expected_supplier_refund_amount' => $input['expected_supplier_refund_amount'] ?? 0,
                    'agency_fee_refund_amount' => $input['agency_fee_refund_amount'] ?? 0,
                    'settlement_reason' => $reason,
                    'settlement_notes' => 'Penalty/refund correction saved by user.',
                ], $actorUserId, $accessibleBranchIds);
            } else {
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

            if ($customerRefundAmount > 0.005 || $supplierRefundAmount > 0.005) {
                $refundInput = $input;
                $refundInput['refund_event_date'] = $eventDate;
                $refundInput['refund_reason'] = $reason;
                $refundInput['refund_notes'] = 'Penalty/refund correction saved by user.';
                $this->refundService($refundInput, $actorUserId, $accessibleBranchIds, true);
            }

            AuditLog::record($this->app, 'service.penalty_refund.corrected', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'service_id' => $serviceId,
                'service_line_reference' => (string) ($existingService['line_reference'] ?? ''),
                'currency' => (string) ($existingService['currency'] ?? 'PKR'),
                'customer_penalty_amount' => $this->moneyValue($input['customer_penalty_amount'] ?? 0),
                'agency_fee_refund_amount' => $this->moneyValue($input['agency_fee_refund_amount'] ?? 0),
                'expected_supplier_refund_amount' => $this->moneyValue($input['expected_supplier_refund_amount'] ?? 0),
                'customer_refund_amount' => $customerRefundAmount,
                'supplier_refund_amount' => $supplierRefundAmount,
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
        $releasedSupplierCredit = round((float) ($cancelPayload['released_supplier_credit'] ?? $cancelPayload['expected_supplier_refund_credit'] ?? $cancelEvent['supplier_credit_amount'] ?? 0), 2);
        $supplierCostBasis = round((float) ($cancelPayload['supplier_cost_basis'] ?? $existingService['purchase_cost'] ?? 0), 2);
        $journalEntryIds = array_values(array_filter(array_map('intval', (array) ($cancelPayload['journal_entry_ids'] ?? [])), static fn (int $id): bool => $id > 0));

        $customerRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);

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

        $reversalJournalEntryIds = [];
        foreach (array_reverse($journalEntryIds) as $journalEntryId) {
            $reversalJournalEntryId = $accountingRepository->reverseJournalEntry($journalEntryId, [
                'branch_id' => (int) ($existingService['branch_id'] ?? 0),
                'booking_reference' => $bookingReference,
                'source_type' => 'service_cancellation_financials_reversed',
                'source_reference' => $lineReference . '-CANCEL-REV-' . $journalEntryId,
                'entry_date' => $eventDate,
                'currency' => $currency,
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
                $receiptUnallocated = round((float) ($receiptRow['unallocated_amount'] ?? 0), 2);
                if ($receiptId <= 0 || $receiptUnallocated <= 0.005) {
                    continue;
                }

                $allocationAmount = round(min($remainingCustomerRestore, $receiptUnallocated), 2);
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

            if ($remainingCustomerRestore > 0.005) {
                throw new RuntimeException('Customer credit released by the cancellation settlement could not be fully restored.');
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
                'currency' => $currency,
                'gross_amount' => $supplierCostBasis,
                'due_date' => $existingService['due_date'] ?? null,
                'remarks' => 'Cancellation settlement reversed [' . $lineReference . ']',
                'actor_user_id' => $actorUserId,
            ]);

            $restoredObligationId = (int) ($supplierSync['record']['id'] ?? $obligation['id'] ?? 0);
            $supplierId = (int) ($existingService['supplier_id'] ?? $obligation['supplier_id'] ?? 0);
            if ($releasedSupplierCredit > 0.005 && $restoredObligationId > 0 && $supplierId > 0) {
                $remainingSupplierRestore = $releasedSupplierCredit;
                $paymentRows = $supplierRepository->availablePaymentRowsForBooking($supplierId, $bookingReference, $currency);
                foreach ($paymentRows as $paymentRow) {
                    if ($remainingSupplierRestore <= 0.005) {
                        break;
                    }

                    $paymentId = (int) ($paymentRow['id'] ?? 0);
                    $paymentUnallocated = round((float) ($paymentRow['unallocated_amount'] ?? 0), 2);
                    if ($paymentId <= 0 || $paymentUnallocated <= 0.005) {
                        continue;
                    }

                    $allocationAmount = round(min($remainingSupplierRestore, $paymentUnallocated), 2);
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
                    $advanceRows = $supplierRepository->availableAdvanceRowsForSupplier($supplierId, $currency);
                    foreach ($advanceRows as $advanceRow) {
                        if ($remainingSupplierRestore <= 0.005) {
                            break;
                        }

                        $advanceId = (int) ($advanceRow['id'] ?? 0);
                        $advanceAvailable = round((float) ($advanceRow['available_amount'] ?? 0), 2);
                        if ($advanceId <= 0 || $advanceAvailable <= 0.005) {
                            continue;
                        }

                        $appliedAmount = $supplierRepository->applyAdvanceToObligation(
                            $advanceId,
                            $restoredObligationId,
                            min($remainingSupplierRestore, $advanceAvailable),
                            $actorUserId
                        );
                        $remainingSupplierRestore = round($remainingSupplierRestore - $appliedAmount, 2);
                    }
                }

                if ($remainingSupplierRestore > 0.005) {
                    throw new RuntimeException('Supplier credit released by the cancellation settlement could not be fully restored.');
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
        $customerPenaltyAmount = $this->moneyValue($input['customer_penalty_amount'] ?? 0);
        $agencyFeeRefundAmount = $this->moneyValue($input['agency_fee_refund_amount'] ?? 0);
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
        $agencyFeeChargedAmount = round(max((float) ($existingService['service_charge'] ?? 0), 0.0), 2);
        if ($agencyFeeRefundAmount > $agencyFeeChargedAmount + 0.005) {
            throw new RuntimeException('Agency fee refund cannot exceed the agency service fee charged on this service.');
        }
        $customerRepository = new CustomerPaymentRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
        $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);

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
                    round((float) ($cancelPayload['released_supplier_credit'] ?? $cancelPayload['expected_supplier_refund_credit'] ?? $cancelEvent['supplier_credit_amount'] ?? 0), 2),
                    round((float) ($cancelPayload['supplier_cost_basis'] ?? $existingService['purchase_cost'] ?? 0), 2),
                    array_values(array_filter(array_map('intval', (array) ($cancelPayload['journal_entry_ids'] ?? [])), static fn (int $id): bool => $id > 0))
                );

                $receivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference);
                $obligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference);
            }

            $allocatedCustomerAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);
            $currentCustomerDueAmount = round((float) ($receivable['due_amount'] ?? 0), 2);
            $serviceCustomerSaleBasis = round((float) (
                $existingService['final_sale_price']
                ?? $existingService['sale_price']
                ?? $currentCustomerDueAmount
            ), 2);
            $customerRefundBasis = $allocatedCustomerAmount > 0.005
                ? $allocatedCustomerAmount
                : max($serviceCustomerSaleBasis, $currentCustomerDueAmount, 0.0);
            $supplierCostBasis = round((float) ($existingService['purchase_cost'] ?? 0), 2);
            if ($hasExpectedSupplierRefundInput) {
                $expectedSupplierRefundAmount = min(
                    max($this->moneyValue($input['expected_supplier_refund_amount'] ?? 0), 0.0),
                    max($supplierCostBasis, 0.0)
                );
                $supplierPenaltyAmount = round(max($supplierCostBasis - $expectedSupplierRefundAmount, 0), 2);
                $targetCustomerRefundCredit = round(max(
                    $expectedSupplierRefundAmount - $customerPenaltyAmount + $agencyFeeRefundAmount,
                    0
                ), 2);
                $customerFinalChargeAmount = round(max($customerRefundBasis - $targetCustomerRefundCredit, 0), 2);
            } else {
                $supplierPenaltyAmount = $this->moneyValue($input['supplier_penalty_amount'] ?? 0);
                $expectedSupplierRefundAmount = $supplierCostBasis > 0
                    ? round(max($supplierCostBasis - $supplierPenaltyAmount, 0), 2)
                    : 0.0;
                $targetCustomerRefundCredit = round(max($customerRefundBasis - ($customerPenaltyAmount + $supplierPenaltyAmount), 0), 2);
                $customerFinalChargeAmount = round(max($customerPenaltyAmount + $supplierPenaltyAmount, 0), 2);
            }

            $supplierSettledAmount = 0.0;
            if ($obligation !== null) {
                $supplierSettledAmount = round((float) ($obligation['gross_amount'] ?? 0) - (float) ($obligation['net_payable_amount'] ?? 0), 2);
            }

            $accountingRepository = new AccountingRepository($this->app);
            $releasedCustomerCredit = 0.0;
            $releasedSupplierCredit = 0.0;
            $customerReleaseResult = null;
            $supplierReleaseResult = null;
            if ($receivable !== null && $customerFinalChargeAmount < $allocatedCustomerAmount - 0.005) {
                $customerReleaseResult = $customerRepository->releaseAllocatedCreditForReceivable(
                    (int) $receivable['id'],
                    $customerFinalChargeAmount,
                    $actorUserId,
                    'Cancellation settlement release for ' . $lineReference
                );
                $releasedCustomerCredit = round((float) ($customerReleaseResult['released_payment_amount'] ?? 0), 2);
            }
            if ($obligation !== null && $supplierPenaltyAmount < $supplierSettledAmount - 0.005) {
                $supplierReleaseResult = $supplierRepository->releaseSettledCreditForObligation(
                    (int) $obligation['id'],
                    $supplierPenaltyAmount,
                    $actorUserId,
                    'Cancellation settlement release for ' . $lineReference
                );
                $releasedSupplierCredit = round((float) ($supplierReleaseResult['released_amount'] ?? 0), 2);
            }

            $expectedSupplierRefundCredit = $releasedSupplierCredit;
            $supplierBookingPaymentCredit = 0.0;
            $maxSupplierRefundCredit = $expectedSupplierRefundAmount > 0
                ? $expectedSupplierRefundAmount
                : 0.0;
            if ($obligation !== null && (int) ($obligation['supplier_id'] ?? 0) > 0) {
                $supplierBookingPaymentCredit = $supplierRepository->availableRefundablePaymentCreditForBooking(
                    (int) $obligation['supplier_id'],
                    $bookingReference,
                    $currency
                );
            }
            if ($supplierSettledAmount > 0.005) {
                $expectedSupplierRefundCredit = max(
                    $expectedSupplierRefundCredit,
                    round(max($supplierSettledAmount - $supplierPenaltyAmount, 0), 2)
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
                && $expectedSupplierRefundAmount > 0.005
                && (
                    $supplierSettledAmount > 0.005
                    || $releasedSupplierCredit > 0.005
                    || $supplierBookingPaymentCredit > 0.005
                )
            ) {
                $expectedSupplierRefundCredit = max($expectedSupplierRefundCredit, $expectedSupplierRefundAmount);
            }
            if ($maxSupplierRefundCredit > 0.0) {
                $expectedSupplierRefundCredit = min($expectedSupplierRefundCredit, $maxSupplierRefundCredit);
            }
            $expectedSupplierRefundCredit = round(max($expectedSupplierRefundCredit, 0), 2);
            $customerCreditBalanceAfterSettlement = round(max(
                $customerRepository->bookingUnallocatedCreditTotal($bookingReference, $currency),
                0
            ), 2);
            $availableCustomerRefundCredit = round(min(
                max($targetCustomerRefundCredit, 0),
                $customerCreditBalanceAfterSettlement
            ), 2);

            $customerSync = $customerRepository->syncReceivableItem([
                'branch_id' => (int) $existingService['branch_id'],
                'booking_reference' => $bookingReference,
                'service_line_reference' => $lineReference,
                'due_group' => 'service_sale',
                'currency' => $currency,
                'due_amount' => $customerFinalChargeAmount,
                'due_date' => $existingService['due_date'] ?? null,
                'status' => $customerFinalChargeAmount > 0 ? 'open' : 'cancelled',
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
                'customer_credit_amount' => $availableCustomerRefundCredit,
                'supplier_credit_amount' => $expectedSupplierRefundCredit,
                'journal_entry_id' => $journalIds[0] ?? null,
                'reason' => $reason,
                'notes' => $this->optionalText($input['settlement_notes'] ?? null, 4000),
                'payload_json' => [
                    'customer_penalty_amount' => $customerPenaltyAmount,
                    'agency_fee_charged_amount' => $agencyFeeChargedAmount,
                    'agency_fee_refund_amount' => $agencyFeeRefundAmount,
                    'agency_fee_retained_amount' => round(max($agencyFeeChargedAmount - $agencyFeeRefundAmount, 0), 2),
                    'supplier_penalty_amount' => $supplierPenaltyAmount,
                    'expected_supplier_refund_amount' => $expectedSupplierRefundAmount,
                    'customer_final_charge_amount' => $customerFinalChargeAmount,
                    'released_customer_credit' => $releasedCustomerCredit,
                    'available_customer_refund_credit' => $availableCustomerRefundCredit,
                    'released_supplier_credit' => $releasedSupplierCredit,
                    'expected_supplier_refund_credit' => $expectedSupplierRefundCredit,
                    'supplier_booking_payment_credit' => $supplierBookingPaymentCredit,
                    'supplier_cost_basis' => $supplierCostBasis,
                    'customer_refund_basis' => $customerRefundBasis,
                    'customer_received_amount' => $allocatedCustomerAmount,
                    'target_customer_refund_credit' => $targetCustomerRefundCredit,
                    'customer_delta' => $customerDelta,
                    'supplier_delta' => $supplierDelta,
                    'prior_customer_due_amount' => $currentCustomerDueAmount,
                    'prior_supplier_obligation_gross_amount' => (float) ($obligation['gross_amount'] ?? 0),
                    'released_customer_receipt_ids' => array_values(array_map('intval', (array) ($customerReleaseResult['affected_receipt_ids'] ?? []))),
                    'released_supplier_payment_credit' => (float) ($supplierReleaseResult['released_payment_amount'] ?? 0),
                    'released_supplier_advance_credit' => (float) ($supplierReleaseResult['released_advance_amount'] ?? 0),
                    'released_supplier_payment_ids' => array_values(array_map('intval', (array) ($supplierReleaseResult['affected_payment_ids'] ?? []))),
                    'released_supplier_advance_ids' => array_values(array_map('intval', (array) ($supplierReleaseResult['affected_advance_ids'] ?? []))),
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
                'expected_supplier_refund_amount' => $expectedSupplierRefundAmount,
                'customer_final_charge_amount' => $customerFinalChargeAmount,
                'released_customer_credit' => $releasedCustomerCredit,
                'available_customer_refund_credit' => $availableCustomerRefundCredit,
                'released_supplier_credit' => $releasedSupplierCredit,
                'expected_supplier_refund_credit' => $expectedSupplierRefundCredit,
                'customer_credit_balance_after_settlement' => $customerCreditBalanceAfterSettlement,
                'supplier_booking_payment_credit' => $supplierBookingPaymentCredit,
                'supplier_cost_basis' => $supplierCostBasis,
                'customer_refund_basis' => $customerRefundBasis,
                'customer_received_amount' => $allocatedCustomerAmount,
                'target_customer_refund_credit' => $targetCustomerRefundCredit,
                'customer_delta' => $customerDelta,
                'supplier_delta' => $supplierDelta,
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
        $supplierCustomerComponent = round($supplierCostDifferenceAmount * $supplierToCustomerRate, 2);
        $agencyCustomerComponent = round($serviceFeeAmount * $agencyToCustomerRate, 2);
        $customerDelta = round($supplierCustomerComponent + $agencyCustomerComponent, 2);
        $submittedCustomerAmount = $this->moneyValue($input['reissue_customer_amount'] ?? $customerDelta);
        if (abs($submittedCustomerAmount - $customerDelta) > 0.01) {
            throw new RuntimeException('The reissue customer amount changed after exchange conversion. Review the currencies and confirm the rates again.');
        }
        $supplierMirrorIncrement = round($supplierCostDifferenceAmount * $this->reissueExchangeRate($supplierCurrency, $costCurrency, $rateEffectiveDate, $supplierCostDifferenceAmount), 2);
        $agencyMirrorIncrement = round($serviceFeeAmount * $this->reissueExchangeRate($agencyFeeCurrency, $serviceChargeCurrency, $rateEffectiveDate, $serviceFeeAmount), 2);
        $customerMirrorIncrement = round($customerDelta * $this->reissueExchangeRate($customerCurrency, $invoiceCurrency, $rateEffectiveDate, $customerDelta), 2);

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
                        $targetAmount = round(min($outstanding, $receivedAmount * $receiveToCustomerRate), 2);
                        $paymentConsumed = $targetAmount >= $outstanding - 0.005
                            ? round(min($receivedAmount, $targetAmount * $customerToReceiveRate), 2)
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

    private function validatedPayload(array $input, array $accessibleBranchIds, int $defaultBranchId, int $bookingId, int $actorUserId, BookingServiceRepository $repository): array
    {
        $serviceType = $this->normalizeServiceType((string) ($input['service_type'] ?? 'air ticket'));
        $currency = $this->normalizeCurrency((string) ($input['currency'] ?? 'PKR'));
        $costCurrency = $this->normalizeCurrency((string) ($input['cost_currency'] ?? $currency));
        $serviceChargeCurrency = $this->normalizeCurrency((string) (
            $input['service_charge_currency']
            ?? ($serviceType === 'air ticket' ? $costCurrency : $currency)
        ));
        $status = $this->normalizeStatus((string) ($input['service_status'] ?? 'Open'));
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
            'service_charge' => $this->moneyValue($input['service_charge'] ?? 0),
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

        $master['pricing_rate_effective_date'] = $this->resolvePricingRateEffectiveDate($input, $master['due_date']);
        $master['pricing_exchange_rate'] = $this->resolvePricingExchangeRate($master, $input);
        $master['service_charge_rate_effective_date'] = $this->resolveServiceChargeRateEffectiveDate($input, $master['pricing_rate_effective_date']);
        $master['service_charge_exchange_rate'] = $this->resolveServiceChargeExchangeRate($master, $input);
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
                'loss_reason_recorded_at' => $lossReason !== null ? date('Y-m-d H:i:s') : null,
            ],
            'subtype' => $this->validatedSubtypePayload($input, $serviceType, $master),
        ];
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

            $customerBase = $this->customerPricingBaseAmount($financial);
            $financial['service_charge_exchange_rate'] = $this->resolveCorrectionServiceChargeExchangeRate($financial);
            $financial['final_sale_price'] = round($customerBase + $this->customerAgencyComponentAmount($financial), 2);
        } else {
            $newFinalSalePrice = array_key_exists('corrected_final_sale_price', $input) && trim((string) $input['corrected_final_sale_price']) !== ''
                ? $this->moneyValue($input['corrected_final_sale_price'])
                : $currentSnapshot['final_sale_price'];
            $financial['final_sale_price'] = $newFinalSalePrice;
            $financial['pricing_exchange_rate'] = $this->resolveCorrectionPricingExchangeRate($financial);
            $financial['service_charge_exchange_rate'] = $this->resolveCorrectionServiceChargeExchangeRate($financial);
            $customerBase = $this->customerPricingBaseAmount($financial);
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

    private function resolvePricingRateEffectiveDate(array $input, ?string $fallbackDate = null): string
    {
        $postedDate = $this->normalizeOptionalDate((string) ($input['pricing_rate_effective_date'] ?? ''));
        if ($postedDate !== null) {
            return $postedDate;
        }

        if ($fallbackDate !== null && trim($fallbackDate) !== '') {
            return (string) $fallbackDate;
        }

        return date('Y-m-d');
    }

    private function resolvePricingExchangeRate(array $master, array $input): float
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

    private function resolveServiceChargeRateEffectiveDate(array $input, string $fallbackDate): string
    {
        $postedDate = $this->normalizeOptionalDate((string) ($input['service_charge_rate_effective_date'] ?? ''));

        return $postedDate ?? $fallbackDate;
    }

    private function resolveServiceChargeExchangeRate(array $master, array $input): float
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
            return $this->moneyValue($rawFinalSalePrice);
        }

        return round(
            $this->customerPricingBaseAmount($master)
            + $this->customerAgencyComponentAmount($master),
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

    private function applyRetainedSupplierCreditToOpenObligations(
        SupplierRepository $supplierRepository,
        AccountingRepository $accountingRepository,
        array $advanceIds,
        int $supplierId,
        string $currency,
        array $accessibleBranchIds,
        string $sourceBookingReference,
        string $eventDate,
        int $supplierRefundEventId,
        int $actorUserId
    ): array {
        $advanceIds = array_values(array_unique(array_filter(
            array_map('intval', $advanceIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($advanceIds === [] || $supplierId <= 0) {
            return [];
        }

        $obligations = $supplierRepository->openObligationsForSupplierCreditApplication(
            $supplierId,
            $currency,
            $accessibleBranchIds,
            $sourceBookingReference
        );
        $applications = [];

        foreach ($advanceIds as $advanceId) {
            foreach ($obligations as $obligationRow) {
                $advance = $supplierRepository->findAdvanceById($advanceId);
                $availableAmount = round((float) ($advance['available_amount'] ?? 0), 2);
                if ($availableAmount <= 0.005) {
                    break;
                }

                $obligationId = (int) ($obligationRow['id'] ?? 0);
                $obligation = $supplierRepository->findObligationById($obligationId);
                $outstandingAmount = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
                if ($obligationId <= 0 || $outstandingAmount <= 0.005) {
                    continue;
                }

                $appliedAmount = $supplierRepository->applyAdvanceToObligationWithType(
                    $advanceId,
                    $obligationId,
                    min($availableAmount, $outstandingAmount),
                    'same_currency_auto',
                    $actorUserId
                );
                if ($appliedAmount <= 0.005) {
                    continue;
                }

                $accountingRepository->postSupplierAdvanceApplication([
                    'branch_id' => (int) ($obligation['branch_id'] ?? 0),
                    'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                    'source_reference' => 'REFUND-EVT-' . $supplierRefundEventId . '-ADV-' . $advanceId . '-OBL-' . $obligationId,
                    'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== ''
                        ? (string) $obligation['service_line_reference']
                        : null,
                    'supplier_obligation_id' => $obligationId,
                    'amount' => $appliedAmount,
                    'entry_date' => $eventDate,
                    'currency' => $currency,
                    'narration' => 'Supplier refund credit automatically applied to existing payable',
                    'actor_user_id' => $actorUserId,
                ]);

                $applications[] = [
                    'supplier_advance_id' => $advanceId,
                    'supplier_obligation_id' => $obligationId,
                    'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                    'service_line_reference' => (string) ($obligation['service_line_reference'] ?? ''),
                    'currency' => $currency,
                    'applied_amount' => round($appliedAmount, 2),
                ];
            }
        }

        AuditLog::record($this->app, 'supplier.refund.retained_credit_auto_applied', [
            'user_id' => $actorUserId,
            'supplier_id' => $supplierId,
            'source_booking_reference' => $sourceBookingReference,
            'supplier_refund_event_id' => $supplierRefundEventId,
            'currency' => $currency,
            'supplier_advance_ids' => $advanceIds,
            'application_count' => count($applications),
            'applied_amount' => round(array_sum(array_map(
                static fn (array $row): float => (float) ($row['applied_amount'] ?? 0),
                $applications
            )), 2),
            'applications' => $applications,
        ]);

        return $applications;
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
        bool $requireCustomerBankDestination = true
    ): array {
        $treasuryAccountId = 0;
        $treasurySnapshot = null;

        if ($refundAmount > 0.005 && in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
            $treasuryAccountId = (int) ($input[$treasuryAccountField] ?? 0);
            $treasurySnapshot = (new TreasuryRepository($this->app))->validatePaymentTreasuryAccount(
                $treasuryAccountId,
                $branchId,
                $currency,
                $paymentMethod,
                $requireCustomerBankDestination && $paymentMethod === 'cash'
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
            'customer_bank_name' => $customerBankName,
            'customer_bank_account_title' => $customerBankAccountTitle,
            'customer_bank_account_no' => $customerBankAccountNo,
            'customer_bank_iban' => $customerBankIban,
            'transfer_reference' => $transferReference,
            'charges' => $charges,
            'remarks' => $remarks,
            'treasury_account_snapshot' => $treasurySnapshot !== null ? [
                'id' => (int) ($treasurySnapshot['id'] ?? 0),
                'account_name' => (string) ($treasurySnapshot['account_name'] ?? ''),
                'account_code' => (string) ($treasurySnapshot['account_code'] ?? ''),
                'account_type' => (string) ($treasurySnapshot['account_type'] ?? ''),
                'currency' => (string) ($treasurySnapshot['currency'] ?? $currency),
            ] : null,
        ];
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

        $supplierSettledAmount = 0.0;
        $supplierPenaltyAmount = 0.0;
        $supplierCostBasis = round((float) ($cancelPayload['supplier_cost_basis'] ?? $serviceRow['purchase_cost'] ?? 0), 2);

        if ($obligation !== null) {
            $grossAmount = round((float) ($obligation['gross_amount'] ?? 0), 2);
            $netPayableAmount = round((float) ($obligation['net_payable_amount'] ?? 0), 2);
            $supplierSettledAmount = round(max($grossAmount - $netPayableAmount, 0), 2);
            $supplierPenaltyAmount = round((float) ($cancelPayload['supplier_penalty_amount'] ?? $grossAmount), 2);
            if ($supplierCostBasis <= 0.005) {
                $supplierCostBasis = $grossAmount;
            }
        } else {
            $supplierPenaltyAmount = round((float) ($cancelPayload['supplier_penalty_amount'] ?? 0), 2);
        }

        $expectedSupplierRefundAmount = round((float) (
            $cancelPayload['expected_supplier_refund_amount']
            ?? 0
        ), 2);
        if ($expectedSupplierRefundAmount <= 0.005 && $supplierCostBasis > 0.005) {
            $expectedSupplierRefundAmount = round(max($supplierCostBasis - $supplierPenaltyAmount, 0), 2);
        }

        $releasedSupplierCreditAmount = round((float) (
            $cancelPayload['expected_supplier_refund_credit']
            ?? $cancelPayload['released_supplier_credit']
            ?? $cancelEvent['supplier_credit_amount']
            ?? 0
        ), 2);
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
            $repositoryCreditBalance = (new SupplierRepository($this->app))->availableRefundableCreditForService(
                $supplierId,
                $bookingReference,
                $currency
            );
        }

        $expectedSupplierRefund = round(max($expectedSupplierRefundAmount, $releasedSupplierCreditAmount, $repositoryCreditBalance, 0), 2);
        $remainingExpectedSupplierRefund = round(max($expectedSupplierRefund - $supplierRefundReceivedAmount, 0), 2);

        return [
            'expected_supplier_refund' => $expectedSupplierRefund,
            'supplier_refund_received_amount' => $supplierRefundReceivedAmount,
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
     *   remaining_customer_refund_credit: float
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
        $releasedCustomerCreditBasis = round((float) (
            $hasModernAvailableCredit
                ? $cancelPayload['available_customer_refund_credit']
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

        return [
            'released_customer_credit' => $releasedCustomerCredit,
            'customer_refund_received_amount' => $customerRefundReceivedAmount,
            'remaining_customer_refund_credit' => round(min($unsettledReleasedCredit, $currentBookingCredit), 2),
        ];
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
}
