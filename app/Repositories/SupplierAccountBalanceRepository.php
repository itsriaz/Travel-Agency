<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SupplierAccountBalanceRepository extends BaseRepository
{
    public function supplierNames(): array
    {
        return $this->db->query('SELECT id, name FROM suppliers')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allBranchIds(): array
    {
        return array_map('intval', $this->db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function sameNameSupplierIds(int $supplierId): array
    {
        $statement = $this->db->prepare(
            'SELECT pooled.id FROM suppliers pooled
             INNER JOIN suppliers selected ON selected.name = pooled.name
             WHERE selected.id = :supplier_id ORDER BY pooled.id'
        );
        $statement->execute(['supplier_id' => $supplierId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

}
