<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
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
                coa.name AS linked_account_name,
                ROUND(
                    COALESCE(ta.opening_balance, 0)
                    + COALESCE(receipt_balance.receipt_balance, 0)
                    + COALESCE(treasury_balance.treasury_balance, 0),
                    2
                ) AS current_balance
             FROM treasury_accounts ta
             INNER JOIN branches b ON b.id = ta.branch_id
             LEFT JOIN chart_of_accounts coa ON coa.id = ta.linked_account_id
             LEFT JOIN (
                SELECT
                    movement.treasury_account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS receipt_balance
                FROM (
                    SELECT
                        ta_match.id AS treasury_account_id,
                        COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0) AS signed_amount
                    FROM journal_entry_lines jel
                    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    INNER JOIN treasury_accounts ta_match
                        ON ta_match.linked_account_id = jel.account_id
                       AND ta_match.branch_id = je.branch_id
                       AND ta_match.currency = je.currency
                       AND ta_match.is_active = 1
                       AND ta_match.account_code = coa.code

                    UNION ALL

                    SELECT
                        cr.treasury_account_id AS treasury_account_id,
                        COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0) AS signed_amount
                    FROM customer_receipts cr
                    INNER JOIN journal_entry_lines jel ON jel.customer_receipt_id = cr.id
                    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    INNER JOIN treasury_accounts ta_link ON ta_link.id = cr.treasury_account_id
                    WHERE cr.treasury_account_id IS NOT NULL
                      AND coa.code IN ('CASH_ON_HAND', 'BANK_CLEARING', 'CARD_CLEARING')
                ) AS movement
                GROUP BY movement.treasury_account_id
             ) AS receipt_balance ON receipt_balance.treasury_account_id = ta.id
             LEFT JOIN (
                SELECT
                    movement.account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS treasury_balance
                FROM (
                    SELECT
                        tt.to_treasury_account_id AS account_id,
                        tt.amount AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = 'posted'
                      AND tt.journal_entry_id IS NULL
                      AND tt.to_treasury_account_id IS NOT NULL

                    UNION ALL

                    SELECT
                        tt.from_treasury_account_id AS account_id,
                        -tt.amount AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = 'posted'
                      AND tt.journal_entry_id IS NULL
                      AND tt.from_treasury_account_id IS NOT NULL
                ) AS movement
                GROUP BY movement.account_id
             ) AS treasury_balance ON treasury_balance.account_id = ta.id
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

    public function transferAccounts(array $branchIds): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        if ($branchIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                ta.id,
                ta.branch_id,
                b.name AS branch_name,
                ta.account_type,
                ta.account_name,
                ta.account_code,
                ta.currency,
                ta.is_default,
                ta.is_active,
                ROUND(
                    COALESCE(ta.opening_balance, 0)
                    + COALESCE(receipt_balance.receipt_balance, 0)
                    + COALESCE(treasury_balance.treasury_balance, 0),
                    2
                ) AS current_balance
             FROM treasury_accounts ta
             INNER JOIN branches b ON b.id = ta.branch_id
             LEFT JOIN (
                SELECT
                    movement.treasury_account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS receipt_balance
                FROM (
                    SELECT
                        ta_match.id AS treasury_account_id,
                        COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0) AS signed_amount
                    FROM journal_entry_lines jel
                    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    INNER JOIN treasury_accounts ta_match
                        ON ta_match.linked_account_id = jel.account_id
                       AND ta_match.branch_id = je.branch_id
                       AND ta_match.currency = je.currency
                       AND ta_match.is_active = 1
                       AND ta_match.account_code = coa.code

                    UNION ALL

                    SELECT
                        cr.treasury_account_id AS treasury_account_id,
                        COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0) AS signed_amount
                    FROM customer_receipts cr
                    INNER JOIN journal_entry_lines jel ON jel.customer_receipt_id = cr.id
                    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    WHERE cr.treasury_account_id IS NOT NULL
                      AND coa.code IN ('CASH_ON_HAND', 'BANK_CLEARING', 'CARD_CLEARING')
                ) AS movement
                GROUP BY movement.treasury_account_id
             ) AS receipt_balance ON receipt_balance.treasury_account_id = ta.id
             LEFT JOIN (
                SELECT
                    movement.account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS treasury_balance
                FROM (
                    SELECT
                        tt.to_treasury_account_id AS account_id,
                        tt.amount AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = 'posted'
                      AND tt.journal_entry_id IS NULL
                      AND tt.to_treasury_account_id IS NOT NULL

                    UNION ALL

                    SELECT
                        tt.from_treasury_account_id AS account_id,
                        -tt.amount AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = 'posted'
                      AND tt.journal_entry_id IS NULL
                      AND tt.from_treasury_account_id IS NOT NULL
                ) AS movement
                GROUP BY movement.account_id
             ) AS treasury_balance ON treasury_balance.account_id = ta.id
             WHERE ta.branch_id IN ({$placeholders})
               AND ta.is_active = 1
               AND ta.account_type IN ('cash', 'bank')
             ORDER BY b.name ASC, ta.account_type ASC, ta.is_default DESC, ta.account_name ASC"
        );
        $statement->execute($branchIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function recentTransfers(array $branchIds, int $limit = 20): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));
        $limit = max(1, min($limit, 100));

        if ($branchIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
        $counterpartySelect = $this->columnExists('treasury_transactions', 'counterparty_name')
            ? ', tt.counterparty_name'
            : ', NULL AS counterparty_name';
        $statement = $this->db->prepare(
            "SELECT
                tt.id,
                tt.transaction_type,
                tt.transaction_date,
                tt.currency,
                tt.amount,
                tt.reference_no,
                tt.narration,
                " . ltrim($counterpartySelect, ', ') . ",
                tt.status,
                tt.voided_at,
                tt.void_reason,
                tt.reversal_journal_entry_id,
                b.name AS branch_name,
                from_ta.account_name AS from_account_name,
                from_ta.account_code AS from_account_code,
                to_ta.account_name AS to_account_name,
                to_ta.account_code AS to_account_code,
                u.username AS created_by_username,
                void_user.username AS voided_by_username
             FROM treasury_transactions tt
             INNER JOIN branches b ON b.id = tt.branch_id
             LEFT JOIN treasury_accounts from_ta ON from_ta.id = tt.from_treasury_account_id
             LEFT JOIN treasury_accounts to_ta ON to_ta.id = tt.to_treasury_account_id
             LEFT JOIN users u ON u.id = tt.created_by_user_id
             LEFT JOIN users void_user ON void_user.id = tt.voided_by_user_id
             WHERE tt.branch_id IN ({$placeholders})
             ORDER BY tt.transaction_date DESC, tt.id DESC
             LIMIT {$limit}"
        );
        $statement->execute($branchIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveDirectEntry(array $data, array $branchIds): int
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        return $this->transaction(function () use ($data, $branchIds): int {
            $branchId = (int) ($data['branch_id'] ?? 0);
            if ($branchId <= 0 || ! in_array($branchId, $branchIds, true)) {
                throw new RuntimeException('Please select an accessible branch for the direct treasury entry.');
            }

            $transactionType = trim((string) ($data['transaction_type'] ?? ''));
            if (! in_array($transactionType, ['adjustment_increase', 'adjustment_decrease'], true)) {
                throw new RuntimeException('Please select a valid direct treasury entry type.');
            }

            $transactionDate = trim((string) ($data['transaction_date'] ?? ''));
            if ($transactionDate === '') {
                throw new RuntimeException('Please select the direct treasury entry date.');
            }

            $currency = strtoupper(trim((string) ($data['currency'] ?? 'PKR')));
            if ($currency === '') {
                throw new RuntimeException('Please select the direct treasury entry currency.');
            }

            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('Please enter a direct treasury amount greater than zero.');
            }

            $treasuryAccountId = (int) ($data['treasury_account_id'] ?? 0);
            if ($treasuryAccountId <= 0) {
                throw new RuntimeException('Please select the cash or bank account for this direct treasury entry.');
            }

            $account = $this->validatedTransferAccount($treasuryAccountId, $branchId, $currency);
            $availableBalance = $this->currentBalanceForAccountId($treasuryAccountId);
            if ($transactionType === 'adjustment_decrease' && round($availableBalance + 0.005, 2) < $amount) {
                throw new RuntimeException(
                    'Selected treasury account does not have enough balance. Available: '
                    . number_format($availableBalance, 2)
                    . ' ' . $currency . '.'
                );
            }

            $counterpartyName = trim((string) ($data['counterparty_name'] ?? ''));
            $counterpartyName = $counterpartyName !== '' ? mb_substr($counterpartyName, 0, 190) : null;
            $referenceNo = trim((string) ($data['reference_no'] ?? ''));
            $referenceNo = $referenceNo !== '' ? mb_substr($referenceNo, 0, 100) : null;
            $remarks = trim((string) ($data['narration'] ?? ''));
            $remarks = $remarks !== '' ? mb_substr($remarks, 0, 500) : null;

            $directionLabel = $transactionType === 'adjustment_increase' ? 'Money In' : 'Money Out';
            $defaultNarration = $directionLabel
                . ($counterpartyName !== null ? ' - ' . $counterpartyName : '')
                . ($remarks !== null ? ' - ' . $remarks : '');

            $accounting = new AccountingRepository($this->app);
            $journalEntryId = $accounting->postDirectTreasuryEntry([
                'branch_id' => $branchId,
                'entry_date' => $transactionDate,
                'currency' => $currency,
                'amount' => $amount,
                'transaction_type' => $transactionType,
                'source_reference' => $referenceNo,
                'narration' => $defaultNarration,
                'counterparty_name' => $counterpartyName,
                'treasury_account_id' => $treasuryAccountId,
                'actor_user_id' => $data['created_by_user_id'] ?? null,
            ]);

            $hasCounterpartyColumn = $this->columnExists('treasury_transactions', 'counterparty_name');
            $columns = [
                'branch_id',
                'transaction_type',
                'transaction_date',
                'currency',
                'from_treasury_account_id',
                'to_treasury_account_id',
                'amount',
                'reference_no',
                'narration',
                'journal_entry_id',
                'status',
                'created_by_user_id',
            ];
            if ($hasCounterpartyColumn) {
                array_splice($columns, 8, 0, ['counterparty_name']);
            }

            $payload = [
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'currency' => $currency,
                'from_treasury_account_id' => $transactionType === 'adjustment_decrease' ? $treasuryAccountId : null,
                'to_treasury_account_id' => $transactionType === 'adjustment_increase' ? $treasuryAccountId : null,
                'amount' => $amount,
                'reference_no' => $referenceNo,
                'counterparty_name' => $counterpartyName,
                'narration' => $defaultNarration,
                'journal_entry_id' => $journalEntryId,
                'status' => 'posted',
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ];

            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $statement = $this->db->prepare(sprintf(
                'INSERT INTO treasury_transactions (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            ));
            $statement->execute(array_intersect_key($payload, array_flip($columns)));

            $transactionId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'treasury.direct_entry.posted', [
                'user_id' => $data['created_by_user_id'] ?? null,
                'treasury_transaction_id' => $transactionId,
                'journal_entry_id' => $journalEntryId,
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'currency' => $currency,
                'amount' => $amount,
                'treasury_account_id' => $treasuryAccountId,
                'treasury_account_name' => (string) ($account['account_name'] ?? ''),
                'counterparty_name' => $counterpartyName,
                'reference_no' => $referenceNo,
                'narration' => $defaultNarration,
            ]);

            return $transactionId;
        });
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
                linked_account_id,
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
            'debit_card', 'credit_card', 'card' => ['bank', 'card_clearing'],
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

    private function accountTypeSupportsOperationalDefault(string $accountType): bool
    {
        return in_array($accountType, ['cash', 'bank', 'wallet'], true);
    }

    private function activeSiblingAccountCount(int $branchId, string $accountType, string $currency, ?int $excludeId = null): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM treasury_accounts
             WHERE branch_id = :branch_id
               AND account_type = :account_type
               AND currency = :currency
               AND is_active = 1'
             . ($excludeId !== null ? ' AND id <> :exclude_id' : '')
        );

        $params = [
            'branch_id' => $branchId,
            'account_type' => $accountType,
            'currency' => $currency,
        ];

        if ($excludeId !== null) {
            $params['exclude_id'] = $excludeId;
        }

        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function clearSiblingDefaults(int $branchId, string $accountType, string $currency, int $keepId): void
    {
        $statement = $this->db->prepare(
            'UPDATE treasury_accounts
             SET is_default = 0
             WHERE branch_id = :branch_id
               AND account_type = :account_type
               AND currency = :currency
               AND id <> :keep_id
               AND is_default = 1'
        );
        $statement->execute([
            'branch_id' => $branchId,
            'account_type' => $accountType,
            'currency' => $currency,
            'keep_id' => $keepId,
        ]);
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

            if ($this->accountTypeSupportsOperationalDefault($accountType) && $payload['is_active'] === 1) {
                $siblingCount = $this->activeSiblingAccountCount($branchId, $accountType, $currency, $id > 0 ? $id : null);
                if ($payload['is_default'] !== 1 && $siblingCount === 0) {
                    $payload['is_default'] = 1;
                }
            }

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

                if ($this->accountTypeSupportsOperationalDefault($accountType) && $payload['is_default'] === 1) {
                    $this->clearSiblingDefaults($branchId, $accountType, $currency, $id);
                }

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
            $newId = (int) $this->db->lastInsertId();

            if ($this->accountTypeSupportsOperationalDefault($accountType) && $payload['is_default'] === 1) {
                $this->clearSiblingDefaults($branchId, $accountType, $currency, $newId);
            }

            return $newId;
        });
    }

    public function saveTransfer(array $data, array $branchIds): int
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        return $this->transaction(function () use ($data, $branchIds): int {
            $branchId = (int) ($data['branch_id'] ?? 0);
            if ($branchId <= 0 || ! in_array($branchId, $branchIds, true)) {
                throw new RuntimeException('Please select an accessible branch for the treasury transfer.');
            }

            $transactionType = trim((string) ($data['transaction_type'] ?? ''));
            $allowedTypes = ['cash_deposit_to_bank', 'bank_withdrawal_to_cash', 'bank_to_bank_transfer', 'cash_to_cash_transfer'];
            if (! in_array($transactionType, $allowedTypes, true)) {
                throw new RuntimeException('Please select a valid treasury transfer type.');
            }

            $transactionDate = trim((string) ($data['transaction_date'] ?? ''));
            if ($transactionDate === '') {
                throw new RuntimeException('Please select the treasury transfer date.');
            }

            $currency = strtoupper(trim((string) ($data['currency'] ?? 'PKR')));
            if ($currency === '') {
                throw new RuntimeException('Please select the transfer currency.');
            }

            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('Please enter a treasury transfer amount greater than zero.');
            }

            $fromAccountId = (int) ($data['from_treasury_account_id'] ?? 0);
            $toAccountId = (int) ($data['to_treasury_account_id'] ?? 0);
            if ($fromAccountId <= 0 || $toAccountId <= 0) {
                throw new RuntimeException('Please select both source and destination treasury accounts.');
            }
            if ($fromAccountId === $toAccountId) {
                throw new RuntimeException('Source and destination treasury accounts must be different.');
            }

            $fromAccount = $this->validatedTransferAccount($fromAccountId, $branchId, $currency);
            $toAccount = $this->validatedTransferAccount($toAccountId, $branchId, $currency);
            $availableSourceBalance = $this->currentBalanceForAccountId($fromAccountId);

            if (round($availableSourceBalance + 0.005, 2) < $amount) {
                throw new RuntimeException(
                    'Selected source treasury account does not have enough balance for this transfer. Available: '
                    . number_format($availableSourceBalance, 2)
                    . ' ' . $currency . '.'
                );
            }

            $fromType = (string) ($fromAccount['account_type'] ?? '');
            $toType = (string) ($toAccount['account_type'] ?? '');

            if ($transactionType === 'cash_deposit_to_bank') {
                if ($fromType !== 'cash' || $toType !== 'bank') {
                    throw new RuntimeException('Cash deposit must move from a cash account to a bank account.');
                }
            } elseif ($transactionType === 'bank_withdrawal_to_cash') {
                if ($fromType !== 'bank' || $toType !== 'cash') {
                    throw new RuntimeException('Bank withdrawal must move from a bank account to a cash account.');
                }
            } elseif ($transactionType === 'bank_to_bank_transfer') {
                if ($fromType !== 'bank' || $toType !== 'bank') {
                    throw new RuntimeException('Bank-to-bank transfer must move between two bank accounts.');
                }
            } elseif ($fromType !== 'cash' || $toType !== 'cash') {
                throw new RuntimeException('Cash-to-cash transfer must move between two cash accounts.');
            }

            $referenceNo = trim((string) ($data['reference_no'] ?? ''));
            $referenceNo = $referenceNo !== '' ? mb_substr($referenceNo, 0, 100) : null;
            $narration = trim((string) ($data['narration'] ?? ''));
            $narration = $narration !== '' ? mb_substr($narration, 0, 500) : null;

            [$defaultNarration, $sourceDescription, $destinationDescription] = match ($transactionType) {
                'cash_deposit_to_bank' => [
                    'Cash deposited into bank account',
                    'Cash deposited into bank',
                    'Bank deposit received from cash',
                ],
                'bank_withdrawal_to_cash' => [
                    'Bank cash withdrawal into treasury cash account',
                    'Bank cash withdrawal outflow',
                    'Cash received from bank withdrawal',
                ],
                'bank_to_bank_transfer' => [
                    'Bank-to-bank treasury transfer',
                    'Bank transfer out',
                    'Bank transfer in',
                ],
                default => [
                    'Cash-to-cash treasury transfer',
                    'Cash transfer out',
                    'Cash transfer in',
                ],
            };

            $accounting = new AccountingRepository($this->app);
            $journalEntryId = $accounting->postTreasuryTransfer([
                'branch_id' => $branchId,
                'entry_date' => $transactionDate,
                'currency' => $currency,
                'amount' => $amount,
                'source_reference' => $referenceNo,
                'narration' => $narration ?: $defaultNarration,
                'actor_user_id' => $data['created_by_user_id'] ?? null,
                'from_treasury_account_id' => $fromAccountId,
                'to_treasury_account_id' => $toAccountId,
                'from_line_description' => $sourceDescription . ': ' . (string) ($fromAccount['account_name'] ?? ''),
                'to_line_description' => $destinationDescription . ': ' . (string) ($toAccount['account_name'] ?? ''),
            ]);

            $statement = $this->db->prepare(
                'INSERT INTO treasury_transactions (
                    branch_id,
                    transaction_type,
                    transaction_date,
                    currency,
                    from_treasury_account_id,
                    to_treasury_account_id,
                    amount,
                    reference_no,
                    narration,
                    journal_entry_id,
                    status,
                    created_by_user_id
                ) VALUES (
                    :branch_id,
                    :transaction_type,
                    :transaction_date,
                    :currency,
                    :from_treasury_account_id,
                    :to_treasury_account_id,
                    :amount,
                    :reference_no,
                    :narration,
                    :journal_entry_id,
                    "posted",
                    :created_by_user_id
                )'
            );
            $statement->execute([
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'currency' => $currency,
                'from_treasury_account_id' => $fromAccountId,
                'to_treasury_account_id' => $toAccountId,
                'amount' => $amount,
                'reference_no' => $referenceNo,
                'narration' => $narration,
                'journal_entry_id' => $journalEntryId,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);

            $transferId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'treasury.transfer.posted', [
                'user_id' => $data['created_by_user_id'] ?? null,
                'treasury_transaction_id' => $transferId,
                'journal_entry_id' => $journalEntryId,
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'currency' => $currency,
                'amount' => $amount,
                'reference_no' => $referenceNo,
                'from_treasury_account_id' => $fromAccountId,
                'from_account_name' => (string) ($fromAccount['account_name'] ?? ''),
                'to_treasury_account_id' => $toAccountId,
                'to_account_name' => (string) ($toAccount['account_name'] ?? ''),
            ]);

            return $transferId;
        });
    }

    public function voidTransfer(int $transferId, string $voidReason, int $actorUserId, array $branchIds): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        return $this->transaction(function () use ($transferId, $voidReason, $actorUserId, $branchIds): array {
            $statement = $this->db->prepare(
                'SELECT
                    id,
                    branch_id,
                    transaction_type,
                    transaction_date,
                    currency,
                    from_treasury_account_id,
                    to_treasury_account_id,
                    amount,
                    reference_no,
                    narration,
                    journal_entry_id,
                    status,
                    reversal_journal_entry_id
                 FROM treasury_transactions
                 WHERE id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $transferId]);
            $transfer = $statement->fetch(PDO::FETCH_ASSOC);

            if ($transfer === false) {
                throw new RuntimeException('The selected treasury transaction could not be found.');
            }

            $branchId = (int) ($transfer['branch_id'] ?? 0);
            if (! in_array($branchId, $branchIds, true)) {
                throw new RuntimeException('You do not have access to reverse this treasury transaction.');
            }

            $status = str_replace(' ', '_', mb_strtolower(trim((string) ($transfer['status'] ?? ''))));
            if ($status === 'void') {
                throw new RuntimeException('This treasury transaction is already void.');
            }

            if ((int) ($transfer['reversal_journal_entry_id'] ?? 0) > 0) {
                throw new RuntimeException('This treasury transaction already has a reversal journal recorded.');
            }

            $accounting = new AccountingRepository($this->app);
            $transactionType = (string) ($transfer['transaction_type'] ?? '');
            $reversalJournalEntryId = in_array($transactionType, ['adjustment_increase', 'adjustment_decrease'], true)
                ? $accounting->postDirectTreasuryEntryVoidReversal([
                    'journal_entry_id' => (int) ($transfer['journal_entry_id'] ?? 0),
                    'branch_id' => $branchId,
                    'entry_date' => date('Y-m-d'),
                    'currency' => (string) ($transfer['currency'] ?? 'PKR'),
                    'source_reference' => 'VOID-DTE-' . $transferId,
                    'narration' => 'Direct treasury entry void reversal for transaction #' . $transferId,
                    'actor_user_id' => $actorUserId,
                ])
                : $accounting->postTreasuryTransferVoidReversal([
                    'journal_entry_id' => (int) ($transfer['journal_entry_id'] ?? 0),
                    'branch_id' => $branchId,
                    'entry_date' => date('Y-m-d'),
                    'currency' => (string) ($transfer['currency'] ?? 'PKR'),
                    'source_reference' => 'VOID-TR-' . $transferId,
                    'narration' => 'Treasury transfer void reversal for transfer #' . $transferId,
                    'actor_user_id' => $actorUserId,
                ]);

            $update = $this->db->prepare(
                'UPDATE treasury_transactions
                 SET status = "void",
                     voided_at = NOW(),
                     voided_by_user_id = :voided_by_user_id,
                     void_reason = :void_reason,
                     reversal_journal_entry_id = :reversal_journal_entry_id
                 WHERE id = :id'
            );
            $update->execute([
                'voided_by_user_id' => $actorUserId,
                'void_reason' => $voidReason,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'id' => $transferId,
            ]);

            AuditLog::record($this->app, in_array($transactionType, ['adjustment_increase', 'adjustment_decrease'], true) ? 'treasury.direct_entry.voided' : 'treasury.transfer.voided', [
                'user_id' => $actorUserId,
                'treasury_transaction_id' => $transferId,
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'currency' => (string) ($transfer['currency'] ?? 'PKR'),
                'amount' => round((float) ($transfer['amount'] ?? 0), 2),
                'reference_no' => (string) ($transfer['reference_no'] ?? ''),
                'void_reason' => $voidReason,
                'original_journal_entry_id' => (int) ($transfer['journal_entry_id'] ?? 0),
                'reversal_journal_entry_id' => $reversalJournalEntryId,
            ]);

            return [
                'id' => $transferId,
                'branch_id' => $branchId,
                'transaction_type' => (string) ($transfer['transaction_type'] ?? ''),
                'currency' => (string) ($transfer['currency'] ?? 'PKR'),
                'amount' => round((float) ($transfer['amount'] ?? 0), 2),
                'void_reason' => $voidReason,
                'reversal_journal_entry_id' => (int) $reversalJournalEntryId,
            ];
        });
    }

    private function validatedTransferAccount(int $treasuryAccountId, int $branchId, string $currency): array
    {
        $statement = $this->db->prepare(
            'SELECT
                id,
                branch_id,
                account_type,
                account_name,
                account_code,
                currency,
                is_active
             FROM treasury_accounts
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $treasuryAccountId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);

        if ($account === false) {
            throw new RuntimeException('Selected treasury account was not found.');
        }

        if ((int) ($account['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Selected treasury account is inactive.');
        }

        if ((int) ($account['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('Selected treasury account does not belong to the chosen branch.');
        }

        if (strtoupper((string) ($account['currency'] ?? '')) !== strtoupper($currency)) {
            throw new RuntimeException('Selected treasury account does not match the chosen currency.');
        }

        if (! in_array((string) ($account['account_type'] ?? ''), ['cash', 'bank'], true)) {
            throw new RuntimeException('Selected treasury account is not valid for cash or bank transfer operations.');
        }

        return $account;
    }

    public function currentBalanceForAccountId(int $treasuryAccountId): float
    {
        if ($treasuryAccountId <= 0) {
            return 0.0;
        }

        $statement = $this->db->prepare(
            'SELECT
                ROUND(
                    COALESCE(ta.opening_balance, 0)
                    + COALESCE(receipt_balance.receipt_balance, 0)
                    + COALESCE(treasury_balance.treasury_balance, 0),
                    2
                ) AS current_balance
             FROM treasury_accounts ta
             LEFT JOIN (
                SELECT
                    movement.treasury_account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS receipt_balance
                FROM (
                    SELECT
                        ta_match.id AS treasury_account_id,
                        COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0) AS signed_amount
                    FROM journal_entry_lines jel
                    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    INNER JOIN treasury_accounts ta_match
                        ON ta_match.linked_account_id = jel.account_id
                       AND ta_match.branch_id = je.branch_id
                       AND ta_match.currency = je.currency
                       AND ta_match.is_active = 1
                       AND ta_match.account_code = coa.code

                    UNION ALL

                    SELECT
                        cr.treasury_account_id AS treasury_account_id,
                        COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0) AS signed_amount
                    FROM customer_receipts cr
                    INNER JOIN journal_entry_lines jel ON jel.customer_receipt_id = cr.id
                    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    WHERE cr.treasury_account_id IS NOT NULL
                      AND coa.code IN (\'CASH_ON_HAND\', \'BANK_CLEARING\', \'CARD_CLEARING\')
                ) AS movement
                GROUP BY movement.treasury_account_id
             ) AS receipt_balance ON receipt_balance.treasury_account_id = ta.id
             LEFT JOIN (
                SELECT
                    movement.account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS treasury_balance
                FROM (
                    SELECT
                        tt.to_treasury_account_id AS account_id,
                        tt.amount AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = \'posted\'
                      AND tt.journal_entry_id IS NULL
                      AND tt.to_treasury_account_id IS NOT NULL

                    UNION ALL

                    SELECT
                        tt.from_treasury_account_id AS account_id,
                        -tt.amount AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = \'posted\'
                      AND tt.journal_entry_id IS NULL
                      AND tt.from_treasury_account_id IS NOT NULL
                ) AS movement
                GROUP BY movement.account_id
             ) AS treasury_balance ON treasury_balance.account_id = ta.id
             WHERE ta.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $treasuryAccountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return round((float) ($row['current_balance'] ?? 0), 2);
    }
}
