<?php

declare(strict_types=1);

namespace App\Repositories;

final class TravelerRepository extends BaseRepository
{
    public function travelersForBooking(int $bookingId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                bt.id AS booking_traveler_id,
                bt.traveler_role,
                bt.display_order,
                t.id,
                t.branch_id,
                t.first_name,
                t.last_name,
                t.full_name,
                t.passport_number,
                t.nationality,
                t.date_of_birth,
                t.gender,
                t.passport_expiry,
                t.mobile,
                t.address,
                t.permanent_residence,
                t.current_residence,
                t.occupation,
                t.village,
                t.district,
                t.family_id,
                t.color_tag,
                t.notes,
                t.created_at,
                t.updated_at
             FROM booking_travelers bt
             INNER JOIN travelers t ON t.id = bt.traveler_id
             WHERE bt.booking_id = :booking_id
             ORDER BY
                CASE WHEN bt.traveler_role = "lead" THEN 0 ELSE 1 END ASC,
                bt.display_order ASC,
                t.full_name ASC'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return $statement->fetchAll() ?: [];
    }

    public function searchTravelers(string $query, array $accessibleBranchIds, int $limit = 20): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    t.id,
                    t.branch_id,
                    t.first_name,
                    t.last_name,
                    t.full_name,
                    t.passport_number,
                    t.nationality,
                    t.date_of_birth,
                    t.gender,
                    t.passport_expiry,
                    t.mobile,
                    t.address,
                    t.permanent_residence,
                    t.current_residence,
                    t.occupation,
                    t.village,
                    t.district,
                    t.family_id,
                    t.color_tag,
                    t.notes,
                    b.name AS branch_name
                FROM travelers t
                INNER JOIN branches b ON b.id = t.branch_id
                WHERE t.branch_id IN ({$placeholders})";

        $params = array_map('intval', $accessibleBranchIds);
        $trimmed = trim($query);

