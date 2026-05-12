<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingDocumentRepository;
use App\Repositories\BookingRepository;
use RuntimeException;

final class DocumentWorkspaceService extends Service
{
    private const DOCUMENT_TYPES = [
        'passport_copy' => 'Passport Copy',
        'visa_copy' => 'Visa Copy',
        'ticket_copy' => 'Ticket Copy',
        'payment_proof' => 'Payment Proof',
        'supplier_invoice' => 'Supplier Invoice / Supporting Doc',
        'general_attachment' => 'General Attachment',
    ];

    public function documentState(
        ?int $bookingId,
        array $accessibleBranchIds,
        array $travelers,
        array $services,
        array $customerPaymentFoundation,
        array $supplierFoundation
    ): array {
        if ($bookingId === null || $bookingId <= 0) {
            return [
                'documents' => [],
                'documentTypeOptions' => self::DOCUMENT_TYPES,
                'documentLinkTargets' => [],
                'replaceableDocuments' => [],
            ];
        }

        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot open documents for a booking outside your accessible branches.');
        }

        $repository = new BookingDocumentRepository($this->app);
        if (! $repository->documentsTableExists()) {
            return [
                'documents' => [],
                'documentTypeOptions' => self::DOCUMENT_TYPES,
                'documentLinkTargets' => $this->linkTargets($bookingId, $travelers, $services, $customerPaymentFoundation, $supplierFoundation),
                'replaceableDocuments' => [],
            ];
        }

        $documents = $repository->documentsForBooking($bookingId);

