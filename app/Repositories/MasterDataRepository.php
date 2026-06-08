<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

final class MasterDataRepository extends BaseRepository
{
    private const REGISTER_CONFIG = [
        'branches' => [
            'table' => 'branches',
            'columns' => ['code', 'name', 'city', 'country_code', 'base_currency', 'is_active'],
            'order_by' => 'is_active DESC, name ASC',
        ],
        'currencies' => [
            'table' => 'currencies',
            'columns' => ['code', 'name', 'symbol', 'reporting_role', 'sort_order', 'is_system', 'is_active'],
            'order_by' => 'sort_order ASC, name ASC',
        ],
        'service_types' => [
            'table' => 'service_types',
            'columns' => ['code', 'name', 'posting_mode', 'sort_order', 'is_system', 'is_active'],
            'order_by' => 'sort_order ASC, name ASC',
        ],
        'payment_methods' => [
            'table' => 'payment_methods',
            'columns' => ['code', 'name', 'ledger_target', 'charges_target', 'sort_order', 'is_system', 'is_active'],
            'order_by' => 'sort_order ASC, name ASC',
        ],
        'supplier_modes' => [
            'table' => 'supplier_modes',
            'columns' => ['code', 'name', 'behavior', 'sort_order', 'is_system', 'is_active'],
            'order_by' => 'sort_order ASC, name ASC',
        ],
        'document_types' => [
            'table' => 'document_types',
            'columns' => ['code', 'name', 'linked_area', 'sort_order', 'is_system', 'is_active'],
            'order_by' => 'sort_order ASC, name ASC',
        ],
    ];

    public function rows(string $register): array
    {
        $config = $this->config($register);
        $selectColumns = implode(', ', array_merge(['id'], $config['columns']));

        $statement = $this->db->query(
            sprintf(
                'SELECT %s FROM %s ORDER BY %s',
                $selectColumns,
                $config['table'],
                $config['order_by']
            )
        );

        return $statement->fetchAll() ?: [];
    }

    public function activeRows(string $register): array
    {
        return array_values(array_filter(
            $this->rows($register),
            static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1
        ));
    }

    public function activeCodeLabelMap(string $register): array
    {
        $options = [];

        foreach ($this->activeRows($register) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $options[$code] = trim((string) ($row['name'] ?? $code));
        }

        return $options;
    }

    public function find(string $register, int $id): ?array
    {
        $config = $this->config($register);
        $selectColumns = implode(', ', array_merge(['id'], $config['columns']));

        $statement = $this->db->prepare(
            sprintf('SELECT %s FROM %s WHERE id = :id LIMIT 1', $selectColumns, $config['table'])
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function save(string $register, array $payload): int
    {
        $config = $this->config($register);
        $columns = $config['columns'];
        $data = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $payload)) {
                $data[$column] = $payload[$column];
            }
        }

        if ($data === []) {
            throw new RuntimeException('No writable fields were supplied.');
        }

        $id = (int) ($payload['id'] ?? 0);

        if ($id > 0) {
            $assignments = implode(', ', array_map(
                static fn (string $column): string => $column . ' = :' . $column,
                array_keys($data)
            ));
            $data['id'] = $id;

            $statement = $this->db->prepare(
                sprintf('UPDATE %s SET %s WHERE id = :id', $config['table'], $assignments)
            );
            $statement->execute($data);

            return $id;
        }

        $columnList = implode(', ', array_keys($data));
        $placeholderList = implode(', ', array_map(
            static fn (string $column): string => ':' . $column,
            array_keys($data)
        ));

        $statement = $this->db->prepare(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $config['table'], $columnList, $placeholderList)
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function delete(string $register, int $id): void
    {
        $config = $this->config($register);
        $statement = $this->db->prepare(sprintf('DELETE FROM %s WHERE id = :id', $config['table']));
        $statement->execute(['id' => $id]);
    }

