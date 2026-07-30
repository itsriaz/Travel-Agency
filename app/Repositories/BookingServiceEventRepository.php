<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingServiceEventRepository extends BaseRepository
{
    private function reversalMetadataSelect(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return implode(",\n                    ", [
            $this->columnExists('booking_service_events', 'void_reason') ? $prefix . 'void_reason' : 'NULL AS void_reason',
            $this->columnExists('booking_service_events', 'reversal_reference') ? $prefix . 'reversal_reference' : 'NULL AS reversal_reference',
            $this->columnExists('booking_service_events', 'reversal_journal_entry_id') ? $prefix . 'reversal_journal_entry_id' : 'NULL AS reversal_journal_entry_id',
        ]);
    }

    public function createEvent(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $payloadJson = isset($data['payload_json'])
                ? json_encode($data['payload_json'], JSON_UNESCAPED_SLASHES)
                : null;

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
                'payload_json' => $payloadJson,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            $eventId = (int) $this->db->lastInsertId();
            if ($eventId > 0) {
                return $eventId;
            }

            $lookup = $this->db->prepare(
                'SELECT id
                 FROM booking_service_events
                 WHERE branch_id = :branch_id
                   AND booking_id = :booking_id
                   AND booking_service_id = :booking_service_id
                   AND booking_reference = :booking_reference
                   AND service_line_reference = :service_line_reference
                   AND event_type = :event_type
                   AND event_status = :event_status
                   AND event_date = :event_date
                   AND currency = :currency
                   AND (original_ticket_number <=> :original_ticket_number)
                   AND (new_ticket_number <=> :new_ticket_number)
                   AND (original_pnr <=> :original_pnr)
                   AND (new_pnr <=> :new_pnr)
                   AND fare_difference_amount = :fare_difference_amount
                   AND penalty_amount = :penalty_amount
                   AND service_fee_amount = :service_fee_amount
                   AND customer_refund_amount = :customer_refund_amount
                   AND customer_credit_amount = :customer_credit_amount
                   AND supplier_refund_amount = :supplier_refund_amount
                   AND supplier_credit_amount = :supplier_credit_amount
                   AND (journal_entry_id <=> :journal_entry_id)
                   AND (reason <=> :reason)
                   AND (notes <=> :notes)
                   AND (created_by_user_id <=> :created_by_user_id)
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $lookup->execute([
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
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            return (int) ($lookup->fetchColumn() ?: 0);
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

    public function mergePayload(int $eventId, array $values): void
    {
        $statement = $this->db->prepare('SELECT payload_json FROM booking_service_events WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $eventId]);
        $payload = json_decode((string) ($statement->fetchColumn() ?: ''), true);
        if (! is_array($payload)) {
            $payload = [];
        }

        $update = $this->db->prepare(
            'UPDATE booking_service_events SET payload_json = :payload_json WHERE id = :id'
        );
        $update->execute([
            'id' => $eventId,
            'payload_json' => json_encode(array_replace_recursive($payload, $values), JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function latestPostedEvent(int $bookingServiceId, string $eventType): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *,
                    ' . $this->reversalMetadataSelect() . '
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
            'SELECT *,
                    ' . $this->reversalMetadataSelect() . '
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

    public function updateRefundFinancials(int $eventId, array $data): void
    {
        $existing = $this->findPostedEventById($eventId, 'refund');
        if ($existing === null) {
            return;
        }

        $payload = json_decode((string) ($existing['payload_json'] ?? ''), true);
        if (! is_array($payload)) {
            $payload = [];
        }
        if (isset($data['payload_patch']) && is_array($data['payload_patch'])) {
            $payload = array_replace_recursive($payload, $data['payload_patch']);
        }

        $statement = $this->db->prepare(
            'UPDATE booking_service_events
             SET customer_refund_amount = :customer_refund_amount,
                 supplier_refund_amount = :supplier_refund_amount,
                 reason = COALESCE(:reason, reason),
                 notes = COALESCE(:notes, notes),
                 payload_json = :payload_json
             WHERE id = :id
               AND event_type = "refund"
               AND event_status = "posted"'
        );
        $statement->execute([
            'id' => $eventId,
            'customer_refund_amount' => $data['customer_refund_amount'] ?? $existing['customer_refund_amount'] ?? 0,
            'supplier_refund_amount' => $data['supplier_refund_amount'] ?? $existing['supplier_refund_amount'] ?? 0,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function postedEventsForService(int $bookingServiceId): array
    {
        $statement = $this->db->prepare(
            'SELECT *,
                    ' . $this->reversalMetadataSelect() . '
             FROM booking_service_events
             WHERE booking_service_id = :booking_service_id
               AND event_status = "posted"
             ORDER BY event_date ASC, id ASC'
        );
        $statement->execute(['booking_service_id' => $bookingServiceId]);

        return $statement->fetchAll() ?: [];
    }

    public function separateReissueMirrorAdjustments(int $bookingServiceId): array
    {
        $statement = $this->db->prepare(
            'SELECT payload_json
             FROM booking_service_events
             WHERE booking_service_id = :booking_service_id
               AND event_type = "reissue"
               AND event_status = "posted"'
        );
        $statement->execute(['booking_service_id' => $bookingServiceId]);

        $customerMirror = 0.0;
        $supplierMirror = 0.0;
        foreach (($statement->fetchAll() ?: []) as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (! is_array($payload)) {
                continue;
            }
            if (($payload['customer_uses_separate_receivable'] ?? false) === true) {
                $customerMirror += (float) ($payload['customer_mirror_increment'] ?? 0);
            }
            if (($payload['supplier_uses_separate_obligation'] ?? false) === true) {
                $supplierMirror += (float) ($payload['supplier_mirror_increment'] ?? 0);
            }
        }

        return [
            'customer_mirror_amount' => round($customerMirror, 2),
            'supplier_mirror_amount' => round($supplierMirror, 2),
        ];
    }

    public function voidEvent(
        int $eventId,
        string $voidReason,
        int $actorUserId,
        string $reversalReference,
        ?int $reversalJournalEntryId = null,
        array $payloadPatch = []
    ): array {
        return $this->transaction(function () use (
            $eventId,
            $voidReason,
            $actorUserId,
            $reversalReference,
            $reversalJournalEntryId,
            $payloadPatch
        ): array {
            $selectSql = 'SELECT *,
                    ' . $this->reversalMetadataSelect() . '
                 FROM booking_service_events
                 WHERE id = :event_id
                 FOR UPDATE';
            $statement = $this->db->prepare($selectSql);
            $statement->execute(['event_id' => $eventId]);
            $event = $statement->fetch();

            if ($event === false) {
                throw new \RuntimeException('The selected service event could not be found.');
            }

            $status = str_replace(' ', '_', mb_strtolower(trim((string) ($event['event_status'] ?? ''))));
            if ($status === 'voided') {
                throw new \RuntimeException('This service event is already voided.');
            }

            if ((int) ($event['reversal_journal_entry_id'] ?? 0) > 0 && $reversalJournalEntryId === null) {
                throw new \RuntimeException('This service event already has a reversal journal recorded.');
            }

            $payload = json_decode((string) ($event['payload_json'] ?? ''), true);
            if (! is_array($payload)) {
                $payload = [];
            }

            if ($payloadPatch !== []) {
                $payload = array_merge($payload, $payloadPatch);
            }

            $assignments = [
                'event_status = "voided"',
                'voided_by_user_id = :voided_by_user_id',
                'voided_at = NOW()',
                'payload_json = :payload_json',
            ];
            $params = [
                'event_id' => $eventId,
                'voided_by_user_id' => $actorUserId,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ];

            if ($this->columnExists('booking_service_events', 'void_reason')) {
                $assignments[] = 'void_reason = :void_reason';
                $params['void_reason'] = $voidReason;
            }

            if ($this->columnExists('booking_service_events', 'reversal_reference')) {
                $assignments[] = 'reversal_reference = :reversal_reference';
                $params['reversal_reference'] = $reversalReference;
            }

            if ($this->columnExists('booking_service_events', 'reversal_journal_entry_id')) {
                $assignments[] = 'reversal_journal_entry_id = :reversal_journal_entry_id';
                $params['reversal_journal_entry_id'] = $reversalJournalEntryId;
            }

            $update = $this->db->prepare(
                'UPDATE booking_service_events
                 SET ' . implode(",\n                     ", $assignments) . '
                 WHERE id = :event_id'
            );
            $update->execute($params);

            return [
                'id' => (int) ($event['id'] ?? 0),
                'booking_id' => (int) ($event['booking_id'] ?? 0),
                'booking_service_id' => (int) ($event['booking_service_id'] ?? 0),
                'booking_reference' => (string) ($event['booking_reference'] ?? ''),
                'service_line_reference' => (string) ($event['service_line_reference'] ?? ''),
                'event_type' => (string) ($event['event_type'] ?? ''),
                'event_date' => (string) ($event['event_date'] ?? ''),
                'currency' => (string) ($event['currency'] ?? 'PKR'),
                'customer_refund_amount' => round((float) ($event['customer_refund_amount'] ?? 0), 2),
                'supplier_refund_amount' => round((float) ($event['supplier_refund_amount'] ?? 0), 2),
                'journal_entry_id' => (int) ($event['journal_entry_id'] ?? 0),
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => (int) ($reversalJournalEntryId ?? 0),
            ];
        });
    }

    public function attachReversalJournalEntry(int $eventId, int $journalEntryId): void
    {
        if (! $this->columnExists('booking_service_events', 'reversal_journal_entry_id')) {
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE booking_service_events
             SET reversal_journal_entry_id = :journal_entry_id
             WHERE id = :event_id'
        );
        $statement->execute([
            'journal_entry_id' => $journalEntryId,
            'event_id' => $eventId,
        ]);
    }
}
