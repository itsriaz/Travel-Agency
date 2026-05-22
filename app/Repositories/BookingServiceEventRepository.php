<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingServiceEventRepository extends BaseRepository
{
    public function createEvent(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $statement = $this->db->prepare(
                'INSERT INTO booking_service_events (
                    branch_id, booking_id, booking_service_id, booking_reference, service_line_reference,
                    event_type, event_status, event_date, currency, original_ticket_number, new_ticket_number,
                    original_pnr, new_pnr, fare_difference_amount, penalty_amount, service_fee_amount,
                    customer_refund_amount, customer_credit_amount, supplier_refund_amount, supplier_credit_amount,
                    journal_entry_id, reason, notes, payload_json, created_by_user_id
                 ) VALUES (
                    :branch_id, :booking_id, :booking_service_id, :booking_reference, :service_line_reference,
                    :event_type, :event_status, :event_date, :currency, :original_ticket_number, :new_ticket_number,
                    :original_pnr, :new_pnr, :fare_difference_amount, :penalty_amount, :service_fee_amount,
                    :customer_refund_amount, :customer_credit_amount, :supplier_refund_amount, :supplier_credit_amount,
                    :journal_entry_id, :reason, :notes, :payload_json, :created_by_user_id
                 )'
            );
            $statement->execute([
                'branch_id' => $data['branch_id'],
                'booking_id' => $data['booking_id'],
                'booking_service_id' => $data['booking_service_id'],
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'],
                'event_type' => $data['event_type'],
                'event_status' => $data['event_status'] ?? 'draft',
                'event_date' => $data['event_date'],
                'currency' => $data['currency'],
                'original_ticket_number' => $data['original_ticket_number'] ?? null,
                'new_ticket_number' => $data['new_ticket_number'] ?? null,
                'original_pnr' => $data['original_pnr'] ?? null,
                'new_pnr' => $data['new_pnr'] ?? null,
                'fare_difference_amount' => $data['fare_difference_amount'] ?? 0,
                'penalty_amount' => $data['penalty_amount'] ?? 0,
                'service_fee_amount' => $data['service_fee_amount'] ?? 0,
                'customer_refund_amount' => $data['customer_refund_amount'] ?? 0,
                'customer_credit_amount' => $data['customer_credit_amount'] ?? 0,
                'supplier_refund_amount' => $data['supplier_refund_amount'] ?? 0,
                'supplier_credit_amount' => $data['supplier_credit_amount'] ?? 0,
                'journal_entry_id' => $data['journal_entry_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payload_json' => isset($data['payload_json']) ? json_encode($data['payload_json'], JSON_UNESCAPED_SLASHES) : null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            return (int) $this->db->lastInsertId();
        });
    }

    public function postedEventExists(int $bookingServiceId, string $eventType): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM booking_service_events
             WHERE booking_service_id = :booking_service_id
               AND event_type = :event_type
               AND event_status = "posted"
             LIMIT 1'
        );
        $statement->execute([
            'booking_service_id' => $bookingServiceId,
            'event_type' => $eventType,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function attachJournalEntry(int $eventId, int $journalEntryId): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_service_events
             SET journal_entry_id = :journal_entry_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $eventId,
            'journal_entry_id' => $journalEntryId,
        ]);
    }

    public function latestPostedEvent(int $bookingServiceId, string $eventType): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM booking_service_events
             WHERE booking_service_id = :booking_service_id
               AND event_type = :event_type
               AND event_status = "posted"
             ORDER BY event_date DESC, id DESC
             LIMIT 1'
        );
        $statement->execute([
            'booking_service_id' => $bookingServiceId,
            'event_type' => $eventType,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findPostedEventById(int $eventId, string $eventType): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM booking_service_events
             WHERE id = :id
               AND event_type = :event_type
               AND event_status = "posted"
             LIMIT 1'
        );
        $statement->execute([
            'id' => $eventId,
            'event_type' => $eventType,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function updateCancellationFinancials(int $eventId, array $data): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_service_events
             SET penalty_amount = :penalty_amount,
                 customer_credit_amount = :customer_credit_amount,
                 supplier_credit_amount = :supplier_credit_amount,
                 journal_entry_id = :journal_entry_id,
                 reason = COALESCE(:reason, reason),
                 notes = COALESCE(:notes, notes),
                 payload_json = :payload_json
             WHERE id = :id
               AND event_type = "cancel"
               AND event_status = "posted"'
        );
        $statement->execute([
            'id' => $eventId,
            'penalty_amount' => $data['penalty_amount'] ?? 0,
            'customer_credit_amount' => $data['customer_credit_amount'] ?? 0,
            'supplier_credit_amount' => $data['supplier_credit_amount'] ?? 0,
            'journal_entry_id' => $data['journal_entry_id'] ?? null,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'payload_json' => isset($data['payload_json']) ? json_encode($data['payload_json'], JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    public function postedEventsForService(int $bookingServiceId): array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM booking_service_events
             WHERE booking_service_id = :booking_service_id
               AND event_status = "posted"
             ORDER BY event_date ASC, id ASC'
        );
        $statement->execute(['booking_service_id' => $bookingServiceId]);

        return $statement->fetchAll() ?: [];
    }
}
