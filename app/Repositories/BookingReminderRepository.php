<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingReminderRepository extends BaseRepository
{
    public function remindersForBooking(int $bookingId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                br.*,
                t.full_name AS traveler_name,
                bs.line_reference AS service_line_reference,
                cr.receipt_no AS customer_receipt_no,
                sp.payment_no AS supplier_payment_no,
                so.service_line_reference AS supplier_obligation_service_line,
                s.name AS supplier_name
             FROM booking_reminders br
             LEFT JOIN travelers t ON t.id = br.traveler_id
             LEFT JOIN booking_services bs ON bs.id = br.booking_service_id
             LEFT JOIN customer_receipts cr ON cr.id = br.customer_receipt_id
             LEFT JOIN supplier_payments sp ON sp.id = br.supplier_payment_id
             LEFT JOIN supplier_obligations so ON so.id = br.supplier_obligation_id
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             WHERE br.booking_id = :booking_id
             ORDER BY
                CASE br.status
                    WHEN "due" THEN 0
                    WHEN "open" THEN 1
                    WHEN "completed" THEN 2
                    ELSE 3
                END ASC,
                br.due_at ASC,
                br.id DESC'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return $statement->fetchAll() ?: [];
    }

    public function findReminderById(int $reminderId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM booking_reminders
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $reminderId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createReminder(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $statement = $this->db->prepare(
                'INSERT INTO booking_reminders (
                    branch_id, booking_id, traveler_id, booking_service_id, customer_receipt_id, supplier_payment_id, supplier_obligation_id,
                    reminder_type, title, reminder_note, due_at, channel, owner_label, status, priority, system_generated, reminder_key,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :branch_id, :booking_id, :traveler_id, :booking_service_id, :customer_receipt_id, :supplier_payment_id, :supplier_obligation_id,
                    :reminder_type, :title, :reminder_note, :due_at, :channel, :owner_label, :status, :priority, :system_generated, :reminder_key,
                    :created_by_user_id, :updated_by_user_id
                 )'
            );
            $statement->execute([
                'branch_id' => $data['branch_id'],
                'booking_id' => $data['booking_id'],
                'traveler_id' => $data['traveler_id'] ?? null,
                'booking_service_id' => $data['booking_service_id'] ?? null,
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'reminder_type' => $data['reminder_type'],
                'title' => $data['title'],
                'reminder_note' => $data['reminder_note'] ?? null,
                'due_at' => $data['due_at'],
                'channel' => $data['channel'] ?? null,
                'owner_label' => $data['owner_label'] ?? null,
                'status' => $data['status'],
                'priority' => $data['priority'] ?? 'normal',
                'system_generated' => $data['system_generated'] ?? 0,
                'reminder_key' => $data['reminder_key'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
                'updated_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            return (int) $this->db->lastInsertId();
        });
    }

    public function updateReminder(int $reminderId, array $data): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_reminders
             SET traveler_id = :traveler_id,
                 booking_service_id = :booking_service_id,
                 customer_receipt_id = :customer_receipt_id,
                 supplier_payment_id = :supplier_payment_id,
                 supplier_obligation_id = :supplier_obligation_id,
                 reminder_type = :reminder_type,
                 title = :title,
                 reminder_note = :reminder_note,
                 due_at = :due_at,
                 channel = :channel,
                 owner_label = :owner_label,
                 status = :status,
                 priority = :priority,
                 updated_by_user_id = :updated_by_user_id,
                 completed_at = CASE WHEN :completed_status = "completed" THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                 dismissed_at = CASE WHEN :dismissed_status = "dismissed" THEN COALESCE(dismissed_at, NOW()) ELSE NULL END
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $reminderId,
            'traveler_id' => $data['traveler_id'] ?? null,
            'booking_service_id' => $data['booking_service_id'] ?? null,
            'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
            'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
            'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
            'reminder_type' => $data['reminder_type'],
            'title' => $data['title'],
            'reminder_note' => $data['reminder_note'] ?? null,
            'due_at' => $data['due_at'],
            'channel' => $data['channel'] ?? null,
            'owner_label' => $data['owner_label'] ?? null,
            'status' => $data['status'],
            'completed_status' => $data['status'],
            'dismissed_status' => $data['status'],
            'priority' => $data['priority'] ?? 'normal',
            'updated_by_user_id' => $data['actor_user_id'] ?? null,
        ]);
    }

    public function markReminderStatus(int $reminderId, string $status, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_reminders
             SET status = :status,
                 completed_at = CASE WHEN :completed_status = "completed" THEN NOW() ELSE NULL END,
                 dismissed_at = CASE WHEN :dismissed_status = "dismissed" THEN NOW() ELSE NULL END,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $reminderId,
            'status' => $status,
            'completed_status' => $status,
            'dismissed_status' => $status,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function upsertSystemReminder(array $data): void
    {
        $existing = $this->findReminderByKey((string) $data['reminder_key']);
        if ($existing === null) {
            $this->createReminder($data);
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE booking_reminders
             SET traveler_id = :traveler_id,
                 booking_service_id = :booking_service_id,
                 customer_receipt_id = :customer_receipt_id,
                 supplier_payment_id = :supplier_payment_id,
                 supplier_obligation_id = :supplier_obligation_id,
                 reminder_type = :reminder_type,
                 title = :title,
                 reminder_note = :reminder_note,
                 due_at = :due_at,
                 channel = :channel,
                 owner_label = :owner_label,
                 status = :status,
                 priority = :priority,
                 updated_by_user_id = :updated_by_user_id,
                 completed_at = CASE WHEN :completed_status = "completed" THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                 dismissed_at = CASE WHEN :dismissed_status = "dismissed" THEN COALESCE(dismissed_at, NOW()) ELSE NULL END
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $existing['id'],
            'traveler_id' => $data['traveler_id'] ?? null,
            'booking_service_id' => $data['booking_service_id'] ?? null,
            'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
            'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
            'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
            'reminder_type' => $data['reminder_type'],
            'title' => $data['title'],
            'reminder_note' => $data['reminder_note'] ?? null,
            'due_at' => $data['due_at'],
            'channel' => $data['channel'] ?? null,
            'owner_label' => $data['owner_label'] ?? null,
            'status' => $data['status'],
            'completed_status' => $data['status'],
            'dismissed_status' => $data['status'],
            'priority' => $data['priority'] ?? 'normal',
            'updated_by_user_id' => $data['actor_user_id'] ?? null,
        ]);
    }

    public function completeInactiveSystemReminders(int $bookingId, array $activeKeys, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'SELECT id, reminder_key
             FROM booking_reminders
             WHERE booking_id = :booking_id
               AND system_generated = 1
               AND status IN ("open", "due")'
        );
        $statement->execute(['booking_id' => $bookingId]);
        $rows = $statement->fetchAll() ?: [];

        foreach ($rows as $row) {
            $key = (string) ($row['reminder_key'] ?? '');
            if ($key === '' || in_array($key, $activeKeys, true)) {
                continue;
            }

            $this->markReminderStatus((int) $row['id'], 'completed', $actorUserId);
        }
    }

    public function findReminderByKey(string $reminderKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM booking_reminders
             WHERE reminder_key = :reminder_key
             LIMIT 1'
        );
        $statement->execute(['reminder_key' => $reminderKey]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function bookingTravelerBelongsToBooking(int $bookingId, int $travelerId): bool
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

    public function serviceBelongsToBooking(int $bookingId, int $serviceId): bool
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM booking_services
             WHERE booking_id = :booking_id
               AND id = :id
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'id' => $serviceId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function receiptBelongsToBooking(int $bookingId, int $receiptId): bool
    {
        $statement = $this->db->prepare(
            'SELECT cr.id
             FROM customer_receipts cr
             INNER JOIN bookings b ON b.booking_reference = cr.booking_reference
             WHERE b.id = :booking_id
               AND cr.id = :receipt_id
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'receipt_id' => $receiptId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function supplierPaymentBelongsToBooking(int $bookingId, int $paymentId): bool
    {
        $statement = $this->db->prepare(
            'SELECT sp.id
             FROM supplier_payments sp
             INNER JOIN bookings b ON b.booking_reference = sp.booking_reference
             WHERE b.id = :booking_id
               AND sp.id = :payment_id
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'payment_id' => $paymentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function supplierObligationBelongsToBooking(int $bookingId, int $obligationId): bool
    {
        $statement = $this->db->prepare(
            'SELECT so.id
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             WHERE b.id = :booking_id
               AND so.id = :obligation_id
             LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'obligation_id' => $obligationId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