        return [
            'documents' => $documents,
            'documentTypeOptions' => self::DOCUMENT_TYPES,
            'documentLinkTargets' => $this->linkTargets($bookingId, $travelers, $services, $customerPaymentFoundation, $supplierFoundation),
            'replaceableDocuments' => array_values(array_filter($documents, static fn (array $document): bool => (string) ($document['status'] ?? '') === 'active')),
        ];
    }

    public function uploadDocument(array $input, array $files, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $booking = $this->loadBooking($bookingId, $accessibleBranchIds);

        $type = $this->normalizeDocumentType((string) ($input['document_type'] ?? ''));
        $title = $this->requiredText($input['document_title'] ?? null, 'Document title', 190);
        $notes = $this->optionalText($input['document_note'] ?? null, 4000);
        $replaceDocumentId = (int) ($input['replace_document_id'] ?? 0);
        $linkage = $this->resolveLinkage($bookingId, (string) ($input['linked_target'] ?? 'booking:' . $bookingId));
        $fileMeta = $this->validateUpload($files['document_file'] ?? null);
        $repository = new BookingDocumentRepository($this->app);
        if (! $repository->documentsTableExists()) {
            throw new RuntimeException('Documents table is missing. Run migration 20260414_000014_documents_core.php first.');
        }

        $storageDirectory = $this->buildStorageDirectory((int) $booking['branch_id'], (string) $booking['booking_reference']);
        if (! is_dir($storageDirectory) && ! mkdir($storageDirectory, 0775, true) && ! is_dir($storageDirectory)) {
            throw new RuntimeException('Unable to prepare secure document storage.');
        }

        $storedFileName = bin2hex(random_bytes(16)) . '.' . $fileMeta['extension'];
        $absolutePath = $storageDirectory . DIRECTORY_SEPARATOR . $storedFileName;

        if (! move_uploaded_file($fileMeta['tmp_name'], $absolutePath)) {
            throw new RuntimeException('The uploaded file could not be stored securely.');
        }

        $relativePath = 'documents/' . trim(str_replace('\\', '/', substr($absolutePath, strlen(base_path('/storage')))), '/');
        if ($replaceDocumentId > 0) {
            $replacedDocument = $repository->findDocumentById($replaceDocumentId);
            if ($replacedDocument === null || (int) $replacedDocument['booking_id'] !== $bookingId || (string) $replacedDocument['status'] !== 'active') {
                @unlink($absolutePath);
                throw new RuntimeException('The selected replacement target is invalid for this booking.');
            }
        }

        try {
            $documentId = $repository->createDocument(array_merge($linkage, [
                'branch_id' => (int) $booking['branch_id'],
                'booking_id' => $bookingId,
                'document_type' => $type,
                'title' => $title,
                'notes' => $notes,
                'original_file_name' => $fileMeta['original_name'],
                'stored_file_name' => $storedFileName,
                'storage_path' => $relativePath,
                'mime_type' => $fileMeta['mime_type'],
                'file_extension' => $fileMeta['extension'],
                'file_size_bytes' => $fileMeta['size'],
                'sha256_hash' => $fileMeta['sha256_hash'],
                'replaced_document_id' => $replaceDocumentId > 0 ? $replaceDocumentId : null,
                'actor_user_id' => $actorUserId,
            ]));

            if ($replaceDocumentId > 0) {
                $repository->supersedeDocument($replaceDocumentId, $actorUserId);
                AuditLog::record($this->app, 'document.replaced', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) $booking['booking_reference'],
                    'document_id' => $documentId,
                    'replaced_document_id' => $replaceDocumentId,
                    'document_type' => $type,
                ]);
            } else {
                AuditLog::record($this->app, 'document.uploaded', [
                    'user_id' => $actorUserId,
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) $booking['booking_reference'],
                    'document_id' => $documentId,
                    'document_type' => $type,
                    'file_name' => $fileMeta['original_name'],
                    'linked_scope' => $linkage['linked_scope'],
                    'linked_id' => $linkage['linked_id'],
                ]);
            }
        } catch (\Throwable $exception) {
            @unlink($absolutePath);
            throw $exception;
        }

        return [
            'booking_id' => $bookingId,
        ];
    }

    public function revokeDocument(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new BookingDocumentRepository($this->app);
        if (! $repository->documentsTableExists()) {
            throw new RuntimeException('Documents table is missing. Run migration 20260414_000014_documents_core.php first.');
        }

        $documentId = (int) ($input['document_id'] ?? 0);
        $document = $repository->findDocumentById($documentId);

        if ($document === null) {
            throw new RuntimeException('The selected document could not be found.');
        }

        $booking = $this->loadBooking((int) $document['booking_id'], $accessibleBranchIds);
        if ((string) $document['status'] !== 'active') {
            throw new RuntimeException('Only active documents can be revoked.');
        }

        $repository->revokeDocument($documentId, $actorUserId);
        AuditLog::record($this->app, 'document.revoked', [
            'user_id' => $actorUserId,
            'booking_id' => (int) $document['booking_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'document_id' => $documentId,
            'document_type' => (string) $document['document_type'],
            'file_name' => (string) $document['original_file_name'],
        ]);

        return [
            'booking_id' => (int) $document['booking_id'],
        ];
    }

    public function documentDownload(int $documentId, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new BookingDocumentRepository($this->app);
        if (! $repository->documentsTableExists()) {
            throw new RuntimeException('Documents table is missing. Run migration 20260414_000014_documents_core.php first.');
        }

        $document = $repository->findDocumentById($documentId);

        if ($document === null) {
            throw new RuntimeException('The selected document could not be found.');
        }

        $booking = $this->loadBooking((int) $document['booking_id'], $accessibleBranchIds);
        if ((string) $document['status'] !== 'active') {
            throw new RuntimeException('This document is no longer available for download.');
        }

        $absolutePath = base_path('/storage/' . ltrim((string) $document['storage_path'], '/'));
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The secure document file is missing from storage.');
        }

        AuditLog::record($this->app, 'document.downloaded', [
            'user_id' => $actorUserId,
            'booking_id' => (int) $document['booking_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'document_id' => $documentId,
            'document_type' => (string) $document['document_type'],
            'file_name' => (string) $document['original_file_name'],
        ]);

        return [
            'absolute_path' => $absolutePath,
            'download_name' => (string) $document['original_file_name'],
            'mime_type' => (string) $document['mime_type'],
            'size' => (int) $document['file_size_bytes'],
        ];
    }

    private function loadBooking(int $bookingId, array $accessibleBranchIds): array
    {
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage documents for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        return $booking;
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

        $repository = new BookingDocumentRepository($this->app);
        $payload = [
            'traveler_id' => null,
            'booking_service_id' => null,
            'customer_receipt_id' => null,
            'supplier_payment_id' => null,
            'supplier_obligation_id' => null,
            'linked_scope' => $scope !== '' ? $scope : 'booking',
            'linked_id' => $linkedId > 0 ? $linkedId : $bookingId,
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
            default => throw new RuntimeException('Please select a valid document linkage target.'),
        };
    }

    private function assertTravelerLink(BookingDocumentRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->bookingTravelerBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid traveler linked to this booking.');
        }

        $payload['traveler_id'] = $linkedId;
        return $payload;
    }

    private function assertServiceLink(BookingDocumentRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->serviceBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid service line linked to this booking.');
        }

        $payload['booking_service_id'] = $linkedId;
        return $payload;
    }

    private function assertReceiptLink(BookingDocumentRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->receiptBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid customer receipt linked to this booking.');
        }

        $payload['customer_receipt_id'] = $linkedId;
        return $payload;
    }

    private function assertSupplierPaymentLink(BookingDocumentRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->supplierPaymentBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid supplier payment linked to this booking.');
        }

        $payload['supplier_payment_id'] = $linkedId;
        return $payload;
    }

    private function assertSupplierObligationLink(BookingDocumentRepository $repository, int $bookingId, int $linkedId, array $payload): array
    {
        if ($linkedId <= 0 || ! $repository->supplierObligationBelongsToBooking($bookingId, $linkedId)) {
            throw new RuntimeException('Please select a valid supplier obligation linked to this booking.');
        }

        $payload['supplier_obligation_id'] = $linkedId;
        return $payload;
    }

    private function validateUpload(mixed $file): array
    {
        if (! is_array($file) || ! isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            throw new RuntimeException('Please choose a file to upload.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload could not be completed securely.');
        }

        $tmpName = (string) $file['tmp_name'];
        if (! is_uploaded_file($tmpName)) {
            throw new RuntimeException('The selected upload is invalid.');
        }

        $maxBytes = (int) config('security.documents.max_upload_bytes', 8 * 1024 * 1024);
        $size = (int) $file['size'];
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Document size exceeds the allowed secure upload limit.');
        }

        $originalName = trim((string) $file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = array_map('strtolower', (array) config('security.documents.allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png', 'webp']));
        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Only PDF, JPG, JPEG, PNG, and WEBP files are allowed.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        $allowedMimeTypes = (array) config('security.documents.allowed_mime_types', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);
        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException('The uploaded file type is not permitted.');
        }

        return [
            'tmp_name' => $tmpName,
            'original_name' => substr(basename($originalName), 0, 255),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'sha256_hash' => hash_file('sha256', $tmpName),
        ];
    }

    private function buildStorageDirectory(int $branchId, string $bookingReference): string
    {
        $safeReference = preg_replace('/[^A-Za-z0-9_-]/', '_', $bookingReference) ?: 'booking';
        $datePath = date('Y/m');

        return base_path('/storage/documents/branch_' . $branchId . '/' . $safeReference . '/' . $datePath);
    }

    private function normalizeDocumentType(string $value): string
    {
        $type = trim($value);
        if (! array_key_exists($type, self::DOCUMENT_TYPES)) {
            throw new RuntimeException('Please select a valid document type.');
        }

        return $type;
    }

    private function linkTargets(
        int $bookingId,
        array $travelers,
        array $services,
        array $customerPaymentFoundation,
        array $supplierFoundation
    ): array {
        $targets = [
            [
                'value' => 'booking:' . $bookingId,
                'label' => 'Booking File',
            ],
        ];

        foreach ($travelers as $traveler) {
            $travelerId = (int) ($traveler['id'] ?? 0);
            if ($travelerId <= 0) {
                continue;
            }

            $targets[] = [
                'value' => 'traveler:' . $travelerId,
                'label' => 'Traveler: ' . (string) ($traveler['full_name'] ?? 'Traveler'),
            ];
        }

        foreach ($services as $service) {
            $serviceId = (int) ($service['id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $targets[] = [
                'value' => 'service:' . $serviceId,
                'label' => 'Service: ' . (string) ($service['line_reference'] ?? 'SV') . ' / ' . ucwords((string) ($service['service_type'] ?? 'service')),
            ];
        }

        foreach (($customerPaymentFoundation['receipts'] ?? []) as $receipt) {
            $receiptId = (int) ($receipt['id'] ?? 0);
            if ($receiptId <= 0) {
                continue;
            }

            $targets[] = [
                'value' => 'receipt:' . $receiptId,
                'label' => 'Customer Receipt: ' . (string) ($receipt['receiptNo'] ?? 'RCPT'),
            ];
        }

        foreach (($supplierFoundation['payments'] ?? []) as $payment) {
            $paymentId = (int) ($payment['id'] ?? 0);
            if ($paymentId <= 0) {
                continue;
            }

            $targets[] = [
                'value' => 'supplier_payment:' . $paymentId,
                'label' => 'Supplier Payment: ' . (string) ($payment['paymentNo'] ?? 'SPAY'),
            ];
        }

        foreach (($supplierFoundation['obligations'] ?? []) as $obligation) {
            $obligationId = (int) ($obligation['id'] ?? 0);
            if ($obligationId <= 0) {
                continue;
            }

            $targets[] = [
                'value' => 'supplier_obligation:' . $obligationId,
                'label' => 'Supplier Obligation: ' . (string) ($obligation['supplier'] ?? 'Supplier') . ' / ' . (string) ($obligation['serviceLineReference'] ?? 'Service'),
            ];
        }

        return $targets;
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
            throw new RuntimeException('One of the document values exceeds the allowed length.');
        }

        return $text;
    }
}
