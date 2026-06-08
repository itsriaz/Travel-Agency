<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingReminderRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use RuntimeException;

final class ReminderWorkspaceService extends Service
{
    private const REMINDER_TYPES = [
        'due_date' => 'Due Date Reminder',
        'passport_expiry' => 'Passport Expiry Reminder',
        'visa_expiry' => 'Visa Expiry Reminder',
        'supplier_payment' => 'Supplier Payment Reminder',
        'document_missing' => 'Document Missing Reminder',
        'travel_date' => 'Travel Date Reminder',
        'custom_manual' => 'Custom Manual Reminder',
    ];

    private const REMINDER_CHANNELS = ['Call', 'WhatsApp', 'Email', 'Counter Follow-Up', 'System'];

    public function reminderState(
        ?int $bookingId,
        array $accessibleBranchIds,
        ?int $customerId,
        ?array $bookingRecord,
        array $travelers,
        array $services,
        array $customerPaymentFoundation,
        array $supplierFoundation,
        array $documents,
        ?int $editingReminderId,
        int $actorUserId
    ): array {
        $receivableAlerts = $this->receivableAlertState($accessibleBranchIds, $bookingId, $customerId, $bookingRecord);

        if ($bookingId === null || $bookingId <= 0 || $bookingRecord === null) {
            return [
                'reminders' => [],
                'receivableAlerts' => $receivableAlerts,
                'reminderTypeOptions' => self::REMINDER_TYPES,
                'reminderChannels' => self::REMINDER_CHANNELS,
                'reminderLinkTargets' => [],
                'editingReminder' => null,
            ];
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot open reminders for a booking outside your accessible branches.');
        }

        $repository = new BookingReminderRepository($this->app);
        $activeKeys = [];
        foreach ($this->systemReminderSpecs($bookingRecord, $travelers, $services, $supplierFoundation, $documents, $actorUserId) as $spec) {
            $repository->upsertSystemReminder($spec);
            $activeKeys[] = (string) $spec['reminder_key'];
        }

        $repository->completeInactiveSystemReminders($bookingId, $activeKeys, $actorUserId);
        $reminders = $repository->remindersForBooking($bookingId);

        $editingReminder = null;
        if ($editingReminderId !== null && $editingReminderId > 0) {
            $editingReminder = $repository->findReminderById($editingReminderId);
            if ($editingReminder === null || (int) $editingReminder['booking_id'] !== $bookingId) {
                throw new RuntimeException('The selected reminder could not be opened for editing.');
            }
        }

        return [
            'reminders' => $reminders,
            'receivableAlerts' => $receivableAlerts,
            'reminderTypeOptions' => self::REMINDER_TYPES,
            'reminderChannels' => self::REMINDER_CHANNELS,
            'reminderLinkTargets' => $this->linkTargets($bookingId, $travelers, $services, $customerPaymentFoundation, $supplierFoundation),
            'editingReminder' => $editingReminder,
        ];
    }

