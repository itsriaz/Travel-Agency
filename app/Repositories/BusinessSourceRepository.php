<?php

declare(strict_types=1);

namespace App\Repositories;

final class BusinessSourceRepository extends BaseRepository
{
    public function activeOptions(): array
    {
        $statement = $this->db->query(
            'SELECT id, code, name, phone, address, description
             FROM business_sources
             WHERE is_active = 1
             ORDER BY
                CASE WHEN LOWER(name) = "walking client" THEN 0 ELSE 1 END,
                name ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, phone, address, description, is_active
             FROM business_sources
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function defaultId(): int
    {
        $statement = $this->db->query(
            'SELECT id
             FROM business_sources
             WHERE is_active = 1
             ORDER BY
                CASE WHEN LOWER(name) = "walking client" THEN 0 ELSE 1 END,
                name ASC
             LIMIT 1'
        );

        return (int) ($statement ? $statement->fetchColumn() : 0);
    }
}