    public function codeExists(string $register, string $code, ?int $excludeId = null): bool
    {
        $config = $this->config($register);
        $statement = $this->db->prepare(
            sprintf(
                'SELECT id FROM %s WHERE code = :code%s LIMIT 1',
                $config['table'],
                $excludeId !== null ? ' AND id != :exclude_id' : ''
            )
        );
        $params = ['code' => $code];

        if ($excludeId !== null) {
            $params['exclude_id'] = $excludeId;
        }

        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function deleteBlockedReason(string $register, int $id): ?string
    {
        $record = $this->find($register, $id);

        if ($record === null) {
            return 'Record not found.';
        }

        if ((int) ($record['is_system'] ?? 0) === 1) {
            return 'System register rows cannot be deleted.';
        }

        return match ($register) {
            'branches' => $this->branchDeleteBlockedReason($id),
            'currencies' => $this->currencyDeleteBlockedReason((string) $record['code']),
            'payment_methods' => $this->codeReferenceReason('customer_receipts', 'payment_method', (string) $record['code'], 'This payment method is already used in customer receipts.'),
            'supplier_modes' => $this->codeReferenceReason('suppliers', 'supplier_mode', (string) $record['code'], 'This supplier mode is already used by suppliers.'),
            default => null,
        };
    }

    private function branchDeleteBlockedReason(int $branchId): ?string
    {
        $checks = [
            ['table' => 'users', 'column' => 'default_branch_id', 'message' => 'This branch is assigned as a default branch for one or more users.'],
            ['table' => 'user_branch_access', 'column' => 'branch_id', 'message' => 'This branch is assigned in user branch access.'],
            ['table' => 'journal_entries', 'column' => 'branch_id', 'message' => 'This branch already has journal entries.'],
            ['table' => 'suppliers', 'column' => 'branch_id', 'message' => 'This branch already has suppliers.'],
            ['table' => 'supplier_advances', 'column' => 'branch_id', 'message' => 'This branch already has supplier advances.'],
            ['table' => 'supplier_obligations', 'column' => 'branch_id', 'message' => 'This branch already has supplier obligations.'],
            ['table' => 'customer_receivable_items', 'column' => 'branch_id', 'message' => 'This branch already has customer receivable items.'],
            ['table' => 'customer_receipts', 'column' => 'branch_id', 'message' => 'This branch already has customer receipts.'],
        ];

        foreach ($checks as $check) {
            if ($this->countByValue($check['table'], $check['column'], $branchId) > 0) {
                return $check['message'];
            }
        }

        return null;
    }

    private function currencyDeleteBlockedReason(string $currencyCode): ?string
    {
        $checks = [
            ['table' => 'branches', 'column' => 'base_currency', 'message' => 'This currency is configured as a branch base currency.'],
            ['table' => 'suppliers', 'column' => 'default_currency', 'message' => 'This currency is already used by suppliers.'],
            ['table' => 'supplier_advances', 'column' => 'currency', 'message' => 'This currency is already used in supplier advances.'],
            ['table' => 'supplier_obligations', 'column' => 'currency', 'message' => 'This currency is already used in supplier obligations.'],
            ['table' => 'customer_receivable_items', 'column' => 'currency', 'message' => 'This currency is already used in receivable items.'],
            ['table' => 'customer_receipts', 'column' => 'currency', 'message' => 'This currency is already used in customer receipts.'],
            ['table' => 'journal_entries', 'column' => 'currency', 'message' => 'This currency is already used in journal entries.'],
        ];

        foreach ($checks as $check) {
            if ($this->countByValue($check['table'], $check['column'], $currencyCode) > 0) {
                return $check['message'];
            }
        }

        return null;
    }

    private function codeReferenceReason(string $table, string $column, string $value, string $message): ?string
    {
        return $this->countByValue($table, $column, $value) > 0 ? $message : null;
    }

    private function countByValue(string $table, string $column, int|string $value): int
    {
        $statement = $this->db->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s = :value', $table, $column));
        $statement->execute(['value' => $value]);

        return (int) $statement->fetchColumn();
    }

    private function config(string $register): array
    {
        $config = self::REGISTER_CONFIG[$register] ?? null;

        if ($config === null) {
            throw new RuntimeException('Unknown master-data register: ' . $register);
        }

        return $config;
    }
}
