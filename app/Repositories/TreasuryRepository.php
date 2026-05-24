<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

final class TreasuryRepository extends BaseRepository
{
    public function accounts(array $branchIds): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        if ($branchIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));

        $statement = $this->db->prepare(
            "SELECT
                ta.*,
                b.name AS branch_name,
                coa.code AS linked_account_code,
                coa.name AS linked_account_name
             FROM treasury_accounts ta
             INNER JOIN branches b ON b.id = ta.branch_id
             LEFT JOIN chart_of_accounts coa ON coa.id = ta.linked_account_id
             WHERE ta.branch_id IN ({$placeholders})
             ORDER BY
                b.name ASC,
                FIELD(ta.account_type, 'cash', 'bank', 'wallet', 'bank_clearing', 'card_clearing'),
                ta.account_name ASC"
        );
        $statement->execute($branchIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findAccount(int $id, array $branchIds): ?array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        if ($id <= 0 || $branchIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
        $params = array_merge([$id], $branchIds);

        $statement = $this->db->prepare(
            "SELECT *
             FROM treasury_accounts
             WHERE id = ?
               AND branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute($params);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function branches(array $branchIds): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        if ($branchIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));

        $statement = $this->db->prepare(
            "SELECT id, code, name, base_currency
             FROM branches
             WHERE id IN ({$placeholders})
             ORDER BY name ASC"
        );
        $statement->execute($branchIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function currencies(): array
    {
        $statement = $this->db->query(
            "SELECT code, name
             FROM currencies
             WHERE is_active = 1
             ORDER BY sort_order ASC, code ASC"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [
            ['code' => 'PKR', 'name' => 'Pakistani Rupee'],
        ];
    }

    public function assetLedgerAccounts(): array
    {
        $statement = $this->db->query(
            "SELECT id, code, name
             FROM chart_of_accounts
             WHERE account_type = 'asset'
               AND is_active = 1
             ORDER BY code ASC"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveAccount(array $data, array $branchIds): int
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        return $this->transaction(function () use ($data, $branchIds): int {
            $id = (int) ($data['id'] ?? 0);
            $branchId = (int) ($data['branch_id'] ?? 0);

            if ($branchId <= 0 || ! in_array($branchId, $branchIds, true)) {
                throw new RuntimeException('Please select an accessible branch.');
            }

            $accountType = trim((string) ($data['account_type'] ?? ''));
            $allowedTypes = ['cash', 'bank', 'wallet', 'card_clearing', 'bank_clearing'];

            if (! in_array($accountType, $allowedTypes, true)) {
                throw new RuntimeException('Please select a valid account type.');
            }

            $accountName = trim((string) ($data['account_name'] ?? ''));

            if ($accountName === '') {
                throw new RuntimeException('Please enter account name.');
            }

            $accountCode = strtoupper(trim((string) ($data['account_code'] ?? '')));

            if ($accountCode === '') {
                throw new RuntimeException('Please enter account code.');
            }

            $currency = strtoupper(trim((string) ($data['currency'] ?? 'PKR')));

            if ($currency === '') {
                $currency = 'PKR';
            }

            $linkedAccountId = (int) ($data['linked_account_id'] ?? 0);
            $linkedAccountId = $linkedAccountId > 0 ? $linkedAccountId : null;

            $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);
            $openingBalanceDate = trim((string) ($data['opening_balance_date'] ?? ''));
            $openingBalanceDate = $openingBalanceDate !== '' ? $openingBalanceDate : null;

            $payload = [
                'branch_id' => $branchId,
                'linked_account_id' => $linkedAccountId,
                'account_type' => $accountType,
                'account_name' => $accountName,
                'account_code' => $accountCode,
                'currency' => $currency,
                'bank_name' => trim((string) ($data['bank_name'] ?? '')) ?: null,
                'account_number' => trim((string) ($data['account_number'] ?? '')) ?: null,
                'iban' => trim((string) ($data['iban'] ?? '')) ?: null,
                'opening_balance' => $openingBalance,
                'opening_balance_date' => $openingBalanceDate,
                'is_default' => isset($data['is_default']) ? 1 : 0,
                'is_active' => isset($data['is_active']) ? 1 : 0,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ];

            if ($id > 0) {
                $existing = $this->findAccount($id, $branchIds);

                if ($existing === null) {
                    throw new RuntimeException('Treasury account not found.');
                }

                $statement = $this->db->prepare(
                    'UPDATE treasury_accounts
                     SET branch_id = :branch_id,
                         linked_account_id = :linked_account_id,
                         account_type = :account_type,
                         account_name = :account_name,
                         account_code = :account_code,
                         currency = :currency,
                         bank_name = :bank_name,
                         account_number = :account_number,
                         iban = :iban,
                         opening_balance = :opening_balance,
                         opening_balance_date = :opening_balance_date,
                         is_default = :is_default,
                         is_active = :is_active,
                         notes = :notes
                     WHERE id = :id'
                );

                $payload['id'] = $id;
                $statement->execute($payload);

                return $id;
            }

            $payload['created_by_user_id'] = $data['created_by_user_id'] ?? null;

            $statement = $this->db->prepare(
                'INSERT INTO treasury_accounts (
                    branch_id,
                    linked_account_id,
                    account_type,
                    account_name,
                    account_code,
                    currency,
                    bank_name,
                    account_number,
                    iban,
                    opening_balance,
                    opening_balance_date,
                    is_default,
                    is_active,
                    notes,
                    created_by_user_id
                ) VALUES (
                    :branch_id,
                    :linked_account_id,
                    :account_type,
                    :account_name,
                    :account_code,
                    :currency,
                    :bank_name,
                    :account_number,
                    :iban,
                    :opening_balance,
                    :opening_balance_date,
                    :is_default,
                    :is_active,
                    :notes,
                    :created_by_user_id
                )'
            );
            $statement->execute($payload);

            return (int) $this->db->lastInsertId();
        });
    }
}
