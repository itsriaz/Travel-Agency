<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;

final class TravelerWorkspaceService extends Service
{
    private const ALLOWED_GENDERS = ['male', 'female', 'other', 'unspecified'];
    private const ALLOWED_ROLES = ['lead', 'additional'];
    private const ALLOWED_COLOR_TAGS = ['none', 'green', 'blue', 'orange', 'red', 'gold', 'purple'];

    public function travelerState(?int $bookingId, array $accessibleBranchIds, string $searchTerm): array
    {
        $travelers = [];
        $searchResults = (new TravelerRepository($this->app))->searchTravelers($searchTerm, $accessibleBranchIds, 200);

        if ($bookingId !== null && $bookingId > 0) {
            $bookingRepository = new BookingRepository($this->app);
            if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected booking is outside your accessible branches.');
            }

            $travelers = (new TravelerRepository($this->app))->travelersForBooking($bookingId);
        }

        return [
            'travelers' => $travelers,
            'travelerSearchResults' => $searchResults,
            'travelerSearchTerm' => trim($searchTerm),
        ];
    }

    public function saveTraveler(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        $role = $this->normalizeRole((string) ($input['traveler_role'] ?? 'additional'));
        $repository = new TravelerRepository($this->app);

        if ($bookingId <= 0) {
            $payload = $this->validatedTravelerPayload($input, $accessibleBranchIds, (int) ($accessibleBranchIds[0] ?? 0));

            if ($travelerId > 0) {
                if (! $repository->travelerExistsInBranches($travelerId, $accessibleBranchIds)) {
                    throw new RuntimeException('The selected customer profile is outside your accessible branches.');
                }

                $repository->updateTraveler($travelerId, array_merge($payload, ['actor_user_id' => $actorUserId]));
                $traveler = $repository->findTravelerById($travelerId);

                AuditLog::record($this->app, 'traveler.updated', [
                    'user_id' => $actorUserId,
                    'traveler_id' => $travelerId,
                    'traveler_role' => $role,
                    'mode' => 'standalone_profile',
                ]);

                return [
                    'traveler' => $traveler,
                    'action' => 'updated',
                    'booking_id' => 0,
                ];
            }

            $newTravelerId = $repository->createTraveler(array_merge($payload, ['actor_user_id' => $actorUserId]));
            $traveler = $repository->findTravelerById($newTravelerId);

            AuditLog::record($this->app, 'traveler.created', [
                'user_id' => $actorUserId,
                'traveler_id' => $newTravelerId,
                'traveler_role' => $role,
                'mode' => 'standalone_profile',
            ]);

            return [
                'traveler' => $traveler,
                'action' => 'created',
                'booking_id' => 0,
            ];
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage travelers for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $payload = $this->validatedTravelerPayload($input, $accessibleBranchIds, (int) $booking['branch_id']);

        if ($travelerId > 0) {
            if (! $repository->travelerExistsInBranches($travelerId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected traveler is outside your accessible branches.');
            }

            $repository->updateTraveler($travelerId, array_merge($payload, ['actor_user_id' => $actorUserId]));

            if (! $repository->travelerAttachedToBooking($bookingId, $travelerId)) {
                $repository->attachTravelerToBooking($bookingId, $travelerId, $role, $actorUserId);
                AuditLog::record($this->app, 'traveler.attached', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) $booking['booking_reference'],
                    'traveler_id' => $travelerId,
                    'traveler_role' => $role,
                ]);
            } elseif ($role === 'lead') {
                $repository->attachTravelerToBooking($bookingId, $travelerId, 'lead', $actorUserId);
            }

            $traveler = $repository->findTravelerById($travelerId);

            AuditLog::record($this->app, 'traveler.updated', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $booking['booking_reference'],
                'traveler_id' => $travelerId,
                'traveler_role' => $role,
            ]);

            return [
                'traveler' => $traveler,
                'action' => 'updated',
                'booking_id' => $bookingId,
            ];
        }

        $newTravelerId = $repository->createTraveler(array_merge($payload, ['actor_user_id' => $actorUserId]));
        $repository->attachTravelerToBooking($bookingId, $newTravelerId, $role, $actorUserId);
        $traveler = $repository->findTravelerById($newTravelerId);

        AuditLog::record($this->app, 'traveler.created', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'traveler_id' => $newTravelerId,
            'traveler_role' => $role,
        ]);
        AuditLog::record($this->app, 'traveler.attached', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'traveler_id' => $newTravelerId,
            'traveler_role' => $role,
        ]);

        return [
            'traveler' => $traveler,
            'action' => 'created',
            'booking_id' => $bookingId,
        ];
    }

    public function attachExistingTraveler(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $travelerId = (int) ($input['traveler_id'] ?? 0);
        $role = $this->normalizeRole((string) ($input['traveler_role'] ?? 'additional'));

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot attach travelers to a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $travelerRepository = new TravelerRepository($this->app);

        if ($booking === null || ! $travelerRepository->travelerExistsInBranches($travelerId, $accessibleBranchIds)) {
            throw new RuntimeException('The selected traveler could not be attached.');
        }

        $travelerRepository->attachTravelerToBooking($bookingId, $travelerId, $role, $actorUserId);

        AuditLog::record($this->app, 'traveler.attached', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'traveler_id' => $travelerId,
            'traveler_role' => $role,
        ]);

        return $bookingId;
    }

    public function travelerProfile(int $travelerId, array $accessibleBranchIds): ?array
    {
        $repository = new TravelerRepository($this->app);
        if (! $repository->travelerExistsInBranches($travelerId, $accessibleBranchIds)) {
            return null;
        }

        return $repository->findTravelerById($travelerId);
    }

    public function resolveAutosaveCustomer(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new TravelerRepository($this->app);
        $selectedTravelerId = (int) (($input['selected_customer_id'] ?? 0) ?: ($input['selected_traveler_id'] ?? 0));
        if ($selectedTravelerId > 0) {
            if (! $repository->travelerExistsInBranches($selectedTravelerId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected customer profile is outside your accessible branches.');
            }

            $traveler = $repository->findTravelerById($selectedTravelerId);
            if ($traveler === null) {
                throw new RuntimeException('The selected customer could not be loaded.');
            }

            return $traveler;
        }

        $branchId = (int) ($input['branch_id'] ?? $input['traveler_branch_id'] ?? 0);
        if (! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch for the customer.');
        }

        $fullName = trim((string) ($input['lead_traveler_name'] ?? $input['full_name'] ?? ''));
        if ($fullName === '') {
            throw new RuntimeException('Select or enter a customer name first.');
        }

        if (mb_strlen($fullName) < 3) {
            throw new RuntimeException('Enter a more complete customer name before autosave.');
        }

        $traveler = $repository->findAccessibleTravelerByExactName($fullName, $accessibleBranchIds, $branchId);
        if ($traveler === null) {
            $traveler = $repository->findAccessibleTravelerByExactName($fullName, $accessibleBranchIds);
        }

        if ($traveler !== null) {
            return $traveler;
        }

        $created = $this->saveTraveler([
            'booking_id' => 0,
            'traveler_branch_id' => $branchId,
            'traveler_role' => 'lead',
            'first_name' => $fullName,
            'last_name' => '',
            'full_name' => $fullName,
            'gender' => 'unspecified',
            'mobile' => $input['contact_mobile'] ?? $input['mobile'] ?? '',
            'passport_number' => $input['passport_number'] ?? '',
            'notes' => 'Auto-created from workspace invoice autosave.',
        ], $actorUserId, $accessibleBranchIds);

        return $created['traveler'];
    }

    public function removeTraveler(array $input, int $actorUserId, array $accessibleBranchIds): int
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $travelerId = (int) ($input['traveler_id'] ?? 0);

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot remove travelers from a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        $travelerRepository = new TravelerRepository($this->app);

        if ($booking === null || ! $travelerRepository->travelerAttachedToBooking($bookingId, $travelerId)) {
            throw new RuntimeException('The selected traveler is not attached to this booking.');
        }

        $travelers = $travelerRepository->travelersForBooking($bookingId);
        $attachedCount = count($travelers);
        $targetRow = null;
        foreach ($travelers as $travelerRow) {
            if ((int) $travelerRow['id'] === $travelerId) {
                $targetRow = $travelerRow;
                break;
            }
        }

        if ($targetRow !== null && (string) $targetRow['traveler_role'] === 'lead' && $attachedCount <= 1) {
            throw new RuntimeException('A booking must keep one lead traveler / booking party attached.');
        }

        $travelerRepository->removeTravelerFromBooking($bookingId, $travelerId);

        if ($targetRow !== null && (string) $targetRow['traveler_role'] === 'lead' && $travelerRepository->bookingHasTravelers($bookingId)) {
            $remaining = $travelerRepository->travelersForBooking($bookingId);
            if ($remaining !== []) {
                $travelerRepository->attachTravelerToBooking($bookingId, (int) $remaining[0]['id'], 'lead', $actorUserId);
            }
        }

        AuditLog::record($this->app, 'traveler.removed', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'traveler_id' => $travelerId,
        ]);

        return $bookingId;
    }

    private function validatedTravelerPayload(array $input, array $accessibleBranchIds, int $defaultBranchId): array
    {
        $branchId = (int) ($input['traveler_branch_id'] ?? $defaultBranchId);
        if (! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch for the traveler.');
        }

        $passportExpiry = $this->normalizeOptionalDate((string) ($input['passport_expiry'] ?? ''));
        $dateOfBirth = $this->normalizeOptionalDate((string) ($input['date_of_birth'] ?? ''));
        $firstName = $this->requiredText($input['first_name'] ?? null, 'First name', 100);
        $lastName = $this->optionalText($input['last_name'] ?? null, 100);
        $fullName = $this->buildFullName($firstName, $lastName, $input['full_name'] ?? null);

        if ($passportExpiry !== null && $dateOfBirth !== null && $passportExpiry <= $dateOfBirth) {
            throw new RuntimeException('Passport expiry must be later than date of birth.');
        }

        $currentResidence = $this->optionalText($input['current_residence'] ?? null, 255);
        $permanentResidence = $this->optionalText($input['permanent_residence'] ?? null, 255);
        $address = $this->optionalText($input['address'] ?? null, 255) ?? $currentResidence ?? $permanentResidence;

        return [
            'branch_id' => $branchId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'passport_number' => $this->optionalText($input['passport_number'] ?? null, 50),
            'nationality' => $this->optionalText($input['nationality'] ?? null, 120),
            'date_of_birth' => $dateOfBirth,
            'gender' => $this->normalizeGender((string) ($input['gender'] ?? 'unspecified')),
            'passport_expiry' => $passportExpiry,
            'mobile' => $this->optionalText($input['mobile'] ?? null, 50),
            'address' => $address,
            'permanent_residence' => $permanentResidence,
            'current_residence' => $currentResidence,
            'occupation' => $this->optionalText($input['occupation'] ?? null, 120),
            'village' => $this->optionalText($input['village'] ?? null, 120),
            'district' => $this->optionalText($input['district'] ?? null, 120),
            'family_id' => $this->optionalText($input['family_id'] ?? null, 60),
            'color_tag' => $this->normalizeColorTag((string) ($input['color_tag'] ?? 'none')),
            'notes' => $this->optionalText($input['notes'] ?? null, 4000),
        ];
    }

    private function requiredText(mixed $value, string $label, int $maxLength): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw new RuntimeException($label . ' is required.');
        }

        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException($label . ' exceeds the allowed length.');
        }

        return $text;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('One of the traveler values exceeds the allowed length.');
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
            throw new RuntimeException('One of the traveler dates is invalid.');
        }

        return $date;
    }

    private function normalizeGender(string $value): string
    {
        $gender = mb_strtolower(trim($value));

        if (! in_array($gender, self::ALLOWED_GENDERS, true)) {
            throw new RuntimeException('Please select a valid gender.');
        }

        return $gender;
    }

    private function normalizeRole(string $value): string
    {
        $role = mb_strtolower(trim($value));

        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw new RuntimeException('Please select a valid traveler role.');
        }

        return $role;
    }

    private function normalizeColorTag(string $value): ?string
    {
        $colorTag = mb_strtolower(trim($value));
        if ($colorTag === '' || $colorTag === 'none') {
            return null;
        }

        if (! in_array($colorTag, self::ALLOWED_COLOR_TAGS, true)) {
            throw new RuntimeException('Please select a valid customer color tag.');
        }

        return $colorTag;
    }

    private function buildFullName(string $firstName, ?string $lastName, mixed $legacyFullName): string
    {
        $name = trim($firstName . ' ' . (string) ($lastName ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $this->requiredText($legacyFullName, 'Full name', 190);
    }

}
