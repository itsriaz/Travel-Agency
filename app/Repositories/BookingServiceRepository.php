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
                bs.sale_price,
                bs.purchase_cost,
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
                sat.ticket_remarks
             FROM booking_services bs
             LEFT JOIN suppliers s ON s.id = bs.supplier_id
             LEFT JOIN travelers t ON t.id = bs.traveler_id
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
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
                bs.sale_price,
                bs.purchase_cost,
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
                bs.sale_price,
                bs.purchase_cost,
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
                sat.ticket_remarks
             FROM booking_services bs
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
             WHERE bs.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $serviceId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createService(array $masterData, array $airTicketData): int
    {
        return $this->transaction(function () use ($masterData, $airTicketData): int {
            $displayOrder = $this->nextDisplayOrder((int) $masterData['booking_id']);
            $lineReference = 'SV-' . str_pad((string) $displayOrder, 3, '0', STR_PAD_LEFT);

            $statement = $this->db->prepare(
                'INSERT INTO booking_services (
                    booking_id, branch_id, line_reference, display_order, service_type, supplier_id, supplier_name_snapshot, traveler_id, passenger_name_snapshot,
                    currency, sale_price, purchase_cost, taxes, other_fare, soto_fare, spyi_amount, aq_yr_pk_amount, yq_amount, oth_amount, vat_input, vat, commission, service_charge, discount_amount, final_sale_price, net_profit_loss, due_date, service_status,
                    remarks, loss_reason, loss_reason_recorded_at, is_active, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :booking_id, :branch_id, :line_reference, :display_order, :service_type, :supplier_id, :supplier_name_snapshot, :traveler_id, :passenger_name_snapshot,
                    :currency, :sale_price, :purchase_cost, :taxes, :other_fare, :soto_fare, :spyi_amount, :aq_yr_pk_amount, :yq_amount, :oth_amount, :vat_input, :vat, :commission, :service_charge, :discount_amount, :final_sale_price, :net_profit_loss, :due_date, :service_status,
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
                'sale_price' => $masterData['sale_price'],
                'purchase_cost' => $masterData['purchase_cost'],
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
            $this->replaceSubtypeRecord($serviceId, (string) $masterData['service_type'], $airTicketData);

            return $serviceId;
        });
    }

    public function updateService(int $serviceId, array $masterData, array $airTicketData): void
    {
        $this->transaction(function () use ($serviceId, $masterData, $airTicketData): void {
            $statement = $this->db->prepare(
                'UPDATE booking_services
                 SET service_type = :service_type,
                     supplier_id = :supplier_id,
                     supplier_name_snapshot = :supplier_name_snapshot,
                     traveler_id = :traveler_id,
                     passenger_name_snapshot = :passenger_name_snapshot,
                     currency = :currency,
                     sale_price = :sale_price,
                     purchase_cost = :purchase_cost,
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
                'sale_price' => $masterData['sale_price'],
                'purchase_cost' => $masterData['purchase_cost'],
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

            $this->replaceSubtypeRecord($serviceId, (string) $masterData['service_type'], $airTicketData);
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

    private function replaceSubtypeRecord(int $serviceId, string $serviceType, array $airTicketData): void
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
                'pnr' => $airTicketData['pnr'],
                'ticket_number' => $airTicketData['ticket_number'],
                'airline' => $airTicketData['airline'],
                'sector_from' => $airTicketData['sector_from'],
                'sector_to' => $airTicketData['sector_to'],
                'departure_date' => $airTicketData['departure_date'],
                'return_date' => $airTicketData['return_date'],
                'travel_class' => $airTicketData['travel_class'],
                'fare' => $airTicketData['fare'],
                'ticket_tax' => $airTicketData['ticket_tax'],
                'ticket_vat' => $airTicketData['ticket_vat'],
                'ticket_commission' => $airTicketData['ticket_commission'],
                'supplier_cost' => $airTicketData['supplier_cost'],
                'sale_amount' => $airTicketData['sale_amount'],
                'ticket_remarks' => $airTicketData['ticket_remarks'],
            ]);

            return;
        }

        $tableName = self::SUBTYPE_TABLES[$serviceType] ?? 'service_other';
        $payload = match ($tableName) {
            'service_visa' => ['visa_country', 'visa_type', 'remarks'],
            'service_umrah' => ['package_name', 'remarks'],
            'service_hotel' => ['hotel_name', 'city', 'remarks'],
            'service_transport' => ['transport_mode', 'route_notes', 'remarks'],
            'service_tour' => ['tour_name', 'destination', 'remarks'],
            default => ['label', 'remarks'],
        };

        $columns = implode(', ', array_merge(['booking_service_id'], $payload));
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, array_merge(['booking_service_id'], $payload)));
        $statement = $this->db->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $columns, $placeholders));

        $values = ['booking_service_id' => $serviceId];
        foreach ($payload as $column) {
            $values[$column] = null;
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
