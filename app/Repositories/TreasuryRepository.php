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

    public function eligiblePaymentTreasuryAccounts(int $branchId, string $currency, string $paymentMethod): array
    {
        $compatibleTypes = $this->compatibleTreasuryTypesForPaymentMethod($paymentMethod);
        if ($branchId <= 0 || $currency === '' || $compatibleTypes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($compatibleTypes), '?'));
        $params = array_merge([$branchId, strtoupper(trim($currency))], $compatibleTypes);

        $statement = $this->db->prepare(
            "SELECT
                id,
                branch_id,
                account_type,
                account_name,
                account_code,
                currency,
                bank_name,
                account_number,
                iban,
                is_default,
                is_active
             FROM treasury_accounts
             WHERE branch_id = ?
               AND currency = ?
               AND is_active = 1
               AND account_type IN ({$placeholders})
             ORDER BY is_default DESC, account_name ASC, id ASC"
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function defaultTreasuryAccountForPayment(int $branchId, string $currency, string $paymentMethod): ?array
    {
        $eligible = $this->eligiblePaymentTreasuryAccounts($branchId, $currency, $paymentMethod);
        if ($eligible === []) {
            return null;
        }

        foreach ($eligible as $account) {
            if ((int) ($account['is_default'] ?? 0) === 1) {
                return $account;
            }
        }

        return count($eligible) === 1 ? $eligible[0] : null;
    }

    public function validatePaymentTreasuryAccount(int $treasuryAccountId, int $branchId, string $currency, string $paymentMethod): array
    {
        if ($treasuryAccountId <= 0) {
            throw new RuntimeException('Please configure/select a cash or bank account for this payment.');
        }

        $statement = $this->db->prepare(
            'SELECT
                id,
                branch_id,
                account_type,
                account_name,
                account_code,
                currency,
                bank_name,
                account_number,
                iban,
                is_default,
                is_active
             FROM treasury_accounts
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $treasuryAccountId,
        ]);

        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if ($account === false) {
            throw new RuntimeException('Please configure/select a cash or bank account for this payment.');
        }

        if ((int) ($account['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Selected cash or bank account is inactive.');
        }

        if ((int) ($account['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('Selected cash or bank account does not belong to this branch.');
        }

        if (strtoupper((string) ($account['currency'] ?? '')) !== strtoupper(trim($currency))) {
            throw new RuntimeException('Selected cash or bank account does not match the payment currency.');
        }

        $compatibleTypes = $this->compatibleTreasuryTypesForPaymentMethod($paymentMethod);
        if (! in_array((string) ($account['account_type'] ?? ''), $compatibleTypes, true)) {
            throw new RuntimeException('Selected cash or bank account is not valid for this payment method.');
        }

        return $account;
    }

    private function compatibleTreasuryTypesForPaymentMethod(string $paymentMethod): array
    {
        return match (str_replace(' ', '_', mb_strtolower(trim($paymentMethod)))) {
            'cash' => ['cash'],
            'bank_transfer' => ['bank'],
            'wallet', 'wallet_mobile', 'mobile_wallet' => ['wallet'],
            default => [],
        };
    }

    private function linkedLedgerAccountIdForType(string $accountType): ?int
    {
        $codeMap = [
            'cash' => 'CASH_ON_HAND',
            'bank' => 'BANK_CLEARING',
            'wallet' => 'BANK_CLEARING',
            'bank_clearing' => 'BANK_CLEARING',
            'card_clearing' => 'CARD_CLEARING',
        ];

        $code = $codeMap[$accountType] ?? '';

        if ($code === '') {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM chart_of_accounts
             WHERE code = :code
               AND is_active = 1
             LIMIT 1'
        );
        $statement->execute([
            'code' => $code,
        ]);

        $id = $statement->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function accountTypeSupportsBankDetails(string $accountType): bool
    {
        return in_array($accountType, ['bank', 'wallet'], true);
    }

    private function normalizeAccountCodePart(string $value, int $maxLength = 48): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        $value = trim($value, '_');

        if ($value === '') {
            $value = 'ACCOUNT';
        }

        if (mb_strlen($value) > $maxLength) {
            $value = rtrim((string) mb_substr($value, 0, $maxLength), '_');
        }

        return $value !== '' ? $value : 'ACCOUNT';
    }

    private function baseAccountCodeFor(string $accountType, string $accountName, string $currency): string
    {
        $normalizedCurrency = $this->normalizeAccountCodePart($currency, 12);
        $normalizedAccountName = $this->normalizeAccountCodePart($accountName, 48);

        return match ($accountType) {
            'cash' => 'CASH_' . $normalizedAccountName . '_' . $normalizedCurrency,
            'bank' => 'BANK_' . $normalizedAccountName . '_' . $normalizedCurrency,
            'wallet' => 'WALLET_' . $normalizedAccountName . '_' . $normalizedCurrency,
            'bank_clearing' => 'BANK_CLEARING_' . $normalizedCurrency,
            'card_clearing' => 'CARD_CLEARING_' . $normalizedCurrency,
            default => 'TREASURY_' . $normalizedAccountName . '_' . $normalizedCurrency,
        };
    }

    private function uniqueAccountCode(string $baseCode, ?int $excludeId = null): string
    {
        $baseCode = $this->normalizeAccountCodePart($baseCode, 72);
        $suffix = 1;
        $candidate = $baseCode;

        while (true) {
            $statement = $this->db->prepare(
                'SELECT id
                 FROM treasury_accounts
                 WHERE account_code = :account_code'
                 . ($excludeId !== null ? ' AND id <> :exclude_id' : '')
                 . ' LIMIT 1'
            );

            $params = ['account_code' => $candidate];
            if ($excludeId !== null) {
                $params['exclude_id'] = $excludeId;
            }

            $statement->execute($params);
            $existingId = $statement->fetchColumn();

            if ($existingId === false) {
                return $candidate;
            }

            $suffix++;
            $suffixText = '_' . $suffix;
            $prefixLimit = 80 - strlen($suffixText);
            $candidate = rtrim(substr($baseCode, 0, $prefixLimit), '_') . $suffixText;
        }
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

            $currency = strtoupper(trim((string) ($data['currency'] ?? 'PKR')));

            if ($currency === '') {
                $currency = 'PKR';
            }

            $linkedAccountId = $this->linkedLedgerAccountIdForType($accountType);

            if ($linkedAccountId === null) {
                throw new RuntimeException('Linked ledger account is missing for the selected treasury account type.');
            }

            $supportsBankDetails = $this->accountTypeSupportsBankDetails($accountType);
            $baseAccountCode = $this->baseAccountCodeFor($accountType, $accountName, $currency);

            $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);
            $openingBalanceDate = trim((string) ($data['opening_balance_date'] ?? ''));
            $openingBalanceDate = $openingBalanceDate !== '' ? $openingBalanceDate : null;

            $payload = [
                'branch_id' => $branchId,
                'linked_account_id' => $linkedAccountId,
                'account_type' => $accountType,
                'account_name' => $accountName,
                'currency' => $currency,
                'bank_name' => $supportsBankDetails ? (trim((string) ($data['bank_name'] ?? '')) ?: null) : null,
                'account_number' => $supportsBankDetails ? (trim((string) ($data['account_number'] ?? '')) ?: null) : null,
                'iban' => $supportsBankDetails ? (trim((string) ($data['iban'] ?? '')) ?: null) : null,
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

                $existingAccountCode = strtoupper(trim((string) ($existing['account_code'] ?? '')));
                $payload['account_code'] = $existingAccountCode !== ''
                    ? $existingAccountCode
                    : $this->uniqueAccountCode($baseAccountCode, $id);

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
            $payload['account_code'] = $this->uniqueAccountCode($baseAccountCode);

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
