<?php

declare(strict_types=1);

namespace App\Repositories;

final class CounterpartyLinkRepository extends BaseRepository
{
    public function findBusinessSource(int $businessSourceId, bool $forUpdate = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, phone, address, description, is_system, is_active
             FROM business_sources
             WHERE id = :id
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $businessSourceId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findSupplier(int $supplierId, bool $forUpdate = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT s.id, s.branch_id, s.code, s.name, s.supplier_mode,
                    s.default_currency, s.is_active, s.notes, b.name AS branch_name
             FROM suppliers s
             LEFT JOIN branches b ON b.id = s.branch_id
             WHERE s.id = :id
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $supplierId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findByBusinessSourceId(int $businessSourceId, bool $forUpdate = false): ?array
    {
        return $this->findLink('l.business_source_id = :record_id', $businessSourceId, $forUpdate);
    }

    public function findBySupplierId(int $supplierId, bool $forUpdate = false): ?array
    {
        return $this->findLink('l.supplier_id = :record_id', $supplierId, $forUpdate);
    }

    public function findById(int $linkId, bool $forUpdate = false): ?array
    {
        return $this->findLink('l.id = :record_id', $linkId, $forUpdate);
    }

    public function listLinks(): array
    {
        $statement = $this->db->query($this->linkSelectSql() . '
            ORDER BY bs.name ASC, s.name ASC, l.id ASC');

        return $statement->fetchAll() ?: [];
    }

    public function availableBusinessSources(): array
    {
        $statement = $this->db->query(
            'SELECT bs.id, bs.code, bs.name, bs.phone
             FROM business_sources bs
             LEFT JOIN business_source_supplier_links l ON l.business_source_id = bs.id
             WHERE bs.is_active = 1
               AND bs.is_system = 0
               AND l.id IS NULL
             ORDER BY bs.name ASC, bs.id ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function availableSuppliers(): array
    {
        $statement = $this->db->query(
            'SELECT s.id, s.code, s.name, s.branch_id, s.default_currency,
                    b.name AS branch_name
             FROM suppliers s
             LEFT JOIN branches b ON b.id = s.branch_id
             LEFT JOIN business_source_supplier_links l ON l.supplier_id = s.id
             WHERE s.is_active = 1
               AND l.id IS NULL
             ORDER BY s.name ASC, s.code ASC, s.id ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function recentHistory(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->query(
            'SELECT h.id, h.action, h.reason, h.created_at,
                    bs.code AS business_source_code, bs.name AS business_source_name,
                    s.code AS supplier_code, s.name AS supplier_name,
                    u.name AS actor_name
             FROM business_source_supplier_link_history h
             INNER JOIN business_sources bs ON bs.id = h.business_source_id
             INNER JOIN suppliers s ON s.id = h.supplier_id
             LEFT JOIN users u ON u.id = h.actor_user_id
             ORDER BY h.id DESC
             LIMIT ' . $limit
        );

        return $statement->fetchAll() ?: [];
    }

    public function createLink(int $businessSourceId, int $supplierId, int $actorUserId): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO business_source_supplier_links (
                business_source_id, supplier_id, linked_by_user_id, linked_at
             ) VALUES (
                :business_source_id, :supplier_id, :linked_by_user_id, NOW()
             )'
        );
        $statement->execute([
            'business_source_id' => $businessSourceId,
            'supplier_id' => $supplierId,
            'linked_by_user_id' => $actorUserId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteLink(int $linkId): void
    {
        $statement = $this->db->prepare('DELETE FROM business_source_supplier_links WHERE id = :id');
        $statement->execute(['id' => $linkId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The account–supplier link no longer exists.');
        }
    }

    public function recordHistory(
        int $businessSourceId,
        int $supplierId,
        string $action,
        ?string $reason,
        int $actorUserId
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO business_source_supplier_link_history (
                business_source_id, supplier_id, action, reason, actor_user_id
             ) VALUES (
                :business_source_id, :supplier_id, :action, :reason, :actor_user_id
             )'
        );
        $statement->execute([
            'business_source_id' => $businessSourceId,
            'supplier_id' => $supplierId,
            'action' => $action,
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function historyForBusinessSource(int $businessSourceId): array
    {
        $statement = $this->db->prepare(
            'SELECT h.id, h.business_source_id, h.supplier_id, h.action, h.reason,
                    h.actor_user_id, h.created_at,
                    bs.code AS business_source_code, bs.name AS business_source_name,
                    s.code AS supplier_code, s.name AS supplier_name,
                    u.name AS actor_name
             FROM business_source_supplier_link_history h
             INNER JOIN business_sources bs ON bs.id = h.business_source_id
             INNER JOIN suppliers s ON s.id = h.supplier_id
             LEFT JOIN users u ON u.id = h.actor_user_id
             WHERE h.business_source_id = :business_source_id
             ORDER BY h.id DESC'
        );
        $statement->execute(['business_source_id' => $businessSourceId]);

        return $statement->fetchAll() ?: [];
    }

    private function findLink(string $condition, int $recordId, bool $forUpdate): ?array
    {
        $statement = $this->db->prepare(
            $this->linkSelectSql() . "\n             WHERE " . $condition
            . "\n             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['record_id' => $recordId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    private function linkSelectSql(): string
    {
        return 'SELECT l.id, l.business_source_id, l.supplier_id,
                       l.linked_by_user_id, l.linked_at, l.created_at, l.updated_at,
                       bs.code AS business_source_code, bs.name AS business_source_name,
                       bs.phone AS business_source_phone, bs.is_active AS business_source_is_active,
                       s.code AS supplier_code, s.name AS supplier_name,
                       s.branch_id AS supplier_branch_id, s.default_currency AS supplier_currency,
                       s.is_active AS supplier_is_active,
                       b.name AS supplier_branch_name,
                       u.name AS linked_by_name
                FROM business_source_supplier_links l
                INNER JOIN business_sources bs ON bs.id = l.business_source_id
                INNER JOIN suppliers s ON s.id = l.supplier_id
                LEFT JOIN branches b ON b.id = s.branch_id
                LEFT JOIN users u ON u.id = l.linked_by_user_id';
    }
}
