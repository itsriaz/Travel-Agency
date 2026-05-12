<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingRepository extends BaseRepository
{
    public function branchOptions(array $accessibleBranchIds): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id, code, name, city, country_code, base_currency
             FROM branches
             WHERE is_active = 1
               AND id IN ({$placeholders})
             ORDER BY name ASC"
        );
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function createBooking(array $bookingData, array $partyData): int
    {
        return $this->transaction(function () use ($bookingData, $partyData): int {
            $reference = $this->nextBookingReference();

            $statement = $this->db->prepare(
                'INSERT INTO bookings (
                    booking_reference, branch_id, booking_status, booking_date, due_date, departure_date, return_date, remarks,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :booking_reference, :branch_id, :booking_status, :booking_date, :due_date, :departure_date, :return_date, :remarks,
                    :created_by_user_id, :updated_by_user_id
                 )'
            );
            $statement->execute([
                'booking_reference' => $reference,
                'branch_id' => $bookingData['branch_id'],
                'booking_status' => $bookingData['booking_status'],
                'booking_date' => $bookingData['booking_date'],
                'due_date' => $bookingData['due_date'],
                'departure_date' => $bookingData['departure_date'],
                'return_date' => $bookingData['return_date'],
                'remarks' => $bookingData['remarks'],
                'created_by_user_id' => $bookingData['actor_user_id'],
                'updated_by_user_id' => $bookingData['actor_user_id'],
            ]);

            $bookingId = (int) $this->db->lastInsertId();
            $this->upsertParty($bookingId, $partyData);

            return $bookingId;
        });
    }

    public function updateBooking(int $bookingId, array $bookingData, array $partyData): void
    {
        $this->transaction(function () use ($bookingId, $bookingData, $partyData): void {
            $statement = $this->db->prepare(
                'UPDATE bookings
                 SET branch_id = :branch_id,
                     booking_status = :booking_status,
                     booking_date = :booking_date,
                     due_date = :due_date,
                     departure_date = :departure_date,
                     return_date = :return_date,
                     remarks = :remarks,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $bookingId,
                'branch_id' => $bookingData['branch_id'],
                'booking_status' => $bookingData['booking_status'],
                'booking_date' => $bookingData['booking_date'],
                'due_date' => $bookingData['due_date'],
                'departure_date' => $bookingData['departure_date'],
                'return_date' => $bookingData['return_date'],
                'remarks' => $bookingData['remarks'],
                'updated_by_user_id' => $bookingData['actor_user_id'],
            ]);

            $this->upsertParty($bookingId, $partyData);
        });
    }

    public function findBookingById(int $bookingId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                b.id,
                b.booking_reference,
                b.branch_id,
                b.lead_traveler_id,
                b.booking_status,
                b.booking_date,
                b.due_date,
                b.departure_date,
                b.return_date,
                b.remarks,
                b.created_at,
                b.updated_at,
                bp.party_label,
                bp.lead_traveler_name,
                bp.contact_mobile,
                bp.passport_number,
                bp.notes AS party_notes,
                br.name AS branch_name,
                br.city AS branch_city,
                br.country_code,
                br.base_currency
             FROM bookings b
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             WHERE b.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findBookingByReference(string $bookingReference, array $accessibleBranchIds): ?array
    {
        $trimmedReference = trim($bookingReference);
        if ($trimmedReference === '' || $accessibleBranchIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                b.id,
                b.booking_reference,
                b.branch_id,
                b.lead_traveler_id,
                b.booking_status,
                b.booking_date,
                b.due_date,
                b.departure_date,
                b.return_date,
                b.remarks,
                b.created_at,
                b.updated_at,
                bp.party_label,
                bp.lead_traveler_name,
                bp.contact_mobile,
                bp.passport_number,
                bp.notes AS party_notes,
                br.name AS branch_name,
                br.city AS branch_city,
                br.country_code,
                br.base_currency
             FROM bookings b
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             WHERE b.booking_reference = ?
               AND b.branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute(array_merge([$trimmedReference], array_map('intval', $accessibleBranchIds)));
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function updateBookingDueDate(int $bookingId, ?string $dueDate, ?int $actorUserId = null): void
    {
        $statement = $this->db->prepare(
            'UPDATE bookings
             SET due_date = :due_date,
                 updated_by_user_id = COALESCE(:updated_by_user_id, updated_by_user_id)
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $bookingId,
            'due_date' => $dueDate,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function searchBookings(string $query, array $accessibleBranchIds, int $limit = 20): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $likeQuery = '%' . trim($query) . '%';
        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    b.id,
                    b.booking_reference,
                    b.booking_status,
                    b.booking_date,
                    b.departure_date,
                    b.return_date,
                    b.updated_at,
                    br.name AS branch_name,
                    br.base_currency,
                    bp.lead_traveler_name,
                    bp.contact_mobile,
                    bp.passport_number,
                    COALESCE(svc.service_line_reference, '') AS service_line_reference,
                    COALESCE(svc.ticket_number, '') AS ticket_number,
                    COALESCE(svc.pnr, '') AS pnr,
                    COALESCE(svc.supplier_name, '') AS supplier_name,
                    COALESCE(svc.booking_currency, recv.outstanding_currency, br.base_currency, 'PKR') AS booking_currency,
                    COALESCE(recv.total_outstanding, 0) AS total_outstanding
                FROM bookings b
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        bs.booking_id,
                        MAX(bs.line_reference) AS service_line_reference,
                        MAX(COALESCE(sat.ticket_number, '')) AS ticket_number,
                        MAX(COALESCE(sat.pnr, '')) AS pnr,
                        MAX(COALESCE(s.name, '')) AS supplier_name,
                        MAX(COALESCE(bs.currency, 'PKR')) AS booking_currency
                    FROM booking_services bs
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    GROUP BY bs.booking_id
                ) svc ON svc.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        booking_reference,
                        MAX(currency) AS outstanding_currency,
                        SUM(CASE WHEN status <> 'cancelled' THEN outstanding_amount ELSE 0 END) AS total_outstanding
                    FROM customer_receivable_items
                    GROUP BY booking_reference
                ) recv ON recv.booking_reference = b.booking_reference
                WHERE b.branch_id IN ({$placeholders})";

        $params = array_map('intval', $accessibleBranchIds);

        if ($query !== '') {
            $sql .= '
                AND (
                    b.booking_reference LIKE ?
                    OR bp.lead_traveler_name LIKE ?
                    OR COALESCE(bp.contact_mobile, "") LIKE ?
                    OR COALESCE(bp.passport_number, "") LIKE ?
                    OR COALESCE(svc.service_line_reference, "") LIKE ?
                    OR COALESCE(svc.ticket_number, "") LIKE ?
                    OR COALESCE(svc.pnr, "") LIKE ?
                    OR COALESCE(svc.supplier_name, "") LIKE ?
                )';
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
        }

        $sql .= '
            ORDER BY b.updated_at DESC, b.id DESC
            LIMIT ' . max(1, $limit);

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function bookingExistsInBranches(int $bookingId, array $accessibleBranchIds): bool
    {
        if ($accessibleBranchIds === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id
             FROM bookings
             WHERE id = ?
               AND branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $params = array_merge([$bookingId], array_map('intval', $accessibleBranchIds));
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function recentActivityForBooking(string $bookingReference, int $limit = 12): array
    {
        $statement = $this->db->prepare(
            'SELECT event_name, created_at, payload_json
             FROM audit_logs
             WHERE payload_json LIKE :reference_pattern
             ORDER BY created_at DESC, id DESC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':reference_pattern', '%"booking_reference":"' . $bookingReference . '"%');
        $statement->bindValue(':limit_rows', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    private function upsertParty(int $bookingId, array $partyData): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO booking_parties (
                booking_id, party_label, lead_traveler_name, contact_mobile, passport_number, notes
             ) VALUES (
                :booking_id, :party_label, :lead_traveler_name, :contact_mobile, :passport_number, :notes
             )
             ON DUPLICATE KEY UPDATE
                party_label = VALUES(party_label),
                lead_traveler_name = VALUES(lead_traveler_name),
                contact_mobile = VALUES(contact_mobile),
                passport_number = VALUES(passport_number),
                notes = VALUES(notes)'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'party_label' => $partyData['party_label'],
            'lead_traveler_name' => $partyData['lead_traveler_name'],
            'contact_mobile' => $partyData['contact_mobile'],
            'passport_number' => $partyData['passport_number'],
            'notes' => $partyData['notes'],
        ]);
    }

    private function nextBookingReference(): string
    {
        $statement = $this->db->prepare(
            'SELECT id, setting_value
             FROM app_settings
             WHERE setting_key = :setting_key
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['setting_key' => 'booking.reference.sequence']);
        $row = $statement->fetch();

        $nextSequence = ((int) ($row['setting_value'] ?? 0)) + 1;

        if ($row === false) {
            $insert = $this->db->prepare(
                'INSERT INTO app_settings (setting_key, setting_value)
                 VALUES (:setting_key, :setting_value)'
            );
            $insert->execute([
                'setting_key' => 'booking.reference.sequence',
                'setting_value' => (string) $nextSequence,
            ]);
        } else {
            $update = $this->db->prepare(
                'UPDATE app_settings
                 SET setting_value = :setting_value
                 WHERE id = :id'
            );
            $update->execute([
                'setting_value' => (string) $nextSequence,
                'id' => $row['id'],
            ]);
        }

        return 'BK-' . str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
    }
}
