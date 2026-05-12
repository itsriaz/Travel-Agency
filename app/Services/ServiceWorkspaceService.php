<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;

final class ServiceWorkspaceService extends Service
{
    private const ALLOWED_SERVICE_TYPES = ['air ticket', 'visa', 'umrah', 'hotel', 'transport', 'tourism', 'other'];
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_STATUSES = ['Booked', 'Docs Pending', 'Reserved', 'Open', 'Delivered', 'Cancelled'];

    public function serviceState(?int $bookingId, array $accessibleBranchIds): array
    {
        $services = [];
        $suppliers = [];

        if ($bookingId !== null && $bookingId > 0) {
            $bookingRepository = new BookingRepository($this->app);
            if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected booking is outside your accessible branches.');
            }

            $services = (new BookingServiceRepository($this->app))->servicesForBooking($bookingId);
            $suppliers = (new SupplierRepository($this->app))->activeSuppliersForBranches($accessibleBranchIds);
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
                $payload['airTicket']
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
            $payload['airTicket']
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

        $serviceRepository->deactivateService($serviceId, $actorUserId);
        (new CommercialObligationSyncService($this->app))->syncForServiceId($serviceId, $actorUserId);

        AuditLog::record($this->app, 'service.deactivated', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'service_id' => $serviceId,
        ]);

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
            'airTicket' => $this->validatedAirTicketPayload($input, $serviceType, $master),
        ];
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
                'full_name' => $this->optionalText($passengerName, 190),
                'passport_number' => null,
                'nationality' => null,
                'date_of_birth' => null,
                'gender' => 'unspecified',
                'passport_expiry' => null,
                'mobile' => null,
                'address' => null,
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
