<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends BaseRepository
{
    public function recentWorkspaceActivityForUser(int $userId, int $limit = 12): array
    {
        $limit = max(1, min($limit, 50));
        $eventNames = [
            'booking.created',
            'booking.updated',
            'booking.opened',
            'traveler.created',
            'traveler.updated',
            'traveler.attached',
            'service.created',
            'service.updated',
            'service.financial_corrected',
            'service.cancelled',
            'service.refund.posted',
            'service.reissued',
            'customer.receipt.recorded',
            'customer.receipt.allocated',
            'customer.receipt.voided',
            'supplier.payment.created',
            'supplier.payment.allocated',
            'supplier.payment.voided',
            'reminder.created',
            'reminder.updated',
            'reminder.open',
            'reminder.closed',
            'document.uploaded',
            'document.replaced',
        ];

        $placeholders = implode(', ', array_fill(0, count($eventNames), '?'));
        $sql = sprintf(
            'SELECT event_name, created_at, payload_json
             FROM audit_logs
             WHERE actor_user_id = ?
               AND event_name IN (%s)
             ORDER BY created_at DESC
             LIMIT %d',
            $placeholders,
            $limit
        );

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([$userId], $eventNames));
        $rows = $statement->fetchAll() ?: [];

        return array_values(array_filter(array_map(
            fn (array $row): ?array => $this->normalizeWorkspaceActivityRow($row),
            $rows
        )));
    }

    public function recentFailureSummaryForUser(int $userId, int $days = 7): array
    {
        $cutoffAt = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'SELECT event_name, COUNT(*) AS total
             FROM audit_logs
             WHERE actor_user_id = :user_id
               AND event_name IN (
                   "auth.login.failed",
                   "auth.2fa.otp.failure",
                   "auth.reset.invalid_or_expired"
               )
               AND created_at >= :cutoff_at
             GROUP BY event_name
             ORDER BY total DESC'
        );
        $statement->execute([
            'user_id' => $userId,
            'cutoff_at' => $cutoffAt,
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function relevantEventsForUser(int $userId, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT event_name, ip_address, user_agent, created_at, payload_json
             FROM audit_logs
             WHERE actor_user_id = :user_id
                OR payload_json LIKE :target_pattern
             ORDER BY created_at DESC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $statement->bindValue(':target_pattern', '%"target_user_id":' . $userId . '%');
        $statement->bindValue(':limit_rows', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    private function normalizeWorkspaceActivityRow(array $row): ?array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (! is_array($payload)) {
            $payload = [];
        }

        $eventName = (string) ($row['event_name'] ?? '');
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        $bookingReference = trim((string) ($payload['booking_reference'] ?? ''));
        $serviceReference = trim((string) ($payload['service_line_reference'] ?? $payload['service_line'] ?? ''));
        $receiptNumber = trim((string) ($payload['receipt_no'] ?? ''));
        $supplierCode = trim((string) ($payload['supplier_code'] ?? ''));
        $supplierName = trim((string) ($payload['supplier_name'] ?? ''));
        $travelerRole = trim((string) ($payload['traveler_role'] ?? ''));
        $passengerName = trim((string) ($payload['service_passenger_name'] ?? $payload['passenger_name'] ?? $payload['traveler_name'] ?? ''));
        $customerName = trim((string) ($payload['customer_name'] ?? $payload['lead_traveler_name'] ?? ''));
        $amount = $payload['amount'] ?? $payload['received_amount'] ?? $payload['allocated_amount'] ?? $payload['paid_amount'] ?? null;
        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));

        $titleMap = [
            'booking.created' => 'Invoice created',
            'booking.updated' => 'Invoice updated',
            'booking.opened' => 'Invoice opened',
            'traveler.created' => 'Passenger created',
            'traveler.updated' => 'Passenger updated',
            'traveler.attached' => 'Passenger attached',
            'service.created' => 'Service added',
            'service.updated' => 'Service updated',
            'service.financial_corrected' => 'Service price edited',
            'service.cancelled' => 'Service cancelled',
            'service.refund.posted' => 'Refund posted',
            'service.reissued' => 'Service reissued',
            'customer.receipt.recorded' => 'Customer payment saved',
            'customer.receipt.allocated' => 'Customer payment allocated',
            'customer.receipt.voided' => 'Customer payment voided',
            'supplier.payment.created' => 'Supplier payment saved',
            'supplier.payment.allocated' => 'Supplier payment allocated',
            'supplier.payment.voided' => 'Supplier payment voided',
            'reminder.created' => 'Reminder created',
            'reminder.updated' => 'Reminder updated',
            'reminder.open' => 'Reminder reopened',
            'reminder.closed' => 'Reminder closed',
            'document.uploaded' => 'Document uploaded',
            'document.replaced' => 'Document replaced',
        ];

        $detailParts = [];
        if ($bookingReference !== '') {
            $detailParts[] = $bookingReference;
        }
        if ($receiptNumber !== '') {
            $detailParts[] = $receiptNumber;
        }
        if ($serviceReference !== '') {
            $detailParts[] = $serviceReference;
        }
        if ($passengerName !== '') {
            $detailParts[] = $passengerName;
        } elseif ($customerName !== '') {
            $detailParts[] = $customerName;
        }
        if ($supplierName !== '') {
            $detailParts[] = $supplierName;
        } elseif ($supplierCode !== '') {
            $detailParts[] = $supplierCode;
        }
        if ($travelerRole !== '') {
            $detailParts[] = ucfirst($travelerRole);
        }
        if ($amount !== null && is_numeric((string) $amount)) {
            $formattedAmount = number_format((float) $amount, 0);
            $detailParts[] = trim(($currency !== '' ? $currency . ' ' : '') . $formattedAmount);
        }

        $openUrl = '';
        if ($bookingId > 0) {
            $openUrl = '/workspace?booking_id=' . $bookingId;
        } elseif ($bookingReference !== '') {
            $openUrl = '/workspace?booking_reference=' . urlencode($bookingReference);
        }

        return [
            'eventName' => $eventName,
            'title' => $titleMap[$eventName] ?? ucwords(str_replace(['.', '_'], ' ', $eventName)),
            'details' => implode(' | ', array_values(array_filter($detailParts, static fn (string $value): bool => $value !== ''))),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'bookingReference' => $bookingReference,
            'openUrl' => $openUrl,
        ];
    }
}
