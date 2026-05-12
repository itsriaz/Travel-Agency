<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingDocumentRepository extends BaseRepository
{
    public function documentsTableExists(): bool
    {
        return $this->tableExists('booking_documents');
    }

    public function documentsForBooking(int $bookingId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                bd.id,
                bd.branch_id,
                bd.booking_id,
                bd.traveler_id,
                bd.booking_service_id,
                bd.customer_receipt_id,
                bd.supplier_payment_id,
                bd.supplier_obligation_id,
                bd.document_type,
                bd.title,
                bd.notes,
                bd.original_file_name,
                bd.storage_path,
                bd.mime_type,
                bd.file_size_bytes,
                bd.status,
                bd.created_at,
                bd.updated_at,
                t.full_name AS traveler_name,
                bs.line_reference AS service_line_reference,
                cr.receipt_no AS customer_receipt_no,
                sp.payment_no AS supplier_payment_no,
                so.service_line_reference AS supplier_obligation_service_line,
                s.name AS supplier_name
             FROM booking_documents bd
             LEFT JOIN travelers t ON t.id = bd.traveler_id
             LEFT JOIN booking_services bs ON bs.id = bd.booking_service_id
             LEFT JOIN customer_receipts cr ON cr.id = bd.customer_receipt_id
             LEFT JOIN supplier_payments sp ON sp.id = bd.supplier_payment_id
             LEFT JOIN supplier_obligations so ON so.id = bd.supplier_obligation_id
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             WHERE bd.booking_id = :booking_id
             ORDER BY
                CASE bd.status
                    WHEN "active" THEN 0
                    WHEN "superseded" THEN 1
                    ELSE 2
                END ASC,
                bd.updated_at DESC,
                bd.id DESC'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return $statement->fetchAll() ?: [];
    }

    public function findDocumentById(int $documentId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                bd.*,
                b.booking_reference,
                t.full_name AS traveler_name,
                bs.line_reference AS service_line_reference,
                cr.receipt_no AS customer_receipt_no,
                sp.payment_no AS supplier_payment_no,
                so.service_line_reference AS supplier_obligation_service_line,
                s.name AS supplier_name
             FROM booking_documents bd
             INNER JOIN bookings b ON b.id = bd.booking_id
             LEFT JOIN travelers t ON t.id = bd.traveler_id
             LEFT JOIN booking_services bs ON bs.id = bd.booking_service_id
             LEFT JOIN customer_receipts cr ON cr.id = bd.customer_receipt_id
             LEFT JOIN supplier_payments sp ON sp.id = bd.supplier_payment_id
             LEFT JOIN supplier_obligations so ON so.id = bd.supplier_obligation_id
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             WHERE bd.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $documentId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createDocument(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $statement = $this->db->prepare(
                'INSERT INTO booking_documents (
                    branch_id, booking_id, traveler_id, booking_service_id, customer_receipt_id, supplier_payment_id, supplier_obligation_id,
                    document_type, title, notes, original_file_name, stored_file_name, storage_disk, storage_path, mime_type,
                    file_extension, file_size_bytes, sha256_hash, visibility, status, replaced_document_id,
                    uploaded_by_user_id, updated_by_user_id
                 ) VALUES (
                    :branch_id, :booking_id, :traveler_id, :booking_service_id, :customer_receipt_id, :supplier_payment_id, :supplier_obligation_id,
                    :document_type, :title, :notes, :original_file_name, :stored_file_name, :storage_disk, :storage_path, :mime_type,
                    :file_extension, :file_size_bytes, :sha256_hash, "private", :status, :replaced_document_id,
                    :uploaded_by_user_id, :updated_by_user_id
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
                'document_type' => $data['document_type'],
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'original_file_name' => $data['original_file_name'],
                'stored_file_name' => $data['stored_file_name'],
                'storage_disk' => $data['storage_disk'] ?? 'local',
                'storage_path' => $data['storage_path'],
                'mime_type' => $data['mime_type'],
                'file_extension' => $data['file_extension'],
                'file_size_bytes' => $data['file_size_bytes'],
                'sha256_hash' => $data['sha256_hash'],
                'status' => $data['status'] ?? 'active',
                'replaced_document_id' => $data['replaced_document_id'] ?? null,
                'uploaded_by_user_id' => $data['actor_user_id'] ?? null,
                'updated_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            return (int) $this->db->lastInsertId();
        });
    }

    public function supersedeDocument(int $documentId, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_documents
             SET status = "superseded",
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $documentId,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function revokeDocument(int $documentId, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE booking_documents
             SET status = "revoked",
                 revoked_by_user_id = :revoked_by_user_id,
                 revoked_at = NOW(),
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $documentId,
            'revoked_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
        ]);
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
