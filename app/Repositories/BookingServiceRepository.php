<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingServiceRepository extends BaseRepository
{
    private const SUBTYPE_TABLES = [
        'air ticket' => 'service_air_ticket',
        'visa' => 'service_visa',
        'umrah' => 'service_umrah',
        'hotel' => 'service_hotel',
        'transport' => 'service_transport',
        'tourism' => 'service_tour',
        'other' => 'service_other',
    ];

    public function servicesForBooking(int $bookingId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                bs.id,
                bs.booking_id,
                bs.branch_id,
                bs.line_reference,
                bs.display_order,
                bs.service_type,
                bs.supplier_id,
                COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                bs.traveler_id,
                COALESCE(t.full_name, bs.passenger_name_snapshot) AS passenger_name,
                bs.currency,
                bs.cost_currency,
                bs.sale_price,
                bs.purchase_cost,
                bs.pricing_exchange_rate,
                bs.pricing_rate_effective_date,
                bs.taxes,
                bs.other_fare,
                bs.soto_fare,
                bs.spyi_amount,
                bs.aq_yr_pk_amount,
                bs.yq_amount,
                bs.oth_amount,
                bs.vat_input,
                bs.vat,
                bs.commission,
                bs.service_charge,
                bs.service_charge_currency,
                bs.service_charge_exchange_rate,
                bs.service_charge_rate_effective_date,
                bs.discount_amount,
                bs.final_sale_price,
                bs.net_profit_loss,
                bs.due_date,
                bs.service_status,
                bs.remarks,
                bs.loss_reason,
                bs.is_active,
                sat.pnr,
                sat.ticket_number,
                sat.airline,
                sat.sector_from,
                sat.sector_to,
                sat.departure_date,
                sat.return_date,
                sat.travel_class,
                sat.fare,
                sat.ticket_tax,
                sat.ticket_vat,
                sat.ticket_commission,
                sat.supplier_cost,
                sat.sale_amount,
                sat.ticket_remarks,
                sv.visa_country,
                sv.visa_type,
                sv.application_reference AS visa_application_reference,
                sv.passport_number AS visa_passport_number,
                sv.submission_date AS visa_submission_date,
                sv.issue_date AS visa_issue_date,
                sv.expiry_date AS visa_expiry_date,
                sv.visa_status,
                sv.remarks AS visa_remarks,
                su.package_name AS umrah_package_name,
                su.mofa_reference AS umrah_mofa_reference,
                su.departure_date AS umrah_departure_date,
                su.return_date AS umrah_return_date,
                su.hotel_name AS umrah_hotel_name,
                su.transport_notes AS umrah_transport_notes,
                su.remarks AS umrah_remarks,
                sh.hotel_name,
                sh.city AS hotel_city,
                sh.confirmation_number AS hotel_confirmation_number,
                sh.check_in_date AS hotel_check_in_date,
                sh.check_out_date AS hotel_check_out_date,
                sh.room_type AS hotel_room_type,
                sh.guest_count AS hotel_guest_count,
                sh.remarks AS hotel_remarks,
                st.transport_mode,
                st.vehicle_type AS transport_vehicle_type,
                st.pickup_date AS transport_pickup_date,
                st.pickup_location AS transport_pickup_location,
                st.dropoff_location AS transport_dropoff_location,
                st.driver_detail AS transport_driver_detail,
                st.route_notes AS transport_route_notes,
                st.remarks AS transport_remarks,
                sto.tour_name,
                sto.destination AS tour_destination,
                sto.confirmation_number AS tour_confirmation_number,
                sto.start_date AS tour_start_date,
                sto.end_date AS tour_end_date,
                sto.inclusions AS tour_inclusions,
                sto.remarks AS tour_remarks,
                so.label AS other_label,
                so.reference_number AS other_reference_number,
                so.service_date AS other_service_date,
                so.provider_name AS other_provider_name,
                so.remarks AS other_remarks
             FROM booking_services bs
             LEFT JOIN suppliers s ON s.id = bs.supplier_id
             LEFT JOIN travelers t ON t.id = bs.traveler_id
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
             LEFT JOIN service_visa sv ON sv.booking_service_id = bs.id
             LEFT JOIN service_umrah su ON su.booking_service_id = bs.id
             LEFT JOIN service_hotel sh ON sh.booking_service_id = bs.id
             LEFT JOIN service_transport st ON st.booking_service_id = bs.id
             LEFT JOIN service_tour sto ON sto.booking_service_id = bs.id
             LEFT JOIN service_other so ON so.booking_service_id = bs.id
             WHERE bs.booking_id = :booking_id
             ORDER BY bs.display_order ASC, bs.id ASC'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return $statement->fetchAll() ?: [];
    }

    public function servicesByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                bs.id,
                bs.booking_id,
                bs.branch_id,
                bs.line_reference,
                bs.display_order,
                bs.service_type,
                bs.supplier_id,
                COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                bs.traveler_id,
                COALESCE(t.full_name, bs.passenger_name_snapshot) AS passenger_name,
                bs.currency,
                bs.cost_currency,
                bs.sale_price,
                bs.purchase_cost,
                bs.pricing_exchange_rate,
                bs.pricing_rate_effective_date,
                bs.taxes,
                bs.other_fare,
                bs.soto_fare,
                bs.spyi_amount,
                bs.aq_yr_pk_amount,
                bs.yq_amount,
                bs.oth_amount,
                bs.vat_input,
                bs.vat,
                bs.commission,
                bs.service_charge,
                bs.service_charge_currency,
                bs.service_charge_exchange_rate,
                bs.service_charge_rate_effective_date,
                bs.discount_amount,
                bs.final_sale_price,
                bs.net_profit_loss,
                bs.due_date,
                bs.service_status,
                bs.remarks,
                bs.loss_reason,
                bs.is_active
             FROM booking_services bs
             INNER JOIN bookings b ON b.id = bs.booking_id
             LEFT JOIN suppliers s ON s.id = bs.supplier_id
             LEFT JOIN travelers t ON t.id = bs.traveler_id
             WHERE b.booking_reference = :booking_reference
             ORDER BY bs.display_order ASC, bs.id ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function serviceBelongsToAccessibleBooking(int $serviceId, array $accessibleBranchIds): bool
    {
        if ($accessibleBranchIds === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT bs.id
             FROM booking_services bs
             INNER JOIN bookings b ON b.id = bs.booking_id
             WHERE bs.id = ?
               AND b.branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute(array_merge([$serviceId], array_map('intval', $accessibleBranchIds)));

        return $statement->fetchColumn() !== false;
    }

    public function travelerForBooking(int $bookingId, int $travelerId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.full_name
             FROM booking_travelers bt
             INNER JOIN travelers t ON t.id = bt.traveler_id
             WHERE bt.booking_id = :booking_id
               AND bt.traveler_id = :traveler_id
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'traveler_id' => $travelerId,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findServiceById(int $serviceId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                bs.id,
                bs.booking_id,
                bs.branch_id,
                bs.line_reference,
                bs.display_order,
                bs.service_type,
                bs.supplier_id,
                bs.supplier_name_snapshot,
                bs.traveler_id,
                bs.passenger_name_snapshot,
                bs.currency,
                bs.cost_currency,
                bs.sale_price,
                bs.purchase_cost,
                bs.pricing_exchange_rate,
                bs.pricing_rate_effective_date,
                bs.taxes,
                bs.other_fare,
                bs.soto_fare,
                bs.spyi_amount,
                bs.aq_yr_pk_amount,
                bs.yq_amount,
                bs.oth_amount,
                bs.vat_input,
                bs.vat,
                bs.commission,
                bs.service_charge,
                bs.service_charge_currency,
                bs.service_charge_exchange_rate,
                bs.service_charge_rate_effective_date,
                bs.discount_amount,
                bs.final_sale_price,
                bs.net_profit_loss,
                bs.due_date,
                bs.service_status,
                bs.remarks,
                bs.loss_reason,
                bs.is_active,
                sat.pnr,
                sat.ticket_number,
                sat.airline,
                sat.sector_from,
                sat.sector_to,
                sat.departure_date,
                sat.return_date,
                sat.travel_class,
                sat.fare,
                sat.ticket_tax,
                sat.ticket_vat,
                sat.ticket_commission,
                sat.supplier_cost,
                sat.sale_amount,
                sat.ticket_remarks,
                sv.visa_country,
                sv.visa_type,
                sv.application_reference AS visa_application_reference,
                sv.passport_number AS visa_passport_number,
                sv.submission_date AS visa_submission_date,
                sv.issue_date AS visa_issue_date,
                sv.expiry_date AS visa_expiry_date,
                sv.visa_status,
                sv.remarks AS visa_remarks,
                su.package_name AS umrah_package_name,
                su.mofa_reference AS umrah_mofa_reference,
                su.departure_date AS umrah_departure_date,
                su.return_date AS umrah_return_date,
                su.hotel_name AS umrah_hotel_name,
                su.transport_notes AS umrah_transport_notes,
                su.remarks AS umrah_remarks,
                sh.hotel_name,
                sh.city AS hotel_city,
                sh.confirmation_number AS hotel_confirmation_number,
                sh.check_in_date AS hotel_check_in_date,
                sh.check_out_date AS hotel_check_out_date,
                sh.room_type AS hotel_room_type,
                sh.guest_count AS hotel_guest_count,
                sh.remarks AS hotel_remarks,
                st.transport_mode,
                st.vehicle_type AS transport_vehicle_type,
                st.pickup_date AS transport_pickup_date,
                st.pickup_location AS transport_pickup_location,
                st.dropoff_location AS transport_dropoff_location,
                st.driver_detail AS transport_driver_detail,
                st.route_notes AS transport_route_notes,
                st.remarks AS transport_remarks,
                sto.tour_name,
                sto.destination AS tour_destination,
                sto.confirmation_number AS tour_confirmation_number,
                sto.start_date AS tour_start_date,
                sto.end_date AS tour_end_date,
                sto.inclusions AS tour_inclusions,
                sto.remarks AS tour_remarks,
                so.label AS other_label,
                so.reference_number AS other_reference_number,
                so.service_date AS other_service_date,
                so.provider_name AS other_provider_name,
                so.remarks AS other_remarks
             FROM booking_services bs
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
             LEFT JOIN service_visa sv ON sv.booking_service_id = bs.id
             LEFT JOIN service_umrah su ON su.booking_service_id = bs.id
             LEFT JOIN service_hotel sh ON sh.booking_service_id = bs.id
             LEFT JOIN service_transport st ON st.booking_service_id = bs.id
             LEFT JOIN service_tour sto ON sto.booking_service_id = bs.id
             LEFT JOIN service_other so ON so.booking_service_id = bs.id
             WHERE bs.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $serviceId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createService(array $masterData, array $subtypeData): int
    {
        return $this->transaction(function () use ($masterData, $subtypeData): int {
            $displayOrder = $this->nextDisplayOrder((int) $masterData['booking_id']);
            $lineReference = 'SV-' . str_pad((string) $displayOrder, 3, '0', STR_PAD_LEFT);

            $statement = $this->db->prepare(
                'INSERT INTO booking_services (
                    booking_id, branch_id, line_reference, display_order, service_type, supplier_id, supplier_name_snapshot, traveler_id, passenger_name_snapshot,
                    currency, cost_currency, sale_price, purchase_cost, pricing_exchange_rate, pricing_rate_effective_date, taxes, other_fare, soto_fare, spyi_amount, aq_yr_pk_amount, yq_amount, oth_amount, vat_input, vat, commission, service_charge, service_charge_currency, service_charge_exchange_rate, service_charge_rate_effective_date, discount_amount, final_sale_price, net_profit_loss, due_date, service_status,
                    remarks, loss_reason, loss_reason_recorded_at, is_active, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :booking_id, :branch_id, :line_reference, :display_order, :service_type, :supplier_id, :supplier_name_snapshot, :traveler_id, :passenger_name_snapshot,
                    :currency, :cost_currency, :sale_price, :purchase_cost, :pricing_exchange_rate, :pricing_rate_effective_date, :taxes, :other_fare, :soto_fare, :spyi_amount, :aq_yr_pk_amount, :yq_amount, :oth_amount, :vat_input, :vat, :commission, :service_charge, :service_charge_currency, :service_charge_exchange_rate, :service_charge_rate_effective_date, :discount_amount, :final_sale_price, :net_profit_loss, :due_date, :service_status,
                    :remarks, :loss_reason, :loss_reason_recorded_at, 1, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $statement->execute([
                'booking_id' => $masterData['booking_id'],
                'branch_id' => $masterData['branch_id'],
                'line_reference' => $lineReference,
                'display_order' => $displayOrder,
                'service_type' => $masterData['service_type'],
                'supplier_id' => $masterData['supplier_id'],
                'supplier_name_snapshot' => $masterData['supplier_name_snapshot'],
                'traveler_id' => $masterData['traveler_id'],
                'passenger_name_snapshot' => $masterData['passenger_name_snapshot'],
                'currency' => $masterData['currency'],
                'cost_currency' => $masterData['cost_currency'],
                'sale_price' => $masterData['sale_price'],
                'purchase_cost' => $masterData['purchase_cost'],
                'pricing_exchange_rate' => $masterData['pricing_exchange_rate'],
                'pricing_rate_effective_date' => $masterData['pricing_rate_effective_date'],
                'taxes' => $masterData['taxes'],
                'other_fare' => $masterData['other_fare'],
                'soto_fare' => $masterData['soto_fare'],
                'spyi_amount' => $masterData['spyi_amount'],
                'aq_yr_pk_amount' => $masterData['aq_yr_pk_amount'],
                'yq_amount' => $masterData['yq_amount'],
                'oth_amount' => $masterData['oth_amount'],
                'vat_input' => $masterData['vat_input'],
                'vat' => $masterData['vat'],
                'commission' => $masterData['commission'],
                'service_charge' => $masterData['service_charge'],
                'service_charge_currency' => $masterData['service_charge_currency'],
                'service_charge_exchange_rate' => $masterData['service_charge_exchange_rate'],
                'service_charge_rate_effective_date' => $masterData['service_charge_rate_effective_date'],
                'discount_amount' => $masterData['discount_amount'],
                'final_sale_price' => $masterData['final_sale_price'],
                'net_profit_loss' => $masterData['net_profit_loss'],
                'due_date' => $masterData['due_date'],
                'service_status' => $masterData['service_status'],
                'remarks' => $masterData['remarks'],
                'loss_reason' => $masterData['loss_reason'],
                'loss_reason_recorded_at' => $masterData['loss_reason_recorded_at'],
                'created_by_user_id' => $masterData['actor_user_id'],
                'updated_by_user_id' => $masterData['actor_user_id'],
            ]);

            $serviceId = (int) $this->db->lastInsertId();
            $this->replaceSubtypeRecord($serviceId, (string) $masterData['service_type'], $subtypeData);

            return $serviceId;
        });
    }

    public function updateService(int $serviceId, array $masterData, array $subtypeData): void
    {
        $this->transaction(function () use ($serviceId, $masterData, $subtypeData): void {
            $statement = $this->db->prepare(
                'UPDATE booking_services
                 SET service_type = :service_type,
                     supplier_id = :supplier_id,
                     supplier_name_snapshot = :supplier_name_snapshot,
                     traveler_id = :traveler_id,
                     passenger_name_snapshot = :passenger_name_snapshot,
                     currency = :currency,
                     cost_currency = :cost_currency,
                     sale_price = :sale_price,
                     purchase_cost = :purchase_cost,
                     pricing_exchange_rate = :pricing_exchange_rate,
                     pricing_rate_effective_date = :pricing_rate_effective_date,
                     taxes = :taxes,
                     other_fare = :other_fare,
                     soto_fare = :soto_fare,
                     spyi_amount = :spyi_amount,
                     aq_yr_pk_amount = :aq_yr_pk_amount,
                     yq_amount = :yq_amount,
                     oth_amount = :oth_amount,
                     vat_input = :vat_input,
                     vat = :vat,
                      commission = :commission,
                      service_charge = :service_charge,
                      service_charge_currency = :service_charge_currency,
                      service_charge_exchange_rate = :service_charge_exchange_rate,
                      service_charge_rate_effective_date = :service_charge_rate_effective_date,
                      discount_amount = :discount_amount,
                     final_sale_price = :final_sale_price,
                     net_profit_loss = :net_profit_loss,
                     due_date = :due_date,
                     service_status = :service_status,
                     remarks = :remarks,
                     loss_reason = :loss_reason,
                     loss_reason_recorded_at = :loss_reason_recorded_at,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $serviceId,
                'service_type' => $masterData['service_type'],
                'supplier_id' => $masterData['supplier_id'],
                'supplier_name_snapshot' => $masterData['supplier_name_snapshot'],
                'traveler_id' => $masterData['traveler_id'],
                'passenger_name_snapshot' => $masterData['passenger_name_snapshot'],
                'currency' => $masterData['currency'],
                'cost_currency' => $masterData['cost_currency'],
                'sale_price' => $masterData['sale_price'],
                'purchase_cost' => $masterData['purchase_cost'],
                'pricing_exchange_rate' => $masterData['pricing_exchange_rate'],
                'pricing_rate_effective_date' => $masterData['pricing_rate_effective_date'],
                'taxes' => $masterData['taxes'],
                'other_fare' => $masterData['other_fare'],
                'soto_fare' => $masterData['soto_fare'],
                'spyi_amount' => $masterData['spyi_amount'],
                'aq_yr_pk_amount' => $masterData['aq_yr_pk_amount'],
                'yq_amount' => $masterData['yq_amount'],
                'oth_amount' => $masterData['oth_amount'],
                'vat_input' => $masterData['vat_input'],
                'vat' => $masterData['vat'],
                'commission' => $masterData['commission'],
                'service_charge' => $masterData['service_charge'],
                'service_charge_currency' => $masterData['service_charge_currency'],
                'service_charge_exchange_rate' => $masterData['service_charge_exchange_rate'],
                'service_charge_rate_effective_date' => $masterData['service_charge_rate_effective_date'],
                'discount_amount' => $masterData['discount_amount'],
                'final_sale_price' => $masterData['final_sale_price'],
                'net_profit_loss' => $masterData['net_profit_loss'],
                'due_date' => $masterData['due_date'],
                'service_status' => $masterData['service_status'],
                'remarks' => $masterData['remarks'],
                'loss_reason' => $masterData['loss_reason'],
                'loss_reason_recorded_at' => $masterData['loss_reason_recorded_at'],
                'updated_by_user_id' => $masterData['actor_user_id'],
            ]);

            $this->replaceSubtypeRecord($serviceId, (string) $masterData['service_type'], $subtypeData);
        });
    }

    public function updateServiceFinancialFields(int $serviceId, array $financialData): void
    {
        $this->transaction(function () use ($serviceId, $financialData): void {
            $statement = $this->db->prepare(
                'UPDATE booking_services
                 SET sale_price = :sale_price,
                     currency = :currency,
                     cost_currency = :cost_currency,
                     purchase_cost = :purchase_cost,
                     pricing_exchange_rate = :pricing_exchange_rate,
                     pricing_rate_effective_date = :pricing_rate_effective_date,
                      commission = :commission,
                      service_charge = :service_charge,
                      service_charge_currency = :service_charge_currency,
                      service_charge_exchange_rate = :service_charge_exchange_rate,
                      service_charge_rate_effective_date = :service_charge_rate_effective_date,
                      discount_amount = :discount_amount,
                     final_sale_price = :final_sale_price,
                     net_profit_loss = :net_profit_loss,
                     remarks = :remarks,
                     loss_reason = :loss_reason,
                     loss_reason_recorded_at = :loss_reason_recorded_at,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $serviceId,
                'sale_price' => $financialData['sale_price'],
                'currency' => $financialData['currency'],
                'cost_currency' => $financialData['cost_currency'],
                'purchase_cost' => $financialData['purchase_cost'],
                'pricing_exchange_rate' => $financialData['pricing_exchange_rate'],
                'pricing_rate_effective_date' => $financialData['pricing_rate_effective_date'],
                'commission' => $financialData['commission'] ?? 0,
                'service_charge' => $financialData['service_charge'] ?? 0,
                'service_charge_currency' => $financialData['service_charge_currency'] ?? $financialData['currency'],
                'service_charge_exchange_rate' => $financialData['service_charge_exchange_rate'] ?? 1,
                'service_charge_rate_effective_date' => $financialData['service_charge_rate_effective_date'] ?? $financialData['pricing_rate_effective_date'],
                'discount_amount' => $financialData['discount_amount'] ?? 0,
                'final_sale_price' => $financialData['final_sale_price'],
                'net_profit_loss' => $financialData['net_profit_loss'],
                'remarks' => $financialData['remarks'] ?? null,
                'loss_reason' => $financialData['loss_reason'] ?? null,
                'loss_reason_recorded_at' => $financialData['loss_reason_recorded_at'] ?? null,
                'updated_by_user_id' => $financialData['actor_user_id'],
            ]);

            if ((string) ($financialData['service_type'] ?? '') === 'air ticket') {
                $airTicketStatement = $this->db->prepare(
                    'UPDATE service_air_ticket
                     SET supplier_cost = :supplier_cost,
                         sale_amount = :sale_amount
                     WHERE booking_service_id = :service_id'
                );
                $airTicketStatement->execute([
                    'service_id' => $serviceId,
                    'supplier_cost' => $financialData['purchase_cost'],
                    'sale_amount' => $financialData['final_sale_price'],
                ]);
            }
        });
    }

    public function deactivateService(int $serviceId, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_services
             SET is_active = 0,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $serviceId,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function updateServiceStatus(int $serviceId, string $status, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_services
             SET service_status = :service_status,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $serviceId,
            'service_status' => $status,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function updateAirTicketReissueDetails(
        int $serviceId,
        ?string $ticketNumber,
        ?string $pnr,
        int $actorUserId,
        float $supplierReissueCharge = 0.0,
        float $agencyServiceFee = 0.0,
        ?float $customerAdjustment = null
    ): void
    {
        $this->transaction(function () use (
            $serviceId,
            $ticketNumber,
            $pnr,
            $actorUserId,
            $supplierReissueCharge,
            $agencyServiceFee,
            $customerAdjustment
        ): void {
            $supplierReissueCharge = round(max($supplierReissueCharge, 0.0), 2);
            $agencyServiceFee = round(max($agencyServiceFee, 0.0), 2);
            $customerAdjustment = round(max($customerAdjustment ?? ($supplierReissueCharge + $agencyServiceFee), 0.0), 2);
            $statement = $this->db->prepare(
                'UPDATE service_air_ticket
                 SET ticket_number = :ticket_number,
                     pnr = :pnr,
                     supplier_cost = COALESCE(supplier_cost, 0) + :supplier_reissue_charge,
                     sale_amount = COALESCE(sale_amount, 0) + :customer_adjustment
                 WHERE booking_service_id = :service_id'
            );
            $statement->execute([
                'service_id' => $serviceId,
                'ticket_number' => $ticketNumber,
                'pnr' => $pnr,
                'supplier_reissue_charge' => $supplierReissueCharge,
                'customer_adjustment' => $customerAdjustment,
            ]);

            $updateService = $this->db->prepare(
                'UPDATE booking_services
                 SET service_status = "Booked",
                     sale_price = COALESCE(sale_price, 0) + :sale_supplier_reissue_charge,
                     purchase_cost = COALESCE(purchase_cost, 0) + :cost_supplier_reissue_charge,
                     service_charge = COALESCE(service_charge, 0) + :service_charge_increment,
                     final_sale_price = COALESCE(final_sale_price, 0) + :customer_adjustment,
                     net_profit_loss = COALESCE(net_profit_loss, 0) + :profit_increment,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id'
            );
            $updateService->execute([
                'id' => $serviceId,
                'sale_supplier_reissue_charge' => $supplierReissueCharge,
                'cost_supplier_reissue_charge' => $supplierReissueCharge,
                'service_charge_increment' => $agencyServiceFee,
                'profit_increment' => $agencyServiceFee,
                'customer_adjustment' => $customerAdjustment,
                'updated_by_user_id' => $actorUserId,
            ]);
        });
    }

    private function replaceSubtypeRecord(int $serviceId, string $serviceType, array $subtypeData): void
    {
        foreach (self::SUBTYPE_TABLES as $tableName) {
            $deleteStatement = $this->db->prepare('DELETE FROM ' . $tableName . ' WHERE booking_service_id = :service_id');
            $deleteStatement->execute(['service_id' => $serviceId]);
        }

        if ($serviceType === 'air ticket') {
            $statement = $this->db->prepare(
                'INSERT INTO service_air_ticket (
                    booking_service_id, pnr, ticket_number, airline, sector_from, sector_to, departure_date, return_date,
                    travel_class, fare, ticket_tax, ticket_vat, ticket_commission, supplier_cost, sale_amount, ticket_remarks
                 ) VALUES (
                    :booking_service_id, :pnr, :ticket_number, :airline, :sector_from, :sector_to, :departure_date, :return_date,
                    :travel_class, :fare, :ticket_tax, :ticket_vat, :ticket_commission, :supplier_cost, :sale_amount, :ticket_remarks
                 )'
            );
            $statement->execute([
                'booking_service_id' => $serviceId,
                'pnr' => $subtypeData['pnr'],
                'ticket_number' => $subtypeData['ticket_number'],
                'airline' => $subtypeData['airline'],
                'sector_from' => $subtypeData['sector_from'],
                'sector_to' => $subtypeData['sector_to'],
                'departure_date' => $subtypeData['departure_date'],
                'return_date' => $subtypeData['return_date'],
                'travel_class' => $subtypeData['travel_class'],
                'fare' => $subtypeData['fare'],
                'ticket_tax' => $subtypeData['ticket_tax'],
                'ticket_vat' => $subtypeData['ticket_vat'],
                'ticket_commission' => $subtypeData['ticket_commission'],
                'supplier_cost' => $subtypeData['supplier_cost'],
                'sale_amount' => $subtypeData['sale_amount'],
                'ticket_remarks' => $subtypeData['ticket_remarks'],
            ]);

            return;
        }

        $tableName = self::SUBTYPE_TABLES[$serviceType] ?? 'service_other';
        $payload = match ($tableName) {
            'service_visa' => ['visa_country', 'visa_type', 'application_reference', 'passport_number', 'submission_date', 'issue_date', 'expiry_date', 'visa_status', 'remarks'],
            'service_umrah' => ['package_name', 'mofa_reference', 'departure_date', 'return_date', 'hotel_name', 'transport_notes', 'remarks'],
            'service_hotel' => ['hotel_name', 'city', 'confirmation_number', 'check_in_date', 'check_out_date', 'room_type', 'guest_count', 'remarks'],
            'service_transport' => ['transport_mode', 'vehicle_type', 'pickup_date', 'pickup_location', 'dropoff_location', 'driver_detail', 'route_notes', 'remarks'],
            'service_tour' => ['tour_name', 'destination', 'confirmation_number', 'start_date', 'end_date', 'inclusions', 'remarks'],
            default => ['label', 'reference_number', 'service_date', 'provider_name', 'remarks'],
        };

        $columns = implode(', ', array_merge(['booking_service_id'], $payload));
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, array_merge(['booking_service_id'], $payload)));
        $statement = $this->db->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $columns, $placeholders));

        $values = ['booking_service_id' => $serviceId];
        foreach ($payload as $column) {
            $values[$column] = $subtypeData[$column] ?? null;
        }
        $statement->execute($values);
    }

    private function nextDisplayOrder(int $bookingId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(display_order), 0) + 1
             FROM booking_services
             WHERE booking_id = :booking_id'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return (int) $statement->fetchColumn();
    }
}
