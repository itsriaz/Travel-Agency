<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BusinessSourceRepository;
use App\Repositories\BookingRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;

final class BookingWorkspaceService extends Service
{
    private const ALLOWED_STATUSES = ['draft', 'open', 'confirmed', 'on_hold', 'closed'];

    public function workspaceState(
        array $accessibleBranchIds,
        ?int $bookingId,
        ?string $bookingReference,
        string $searchTerm,
        bool $newMode,
        int $actorUserId
    ): array
    {
        $repository = new BookingRepository($this->app);
        $branchOptions = $repository->branchOptions($accessibleBranchIds);
        $businessSourceOptions = (new BusinessSourceRepository($this->app))->activeOptions();
        $resolvedBookingReference = trim((string) $bookingReference);

        if (! $newMode && ($bookingId === null || $bookingId <= 0) && $resolvedBookingReference !== '') {
            $bookingByReference = $repository->findBookingByReference($resolvedBookingReference, $accessibleBranchIds);
            if ($bookingByReference === null) {
                throw new RuntimeException('No accessible booking found.');
            }

            $bookingId = (int) ($bookingByReference['id'] ?? 0);
        }

        $currentBooking = null;
        if (! $newMode && $bookingId !== null && $bookingId > 0) {
            if (! $repository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
                throw new RuntimeException('The selected booking could not be found in your accessible branches.');
            }

            $currentBooking = $repository->findBookingById($bookingId);

            if ($currentBooking === null) {
                throw new RuntimeException('The selected booking could not be loaded.');
            }

            AuditLog::record($this->app, 'booking.opened', [
                'user_id' => $actorUserId,
                'booking_id' => (int) $currentBooking['id'],
                'booking_reference' => (string) $currentBooking['booking_reference'],
                'branch_id' => (int) $currentBooking['branch_id'],
            ]);
        }

        $searchResults = $repository->searchBookings($searchTerm, $accessibleBranchIds);

        if (! $newMode && $currentBooking === null && $searchTerm !== '') {
            $exactReference = strtoupper($searchTerm);
            $exactBookingId = ctype_digit(trim($searchTerm)) ? (int) trim($searchTerm) : 0;
            foreach ($searchResults as $searchResult) {
                $exactReceiptNo = strtoupper((string) ($searchResult['receipt_no'] ?? ''));
                $exactTicketNo = strtoupper((string) ($searchResult['ticket_number'] ?? ''));
                $exactPnr = strtoupper((string) ($searchResult['pnr'] ?? ''));

                if (
                    ($exactBookingId > 0 && (int) ($searchResult['id'] ?? 0) === $exactBookingId)
                    || ($exactBookingId > 0 && strtoupper((string) ($searchResult['booking_reference'] ?? '')) === 'BK-' . str_pad((string) $exactBookingId, 6, '0', STR_PAD_LEFT))
                    || (
                    strtoupper((string) ($searchResult['booking_reference'] ?? '')) === $exactReference
                    || $exactReceiptNo === $exactReference
                    || $exactTicketNo === $exactReference
                    || $exactPnr === $exactReference
                    )
                ) {
                    $currentBooking = $repository->findBookingById((int) ($searchResult['id'] ?? 0));
                    break;
                }
            }

            if ($currentBooking === null && count($searchResults) === 1) {
                $singleMatch = $searchResults[0];
                $leadTravelerName = mb_strtolower(trim((string) ($singleMatch['lead_traveler_name'] ?? '')));
                $normalizedSearchTerm = mb_strtolower(trim($searchTerm));

                if (
                    $normalizedSearchTerm !== ''
                    && $leadTravelerName !== ''
                    && str_contains($leadTravelerName, $normalizedSearchTerm)
                ) {
                    $currentBooking = $repository->findBookingById((int) ($singleMatch['id'] ?? 0));
                }
            }
        }

        return [
            'branchOptions' => $branchOptions,
            'businessSourceOptions' => $businessSourceOptions,
            'currentBooking' => $currentBooking,
            'searchResults' => $searchResults,
            'searchTerm' => trim($searchTerm),
        ];
    }

    public function saveBooking(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new BookingRepository($this->app);
        $payload = $this->validatedPayload($input, $accessibleBranchIds);
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $selectedTravelerId = (int) (($input['selected_customer_id'] ?? 0) ?: ($input['selected_traveler_id'] ?? 0));

        if ($bookingId > 0) {
            if (! $repository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
                throw new RuntimeException('You cannot update a booking outside your accessible branches.');
            }

            $priorBooking = $repository->findBookingById($bookingId);
            if ($priorBooking === null) {
                throw new RuntimeException('The booking could not be loaded for update.');
            }

            $this->assertCanUseStatus($repository, $bookingId, (string) $payload['booking']['booking_status']);

            $repository->updateBooking(
                $bookingId,
                array_merge($payload['booking'], ['actor_user_id' => $actorUserId]),
                $payload['party']
            );

            if ($selectedTravelerId > 0) {
                $travelerRepository = new TravelerRepository($this->app);
                if (! $travelerRepository->travelerExistsInBranches($selectedTravelerId, $accessibleBranchIds)) {
                    throw new RuntimeException('The selected customer profile is outside your accessible branches.');
                }

                $travelerRepository->attachTravelerToBooking($bookingId, $selectedTravelerId, 'lead', $actorUserId);
            }

            $savedBooking = $repository->findBookingById($bookingId);

            if ($savedBooking === null) {
                throw new RuntimeException('The booking was updated but could not be reloaded.');
            }

            AuditLog::record($this->app, 'booking.updated', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $savedBooking['booking_reference'],
                'prior_branch_id' => (int) $priorBooking['branch_id'],
                'branch_id' => (int) $savedBooking['branch_id'],
                'branch_changed' => (int) $priorBooking['branch_id'] !== (int) $savedBooking['branch_id'],
                'booking_status' => (string) $savedBooking['booking_status'],
            ]);

            return [
                'booking' => $savedBooking,
                'action' => 'updated',
            ];
        }

