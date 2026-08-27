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
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['current_balance'] = $this->currentBalanceForAccountId((int) ($row['id'] ?? 0));
        }
        unset($row);

        return $rows;
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
                ta.is_active
             FROM treasury_accounts ta
             INNER JOIN branches b ON b.id = ta.branch_id
             WHERE ta.branch_id IN ({$placeholders})
               AND ta.is_active = 1
               AND ta.account_type IN ('cash', 'bank')
             ORDER BY b.name ASC, ta.account_type ASC, ta.is_default DESC, ta.account_name ASC"
        );
        $statement->execute($branchIds);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['current_balance'] = $this->currentBalanceForAccountId((int) ($row['id'] ?? 0));
        }
        unset($row);

        return $rows;
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
        $hasSourceBranchColumn = $this->columnExists('treasury_transactions', 'source_branch_id');
        $hasDestinationBranchColumn = $this->columnExists('treasury_transactions', 'destination_branch_id');
        $sourceBranchSelect = $hasSourceBranchColumn
            ? ', tt.source_branch_id, source_branch.name AS source_branch_name'
            : ', tt.branch_id AS source_branch_id, b.name AS source_branch_name';
        $destinationBranchSelect = $hasDestinationBranchColumn
            ? ', tt.destination_branch_id, destination_branch.name AS destination_branch_name'
            : ', tt.branch_id AS destination_branch_id, b.name AS destination_branch_name';
        $sourceCurrencySelect = $this->columnExists('treasury_transactions', 'source_currency')
            ? ', tt.source_currency'
            : ', tt.currency AS source_currency';
        $destinationCurrencySelect = $this->columnExists('treasury_transactions', 'destination_currency')
            ? ', tt.destination_currency'
            : ', tt.currency AS destination_currency';
        $sourceAmountSelect = $this->columnExists('treasury_transactions', 'source_amount')
            ? ', tt.source_amount'
            : ', tt.amount AS source_amount';
        $destinationAmountSelect = $this->columnExists('treasury_transactions', 'destination_amount')
            ? ', tt.destination_amount'
            : ', tt.amount AS destination_amount';
        $exchangeRateSelect = $this->columnExists('treasury_transactions', 'exchange_rate')
            ? ', tt.exchange_rate'
            : ', NULL AS exchange_rate';
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
                " . ltrim($sourceBranchSelect, ', ') . ",
                " . ltrim($destinationBranchSelect, ', ') . ",
                " . ltrim($sourceCurrencySelect, ', ') . ",
                " . ltrim($destinationCurrencySelect, ', ') . ",
                " . ltrim($sourceAmountSelect, ', ') . ",
                " . ltrim($destinationAmountSelect, ', ') . ",
                " . ltrim($exchangeRateSelect, ', ') . ",
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
             " . ($hasSourceBranchColumn ? 'LEFT JOIN branches source_branch ON source_branch.id = tt.source_branch_id' : '') . "
             " . ($hasDestinationBranchColumn ? 'LEFT JOIN branches destination_branch ON destination_branch.id = tt.destination_branch_id' : '') . "
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

    public function findTransactionForCorrection(int $transactionId, array $branchIds): ?array
    {
        $branchIds = array_values(array_map('intval', $branchIds));
        if ($transactionId <= 0 || $branchIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
        $hasCounterpartyColumn = $this->columnExists('treasury_transactions', 'counterparty_name');
        $hasBusinessSourceColumn = $this->columnExists('treasury_transactions', 'business_source_id');
        $hasSourceBranchColumn = $this->columnExists('treasury_transactions', 'source_branch_id');
        $hasDestinationBranchColumn = $this->columnExists('treasury_transactions', 'destination_branch_id');
        $hasSourceCurrencyColumn = $this->columnExists('treasury_transactions', 'source_currency');
        $hasDestinationCurrencyColumn = $this->columnExists('treasury_transactions', 'destination_currency');
        $hasSourceAmountColumn = $this->columnExists('treasury_transactions', 'source_amount');
        $hasDestinationAmountColumn = $this->columnExists('treasury_transactions', 'destination_amount');
        $hasExchangeRateColumn = $this->columnExists('treasury_transactions', 'exchange_rate');
        $hasRateDateColumn = $this->columnExists('treasury_transactions', 'exchange_rate_effective_date');

        $params = array_merge([$transactionId], $branchIds, $branchIds, $branchIds);
        $statement = $this->db->prepare(
            'SELECT
                tt.id,
                tt.branch_id,
                tt.transaction_type,
                tt.transaction_date,
                tt.currency,
                tt.from_treasury_account_id,
                tt.to_treasury_account_id,
                tt.amount,
                tt.reference_no,
                tt.narration,
                tt.status,
                tt.journal_entry_id,
                ' . ($hasCounterpartyColumn ? 'tt.counterparty_name' : 'NULL') . ' AS counterparty_name,
                ' . ($hasBusinessSourceColumn ? 'tt.business_source_id' : 'NULL') . ' AS business_source_id,
                ' . ($hasSourceBranchColumn ? 'COALESCE(tt.source_branch_id, tt.branch_id)' : 'tt.branch_id') . ' AS source_branch_id,
                ' . ($hasDestinationBranchColumn ? 'COALESCE(tt.destination_branch_id, tt.branch_id)' : 'tt.branch_id') . ' AS destination_branch_id,
                ' . ($hasSourceCurrencyColumn ? 'COALESCE(tt.source_currency, tt.currency)' : 'tt.currency') . ' AS source_currency,
                ' . ($hasDestinationCurrencyColumn ? 'COALESCE(tt.destination_currency, tt.currency)' : 'tt.currency') . ' AS destination_currency,
                ' . ($hasSourceAmountColumn ? 'COALESCE(tt.source_amount, tt.amount)' : 'tt.amount') . ' AS source_amount,
                ' . ($hasDestinationAmountColumn ? 'COALESCE(tt.destination_amount, tt.amount)' : 'tt.amount') . ' AS destination_amount,
                ' . ($hasExchangeRateColumn ? 'tt.exchange_rate' : 'NULL') . ' AS exchange_rate,
                ' . ($hasRateDateColumn ? 'tt.exchange_rate_effective_date' : 'NULL') . ' AS exchange_rate_effective_date,
                from_ta.account_name AS from_account_name,
                to_ta.account_name AS to_account_name,
                b.name AS branch_name
             FROM treasury_transactions tt
             INNER JOIN branches b ON b.id = tt.branch_id
             LEFT JOIN treasury_accounts from_ta ON from_ta.id = tt.from_treasury_account_id
             LEFT JOIN treasury_accounts to_ta ON to_ta.id = tt.to_treasury_account_id
             WHERE tt.id = ?
               AND (
                    tt.branch_id IN (' . $placeholders . ')
                    OR ' . ($hasSourceBranchColumn ? 'COALESCE(tt.source_branch_id, tt.branch_id)' : 'tt.branch_id') . ' IN (' . $placeholders . ')
                    OR ' . ($hasDestinationBranchColumn ? 'COALESCE(tt.destination_branch_id, tt.branch_id)' : 'tt.branch_id') . ' IN (' . $placeholders . ')
               )
             LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
            $businessSourceId = max(0, (int) ($data['business_source_id'] ?? 0));
            $businessSource = null;
            if ($businessSourceId > 0) {
                $businessSource = (new MasterDataRepository($this->app))->find('business_sources', $businessSourceId);
                if ($businessSource === null || (int) ($businessSource['is_active'] ?? 1) !== 1) {
                    throw new RuntimeException('Please select a valid active account holder.');
                }
            }
            $referenceNo = trim((string) ($data['reference_no'] ?? ''));
            $referenceNo = $referenceNo !== '' ? mb_substr($referenceNo, 0, 100) : null;
            $remarks = trim((string) ($data['narration'] ?? ''));
            $remarks = $remarks !== '' ? mb_substr($remarks, 0, 500) : null;

            $directionLabel = $transactionType === 'adjustment_increase' ? 'Money In' : 'Money Out';
            $defaultNarration = $directionLabel
                . ($businessSource !== null ? ' / ' . trim((string) ($businessSource['name'] ?? 'Account Holder')) : '')
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
                'business_source_id' => $businessSourceId > 0 ? $businessSourceId : null,
                'treasury_account_id' => $treasuryAccountId,
                'actor_user_id' => $data['created_by_user_id'] ?? null,
            ]);

            $hasCounterpartyColumn = $this->columnExists('treasury_transactions', 'counterparty_name');
            $hasBusinessSourceColumn = $this->columnExists('treasury_transactions', 'business_source_id');
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
            if ($hasBusinessSourceColumn) {
                array_splice($columns, 1, 0, ['business_source_id']);
            }
            if ($hasCounterpartyColumn) {
                array_splice($columns, 8, 0, ['counterparty_name']);
            }

            $payload = [
                'branch_id' => $branchId,
                'business_source_id' => $businessSourceId > 0 ? $businessSourceId : null,
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
                'business_source_id' => $businessSourceId,
                'business_source_name' => $businessSource !== null ? (string) ($businessSource['name'] ?? '') : null,
                'treasury_account_name' => (string) ($account['account_name'] ?? ''),
                'counterparty_name' => $counterpartyName,
                'reference_no' => $referenceNo,
                'narration' => $defaultNarration,
            ]);

            return $transactionId;
        });
    }

    public function correctDirectEntry(array $data, int $actorUserId, array $branchIds): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        return $this->transaction(function () use ($data, $actorUserId, $branchIds): array {
            $transactionId = (int) ($data['correction_transaction_id'] ?? 0);
            if ($transactionId <= 0) {
                throw new RuntimeException('Please select a treasury entry to correct.');
            }

            $original = $this->findTransactionForCorrection($transactionId, $branchIds);
            if ($original === null) {
                throw new RuntimeException('The selected treasury entry could not be found for correction.');
            }

            $transactionType = (string) ($original['transaction_type'] ?? '');
            if (! in_array($transactionType, ['adjustment_increase', 'adjustment_decrease'], true)) {
                throw new RuntimeException('Only direct treasury entries can be corrected from this form.');
            }

            $status = str_replace(' ', '_', mb_strtolower(trim((string) ($original['status'] ?? 'posted'))));
            if ($status === 'void') {
                throw new RuntimeException('Voided treasury entries cannot be corrected.');
            }

            $correctionReason = trim((string) ($data['correction_reason'] ?? ''));
            if ($correctionReason === '') {
                throw new RuntimeException('Please enter the correction reason before saving the treasury entry edit.');
            }

            $voidResult = $this->voidTransfer(
                $transactionId,
                'Correction: ' . mb_substr($correctionReason, 0, 500),
                $actorUserId,
                $branchIds
            );
            $newTransactionId = $this->saveDirectEntry($data, $branchIds);

            AuditLog::record($this->app, 'treasury.direct_entry.corrected', [
                'user_id' => $actorUserId,
                'original_treasury_transaction_id' => $transactionId,
                'corrected_treasury_transaction_id' => $newTransactionId,
                'void_reversal_journal_entry_id' => (int) ($voidResult['reversal_journal_entry_id'] ?? 0),
                'correction_reason' => $correctionReason,
            ]);

            return [
                'original_treasury_transaction_id' => $transactionId,
                'corrected_treasury_transaction_id' => $newTransactionId,
            ];
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

    public function validatePaymentTreasuryAccount(
        int $treasuryAccountId,
        int $branchId,
        string $currency,
        string $paymentMethod,
        bool $allowBankSourceForCash = false,
        bool $allowDifferentCurrency = false,
        bool $allowDifferentBranch = true,
        bool $allowCashBankInterchange = false
    ): array
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

        if (! $allowDifferentBranch && (int) ($account['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('Selected cash or bank account does not belong to this branch.');
        }

        if (! $allowDifferentCurrency
            && strtoupper((string) ($account['currency'] ?? '')) !== strtoupper(trim($currency))) {
            throw new RuntimeException('Selected cash or bank account does not match the payment currency.');
        }

        $compatibleTypes = $this->compatibleTreasuryTypesForPaymentMethod($paymentMethod);
        if ($allowCashBankInterchange && in_array(str_replace(' ', '_', mb_strtolower(trim($paymentMethod))), ['cash', 'bank_transfer'], true)) {
            $compatibleTypes = array_values(array_unique(array_merge($compatibleTypes, ['cash', 'bank'])));
        }
        if ($allowBankSourceForCash && str_replace(' ', '_', mb_strtolower(trim($paymentMethod))) === 'cash') {
            $compatibleTypes[] = 'bank';
            $compatibleTypes = array_values(array_unique($compatibleTypes));
        }
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

    private function ensureOperationalLedgerAccountId(string $accountCode, string $accountName, ?int $fallbackAccountId = null): ?int
    {
        $accountCode = strtoupper(trim($accountCode));
        if ($accountCode === '') {
            return $fallbackAccountId;
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM chart_of_accounts
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => $accountCode]);
        $existingId = (int) ($statement->fetchColumn() ?: 0);

        if ($existingId > 0) {
            return $existingId;
        }

        $insert = $this->db->prepare(
            'INSERT INTO chart_of_accounts (
                code, name, purpose, account_type, normal_balance, is_system, is_active
             ) VALUES (
                :code, :name, :purpose, "asset", "debit", 0, 1
             )'
        );
        $insert->execute([
            'code' => $accountCode,
            'name' => $accountName !== '' ? $accountName : $accountCode,
            'purpose' => 'Operational treasury account',
        ]);

        return (int) $this->db->lastInsertId();
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
                $payload['linked_account_id'] = $this->ensureOperationalLedgerAccountId(
                    $payload['account_code'],
                    $accountName,
                    $linkedAccountId
                );

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
            $payload['linked_account_id'] = $this->ensureOperationalLedgerAccountId(
                $payload['account_code'],
                $accountName,
                $linkedAccountId
            );

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
            $transactionType = trim((string) ($data['transaction_type'] ?? ''));
            $allowedTypes = ['cash_deposit_to_bank', 'bank_withdrawal_to_cash', 'bank_to_bank_transfer', 'cash_to_cash_transfer'];
            if (! in_array($transactionType, $allowedTypes, true)) {
                throw new RuntimeException('Please select a valid treasury transfer type.');
            }

            $transactionDate = trim((string) ($data['transaction_date'] ?? ''));
            if ($transactionDate === '') {
                throw new RuntimeException('Please select the treasury transfer date.');
            }

            $fromAccountId = (int) ($data['from_treasury_account_id'] ?? 0);
            $toAccountId = (int) ($data['to_treasury_account_id'] ?? 0);
            if ($fromAccountId <= 0 || $toAccountId <= 0) {
                throw new RuntimeException('Please select both source and destination treasury accounts.');
            }
            if ($fromAccountId === $toAccountId) {
                throw new RuntimeException('Source and destination treasury accounts must be different.');
            }

            $fromAccount = $this->validatedTransferAccount($fromAccountId);
            $toAccount = $this->validatedTransferAccount($toAccountId);
            $branchId = (int) ($fromAccount['branch_id'] ?? 0);
            if ($branchId <= 0 || ! in_array($branchId, $branchIds, true)) {
                throw new RuntimeException('Please select an accessible source branch for the treasury transfer.');
            }
            $destinationBranchId = (int) ($toAccount['branch_id'] ?? 0);
            if ($destinationBranchId <= 0 || ! in_array($destinationBranchId, $branchIds, true)) {
                throw new RuntimeException('Please select an accessible destination branch for the treasury transfer.');
            }

            $sourceCurrency = strtoupper(trim((string) ($data['source_currency'] ?? $data['currency'] ?? $fromAccount['currency'] ?? 'PKR')));
            $destinationCurrency = strtoupper(trim((string) ($data['destination_currency'] ?? $data['currency'] ?? $toAccount['currency'] ?? $sourceCurrency)));
            if ($sourceCurrency === '' || $destinationCurrency === '') {
                throw new RuntimeException('Please select the source and destination currencies.');
            }

            if (strtoupper((string) ($fromAccount['currency'] ?? '')) !== $sourceCurrency) {
                throw new RuntimeException('Selected source treasury account does not match the chosen source currency.');
            }
            if (strtoupper((string) ($toAccount['currency'] ?? '')) !== $destinationCurrency) {
                throw new RuntimeException('Selected destination treasury account does not match the chosen destination currency.');
            }

            $sourceAmount = round((float) ($data['source_amount'] ?? $data['amount'] ?? 0), 2);
            $destinationAmount = round((float) ($data['destination_amount'] ?? $data['amount_received'] ?? $data['amount'] ?? 0), 2);
            if ($sourceAmount <= 0 || $destinationAmount <= 0) {
                throw new RuntimeException('Please enter both sent and received transfer amounts greater than zero.');
            }

            $hasSourceBranchColumn = $this->columnExists('treasury_transactions', 'source_branch_id');
            $hasDestinationBranchColumn = $this->columnExists('treasury_transactions', 'destination_branch_id');
            $hasSourceCurrencyColumn = $this->columnExists('treasury_transactions', 'source_currency');
            $hasDestinationCurrencyColumn = $this->columnExists('treasury_transactions', 'destination_currency');
            $hasSourceAmountColumn = $this->columnExists('treasury_transactions', 'source_amount');
            $hasDestinationAmountColumn = $this->columnExists('treasury_transactions', 'destination_amount');
            $hasExchangeRateColumn = $this->columnExists('treasury_transactions', 'exchange_rate');
            $hasExchangeRateDateColumn = $this->columnExists('treasury_transactions', 'exchange_rate_effective_date');
            $hasDestinationJournalColumn = $this->columnExists('treasury_transactions', 'destination_journal_entry_id');

            $exchangeRate = round((float) ($data['exchange_rate'] ?? 0), 8);
            if ($sourceCurrency === $destinationCurrency) {
                $exchangeRate = 1.0;
                $destinationAmount = $sourceAmount;
            } elseif (
                ! $hasSourceBranchColumn
                || ! $hasDestinationBranchColumn
                || ! $hasSourceCurrencyColumn
                || ! $hasDestinationCurrencyColumn
                || ! $hasSourceAmountColumn
                || ! $hasDestinationAmountColumn
                || ! $hasExchangeRateColumn
                || ! $hasExchangeRateDateColumn
            ) {
                throw new RuntimeException(
                    'Cross-currency treasury transfer is not available until the treasury migration is applied. '
                    . 'Run migration 20260825_000076_treasury_cross_branch_cross_currency and save again.'
                );
            } elseif ($exchangeRate <= 0) {
                throw new RuntimeException('Please enter the transfer exchange rate for cross-currency treasury movement.');
            }

            $availableSourceBalance = $this->currentBalanceForAccountId($fromAccountId);
            if (round($availableSourceBalance + 0.005, 2) < $sourceAmount) {
                throw new RuntimeException(
                    'Selected source treasury account does not have enough balance for this transfer. Available: '
                    . number_format($availableSourceBalance, 2)
                    . ' ' . $sourceCurrency . '.'
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

            $isSingleCurrencySameBranch = $branchId === $destinationBranchId && $sourceCurrency === $destinationCurrency;
            $journalEntryId = null;
            if ($isSingleCurrencySameBranch) {
                $accounting = new AccountingRepository($this->app);
                $journalEntryId = $accounting->postTreasuryTransfer([
                    'branch_id' => $branchId,
                    'entry_date' => $transactionDate,
                    'currency' => $sourceCurrency,
                    'amount' => $sourceAmount,
                    'source_reference' => $referenceNo,
                    'narration' => $narration ?: $defaultNarration,
                    'actor_user_id' => $data['created_by_user_id'] ?? null,
                    'from_treasury_account_id' => $fromAccountId,
                    'to_treasury_account_id' => $toAccountId,
                    'from_line_description' => $sourceDescription . ': ' . (string) ($fromAccount['account_name'] ?? ''),
                    'to_line_description' => $destinationDescription . ': ' . (string) ($toAccount['account_name'] ?? ''),
                ]);
            }

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
            if ($hasSourceBranchColumn) {
                $columns[] = 'source_branch_id';
            }
            if ($hasDestinationBranchColumn) {
                $columns[] = 'destination_branch_id';
            }
            if ($hasSourceCurrencyColumn) {
                $columns[] = 'source_currency';
            }
            if ($hasDestinationCurrencyColumn) {
                $columns[] = 'destination_currency';
            }
            if ($hasSourceAmountColumn) {
                $columns[] = 'source_amount';
            }
            if ($hasDestinationAmountColumn) {
                $columns[] = 'destination_amount';
            }
            if ($hasExchangeRateColumn) {
                $columns[] = 'exchange_rate';
            }
            if ($hasExchangeRateDateColumn) {
                $columns[] = 'exchange_rate_effective_date';
            }
            if ($hasDestinationJournalColumn) {
                $columns[] = 'destination_journal_entry_id';
            }

            $payload = [
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'currency' => $sourceCurrency,
                'from_treasury_account_id' => $fromAccountId,
                'to_treasury_account_id' => $toAccountId,
                'amount' => $sourceAmount,
                'reference_no' => $referenceNo,
                'narration' => $narration,
                'journal_entry_id' => $journalEntryId,
                'status' => 'posted',
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'source_branch_id' => $branchId,
                'destination_branch_id' => $destinationBranchId,
                'source_currency' => $sourceCurrency,
                'destination_currency' => $destinationCurrency,
                'source_amount' => $sourceAmount,
                'destination_amount' => $destinationAmount,
                'exchange_rate' => $exchangeRate > 0 ? $exchangeRate : null,
                'exchange_rate_effective_date' => trim((string) ($data['exchange_rate_effective_date'] ?? '')) ?: null,
                'destination_journal_entry_id' => null,
            ];

            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $statement = $this->db->prepare(sprintf(
                'INSERT INTO treasury_transactions (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            ));
            $statement->execute(array_intersect_key($payload, array_flip($columns)));

            $transferId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'treasury.transfer.posted', [
                'user_id' => $data['created_by_user_id'] ?? null,
                'treasury_transaction_id' => $transferId,
                'journal_entry_id' => $journalEntryId,
                'branch_id' => $branchId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'currency' => $sourceCurrency,
                'amount' => $sourceAmount,
                'source_branch_id' => $branchId,
                'destination_branch_id' => $destinationBranchId,
                'source_currency' => $sourceCurrency,
                'destination_currency' => $destinationCurrency,
                'source_amount' => $sourceAmount,
                'destination_amount' => $destinationAmount,
                'exchange_rate' => $exchangeRate > 0 ? $exchangeRate : null,
                'reference_no' => $referenceNo,
                'from_treasury_account_id' => $fromAccountId,
                'from_account_name' => (string) ($fromAccount['account_name'] ?? ''),
                'to_treasury_account_id' => $toAccountId,
                'to_account_name' => (string) ($toAccount['account_name'] ?? ''),
            ]);

            return $transferId;
        });
    }

    public function correctTransfer(array $data, int $actorUserId, array $branchIds): array
    {
        $branchIds = array_values(array_map('intval', $branchIds));

        return $this->transaction(function () use ($data, $actorUserId, $branchIds): array {
            $transactionId = (int) ($data['correction_transaction_id'] ?? 0);
            if ($transactionId <= 0) {
                throw new RuntimeException('Please select a treasury transfer to correct.');
            }

            $original = $this->findTransactionForCorrection($transactionId, $branchIds);
            if ($original === null) {
                throw new RuntimeException('The selected treasury transfer could not be found for correction.');
            }

            $transactionType = (string) ($original['transaction_type'] ?? '');
            if (! in_array($transactionType, ['cash_deposit_to_bank', 'bank_withdrawal_to_cash', 'bank_to_bank_transfer', 'cash_to_cash_transfer'], true)) {
                throw new RuntimeException('Only internal treasury transfers can be corrected from this form.');
            }

            $status = str_replace(' ', '_', mb_strtolower(trim((string) ($original['status'] ?? 'posted'))));
            if ($status === 'void') {
                throw new RuntimeException('Voided treasury transfers cannot be corrected.');
            }

            $correctionReason = trim((string) ($data['correction_reason'] ?? ''));
            if ($correctionReason === '') {
                throw new RuntimeException('Please enter the correction reason before saving the treasury transfer edit.');
            }

            $voidResult = $this->voidTransfer(
                $transactionId,
                'Correction: ' . mb_substr($correctionReason, 0, 500),
                $actorUserId,
                $branchIds
            );
            $newTransactionId = $this->saveTransfer($data, $branchIds);

            AuditLog::record($this->app, 'treasury.transfer.corrected', [
                'user_id' => $actorUserId,
                'original_treasury_transaction_id' => $transactionId,
                'corrected_treasury_transaction_id' => $newTransactionId,
                'void_reversal_journal_entry_id' => (int) ($voidResult['reversal_journal_entry_id'] ?? 0),
                'correction_reason' => $correctionReason,
            ]);

            return [
                'original_treasury_transaction_id' => $transactionId,
                'corrected_treasury_transaction_id' => $newTransactionId,
            ];
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

    private function validatedTransferAccount(int $treasuryAccountId, ?int $branchId = null, string $currency = ''): array
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

        if ($branchId !== null && (int) ($account['branch_id'] ?? 0) !== $branchId) {
            throw new RuntimeException('Selected treasury account does not belong to the chosen branch.');
        }

        if ($currency !== '' && strtoupper((string) ($account['currency'] ?? '')) !== strtoupper($currency)) {
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

        $hasSourceAmountColumn = $this->columnExists('treasury_transactions', 'source_amount');
        $hasDestinationAmountColumn = $this->columnExists('treasury_transactions', 'destination_amount');

        $statement = $this->db->prepare(
            'SELECT
                ROUND(
                    COALESCE(ta.opening_balance, 0)
                    + COALESCE(journal_balance.journal_balance, 0)
                    + COALESCE(treasury_balance.treasury_balance, 0),
                    2
                ) AS current_balance
             FROM treasury_accounts ta
             LEFT JOIN (
                SELECT
                    ta_match.id AS treasury_account_id,
                    ROUND(SUM(COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0)), 2) AS journal_balance
                 FROM treasury_accounts ta_match
                 INNER JOIN chart_of_accounts coa ON coa.code = ta_match.account_code
                 INNER JOIN journal_entry_lines jel ON jel.account_id = coa.id
                 INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                 WHERE ta_match.account_code IS NOT NULL
                   AND ta_match.account_code <> \'\'
                   AND je.branch_id = ta_match.branch_id
                   AND je.currency = ta_match.currency
                 GROUP BY ta_match.id
             ) AS journal_balance ON journal_balance.treasury_account_id = ta.id
             LEFT JOIN (
                SELECT
                    movement.account_id,
                    ROUND(SUM(movement.signed_amount), 2) AS treasury_balance
                FROM (
                    SELECT
                        tt.to_treasury_account_id AS account_id,
                        ' . ($hasDestinationAmountColumn ? 'COALESCE(tt.destination_amount, tt.amount)' : 'tt.amount') . ' AS signed_amount
                    FROM treasury_transactions tt
                    WHERE tt.status = \'posted\'
                      AND tt.journal_entry_id IS NULL
                      AND tt.to_treasury_account_id IS NOT NULL

                    UNION ALL

                    SELECT
                        tt.from_treasury_account_id AS account_id,
                        -' . ($hasSourceAmountColumn ? 'COALESCE(tt.source_amount, tt.amount)' : 'tt.amount') . ' AS signed_amount
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
