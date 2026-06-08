<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\OfflineDraftSyncRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;
use Throwable;

final class OfflineWorkspaceService extends Service
{
    private const ALLOWED_DRAFT_TYPES = ['traveler.create'];

    public function snapshot(array $accessibleBranchIds, int $actorUserId): array
    {
        $bookings = (new BookingRepository($this->app))->searchBookings('', $accessibleBranchIds, 75);
        $travelers = (new TravelerRepository($this->app))->searchTravelers('', $accessibleBranchIds, 200);
        $customerOutstanding = (new CustomerPaymentRepository($this->app))->receivableCollectionSnapshot($accessibleBranchIds);
        $suppliers = (new SupplierRepository($this->app))->activeSuppliersForBranches($accessibleBranchIds);

        AuditLog::record($this->app, 'offline.snapshot.generated', [
            'user_id' => $actorUserId,
            'branch_ids' => $accessibleBranchIds,
            'booking_count' => count($bookings),
            'traveler_count' => count($travelers),
        ]);

        return [
            'generated_at' => date(DATE_ATOM),
            'branch_ids' => array_values(array_map('intval', $accessibleBranchIds)),
            'bookings' => $bookings,
            'travelers' => $travelers,
            'customer_outstanding' => $customerOutstanding,
            'suppliers' => $suppliers,
            'offline_policy' => [
                'allowed_drafts' => self::ALLOWED_DRAFT_TYPES,
                'blocked_financial_actions' => [
                    'customer_receipt',
                    'receipt_allocation',
                    'supplier_payment',
                    'supplier_advance',
                    'supplier_allocation',
                    'void_or_reversal',
                    'exchange_rate_settlement',
                ],
            ],
        ];
    }

    public function syncDrafts(array $drafts, int $actorUserId, array $accessibleBranchIds, string $sourceDevice): array
    {
        if (count($drafts) > 50) {
            throw new RuntimeException('Sync is limited to 50 drafts per request.');
        }

        $repository = new OfflineDraftSyncRepository($this->app);
        $results = [];

        foreach ($drafts as $draft) {
            if (! is_array($draft)) {
                continue;
            }

            $payload = is_array($draft['payload'] ?? null) ? $draft['payload'] : [];
            $branchId = (int) ($payload['branch_id'] ?? $payload['traveler_branch_id'] ?? 0);
            $clientDraftId = trim((string) ($draft['client_draft_id'] ?? ''));
            $draftType = trim((string) ($draft['type'] ?? ''));

            try {
                $clientDraftId = $this->requiredText($clientDraftId, 'Client draft ID', 100);
                $draftType = $this->requiredText($draftType, 'Draft type', 50);

                $existing = $repository->findForUserClientDraft($actorUserId, $clientDraftId);
                if ($existing !== null) {
                    $results[] = $this->resultFromExisting($existing);
                    continue;
                }

                if (! in_array($draftType, self::ALLOWED_DRAFT_TYPES, true)) {
                    throw new RuntimeException('This draft type is not allowed for offline sync.');
                }

                $result = $this->syncTravelerDraft($payload, $actorUserId, $accessibleBranchIds);

                $repository->recordResult([
                    'user_id' => $actorUserId,
                    'branch_id' => $branchId > 0 ? $branchId : ($result['branch_id'] ?? null),
                    'client_draft_id' => $clientDraftId,
                    'draft_type' => $draftType,
                    'source_device' => $sourceDevice,
                    'status' => 'synced',
                    'server_record_type' => $result['record_type'],
                    'server_record_id' => $result['record_id'],
                    'server_reference' => $result['reference'] ?? null,
                    'payload' => $payload,
                ]);

                $results[] = [
                    'client_draft_id' => $clientDraftId,
                    'type' => $draftType,
                    'status' => 'synced',
                    'server_record_type' => $result['record_type'],
                    'server_record_id' => $result['record_id'],
                    'server_reference' => $result['reference'] ?? null,
                ];
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 255);
                if ($clientDraftId !== '' && $draftType !== '') {
                    $repository->recordResult([
                        'user_id' => $actorUserId,
                        'branch_id' => $branchId > 0 ? $branchId : null,
                        'client_draft_id' => mb_substr($clientDraftId, 0, 100),
                        'draft_type' => mb_substr($draftType, 0, 50),
                        'source_device' => $sourceDevice,
                        'status' => 'rejected',
                        'error_message' => $message,
                        'payload' => $payload,
                    ]);
                }

                $results[] = [
                    'client_draft_id' => $clientDraftId,
                    'type' => $draftType,
                    'status' => 'rejected',
                    'message' => $message,
                ];
            }
        }

        AuditLog::record($this->app, 'offline.drafts.synced', [
            'user_id' => $actorUserId,
            'source_device' => $sourceDevice,
            'draft_count' => count($results),
        ]);

        return $results;
    }

    private function syncTravelerDraft(array $payload, int $actorUserId, array $accessibleBranchIds): array
    {
        $payload['booking_id'] = 0;
        $payload['traveler_role'] = 'additional';
        $saved = (new TravelerWorkspaceService($this->app))->saveTraveler($payload, $actorUserId, $accessibleBranchIds);
        $traveler = is_array($saved['traveler'] ?? null) ? $saved['traveler'] : [];

        return [
            'record_type' => 'traveler',
            'record_id' => (int) ($traveler['id'] ?? 0),
            'reference' => (string) ($traveler['full_name'] ?? ''),
            'branch_id' => (int) ($traveler['branch_id'] ?? 0),
        ];
    }

    private function resultFromExisting(array $existing): array
    {
        return [
            'client_draft_id' => (string) ($existing['client_draft_id'] ?? ''),
            'type' => (string) ($existing['draft_type'] ?? ''),
            'status' => (string) ($existing['status'] ?? 'rejected'),
            'server_record_type' => $existing['server_record_type'] ?? null,
            'server_record_id' => isset($existing['server_record_id']) ? (int) $existing['server_record_id'] : null,
            'server_reference' => $existing['server_reference'] ?? null,
            'message' => $existing['error_message'] ?? null,
            'already_processed' => true,
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
}
