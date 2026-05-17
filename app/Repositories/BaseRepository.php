<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\App;
use PDO;

abstract class BaseRepository
{
    protected PDO $db;
    /** @var array<string, bool> */
    private array $columnExistsCache = [];

    public function __construct(protected readonly App $app)
    {
        /** @var PDO $db */
        $db = $app->get('db');
        $this->db = $db;
    }

    protected function transaction(callable $callback): mixed
    {
        $startedTransaction = ! $this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $callback($this->db);
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    protected function tableExists(string $tableName): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
             LIMIT 1'
        );
        $statement->execute([
            'table_name' => $tableName,
        ]);

        return $statement->fetchColumn() !== false;
    }

    protected function columnExists(string $tableName, string $columnName): bool
    {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        $statement = $this->db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $statement->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        $exists = $statement->fetchColumn() !== false;
        $this->columnExistsCache[$cacheKey] = $exists;

        return $exists;
    }
}
