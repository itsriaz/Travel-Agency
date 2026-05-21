<?php

declare(strict_types=1);

namespace App\Repositories;

final class OfflineDraftSyncRepository extends BaseRepository
{
    public function findForUserClientDraft(int $userId, string $clientDraftId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, branch_id, client_draft_id, draft_type, source_device, status,
                    server_record_type, server_record_id, server_reference, error_message, payload_json,
                    created_at, updated_at
             FROM offline_draft_syncs
             WHERE user_id = :user_id
               AND client_draft_id = :client_draft_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'client_draft_id' => $clientDraftId,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function recordResult(array $data): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO offline_draft_syncs (
                user_id, branch_id, client_draft_id, draft_type, source_device, status,
                server_record_type, server_record_id, server_reference, error_message, payload_json
             ) VALUES (
                :user_id, :branch_id, :client_draft_id, :draft_type, :source_device, :status,
                :server_record_type, :server_record_id, :server_reference, :error_message, :payload_json
             )
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                server_record_type = VALUES(server_record_type),
                server_record_id = VALUES(server_record_id),
                server_reference = VALUES(server_reference),
                error_message = VALUES(error_message),
                payload_json = VALUES(payload_json),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $data['user_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'client_draft_id' => $data['client_draft_id'],
            'draft_type' => $data['draft_type'],
            'source_device' => $data['source_device'] ?? null,
            'status' => $data['status'],
            'server_record_type' => $data['server_record_type'] ?? null,
            'server_record_id' => $data['server_record_id'] ?? null,
            'server_reference' => $data['server_reference'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'payload_json' => json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