         if ($trimmed !== '') {
             $like = '%' . $trimmed . '%';
             $searchableColumns = [
                 't.full_name',
                 'CAST(t.id AS CHAR)',
                 'COALESCE(t.passport_number, "")',
                 'COALESCE(t.mobile, "")',
                 'COALESCE(t.first_name, "")',
                 'COALESCE(t.last_name, "")',
                 'COALESCE(t.family_id, "")',
                 'COALESCE(t.occupation, "")',
                 'COALESCE(t.village, "")',
                 'COALESCE(t.district, "")',
                 'COALESCE(t.nationality, "")',
                 'COALESCE(t.address, "")',
                 'COALESCE(t.current_residence, "")',
                 'COALESCE(t.permanent_residence, "")',
                 'COALESCE(t.notes, "")',
             ];
             $sql .= '
                  AND (
                      ' . implode("
                      OR ", array_map(
                          static fn (string $column): string => $column . ' LIKE ?',
                          $searchableColumns
                      )) . '
                  )';
             $params = array_merge($params, array_fill(0, count($searchableColumns), $like));
         }

        $sql .= '
            ORDER BY t.updated_at DESC, t.id DESC
            LIMIT ' . max(1, $limit);

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function travelerExistsInBranches(int $travelerId, array $accessibleBranchIds): bool
    {
        if ($accessibleBranchIds === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id
             FROM travelers
             WHERE id = ?
               AND branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute(array_merge([$travelerId], array_map('intval', $accessibleBranchIds)));

        return $statement->fetchColumn() !== false;
    }

    public function findTravelerById(int $travelerId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, first_name, last_name, full_name, passport_number, nationality, date_of_birth, gender, passport_expiry, mobile, address,
                    permanent_residence, current_residence, occupation, village, district, family_id, color_tag, notes, created_at, updated_at
             FROM travelers
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $travelerId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findAccessibleTravelerByExactName(string $fullName, array $accessibleBranchIds, ?int $branchId = null): ?array
    {
        $trimmedName = trim($fullName);
        if ($trimmedName === '' || $accessibleBranchIds === []) {
            return null;
        }

        $branchIds = array_values(array_unique(array_map('intval', $accessibleBranchIds)));
        $placeholders = implode(', ', array_fill(0, count($branchIds), '?'));
        $sql = "SELECT id, branch_id, first_name, last_name, full_name, passport_number, nationality, date_of_birth, gender, passport_expiry, mobile, address,
                       permanent_residence, current_residence, occupation, village, district, family_id, color_tag, notes, created_at, updated_at
                FROM travelers
                WHERE LOWER(TRIM(full_name)) = ?
                  AND branch_id IN ({$placeholders})";

        $params = array_merge([mb_strtolower($trimmedName)], $branchIds);
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY id ASC LIMIT 1';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findTravelerForBookingByName(int $bookingId, string $fullName): ?array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.full_name
             FROM booking_travelers bt
             INNER JOIN travelers t ON t.id = bt.traveler_id
             WHERE bt.booking_id = :booking_id
               AND LOWER(TRIM(t.full_name)) = :full_name
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'full_name' => mb_strtolower(trim($fullName)),
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createTraveler(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO travelers (
                branch_id, first_name, last_name, full_name, passport_number, nationality, date_of_birth, gender, passport_expiry, mobile, address,
                permanent_residence, current_residence, occupation, village, district, family_id, color_tag, notes,
                created_by_user_id, updated_by_user_id
             ) VALUES (
                :branch_id, :first_name, :last_name, :full_name, :passport_number, :nationality, :date_of_birth, :gender, :passport_expiry, :mobile, :address,
                :permanent_residence, :current_residence, :occupation, :village, :district, :family_id, :color_tag, :notes,
                :created_by_user_id, :updated_by_user_id
             )'
        );
        $statement->execute([
            'branch_id' => $data['branch_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'full_name' => $data['full_name'],
            'passport_number' => $data['passport_number'],
            'nationality' => $data['nationality'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
            'passport_expiry' => $data['passport_expiry'],
            'mobile' => $data['mobile'],
            'address' => $data['address'],
            'permanent_residence' => $data['permanent_residence'],
            'current_residence' => $data['current_residence'],
            'occupation' => $data['occupation'],
            'village' => $data['village'],
            'district' => $data['district'],
            'family_id' => $data['family_id'],
            'color_tag' => $data['color_tag'],
            'notes' => $data['notes'],
            'created_by_user_id' => $data['actor_user_id'],
            'updated_by_user_id' => $data['actor_user_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateTraveler(int $travelerId, array $data): void
    {
        $statement = $this->db->prepare(
            'UPDATE travelers
             SET branch_id = :branch_id,
                 first_name = :first_name,
                 last_name = :last_name,
                 full_name = :full_name,
                 passport_number = :passport_number,
                 nationality = :nationality,
                 date_of_birth = :date_of_birth,
                 gender = :gender,
                 passport_expiry = :passport_expiry,
                 mobile = :mobile,
                 address = :address,
                 permanent_residence = :permanent_residence,
                 current_residence = :current_residence,
                 occupation = :occupation,
                 village = :village,
                 district = :district,
                 family_id = :family_id,
                 color_tag = :color_tag,
                 notes = :notes,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $travelerId,
            'branch_id' => $data['branch_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'full_name' => $data['full_name'],
            'passport_number' => $data['passport_number'],
            'nationality' => $data['nationality'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
            'passport_expiry' => $data['passport_expiry'],
            'mobile' => $data['mobile'],
            'address' => $data['address'],
            'permanent_residence' => $data['permanent_residence'],
            'current_residence' => $data['current_residence'],
            'occupation' => $data['occupation'],
            'village' => $data['village'],
            'district' => $data['district'],
            'family_id' => $data['family_id'],
            'color_tag' => $data['color_tag'],
            'notes' => $data['notes'],
            'updated_by_user_id' => $data['actor_user_id'],
        ]);
    }

    public function attachTravelerToBooking(int $bookingId, int $travelerId, string $role, int $actorUserId): void
    {
        $this->transaction(function () use ($bookingId, $travelerId, $role, $actorUserId): void {
            if ($role === 'lead') {
                $clearLead = $this->db->prepare(
                    'UPDATE booking_travelers
                     SET traveler_role = "additional"
                     WHERE booking_id = :booking_id
                       AND traveler_role = "lead"'
                );
                $clearLead->execute(['booking_id' => $bookingId]);
            }

            $displayOrder = $this->nextDisplayOrder($bookingId);
            $statement = $this->db->prepare(
                'INSERT INTO booking_travelers (
                    booking_id, traveler_id, traveler_role, display_order, attached_by_user_id
                 ) VALUES (
                    :booking_id, :traveler_id, :traveler_role, :display_order, :attached_by_user_id
                 )
                 ON DUPLICATE KEY UPDATE
                    traveler_role = VALUES(traveler_role),
                    attached_by_user_id = VALUES(attached_by_user_id)'
            );
            $statement->execute([
                'booking_id' => $bookingId,
                'traveler_id' => $travelerId,
                'traveler_role' => $role,
                'display_order' => $displayOrder,
                'attached_by_user_id' => $actorUserId,
            ]);

            $this->synchronizeLeadTraveler($bookingId);
        });
    }

    public function removeTravelerFromBooking(int $bookingId, int $travelerId): void
    {
        $this->transaction(function () use ($bookingId, $travelerId): void {
            $statement = $this->db->prepare(
                'DELETE FROM booking_travelers
                 WHERE booking_id = :booking_id
                   AND traveler_id = :traveler_id'
            );
            $statement->execute([
                'booking_id' => $bookingId,
                'traveler_id' => $travelerId,
            ]);

            $this->synchronizeLeadTraveler($bookingId);
        });
    }

    public function travelerAttachedToBooking(int $bookingId, int $travelerId): bool
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM booking_travelers
             WHERE booking_id = :booking_id
               AND traveler_id = :traveler_id
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'traveler_id' => $travelerId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function bookingHasTravelers(int $bookingId): bool
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM booking_travelers
             WHERE booking_id = :booking_id
             LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return $statement->fetchColumn() !== false;
    }

    public function currentLeadTravelerForBooking(int $bookingId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT t.*
             FROM booking_travelers bt
             INNER JOIN travelers t ON t.id = bt.traveler_id
             WHERE bt.booking_id = :booking_id
               AND bt.traveler_role = "lead"
             LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    private function synchronizeLeadTraveler(int $bookingId): void
    {
        $leadTraveler = $this->currentLeadTravelerForBooking($bookingId);
        $leadTravelerId = (int) ($leadTraveler['id'] ?? 0);

        $updateBooking = $this->db->prepare(
            'UPDATE bookings
             SET lead_traveler_id = :lead_traveler_id
             WHERE id = :id'
        );
        $updateBooking->execute([
            'id' => $bookingId,
            'lead_traveler_id' => $leadTravelerId > 0 ? $leadTravelerId : null,
        ]);

        $updateParty = $this->db->prepare(
            'UPDATE booking_parties
             SET lead_traveler_name = :lead_traveler_name,
                 contact_mobile = :contact_mobile,
                 passport_number = :passport_number
             WHERE booking_id = :booking_id'
        );
        $updateParty->execute([
            'booking_id' => $bookingId,
            'lead_traveler_name' => $leadTraveler['full_name'] ?? '',
            'contact_mobile' => $leadTraveler['mobile'] ?? null,
            'passport_number' => $leadTraveler['passport_number'] ?? null,
        ]);
    }

    private function nextDisplayOrder(int $bookingId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(display_order), 0) + 1
             FROM booking_travelers
             WHERE booking_id = :booking_id'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return (int) $statement->fetchColumn();
    }
}