        if ((string) $payload['booking']['booking_status'] === 'closed') {
            throw new RuntimeException('A new invoice cannot be created as Closed. Save the invoice, complete services and payments, then close it.');
        }

        $newBookingId = $repository->createBooking(
            array_merge($payload['booking'], ['actor_user_id' => $actorUserId]),
            $payload['party']
        );
            $savedBooking = $repository->findBookingById($newBookingId);

            if ($savedBooking === null) {
                throw new RuntimeException('The booking was created but could not be reloaded.');
            }

            if ($selectedTravelerId > 0) {
                $travelerRepository = new TravelerRepository($this->app);
                if (! $travelerRepository->travelerExistsInBranches($selectedTravelerId, $accessibleBranchIds)) {
                    throw new RuntimeException('The selected customer profile is outside your accessible branches.');
                }

                $travelerRepository->attachTravelerToBooking($newBookingId, $selectedTravelerId, 'lead', $actorUserId);
                $savedBooking = $repository->findBookingById($newBookingId) ?? $savedBooking;
            }

            AuditLog::record($this->app, 'booking.created', [
            'user_id' => $actorUserId,
            'booking_id' => $newBookingId,
            'booking_reference' => (string) $savedBooking['booking_reference'],
            'branch_id' => (int) $savedBooking['branch_id'],
            'booking_status' => (string) $savedBooking['booking_status'],
        ]);

        return [
            'booking' => $savedBooking,
            'action' => 'created',
        ];
    }

    private function assertCanUseStatus(BookingRepository $repository, int $bookingId, string $status): void
    {
        if ($status !== 'closed') {
            return;
        }

        $totals = $repository->closeReadinessTotals($bookingId);
        if ((int) $totals['serviceCount'] <= 0) {
            throw new RuntimeException('Invoice cannot be closed before at least one active service is saved.');
        }

        if ((float) $totals['customerOutstanding'] > 0.005 || (float) $totals['supplierOutstanding'] > 0.005) {
            throw new RuntimeException(
                'Invoice cannot be closed while customer outstanding or supplier payable balance is still open.'
            );
        }
    }

    private function validatedPayload(array $input, array $accessibleBranchIds): array
    {
        $branchId = (int) ($input['branch_id'] ?? 0);
        if (! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch.');
        }

        $status = mb_strtolower(trim((string) ($input['booking_status'] ?? 'draft')));
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new RuntimeException('Please select a valid booking status.');
        }

        $businessSourceRepository = new BusinessSourceRepository($this->app);
        $businessSourceId = (int) ($input['business_source_id'] ?? 0);
        if ($businessSourceId <= 0) {
            $businessSourceId = $businessSourceRepository->defaultId();
        }

        $businessSource = $businessSourceRepository->findById($businessSourceId);
        if ($businessSource === null || (int) ($businessSource['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Please select a valid account.');
        }

        $bookingDate = $this->normalizeDate((string) ($input['booking_date'] ?? ''), 'Booking date');
        $departureDate = $this->normalizeOptionalDate((string) ($input['departure_date'] ?? ''));
        $returnDate = $this->normalizeOptionalDate((string) ($input['return_date'] ?? ''));
        $dueDate = $this->normalizeOptionalDate((string) ($input['due_date'] ?? ''));

        if ($departureDate !== null && $returnDate !== null && $returnDate < $departureDate) {
            throw new RuntimeException('Return date cannot be earlier than departure date.');
        }

        return [
            'booking' => [
                'branch_id' => $branchId,
                'business_source_id' => $businessSourceId,
                'booking_status' => $status,
                'booking_date' => $bookingDate,
                'due_date' => $dueDate,
                'departure_date' => $departureDate,
                'return_date' => $returnDate,
                'remarks' => $this->optionalText($input['remarks'] ?? null, 4000),
            ],
            'party' => [
                'party_label' => $this->requiredText($input['party_label'] ?? 'Lead Traveler / Booking Party', 'Party label', 120),
                'lead_traveler_name' => $this->requiredText($input['lead_traveler_name'] ?? null, 'Lead traveler / booking party', 190),
                'contact_mobile' => $this->optionalText($input['contact_mobile'] ?? null, 50),
                'passport_number' => $this->optionalText($input['passport_number'] ?? null, 50),
                'notes' => $this->optionalText($input['party_notes'] ?? null, 4000),
            ],
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
            throw new RuntimeException('One of the optional booking values exceeds the allowed length.');
        }

        return $text;
    }

    private function normalizeDate(string $value, string $label): string
    {
        $date = trim($value);

        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException($label . ' is required.');
        }

        return $date;
    }

    private function normalizeOptionalDate(string $value): ?string
    {
        $date = trim($value);

        if ($date === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('One of the booking dates is invalid.');
        }

        return $date;
    }
}