    public function saveReminder(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $booking = $this->loadBooking($bookingId, $accessibleBranchIds);
        $payload = $this->validatedManualPayload($input, $bookingId);
        $repository = new BookingReminderRepository($this->app);
        $reminderId = (int) ($input['reminder_id'] ?? 0);

        if ($reminderId > 0) {
            $existing = $repository->findReminderById($reminderId);
            if ($existing === null || (int) $existing['booking_id'] !== $bookingId) {
                throw new RuntimeException('The selected reminder could not be updated for this booking.');
            }

            if ((int) ($existing['system_generated'] ?? 0) === 1) {
                throw new RuntimeException('System-generated reminders cannot be edited manually.');
            }

            $repository->updateReminder($reminderId, array_merge($payload, ['actor_user_id' => $actorUserId]));
            AuditLog::record($this->app, 'reminder.updated', [
                'user_id' => $actorUserId,
                'booking_id' => $bookingId,
                'booking_reference' => (string) $booking['booking_reference'],
                'reminder_id' => $reminderId,
                'reminder_type' => $payload['reminder_type'],
                'due_at' => $payload['due_at'],
            ]);

            return [
                'booking_id' => $bookingId,
                'action' => 'updated',
            ];
        }

        $newReminderId = $repository->createReminder(array_merge($payload, [
            'branch_id' => (int) $booking['branch_id'],
            'booking_id' => $bookingId,
            'system_generated' => 0,
            'reminder_key' => null,
            'actor_user_id' => $actorUserId,
        ]));

        AuditLog::record($this->app, 'reminder.created', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) $booking['booking_reference'],
            'reminder_id' => $newReminderId,
            'reminder_type' => $payload['reminder_type'],
            'due_at' => $payload['due_at'],
        ]);

        return [
            'booking_id' => $bookingId,
            'action' => 'created',
        ];
    }

    public function completeReminder(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        return $this->transitionReminder((int) ($input['reminder_id'] ?? 0), 'completed', $actorUserId, $accessibleBranchIds);
    }

    public function dismissReminder(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        return $this->transitionReminder((int) ($input['reminder_id'] ?? 0), 'dismissed', $actorUserId, $accessibleBranchIds);
    }

    private function transitionReminder(int $reminderId, string $status, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new BookingReminderRepository($this->app);
        $reminder = $repository->findReminderById($reminderId);
        if ($reminder === null) {
            throw new RuntimeException('The selected reminder could not be found.');
        }

        $booking = $this->loadBooking((int) $reminder['booking_id'], $accessibleBranchIds);
        $repository->markReminderStatus($reminderId, $status, $actorUserId);

        AuditLog::record($this->app, 'reminder.' . $status, [
            'user_id' => $actorUserId,
            'booking_id' => (int) $reminder['booking_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'reminder_id' => $reminderId,
            'reminder_type' => (string) $reminder['reminder_type'],
        ]);

        return [
            'booking_id' => (int) $reminder['booking_id'],
        ];
    }

    private function loadBooking(int $bookingId, array $accessibleBranchIds): array
    {
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage reminders for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        return $booking;
    }

    private function systemReminderSpecs(
        array $bookingRecord,
        array $travelers,
        array $services,
        array $supplierFoundation,
        array $documents,
        int $actorUserId
    ): array {
        $bookingId = (int) ($bookingRecord['id'] ?? 0);
        $branchId = (int) ($bookingRecord['branch_id'] ?? 0);
        $specs = [];

        $departureDate = trim((string) ($bookingRecord['departure_date'] ?? ''));
        if ($departureDate !== '') {
            $dueAt = $departureDate . ' 09:00:00';
            $specs[] = $this->baseSystemSpec($branchId, $bookingId, $actorUserId, [
                'reminder_type' => 'travel_date',
                'title' => 'Travel date follow-up',
                'reminder_note' => 'Travel date reminder linked to the booking departure date.',
                'due_at' => $dueAt,
                'channel' => 'System',
                'owner_label' => 'Operations Desk',
                'status' => $this->statusForDueAt($dueAt),
                'priority' => 'high',
                'reminder_key' => 'travel_date:booking:' . $bookingId . ':' . $departureDate,
            ]);
        }

        foreach ($travelers as $traveler) {
            $travelerId = (int) ($traveler['id'] ?? 0);
            $passportExpiry = trim((string) ($traveler['passport_expiry'] ?? ''));
            if ($travelerId > 0 && $passportExpiry !== '') {
                $passportExpiryDate = \DateTimeImmutable::createFromFormat('Y-m-d', $passportExpiry);
                if (! $passportExpiryDate instanceof \DateTimeImmutable) {
                    continue;
                }

                $dueAt = $passportExpiryDate->modify('-6 months')->format('Y-m-d') . ' 09:00:00';
                $specs[] = $this->baseSystemSpec($branchId, $bookingId, $actorUserId, [
                    'traveler_id' => $travelerId,
                    'reminder_type' => 'passport_expiry',
                    'title' => 'Passport expiry check / ' . (string) ($traveler['full_name'] ?? 'Traveler'),
                    'reminder_note' => 'Passport expiry reminder linked to traveler record and scheduled 6 months before expiry.',
                    'due_at' => $dueAt,
                    'channel' => 'System',
                    'owner_label' => 'Documentation Desk',
                    'status' => $this->statusForDueAt($dueAt),
                    'priority' => 'high',
                    'reminder_key' => 'passport_expiry:traveler:' . $travelerId . ':' . $passportExpiry,
                ]);
            }
        }

        foreach ($services as $service) {
            $serviceId = (int) ($service['id'] ?? 0);
            $dueDate = trim((string) ($service['due_date'] ?? ''));
            if ($serviceId > 0 && $dueDate !== '') {
                $dueAt = $dueDate . ' 09:00:00';
                $specs[] = $this->baseSystemSpec($branchId, $bookingId, $actorUserId, [
                    'booking_service_id' => $serviceId,
                    'reminder_type' => 'due_date',
                    'title' => 'Service due date / ' . (string) ($service['line_reference'] ?? 'SV'),
                    'reminder_note' => 'Service due date reminder linked to booking service line.',
                    'due_at' => $dueAt,
                    'channel' => 'System',
                    'owner_label' => 'Operations Desk',
                    'status' => $this->statusForDueAt($dueAt),
                    'priority' => 'normal',
                    'reminder_key' => 'due_date:service:' . $serviceId . ':' . $dueDate,
                ]);
            }
        }

        foreach (($supplierFoundation['openObligations'] ?? []) as $obligation) {
            $obligationId = (int) ($obligation['id'] ?? 0);
            $dueDate = trim((string) ($obligation['dueDate'] ?? ''));
            if ($obligationId > 0 && $dueDate !== '') {
                $dueAt = $dueDate . ' 09:00:00';
                $specs[] = $this->baseSystemSpec($branchId, $bookingId, $actorUserId, [
                    'supplier_obligation_id' => $obligationId,
                    'reminder_type' => 'supplier_payment',
                    'title' => 'Supplier payment due / ' . (string) ($obligation['supplier'] ?? 'Supplier'),
                    'reminder_note' => 'Supplier payable reminder linked to open supplier obligation.',
                    'due_at' => $dueAt,
                    'channel' => 'System',
                    'owner_label' => 'Accounts Desk',
                    'status' => $this->statusForDueAt($dueAt),
                    'priority' => 'high',
                    'reminder_key' => 'supplier_payment:obligation:' . $obligationId . ':' . $dueDate,
                ]);
            }
        }

        $hasActiveDocuments = count(array_filter($documents, static fn (array $document): bool => (string) ($document['status'] ?? '') === 'active')) > 0;
        if (! $hasActiveDocuments && $bookingId > 0) {
            $today = date('Y-m-d');
            $specs[] = $this->baseSystemSpec($branchId, $bookingId, $actorUserId, [
                'reminder_type' => 'document_missing',
                'title' => 'Documents missing from booking file',
                'reminder_note' => 'No active booking-linked documents are currently stored for this booking.',
                'due_at' => $today . ' 09:00:00',
                'channel' => 'System',
                'owner_label' => 'Documentation Desk',
                'status' => $this->statusForDueAt($today . ' 09:00:00'),
                'priority' => 'high',
                'reminder_key' => 'document_missing:booking:' . $bookingId,
            ]);
        }

        return $specs;
    }

    private function baseSystemSpec(int $branchId, int $bookingId, int $actorUserId, array $data): array
    {
        return array_merge([
            'branch_id' => $branchId,
            'booking_id' => $bookingId,
            'traveler_id' => null,
            'booking_service_id' => null,
            'customer_receipt_id' => null,
            'supplier_payment_id' => null,
            'supplier_obligation_id' => null,
            'system_generated' => 1,
            'actor_user_id' => $actorUserId,
        ], $data);
    }

    private function validatedManualPayload(array $input, int $bookingId): array
    {
        $type = $this->normalizeReminderType((string) ($input['reminder_type'] ?? 'custom_manual'));
        $dueAt = $this->normalizeDateTime((string) ($input['due_at'] ?? ''), 'Due date / time');
        $status = $this->statusForDueAt($dueAt);
        $linkage = $this->resolveLinkage($bookingId, (string) ($input['linked_target'] ?? 'booking:' . $bookingId));

        return array_merge($linkage, [
            'reminder_type' => $type,
            'title' => $this->requiredText($input['title'] ?? null, 'Reminder task', 190),
            'reminder_note' => $this->optionalText($input['reminder_note'] ?? null, 4000),
            'due_at' => $dueAt,
            'channel' => $this->normalizeChannel((string) ($input['channel'] ?? 'Call')),
            'owner_label' => $this->optionalText($input['owner_label'] ?? null, 120),
            'status' => $status,
            'priority' => $this->normalizePriority((string) ($input['priority'] ?? 'normal')),
        ]);
    }

    private function resolveLinkage(int $bookingId, string $linkedTarget): array
    {
        $normalized = trim($linkedTarget);
        if ($normalized === '') {
            $normalized = 'booking:' . $bookingId;
        }

        [$scope, $idValue] = array_pad(explode(':', $normalized, 2), 2, '');
        $scope = trim($scope);
        $linkedId = (int) $idValue;
        $repository = new BookingReminderRepository($this->app);

        $payload = [
            'traveler_id' => null,
            'booking_service_id' => null,
            'customer_receipt_id' => null,
            'supplier_payment_id' => null,
            'supplier_obligation_id' => null,
        ];

        if ($scope === '' || $scope === 'booking') {
            return $payload;
        }

        return match ($scope) {
            'traveler' => $this->assertTravelerLink($repository, $bookingId, $linkedId, $payload),
            'service' => $this->assertServiceLink($repository, $bookingId, $linkedId, $payload),
            'receipt' => $this->assertReceiptLink($repository, $bookingId, $linkedId, $payload),
            'supplier_payment' => $this->assertSupplierPaymentLink($repository, $bookingId, $linkedId, $payload),
            'supplier_obligation' => $this->assertSupplierObligationLink($repository, $bookingId, $linkedId, $payload),
            default => throw new RuntimeException('Please select a valid reminder linkage target.'),
        };
    }

    private function assertTravelerLink(BookingReminderRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->bookingTravelerBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid traveler linked to this booking.');
        }

        $payload['traveler_id'] = $linkedId;
        return $payload;
    }

    private function assertServiceLink(BookingReminderRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->serviceBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid service line linked to this booking.');
        }

        $payload['booking_service_id'] = $linkedId;
        return $payload;
    }

    private function assertReceiptLink(BookingReminderRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->receiptBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid customer receipt linked to this booking.');
        }

        $payload['customer_receipt_id'] = $linkedId;
        return $payload;
    }

    private function assertSupplierPaymentLink(BookingReminderRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->supplierPaymentBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid supplier payment linked to this booking.');
        }

        $payload['supplier_payment_id'] = $linkedId;
        return $payload;
    }

    private function assertSupplierObligationLink(BookingReminderRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->supplierObligationBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid supplier obligation linked to this booking.');
        }

        $payload['supplier_obligation_id'] = $linkedId;
        return $payload;
    }

    private function linkTargets(
        int $bookingId,
        array $travelers,
        array $services,
        array $customerPaymentFoundation,
        array $supplierFoundation
    ): array {
        $targets = [
            ['value' => 'booking:' . $bookingId, 'label' => 'Booking File'],
        ];

        foreach ($travelers as $traveler) {
            $travelerId = (int) ($traveler['id'] ?? 0);
            if ($travelerId > 0) {
                $targets[] = [
                    'value' => 'traveler:' . $travelerId,
                    'label' => 'Traveler: ' . (string) ($traveler['full_name'] ?? 'Traveler'),
                ];
            }
        }

        foreach ($services as $service) {
            $serviceId = (int) ($service['id'] ?? 0);
            if ($serviceId > 0) {
                $targets[] = [
                    'value' => 'service:' . $serviceId,
                    'label' => 'Service: ' . (string) ($service['line_reference'] ?? 'SV') . ' / ' . ucwords((string) ($service['service_type'] ?? 'service')),
                ];
            }
        }

        foreach (($customerPaymentFoundation['receipts'] ?? []) as $receipt) {
            $receiptId = (int) ($receipt['id'] ?? 0);
            if ($receiptId > 0) {
                $targets[] = [
                    'value' => 'receipt:' . $receiptId,
                    'label' => 'Customer Receipt: ' . (string) ($receipt['receiptNo'] ?? 'RCPT'),
                ];
            }
        }

        foreach (($supplierFoundation['payments'] ?? []) as $payment) {
            $paymentId = (int) ($payment['id'] ?? 0);
            if ($paymentId > 0) {
                $targets[] = [
                    'value' => 'supplier_payment:' . $paymentId,
                    'label' => 'Supplier Payment: ' . (string) ($payment['paymentNo'] ?? 'SPAY'),
                ];
            }
        }

        foreach (($supplierFoundation['openObligations'] ?? []) as $obligation) {
            $obligationId = (int) ($obligation['id'] ?? 0);
            if ($obligationId > 0) {
                $targets[] = [
                    'value' => 'supplier_obligation:' . $obligationId,
                    'label' => 'Supplier Obligation: ' . (string) ($obligation['supplier'] ?? 'Supplier') . ' / ' . (string) ($obligation['serviceLineReference'] ?? 'Service'),
                ];
            }
        }

        return $targets;
    }

    private function normalizeReminderType(string $value): string
    {
        $type = trim($value);
        if (! array_key_exists($type, self::REMINDER_TYPES)) {
            throw new RuntimeException('Please select a valid reminder type.');
        }

        return $type;
    }

    private function normalizeChannel(string $value): string
    {
        $channel = trim($value);
        if (! in_array($channel, self::REMINDER_CHANNELS, true)) {
            throw new RuntimeException('Please select a valid reminder channel.');
        }

        return $channel;
    }

    private function normalizePriority(string $value): string
    {
        $priority = strtolower(trim($value));
        if (! in_array($priority, ['normal', 'high'], true)) {
            throw new RuntimeException('Please select a valid reminder priority.');
        }

        return $priority;
    }

    private function normalizeDateTime(string $value, string $label): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new RuntimeException($label . ' is required.');
        }

        $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $trimmed)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $trimmed);

        if (! $dateTime instanceof \DateTimeImmutable) {
            throw new RuntimeException($label . ' is invalid.');
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    private function statusForDueAt(string $dueAt): string
    {
        return strtotime($dueAt) <= time() ? 'due' : 'open';
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
            throw new RuntimeException('One of the reminder values exceeds the allowed length.');
        }

        return $text;
    }

    private function receivableAlertState(array $accessibleBranchIds, ?int $bookingId, ?int $customerId, ?array $bookingRecord): array
    {
        $resolvedCustomerId = (int) ($customerId ?? 0);
        if ($resolvedCustomerId <= 0) {
            return $this->emptyReceivableAlertState();
        }

        $bookingReference = trim((string) ($bookingRecord['booking_reference'] ?? ''));
        $customerName = trim((string) (($bookingRecord['lead_traveler_name'] ?? '') !== ''
            ? $bookingRecord['lead_traveler_name']
            : ($bookingRecord['party_label'] ?? '')));
        if ($customerName === '') {
            $customerName = 'Customer pending';
        }

        $contactMobile = trim((string) ($bookingRecord['contact_mobile'] ?? ''));
        $rows = (new CustomerPaymentRepository($this->app))->openReceivablesDetailedForLeadTraveler($resolvedCustomerId, $accessibleBranchIds);
        $customerRows = [];
        $bookingRows = [];

        foreach ($rows as $row) {
            $currentRowBookingReference = trim((string) ($row['booking_reference'] ?? ''));
            $serviceSummary = trim((string) ($row['service_type'] ?? ''));
            if ($serviceSummary === '') {
                $serviceSummary = 'Service summary pending';
            } else {
                $serviceSummary = ucwords(str_replace('_', ' ', $serviceSummary));
            }

            $alertRow = [
                'bookingId' => (int) ($row['booking_id'] ?? 0),
                'bookingReference' => $currentRowBookingReference,
                'branchName' => trim((string) ($row['branch_name'] ?? '')),
                'branchCity' => '',
                'customerName' => $customerName !== '' ? $customerName : 'Customer pending',
                'contactMobile' => $contactMobile,
                'currency' => (string) ($row['currency'] ?? ''),
                'dueAmount' => round((float) ($row['due_amount'] ?? 0), 2),
                'allocatedAmount' => round((float) ($row['allocated_amount'] ?? 0), 2),
                'outstandingAmount' => round((float) ($row['outstanding_amount'] ?? 0), 2),
                'dueDate' => trim((string) ($row['due_date'] ?? '')),
                'serviceCount' => 1,
                'serviceSummary' => $serviceSummary,
                'classification' => 'missing_due_date',
                'daysDelta' => null,
                'daysLabel' => 'Due Date Missing',
                'isCurrentBooking' => $bookingReference !== '' && $currentRowBookingReference === $bookingReference,
            ];

            $customerRows[] = $alertRow;
            if ($alertRow['isCurrentBooking']) {
                $bookingRows[] = $alertRow;
            }
        }

        $customerScope = $this->categorizeReceivableAlertRows($customerRows);
        $bookingScope = $this->categorizeReceivableAlertRows($bookingRows);

        return [
            'defaultScope' => 'customer',
            'scopes' => [
                'customer' => $customerScope,
                'booking' => $bookingScope,
            ],
            'overdue' => $customerScope['overdue'],
            'dueToday' => $customerScope['dueToday'],
            'pending' => $customerScope['pending'],
            'missingDueDate' => $customerScope['missingDueDate'],
            'counts' => $customerScope['counts'],
        ];
    }

    private function categorizeReceivableAlertRows(array $rows): array
    {
        $today = new \DateTimeImmutable('today');
        $categories = [
            'overdue' => [],
            'dueToday' => [],
            'pending' => [],
            'missingDueDate' => [],
        ];

        foreach ($rows as $row) {
            $outstandingAmount = round((float) ($row['outstandingAmount'] ?? $row['total_outstanding_amount'] ?? 0), 2);
            if ($outstandingAmount <= 0) {
                continue;
            }

            $serviceCount = (int) ($row['serviceCount'] ?? $row['service_count'] ?? 0);
            $serviceSummary = trim((string) ($row['serviceSummary'] ?? $row['service_summary'] ?? ''));
            if ($serviceSummary === '') {
                $serviceSummary = $serviceCount > 0 ? $serviceCount . ' service(s)' : 'Service summary pending';
            }

            $alert = [
                'bookingId' => (int) ($row['bookingId'] ?? $row['booking_id'] ?? 0),
                'bookingReference' => (string) ($row['bookingReference'] ?? $row['booking_reference'] ?? ''),
                'branchName' => trim((string) ($row['branchName'] ?? $row['branch_name'] ?? '')),
                'branchCity' => trim((string) ($row['branchCity'] ?? $row['branch_city'] ?? '')),
                'customerName' => trim((string) ($row['customerName'] ?? $row['customer_name'] ?? 'Customer pending')),
                'contactMobile' => trim((string) ($row['contactMobile'] ?? $row['contact_mobile'] ?? '')),
                'currency' => (string) ($row['currency'] ?? ''),
                'dueAmount' => round((float) ($row['dueAmount'] ?? $row['total_due_amount'] ?? 0), 2),
                'allocatedAmount' => round((float) ($row['allocatedAmount'] ?? $row['total_allocated_amount'] ?? 0), 2),
                'outstandingAmount' => $outstandingAmount,
                'dueDate' => trim((string) ($row['dueDate'] ?? $row['due_date'] ?? '')),
                'serviceCount' => $serviceCount,
                'serviceSummary' => $serviceSummary,
                'classification' => 'missing_due_date',
                'daysDelta' => null,
                'daysLabel' => 'Due Date Missing',
                'isCurrentBooking' => (bool) ($row['isCurrentBooking'] ?? false),
            ];

            $dueDateValue = $alert['dueDate'];
            if ($dueDateValue === '') {
                $categories['missingDueDate'][] = $alert;
                continue;
            }

            $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dueDateValue);
            if (! $dueDate instanceof \DateTimeImmutable) {
                $categories['missingDueDate'][] = $alert;
                continue;
            }

            if ($dueDate < $today) {
                $daysOverdue = (int) $dueDate->diff($today)->format('%a');
                $alert['classification'] = 'overdue';
                $alert['daysDelta'] = $daysOverdue;
                $alert['daysLabel'] = $daysOverdue . ' day' . ($daysOverdue === 1 ? '' : 's') . ' overdue';
                $categories['overdue'][] = $alert;
                continue;
            }

            if ($dueDate == $today) {
                $alert['classification'] = 'due_today';
                $alert['daysDelta'] = 0;
                $alert['daysLabel'] = 'Due Today';
                $categories['dueToday'][] = $alert;
                continue;
            }

            $daysUntilDue = (int) $today->diff($dueDate)->format('%a');
            $alert['classification'] = 'pending';
            $alert['daysDelta'] = $daysUntilDue;
            $alert['daysLabel'] = 'Due in ' . $daysUntilDue . ' day' . ($daysUntilDue === 1 ? '' : 's');
            $categories['pending'][] = $alert;
        }

        return [
            'overdue' => $categories['overdue'],
            'dueToday' => $categories['dueToday'],
            'pending' => $categories['pending'],
            'missingDueDate' => $categories['missingDueDate'],
            'counts' => [
                'overdue' => count($categories['overdue']),
                'dueToday' => count($categories['dueToday']),
                'pending' => count($categories['pending']),
                'missingDueDate' => count($categories['missingDueDate']),
                'total' => count($categories['overdue']) + count($categories['dueToday']) + count($categories['pending']) + count($categories['missingDueDate']),
            ],
        ];
    }

    private function emptyReceivableAlertState(): array
    {
        return [
            'defaultScope' => 'customer',
            'scopes' => [
                'customer' => [
                    'overdue' => [],
                    'dueToday' => [],
                    'pending' => [],
                    'missingDueDate' => [],
                    'counts' => [
                        'overdue' => 0,
                        'dueToday' => 0,
                        'pending' => 0,
                        'missingDueDate' => 0,
                        'total' => 0,
                    ],
                ],
                'booking' => [
                    'overdue' => [],
                    'dueToday' => [],
                    'pending' => [],
                    'missingDueDate' => [],
                    'counts' => [
                        'overdue' => 0,
                        'dueToday' => 0,
                        'pending' => 0,
                        'missingDueDate' => 0,
                        'total' => 0,
                    ],
                ],
            ],
            'overdue' => [],
            'dueToday' => [],
            'pending' => [],
            'missingDueDate' => [],
            'counts' => [
                'overdue' => 0,
                'dueToday' => 0,
                'pending' => 0,
                'missingDueDate' => 0,
                'total' => 0,
            ],
        ];
    }
}
