<?php

declare(strict_types=1);

namespace App\Repositories;

final class ReportRepository extends BaseRepository
{
    private function salaryExpenseTablesExist(): bool
    {
        return $this->tableExists('employee_salary_records');
    }

    private function nonVoidedSalaryStatusClause(string $alias = 'r'): string
    {
        return 'LOWER(REPLACE(COALESCE(' . $alias . '.status, "open"), " ", "_")) <> "void"';
    }

    public function cashBankPosition(array $branchIds, string $asOfDate, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $params['as_of_date'] = $asOfDate;
        $currencyClause = '';

        if (trim($currency) !== '') {
            $currencyClause = ' AND je.currency = :currency';
            $params['currency'] = strtoupper(trim($currency));
        }

        $rows = $this->fetchRows(
            'SELECT
                je.branch_id,
                br.name AS branch_name,
                je.currency,
                CASE
                    WHEN ta_direct.id IS NOT NULL THEN ta_direct.account_code
                    WHEN ta_receipt.id IS NOT NULL THEN ta_receipt.account_code
                    ELSE coa.code
                END AS account_code,
                CASE
                    WHEN ta_direct.id IS NOT NULL THEN ta_direct.account_name
                    WHEN ta_receipt.id IS NOT NULL THEN ta_receipt.account_name
                    ELSE coa.name
                END AS account_name,
                CASE
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "cash" THEN "Cash Counter"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "bank" THEN "Bank Account"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "wallet" THEN "Wallet / Mobile"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "bank_clearing" THEN "Bank / Clearing"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "card_clearing" THEN "Card / Clearing"
                    WHEN coa.code = "CASH_ON_HAND" THEN "Cash"
                    WHEN coa.code = "BANK_CLEARING" THEN "Bank / Clearing"
                    WHEN coa.code = "CARD_CLEARING" THEN "Card / Clearing"
                    ELSE "Other"
                END AS account_group,
                ROUND(SUM(COALESCE(jel.debit_amount, 0)), 2) AS total_debit,
                ROUND(SUM(COALESCE(jel.credit_amount, 0)), 2) AS total_credit,
                ROUND(SUM(COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0)), 2) AS balance
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             INNER JOIN branches br ON br.id = je.branch_id
             LEFT JOIN customer_receipts cr ON cr.id = jel.customer_receipt_id
             LEFT JOIN treasury_accounts ta_receipt ON ta_receipt.id = cr.treasury_account_id
             LEFT JOIN treasury_accounts ta_direct
                ON ta_direct.linked_account_id = jel.account_id
               AND ta_direct.branch_id = je.branch_id
               AND ta_direct.currency = je.currency
               AND ta_direct.is_active = 1
               AND ta_direct.account_code = coa.code
             WHERE je.branch_id ' . $clause . '
               AND je.entry_date <= :as_of_date
               AND (
                    ta_direct.id IS NOT NULL
                    OR (
                        cr.treasury_account_id IS NOT NULL
                        AND ta_receipt.id IS NOT NULL
                        AND coa.code IN ("CASH_ON_HAND", "BANK_CLEARING", "CARD_CLEARING")
                    )
                    OR (
                        coa.code IN ("CASH_ON_HAND", "BANK_CLEARING", "CARD_CLEARING")
                        AND ta_direct.id IS NULL
                        AND cr.treasury_account_id IS NULL
                    )
               )' . $currencyClause . '
             GROUP BY
                je.branch_id,
                br.name,
                je.currency,
                CASE
                    WHEN ta_direct.id IS NOT NULL THEN ta_direct.account_code
                    WHEN ta_receipt.id IS NOT NULL THEN ta_receipt.account_code
                    ELSE coa.code
                END,
                CASE
                    WHEN ta_direct.id IS NOT NULL THEN ta_direct.account_name
                    WHEN ta_receipt.id IS NOT NULL THEN ta_receipt.account_name
                    ELSE coa.name
                END,
                CASE
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "cash" THEN "Cash Counter"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "bank" THEN "Bank Account"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "wallet" THEN "Wallet / Mobile"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "bank_clearing" THEN "Bank / Clearing"
                    WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "card_clearing" THEN "Card / Clearing"
                    WHEN coa.code = "CASH_ON_HAND" THEN "Cash"
                    WHEN coa.code = "BANK_CLEARING" THEN "Bank / Clearing"
                    WHEN coa.code = "CARD_CLEARING" THEN "Card / Clearing"
                    ELSE "Other"
                END
             ORDER BY
                br.name ASC,
                je.currency ASC,
                FIELD(
                    CASE
                        WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "cash" THEN "cash"
                        WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "bank" THEN "bank"
                        WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "wallet" THEN "wallet"
                        WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "bank_clearing" THEN "bank_clearing"
                        WHEN COALESCE(ta_direct.account_type, ta_receipt.account_type) = "card_clearing" THEN "card_clearing"
                        ELSE coa.code
                    END,
                    "cash",
                    "CASH_ON_HAND",
                    "bank",
                    "wallet",
                    "BANK_CLEARING",
                    "bank_clearing",
                    "CARD_CLEARING",
                    "card_clearing"
                ),
                account_name ASC',
            $params
        );

        [$accountClause, $accountParams] = $this->branchScope($branchIds, 'treasury_branch_');
        $accountCurrencyClause = '';
        if (trim($currency) !== '') {
            $accountCurrencyClause = ' AND ta.currency = :treasury_currency';
            $accountParams['treasury_currency'] = strtoupper(trim($currency));
        }

        $treasuryAccounts = $this->fetchRows(
            'SELECT
                ta.branch_id,
                br.name AS branch_name,
                ta.currency,
                ta.account_code,
                ta.account_name,
                CASE
                    WHEN ta.account_type = "cash" THEN "Cash Counter"
                    WHEN ta.account_type = "bank" THEN "Bank Account"
                    WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                    WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                    WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                    ELSE "Other"
                END AS account_group,
                COALESCE(ta.opening_balance, 0) AS opening_balance
             FROM treasury_accounts ta
             INNER JOIN branches br ON br.id = ta.branch_id
             WHERE ta.is_active = 1
               AND ta.branch_id ' . $accountClause . $accountCurrencyClause,
            $accountParams
        );

        $rowIndexes = [];
        foreach ($rows as $index => $row) {
            $rowIndexes[implode('|', [
                (int) ($row['branch_id'] ?? 0),
                strtoupper((string) ($row['currency'] ?? '')),
                strtoupper((string) ($row['account_code'] ?? '')),
            ])] = $index;
        }

        foreach ($treasuryAccounts as $account) {
            $openingBalance = round((float) ($account['opening_balance'] ?? 0), 2);
            $key = implode('|', [
                (int) ($account['branch_id'] ?? 0),
                strtoupper((string) ($account['currency'] ?? '')),
                strtoupper((string) ($account['account_code'] ?? '')),
            ]);

            if (isset($rowIndexes[$key])) {
                $rowIndex = $rowIndexes[$key];
                $rows[$rowIndex]['total_debit'] = round((float) ($rows[$rowIndex]['total_debit'] ?? 0) + max($openingBalance, 0), 2);
                $rows[$rowIndex]['total_credit'] = round((float) ($rows[$rowIndex]['total_credit'] ?? 0) + max(-$openingBalance, 0), 2);
                $rows[$rowIndex]['balance'] = round((float) ($rows[$rowIndex]['balance'] ?? 0) + $openingBalance, 2);
                continue;
            }

            $rows[] = [
                'branch_id' => (int) ($account['branch_id'] ?? 0),
                'branch_name' => (string) ($account['branch_name'] ?? ''),
                'currency' => (string) ($account['currency'] ?? ''),
                'account_code' => (string) ($account['account_code'] ?? ''),
                'account_name' => (string) ($account['account_name'] ?? ''),
                'account_group' => (string) ($account['account_group'] ?? 'Other'),
                'total_debit' => round(max($openingBalance, 0), 2),
                'total_credit' => round(max(-$openingBalance, 0), 2),
                'balance' => $openingBalance,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return [
                (string) ($left['branch_name'] ?? ''),
                (string) ($left['currency'] ?? ''),
                (string) ($left['account_name'] ?? ''),
            ] <=> [
                (string) ($right['branch_name'] ?? ''),
                (string) ($right['currency'] ?? ''),
                (string) ($right['account_name'] ?? ''),
            ];
        });

        return $rows;
    }

    public function directTreasuryAccountOptions(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($branchIds) as $index => $branchId) {
            $placeholder = ':direct_account_branch_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int) $branchId;
        }

        $sql = "
            SELECT
                ta.id,
                ta.account_name,
                ta.currency,
                COALESCE(b.name, 'N/A') AS branch_name
            FROM treasury_accounts ta
            INNER JOIN branches b ON b.id = ta.branch_id
            WHERE ta.branch_id IN (" . implode(', ', $placeholders) . ")
              AND ta.is_active = 1
              AND ta.account_type IN ('cash', 'bank')
            ORDER BY b.name, ta.currency, ta.account_name
        ";

        $statement = $this->db->prepare($sql);
        foreach ($params as $placeholder => $branchId) {
            $statement->bindValue($placeholder, $branchId, \PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function directMoneyInOutLedger(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $treasuryAccountId = null,
        string $direction = 'all',
        string $sourceType = 'all'
    ): array {
        if ($branchIds === []) {
            return [];
        }

        $branchPlaceholders = [];
        $params = [];

        foreach (array_values($branchIds) as $index => $branchId) {
            $placeholder = ':direct_branch_' . $index;
            $branchPlaceholders[] = $placeholder;
            $params[$placeholder] = (int) $branchId;
        }

        $where = [
            'direct_rows.branch_id IN (' . implode(', ', $branchPlaceholders) . ')',
        ];

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'direct_rows.entry_date >= :direct_date_from';
            $params[':direct_date_from'] = $dateFrom;
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'direct_rows.entry_date <= :direct_date_to';
            $params[':direct_date_to'] = $dateTo;
        }

        if ($treasuryAccountId !== null && $treasuryAccountId > 0) {
            $where[] = 'direct_rows.treasury_account_id = :direct_treasury_account_id';
            $params[':direct_treasury_account_id'] = $treasuryAccountId;
        }

        if ($direction === 'in') {
            $where[] = 'direct_rows.debit_amount > 0';
        } elseif ($direction === 'out') {
            $where[] = 'direct_rows.credit_amount > 0';
        }

        if ($sourceType === 'posted') {
            $where[] = "direct_rows.source_group = 'posted'";
        } elseif ($sourceType === 'reversal') {
            $where[] = "direct_rows.source_group = 'reversal'";
        }

        $counterpartyExpression = $this->columnExists('treasury_transactions', 'counterparty_name')
            ? "NULLIF(TRIM(tt.counterparty_name), '')"
            : 'NULL';

        $sql = "
            SELECT direct_rows.*
            FROM (
                SELECT
                    tt.id AS treasury_transaction_id,
                    ta.id AS treasury_account_id,
                    tt.branch_id,
                    COALESCE(b.name, 'N/A') AS branch_name,
                    DATE(je.entry_date) AS entry_date,
                    tt.currency,
                    COALESCE(ta.account_type, 'cash') AS account_group,
                    ta.account_name,
                    je.source_type,
                    'posted' AS source_group,
                    CASE WHEN COALESCE(jel.debit_amount, 0) > 0 THEN 'in' ELSE 'out' END AS direction,
                    COALESCE({$counterpartyExpression}, 'Direct Treasury') AS party_name,
                    COALESCE(NULLIF(TRIM(tt.reference_no), ''), je.source_reference, CONCAT('DTE-', LPAD(tt.id, 6, '0'))) AS reference,
                    COALESCE(
                        NULLIF(TRIM(tt.narration), ''),
                        NULLIF(TRIM(jel.line_description), ''),
                        CASE WHEN tt.transaction_type = 'adjustment_increase' THEN 'Direct Money In' ELSE 'Direct Money Out' END
                    ) AS description,
                    ROUND(COALESCE(jel.debit_amount, 0), 2) AS debit_amount,
                    ROUND(COALESCE(jel.credit_amount, 0), 2) AS credit_amount,
                    jel.id AS line_id
                FROM treasury_transactions tt
                INNER JOIN branches b ON b.id = tt.branch_id
                INNER JOIN treasury_accounts ta
                    ON ta.id = CASE
                        WHEN tt.transaction_type = 'adjustment_increase' THEN tt.to_treasury_account_id
                        WHEN tt.transaction_type = 'adjustment_decrease' THEN tt.from_treasury_account_id
                        ELSE NULL
                    END
                INNER JOIN journal_entries je
                    ON je.id = tt.journal_entry_id
                   AND je.source_type = 'direct_treasury_entry_posted'
                INNER JOIN journal_entry_lines jel
                    ON jel.journal_entry_id = je.id
                   AND jel.account_id = ta.linked_account_id
                WHERE tt.transaction_type IN ('adjustment_increase', 'adjustment_decrease')

                UNION ALL

                SELECT
                    tt.id AS treasury_transaction_id,
                    ta.id AS treasury_account_id,
                    tt.branch_id,
                    COALESCE(b.name, 'N/A') AS branch_name,
                    DATE(reversal_je.entry_date) AS entry_date,
                    tt.currency,
                    COALESCE(ta.account_type, 'cash') AS account_group,
                    ta.account_name,
                    reversal_je.source_type,
                    'reversal' AS source_group,
                    CASE WHEN COALESCE(reversal_line.debit_amount, 0) > 0 THEN 'in' ELSE 'out' END AS direction,
                    COALESCE({$counterpartyExpression}, 'Direct Treasury') AS party_name,
                    COALESCE(reversal_je.source_reference, CONCAT('VOID-DTE-', tt.id)) AS reference,
                    CONCAT(
                        'Correction reversal',
                        CASE
                            WHEN NULLIF(TRIM(tt.void_reason), '') IS NOT NULL THEN CONCAT(': ', TRIM(tt.void_reason))
                            WHEN NULLIF(TRIM(tt.narration), '') IS NOT NULL THEN CONCAT(': ', TRIM(tt.narration))
                            ELSE ''
                        END
                    ) AS description,
                    ROUND(COALESCE(reversal_line.debit_amount, 0), 2) AS debit_amount,
                    ROUND(COALESCE(reversal_line.credit_amount, 0), 2) AS credit_amount,
                    reversal_line.id AS line_id
                FROM treasury_transactions tt
                INNER JOIN branches b ON b.id = tt.branch_id
                INNER JOIN treasury_accounts ta
                    ON ta.id = CASE
                        WHEN tt.transaction_type = 'adjustment_increase' THEN tt.to_treasury_account_id
                        WHEN tt.transaction_type = 'adjustment_decrease' THEN tt.from_treasury_account_id
                        ELSE NULL
                    END
                INNER JOIN journal_entries reversal_je
                    ON reversal_je.id = tt.reversal_journal_entry_id
                   AND reversal_je.source_type IN ('direct_treasury_entry_void_reversed', 'direct_treasury_entry_void_reversal')
                INNER JOIN journal_entry_lines reversal_line
                    ON reversal_line.journal_entry_id = reversal_je.id
                   AND reversal_line.account_id = ta.linked_account_id
                WHERE tt.transaction_type IN ('adjustment_increase', 'adjustment_decrease')
            ) direct_rows
            WHERE " . implode(' AND ', $where) . "
            ORDER BY direct_rows.branch_name, direct_rows.account_name, direct_rows.currency,
                     direct_rows.entry_date, direct_rows.line_id
        ";

        $statement = $this->db->prepare($sql);

        foreach ($params as $placeholder => $value) {
            $statement->bindValue(
                $placeholder,
                $value,
                is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR
            );
        }

        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function internalTreasuryTransferReport(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $sourceAccountId = null,
        ?int $destinationAccountId = null,
        string $transactionType = 'all',
        string $status = 'all',
        string $sourceCurrency = '',
        string $destinationCurrency = '',
        string $search = ''
    ): array {
        if ($branchIds === []) {
            return [];
        }

        $branchIds = array_values(array_map('intval', $branchIds));
        $params = [];
        $sourceBranchPlaceholders = [];
        $destinationBranchPlaceholders = [];
        foreach ($branchIds as $index => $branchId) {
            $sourcePlaceholder = ':transfer_source_branch_' . $index;
            $destinationPlaceholder = ':transfer_destination_branch_' . $index;
            $sourceBranchPlaceholders[] = $sourcePlaceholder;
            $destinationBranchPlaceholders[] = $destinationPlaceholder;
            $params[$sourcePlaceholder] = $branchId;
            $params[$destinationPlaceholder] = $branchId;
        }

        $hasSourceBranchColumn = $this->columnExists('treasury_transactions', 'source_branch_id');
        $hasDestinationBranchColumn = $this->columnExists('treasury_transactions', 'destination_branch_id');
        $hasSourceCurrencyColumn = $this->columnExists('treasury_transactions', 'source_currency');
        $hasDestinationCurrencyColumn = $this->columnExists('treasury_transactions', 'destination_currency');
        $hasSourceAmountColumn = $this->columnExists('treasury_transactions', 'source_amount');
        $hasDestinationAmountColumn = $this->columnExists('treasury_transactions', 'destination_amount');
        $hasExchangeRateColumn = $this->columnExists('treasury_transactions', 'exchange_rate');
        $hasRateDateColumn = $this->columnExists('treasury_transactions', 'exchange_rate_effective_date');
        $hasDestinationJournalColumn = $this->columnExists('treasury_transactions', 'destination_journal_entry_id');
        $hasCounterpartyColumn = $this->columnExists('treasury_transactions', 'counterparty_name');

        $sourceBranchIdExpr = $hasSourceBranchColumn ? 'COALESCE(tt.source_branch_id, tt.branch_id)' : 'tt.branch_id';
        $destinationBranchIdExpr = $hasDestinationBranchColumn ? 'COALESCE(tt.destination_branch_id, tt.branch_id)' : 'tt.branch_id';
        $sourceCurrencyExpr = $hasSourceCurrencyColumn ? 'COALESCE(tt.source_currency, tt.currency)' : 'tt.currency';
        $destinationCurrencyExpr = $hasDestinationCurrencyColumn ? 'COALESCE(tt.destination_currency, tt.currency)' : 'tt.currency';
        $sourceAmountExpr = $hasSourceAmountColumn ? 'COALESCE(tt.source_amount, tt.amount)' : 'tt.amount';
        $destinationAmountExpr = $hasDestinationAmountColumn ? 'COALESCE(tt.destination_amount, tt.amount)' : 'tt.amount';
        $exchangeRateExpr = $hasExchangeRateColumn ? 'tt.exchange_rate' : 'NULL';
        $rateDateExpr = $hasRateDateColumn ? 'tt.exchange_rate_effective_date' : 'NULL';
        $counterpartyExpr = $hasCounterpartyColumn ? 'tt.counterparty_name' : 'NULL';

        $where = [
            '(' . $sourceBranchIdExpr . ' IN (' . implode(', ', $sourceBranchPlaceholders) . ')'
            . ' OR ' . $destinationBranchIdExpr . ' IN (' . implode(', ', $destinationBranchPlaceholders) . '))',
            "tt.transaction_type IN ('cash_deposit_to_bank', 'bank_withdrawal_to_cash', 'bank_to_bank_transfer', 'cash_to_cash_transfer')",
        ];

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'tt.transaction_date >= :transfer_date_from';
            $params[':transfer_date_from'] = $dateFrom;
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'tt.transaction_date <= :transfer_date_to';
            $params[':transfer_date_to'] = $dateTo;
        }

        if ($sourceAccountId !== null && $sourceAccountId > 0) {
            $where[] = 'tt.from_treasury_account_id = :transfer_source_account_id';
            $params[':transfer_source_account_id'] = $sourceAccountId;
        }

        if ($destinationAccountId !== null && $destinationAccountId > 0) {
            $where[] = 'tt.to_treasury_account_id = :transfer_destination_account_id';
            $params[':transfer_destination_account_id'] = $destinationAccountId;
        }

        if ($transactionType !== 'all') {
            $where[] = 'tt.transaction_type = :transfer_transaction_type';
            $params[':transfer_transaction_type'] = $transactionType;
        }

        if ($status === 'posted') {
            $where[] = "LOWER(REPLACE(COALESCE(tt.status, 'posted'), ' ', '_')) <> 'void'";
        } elseif ($status === 'void') {
            $where[] = "LOWER(REPLACE(COALESCE(tt.status, 'posted'), ' ', '_')) = 'void'";
        }

        if ($sourceCurrency !== '') {
            $where[] = 'UPPER(' . $sourceCurrencyExpr . ') = :transfer_source_currency';
            $params[':transfer_source_currency'] = strtoupper($sourceCurrency);
        }

        if ($destinationCurrency !== '') {
            $where[] = 'UPPER(' . $destinationCurrencyExpr . ') = :transfer_destination_currency';
            $params[':transfer_destination_currency'] = strtoupper($destinationCurrency);
        }

        if ($search !== '') {
            $where[] = '('
                . 'tt.reference_no LIKE :transfer_search'
                . ' OR tt.narration LIKE :transfer_search'
                . ' OR from_ta.account_name LIKE :transfer_search'
                . ' OR to_ta.account_name LIKE :transfer_search'
                . ' OR source_branch.name LIKE :transfer_search'
                . ' OR destination_branch.name LIKE :transfer_search'
                . ' OR COALESCE(' . $counterpartyExpr . ', "") LIKE :transfer_search'
                . ')';
            $params[':transfer_search'] = '%' . $search . '%';
        }

        $sql = '
            SELECT
                tt.id,
                tt.transaction_date,
                tt.transaction_type,
                tt.status,
                tt.reference_no,
                tt.narration,
                ' . $counterpartyExpr . ' AS counterparty_name,
                ' . $sourceBranchIdExpr . ' AS source_branch_id,
                ' . $destinationBranchIdExpr . ' AS destination_branch_id,
                COALESCE(source_branch.name, branch_fallback.name, "N/A") AS source_branch_name,
                COALESCE(destination_branch.name, branch_fallback.name, "N/A") AS destination_branch_name,
                ' . $sourceCurrencyExpr . ' AS source_currency,
                ' . $destinationCurrencyExpr . ' AS destination_currency,
                ROUND(' . $sourceAmountExpr . ', 2) AS source_amount,
                ROUND(' . $destinationAmountExpr . ', 2) AS destination_amount,
                ' . $exchangeRateExpr . ' AS exchange_rate,
                ' . $rateDateExpr . ' AS exchange_rate_effective_date,
                tt.voided_at,
                tt.void_reason,
                tt.journal_entry_id,
                ' . ($hasDestinationJournalColumn ? 'tt.destination_journal_entry_id' : 'NULL') . ' AS destination_journal_entry_id,
                tt.reversal_journal_entry_id,
                from_ta.account_name AS from_account_name,
                from_ta.account_code AS from_account_code,
                from_ta.currency AS from_account_currency,
                to_ta.account_name AS to_account_name,
                to_ta.account_code AS to_account_code,
                to_ta.currency AS to_account_currency,
                creator.username AS created_by_username,
                voider.username AS voided_by_username
            FROM treasury_transactions tt
            INNER JOIN branches branch_fallback ON branch_fallback.id = tt.branch_id
            LEFT JOIN branches source_branch ON source_branch.id = ' . $sourceBranchIdExpr . '
            LEFT JOIN branches destination_branch ON destination_branch.id = ' . $destinationBranchIdExpr . '
            LEFT JOIN treasury_accounts from_ta ON from_ta.id = tt.from_treasury_account_id
            LEFT JOIN treasury_accounts to_ta ON to_ta.id = tt.to_treasury_account_id
            LEFT JOIN users creator ON creator.id = tt.created_by_user_id
            LEFT JOIN users voider ON voider.id = tt.voided_by_user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY tt.transaction_date DESC, tt.id DESC
        ';

        $statement = $this->db->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $statement->bindValue(
                $placeholder,
                $value,
                is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR
            );
        }
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function cashBankLedger(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        string $sourceType = 'all',
        ?float $transactionAmount = null
    ): array
    {
        [$branchClause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('ledger_rows.entry_date', $dateFrom, $dateTo, $params, 'cash_bank_');

        $currencySql = '';
        if (trim($currency) !== '') {
            $currencySql = ' AND ledger_rows.currency = :cash_bank_currency';
            $params['cash_bank_currency'] = strtoupper(trim($currency));
        }

        $sourceSql = '';
        $sourceType = strtolower(trim($sourceType));
        if ($sourceType !== '' && $sourceType !== 'all') {
            $sourceSql = match ($sourceType) {
                'customer' => ' AND ledger_rows.source_type IN ("customer_receipt_recorded", "customer_receipt_void_reversal")',
                'supplier' => ' AND ledger_rows.source_type IN ("supplier_payment_recorded", "supplier_payment_void_reversal")',
                'expense' => ' AND ledger_rows.source_type IN ("business_expense_recorded", "business_expense_corrected_reversal")',
                'direct' => ' AND ledger_rows.source_type IN ("direct_treasury_entry_posted", "direct_treasury_entry_void_reversal")',
                'transfer' => ' AND ledger_rows.source_type IN ("treasury_transfer_posted", "treasury_transfer_void_reversal")',
                default => '',
            };
        }

        $amountSql = '';
        if ($transactionAmount !== null) {
            $amountSql = ' AND (
                ABS(ledger_rows.debit_amount - :cash_bank_debit_amount) < 0.005
                OR ABS(ledger_rows.credit_amount - :cash_bank_credit_amount) < 0.005
            )';
            $params['cash_bank_debit_amount'] = round($transactionAmount, 2);
            $params['cash_bank_credit_amount'] = round($transactionAmount, 2);
        }

        $treasuryCounterpartySql = $this->columnExists('treasury_transactions', 'counterparty_name')
            ? 'NULLIF(tt.counterparty_name, ""), '
            : '';

        return $this->fetchRows(
            'SELECT
                ledger_rows.treasury_account_id,
                ledger_rows.branch_id,
                ledger_rows.branch_name,
                ledger_rows.booking_id,
                ledger_rows.booking_reference,
                ledger_rows.entry_date,
                ledger_rows.currency,
                ledger_rows.account_group,
                ledger_rows.account_name,
                ledger_rows.source_type,
                ledger_rows.party_name,
                ledger_rows.reference,
                ledger_rows.description,
                ledger_rows.debit_amount,
                ledger_rows.credit_amount,
                ledger_rows.line_id
             FROM (
                SELECT
                    cr.treasury_account_id,
                    je.branch_id,
                    CONVERT(br.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS branch_name,
                    b.id AS booking_id,
                    CONVERT(cr.booking_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci AS booking_reference,
                    je.entry_date,
                    CONVERT(je.currency USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
                    (CASE
                        WHEN ta.account_type = "cash" THEN "Cash"
                        WHEN ta.account_type = "bank" THEN "Bank"
                        WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                        WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                        WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                        ELSE "Cash / Bank"
                    END) COLLATE utf8mb4_unicode_ci AS account_group,
                    CONVERT(ta.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name,
                    CONVERT(je.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
                    CONVERT(COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t.full_name, ""), cr.booking_reference, "Customer") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS party_name,
                    CONVERT(COALESCE(cr.receipt_no, je.source_reference, je.booking_reference, CAST(je.id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference,
                    CONVERT(COALESCE(jel.line_description, je.narration, "") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
                    ROUND(COALESCE(jel.debit_amount, 0), 2) AS debit_amount,
                    ROUND(COALESCE(jel.credit_amount, 0), 2) AS credit_amount,
                    jel.id AS line_id
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 INNER JOIN branches br ON br.id = je.branch_id
                 INNER JOIN customer_receipts cr ON cr.id = jel.customer_receipt_id
                 INNER JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
                 LEFT JOIN bookings b ON b.booking_reference = cr.booking_reference
                 LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                 LEFT JOIN travelers t ON t.id = b.lead_traveler_id
                 WHERE cr.treasury_account_id IS NOT NULL
                   AND jel.account_id = ta.linked_account_id

                 UNION ALL

                SELECT
                    sp.treasury_account_id,
                    je.branch_id,
                    CONVERT(br.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS branch_name,
                    b.id AS booking_id,
                    CONVERT(sp.booking_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci AS booking_reference,
                    je.entry_date,
                    CONVERT(je.currency USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
                    (CASE
                        WHEN ta.account_type = "cash" THEN "Cash"
                        WHEN ta.account_type = "bank" THEN "Bank"
                        WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                        WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                        WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                        ELSE "Cash / Bank"
                    END) COLLATE utf8mb4_unicode_ci AS account_group,
                    CONVERT(ta.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name,
                    CONVERT(je.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
                    CONVERT(COALESCE(NULLIF(s.name, ""), sp.payment_no, "Supplier") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS party_name,
                    CONVERT(COALESCE(sp.payment_no, je.source_reference, je.booking_reference, CAST(je.id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference,
                    CONVERT(COALESCE(jel.line_description, je.narration, "") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
                    ROUND(COALESCE(jel.debit_amount, 0), 2) AS debit_amount,
                    ROUND(COALESCE(jel.credit_amount, 0), 2) AS credit_amount,
                    jel.id AS line_id
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 INNER JOIN branches br ON br.id = je.branch_id
                 INNER JOIN supplier_payments sp ON sp.id = jel.supplier_payment_id
                 INNER JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
                 LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
                 LEFT JOIN suppliers s ON s.id = sp.supplier_id
                 WHERE sp.treasury_account_id IS NOT NULL
                   AND jel.account_id = ta.linked_account_id

                 UNION ALL

                SELECT
                    be.treasury_account_id,
                    je.branch_id,
                    CONVERT(br.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS branch_name,
                    NULL AS booking_id,
                    CONVERT(COALESCE(je.booking_reference, "") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS booking_reference,
                    je.entry_date,
                    CONVERT(je.currency USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
                    (CASE
                        WHEN ta.account_type = "cash" THEN "Cash"
                        WHEN ta.account_type = "bank" THEN "Bank"
                        WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                        WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                        WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                        ELSE "Cash / Bank"
                    END) COLLATE utf8mb4_unicode_ci AS account_group,
                    CONVERT(ta.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name,
                    CONVERT(je.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
                    CONVERT(COALESCE(NULLIF(be.paid_to_name, ""), NULLIF(ec.name, ""), "Expense") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS party_name,
                    CONVERT(COALESCE(be.reference_number, je.source_reference, CAST(be.id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference,
                    CONVERT(COALESCE(jel.line_description, be.title, je.narration, "") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
                    ROUND(COALESCE(jel.debit_amount, 0), 2) AS debit_amount,
                    ROUND(COALESCE(jel.credit_amount, 0), 2) AS credit_amount,
                    jel.id AS line_id
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 INNER JOIN branches br ON br.id = je.branch_id
                 INNER JOIN business_expenses be ON be.journal_entry_id = je.id
                 INNER JOIN expense_categories ec ON ec.id = be.expense_category_id
                 INNER JOIN treasury_accounts ta ON ta.id = be.treasury_account_id
                 WHERE be.treasury_account_id IS NOT NULL
                   AND jel.account_id = ta.linked_account_id

                 UNION ALL

                SELECT
                    ta.id AS treasury_account_id,
                    je.branch_id,
                    CONVERT(br.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS branch_name,
                    NULL AS booking_id,
                    CONVERT(COALESCE(je.booking_reference, "") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS booking_reference,
                    je.entry_date,
                    CONVERT(je.currency USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
                    (CASE
                        WHEN ta.account_type = "cash" THEN "Cash"
                        WHEN ta.account_type = "bank" THEN "Bank"
                        WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                        WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                        WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                        ELSE "Cash / Bank"
                    END) COLLATE utf8mb4_unicode_ci AS account_group,
                    CONVERT(ta.account_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS account_name,
                    CONVERT(je.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
                    CONVERT(COALESCE(' . $treasuryCounterpartySql . 'NULLIF(tt.narration, ""), NULLIF(je.narration, ""), "Direct / Transfer") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS party_name,
                    CONVERT(COALESCE(tt.reference_no, je.source_reference, CAST(je.id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference,
                    CONVERT(COALESCE(jel.line_description, tt.narration, je.narration, "") USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
                    ROUND(COALESCE(jel.debit_amount, 0), 2) AS debit_amount,
                    ROUND(COALESCE(jel.credit_amount, 0), 2) AS credit_amount,
                    jel.id AS line_id
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 INNER JOIN branches br ON br.id = je.branch_id
                 INNER JOIN treasury_accounts ta
                    ON ta.linked_account_id = jel.account_id
                   AND ta.branch_id = je.branch_id
                   AND ta.currency = je.currency
                   AND ta.is_active = 1
                 LEFT JOIN treasury_transactions tt ON tt.journal_entry_id = je.id
                 LEFT JOIN customer_receipts cr ON cr.id = jel.customer_receipt_id
                 LEFT JOIN supplier_payments sp ON sp.id = jel.supplier_payment_id
                 LEFT JOIN business_expenses be ON be.journal_entry_id = je.id
                 WHERE cr.id IS NULL
                   AND sp.id IS NULL
                   AND be.id IS NULL
             ) AS ledger_rows
             WHERE ledger_rows.branch_id ' . $branchClause . $dateSql . $currencySql . $sourceSql . $amountSql . '
             ORDER BY
                ledger_rows.branch_name ASC,
                ledger_rows.account_group ASC,
                ledger_rows.account_name ASC,
                ledger_rows.currency ASC,
                ledger_rows.entry_date ASC,
                ledger_rows.line_id ASC',
            $params
        );
    }

    public function cashFlow(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        [$customerRefundClause, $customerRefundParams] = $this->branchScope($branchIds, 'refund_branch_');
        [$supplierRefundClause, $supplierRefundParams] = $this->branchScope($branchIds, 'supplier_refund_branch_');
        $dateParams = $params;
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $dateParams);
        $supplierWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $dateParams);
        $customerRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $customerRefundParams, 'refund_');
        $supplierRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $supplierRefundParams, 'supplier_refund_');

        $receiptRows = $this->fetchRows(
            'SELECT
                receipt_rows.branch_id,
                receipt_rows.branch_name,
                receipt_rows.movement_date,
                receipt_rows.currency,
                GREATEST(receipt_rows.cash_in_amount - COALESCE(refund_rows.refund_amount, 0), 0) AS cash_in_amount
             FROM (
                SELECT
                    cr.branch_id,
                    br.name AS branch_name,
                    cr.receipt_date AS movement_date,
                    cr.currency,
                    SUM(cr.received_amount) AS cash_in_amount
                 FROM customer_receipts cr
                 INNER JOIN branches br ON br.id = cr.branch_id
                 WHERE cr.branch_id ' . $clause . '
                   AND cr.status <> "void"' . $receiptWindow . '
                 GROUP BY cr.branch_id, br.name, cr.receipt_date, cr.currency
             ) AS receipt_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.event_date AS movement_date,
                    bse.currency,
                    SUM(bse.customer_refund_amount) AS refund_amount
                 FROM booking_service_events bse
                 WHERE bse.branch_id ' . $customerRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $customerRefundWindow . '
                 GROUP BY bse.branch_id, bse.event_date, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = receipt_rows.branch_id
               AND refund_rows.movement_date = receipt_rows.movement_date
               AND refund_rows.currency = receipt_rows.currency
             ORDER BY receipt_rows.movement_date ASC, receipt_rows.branch_name ASC, receipt_rows.currency ASC',
            array_merge($dateParams, $customerRefundParams)
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                payment_rows.branch_id,
                payment_rows.branch_name,
                payment_rows.movement_date,
                payment_rows.currency,
                GREATEST(payment_rows.cash_out_amount - COALESCE(refund_rows.refund_amount, 0), 0) AS cash_out_amount
             FROM (
                SELECT
                    sp.branch_id,
                    br.name AS branch_name,
                    sp.payment_date AS movement_date,
                    sp.currency,
                    SUM(sp.paid_amount + COALESCE(sp.charges_amount, 0)) AS cash_out_amount
                 FROM supplier_payments sp
                 INNER JOIN branches br ON br.id = sp.branch_id
                 WHERE sp.branch_id ' . $clause . '
                   AND sp.status <> "void"' . $supplierWindow . '
                 GROUP BY sp.branch_id, br.name, sp.payment_date, sp.currency
             ) AS payment_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.event_date AS movement_date,
                    bse.currency,
                    SUM(bse.supplier_refund_amount) AS refund_amount
                 FROM booking_service_events bse
                 WHERE bse.branch_id ' . $supplierRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $supplierRefundWindow . '
                 GROUP BY bse.branch_id, bse.event_date, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = payment_rows.branch_id
               AND refund_rows.movement_date = payment_rows.movement_date
               AND refund_rows.currency = payment_rows.currency
             ORDER BY payment_rows.movement_date ASC, payment_rows.branch_name ASC, payment_rows.currency ASC',
            array_merge($dateParams, $supplierRefundParams)
        );

        return [
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
        ];
    }

    public function managementSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        [$customerRefundClause, $customerRefundParams] = $this->branchScope($branchIds, 'refund_branch_');
        [$supplierRefundClause, $supplierRefundParams] = $this->branchScope($branchIds, 'supplier_refund_branch_');
        $bookingParams = $params;
        $receiptParams = $params;
        $supplierPaymentParams = $params;
        $supplierAdvanceAppliedParams = $params;
        $receivableParams = $params;
        $payableParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $receiptParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $customerRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $customerRefundParams, 'refund_');
        $supplierRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $supplierRefundParams, 'supplier_refund_');
        $supplierAdvanceAppliedWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $supplierAdvanceAppliedParams);
        $receivableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $receivableParams);
        $payableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $payableParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $payableInInvoiceCurrencyFormula = $this->servicePayableInInvoiceCurrencyFormula('bs', 'so');

        $branchRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.branch_base_currency,
                financial_rows.currency,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS total_profit
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    bs.id AS service_id,
                    bs.currency,
                    COALESCE(cri.due_amount, 0) AS receivable_amount,
                    ' . $payableInInvoiceCurrencyFormula . ' AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
              GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.branch_base_currency, financial_rows.currency',
            $bookingParams
        );

        $serviceTypeRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.branch_base_currency,
                financial_rows.currency,
                financial_rows.service_type,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS total_profit
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    bs.id AS service_id,
                    bs.currency,
                    bs.service_type,
                    COALESCE(cri.due_amount, 0) AS receivable_amount,
                    ' . $payableInInvoiceCurrencyFormula . ' AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
              GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.branch_base_currency, financial_rows.currency, financial_rows.service_type
              ORDER BY financial_rows.branch_name ASC, financial_rows.service_type ASC, financial_rows.currency ASC',
            $bookingParams
        );

        $receiptRows = $this->fetchRows(
            'SELECT
                receipt_rows.branch_id,
                receipt_rows.branch_name,
                receipt_rows.branch_base_currency,
                receipt_rows.currency,
                GREATEST(receipt_rows.total_received - COALESCE(refund_rows.total_refunded, 0), 0) AS total_received
             FROM (
                SELECT
                    cr.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    cr.currency,
                    SUM(cr.received_amount) AS total_received
                 FROM customer_receipts cr
                 INNER JOIN branches br ON br.id = cr.branch_id
                 WHERE cr.branch_id ' . $clause . '
                   AND cr.status <> "void"' . $receiptWindow . '
                  GROUP BY cr.branch_id, br.name, br.base_currency, cr.currency
             ) AS receipt_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.customer_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $customerRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $customerRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = receipt_rows.branch_id
               AND refund_rows.currency = receipt_rows.currency',
            array_merge($receiptParams, $customerRefundParams)
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                payment_rows.branch_id,
                payment_rows.branch_name,
                payment_rows.branch_base_currency,
                payment_rows.currency,
                GREATEST(payment_rows.total_supplier_paid - COALESCE(refund_rows.total_refunded, 0), 0) AS total_supplier_paid
             FROM (
                SELECT
                    sp.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    sp.currency,
                    SUM(sp.paid_amount + COALESCE(sp.charges_amount, 0)) AS total_supplier_paid
                 FROM supplier_payments sp
                 INNER JOIN branches br ON br.id = sp.branch_id
                 WHERE sp.branch_id ' . $clause . '
                   AND sp.status <> "void"' . $supplierPaymentWindow . '
                  GROUP BY sp.branch_id, br.name, br.base_currency, sp.currency
             ) AS payment_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.supplier_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $supplierRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $supplierRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = payment_rows.branch_id
               AND refund_rows.currency = payment_rows.currency',
            array_merge($supplierPaymentParams, $supplierRefundParams)
        );

        $supplierAdvanceAppliedRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency AS branch_base_currency,
                so.currency,
                SUM(so.advance_applied_amount) AS supplier_advance_applied
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE so.advance_applied_amount > 0
               AND b.branch_id ' . $clause . $supplierAdvanceAppliedWindow . '
              GROUP BY b.branch_id, br.name, br.base_currency, so.currency',
            $supplierAdvanceAppliedParams
        );

        $receivableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency AS branch_base_currency,
                cri.currency,
                SUM(cri.outstanding_amount) AS customer_outstanding
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE cri.outstanding_amount > 0
               AND b.branch_id ' . $clause . $receivableBookingWindow . '
              GROUP BY b.branch_id, br.name, br.base_currency, cri.currency',
            $receivableParams
        );

        $payableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency AS branch_base_currency,
                so.currency,
                SUM(so.net_payable_amount) AS supplier_outstanding
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE so.net_payable_amount > 0
               AND b.branch_id ' . $clause . $payableBookingWindow . '
              GROUP BY b.branch_id, br.name, br.base_currency, so.currency',
            $payableParams
        );

        $expenseRows = $this->expenseSummary($branchIds, $dateFrom, $dateTo);
        $expenseCategoryRows = $this->expenseCategorySummary($branchIds, $dateFrom, $dateTo);

        return [
            'branches' => $branchRows,
            'serviceTypes' => $serviceTypeRows,
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
            'supplierAdvanceApplied' => $supplierAdvanceAppliedRows,
            'receivables' => $receivableRows,
            'payables' => $payableRows,
            'expenses' => $expenseRows,
            'expenseCategories' => $expenseCategoryRows,
        ];
    }

    public function branchLocalDashboard(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $bookingParams = $params;
        $expenseParams = $params;

        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $expenseWindow = $this->bookingDateWindow('be.expense_date', $dateFrom, $dateTo, $expenseParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableFormula = $this->servicePayableFormula('bs');
        $payableInInvoiceCurrencyFormula = $this->servicePayableInInvoiceCurrencyFormula('bs', 'so');
        $payableCurrency = 'COALESCE(NULLIF(so.currency, ""), NULLIF(bs.cost_currency, ""), bs.currency)';
        $rawPayable = 'COALESCE(so.gross_amount, ' . $payableFormula . ')';
        $receivable = 'COALESCE(cri.due_amount, ' . $receivableFormula . ')';

        $branches = $this->fetchRows(
            'SELECT id AS branch_id, name AS branch_name, base_currency
             FROM branches
             WHERE id ' . $clause . '
               AND is_active = 1
             ORDER BY id ASC',
            $params
        );

        $baseServices = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency,
                ROUND(SUM(
                    CASE
                        WHEN bs.currency = br.base_currency THEN ' . $receivable . '
                        WHEN ' . $payableCurrency . ' = br.base_currency
                             AND COALESCE(bs.pricing_exchange_rate, 0) > 0
                        THEN ' . $receivable . ' / bs.pricing_exchange_rate
                        ELSE 0
                    END
                ), 2) AS base_sales,
                ROUND(SUM(
                    CASE
                        WHEN bs.currency = br.base_currency THEN ' . $payableInInvoiceCurrencyFormula . '
                        WHEN ' . $payableCurrency . ' = br.base_currency
                             AND COALESCE(bs.pricing_exchange_rate, 0) > 0
                        THEN ' . $rawPayable . '
                        ELSE 0
                    END
                ), 2) AS base_supplier_cost,
                SUM(
                    CASE
                        WHEN bs.currency = br.base_currency
                          OR (
                            ' . $payableCurrency . ' = br.base_currency
                            AND COALESCE(bs.pricing_exchange_rate, 0) > 0
                          )
                        THEN 0
                        ELSE 1
                    END
                ) AS pending_fx_count
             FROM booking_services bs
             INNER JOIN bookings b ON b.id = bs.booking_id
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN ' . $receivableAggregateSql . ' cri
                ON cri.booking_reference = b.booking_reference
               AND cri.service_line_reference = bs.line_reference
               AND cri.currency = bs.currency
             LEFT JOIN ' . $payableAggregateSql . ' so
                ON so.booking_reference = b.booking_reference
               AND so.service_line_reference = bs.line_reference
               AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
             WHERE b.branch_id ' . $clause . '
               AND bs.is_active = 1' . $bookingWindow . '
             GROUP BY b.branch_id, br.name, br.base_currency',
            $bookingParams
        );

        $expenseQuery = 'SELECT
                expense_rows.branch_id,
                expense_rows.branch_name,
                expense_rows.base_currency,
                ROUND(SUM(CASE WHEN expense_rows.currency = expense_rows.base_currency THEN expense_rows.amount ELSE 0 END), 2) AS base_expenses,
                SUM(CASE WHEN expense_rows.currency = expense_rows.base_currency THEN 0 ELSE 1 END) AS pending_fx_count
             FROM (
                SELECT
                    be.branch_id,
                    br.name AS branch_name,
                    br.base_currency,
                    be.currency,
                    be.amount
                 FROM business_expenses be
                 INNER JOIN branches br ON br.id = be.branch_id
                 WHERE be.branch_id ' . $clause . '
                   AND be.expense_status IN ("active", "posted")' . $expenseWindow;
        $expenseQueryParams = $expenseParams;

        if ($this->salaryExpenseTablesExist()) {
            [$salaryClause, $salaryExpenseParams] = $this->branchScope($branchIds, 'salary_branch_');
            $salaryExpenseWindow = $this->bookingDateWindow('r.record_date', $dateFrom, $dateTo, $salaryExpenseParams, 'salary_');
            $expenseQuery .= '
                UNION ALL
                SELECT
                    r.branch_id,
                    br.name AS branch_name,
                    br.base_currency,
                    r.currency,
                    r.gross_debit_amount AS amount
                 FROM employee_salary_records r
                 INNER JOIN branches br ON br.id = r.branch_id
                 WHERE r.branch_id ' . $salaryClause . '
                   AND ' . $this->nonVoidedSalaryStatusClause('r') . $salaryExpenseWindow;
            $expenseQueryParams = array_merge($expenseQueryParams, $salaryExpenseParams);
        }

        $expenseQuery .= '
             ) AS expense_rows
             GROUP BY expense_rows.branch_id, expense_rows.branch_name, expense_rows.base_currency';

        $expenses = $this->fetchRows($expenseQuery, $expenseQueryParams);

        return [
            'branches' => $branches,
            'baseServices' => $baseServices,
            'expenses' => $expenses,
        ];
    }

    public function supplierPostpaidPayments(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        int $supplierId = 0
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $window = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $dateParams);
        $currencyFilter = strtoupper(trim($currency));
        $currencySql = '';
        if ($currencyFilter !== '') {
            $currencySql = ' AND sp.currency = :currency_filter';
            $dateParams['currency_filter'] = $currencyFilter;
        }
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND sp.supplier_id = :supplier_id';
            $dateParams['supplier_id'] = $supplierId;
        }

        return $this->fetchRows(
            'SELECT
                sp.id,
                COALESCE(b.id, 0) AS booking_id,
                sp.branch_id,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no,
                sp.payment_date,
                sp.currency,
                s.name AS supplier_name,
                sp.paid_amount,
                sp.allocated_amount,
                sp.unallocated_amount,
                sp.charges_amount,
                sp.payment_method,
                sp.status,
                sp.reference_number,
                sp.remarks
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.branch_id ' . $clause . $window . $currencySql . $supplierSql . '
               AND LOWER(REPLACE(COALESCE(sp.status, ""), " ", "_")) <> "void"
             ORDER BY sp.payment_date DESC, sp.id DESC',
            $dateParams
        );
    }

    public function supplierPrepaidPayments(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        int $supplierId = 0
    ): array
    {
        [$advanceClause, $advanceParams] = $this->branchScope($branchIds, 'advance_branch_');
        [$paymentClause, $paymentParams] = $this->branchScope($branchIds, 'payment_branch_');
        $advanceWindow = $this->bookingDateWindow('a.received_at', $dateFrom, $dateTo, $advanceParams);
        $paymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $paymentParams);
        $currencyFilter = strtoupper(trim($currency));
        $advanceCurrencySql = '';
        $paymentCurrencySql = '';
        if ($currencyFilter !== '') {
            $advanceCurrencySql = ' AND a.currency = :advance_currency_filter';
            $paymentCurrencySql = ' AND sp.currency = :payment_currency_filter';
            $advanceParams['advance_currency_filter'] = $currencyFilter;
            $paymentParams['payment_currency_filter'] = $currencyFilter;
        }
        $advanceSupplierSql = '';
        $paymentSupplierSql = '';
        if ($supplierId > 0) {
            $advanceSupplierSql = ' AND a.supplier_id = :advance_supplier_id';
            $paymentSupplierSql = ' AND sp.supplier_id = :payment_supplier_id';
            $advanceParams['advance_supplier_id'] = $supplierId;
            $paymentParams['payment_supplier_id'] = $supplierId;
        }

        $advanceRows = $this->fetchRows(
            'SELECT
                a.id,
                a.supplier_id,
                a.branch_id,
                br.name AS branch_name,
                s.name AS supplier_name,
                a.currency,
                a.received_at AS payment_date,
                a.deposit_amount,
                (a.deposit_amount - a.available_amount) AS used_amount,
                a.available_amount,
                a.reference_no,
                a.remarks,
                a.payment_method,
                a.treasury_account_id,
                a.journal_entry_id,
                a.source_supplier_payment_id,
                COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t.full_name, ""), "Customer") AS customer_name,
                COALESCE(sp.booking_reference, "") AS booking_reference,
                COALESCE(sp.payment_no, "") AS source_payment_no,
                ta.account_name AS treasury_account_name,
                CASE
                    WHEN a.available_amount <= 0.005 THEN "fully_used"
                    WHEN a.available_amount + 0.005 < a.deposit_amount THEN "partially_used"
                    ELSE "available"
                END AS status,
                "supplier_advance" AS credit_source
             FROM supplier_advances a
             INNER JOIN branches br ON br.id = a.branch_id
             INNER JOIN suppliers s ON s.id = a.supplier_id
             LEFT JOIN supplier_payments sp ON sp.id = a.source_supplier_payment_id
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t ON t.id = b.lead_traveler_id
             LEFT JOIN treasury_accounts ta ON ta.id = a.treasury_account_id
             WHERE a.branch_id ' . $advanceClause . $advanceWindow . $advanceCurrencySql . $advanceSupplierSql . '
             ORDER BY a.received_at DESC, a.id DESC',
            $advanceParams
        );

        $paymentCreditRows = $this->fetchRows(
            'SELECT
                0 AS id,
                sp.supplier_id,
                sp.branch_id,
                br.name AS branch_name,
                s.name AS supplier_name,
                sp.currency,
                sp.payment_date,
                sp.unallocated_amount AS deposit_amount,
                0.00 AS used_amount,
                sp.unallocated_amount AS available_amount,
                sp.payment_no AS reference_no,
                TRIM(CONCAT(
                    COALESCE(sp.remarks, ""),
                    CASE
                        WHEN COALESCE(sp.remarks, "") = "" THEN ""
                        ELSE " "
                    END,
                    "[Supplier payment credit still available.]"
                )) AS remarks,
                sp.payment_method,
                sp.treasury_account_id,
                NULL AS journal_entry_id,
                sp.id AS source_supplier_payment_id,
                COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t.full_name, ""), "Customer") AS customer_name,
                COALESCE(sp.booking_reference, "") AS booking_reference,
                sp.payment_no AS source_payment_no,
                ta.account_name AS treasury_account_name,
                "available" AS status,
                "supplier_payment_credit" AS credit_source
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t ON t.id = b.lead_traveler_id
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
             WHERE sp.branch_id ' . $paymentClause . $paymentWindow . $paymentCurrencySql . $paymentSupplierSql . '
               AND sp.status <> "void"
               AND sp.unallocated_amount > 0.005
             ORDER BY sp.payment_date DESC, sp.id DESC',
            $paymentParams
        );

        $rows = array_merge($advanceRows, $paymentCreditRows);
        usort($rows, static function (array $left, array $right): int {
            $leftDate = (string) ($left['payment_date'] ?? '');
            $rightDate = (string) ($right['payment_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            return ((int) ($right['source_supplier_payment_id'] ?? $right['id'] ?? 0))
                <=> ((int) ($left['source_supplier_payment_id'] ?? $left['id'] ?? 0));
        });

        return $rows;
    }

    public function supplierPrepaidPaymentReceipt(int $advanceId, array $branchIds): ?array
    {
        $params = ['advance_id' => $advanceId];

        $statement = $this->db->prepare(
            'SELECT
                a.id,
                a.branch_id,
                br.name AS branch_name,
                s.name AS supplier_name,
                a.currency,
                a.received_at AS payment_date,
                a.deposit_amount,
                (a.deposit_amount - a.available_amount) AS used_amount,
                a.available_amount,
                a.reference_no,
                a.remarks,
                a.payment_method,
                a.treasury_account_id,
                a.journal_entry_id,
                a.source_supplier_payment_id,
                COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t.full_name, ""), "Customer") AS customer_name,
                COALESCE(sp.booking_reference, "") AS booking_reference,
                COALESCE(sp.payment_no, "") AS source_payment_no,
                ta.account_name AS treasury_account_name,
                CASE
                    WHEN a.available_amount <= 0.005 THEN "fully_used"
                    WHEN a.available_amount + 0.005 < a.deposit_amount THEN "partially_used"
                    ELSE "available"
                END AS status
             FROM supplier_advances a
             INNER JOIN branches br ON br.id = a.branch_id
             INNER JOIN suppliers s ON s.id = a.supplier_id
             LEFT JOIN supplier_payments sp ON sp.id = a.source_supplier_payment_id
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t ON t.id = b.lead_traveler_id
             LEFT JOIN treasury_accounts ta ON ta.id = a.treasury_account_id
             WHERE a.id = :advance_id
             LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function unallocatedMoneyTrace(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $customerParams = $params;
        $supplierPaymentParams = $params;
        $supplierAdvanceParams = [];

        $customerWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $customerParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $supplierAdvanceWindow = $this->bookingDateWindow('sa.received_at', $dateFrom, $dateTo, $supplierAdvanceParams);

        $currencyFilter = strtoupper(trim($currency));
        $customerCurrencySql = '';
        $supplierPaymentCurrencySql = '';
        $supplierAdvanceCurrencySql = '';
        if ($currencyFilter !== '') {
            $customerCurrencySql = ' AND cr.currency = :currency_filter';
            $supplierPaymentCurrencySql = ' AND sp.currency = :currency_filter';
            $supplierAdvanceCurrencySql = ' AND sa.currency = :currency_filter';
            $customerParams['currency_filter'] = $currencyFilter;
            $supplierPaymentParams['currency_filter'] = $currencyFilter;
            $supplierAdvanceParams['currency_filter'] = $currencyFilter;
        }

        $customerRows = $this->fetchRows(
            'SELECT
                CASE
                    WHEN cr.receipt_purpose = "account_holder_receipt" THEN "account_holder_receipt"
                    ELSE "customer_receipt"
                END AS source_key,
                CASE
                    WHEN cr.receipt_purpose = "account_holder_receipt" THEN "Account-Holder Payment on Account"
                    ELSE "Customer Receipt Credit"
                END AS source_type,
                cr.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                cr.branch_id,
                br.name AS branch_name,
                CASE
                    WHEN cr.receipt_purpose = "account_holder_receipt" THEN ""
                    ELSE cr.booking_reference
                END AS booking_reference,
                cr.receipt_no AS document_no,
                cr.receipt_date AS document_date,
                CASE
                    WHEN cr.receipt_purpose = "account_holder_receipt" THEN COALESCE(bs.name, "Account Holder")
                    ELSE COALESCE(bp.lead_traveler_name, cr.booking_reference, "Customer")
                END AS counterparty_name,
                cr.receipt_purpose,
                COALESCE(cpl.id, 0) AS linked_party_id,
                cr.currency,
                cr.received_amount AS original_amount,
                cr.allocated_amount AS allocated_amount,
                cr.unallocated_amount AS unallocated_amount,
                GREATEST(DATEDIFF(CURDATE(), cr.receipt_date), 0) AS age_days,
                cr.status,
                cr.reference_number,
                cr.remarks
             FROM customer_receipts cr
             LEFT JOIN bookings b ON b.booking_reference = cr.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN business_sources bs ON bs.id = cr.business_source_id
             LEFT JOIN business_source_supplier_links cpl ON cpl.business_source_id = cr.business_source_id
             INNER JOIN branches br ON br.id = cr.branch_id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status <> "void"
               AND cr.unallocated_amount > 0.005' . $customerWindow . $customerCurrencySql,
            $customerParams
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                "supplier_payment" AS source_key,
                "Supplier Payment Open" AS source_type,
                sp.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                sp.branch_id,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no AS document_no,
                sp.payment_date AS document_date,
                s.name AS counterparty_name,
                sp.currency,
                sp.paid_amount AS original_amount,
                sp.allocated_amount AS allocated_amount,
                sp.unallocated_amount AS unallocated_amount,
                GREATEST(DATEDIFF(CURDATE(), sp.payment_date), 0) AS age_days,
                sp.status,
                sp.reference_number,
                sp.remarks
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status <> "void"
               AND sp.unallocated_amount > 0.005' . $supplierPaymentWindow . $supplierPaymentCurrencySql,
            $supplierPaymentParams
        );

        $supplierAdvanceRows = $this->fetchRows(
            'SELECT
                "supplier_advance" AS source_key,
                "Supplier Advance Available" AS source_type,
                sa.id AS entity_id,
                0 AS booking_id,
                sa.branch_id,
                br.name AS branch_name,
                "" AS booking_reference,
                CONCAT("SADV-", LPAD(sa.id, 3, "0")) AS document_no,
                sa.received_at AS document_date,
                s.name AS counterparty_name,
                sa.currency,
                sa.deposit_amount AS original_amount,
                (sa.deposit_amount - sa.available_amount) AS allocated_amount,
                sa.available_amount AS unallocated_amount,
                GREATEST(DATEDIFF(CURDATE(), sa.received_at), 0) AS age_days,
                CASE
                    WHEN sa.available_amount + 0.005 < sa.deposit_amount THEN "partially_used"
                    ELSE "available"
                END AS status,
                sa.reference_no AS reference_number,
                sa.remarks
             FROM supplier_advances sa
             INNER JOIN branches br ON br.id = sa.branch_id
             INNER JOIN suppliers s ON s.id = sa.supplier_id
             WHERE sa.available_amount > 0.005' . $supplierAdvanceWindow . $supplierAdvanceCurrencySql,
            $supplierAdvanceParams
        );

        $rows = array_merge($customerRows, $supplierPaymentRows, $supplierAdvanceRows);
        usort($rows, static function (array $left, array $right): int {
            $leftDate = (string) ($left['document_date'] ?? '');
            $rightDate = (string) ($right['document_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            return strcmp((string) ($right['document_no'] ?? ''), (string) ($left['document_no'] ?? ''));
        });

        return $rows;
    }

    public function voidReversalRegister(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $customerParams = $params;
        $supplierParams = $params;
        $customerWindow = $this->bookingDateWindow('DATE(cr.voided_at)', $dateFrom, $dateTo, $customerParams);
        $supplierWindow = $this->bookingDateWindow('DATE(sp.voided_at)', $dateFrom, $dateTo, $supplierParams);
        $currencyFilter = strtoupper(trim($currency));
        $customerCurrencySql = '';
        $supplierCurrencySql = '';

        if ($currencyFilter !== '') {
            $customerCurrencySql = ' AND cr.currency = :currency_filter';
            $supplierCurrencySql = ' AND sp.currency = :currency_filter';
            $customerParams['currency_filter'] = $currencyFilter;
            $supplierParams['currency_filter'] = $currencyFilter;
        }

        $customerRows = $this->fetchRows(
            'SELECT
                "customer_receipt" AS module_key,
                cr.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                cr.branch_id,
                br.name AS branch_name,
                cr.booking_reference,
                cr.receipt_no AS document_no,
                COALESCE(bp.lead_traveler_name, bp.party_label, b.booking_reference, "Customer") AS counterparty_name,
                cr.currency,
                cr.received_amount AS original_amount,
                COALESCE(alloc.reversed_amount, 0) AS reversed_amount,
                COALESCE(alloc.allocation_count, 0) AS allocation_count,
                cr.status,
                cr.void_reason,
                cr.voided_by_user_id,
                COALESCE(u.name, "Unknown User") AS voided_by_user,
                cr.voided_at,
                cr.reversal_reference,
                cr.reversal_journal_entry_id
             FROM customer_receipts cr
             LEFT JOIN bookings b ON b.booking_reference = cr.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             INNER JOIN branches br ON br.id = cr.branch_id
             LEFT JOIN users u ON u.id = cr.voided_by_user_id
             LEFT JOIN (
                SELECT
                    customer_receipt_id,
                    COUNT(*) AS allocation_count,
                    SUM(receivable_amount_allocated) AS reversed_amount
                FROM customer_receipt_allocations
                GROUP BY customer_receipt_id
             ) alloc ON alloc.customer_receipt_id = cr.id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status = "void"
               AND cr.voided_at IS NOT NULL' . $customerWindow . $customerCurrencySql,
            $customerParams
        );

        $supplierRows = $this->fetchRows(
            'SELECT
                "supplier_payment" AS module_key,
                sp.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                sp.branch_id,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no AS document_no,
                s.name AS counterparty_name,
                sp.currency,
                sp.paid_amount AS original_amount,
                COALESCE(alloc.reversed_amount, 0) AS reversed_amount,
                COALESCE(alloc.allocation_count, 0) AS allocation_count,
                sp.status,
                sp.void_reason,
                sp.voided_by_user_id,
                COALESCE(u.name, "Unknown User") AS voided_by_user,
                sp.voided_at,
                sp.reversal_reference,
                sp.reversal_journal_entry_id
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             LEFT JOIN users u ON u.id = sp.voided_by_user_id
             LEFT JOIN (
                SELECT
                    supplier_payment_id,
                    COUNT(*) AS allocation_count,
                    SUM(allocated_amount) AS reversed_amount
                FROM supplier_payment_allocations
                GROUP BY supplier_payment_id
             ) alloc ON alloc.supplier_payment_id = sp.id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status = "void"
               AND sp.voided_at IS NOT NULL' . $supplierWindow . $supplierCurrencySql,
            $supplierParams
        );

        return array_merge($customerRows, $supplierRows);
    }

    public function financeAuditTrail(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $windowSql = $this->bookingDateWindow('DATE(al.created_at)', $dateFrom, $dateTo, $params);
        $currencyFilter = strtoupper(trim($currency));
        $currencySql = '';

        if ($currencyFilter !== '') {
            $currencySql = ' AND COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.currency")), ""),
                cr.currency,
                sp.currency,
                sa.currency,
                so.currency
            ) = :currency_filter';
            $params['currency_filter'] = $currencyFilter;
        }

        return $this->fetchRows(
            'SELECT
                al.id,
                al.event_name,
                al.actor_user_id,
                COALESCE(actor.name, "Unknown User") AS actor_name,
                al.ip_address,
                al.created_at,
                COALESCE(
                    cr.branch_id,
                    sp.branch_id,
                    sa.branch_id,
                    so.branch_id,
                    b.branch_id,
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.branch_id")), "") AS UNSIGNED)
                ) AS branch_id,
                br.name AS branch_name,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.booking_reference")), ""),
                    cr.booking_reference,
                    sp.booking_reference,
                    so.booking_reference,
                    b.booking_reference
                ) AS booking_reference,
                COALESCE(
                    b.id,
                    booking_lookup.id,
                    booking_lookup_sp.id,
                    booking_lookup_so.id,
                    0
                ) AS booking_id,
                CASE
                    WHEN al.event_name LIKE "customer.receipt.%" THEN "Customer Receipt"
                    WHEN al.event_name LIKE "supplier.payment.%" THEN "Supplier Payment"
                    WHEN al.event_name LIKE "supplier.advance.%" THEN "Supplier Advance"
                    ELSE "Finance"
                END AS module_label,
                CASE
                    WHEN al.event_name LIKE "%.voided" THEN "Void"
                    WHEN al.event_name LIKE "%.metadata_updated" THEN "Metadata Edit"
                    WHEN al.event_name LIKE "%.allocated" THEN "Allocation"
                    WHEN al.event_name LIKE "%.recorded" OR al.event_name LIKE "%.created" THEN "Created"
                    WHEN al.event_name LIKE "%.applied" THEN "Applied"
                    WHEN al.event_name LIKE "%.reconciled" THEN "Reconciled"
                    ELSE "Audit Event"
                END AS action_label,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.receipt_no")), ""),
                    cr.receipt_no,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.payment_no")), ""),
                    sp.payment_no,
                    CONCAT("ADV-", LPAD(CAST(sa.id AS CHAR), 6, "0"))
                ) AS document_no,
                COALESCE(
                    bp.lead_traveler_name,
                    bp.contact_mobile,
                    bp.party_label,
                    s.name,
                    advance_supplier.name,
                    obligation_supplier.name,
                    "N/A"
                ) AS counterparty_name,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.currency")), ""),
                    cr.currency,
                    sp.currency,
                    sa.currency,
                    so.currency
                ) AS currency,
                COALESCE(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.received_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.paid_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.allocated_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.deposit_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.applied_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.returned_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.total_payment_amount_reversed")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.total_allocated_amount_reversed")), "") AS DECIMAL(18,2)),
                    0
                ) AS amount_value,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.void_reason")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.reason")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.allocation_note")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.reference_number")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.remarks")), ""),
                    "N/A"
                ) AS detail_note,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.customer_receipt_id")), "") AS UNSIGNED) AS customer_receipt_id,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_payment_id")), "") AS UNSIGNED) AS supplier_payment_id,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_advance_id")), "") AS UNSIGNED) AS supplier_advance_id
             FROM audit_logs al
             LEFT JOIN users actor ON actor.id = al.actor_user_id
             LEFT JOIN customer_receipts cr
                ON cr.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.customer_receipt_id")), "") AS UNSIGNED)
             LEFT JOIN supplier_payments sp
                ON sp.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_payment_id")), "") AS UNSIGNED)
             LEFT JOIN supplier_advances sa
                ON sa.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_advance_id")), "") AS UNSIGNED)
             LEFT JOIN supplier_obligations so
                ON so.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_obligation_id")), "") AS UNSIGNED)
             LEFT JOIN bookings b
                ON b.booking_reference = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.booking_reference")), "")
             LEFT JOIN bookings booking_lookup
                ON booking_lookup.booking_reference = cr.booking_reference
             LEFT JOIN bookings booking_lookup_sp
                ON booking_lookup_sp.booking_reference = sp.booking_reference
             LEFT JOIN bookings booking_lookup_so
                ON booking_lookup_so.booking_reference = so.booking_reference
             LEFT JOIN bookings customer_booking
                ON customer_booking.id = booking_lookup.id
             LEFT JOIN bookings supplier_booking
                ON supplier_booking.id = booking_lookup_sp.id
             LEFT JOIN bookings so_booking
                ON so_booking.id = booking_lookup_so.id
             LEFT JOIN booking_parties bp
                ON bp.booking_id = COALESCE(customer_booking.id, supplier_booking.id, so_booking.id, b.id)
             LEFT JOIN branches br
                ON br.id = COALESCE(
                    cr.branch_id,
                    sp.branch_id,
                    sa.branch_id,
                    so.branch_id,
                    b.branch_id,
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.branch_id")), "") AS UNSIGNED)
                )
             LEFT JOIN suppliers s ON s.id = sp.supplier_id
             LEFT JOIN suppliers advance_supplier ON advance_supplier.id = sa.supplier_id
             LEFT JOIN suppliers obligation_supplier ON obligation_supplier.id = so.supplier_id
             WHERE al.event_name IN (
                "customer.receipt.recorded",
                "customer.receipt.allocated",
                "customer.receipt.metadata_updated",
                "customer.receipt.voided",
                "supplier.payment.created",
                "supplier.payment.allocated",
                "supplier.payment.metadata_updated",
                "supplier.payment.voided",
                "supplier.advance.recorded",
                "supplier.advance.applied",
                "supplier.advance.reconciled"
             )
               AND COALESCE(
                    cr.branch_id,
                    sp.branch_id,
                    sa.branch_id,
                    so.branch_id,
                    b.branch_id,
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.branch_id")), "") AS UNSIGNED)
               ) ' . $clause . $windowSql . $currencySql . '
             ORDER BY al.created_at DESC, al.id DESC',
            $params
        );
    }

    public function prepaidSupplierLedgerSummary(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        string $balanceView = 'all',
        int $supplierId = 0
    ): array {
        $advanceParams = [];
        $paymentCreditParams = [];
        $usageParams = [];
        $advanceWindow = '';
        $paymentCreditWindow = '';
        $usageAdvanceWindow = '';

        if ($dateFrom !== null) {
            $advanceWindow .= ' AND a.received_at >= :advance_date_from';
            $paymentCreditWindow .= ' AND sp.payment_date >= :payment_credit_date_from';
            $usageAdvanceWindow .= ' AND a.received_at >= :usage_date_from';
            $advanceParams['advance_date_from'] = $dateFrom;
            $paymentCreditParams['payment_credit_date_from'] = $dateFrom;
            $usageParams['usage_date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $advanceWindow .= ' AND a.received_at <= :advance_date_to';
            $paymentCreditWindow .= ' AND sp.payment_date <= :payment_credit_date_to';
            $usageAdvanceWindow .= ' AND a.received_at <= :usage_date_to';
            $advanceParams['advance_date_to'] = $dateTo;
            $paymentCreditParams['payment_credit_date_to'] = $dateTo;
            $usageParams['usage_date_to'] = $dateTo;
        }

        $currency = strtoupper(trim($currency));
        if ($currency !== '') {
            $advanceWindow .= ' AND a.currency = :advance_currency_filter';
            $paymentCreditWindow .= ' AND sp.currency = :payment_credit_currency_filter';
            $usageAdvanceWindow .= ' AND a.currency = :usage_currency_filter';
            $advanceParams['advance_currency_filter'] = $currency;
            $paymentCreditParams['payment_credit_currency_filter'] = $currency;
            $usageParams['usage_currency_filter'] = $currency;
        }
        [$advanceClause, $advanceBranchParams] = $this->branchScope($branchIds, 'advance_branch_');
        [$paymentClause, $paymentBranchParams] = $this->branchScope($branchIds, 'payment_branch_');
        [$usageClause, $usageBranchParams] = $this->branchScope($branchIds, 'usage_branch_');

        if ($supplierId > 0) {
            $advanceWindow .= ' AND a.supplier_id = :advance_supplier_id';
            $paymentCreditWindow .= ' AND sp.supplier_id = :payment_credit_supplier_id';
            $usageAdvanceWindow .= ' AND a.supplier_id = :usage_supplier_id';
            $advanceParams['advance_supplier_id'] = $supplierId;
            $paymentCreditParams['payment_credit_supplier_id'] = $supplierId;
            $usageParams['usage_supplier_id'] = $supplierId;
        }

        $advanceRows = $this->fetchRows(
            'SELECT
                a.supplier_id,
                a.currency,
                s.name AS supplier_name,
                SUM(a.deposit_amount) AS total_advance_paid,
                SUM(a.available_amount) AS remaining_advance_balance,
                COUNT(a.id) AS advance_payments_count,
                MAX(a.received_at) AS last_advance_date
             FROM supplier_advances a
             INNER JOIN suppliers s ON s.id = a.supplier_id
             WHERE a.branch_id ' . $advanceClause . $advanceWindow . '
             GROUP BY a.supplier_id, a.currency, s.name',
            array_merge($advanceParams, $advanceBranchParams)
        );

        $paymentCreditRows = $this->fetchRows(
            'SELECT
                sp.supplier_id,
                sp.currency,
                s.name AS supplier_name,
                SUM(sp.unallocated_amount) AS total_payment_credit,
                COUNT(sp.id) AS payment_credit_entries_count,
                MAX(sp.payment_date) AS last_payment_credit_date
             FROM supplier_payments sp
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.branch_id ' . $paymentClause . $paymentCreditWindow . '
               AND sp.status <> "void"
               AND sp.unallocated_amount > 0.005
             GROUP BY sp.supplier_id, sp.currency, s.name',
            array_merge($paymentCreditParams, $paymentBranchParams)
        );

        $usageRows = $this->fetchRows(
            'SELECT
                a.supplier_id,
                a.currency,
                SUM(aa.applied_amount) AS total_advance_used,
                COUNT(aa.id) AS advance_uses_count,
                MAX(aa.created_at) AS last_used_date
             FROM supplier_advance_applications aa
             INNER JOIN supplier_advances a ON a.id = aa.supplier_advance_id
             WHERE a.branch_id ' . $usageClause . $usageAdvanceWindow . '
             GROUP BY a.supplier_id, a.currency',
            array_merge($usageParams, $usageBranchParams)
        );

        $advanceByKey = [];
        foreach ($advanceRows as $row) {
            $key = (int) ($row['supplier_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            $advanceByKey[$key] = $row;
        }

        $paymentCreditByKey = [];
        foreach ($paymentCreditRows as $row) {
            $key = (int) ($row['supplier_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            $paymentCreditByKey[$key] = $row;
        }

        $usageByKey = [];
        foreach ($usageRows as $row) {
            $key = (int) ($row['supplier_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            $usageByKey[$key] = $row;
        }

        $keys = array_values(array_unique(array_merge(
            array_keys($advanceByKey),
            array_keys($paymentCreditByKey),
            array_keys($usageByKey)
        )));

        $rows = [];
        foreach ($keys as $key) {
            $advanceRow = $advanceByKey[$key] ?? [];
            $paymentCreditRow = $paymentCreditByKey[$key] ?? [];
            $usageRow = $usageByKey[$key] ?? [];

            $balance = round(
                (float) ($advanceRow['remaining_advance_balance'] ?? 0)
                + (float) ($paymentCreditRow['total_payment_credit'] ?? 0),
                2
            );

            if ($balanceView === 'only_available' && $balance <= 0.005) {
                continue;
            }
            if ($balanceView === 'fully_used' && $balance > 0.005) {
                continue;
            }

            $lastAdvanceDate = (string) ($advanceRow['last_advance_date'] ?? '');
            $lastPaymentCreditDate = (string) ($paymentCreditRow['last_payment_credit_date'] ?? '');
            $combinedLastAdvanceDate = $lastAdvanceDate;
            if ($lastPaymentCreditDate !== '' && ($combinedLastAdvanceDate === '' || strcmp($lastPaymentCreditDate, $combinedLastAdvanceDate) > 0)) {
                $combinedLastAdvanceDate = $lastPaymentCreditDate;
            }

            $rows[] = [
                'supplier_id' => (int) ($advanceRow['supplier_id'] ?? $paymentCreditRow['supplier_id'] ?? $usageRow['supplier_id'] ?? 0),
                'currency' => (string) ($advanceRow['currency'] ?? $paymentCreditRow['currency'] ?? $usageRow['currency'] ?? 'PKR'),
                'supplier_name' => (string) ($advanceRow['supplier_name'] ?? $paymentCreditRow['supplier_name'] ?? 'Supplier'),
                'total_advance_paid' => round(
                    (float) ($advanceRow['total_advance_paid'] ?? 0)
                    + (float) ($paymentCreditRow['total_payment_credit'] ?? 0),
                    2
                ),
                'total_advance_used' => round((float) ($usageRow['total_advance_used'] ?? 0), 2),
                'remaining_advance_balance' => $balance,
                'advance_payments_count' => (int) ($advanceRow['advance_payments_count'] ?? 0)
                    + (int) ($paymentCreditRow['payment_credit_entries_count'] ?? 0),
                'advance_uses_count' => (int) ($usageRow['advance_uses_count'] ?? 0),
                'last_advance_date' => $combinedLastAdvanceDate,
                'last_used_date' => (string) ($usageRow['last_used_date'] ?? ''),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $nameCompare = strcmp((string) ($left['supplier_name'] ?? ''), (string) ($right['supplier_name'] ?? ''));
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return strcmp((string) ($left['currency'] ?? ''), (string) ($right['currency'] ?? ''));
        });

        return $rows;
    }

    public function grossProfitSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $dateParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableFormula = $this->servicePayableFormula('bs');
        $payableInInvoiceCurrencyFormula = $this->servicePayableInInvoiceCurrencyFormula('bs', 'so');

        return $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.currency,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS gross_profit
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    COALESCE(cri.due_amount, ' . $receivableFormula . ') AS receivable_amount,
                    ' . $payableInInvoiceCurrencyFormula . ' AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
             GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.currency
             ORDER BY financial_rows.branch_name ASC, financial_rows.currency ASC',
            $dateParams
        );
    }

    public function expenseSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $expenseWindow = $this->bookingDateWindow('be.expense_date', $dateFrom, $dateTo, $dateParams);
        $query = 'SELECT
                expense_rows.branch_id,
                expense_rows.branch_name,
                expense_rows.currency,
                SUM(expense_rows.amount) AS total_expenses
             FROM (
                SELECT
                    be.branch_id,
                    br.name AS branch_name,
                    be.currency,
                    be.amount
                 FROM business_expenses be
                 INNER JOIN branches br ON br.id = be.branch_id
                 WHERE be.branch_id ' . $clause . '
                   AND be.expense_status IN ("active", "posted")' . $expenseWindow;
        $queryParams = $dateParams;

        if ($this->salaryExpenseTablesExist()) {
            [$salaryClause, $salaryParams] = $this->branchScope($branchIds, 'salary_branch_');
            $salaryWindow = $this->bookingDateWindow('r.record_date', $dateFrom, $dateTo, $salaryParams, 'salary_');
            $query .= '
                UNION ALL
                SELECT
                    r.branch_id,
                    br.name AS branch_name,
                    r.currency,
                    r.gross_debit_amount AS amount
                 FROM employee_salary_records r
                 INNER JOIN branches br ON br.id = r.branch_id
                 WHERE r.branch_id ' . $salaryClause . '
                   AND ' . $this->nonVoidedSalaryStatusClause('r') . $salaryWindow;
            $queryParams = array_merge($queryParams, $salaryParams);
        }

        $query .= '
             ) AS expense_rows
             GROUP BY expense_rows.branch_id, expense_rows.branch_name, expense_rows.currency
             ORDER BY expense_rows.branch_name ASC, expense_rows.currency ASC';

        return $this->fetchRows($query, $queryParams);
    }

    public function expenseCategorySummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $expenseWindow = $this->bookingDateWindow('be.expense_date', $dateFrom, $dateTo, $dateParams);
        $query = 'SELECT
                expense_rows.branch_id,
                expense_rows.branch_name,
                expense_rows.category_name,
                expense_rows.currency,
                SUM(expense_rows.amount) AS total_expenses
             FROM (
                SELECT
                    be.branch_id,
                    br.name AS branch_name,
                    ec.name AS category_name,
                    be.currency,
                    be.amount
                 FROM business_expenses be
                 INNER JOIN branches br ON br.id = be.branch_id
                 INNER JOIN expense_categories ec ON ec.id = be.expense_category_id
                 WHERE be.branch_id ' . $clause . '
                   AND be.expense_status IN ("active", "posted")' . $expenseWindow;
        $queryParams = $dateParams;

        if ($this->salaryExpenseTablesExist()) {
            [$salaryClause, $salaryParams] = $this->branchScope($branchIds, 'salary_branch_');
            $salaryWindow = $this->bookingDateWindow('r.record_date', $dateFrom, $dateTo, $salaryParams, 'salary_');
            $query .= '
                UNION ALL
                SELECT
                    r.branch_id,
                    br.name AS branch_name,
                    "Salary" AS category_name,
                    r.currency,
                    r.gross_debit_amount AS amount
                 FROM employee_salary_records r
                 INNER JOIN branches br ON br.id = r.branch_id
                 WHERE r.branch_id ' . $salaryClause . '
                   AND ' . $this->nonVoidedSalaryStatusClause('r') . $salaryWindow;
            $queryParams = array_merge($queryParams, $salaryParams);
        }

        $query .= '
             ) AS expense_rows
             GROUP BY expense_rows.branch_id, expense_rows.branch_name, expense_rows.category_name, expense_rows.currency
             ORDER BY expense_rows.branch_name ASC, expense_rows.category_name ASC, expense_rows.currency ASC';

        return $this->fetchRows($query, $queryParams);
    }

    public function expenseRegister(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = '', int $expenseCategoryId = 0): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $expenseWindow = $this->bookingDateWindow('be.expense_date', $dateFrom, $dateTo, $dateParams);
        $categoryClause = '';
        if ($expenseCategoryId > 0) {
            $categoryClause = ' AND be.expense_category_id = :expense_category_id';
            $dateParams['expense_category_id'] = $expenseCategoryId;
        }

        $currencyClause = '';
        if (trim($currency) !== '') {
            $currencyClause = ' AND be.currency = :expense_currency';
            $dateParams['expense_currency'] = strtoupper(trim($currency));
        }

        $query = 'SELECT
                register_rows.id,
                register_rows.expense_date,
                register_rows.branch_id,
                register_rows.branch_name,
                register_rows.category_name,
                register_rows.title,
                register_rows.amount,
                register_rows.currency,
                register_rows.payment_method_label,
                register_rows.paid_to_name,
                register_rows.reference_number,
                register_rows.notes,
                register_rows.expense_status,
                register_rows.entered_by_name
             FROM (
                SELECT
                    CONCAT("expense-", be.id) AS id,
                    be.expense_date,
                    be.branch_id,
                    br.name AS branch_name,
                    ec.name AS category_name,
                    be.title,
                    be.amount,
                    be.currency,
                    COALESCE(pm.name, REPLACE(be.payment_method, "_", " ")) AS payment_method_label,
                    be.paid_to_name,
                    be.reference_number,
                    be.notes,
                    be.expense_status,
                    COALESCE(u.name, u.username, u.email, "User") AS entered_by_name
                 FROM business_expenses be
                 INNER JOIN branches br ON br.id = be.branch_id
                 INNER JOIN expense_categories ec ON ec.id = be.expense_category_id
                 LEFT JOIN payment_methods pm ON pm.code = be.payment_method
                 LEFT JOIN users u ON u.id = be.entered_by_user_id
                 WHERE be.branch_id ' . $clause . '
                   AND be.expense_status IN ("active", "posted")' . $expenseWindow . $currencyClause . $categoryClause;
        $queryParams = $dateParams;

        if ($expenseCategoryId <= 0 && $this->salaryExpenseTablesExist()) {
            [$salaryClause, $salaryParams] = $this->branchScope($branchIds, 'salary_branch_');
            $salaryWindow = $this->bookingDateWindow('r.record_date', $dateFrom, $dateTo, $salaryParams, 'salary_');
            $salaryCurrencyClause = '';
            if (trim($currency) !== '') {
                $salaryCurrencyClause = ' AND r.currency = :salary_expense_currency';
                $salaryParams['salary_expense_currency'] = strtoupper(trim($currency));
            }

            $query .= '
                UNION ALL
                SELECT
                    CONCAT("salary-", r.id) AS id,
                    r.record_date AS expense_date,
                    r.branch_id,
                    br.name AS branch_name,
                    "Salary" AS category_name,
                    CONCAT("Salary for ", bs.name, " - ", DATE_FORMAT(r.salary_month, "%b %Y")) AS title,
                    r.gross_debit_amount AS amount,
                    r.currency,
                    "Salary Record" AS payment_method_label,
                    bs.name AS paid_to_name,
                    CONCAT("SAL-", r.id) AS reference_number,
                    COALESCE(NULLIF(r.notes, ""), "Employee salary recorded.") AS notes,
                    "posted" AS expense_status,
                    COALESCE(u.name, u.username, u.email, bs.name, "User") AS entered_by_name
                 FROM employee_salary_records r
                 INNER JOIN branches br ON br.id = r.branch_id
                 INNER JOIN business_sources bs ON bs.id = r.business_source_id
                 LEFT JOIN users u ON u.id = r.entered_by_user_id
                 WHERE r.branch_id ' . $salaryClause . '
                   AND ' . $this->nonVoidedSalaryStatusClause('r') . $salaryWindow . $salaryCurrencyClause;
            $queryParams = array_merge($queryParams, $salaryParams);
        }

        $query .= '
             ) AS register_rows
             ORDER BY register_rows.expense_date DESC, register_rows.id DESC';

        return $this->fetchRows($query, $queryParams);
    }

    public function accountingIntegrityChecks(array $branchIds): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $rows = [];

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "critical" AS severity,
                "Unbalanced journal entry" AS check_name,
                br.name AS branch_name,
                je.booking_reference,
                COALESCE(je.source_reference, CAST(je.id AS CHAR)) AS document_reference,
                je.currency,
                ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS expected_amount,
                ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS actual_amount,
                ROUND(COALESCE(SUM(jel.debit_amount), 0) - COALESCE(SUM(jel.credit_amount), 0), 2) AS difference_amount,
                CONCAT("Journal entry #", je.id, " debits and credits do not match.") AS detail_note
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN branches br ON br.id = je.branch_id
             WHERE je.branch_id ' . $clause . '
             GROUP BY je.id, br.name, je.booking_reference, je.source_reference, je.currency
             HAVING expected_amount <> actual_amount',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Receivable without matching booking/service" AS check_name,
                br.name AS branch_name,
                cri.booking_reference,
                COALESCE(cri.service_line_reference, CAST(cri.id AS CHAR)) AS document_reference,
                cri.currency,
                NULL AS expected_amount,
                cri.outstanding_amount AS actual_amount,
                NULL AS difference_amount,
                CASE
                    WHEN b.id IS NULL THEN "Receivable booking reference does not match a booking."
                    ELSE "Receivable service line reference does not match an active booking service."
                END AS detail_note
             FROM customer_receivable_items cri
             INNER JOIN branches br ON br.id = cri.branch_id
             LEFT JOIN bookings b ON b.booking_reference = cri.booking_reference
             LEFT JOIN booking_services bs
                ON bs.booking_id = b.id
               AND bs.line_reference = cri.service_line_reference
             WHERE cri.branch_id ' . $clause . '
               AND cri.status <> "cancelled"
               AND (b.id IS NULL OR (cri.service_line_reference IS NOT NULL AND cri.service_line_reference <> "" AND bs.id IS NULL))',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Supplier obligation without matching booking/service" AS check_name,
                br.name AS branch_name,
                so.booking_reference,
                COALESCE(so.service_line_reference, CAST(so.id AS CHAR)) AS document_reference,
                so.currency,
                NULL AS expected_amount,
                so.net_payable_amount AS actual_amount,
                NULL AS difference_amount,
                CASE
                    WHEN b.id IS NULL THEN "Supplier obligation booking reference does not match a booking."
                    ELSE "Supplier obligation service line reference does not match an active booking service."
                END AS detail_note
             FROM supplier_obligations so
             INNER JOIN branches br ON br.id = so.branch_id
             LEFT JOIN bookings b ON b.booking_reference = so.booking_reference
             LEFT JOIN booking_services bs
                ON bs.booking_id = b.id
               AND bs.line_reference = so.service_line_reference
             WHERE so.branch_id ' . $clause . '
               AND so.status <> "cancelled"
               AND (b.id IS NULL OR (so.service_line_reference IS NOT NULL AND so.service_line_reference <> "" AND bs.id IS NULL))',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "critical" AS severity,
                "Negative customer outstanding" AS check_name,
                br.name AS branch_name,
                cri.booking_reference,
                COALESCE(cri.service_line_reference, CAST(cri.id AS CHAR)) AS document_reference,
                cri.currency,
                0 AS expected_amount,
                cri.outstanding_amount AS actual_amount,
                cri.outstanding_amount AS difference_amount,
                "Customer receivable outstanding amount is below zero." AS detail_note
             FROM customer_receivable_items cri
             INNER JOIN branches br ON br.id = cri.branch_id
             WHERE cri.branch_id ' . $clause . '
               AND cri.outstanding_amount < -0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "critical" AS severity,
                "Negative supplier payable" AS check_name,
                br.name AS branch_name,
                so.booking_reference,
                COALESCE(so.service_line_reference, CAST(so.id AS CHAR)) AS document_reference,
                so.currency,
                0 AS expected_amount,
                so.net_payable_amount AS actual_amount,
                so.net_payable_amount AS difference_amount,
                "Supplier obligation net payable amount is below zero." AS detail_note
             FROM supplier_obligations so
             INNER JOIN branches br ON br.id = so.branch_id
             WHERE so.branch_id ' . $clause . '
               AND so.net_payable_amount < -0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Customer receipt allocation mismatch" AS check_name,
                br.name AS branch_name,
                cr.booking_reference,
                cr.receipt_no AS document_reference,
                cr.currency,
                cr.received_amount AS expected_amount,
                cr.allocated_amount + cr.unallocated_amount AS actual_amount,
                (cr.received_amount - (cr.allocated_amount + cr.unallocated_amount)) AS difference_amount,
                "Receipt allocated plus unallocated does not equal received amount." AS detail_note
             FROM customer_receipts cr
             INNER JOIN branches br ON br.id = cr.branch_id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status <> "void"
               AND ABS(cr.received_amount - (cr.allocated_amount + cr.unallocated_amount)) > 0.005',
            $params
        ));

        $supplierPaymentConvertedAdvanceValue = $this->columnExists('supplier_payments', 'converted_advance_amount')
            ? 'sp.converted_advance_amount'
            : '0';

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Supplier payment allocation mismatch" AS check_name,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no AS document_reference,
                sp.currency,
                sp.paid_amount AS expected_amount,
                sp.allocated_amount + sp.unallocated_amount + ' . $supplierPaymentConvertedAdvanceValue . ' AS actual_amount,
                (sp.paid_amount - (sp.allocated_amount + sp.unallocated_amount + ' . $supplierPaymentConvertedAdvanceValue . ')) AS difference_amount,
                "Supplier payment allocated plus unallocated plus converted advance does not equal paid amount." AS detail_note
             FROM supplier_payments sp
             INNER JOIN branches br ON br.id = sp.branch_id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status <> "void"
               AND ABS(sp.paid_amount - (sp.allocated_amount + sp.unallocated_amount + ' . $supplierPaymentConvertedAdvanceValue . ')) > 0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Supplier advance balance mismatch" AS check_name,
                br.name AS branch_name,
                NULL AS booking_reference,
                COALESCE(sa.reference_no, CAST(sa.id AS CHAR)) AS document_reference,
                sa.currency,
                sa.deposit_amount AS expected_amount,
                sa.available_amount AS actual_amount,
                (sa.deposit_amount - sa.available_amount) AS difference_amount,
                "Supplier advance available amount is negative or greater than deposit amount." AS detail_note
             FROM supplier_advances sa
             INNER JOIN branches br ON br.id = sa.branch_id
             WHERE sa.branch_id ' . $clause . '
               AND (sa.available_amount < -0.005 OR sa.available_amount - sa.deposit_amount > 0.005)',
            $params
        ));

        return $rows;
    }

    public function receivableAging(
        array $branchIds,
        string $asOfDate,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $businessSourceId = 0,
        string $customerName = '',
        string $bookingReference = ''
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $params, 'aging_');
        $filters = [
            'cri.outstanding_amount > 0',
            'cri.status IN ("open", "partially_paid")',
            'b.branch_id ' . $clause,
        ];
        if ($businessSourceId > 0) {
            $filters[] = 'b.business_source_id = :business_source_id';
            $params['business_source_id'] = $businessSourceId;
        }
        if ($customerName !== '') {
            $filters[] = 'COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") COLLATE utf8mb4_unicode_ci = :customer_name';
            $params['customer_name'] = $customerName;
        }
        if ($bookingReference !== '') {
            $filters[] = 'cri.booking_reference LIKE :booking_reference';
            $params['booking_reference'] = '%' . $bookingReference . '%';
        }
        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_date,
                    cri.booking_reference,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS lead_traveler_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    cri.service_line_reference,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "") AS route,
                    cri.currency,
                    cri.due_amount,
                    cri.allocated_amount,
                    cri.outstanding_amount,
                    cri.due_date,
                    DATEDIFF(:as_of_date_calc, COALESCE(cri.due_date, :as_of_date_fallback)) AS overdue_days
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = cri.service_line_reference
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                WHERE ' . implode(' AND ', $filters) . $dateSql . '
                ORDER BY
                    CASE
                        WHEN overdue_days > 90 THEN 1
                        WHEN overdue_days > 60 THEN 2
                        WHEN overdue_days > 30 THEN 3
                        WHEN overdue_days > 0 THEN 4
                        ELSE 5
                    END ASC,
                    cri.due_date IS NULL ASC,
                    cri.due_date ASC,
                    cri.booking_reference ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            'as_of_date_calc' => $asOfDate,
            'as_of_date_fallback' => $asOfDate,
        ], $params));

        return $statement->fetchAll() ?: [];
    }

    public function payableAging(
        array $branchIds,
        string $asOfDate,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $bookingReference = '',
        int $supplierId = 0,
        int $businessSourceId = 0
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $params, 'aging_');
        $bookingReferenceSql = '';
        if ($bookingReference !== '') {
            $bookingReferenceSql = ' AND so.booking_reference LIKE :booking_reference';
            $params['booking_reference'] = '%' . $bookingReference . '%';
        }
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND so.supplier_id = :payable_aging_supplier_id';
            $params['payable_aging_supplier_id'] = $supplierId;
        }
        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND b.business_source_id = :payable_aging_business_source_id';
            $params['payable_aging_business_source_id'] = $businessSourceId;
        }
        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_date,
                    so.booking_reference,
                    s.name AS supplier_name,
                    so.service_line_reference,
                    so.currency,
                    so.gross_amount,
                    so.advance_applied_amount,
                    so.net_payable_amount,
                    so.due_date,
                    DATEDIFF(:as_of_date_calc, COALESCE(so.due_date, :as_of_date_fallback)) AS overdue_days
                FROM supplier_obligations so
                INNER JOIN bookings b ON b.booking_reference = so.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                INNER JOIN suppliers s ON s.id = so.supplier_id
                WHERE so.net_payable_amount > 0
                  AND so.status IN ("open", "partially_covered")
                  AND b.branch_id ' . $clause . $bookingReferenceSql . $supplierSql . $accountSql . $dateSql . '
                ORDER BY
                    CASE
                        WHEN overdue_days > 90 THEN 0
                        WHEN overdue_days > 60 THEN 1
                        WHEN overdue_days > 30 THEN 2
                        WHEN overdue_days > 0 THEN 3
                        ELSE 4
                    END ASC,
                    so.due_date IS NULL,
                    so.due_date ASC,
                    so.booking_reference ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            'as_of_date_calc' => $asOfDate,
            'as_of_date_fallback' => $asOfDate,
        ], $params));

        return $statement->fetchAll() ?: [];
    }

    public function serviceProfit(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        int $supplierId = 0,
        int $businessSourceId = 0,
        string $customerName = '',
        string $bookingReference = '',
        string $currency = ''
    ): array
    {
        [$outerClause, $params] = $this->branchScope($branchIds, 'service_profit_outer_branch_');
        [$baseClause, $baseBranchParams] = $this->branchScope($branchIds, 'service_profit_base_branch_');
        [$reissueClause, $reissueBranchParams] = $this->branchScope($branchIds, 'service_profit_reissue_branch_');
        $params = array_merge($params, $baseBranchParams, $reissueBranchParams);
        $dateSql = $this->bookingDateWindow('profit_rows.booking_date', $dateFrom, $dateTo, $params);
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND profit_rows.supplier_id = :service_profit_supplier_id';
            $params['service_profit_supplier_id'] = $supplierId;
        }
        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND profit_rows.business_source_id = :service_profit_business_source_id';
            $params['service_profit_business_source_id'] = $businessSourceId;
        }
        $customerSql = '';
        if ($customerName !== '') {
            $customerSql = ' AND profit_rows.customer_name COLLATE utf8mb4_unicode_ci = :service_profit_customer_name';
            $params['service_profit_customer_name'] = $customerName;
        }
        $bookingSql = '';
        if ($bookingReference !== '') {
            $bookingSql = ' AND profit_rows.booking_reference LIKE :service_profit_booking_reference';
            $params['service_profit_booking_reference'] = '%' . $bookingReference . '%';
        }
        $currencySql = '';
        if ($currency !== '') {
            $currencySql = ' AND profit_rows.currency = :service_profit_currency';
            $params['service_profit_currency'] = $currency;
        }
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableFormula = $this->servicePayableFormula('bs');
        $payableInInvoiceCurrencyFormula = $this->servicePayableInInvoiceCurrencyFormula('bs', 'so');
        $reissueCustomerMirrorExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.customer_mirror_increment")), "") AS DECIMAL(18,2)), 0.00)';
        $reissueSupplierMirrorExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.supplier_mirror_increment")), "") AS DECIMAL(18,2)), 0.00)';
        $reissueAgencyMirrorExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.agency_mirror_increment")), "") AS DECIMAL(18,2)), 0.00)';
        $reissueSupplierCurrencyExpression = 'COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.supplier_currency")), ""), COALESCE(NULLIF(bs.cost_currency, ""), bs.currency))';
        $reissueSupplierCostDifferenceExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.supplier_cost_difference_amount")), "") AS DECIMAL(18,2)), 0.00)';
        $reissueCustomerDeltaExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.customer_delta")), "") AS DECIMAL(18,2)), ROUND(COALESCE(e.fare_difference_amount, 0) + COALESCE(e.service_fee_amount, 0), 2))';
        $reissueAggregateSql = '
            SELECT
                e.booking_service_id,
                SUM(' . $reissueCustomerMirrorExpression . ') AS customer_mirror_total,
                SUM(' . $reissueSupplierMirrorExpression . ') AS supplier_mirror_total,
                SUM(' . $reissueAgencyMirrorExpression . ') AS agency_mirror_total
            FROM booking_service_events e
            WHERE e.event_type = "reissue"
              AND e.event_status = "posted"
            GROUP BY e.booking_service_id
        ';
        $latestCancellationSql = '
            SELECT
                cancel_source.booking_service_id,
                cancel_source.id AS cancellation_event_id,
                COALESCE(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_source.payload_json, "$.customer_final_charge_amount")), "") AS DECIMAL(18,2)),
                    0.00
                ) AS cancellation_customer_final_charge_amount,
                COALESCE(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_source.payload_json, "$.customer_refund_basis")), "") AS DECIMAL(18,2)),
                    0.00
                ) AS cancellation_customer_refund_basis,
                COALESCE(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_source.payload_json, "$.supplier_penalty_source_amount")), "") AS DECIMAL(18,2)),
                    0.00
                ) AS cancellation_supplier_penalty_source_amount,
                COALESCE(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_source.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)),
                    0.00
                ) AS cancellation_supplier_penalty_amount,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_source.payload_json, "$.supplier_penalty_currency")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_source.payload_json, "$.expected_supplier_refund_currency")), ""),
                    NULLIF(cancel_source.currency, ""),
                    "PKR"
                ) AS cancellation_supplier_penalty_currency
            FROM booking_service_events cancel_source
            INNER JOIN (
                SELECT
                    booking_service_id,
                    MAX(id) AS latest_cancel_event_id
                FROM booking_service_events
                WHERE event_type = "cancel"
                  AND event_status = "posted"
                GROUP BY booking_service_id
            ) cancel_lookup
                ON cancel_lookup.latest_cancel_event_id = cancel_source.id
        ';
        $baseReceivableExpression = 'GREATEST(COALESCE(cri.due_amount, ' . $receivableFormula . ') - COALESCE(reissue_agg.customer_mirror_total, 0), 0.00)';
        $basePayableOriginalExpression = 'GREATEST(COALESCE(so.gross_amount, ' . $payableFormula . ') - COALESCE(reissue_agg.supplier_mirror_total, 0), 0.00)';
        $basePayableAdjustmentExpression = 'CASE
            WHEN COALESCE(NULLIF(so.currency, ""), NULLIF(bs.cost_currency, ""), bs.currency) = bs.currency THEN
                COALESCE(reissue_agg.supplier_mirror_total, 0)
            WHEN COALESCE(bs.pricing_exchange_rate, 0) > 0 THEN
                ROUND(COALESCE(reissue_agg.supplier_mirror_total, 0) * bs.pricing_exchange_rate, 2)
            ELSE
                GREATEST(
                    COALESCE(reissue_agg.customer_mirror_total, 0) - COALESCE(reissue_agg.agency_mirror_total, 0),
                    0.00
                )
        END';
        $basePayableExpression = 'GREATEST(' . $payableInInvoiceCurrencyFormula . ' - ' . $basePayableAdjustmentExpression . ', 0.00)';
        $sql = 'SELECT
                    profit_rows.branch_id,
                    profit_rows.branch_name,
                    profit_rows.booking_reference,
                    profit_rows.booking_date,
                    profit_rows.business_source_name,
                    profit_rows.customer_name,
                    profit_rows.line_reference,
                    profit_rows.service_type,
                    profit_rows.supplier_id,
                    profit_rows.supplier_name,
                    profit_rows.service_status,
                    profit_rows.currency,
                    profit_rows.cost_currency,
                    profit_rows.pricing_exchange_rate,
                    profit_rows.sale_price,
                    profit_rows.purchase_cost,
                    profit_rows.taxes,
                    profit_rows.vat,
                    profit_rows.commission,
                    profit_rows.service_charge,
                    profit_rows.discount_amount,
                    profit_rows.loss_reason,
                    profit_rows.receivable_amount,
                    profit_rows.payable_currency,
                    profit_rows.payable_original_amount,
                    profit_rows.payable_amount,
                    profit_rows.cancellation_event_id,
                    profit_rows.cancellation_customer_final_charge_amount,
                    profit_rows.cancellation_customer_refund_basis,
                    profit_rows.cancellation_supplier_penalty_source_amount,
                    profit_rows.cancellation_supplier_penalty_amount,
                    profit_rows.cancellation_supplier_penalty_currency
                FROM (
                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.booking_reference,
                        b.booking_date,
                        b.business_source_id,
                        COALESCE(src.name, "Unassigned Account") AS business_source_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                        bs.line_reference,
                        bs.service_type,
                        bs.supplier_id,
                        COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                        bs.service_status,
                        bs.currency,
                        bs.cost_currency,
                        bs.pricing_exchange_rate,
                        bs.sale_price,
                        bs.purchase_cost,
                        bs.taxes,
                        bs.vat,
                        bs.commission,
                        GREATEST(COALESCE(bs.service_charge, 0) - COALESCE(reissue_agg.agency_mirror_total, 0), 0.00) AS service_charge,
                        bs.discount_amount,
                        bs.loss_reason,
                        ' . $baseReceivableExpression . ' AS receivable_amount,
                        COALESCE(NULLIF(so.currency, ""), NULLIF(bs.cost_currency, ""), bs.currency) AS payable_currency,
                        ' . $basePayableOriginalExpression . ' AS payable_original_amount,
                        ' . $basePayableExpression . ' AS payable_amount,
                        COALESCE(cancel_event.cancellation_event_id, 0) AS cancellation_event_id,
                        COALESCE(cancel_event.cancellation_customer_final_charge_amount, 0.00) AS cancellation_customer_final_charge_amount,
                        COALESCE(cancel_event.cancellation_customer_refund_basis, 0.00) AS cancellation_customer_refund_basis,
                        COALESCE(cancel_event.cancellation_supplier_penalty_source_amount, 0.00) AS cancellation_supplier_penalty_source_amount,
                        COALESCE(cancel_event.cancellation_supplier_penalty_amount, 0.00) AS cancellation_supplier_penalty_amount,
                        COALESCE(cancel_event.cancellation_supplier_penalty_currency, COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)) AS cancellation_supplier_penalty_currency
                    FROM booking_services bs
                    INNER JOIN bookings b ON b.id = bs.booking_id
                    INNER JOIN branches br ON br.id = b.branch_id
                    LEFT JOIN business_sources src ON src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    LEFT JOIN ' . $receivableAggregateSql . ' cri
                        ON cri.booking_reference = b.booking_reference
                       AND cri.service_line_reference = bs.line_reference
                       AND cri.currency = bs.currency
                    LEFT JOIN ' . $payableAggregateSql . ' so
                        ON so.booking_reference = b.booking_reference
                       AND so.service_line_reference = bs.line_reference
                       AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
                    LEFT JOIN (' . $reissueAggregateSql . ') reissue_agg
                        ON reissue_agg.booking_service_id = bs.id
                    LEFT JOIN (' . $latestCancellationSql . ') cancel_event
                        ON cancel_event.booking_service_id = bs.id
                    WHERE b.branch_id ' . $baseClause . '
                      AND bs.is_active = 1

                    UNION ALL

                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.booking_reference,
                        e.event_date AS booking_date,
                        b.business_source_id,
                        COALESCE(src.name, "Unassigned Account") AS business_source_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                        bs.line_reference,
                        CONCAT(bs.service_type, " reissue") AS service_type,
                        bs.supplier_id,
                        COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                        bs.service_status,
                        e.currency,
                        ' . $reissueSupplierCurrencyExpression . ' AS cost_currency,
                        bs.pricing_exchange_rate,
                        0.00 AS sale_price,
                        0.00 AS purchase_cost,
                        0.00 AS taxes,
                        0.00 AS vat,
                        0.00 AS commission,
                        COALESCE(e.service_fee_amount, 0) AS service_charge,
                        0.00 AS discount_amount,
                        "" AS loss_reason,
                        ' . $reissueCustomerDeltaExpression . ' AS receivable_amount,
                        ' . $reissueSupplierCurrencyExpression . ' AS payable_currency,
                        ' . $reissueSupplierCostDifferenceExpression . ' AS payable_original_amount,
                        COALESCE(e.fare_difference_amount, 0) AS payable_amount,
                        0 AS cancellation_event_id,
                        0.00 AS cancellation_customer_final_charge_amount,
                        0.00 AS cancellation_customer_refund_basis,
                        0.00 AS cancellation_supplier_penalty_source_amount,
                        0.00 AS cancellation_supplier_penalty_amount,
                        "" AS cancellation_supplier_penalty_currency
                    FROM booking_service_events e
                    INNER JOIN booking_services bs ON bs.id = e.booking_service_id
                    INNER JOIN bookings b ON b.id = e.booking_id
                    INNER JOIN branches br ON br.id = b.branch_id
                    LEFT JOIN business_sources src ON src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    WHERE b.branch_id ' . $reissueClause . '
                      AND bs.is_active = 1
                      AND e.event_type = "reissue"
                      AND e.event_status = "posted"
                      AND (
                          COALESCE(e.fare_difference_amount, 0) > 0.005
                          OR COALESCE(e.service_fee_amount, 0) > 0.005
                      )
                ) AS profit_rows
                WHERE profit_rows.branch_id ' . $outerClause
                . $supplierSql
                . $accountSql
                . $customerSql
                . $bookingSql
                . $currencySql
                . $dateSql .
                ' ORDER BY profit_rows.branch_name ASC, profit_rows.booking_date DESC, profit_rows.booking_reference ASC, profit_rows.line_reference ASC, profit_rows.service_type ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function branchPerformance(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        [$customerRefundClause, $customerRefundParams] = $this->branchScope($branchIds, 'refund_branch_');
        [$supplierRefundClause, $supplierRefundParams] = $this->branchScope($branchIds, 'supplier_refund_branch_');
        $bookingParams = $params;
        $receiptParams = $params;
        $supplierPaymentParams = $params;
        $receivableParams = $params;
        $payableParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $receiptParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $customerRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $customerRefundParams, 'refund_');
        $supplierRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $supplierRefundParams, 'supplier_refund_');
        $receivableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $receivableParams);
        $payableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $payableParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $payableInInvoiceCurrencyFormula = $this->servicePayableInInvoiceCurrencyFormula('bs', 'so');

        $bookingRows = $this->fetchRows(
            'SELECT b.branch_id, br.name AS branch_name, COUNT(*) AS booking_count
             FROM bookings b
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE b.branch_id ' . $clause . $bookingWindow . '
             GROUP BY b.branch_id, br.name',
            $bookingParams
        );

        $serviceRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.currency,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS profit_snapshot
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.id AS service_id,
                    bs.currency,
                    COALESCE(cri.due_amount, 0) AS receivable_amount,
                    ' . $payableInInvoiceCurrencyFormula . ' AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
             GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.currency',
            $bookingParams
        );

        $receiptRows = $this->fetchRows(
            'SELECT
                receipt_rows.branch_id,
                receipt_rows.branch_name,
                receipt_rows.currency,
                GREATEST(receipt_rows.total_received - COALESCE(refund_rows.total_refunded, 0), 0) AS total_received
             FROM (
                SELECT
                    cr.branch_id,
                    br.name AS branch_name,
                    cr.currency,
                    SUM(cr.received_amount) AS total_received
                 FROM customer_receipts cr
                 INNER JOIN branches br ON br.id = cr.branch_id
                 WHERE cr.branch_id ' . $clause . '
                   AND cr.status <> "void"' . $receiptWindow . '
                 GROUP BY cr.branch_id, br.name, cr.currency
             ) AS receipt_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.customer_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $customerRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $customerRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = receipt_rows.branch_id
               AND refund_rows.currency = receipt_rows.currency',
            array_merge($receiptParams, $customerRefundParams)
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                payment_rows.branch_id,
                payment_rows.branch_name,
                payment_rows.currency,
                GREATEST(payment_rows.total_supplier_paid - COALESCE(refund_rows.total_refunded, 0), 0) AS total_supplier_paid
             FROM (
                SELECT
                    sp.branch_id,
                    br.name AS branch_name,
                    sp.currency,
                    SUM(sp.paid_amount) AS total_supplier_paid
                 FROM supplier_payments sp
                 INNER JOIN branches br ON br.id = sp.branch_id
                 WHERE sp.branch_id ' . $clause . '
                   AND sp.status <> "void"' . $supplierPaymentWindow . '
                 GROUP BY sp.branch_id, br.name, sp.currency
             ) AS payment_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.supplier_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $supplierRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $supplierRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = payment_rows.branch_id
               AND refund_rows.currency = payment_rows.currency',
            array_merge($supplierPaymentParams, $supplierRefundParams)
        );

        $receivableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                cri.currency,
                SUM(cri.outstanding_amount) AS customer_outstanding
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE cri.outstanding_amount > 0
               AND b.branch_id ' . $clause . $receivableBookingWindow . '
             GROUP BY b.branch_id, br.name, cri.currency',
            $receivableParams
        );

        $payableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                so.currency,
                SUM(so.net_payable_amount) AS supplier_outstanding
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE so.net_payable_amount > 0
               AND b.branch_id ' . $clause . $payableBookingWindow . '
             GROUP BY b.branch_id, br.name, so.currency',
            $payableParams
        );

        $expenseRows = $this->expenseSummary($branchIds, $dateFrom, $dateTo);

        return [
            'bookings' => $bookingRows,
            'services' => $serviceRows,
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
            'receivables' => $receivableRows,
            'payables' => $payableRows,
            'expenses' => $expenseRows,
        ];
    }

    public function customerOutstanding(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $currency = '',
        int $businessSourceId = 0,
        string $customerName = ''
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $filters = [
            'cri.outstanding_amount > 0',
            'b.branch_id ' . $clause,
        ];
        if ($dateFrom !== null) {
            $filters[] = 'b.booking_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $filters[] = 'b.booking_date <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($currency !== '') {
            $filters[] = 'cri.currency = :currency';
            $params['currency'] = $currency;
        }
        if ($businessSourceId > 0) {
            $filters[] = 'b.business_source_id = :business_source_id';
            $params['business_source_id'] = $businessSourceId;
        }
        if ($customerName !== '') {
            $filters[] = 'COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") COLLATE utf8mb4_unicode_ci = :customer_name';
            $params['customer_name'] = $customerName;
        }

        $customerReceiptPurposeFilter = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? 'AND COALESCE(cr.receipt_purpose, "booking_payment") <> "customer_advance"'
            : '';

        $sql = 'SELECT
                    cri.id AS receivable_id,
                    b.id AS booking_id,
                    b.lead_traveler_id,
                    b.branch_id,
                    br.name AS branch_name,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    b.booking_reference,
                    b.booking_date,
                    COALESCE(cri.due_date, b.due_date) AS due_date,
                    cri.service_line_reference,
                    COALESCE(NULLIF(bs.service_type, ""), "Service") AS service_type,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS lead_traveler_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    COALESCE(NULLIF(sat.ticket_number, ""), NULLIF(so.reference_number, ""), NULLIF(sh.confirmation_number, ""), NULLIF(sto.confirmation_number, ""), NULLIF(sv.application_reference, ""), cri.service_line_reference) AS ticket_reference,
                    COALESCE(NULLIF(sat.pnr, ""), "") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "") AS route,
                    COALESCE(sat.departure_date, su.departure_date, sh.check_in_date, st.pickup_date, sto.start_date, so.service_date) AS travel_date,
                    COALESCE(
                        NULLIF(bs.remarks, ""),
                        NULLIF(sat.ticket_remarks, ""),
                        NULLIF(sv.remarks, ""),
                        NULLIF(su.remarks, ""),
                        NULLIF(sh.remarks, ""),
                        NULLIF(st.route_notes, ""),
                        NULLIF(sto.remarks, ""),
                        NULLIF(so.remarks, ""),
                        CONCAT(UCASE(LEFT(COALESCE(bs.service_type, "service"), 1)), SUBSTRING(COALESCE(bs.service_type, "service"), 2), " Service")
                    ) AS description,
                    cri.currency,
                    COALESCE(NULLIF(bs.final_sale_price, 0), cri.due_amount) AS original_invoice_amount,
                    cri.due_amount AS total_due,
                    cri.allocated_amount AS total_allocated,
                    cri.outstanding_amount AS total_outstanding,
                    COALESCE(receipt_summary.customer_received_amount, 0) AS account_customer_received_amount,
                    COALESCE(receipt_summary.customer_received_date, b.booking_date) AS account_customer_received_date,
                    COALESCE(supplier_payment_summary.supplier_paid_amount, 0) AS account_supplier_paid_amount,
                    COALESCE(supplier_payment_summary.supplier_paid_date, b.booking_date) AS account_supplier_paid_date,
                    COALESCE(refund_summary.customer_paid_amount, 0) AS refund_customer_paid_amount,
                    COALESCE(refund_summary.customer_penalty_amount, 0) AS refund_customer_penalty_amount,
                    COALESCE(refund_summary.customer_refund_expected, 0) AS refund_customer_refund_expected,
                    COALESCE(NULLIF(refund_summary.customer_refund_currency, ""), cri.currency) AS refund_customer_refund_currency,
                    COALESCE(refund_summary.customer_refund_posted, 0) AS refund_customer_refund_posted,
                    COALESCE(refund_summary.supplier_penalty_amount, 0) AS refund_supplier_penalty_amount,
                    COALESCE(refund_summary.supplier_refund_expected, 0) AS refund_supplier_refund_expected,
                    COALESCE(refund_summary.supplier_refund_received, 0) AS refund_supplier_refund_received,
                    COALESCE(refund_summary.refund_event_date, b.booking_date) AS refund_event_date,
                    COALESCE(refund_summary.refund_event_count, 0) AS refund_event_count
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = cri.service_line_reference
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                LEFT JOIN service_visa sv ON sv.booking_service_id = bs.id
                LEFT JOIN service_umrah su ON su.booking_service_id = bs.id
                LEFT JOIN service_hotel sh ON sh.booking_service_id = bs.id
                LEFT JOIN service_transport st ON st.booking_service_id = bs.id
                LEFT JOIN service_tour sto ON sto.booking_service_id = bs.id
                LEFT JOIN service_other so ON so.booking_service_id = bs.id
                LEFT JOIN (
                    SELECT
                        cr.booking_reference,
                        cr.currency,
                        ROUND(SUM(cr.received_amount), 2) AS customer_received_amount,
                        MAX(cr.receipt_date) AS customer_received_date
                    FROM customer_receipts cr
                    WHERE cr.status <> "void"
                      ' . $customerReceiptPurposeFilter . '
                    GROUP BY cr.booking_reference, cr.currency
                ) receipt_summary
                    ON receipt_summary.booking_reference = cri.booking_reference
                   AND receipt_summary.currency = cri.currency
                LEFT JOIN (
                    SELECT
                        so.booking_reference,
                        so.service_line_reference,
                        so.currency,
                        ROUND(SUM(spa.allocated_amount), 2) AS supplier_paid_amount,
                        MAX(sp.payment_date) AS supplier_paid_date
                    FROM supplier_payment_allocations spa
                    INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
                    INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
                    WHERE sp.status <> "void"
                      AND spa.allocated_amount > 0.005
                    GROUP BY so.booking_reference, so.service_line_reference, so.currency
                ) supplier_payment_summary
                    ON supplier_payment_summary.booking_reference = cri.booking_reference
                   AND supplier_payment_summary.service_line_reference = cri.service_line_reference
                   AND supplier_payment_summary.currency = cri.currency
                LEFT JOIN (
                    SELECT
                        bse.booking_id,
                        bse.booking_service_id,
                        COUNT(*) AS refund_event_count,
                        MAX(bse.event_date) AS refund_event_date,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_refund_basis")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.released_customer_credit")), "") AS DECIMAL(18,2)) + COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                ),
                                bse.customer_credit_amount + COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                ),
                                0
                            )
                            ELSE 0
                        END) AS customer_paid_amount,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                bse.penalty_amount,
                                0
                            )
                            ELSE 0
                        END) AS customer_penalty_amount,
                        MAX(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.available_customer_refund_credit_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.target_customer_refund_credit_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_refund_basis_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.settlement_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_currency")), ""),
                                bse.currency
                            )
                            WHEN bse.event_type = "refund" THEN COALESCE(
                                NULLIF(refund_detail.refund_currency, ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.refund_detail.refund_currency")), ""),
                                bse.currency
                            )
                            ELSE NULL
                        END) AS customer_refund_currency,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN GREATEST(
                                COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_source_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                                    0
                                ) - COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_source_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                ) + COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.agency_fee_refund_amount")), "") AS DECIMAL(18,2)),
                                    0
                                ),
                                0
                            )
                            ELSE 0
                        END) AS customer_refund_expected,
                        SUM(CASE
                            WHEN bse.event_type = "refund" THEN COALESCE(
                                refund_detail.refund_source_amount,
                                COALESCE(bse.customer_refund_amount, 0)
                            )
                            ELSE 0
                        END) AS customer_refund_posted,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_source_amount")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)),
                                0
                            )
                            ELSE 0
                        END) AS supplier_penalty_amount,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_source_amount")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                                bse.supplier_credit_amount,
                                0
                            )
                            ELSE 0
                        END) AS supplier_refund_expected,
                        SUM(CASE
                            WHEN bse.event_type = "refund" THEN COALESCE(bse.supplier_refund_amount, 0)
                            ELSE 0
                        END) AS supplier_refund_received
                    FROM booking_service_events bse
                    LEFT JOIN booking_service_refund_details refund_detail
                        ON refund_detail.service_event_id = bse.id
                    WHERE bse.event_status = "posted"
                      AND bse.event_type IN ("cancel", "refund")
                    GROUP BY bse.booking_id, bse.booking_service_id
                ) refund_summary
                    ON refund_summary.booking_id = b.id
                   AND refund_summary.booking_service_id = bs.id
                WHERE ' . implode(' AND ', $filters) . '
                ORDER BY b.booking_date DESC, b.id DESC, cri.id DESC, bs_src.name ASC, br.name ASC, lead_traveler_name ASC';

        return $this->fetchRows($sql, $params);
    }

    private function customerCreditTransferLedgerRows(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency,
        int $businessSourceId,
        string $customerName,
        string $bookingReference
    ): array {
        [$clause, $branchParams] = $this->branchScope($branchIds, 'credit_transfer_branch_');
        $amountExpression = $this->columnExists('customer_receipt_allocations', 'receivable_amount_allocated')
            ? 'COALESCE(cra.receivable_amount_allocated, cra.allocated_amount)'
            : 'cra.allocated_amount';
        $rows = [];

        foreach (['source', 'target'] as $side) {
            $isSource = $side === 'source';
            $params = $branchParams;
            $filters = ['b.branch_id ' . $clause];
            if ($dateFrom !== null) {
                $filters[] = 'b.booking_date >= :credit_transfer_date_from';
                $params['credit_transfer_date_from'] = $dateFrom;
            }
            if ($dateTo !== null) {
                $filters[] = 'b.booking_date <= :credit_transfer_date_to';
                $params['credit_transfer_date_to'] = $dateTo;
            }
            if ($currency !== '') {
                $filters[] = 'cr.currency = :credit_transfer_currency';
                $params['credit_transfer_currency'] = $currency;
            }
            if ($businessSourceId > 0) {
                $filters[] = 'b.business_source_id = :credit_transfer_business_source_id';
                $params['credit_transfer_business_source_id'] = $businessSourceId;
            }
            if ($customerName !== '') {
                $filters[] = 'COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") COLLATE utf8mb4_unicode_ci = :credit_transfer_customer_name';
                $params['credit_transfer_customer_name'] = $customerName;
            }
            if ($bookingReference !== '') {
                $filters[] = 'b.booking_reference LIKE :credit_transfer_booking_reference';
                $params['credit_transfer_booking_reference'] = '%' . $bookingReference . '%';
            }

            $bookingJoin = $isSource
                ? 'b.booking_reference = cr.booking_reference'
                : 'b.booking_reference = cri.booking_reference';
            $counterpartReference = $isSource ? 'cri.booking_reference' : 'cr.booking_reference';

            $sideRows = $this->fetchRows(
                'SELECT
                    "customer_credit_transfer" AS row_type,
                    "' . $side . '" AS transfer_direction,
                    cra.id AS transfer_allocation_id,
                    ' . $amountExpression . ' AS transfer_amount,
                    cr.booking_reference AS source_booking_reference,
                    cri.booking_reference AS target_booking_reference,
                    ' . $counterpartReference . ' AS counterpart_booking_reference,
                    cr.receipt_no AS source_receipt_no,
                    COALESCE(cr.receipt_purpose, "booking_payment") AS receipt_purpose,
                    b.id AS booking_id,
                    b.lead_traveler_id,
                    b.branch_id,
                    br.name AS branch_name,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    b.booking_reference,
                    DATE(cra.allocated_at) AS booking_date,
                    NULL AS due_date,
                    COALESCE(bs.line_reference, cri.service_line_reference, "") AS service_line_reference,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS lead_traveler_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    COALESCE(NULLIF(sat.pnr, ""), "") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "") AS route,
                    cr.currency
                 FROM customer_receipt_allocations cra
                 INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id AND cr.status <> "void"
                 INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
                 INNER JOIN bookings b ON ' . $bookingJoin . '
                 INNER JOIN branches br ON br.id = b.branch_id
                 LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                 LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                 LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                 LEFT JOIN booking_services bs ON bs.booking_id = b.id
                    AND bs.id = (
                        SELECT MIN(bs_lookup.id)
                        FROM booking_services bs_lookup
                        WHERE bs_lookup.booking_id = b.id
                          AND ("' . $side . '" = "source" OR bs_lookup.line_reference = cri.service_line_reference)
                    )
                 LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                 LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                 WHERE ' . implode(' AND ', $filters) . '
                   AND cr.booking_reference <> cri.booking_reference
                   AND ' . $amountExpression . ' > 0.005
                 ORDER BY cra.allocated_at ASC, cra.id ASC',
                $params
            );
            array_push($rows, ...$sideRows);
        }

        return $rows;
    }

    public function customerLedger(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $currency = '',
        int $businessSourceId = 0,
        string $customerName = '',
        string $bookingReference = ''
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $filters = [
            'b.branch_id ' . $clause,
        ];
        if ($dateFrom !== null) {
            $filters[] = 'b.booking_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $filters[] = 'b.booking_date <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($currency !== '') {
            $filters[] = 'cri.currency = :currency';
            $params['currency'] = $currency;
        }
        if ($businessSourceId > 0) {
            $filters[] = 'b.business_source_id = :business_source_id';
            $params['business_source_id'] = $businessSourceId;
        }
        if ($customerName !== '') {
            $filters[] = 'COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") COLLATE utf8mb4_unicode_ci = :customer_name';
            $params['customer_name'] = $customerName;
        }
        if ($bookingReference !== '') {
            $filters[] = 'b.booking_reference LIKE :booking_reference';
            $params['booking_reference'] = '%' . $bookingReference . '%';
        }

        $customerReceiptPurposeFilter = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? 'AND COALESCE(cr.receipt_purpose, "booking_payment") <> "customer_advance"'
            : '';

        $sql = 'SELECT
                    cri.id AS receivable_id,
                    b.id AS booking_id,
                    b.lead_traveler_id,
                    b.branch_id,
                    br.name AS branch_name,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    b.booking_reference,
                    b.booking_date,
                    COALESCE(cri.due_date, b.due_date) AS due_date,
                    cri.service_line_reference,
                    COALESCE(NULLIF(bs.service_type, ""), "Service") AS service_type,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS lead_traveler_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    COALESCE(NULLIF(sat.ticket_number, ""), NULLIF(so.reference_number, ""), NULLIF(sh.confirmation_number, ""), NULLIF(sto.confirmation_number, ""), NULLIF(sv.application_reference, ""), cri.service_line_reference) AS ticket_reference,
                    COALESCE(NULLIF(sat.pnr, ""), "") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "") AS route,
                    COALESCE(sat.departure_date, su.departure_date, sh.check_in_date, st.pickup_date, sto.start_date, so.service_date) AS travel_date,
                    COALESCE(
                        NULLIF(bs.remarks, ""),
                        NULLIF(sat.ticket_remarks, ""),
                        NULLIF(sv.remarks, ""),
                        NULLIF(su.remarks, ""),
                        NULLIF(sh.remarks, ""),
                        NULLIF(st.route_notes, ""),
                        NULLIF(sto.remarks, ""),
                        NULLIF(so.remarks, ""),
                        CONCAT(UCASE(LEFT(COALESCE(bs.service_type, "service"), 1)), SUBSTRING(COALESCE(bs.service_type, "service"), 2), " Service")
                    ) AS description,
                    cri.currency,
                    CASE
                        WHEN COALESCE(bs.net_profit_loss, 0) < -0.005
                          OR COALESCE(NULLIF(bs.loss_reason, ""), "") <> ""
                            THEN ROUND(
                                CASE
                                    WHEN COALESCE(bs.cost_currency, bs.currency) = bs.currency
                                        THEN COALESCE(bs.purchase_cost, 0)
                                    ELSE ROUND(
                                        COALESCE(bs.purchase_cost, 0)
                                        * COALESCE(NULLIF(bs.pricing_exchange_rate, 0), 1),
                                        0
                                    )
                                END
                                + CASE
                                    WHEN COALESCE(bs.service_charge_currency, bs.currency) = bs.currency
                                        THEN COALESCE(bs.service_charge, 0)
                                            + COALESCE(bs.vat, 0)
                                            - COALESCE(bs.discount_amount, 0)
                                    ELSE ROUND(
                                        (
                                            COALESCE(bs.service_charge, 0)
                                            + COALESCE(bs.vat, 0)
                                            - COALESCE(bs.discount_amount, 0)
                                        ) * COALESCE(NULLIF(bs.service_charge_exchange_rate, 0), 1),
                                        0
                                    )
                                END,
                                2
                            )
                        ELSE COALESCE(NULLIF(bs.final_sale_price, 0), cri.due_amount)
                    END AS original_invoice_amount,
                    COALESCE(NULLIF(bs.final_sale_price, 0), cri.due_amount) AS customer_sale_amount,
                    COALESCE(bs.net_profit_loss, 0) AS service_profit_loss,
                    COALESCE(NULLIF(bs.loss_reason, ""), "") AS service_loss_reason,
                    cri.due_amount AS total_due,
                    cri.allocated_amount AS total_allocated,
                    cri.outstanding_amount AS total_outstanding,
                    ROUND(
                        COALESCE(receipt_summary.customer_received_amount, 0)
                        + COALESCE(cross_currency_receipt_summary.customer_received_amount, 0),
                        2
                    ) AS account_customer_received_amount,
                    COALESCE(
                        receipt_summary.customer_received_date,
                        cross_currency_receipt_summary.customer_received_date,
                        b.booking_date
                    ) AS account_customer_received_date,
                    COALESCE(supplier_payment_summary.supplier_paid_amount, 0) AS account_supplier_paid_amount,
                    COALESCE(supplier_payment_summary.supplier_paid_date, b.booking_date) AS account_supplier_paid_date,
                    COALESCE(refund_summary.customer_paid_amount, 0) AS refund_customer_paid_amount,
                    COALESCE(refund_summary.customer_penalty_amount, 0) AS refund_customer_penalty_amount,
                    COALESCE(refund_summary.customer_refund_expected, 0) AS refund_customer_refund_expected,
                    COALESCE(NULLIF(refund_summary.customer_refund_currency, ""), cri.currency) AS refund_customer_refund_currency,
                    COALESCE(refund_summary.customer_refund_posted, 0) AS refund_customer_refund_posted,
                    COALESCE(refund_summary.supplier_penalty_amount, 0) AS refund_supplier_penalty_amount,
                    COALESCE(refund_summary.supplier_refund_expected, 0) AS refund_supplier_refund_expected,
                    COALESCE(refund_summary.supplier_refund_received, 0) AS refund_supplier_refund_received,
                    COALESCE(refund_summary.refund_event_date, b.booking_date) AS refund_event_date,
                    COALESCE(refund_summary.refund_event_count, 0) AS refund_event_count
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = cri.service_line_reference
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                LEFT JOIN service_visa sv ON sv.booking_service_id = bs.id
                LEFT JOIN service_umrah su ON su.booking_service_id = bs.id
                LEFT JOIN service_hotel sh ON sh.booking_service_id = bs.id
                LEFT JOIN service_transport st ON st.booking_service_id = bs.id
                LEFT JOIN service_tour sto ON sto.booking_service_id = bs.id
                LEFT JOIN service_other so ON so.booking_service_id = bs.id
                LEFT JOIN (
                    SELECT
                        cr.booking_reference,
                        cr.currency,
                        ROUND(SUM(cr.received_amount), 2) AS customer_received_amount,
                        MAX(cr.receipt_date) AS customer_received_date
                    FROM customer_receipts cr
                    WHERE cr.status <> "void"
                      ' . $customerReceiptPurposeFilter . '
                    GROUP BY cr.booking_reference, cr.currency
                ) receipt_summary
                    ON receipt_summary.booking_reference = cri.booking_reference
                   AND receipt_summary.currency = cri.currency
                LEFT JOIN (
                    SELECT
                        cra.customer_receivable_item_id,
                        ROUND(SUM(COALESCE(cra.receivable_amount_allocated, cra.allocated_amount)), 2) AS customer_received_amount,
                        MAX(cr.receipt_date) AS customer_received_date
                    FROM customer_receipt_allocations cra
                    INNER JOIN customer_receipts cr
                        ON cr.id = cra.customer_receipt_id
                       AND cr.status <> "void"
                    INNER JOIN customer_receivable_items cri_target
                        ON cri_target.id = cra.customer_receivable_item_id
                    WHERE cr.booking_reference = cri_target.booking_reference
                      AND cr.currency <> cri_target.currency
                      ' . $customerReceiptPurposeFilter . '
                    GROUP BY cra.customer_receivable_item_id
                ) cross_currency_receipt_summary
                    ON cross_currency_receipt_summary.customer_receivable_item_id = cri.id
                LEFT JOIN (
                    SELECT
                        so.booking_reference,
                        so.service_line_reference,
                        so.currency,
                        ROUND(SUM(spa.allocated_amount), 2) AS supplier_paid_amount,
                        MAX(sp.payment_date) AS supplier_paid_date
                    FROM supplier_payment_allocations spa
                    INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
                    INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
                    WHERE sp.status <> "void"
                      AND spa.allocated_amount > 0.005
                    GROUP BY so.booking_reference, so.service_line_reference, so.currency
                ) supplier_payment_summary
                    ON supplier_payment_summary.booking_reference = cri.booking_reference
                   AND supplier_payment_summary.service_line_reference = cri.service_line_reference
                   AND supplier_payment_summary.currency = cri.currency
                LEFT JOIN (
                    SELECT
                        bse.booking_id,
                        bse.booking_service_id,
                        COUNT(*) AS refund_event_count,
                        MAX(bse.event_date) AS refund_event_date,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_refund_basis")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.released_customer_credit")), "") AS DECIMAL(18,2)) + COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                ),
                                bse.customer_credit_amount + COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                ),
                                0
                            )
                            ELSE 0
                        END) AS customer_paid_amount,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                bse.penalty_amount,
                                0
                            )
                            ELSE 0
                        END) AS customer_penalty_amount,
                        MAX(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.available_customer_refund_credit_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.target_customer_refund_credit_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_refund_basis_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.settlement_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_currency")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_currency")), ""),
                                bse.currency
                            )
                            WHEN bse.event_type = "refund" THEN COALESCE(
                                NULLIF(refund_detail.refund_currency, ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.refund_detail.refund_currency")), ""),
                                bse.currency
                            )
                            ELSE NULL
                        END) AS customer_refund_currency,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN GREATEST(
                                COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_source_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                                    0
                                ) - COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_source_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                ) + COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.agency_fee_refund_amount")), "") AS DECIMAL(18,2)),
                                    0
                                ),
                                0
                            )
                            ELSE 0
                        END) AS customer_refund_expected,
                        SUM(CASE
                            WHEN bse.event_type = "refund" THEN COALESCE(
                                refund_detail.refund_source_amount,
                                COALESCE(bse.customer_refund_amount, 0)
                            )
                            ELSE 0
                        END) AS customer_refund_posted,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_source_amount")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)),
                                0
                            )
                            ELSE 0
                        END) AS supplier_penalty_amount,
                        SUM(CASE
                            WHEN bse.event_type = "cancel" THEN COALESCE(
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_source_amount")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                                bse.supplier_credit_amount,
                                0
                            )
                            ELSE 0
                        END) AS supplier_refund_expected,
                        SUM(CASE
                            WHEN bse.event_type = "refund" THEN COALESCE(bse.supplier_refund_amount, 0)
                            ELSE 0
                        END) AS supplier_refund_received
                    FROM booking_service_events bse
                    LEFT JOIN booking_service_refund_details refund_detail
                        ON refund_detail.service_event_id = bse.id
                    WHERE bse.event_status = "posted"
                      AND bse.event_type IN ("cancel", "refund")
                    GROUP BY bse.booking_id, bse.booking_service_id
                ) refund_summary
                    ON refund_summary.booking_id = b.id
                   AND refund_summary.booking_service_id = bs.id
                WHERE ' . implode(' AND ', $filters) . '
                ORDER BY b.booking_date DESC,
                         b.booking_reference DESC,
                         cri.service_line_reference DESC,
                         cri.id DESC,
                         bs_src.name ASC,
                         br.name ASC,
                         lead_traveler_name ASC';

        $rows = $this->fetchRows($sql, $params);
        array_push($rows, ...$this->customerCreditTransferLedgerRows(
            $branchIds,
            $dateFrom,
            $dateTo,
            $currency,
            $businessSourceId,
            $customerName,
            $bookingReference
        ));

        if ($bookingReference === '') {
            $advanceRows = $this->customerAdvanceLedger(
                $branchIds,
                $dateFrom,
                $dateTo,
                $currency,
                $customerName
            );
            foreach ($advanceRows as $advanceRow) {
                $entryType = (string) ($advanceRow['entry_type'] ?? '');
                if (! in_array($entryType, ['Customer Advance Received', 'Customer Advance Returned'], true)) {
                    continue;
                }
                if ($businessSourceId > 0
                    && (int) ($advanceRow['business_source_id'] ?? 0) !== $businessSourceId
                ) {
                    continue;
                }

                $rows[] = [
                    'row_type' => 'customer_advance_movement',
                    'advance_entry_type' => $entryType,
                    'advance_row_id' => (int) ($advanceRow['row_id'] ?? 0),
                    'receipt_id' => (int) ($advanceRow['receipt_id'] ?? 0),
                    'branch_id' => (int) ($advanceRow['branch_id'] ?? 0),
                    'branch_name' => (string) ($advanceRow['branch_name'] ?? ''),
                    'business_source_id' => (int) ($advanceRow['business_source_id'] ?? 0),
                    'business_source_name' => (string) ($advanceRow['business_source_name'] ?? 'Unassigned Account'),
                    'booking_id' => 0,
                    'lead_traveler_id' => (int) ($advanceRow['traveler_id'] ?? 0),
                    'booking_reference' => 'ADVANCE',
                    'booking_date' => (string) ($advanceRow['entry_date'] ?? ''),
                    'due_date' => null,
                    'service_line_reference' => '',
                    'service_type' => 'Customer Advance',
                    'passenger_name' => (string) ($advanceRow['customer_name'] ?? 'Customer'),
                    'lead_traveler_name' => (string) ($advanceRow['customer_name'] ?? 'Customer'),
                    'contact_mobile' => '',
                    'pnr' => '',
                    'route' => '',
                    'description' => (string) ($advanceRow['remarks'] ?? ''),
                    'currency' => (string) ($advanceRow['currency'] ?? 'PKR'),
                    'advance_received_amount' => (float) ($advanceRow['received_amount'] ?? 0),
                    'advance_returned_amount' => (float) ($advanceRow['returned_amount'] ?? 0),
                    'advance_reference' => (string) ($advanceRow['reference'] ?? ''),
                    'advance_payment_method' => (string) ($advanceRow['payment_method'] ?? ''),
                    'advance_treasury_account_name' => (string) ($advanceRow['treasury_account_name'] ?? ''),
                ];
            }
        } else {
            foreach ($rows as &$ledgerRow) {
                if ((string) ($ledgerRow['row_type'] ?? '') === 'customer_credit_transfer'
                    && (string) ($ledgerRow['receipt_purpose'] ?? '') === 'customer_advance'
                ) {
                    $ledgerRow['customer_advance_booking_view'] = true;
                }
            }
            unset($ledgerRow);
        }

        if ($bookingReference === '') {
            foreach ($rows as &$ledgerRow) {
                if ((string) ($ledgerRow['row_type'] ?? '') === 'customer_credit_transfer'
                    && (string) ($ledgerRow['receipt_purpose'] ?? '') === 'customer_advance'
                ) {
                    $ledgerRow['suppress_customer_movement'] = true;
                }
            }
            unset($ledgerRow);
        }

        return $rows;
    }

    public function customerLedgerCustomers(array $branchIds, int $businessSourceId = 0): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $filters = [
            'b.branch_id ' . $clause,
        ];

        if ($businessSourceId > 0) {
            $filters[] = 'b.business_source_id = :business_source_id';
            $params['business_source_id'] = $businessSourceId;
        }

        $sql = 'SELECT DISTINCT
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name
                FROM bookings b
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                WHERE ' . implode(' AND ', $filters) . '
                ORDER BY customer_name ASC';

        $customers = $this->fetchRows($sql, $params);
        if (! $this->columnExists('customer_receipts', 'traveler_id')
            || ! $this->columnExists('customer_receipts', 'receipt_purpose')
        ) {
            return $customers;
        }

        [$advanceClause, $advanceParams] = $this->branchScope($branchIds, 'customer_ledger_advance_branch_');
        $advanceFilters = [
            'cr.branch_id ' . $advanceClause,
            'cr.status <> "void"',
            'cr.receipt_purpose = "customer_advance"',
        ];
        if ($businessSourceId > 0 && $this->columnExists('customer_receipts', 'business_source_id')) {
            $advanceFilters[] = 'cr.business_source_id = :customer_ledger_advance_business_source_id';
            $advanceParams['customer_ledger_advance_business_source_id'] = $businessSourceId;
        }
        $advanceCustomers = $this->fetchRows(
            'SELECT DISTINCT COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name
             FROM customer_receipts cr
             LEFT JOIN travelers t ON t.id = cr.traveler_id
             WHERE ' . implode(' AND ', $advanceFilters),
            $advanceParams
        );

        $byName = [];
        foreach (array_merge($customers, $advanceCustomers) as $customer) {
            $name = trim((string) ($customer['customer_name'] ?? ''));
            if ($name !== '') {
                $byName[mb_strtolower($name)] = ['customer_name' => $name];
            }
        }
        uasort($byName, static fn (array $left, array $right): int =>
            strcasecmp((string) $left['customer_name'], (string) $right['customer_name'])
        );

        return array_values($byName);
    }

    public function customerAdvanceCustomers(array $branchIds): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $refundUnion = '';

        if ($this->tableExists('customer_advance_refunds')) {
            [$refundClause, $refundParams] = $this->branchScope($branchIds, 'advance_refund_branch_');
            $params = array_merge($params, $refundParams);
            $refundUnion = '
                UNION
                SELECT DISTINCT COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name
                FROM customer_advance_refunds car
                INNER JOIN customer_receipts cr ON cr.id = car.customer_receipt_id AND cr.status <> "void"
                LEFT JOIN travelers t ON t.id = car.traveler_id
                WHERE car.branch_id ' . $refundClause;
        }

        return $this->fetchRows(
            'SELECT DISTINCT customer_name
             FROM (
                SELECT DISTINCT COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name
                FROM customer_receipts cr
                LEFT JOIN travelers t ON t.id = cr.traveler_id
                WHERE cr.branch_id ' . $clause . '
                  AND cr.status <> "void"
                  AND cr.receipt_purpose = "customer_advance"'
                . $refundUnion . '
             ) advance_customers
             WHERE customer_name <> ""
             ORDER BY customer_name ASC',
            $params
        );
    }

    public function customerAdvanceLedger(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        string $customerName = '',
        int $businessSourceId = 0
    ): array {
        if (! $this->columnExists('customer_receipts', 'traveler_id')
            || ! $this->columnExists('customer_receipts', 'receipt_purpose')
        ) {
            return [];
        }

        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('advance_rows.entry_date', $dateFrom, $dateTo, $params, 'advance_');
        $currencySql = '';
        if (trim($currency) !== '') {
            $currencySql = ' AND advance_rows.currency = :advance_currency';
            $params['advance_currency'] = strtoupper(trim($currency));
        }

        $customerSql = '';
        if (trim($customerName) !== '') {
            $customerSql = ' AND LOWER(advance_rows.customer_name) = LOWER(:advance_customer_name)';
            $params['advance_customer_name'] = trim($customerName);
        }

        $businessSourceSql = '';
        if ($businessSourceId > 0) {
            $businessSourceSql = ' AND advance_rows.business_source_id = :advance_business_source_id';
            $params['advance_business_source_id'] = $businessSourceId;
        }

        $paymentAmountExpression = $this->columnExists('customer_receipt_allocations', 'payment_amount_consumed')
            ? 'COALESCE(cra.payment_amount_consumed, cra.allocated_amount)'
            : 'cra.allocated_amount';

        $refundSelect = 'SELECT
                    NULL AS row_id,
                    0 AS receipt_id,
                    NULL AS allocation_id,
                    id AS branch_id,
                    0 AS traveler_id,
                    NULL AS business_source_id,
                    "1900-01-01" AS entry_date,
                    "Customer" AS customer_name,
                    "PKR" AS currency,
                    "Customer Advance Returned" AS entry_type,
                    "" AS reference,
                    "" AS raw_reference_number,
                    "" AS payment_method,
                    NULL AS treasury_account_id,
                    "" AS treasury_account_name,
                    "" AS booking_reference,
                    0 AS booking_id,
                    "" AS remarks,
                    "" AS raw_remarks,
                    0 AS received_amount,
                    0 AS applied_amount,
                    0 AS returned_amount,
                    40 AS sort_order
                 FROM branches
                 WHERE 1 = 0';

        if ($this->tableExists('customer_advance_refunds')) {
            $refundSelect = 'SELECT
                    car.id AS row_id,
                    car.customer_receipt_id AS receipt_id,
                    NULL AS allocation_id,
                    car.branch_id,
                    car.traveler_id,
                    cr.business_source_id,
                    car.refund_date AS entry_date,
                    COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name,
                    car.currency,
                    "Customer Advance Returned" AS entry_type,
                    COALESCE(NULLIF(car.reference_number, ""), cr.receipt_no, "") AS reference,
                    COALESCE(car.reference_number, "") AS raw_reference_number,
                    car.payment_method,
                    car.treasury_account_id,
                    COALESCE(NULLIF(ta.account_name, ""), "") AS treasury_account_name,
                    "" AS booking_reference,
                    0 AS booking_id,
                    COALESCE(NULLIF(car.reason, ""), NULLIF(car.remarks, ""), "") AS remarks,
                    COALESCE(car.remarks, "") AS raw_remarks,
                    0 AS received_amount,
                    0 AS applied_amount,
                    car.amount AS returned_amount,
                    30 AS sort_order
                 FROM customer_advance_refunds car
                 INNER JOIN customer_receipts cr ON cr.id = car.customer_receipt_id AND cr.status <> "void"
                 LEFT JOIN travelers t ON t.id = car.traveler_id
                 LEFT JOIN treasury_accounts ta ON ta.id = car.treasury_account_id';
        }

        $sql = 'SELECT
                    advance_rows.row_id,
                    advance_rows.receipt_id,
                    advance_rows.allocation_id,
                    advance_rows.branch_id,
                    advance_rows.traveler_id,
                    advance_rows.business_source_id,
                    COALESCE(bs.name, "Unassigned Account") AS business_source_name,
                    br.name AS branch_name,
                    advance_rows.entry_date,
                    advance_rows.customer_name,
                    advance_rows.currency,
                    advance_rows.entry_type,
                    advance_rows.reference,
                    advance_rows.raw_reference_number,
                    advance_rows.payment_method,
                    advance_rows.treasury_account_id,
                    advance_rows.treasury_account_name,
                    advance_rows.booking_reference,
                    advance_rows.booking_id,
                    advance_rows.remarks,
                    advance_rows.raw_remarks,
                    ROUND(advance_rows.received_amount, 2) AS received_amount,
                    ROUND(advance_rows.applied_amount, 2) AS applied_amount,
                    ROUND(advance_rows.returned_amount, 2) AS returned_amount,
                    advance_rows.sort_order
                FROM (
                    SELECT
                        cr.id AS row_id,
                        cr.id AS receipt_id,
                        NULL AS allocation_id,
                        cr.branch_id,
                        cr.traveler_id,
                        cr.business_source_id,
                        cr.receipt_date AS entry_date,
                        COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name,
                        cr.currency,
                        "Customer Advance Received" AS entry_type,
                        cr.receipt_no AS reference,
                        COALESCE(cr.reference_number, "") AS raw_reference_number,
                        cr.payment_method,
                        cr.treasury_account_id,
                        COALESCE(NULLIF(ta.account_name, ""), "") AS treasury_account_name,
                        "" AS booking_reference,
                        0 AS booking_id,
                        COALESCE(NULLIF(cr.reference_number, ""), NULLIF(cr.remarks, ""), "") AS remarks,
                        COALESCE(cr.remarks, "") AS raw_remarks,
                        cr.received_amount AS received_amount,
                        0 AS applied_amount,
                        0 AS returned_amount,
                        10 AS sort_order
                    FROM customer_receipts cr
                    LEFT JOIN travelers t ON t.id = cr.traveler_id
                    LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
                    WHERE cr.status <> "void"
                      AND cr.receipt_purpose = "customer_advance"

                    UNION ALL

                    SELECT
                        cra.id AS row_id,
                        cr.id AS receipt_id,
                        cra.id AS allocation_id,
                        cr.branch_id,
                        cr.traveler_id,
                        cr.business_source_id,
                        COALESCE(cri.due_date, cr.receipt_date) AS entry_date,
                        COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name,
                        cr.currency,
                        "Advance Applied to Invoice" AS entry_type,
                        cr.receipt_no AS reference,
                        COALESCE(cr.reference_number, "") AS raw_reference_number,
                        cr.payment_method,
                        cr.treasury_account_id,
                        COALESCE(NULLIF(ta.account_name, ""), "") AS treasury_account_name,
                        COALESCE(NULLIF(cri.booking_reference, ""), "") AS booking_reference,
                        COALESCE(b.id, 0) AS booking_id,
                        COALESCE(NULLIF(cri.remarks, ""), "Applied to customer invoice") AS remarks,
                        COALESCE(cr.remarks, "") AS raw_remarks,
                        0 AS received_amount,
                        ' . $paymentAmountExpression . ' AS applied_amount,
                        0 AS returned_amount,
                        20 AS sort_order
                    FROM customer_receipt_allocations cra
                    INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id
                    INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
                    LEFT JOIN bookings b ON b.booking_reference = cri.booking_reference
                    LEFT JOIN travelers t ON t.id = cr.traveler_id
                    LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
                    WHERE cr.status <> "void"
                      AND cr.receipt_purpose = "customer_advance"

                    UNION ALL

                    ' . $refundSelect . '
                ) advance_rows
                INNER JOIN branches br ON br.id = advance_rows.branch_id
                LEFT JOIN business_sources bs ON bs.id = advance_rows.business_source_id
                WHERE advance_rows.branch_id ' . $clause . $dateSql . $currencySql . $customerSql . $businessSourceSql . '
                ORDER BY advance_rows.entry_date DESC, advance_rows.sort_order ASC, advance_rows.row_id DESC';

        return $this->fetchRows($sql, $params);
    }

    public function reminderHub(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $statusFilter = 'active',
        string $reminderType = '',
        string $serviceType = '',
        string $search = '',
        string $priority = '',
        int $businessSourceId = 0,
        string $customerName = ''
    ): array {
        [$clause, $params] = $this->branchScope($branchIds);

        $where = ['brm.branch_id ' . $clause];

        if ($dateFrom !== null) {
            $where[] = 'DATE(brm.due_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $where[] = 'DATE(brm.due_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        if ($reminderType !== '') {
            $where[] = 'brm.reminder_type = :reminder_type';
            $params['reminder_type'] = $reminderType;
        }

        if ($serviceType !== '') {
            $where[] = 'COALESCE(bs.service_type, bs_obligation.service_type, bs_booking.service_type, "") = :service_type';
            $params['service_type'] = $serviceType;
        }

        if ($priority !== '') {
            $where[] = 'brm.priority = :reminder_priority';
            $params['reminder_priority'] = $priority;
        }

        if ($businessSourceId > 0) {
            $where[] = 'b.business_source_id = :reminder_business_source_id';
            $params['reminder_business_source_id'] = $businessSourceId;
        }

        $customerName = trim($customerName);
        if ($customerName !== '') {
            $where[] = 'COALESCE(NULLIF(t.full_name, ""), NULLIF(t_lead.full_name, ""), COALESCE(bp.lead_traveler_name, "Customer")) = :reminder_customer_name';
            $params['reminder_customer_name'] = $customerName;
        }

        $statusFilter = strtolower(trim($statusFilter));
        switch ($statusFilter) {
            case 'open':
            case 'due':
            case 'completed':
            case 'dismissed':
                $where[] = 'brm.status = :status_filter';
                $params['status_filter'] = $statusFilter;
                break;
            case 'overdue':
                $where[] = 'brm.status IN ("open", "due")';
                $where[] = 'brm.due_at < NOW()';
                break;
            case 'upcoming':
                $where[] = 'brm.status = "open"';
                $where[] = 'brm.due_at >= NOW()';
                break;
            case 'active':
                $where[] = 'brm.status IN ("open", "due")';
                break;
            case 'all':
            default:
                break;
        }

        $search = trim($search);
        if ($search !== '') {
            $where[] = '(
                b.booking_reference LIKE :search
                OR COALESCE(NULLIF(t.full_name, ""), NULLIF(t_lead.full_name, ""), COALESCE(bp.lead_traveler_name, "")) LIKE :search
                OR COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t.mobile, ""), NULLIF(t_lead.mobile, ""), "") LIKE :search
                OR COALESCE(t.full_name, "") LIKE :search
                OR COALESCE(s.name, "") LIKE :search
                OR COALESCE(brm.title, "") LIKE :search
                OR COALESCE(brm.reminder_note, "") LIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $sql = 'SELECT
                    brm.id,
                    brm.branch_id,
                    br.name AS branch_name,
                    b.id AS booking_id,
                    b.booking_reference,
                    b.booking_date,
                    b.business_source_id,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    COALESCE(NULLIF(t.full_name, ""), NULLIF(t_lead.full_name, ""), COALESCE(bp.lead_traveler_name, "Customer")) AS customer_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t.mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    t.id AS traveler_id,
                    COALESCE(t.id, t_lead.id) AS customer_traveler_id,
                    brm.reminder_type,
                    brm.title,
                    brm.reminder_note,
                    brm.due_at,
                    brm.channel,
                    brm.owner_label,
                    brm.status,
                    brm.priority,
                    brm.system_generated,
                    t.full_name AS traveler_name,
                    COALESCE(bs.line_reference, bs_booking.line_reference) AS service_line_reference,
                    COALESCE(bs.service_type, bs_obligation.service_type, bs_booking.service_type, "") AS service_type,
                    cr.receipt_no AS customer_receipt_no,
                    sp.payment_no AS supplier_payment_no,
                    so.service_line_reference AS supplier_obligation_service_line,
                    s.name AS supplier_name
                FROM booking_reminders brm
                INNER JOIN branches br ON br.id = brm.branch_id
                INNER JOIN bookings b ON b.id = brm.booking_id
                LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t ON t.id = brm.traveler_id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                LEFT JOIN booking_services bs ON bs.id = brm.booking_service_id
                LEFT JOIN customer_receipts cr ON cr.id = brm.customer_receipt_id
                LEFT JOIN supplier_payments sp ON sp.id = brm.supplier_payment_id
                LEFT JOIN supplier_obligations so ON so.id = brm.supplier_obligation_id
                LEFT JOIN suppliers s ON s.id = so.supplier_id
                LEFT JOIN booking_services bs_obligation
                    ON bs_obligation.booking_id = b.id
                   AND bs_obligation.line_reference = so.service_line_reference
                   AND bs_obligation.is_active = 1
                LEFT JOIN booking_services bs_booking
                    ON bs_booking.id = (
                        SELECT bs_lookup.id
                        FROM booking_services bs_lookup
                        WHERE bs_lookup.booking_id = b.id
                          AND bs_lookup.is_active = 1
                        ORDER BY bs_lookup.id ASC
                        LIMIT 1
                    )
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY
                    CASE brm.priority WHEN "high" THEN 0 ELSE 1 END ASC,
                    CASE
                        WHEN brm.status IN ("open", "due") AND brm.due_at < NOW() THEN 0
                        WHEN brm.status = "due" THEN 1
                        WHEN brm.status = "open" THEN 2
                        WHEN brm.status = "completed" THEN 3
                        ELSE 4
                    END ASC,
                    brm.due_at ASC,
                    br.name ASC,
                    b.booking_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function supplierOutstanding(
        array $branchIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $supplierId = 0,
        int $businessSourceId = 0
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $params, 'supplier_outstanding_');
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND so.supplier_id = :supplier_outstanding_supplier_id';
            $params['supplier_outstanding_supplier_id'] = $supplierId;
        }
        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND b.business_source_id = :supplier_outstanding_business_source_id';
            $params['supplier_outstanding_business_source_id'] = $businessSourceId;
        }
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    so.booking_reference,
                    b.booking_date,
                    s.name AS supplier_name,
                    s.supplier_mode,
                    so.currency,
                    SUM(so.gross_amount) AS total_gross,
                    SUM(so.advance_applied_amount) AS total_advance_applied,
                    SUM(so.net_payable_amount) AS total_outstanding
                FROM supplier_obligations so
                INNER JOIN bookings b ON b.booking_reference = so.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                INNER JOIN suppliers s ON s.id = so.supplier_id
                WHERE so.net_payable_amount > 0
                  AND b.branch_id ' . $clause . $dateSql . $supplierSql . $accountSql . '
                GROUP BY
                    b.branch_id, br.name, so.booking_reference, b.booking_date,
                    s.name, s.supplier_mode, so.currency
                ORDER BY br.name ASC, s.name ASC, b.booking_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function supplierReceivable(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $airline = '',
        int $supplierId = 0,
        int $businessSourceId = 0
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('event_totals.event_date', $dateFrom, $dateTo, $params);
        $airlineSql = '';
        $airline = trim($airline);
        if ($airline !== '') {
            $airlineSql = ' AND COALESCE(sat.airline, "") LIKE :supplier_receivable_airline';
            $params['supplier_receivable_airline'] = '%' . $airline . '%';
        }
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND bs.supplier_id = :supplier_receivable_supplier_id';
            $params['supplier_receivable_supplier_id'] = $supplierId;
        }
        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND b.business_source_id = :supplier_receivable_business_source_id';
            $params['supplier_receivable_business_source_id'] = $businessSourceId;
        }

        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.id AS booking_id,
                    b.booking_reference,
                    event_totals.event_date,
                    event_totals.currency,
                    event_totals.service_line_reference,
                    event_totals.supplier_credit_amount,
                    event_totals.supplier_refund_received,
                    event_totals.supplier_receivable_balance,
                    event_totals.reason,
                    COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                    COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    COALESCE(NULLIF(sat.pnr, ""), "N/A") AS pnr,
                    COALESCE(NULLIF(sat.ticket_number, ""), event_totals.service_line_reference) AS ticket_number
                FROM (
                    SELECT
                        bse.booking_id,
                        bse.booking_service_id,
                        bse.service_line_reference,
                        bse.currency,
                        MAX(bse.event_date) AS event_date,
                        SUM(CASE WHEN bse.event_type = "cancel" THEN COALESCE(bse.supplier_credit_amount, 0) ELSE 0 END) AS supplier_credit_amount,
                        SUM(CASE WHEN bse.event_type = "refund" THEN COALESCE(bse.supplier_refund_amount, 0) ELSE 0 END) AS supplier_refund_received,
                        SUM(CASE WHEN bse.event_type = "cancel" THEN COALESCE(bse.supplier_credit_amount, 0) ELSE 0 END)
                            - SUM(CASE WHEN bse.event_type = "refund" THEN COALESCE(bse.supplier_refund_amount, 0) ELSE 0 END) AS supplier_receivable_balance,
                        MAX(COALESCE(NULLIF(bse.reason, ""), NULLIF(bse.notes, ""))) AS reason
                    FROM booking_service_events bse
                    WHERE bse.event_status = "posted"
                      AND bse.event_type IN ("cancel", "refund")
                    GROUP BY bse.booking_id, bse.booking_service_id, bse.service_line_reference, bse.currency
                ) AS event_totals
                INNER JOIN bookings b ON b.id = event_totals.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                INNER JOIN booking_services bs ON bs.id = event_totals.booking_service_id
                LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN travelers t ON t.id = bs.traveler_id
                WHERE event_totals.supplier_receivable_balance > 0.005
                  AND b.branch_id ' . $clause . $dateSql . $airlineSql . $supplierSql . $accountSql . '
                ORDER BY br.name ASC, supplier_name ASC, event_totals.event_date ASC, b.booking_reference ASC, event_totals.service_line_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function payableRefunds(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        int $supplierId = 0,
        string $bookingReference = '',
        int $businessSourceId = 0
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('refund_totals.event_date', $dateFrom, $dateTo, $params, 'payable_refund_');
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND bs.supplier_id = :payable_refund_supplier_id';
            $params['payable_refund_supplier_id'] = $supplierId;
        }
        $bookingReferenceSql = '';
        $bookingReference = trim($bookingReference);
        if ($bookingReference !== '') {
            $bookingReferenceSql = ' AND b.booking_reference LIKE :payable_refund_booking_reference';
            $params['payable_refund_booking_reference'] = '%' . $bookingReference . '%';
        }
        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND b.business_source_id = :payable_refund_business_source_id';
            $params['payable_refund_business_source_id'] = $businessSourceId;
        }

        $sql = 'SELECT *
                FROM (
                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.id AS booking_id,
                        b.booking_reference,
                        refund_totals.event_date,
                        refund_totals.currency,
                        refund_totals.customer_paid_amount,
                        refund_totals.customer_penalty_amount,
                        refund_totals.customer_refund_expected,
                        refund_totals.customer_refund_posted,
                        ROUND(LEAST(
                            GREATEST(
                                (
                                    CASE
                                        WHEN refund_totals.customer_refund_expected > 0.005 THEN refund_totals.customer_refund_expected
                                        ELSE GREATEST(refund_totals.supplier_refund_received - refund_totals.customer_penalty_amount, 0)
                                    END
                                ) - refund_totals.customer_refund_posted,
                                0
                            ),
                            COALESCE(customer_credit.current_unallocated_credit, 0)
                        ), 2) AS customer_refund_payable,
                        refund_totals.supplier_penalty_amount,
                        refund_totals.supplier_refund_expected,
                        refund_totals.supplier_refund_received,
                        ROUND(GREATEST(
                            (
                                CASE
                                    WHEN refund_totals.supplier_refund_expected > 0.005 THEN refund_totals.supplier_refund_expected
                                    ELSE refund_totals.supplier_refund_received
                                END
                            ) - refund_totals.supplier_refund_received,
                            0
                        ), 2) AS supplier_refund_receivable,
                        refund_totals.reason,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                        COALESCE(NULLIF(s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), "Supplier pending") AS supplier_name
                    FROM (
                        SELECT
                            bse.booking_id,
                            bse.booking_service_id,
                            bse.service_line_reference,
                            bse.currency,
                            MAX(bse.event_date) AS event_date,
                            SUM(CASE
                                WHEN bse.event_type = "cancel" THEN COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_refund_basis")), "") AS DECIMAL(18,2)),
                                    0
                                )
                                ELSE 0
                            END) AS customer_paid_amount,
                            SUM(CASE
                                WHEN bse.event_type = "cancel" THEN COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                    bse.penalty_amount,
                                    0
                                )
                                ELSE 0
                            END) AS customer_penalty_amount,
                            SUM(CASE
                                WHEN bse.event_type = "cancel" THEN GREATEST(
                                    COALESCE(
                                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                                        0
                                    ) - COALESCE(
                                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                                        bse.penalty_amount,
                                        0
                                    ),
                                    0
                                )
                                ELSE 0
                            END) AS customer_refund_expected,
                            SUM(CASE
                                WHEN bse.event_type = "refund" THEN COALESCE(bse.customer_refund_amount, 0)
                                ELSE 0
                            END) AS customer_refund_posted,
                            SUM(CASE
                                WHEN bse.event_type = "cancel" THEN COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)),
                                    0
                                )
                                ELSE 0
                            END) AS supplier_penalty_amount,
                            SUM(CASE
                                WHEN bse.event_type = "cancel" THEN COALESCE(
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                                    bse.supplier_credit_amount,
                                    0
                                )
                                ELSE 0
                            END) AS supplier_refund_expected,
                            SUM(CASE
                                WHEN bse.event_type = "refund" THEN COALESCE(bse.supplier_refund_amount, 0)
                                ELSE 0
                            END) AS supplier_refund_received,
                            MAX(COALESCE(NULLIF(bse.reason, ""), NULLIF(bse.notes, ""))) AS reason
                        FROM booking_service_events bse
                        WHERE bse.event_status = "posted"
                          AND bse.event_type IN ("cancel", "refund")
                        GROUP BY bse.booking_id, bse.booking_service_id, bse.service_line_reference, bse.currency
                    ) AS refund_totals
                    INNER JOIN bookings b ON b.id = refund_totals.booking_id
                    INNER JOIN branches br ON br.id = b.branch_id
                    INNER JOIN booking_services bs ON bs.id = refund_totals.booking_service_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    LEFT JOIN (
                        SELECT
                            booking_reference,
                            currency,
                            ROUND(SUM(unallocated_amount), 2) AS current_unallocated_credit
                        FROM customer_receipts
                        WHERE status <> "void"
                        GROUP BY booking_reference, currency
                    ) customer_credit
                        ON customer_credit.booking_reference = b.booking_reference
                       AND customer_credit.currency = refund_totals.currency
                    WHERE b.branch_id ' . $clause . $dateSql . $supplierSql . $bookingReferenceSql . $accountSql . '
                ) AS payable_refunds
                WHERE payable_refunds.customer_refund_payable > 0.005
                   OR payable_refunds.supplier_refund_receivable > 0.005
                ORDER BY payable_refunds.event_date DESC, payable_refunds.booking_reference DESC, payable_refunds.passenger_name ASC';

        return $this->fetchRows($sql, $params);
    }

    public function supplierLedger(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        string $airline = '',
        int $supplierId = 0,
        int $businessSourceId = 0,
        string $bookingReference = ''
    ): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('ledger_rows.ledger_date', $dateFrom, $dateTo, $params, 'ledger_');
        $currencySql = '';
        $currency = strtoupper(trim($currency));
        if ($currency !== '') {
            $currencySql = ' AND ledger_rows.currency = :ledger_currency';
            $params['ledger_currency'] = $currency;
        }

        $airlineSql = '';
        $airline = trim($airline);
        if ($airline !== '') {
            $airlineSql = ' AND COALESCE(ledger_rows.airline, "") LIKE :ledger_airline';
            $params['ledger_airline'] = '%' . $airline . '%';
        }
        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND ledger_rows.supplier_id = :ledger_supplier_id';
            $params['ledger_supplier_id'] = $supplierId;
        }
        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND (
                ledger_rows.business_source_id = :ledger_business_source_id
                OR EXISTS (
                    SELECT 1
                    FROM business_source_supplier_links linked_party
                    WHERE linked_party.business_source_id = :ledger_linked_party_business_source_id
                      AND linked_party.supplier_id = ledger_rows.supplier_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM counterparty_offset_payable_allocations account_filter_allocation
                    INNER JOIN counterparty_offsets account_filter_offset
                        ON account_filter_offset.id = account_filter_allocation.counterparty_offset_id
                    INNER JOIN supplier_obligations account_filter_obligation
                        ON account_filter_obligation.id = account_filter_allocation.supplier_obligation_id
                    WHERE account_filter_offset.status = "posted"
                      AND account_filter_offset.business_source_id = :ledger_linked_business_source_id
                      AND account_filter_offset.supplier_id = ledger_rows.supplier_id
                      AND account_filter_obligation.booking_reference COLLATE utf8mb4_unicode_ci = ledger_rows.booking_reference COLLATE utf8mb4_unicode_ci
                      AND account_filter_obligation.service_line_reference COLLATE utf8mb4_unicode_ci = ledger_rows.service_line_reference COLLATE utf8mb4_unicode_ci
                )
            )';
            $params['ledger_business_source_id'] = $businessSourceId;
            $params['ledger_linked_party_business_source_id'] = $businessSourceId;
            $params['ledger_linked_business_source_id'] = $businessSourceId;
        }
        $bookingReferenceSql = '';
        $bookingReference = trim($bookingReference);
        if ($bookingReference !== '') {
            $bookingReferenceSql = ' AND ledger_rows.booking_reference COLLATE utf8mb4_unicode_ci LIKE :ledger_booking_reference';
            $params['ledger_booking_reference'] = '%' . $bookingReference . '%';
        }

        $serviceContextSql = '
            INNER JOIN bookings b ON b.booking_reference = so.booking_reference
            INNER JOIN branches br ON br.id = b.branch_id
            INNER JOIN suppliers s ON s.id = so.supplier_id
            LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
            LEFT JOIN booking_parties bp ON bp.booking_id = b.id
            LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
            LEFT JOIN booking_services bs
                ON bs.booking_id = b.id
               AND bs.line_reference = so.service_line_reference
            LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
            LEFT JOIN travelers t ON t.id = bs.traveler_id';

        $baseSelect = '
                    b.branch_id,
                    br.name AS branch_name,
                    b.id AS booking_id,
                    b.booking_reference,
                    so.service_line_reference,
                    so.currency,
                    COALESCE(b.business_source_id, 0) AS business_source_id,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    s.id AS supplier_id,
                    s.name AS supplier_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                    COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    COALESCE(NULLIF(sat.airline, ""), "N/A") AS airline,
                    NULL AS supplier_payment_id';

        $convertedAdvanceSql = '';
        if ($this->columnExists('supplier_payments', 'converted_advance_amount')) {
            // A refund retained in the supplier account is already represented by
            // the refund event and, when consumed, by an advance-application row.
            // Do not present that conversion as another cash advance payment.
            $cashOverpaymentAdvanceExpression = 'GREATEST(
                sp.converted_advance_amount - COALESCE((
                    SELECT SUM(sa_refund.deposit_amount)
                    FROM supplier_advances sa_refund
                    WHERE sa_refund.source_supplier_payment_id = sp.id
                      AND sa_refund.reference_no LIKE "REFUND-EVT-%"
                ), 0.00),
                0.00
            )';
            $convertedAdvanceSql = '

                    UNION ALL

                    SELECT
                        sp.branch_id,
                        br.name AS branch_name,
                        COALESCE(b.id, 0) AS booking_id,
                        CASE
                            WHEN UPPER(COALESCE(sp.booking_reference, "")) = "GLOBAL"
                                THEN COALESCE(NULLIF(sp.payment_no, ""), "Supplier Payment")
                            ELSE COALESCE(NULLIF(sp.booking_reference, ""), NULLIF(sp.payment_no, ""), "Supplier Payment")
                        END AS booking_reference,
                        "" AS service_line_reference,
                        sp.currency,
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                        s.id AS supplier_id,
                        s.name AS supplier_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        "N/A" AS passenger_name,
                        "N/A" AS route,
                        "N/A" AS airline,
                        sp.id AS supplier_payment_id,
                        NULL AS journal_entry_id,
                        NULL AS source_reference,
                        NULL AS posted_by_name,
                        NULL AS narration,
                        sp.payment_date AS ledger_date,
                        ' . $cashOverpaymentAdvanceExpression . ' AS debit_amount,
                        0.00 AS credit_amount,
                        "Supplier Advance / Overpayment" AS entry_type,
                        25 AS sort_order,
                        CAST(sp.id AS CHAR) AS sort_reference
                    FROM supplier_payments sp
                    INNER JOIN suppliers s ON s.id = sp.supplier_id
                    INNER JOIN branches br ON br.id = sp.branch_id
                    LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
                    LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    WHERE sp.status <> "void"
                      AND ' . $cashOverpaymentAdvanceExpression . ' > 0.005';
        }

        $standaloneSupplierAdvanceSql = '

                    UNION ALL

                    SELECT
                        sa.branch_id,
                        br.name AS branch_name,
                        0 AS booking_id,
                        COALESCE(NULLIF(sa.reference_no, ""), CONCAT("SADV-", sa.id)) AS booking_reference,
                        "" AS service_line_reference,
                        sa.currency,
                        0 AS business_source_id,
                        "Unassigned Account" AS business_source_name,
                        s.id AS supplier_id,
                        s.name AS supplier_name,
                        "Customer" AS customer_name,
                        "Supplier advance" AS passenger_name,
                        "N/A" AS route,
                        "N/A" AS airline,
                        NULL AS supplier_payment_id,
                        sa.journal_entry_id AS journal_entry_id,
                        COALESCE(NULLIF(sa.reference_no, ""), CONCAT("SADV-", sa.id)) AS source_reference,
                        COALESCE(NULLIF(created_by.name, ""), NULLIF(created_by.username, ""), NULLIF(created_by.email, ""), "System") AS posted_by_name,
                        sa.remarks AS narration,
                        COALESCE(sa.received_at, DATE(sa.created_at)) AS ledger_date,
                        sa.deposit_amount AS debit_amount,
                        0.00 AS credit_amount,
                        "Supplier Advance / Overpayment" AS entry_type,
                        24 AS sort_order,
                        CONCAT("ADV-", LPAD(sa.id, 12, "0")) AS sort_reference
                    FROM supplier_advances sa
                    INNER JOIN suppliers s ON s.id = sa.supplier_id
                    INNER JOIN branches br ON br.id = sa.branch_id
                    LEFT JOIN users created_by ON created_by.id = sa.created_by_user_id
                    WHERE COALESCE(sa.deposit_amount, 0) > 0.005
                      AND COALESCE(sa.source_supplier_payment_id, 0) = 0';

        $linkedPartyOffsetSql = '';
        if ($this->tableExists('counterparty_offsets')) {
            $linkedPartyOffsetSql = '

                    UNION ALL

                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.id AS booking_id,
                        b.booking_reference,
                        so.service_line_reference,
                        COALESCE(o.supplier_currency, o.currency),
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                        s.id AS supplier_id,
                        s.name AS supplier_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                        COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                        COALESCE(NULLIF(sat.airline, ""), "N/A") AS airline,
                        NULL AS supplier_payment_id,
                        NULL AS journal_entry_id,
                        NULL AS source_reference,
                        NULL AS posted_by_name,
                        NULL AS narration,
                        o.offset_date AS ledger_date,
                        oa.allocated_amount AS debit_amount,
                        0.00 AS credit_amount,
                        "Linked Account Balance Adjustment" AS entry_type,
                        32 AS sort_order,
                        CONCAT(LPAD(o.id, 12, "0"), "-", LPAD(oa.id, 12, "0")) AS sort_reference
                    FROM counterparty_offset_payable_allocations oa
                    INNER JOIN counterparty_offsets o ON o.id = oa.counterparty_offset_id
                    INNER JOIN supplier_obligations so ON so.id = oa.supplier_obligation_id
                    INNER JOIN bookings b ON b.booking_reference = so.booking_reference
                    INNER JOIN branches br ON br.id = b.branch_id
                    INNER JOIN suppliers s ON s.id = o.supplier_id
                    LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN booking_services bs
                        ON bs.booking_id = b.id
                       AND bs.line_reference = so.service_line_reference
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    LEFT JOIN travelers t ON t.id = bs.traveler_id
                    WHERE o.status = "posted"
                      AND oa.allocated_amount > 0.005';
        }

        $supplierPenaltyExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)), 0.00)';
        $supplierPenaltySourceExpression = 'COALESCE(
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_source_amount")), "") AS DECIMAL(18,2)),
            ' . $supplierPenaltyExpression . ',
            0.00
        )';
        $expectedSupplierRefundExpression = 'COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)), 0.00)';
        $expectedSupplierRefundSourceExpression = 'COALESCE(
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_source_amount")), "") AS DECIMAL(18,2)),
            ' . $expectedSupplierRefundExpression . ',
            0.00
        )';
        $supplierLedgerCurrencyExpression = 'COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_currency")), ""),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_currency")), ""),
            NULLIF(bs.cost_currency, ""),
            NULLIF(bs.currency, ""),
            bse.currency
        )';
        $supplierCostBasisExpression = 'COALESCE(
            NULLIF((' . $supplierPenaltySourceExpression . ' + ' . $expectedSupplierRefundSourceExpression . '), 0.00),
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_cost_basis")), "") AS DECIMAL(18,2)),
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.prior_supplier_obligation_gross_amount")), "") AS DECIMAL(18,2)),
            ' . $supplierPenaltyExpression . ' + ' . $expectedSupplierRefundExpression . ',
            0.00
        )';
        $latestCancellationSql = 'bse.id = (
            SELECT MAX(bse_latest.id)
            FROM booking_service_events bse_latest
            WHERE bse_latest.booking_id = bse.booking_id
              AND bse_latest.booking_service_id = bse.booking_service_id
              AND bse_latest.currency = bse.currency
              AND bse_latest.event_type = "cancel"
              AND bse_latest.event_status = "posted"
        )';

        $sql = 'SELECT
                    ledger_rows.branch_id,
                    ledger_rows.branch_name,
                    ledger_rows.booking_id,
                    ledger_rows.booking_reference,
                    ledger_rows.ledger_date,
                    ledger_rows.service_line_reference,
                    ledger_rows.currency,
                    ledger_rows.business_source_id,
                    ledger_rows.business_source_name,
                    ledger_rows.supplier_id,
                    ledger_rows.supplier_name,
                    ledger_rows.customer_name,
                    ledger_rows.passenger_name,
                    ledger_rows.route,
                ledger_rows.airline,
                ledger_rows.supplier_payment_id,
                ledger_rows.journal_entry_id,
                ledger_rows.source_reference,
                ledger_rows.posted_by_name,
                ledger_rows.narration,
                ledger_rows.debit_amount,
                ledger_rows.credit_amount,
                ledger_rows.entry_type
                FROM (
                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.id AS booking_id,
                        b.booking_reference,
                        COALESCE(NULLIF(jel.service_line_reference, ""), so.service_line_reference) AS service_line_reference,
                        je.currency,
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                        CASE
                            WHEN je.source_type IN ("supplier_payment_allocated", "supplier_payment_void_reversed")
                             AND source_payment_supplier.id IS NOT NULL
                                THEN source_payment_supplier.id
                            ELSE s.id
                        END AS supplier_id,
                        CASE
                            WHEN je.source_type IN ("supplier_payment_allocated", "supplier_payment_void_reversed")
                             AND source_payment_supplier.id IS NOT NULL
                                THEN source_payment_supplier.name
                            ELSE s.name
                        END AS supplier_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                        COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                        COALESCE(NULLIF(sat.airline, ""), "N/A") AS airline,
                        jel.supplier_payment_id,
                        je.id AS journal_entry_id,
                        je.source_reference,
                        COALESCE(NULLIF(posted_by.name, ""), NULLIF(posted_by.username, ""), NULLIF(posted_by.email, ""), "System") AS posted_by_name,
                        je.narration,
                        je.entry_date AS ledger_date,
                        jel.debit_amount,
                        jel.credit_amount,
                        CASE je.source_type
                            WHEN "supplier_payable_created" THEN "Payable Created"
                            WHEN "supplier_payable_adjusted" THEN "Payable Adjustment"
                            WHEN "supplier_payment_allocated" THEN
                                CASE
                                    WHEN source_payment.id IS NOT NULL
                                     AND UPPER(COALESCE(source_payment.booking_reference, "")) NOT IN ("", "GLOBAL")
                                     AND source_payment.booking_reference COLLATE utf8mb4_unicode_ci <> so.booking_reference COLLATE utf8mb4_unicode_ci
                                        THEN "Supplier Credit Applied"
                                    ELSE "Supplier Payment"
                                END
                            WHEN "supplier_advance_applied" THEN
                                CASE
                                    WHEN COALESCE(je.source_reference, "") LIKE "REFUND-EVT-%"
                                        THEN "Refund Advance Applied"
                                    ELSE "Advance Applied"
                                END
                            WHEN "supplier_advance_adjusted" THEN "Advance Adjustment"
                            WHEN "customer_direct_supplier_payment" THEN "Customer Paid Supplier"
                            WHEN "supplier_payment_void_reversed" THEN "Supplier Payment Reversed"
                            ELSE "Supplier Account Entry"
                        END AS entry_type,
                        CASE je.source_type
                            WHEN "supplier_payable_created" THEN 10
                            WHEN "supplier_payable_adjusted" THEN 15
                            WHEN "supplier_payment_allocated" THEN 20
                            WHEN "customer_direct_supplier_payment" THEN 20
                            WHEN "supplier_payment_void_reversed" THEN 25
                            WHEN "supplier_advance_applied" THEN 30
                            WHEN "supplier_advance_adjusted" THEN 35
                            ELSE 39
                        END AS sort_order,
                        CONCAT(LPAD(je.id, 12, "0"), "-", LPAD(jel.id, 12, "0")) AS sort_reference
                    FROM journal_entries je
                    INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.code = "AP_CONTROL"
                    INNER JOIN supplier_obligations so ON so.id = jel.supplier_obligation_id
                    ' . $serviceContextSql . '
                    LEFT JOIN users posted_by ON posted_by.id = je.posted_by_user_id
                    LEFT JOIN supplier_payments source_payment ON source_payment.id = jel.supplier_payment_id
                    LEFT JOIN suppliers source_payment_supplier ON source_payment_supplier.id = source_payment.supplier_id
                    WHERE je.source_type IN (
                        "supplier_payable_created",
                        "supplier_payable_adjusted",
                        "supplier_payment_allocated",
                        "supplier_advance_applied",
                        "supplier_advance_adjusted",
                        "customer_direct_supplier_payment",
                        "supplier_payment_void_reversed"
                    )
                      AND (jel.debit_amount > 0.005 OR jel.credit_amount > 0.005)
                      AND NOT (
                          je.source_type = "supplier_payable_adjusted"
                          AND je.narration LIKE "Cancellation supplier penalty adjustment%"
                      )
                      AND NOT (
                          je.source_type = "supplier_payable_adjusted"
                          AND EXISTS (
                              SELECT 1
                              FROM journal_entries je_data_reversal
                              WHERE je_data_reversal.booking_reference = je.booking_reference
                                AND je_data_reversal.currency = je.currency
                                AND je_data_reversal.source_type = "data_integrity_reversal"
                                AND je_data_reversal.narration LIKE CONCAT("% journal ", je.id)
                          )
                      )

                    ' . $convertedAdvanceSql . '

                    ' . $standaloneSupplierAdvanceSql . '

                    ' . $linkedPartyOffsetSql . '

                    UNION ALL

                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.id AS booking_id,
                        b.booking_reference,
                        bse.service_line_reference,
                        ' . $supplierLedgerCurrencyExpression . ' AS currency,
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                        COALESCE(s.id, 0) AS supplier_id,
                        COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                        COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                        COALESCE(NULLIF(sat.airline, ""), "N/A") AS airline,
                        NULL AS supplier_payment_id,
                        NULL AS journal_entry_id,
                        NULL AS source_reference,
                        NULL AS posted_by_name,
                        NULL AS narration,
                        bse.event_date AS ledger_date,
                        ' . $supplierCostBasisExpression . ' AS debit_amount,
                        0.00 AS credit_amount,
                        "Payable Reversed on Cancellation" AS entry_type,
                        40 AS sort_order,
                        CAST(bse.id AS CHAR) AS sort_reference
                    FROM booking_service_events bse
                    INNER JOIN bookings b ON b.id = bse.booking_id
                    INNER JOIN branches br ON br.id = b.branch_id
                    INNER JOIN booking_services bs ON bs.id = bse.booking_service_id
                    LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    LEFT JOIN travelers t ON t.id = bs.traveler_id
                    WHERE bse.event_status = "posted"
                      AND bse.event_type = "cancel"
                      AND ' . $latestCancellationSql . '
                      AND ' . $supplierCostBasisExpression . ' > 0.005

                    UNION ALL

                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.id AS booking_id,
                        b.booking_reference,
                        bse.service_line_reference,
                        ' . $supplierLedgerCurrencyExpression . ' AS currency,
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                        COALESCE(s.id, 0) AS supplier_id,
                        COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                        COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                        COALESCE(NULLIF(sat.airline, ""), "N/A") AS airline,
                        NULL AS supplier_payment_id,
                        NULL AS journal_entry_id,
                        NULL AS source_reference,
                        NULL AS posted_by_name,
                        NULL AS narration,
                        bse.event_date AS ledger_date,
                        0.00 AS debit_amount,
                        ' . $supplierPenaltySourceExpression . ' AS credit_amount,
                        "Supplier Penalty Retained" AS entry_type,
                        45 AS sort_order,
                        CAST(bse.id AS CHAR) AS sort_reference
                    FROM booking_service_events bse
                    INNER JOIN bookings b ON b.id = bse.booking_id
                    INNER JOIN branches br ON br.id = b.branch_id
                    INNER JOIN booking_services bs ON bs.id = bse.booking_service_id
                    LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    LEFT JOIN travelers t ON t.id = bs.traveler_id
                    WHERE bse.event_status = "posted"
                      AND bse.event_type = "cancel"
                      AND ' . $latestCancellationSql . '
                      AND ' . $supplierPenaltyExpression . ' > 0.005

                    UNION ALL

                    SELECT
                        b.branch_id,
                        br.name AS branch_name,
                        b.id AS booking_id,
                        b.booking_reference,
                        bse.service_line_reference,
                        COALESCE(NULLIF(refund_detail.refund_currency, ""), ' . $supplierLedgerCurrencyExpression . ') AS currency,
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                        COALESCE(s.id, 0) AS supplier_id,
                        COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Customer") AS customer_name,
                        COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                        COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                        COALESCE(NULLIF(sat.airline, ""), "N/A") AS airline,
                        NULL AS supplier_payment_id,
                        NULL AS journal_entry_id,
                        NULL AS source_reference,
                        NULL AS posted_by_name,
                        NULL AS narration,
                        bse.event_date AS ledger_date,
                        0.00 AS debit_amount,
                        COALESCE(refund_detail.refund_source_amount, bse.supplier_refund_amount) AS credit_amount,
                        CASE
                            WHEN COALESCE(
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_refund_payment_method")), ""),
                                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.refund_detail.refund_payment_method")), "")
                            ) = "supplier_credit"
                                THEN "Supplier Refund Retained as Credit"
                            ELSE "Supplier Refund Received"
                        END AS entry_type,
                        50 AS sort_order,
                        CAST(bse.id AS CHAR) AS sort_reference
                    FROM booking_service_events bse
                    INNER JOIN bookings b ON b.id = bse.booking_id
                    INNER JOIN branches br ON br.id = b.branch_id
                    INNER JOIN booking_services bs ON bs.id = bse.booking_service_id
                    LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    LEFT JOIN travelers t ON t.id = bs.traveler_id
                    LEFT JOIN booking_service_refund_details refund_detail
                        ON refund_detail.service_event_id = bse.id
                    WHERE bse.event_status = "posted"
                      AND bse.event_type = "refund"
                      AND bse.supplier_refund_amount > 0.005
                ) AS ledger_rows
                WHERE ledger_rows.branch_id ' . $clause . $dateSql . $currencySql . $airlineSql . $supplierSql . $accountSql . $bookingReferenceSql . '
                ORDER BY ledger_rows.supplier_name ASC,
                         ledger_rows.currency ASC,
                         ledger_rows.ledger_date ASC,
                         ledger_rows.booking_reference ASC,
                         ledger_rows.service_line_reference ASC,
                         ledger_rows.sort_order ASC,
                         ledger_rows.sort_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function bookingVoucherLedger(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        int $businessSourceId = 0,
        string $customerName = '',
        int $supplierId = 0,
        string $bookingReference = ''
    ): array {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('voucher_rows.ledger_date', $dateFrom, $dateTo, $params, 'voucher_');
        $currencySql = '';
        $currency = strtoupper(trim($currency));
        if ($currency !== '') {
            $currencySql = ' AND voucher_rows.currency = :voucher_currency';
            $params['voucher_currency'] = $currency;
        }

        $accountSql = '';
        if ($businessSourceId > 0) {
            $accountSql = ' AND voucher_rows.business_source_id = :voucher_business_source_id';
            $params['voucher_business_source_id'] = $businessSourceId;
        }

        $customerSql = '';
        $customerName = trim($customerName);
        if ($customerName !== '') {
            $customerSql = ' AND voucher_rows.customer_name COLLATE utf8mb4_unicode_ci = :voucher_customer_name';
            $params['voucher_customer_name'] = $customerName;
        }

        $supplierSql = '';
        if ($supplierId > 0) {
            $supplierSql = ' AND voucher_rows.supplier_id = :voucher_supplier_id';
            $params['voucher_supplier_id'] = $supplierId;
        }

        $bookingReferenceSql = '';
        $bookingReference = trim($bookingReference);
        if ($bookingReference !== '') {
            $bookingReferenceSql = ' AND voucher_rows.booking_reference LIKE :voucher_booking_reference';
            $params['voucher_booking_reference'] = '%' . $bookingReference . '%';
        }

        $sql = 'SELECT
                    voucher_rows.branch_id,
                    voucher_rows.branch_name,
                    voucher_rows.booking_id,
                    voucher_rows.booking_reference,
                    voucher_rows.ledger_date,
                    voucher_rows.party_name,
                    voucher_rows.entry_type,
                    voucher_rows.passenger_name,
                    voucher_rows.currency,
                    voucher_rows.debit_amount,
                    voucher_rows.credit_amount
                FROM (
                    SELECT
                        je.branch_id,
                        br.name AS branch_name,
                        COALESCE(b.id, 0) AS booking_id,
                        COALESCE(b.booking_reference, je.booking_reference, je.source_reference, "") AS booking_reference,
                        COALESCE(b.business_source_id, 0) AS business_source_id,
                        COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                        COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                        COALESCE(s.id, sp_s.id, so_s.id, sa_s.id, 0) AS supplier_id,
                        COALESCE(NULLIF(s.name, ""), NULLIF(sp_s.name, ""), NULLIF(so_s.name, ""), NULLIF(sa_s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), "Supplier pending") AS supplier_name,
                        je.entry_date AS ledger_date,
                        je.currency,
                        CASE
                            WHEN coa.code IN ("AP_CONTROL", "SUPPLIER_ADVANCES") THEN COALESCE(NULLIF(s.name, ""), NULLIF(sp_s.name, ""), NULLIF(so_s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), "Supplier pending")
                            ELSE COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party")
                        END AS party_name,
                        CASE
                            WHEN je.source_type = "service_receivable_created" AND coa.code = "AR_CONTROL" THEN "Customer Invoice"
                            WHEN je.source_type = "service_receivable_adjusted" AND coa.code = "AR_CONTROL" THEN "Customer Invoice Adjustment"
                            WHEN je.source_type = "supplier_payable_created" AND coa.code = "AP_CONTROL" THEN "Supplier Payable"
                            WHEN je.source_type = "supplier_payable_adjusted" AND coa.code = "AP_CONTROL" THEN "Supplier Payable Adjustment"
                            WHEN je.source_type = "customer_receipt_recorded" AND coa.code = "CUSTOMER_CREDIT" THEN "Customer Receipt Credit"
                            WHEN je.source_type = "customer_receipt_allocated" AND coa.code = "CUSTOMER_CREDIT" THEN "Customer Credit Applied"
                            WHEN je.source_type = "customer_receipt_allocated" AND coa.code = "AR_CONTROL" THEN "Customer Payment Applied"
                            WHEN je.source_type = "customer_direct_supplier_payment" THEN "Customer Paid Supplier"
                            WHEN je.source_type = "customer_receipt_allocation_released" AND coa.code = "AR_CONTROL" THEN "Customer Receivable Restored"
                            WHEN je.source_type = "customer_receipt_allocation_released" AND coa.code = "CUSTOMER_CREDIT" THEN "Customer Refund Credit"
                            WHEN je.source_type = "customer_advance_refunded" AND coa.code = "CUSTOMER_CREDIT" THEN "Customer Advance Returned"
                            WHEN je.source_type = "supplier_payment_recorded" AND coa.code = "SUPPLIER_ADVANCES" THEN "Supplier Payment Credit"
                            WHEN je.source_type = "supplier_advance_recorded" AND coa.code = "SUPPLIER_ADVANCES" THEN "Supplier Advance Recorded"
                            WHEN je.source_type = "supplier_payment_allocated" AND coa.code = "SUPPLIER_ADVANCES" THEN "Supplier Credit Applied"
                            WHEN je.source_type = "supplier_payment_allocated" AND coa.code = "AP_CONTROL" THEN "Supplier Payment Applied"
                            WHEN je.source_type = "supplier_settlement_released" AND coa.code = "SUPPLIER_ADVANCES" THEN "Supplier Refund Receivable"
                            WHEN je.source_type = "supplier_settlement_released" AND coa.code = "AP_CONTROL" THEN "Supplier Payable Restored"
                            WHEN je.source_type = "service_refund_posted" AND coa.code = "CUSTOMER_CREDIT" THEN "Customer Refund Paid"
                            WHEN je.source_type = "service_refund_posted" AND coa.code = "SUPPLIER_ADVANCES" THEN "Supplier Refund Received"
                            WHEN je.source_type IN ("service_refund_reversed", "service_refund_component_reversed") THEN "Refund Correction"
                            WHEN je.source_type IN ("supplier_payment_void_reversed", "customer_receipt_void_reversed", "journal_reversal", "service_cancellation_financials_reversed") THEN "Reversal"
                            ELSE COALESCE(NULLIF(jel.line_description, ""), NULLIF(je.narration, ""), "Journal Entry")
                        END AS entry_type,
                        ROUND(COALESCE(jel.debit_amount, 0), 2) AS debit_amount,
                        ROUND(COALESCE(jel.credit_amount, 0), 2) AS credit_amount,
                        CASE
                            WHEN je.source_type LIKE "%_reversed" OR je.source_type LIKE "%reversal%" THEN 90
                            WHEN je.source_type IN ("service_receivable_created", "service_receivable_adjusted") THEN 10
                            WHEN je.source_type IN ("supplier_payable_created", "supplier_payable_adjusted") THEN 20
                            WHEN je.source_type LIKE "customer_receipt%" THEN 30
                            WHEN je.source_type LIKE "supplier_payment%" THEN 40
                            WHEN je.source_type IN ("customer_receipt_allocation_released", "supplier_settlement_released") THEN 50
                            WHEN je.source_type = "service_refund_posted" THEN 60
                            ELSE 80
                        END AS sort_order,
                        CAST(jel.id AS CHAR) AS sort_reference
                    FROM journal_entries je
                    INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                    INNER JOIN branches br ON br.id = je.branch_id
                    LEFT JOIN bookings b ON b.booking_reference = je.booking_reference
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                    LEFT JOIN booking_services bs
                        ON bs.booking_id = b.id
                       AND bs.line_reference = jel.service_line_reference
                    LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                    LEFT JOIN supplier_obligations so ON so.id = jel.supplier_obligation_id
                    LEFT JOIN suppliers so_s ON so_s.id = so.supplier_id
                    LEFT JOIN supplier_payments sp ON sp.id = jel.supplier_payment_id
                    LEFT JOIN suppliers sp_s ON sp_s.id = sp.supplier_id
                    LEFT JOIN supplier_advances sa ON sa.journal_entry_id = je.id
                    LEFT JOIN suppliers sa_s ON sa_s.id = sa.supplier_id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    WHERE coa.code IN ("AR_CONTROL", "AP_CONTROL", "CUSTOMER_CREDIT", "SUPPLIER_ADVANCES")
                      AND (ROUND(COALESCE(jel.debit_amount, 0), 2) > 0 OR ROUND(COALESCE(jel.credit_amount, 0), 2) > 0)
                ) AS voucher_rows
                WHERE voucher_rows.branch_id ' . $clause . $dateSql . $currencySql . $accountSql . $customerSql . $supplierSql . $bookingReferenceSql . '
                ORDER BY voucher_rows.ledger_date DESC,
                         voucher_rows.booking_reference DESC,
                         voucher_rows.currency ASC,
                         voucher_rows.sort_order ASC,
                         voucher_rows.sort_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function actualMoneyVoucherLedger(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        string $customerName = '',
        int $supplierId = 0,
        string $bookingReference = '',
        int $businessSourceId = 0
    ): array {
        $rows = $this->cashBankLedger($branchIds, $dateFrom, $dateTo, $currency, 'all');
        if ($businessSourceId > 0) {
            $bookingIds = array_values(array_unique(array_filter(array_map(
                static fn (array $row): int => (int) ($row['booking_id'] ?? 0),
                $rows
            ))));
            if ($bookingIds === []) {
                $rows = [];
            } else {
                $params = ['actual_money_business_source_id' => $businessSourceId];
                $placeholders = [];
                foreach ($bookingIds as $index => $bookingId) {
                    $key = 'actual_money_booking_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $bookingId;
                }
                $allowedRows = $this->fetchRows(
                    'SELECT id
                     FROM bookings
                     WHERE business_source_id = :actual_money_business_source_id
                       AND id IN (' . implode(', ', $placeholders) . ')',
                    $params
                );
                $allowedBookingIds = array_fill_keys(array_map(
                    static fn (array $row): int => (int) ($row['id'] ?? 0),
                    $allowedRows
                ), true);
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => isset($allowedBookingIds[(int) ($row['booking_id'] ?? 0)])
                ));
            }
        }
        $customerName = strtolower(trim($customerName));
        $supplierName = $supplierId > 0 ? strtolower($this->supplierNameById($supplierId)) : '';
        $bookingReference = strtolower(trim($bookingReference));

        $voucherRows = [];
        foreach ($rows as $row) {
            $partyName = trim((string) ($row['party_name'] ?? ''));
            $partyNameLower = strtolower($partyName);
            $sourceType = strtolower(trim((string) ($row['source_type'] ?? '')));

            if ($customerName !== '' && $partyNameLower !== $customerName) {
                continue;
            }

            if ($supplierName !== '') {
                $isSupplierRow = str_starts_with($sourceType, 'supplier_payment')
                    || str_contains($sourceType, 'supplier_refund');
                if (! $isSupplierRow || $partyNameLower !== $supplierName) {
                    continue;
                }
            }

            $rowBookingReference = strtolower(trim((string) (($row['reference'] ?? '') !== '' ? $row['reference'] : '')));
            if ($bookingReference !== '' && ! str_contains($rowBookingReference, $bookingReference)) {
                continue;
            }

            $voucherRows[] = [
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'booking_reference' => (string) (($row['reference'] ?? '') !== '' ? $row['reference'] : 'N/A'),
                'booking_reference_href' => (int) ($row['booking_id'] ?? 0) > 0
                    ? url('/workspace?booking_id=' . (int) ($row['booking_id'] ?? 0))
                    : '',
                'ledger_date' => (string) (($row['entry_date'] ?? '') !== '' ? $row['entry_date'] : 'N/A'),
                'party_name' => $partyName !== '' ? $partyName : 'N/A',
                'entry_type' => $this->actualMoneyVoucherEntryType($sourceType),
                'passenger_name' => (string) (($row['description'] ?? '') !== '' ? $row['description'] : 'N/A'),
                'currency' => (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'PKR'),
                'debit_amount' => round((float) ($row['debit_amount'] ?? 0), 2),
                'credit_amount' => round((float) ($row['credit_amount'] ?? 0), 2),
            ];
        }

        usort($voucherRows, static function (array $left, array $right): int {
            return [
                (string) ($right['ledger_date'] ?? ''),
                (string) ($right['booking_reference'] ?? ''),
                (string) ($right['entry_type'] ?? ''),
            ] <=> [
                (string) ($left['ledger_date'] ?? ''),
                (string) ($left['booking_reference'] ?? ''),
                (string) ($left['entry_type'] ?? ''),
            ];
        });

        return $voucherRows;
    }

    private function actualMoneyVoucherEntryType(string $sourceType): string
    {
        return match ($sourceType) {
            'customer_receipt_recorded' => 'Customer Receipt',
            'customer_receipt_void_reversal' => 'Customer Receipt Reversal',
            'supplier_payment_recorded' => 'Supplier Payment',
            'supplier_payment_void_reversal' => 'Supplier Payment Reversal',
            'business_expense_recorded' => 'Expense Payment',
            'business_expense_corrected_reversal' => 'Expense Reversal',
            'direct_treasury_entry_posted' => 'Direct Cash / Bank Entry',
            'direct_treasury_entry_void_reversal' => 'Direct Entry Reversal',
            'treasury_transfer_posted' => 'Treasury Transfer',
            'treasury_transfer_void_reversal' => 'Treasury Transfer Reversal',
            default => ucwords(str_replace('_', ' ', $sourceType !== '' ? $sourceType : 'Money Movement')),
        };
    }

    private function supplierNameById(int $supplierId): string
    {
        $rows = $this->fetchRows(
            'SELECT name FROM suppliers WHERE id = :supplier_id LIMIT 1',
            ['supplier_id' => $supplierId]
        );

        return trim((string) ($rows[0]['name'] ?? ''));
    }

    public function airlineSalesRegister(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    b.booking_date,
                    bp.lead_traveler_name,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.pnr,
                    sat.ticket_number,
                    sat.sector_from,
                    sat.sector_to,
                    sat.departure_date,
                    sat.return_date,
                    sat.travel_class,
                    sat.fare,
                    sat.ticket_tax,
                    sat.ticket_vat,
                    sat.ticket_commission,
                    sat.supplier_cost,
                    sat.sale_amount,
                    bs.service_status,
                    bs.remarks,
                    sat.ticket_remarks
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, COALESCE(sat.departure_date, b.booking_date) DESC, sat.airline ASC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function customerDepartureRegister(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $effectiveDateFrom = $dateFrom;
        if ($effectiveDateFrom === null || trim($effectiveDateFrom) === '' || trim($effectiveDateFrom) < $tomorrow) {
            $effectiveDateFrom = $tomorrow;
        }
        $dateSql = $this->ticketDateWindow($effectiveDateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bp.lead_traveler_name,
                    bp.contact_mobile,
                    bs.line_reference,
                    COALESCE(t.full_name, bs.passenger_name_snapshot) AS passenger_name,
                    sat.airline,
                    sat.pnr,
                    sat.ticket_number,
                    sat.sector_from,
                    sat.sector_to,
                    sat.departure_date
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t ON t.id = bs.traveler_id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY COALESCE(sat.departure_date, b.booking_date) ASC, br.name ASC, b.booking_reference ASC, bs.line_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlinePayableReport(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                    COALESCE(NULLIF(t.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    sat.ticket_number,
                    sat.departure_date,
                    so.gross_amount,
                    so.advance_applied_amount,
                    so.net_payable_amount,
                    so.due_date,
                    so.status
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN travelers t ON t.id = bs.traveler_id
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.airline ASC, so.due_date IS NULL, so.due_date ASC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlineCommissionReport(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.id AS booking_id,
                    b.booking_reference,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.ticket_number,
                    sat.departure_date,
                    sat.fare,
                    sat.ticket_tax,
                    sat.ticket_vat,
                    sat.ticket_commission,
                    bs.commission AS service_commission,
                    bs.service_charge
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.airline ASC, sat.departure_date DESC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function issueReissueRefundRegister(array $branchIds, ?string $dateFrom, ?string $dateTo, string $transactionType = 'all', string $bookingReference = ''): array
    {
        $params = [];
        $eventBranchPlaceholders = [];
        $fallbackBranchPlaceholders = [];
        foreach (array_values($branchIds) as $index => $branchId) {
            $eventKey = 'event_branch_' . $index;
            $fallbackKey = 'fallback_branch_' . $index;
            $eventBranchPlaceholders[] = ':' . $eventKey;
            $fallbackBranchPlaceholders[] = ':' . $fallbackKey;
            $params[$eventKey] = (int) $branchId;
            $params[$fallbackKey] = (int) $branchId;
        }

        $eventDateSql = '';
        $fallbackDateSql = '';
        if ($dateFrom !== null) {
            $eventDateSql .= ' AND bse.event_date >= :event_date_from';
            $fallbackDateSql .= ' AND COALESCE(sat.departure_date, b.booking_date) >= :fallback_date_from';
            $params['event_date_from'] = $dateFrom;
            $params['fallback_date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $eventDateSql .= ' AND bse.event_date <= :event_date_to';
            $fallbackDateSql .= ' AND COALESCE(sat.departure_date, b.booking_date) <= :fallback_date_to';
            $params['event_date_to'] = $dateTo;
            $params['fallback_date_to'] = $dateTo;
        }

        $eventBookingSql = '';
        $fallbackBookingSql = '';
        $bookingReference = trim($bookingReference);
        if ($bookingReference !== '') {
            $eventBookingSql = ' AND b.booking_reference LIKE :ticket_register_event_booking_reference';
            $fallbackBookingSql = ' AND b.booking_reference LIKE :ticket_register_fallback_booking_reference';
            $params['ticket_register_event_booking_reference'] = '%' . $bookingReference . '%';
            $params['ticket_register_fallback_booking_reference'] = '%' . $bookingReference . '%';
        }

        $unionSql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.id AS booking_id,
                    b.booking_reference,
                    bse.event_date AS transaction_date,
                    bs.line_reference,
                    bs.currency,
                    COALESCE(NULLIF(s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), NULLIF(sat.airline, ""), "Supplier pending") AS supplier_name,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    sat.ticket_number,
                    sat.pnr,
                    sat.departure_date,
                    bs.service_status,
                    sat.sale_amount AS issue_customer_debit,
                    sat.supplier_cost AS issue_supplier_credit,
                    bse.fare_difference_amount,
                    bse.service_fee_amount,
                    bse.penalty_amount AS customer_penalty_amount,
                    COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)), 0.00) AS supplier_penalty_amount,
                    COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bse.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)), 0.00) AS expected_supplier_refund_amount,
                    bse.customer_refund_amount,
                    bse.supplier_refund_amount,
                    CASE
                        WHEN bse.event_type = "refund" AND bse.supplier_refund_amount > 0.005
                            THEN COALESCE(NULLIF(s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), NULLIF(sat.airline, ""), "Supplier")
                        WHEN bse.event_type = "refund" AND bse.customer_refund_amount > 0.005
                            THEN COALESCE(NULLIF(refund_ta.account_name, ""), "Treasury")
                        ELSE ""
                    END AS refund_source_account,
                    CASE
                        WHEN bse.event_type = "refund" AND bse.supplier_refund_amount > 0.005
                            THEN COALESCE(NULLIF(refund_ta.account_name, ""), "Treasury")
                        WHEN bse.event_type = "refund" AND bse.customer_refund_amount > 0.005
                            THEN COALESCE(NULLIF(TRIM(CONCAT_WS(" | ",
                                NULLIF(refund_detail.customer_bank_name, ""),
                                NULLIF(refund_detail.customer_bank_account_title, ""),
                                CASE
                                    WHEN NULLIF(refund_detail.customer_bank_account_no, "") IS NOT NULL
                                        THEN CONCAT("A/C ", refund_detail.customer_bank_account_no)
                                    ELSE NULL
                                END,
                                CASE
                                    WHEN NULLIF(refund_detail.customer_bank_iban, "") IS NOT NULL
                                        THEN CONCAT("IBAN ", refund_detail.customer_bank_iban)
                                    ELSE NULL
                                END
                            )), ""), "Customer")
                        ELSE ""
                    END AS refund_destination_detail,
                    COALESCE(refund_detail.transfer_reference, "") AS transfer_reference,
                    COALESCE(NULLIF(bse.notes, ""), NULLIF(bse.reason, ""), sat.ticket_remarks) AS ticket_remarks,
                    CASE bse.event_type
                        WHEN "refund" THEN "Refund"
                        WHEN "reissue" THEN "Reissue"
                        WHEN "cancel" THEN "Cancel"
                        ELSE "Issue"
                    END AS transaction_type
                FROM booking_service_events bse
                INNER JOIN booking_services bs ON bs.id = bse.booking_service_id
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bse.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                LEFT JOIN booking_service_refund_details refund_detail
                    ON refund_detail.service_event_id = bse.id
                LEFT JOIN treasury_accounts refund_ta
                    ON refund_ta.id = refund_detail.treasury_account_id
                WHERE b.branch_id IN (' . implode(', ', $eventBranchPlaceholders) . ')
                  AND bse.event_status = "posted"'
                . $eventDateSql . $eventBookingSql . '
                UNION ALL
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.id AS booking_id,
                    b.booking_reference,
                    COALESCE(sat.departure_date, b.booking_date) AS transaction_date,
                    bs.line_reference,
                    bs.currency,
                    COALESCE(NULLIF(s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), NULLIF(sat.airline, ""), "Supplier pending") AS supplier_name,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    sat.ticket_number,
                    sat.pnr,
                    sat.departure_date,
                    bs.service_status,
                    sat.sale_amount AS issue_customer_debit,
                    sat.supplier_cost AS issue_supplier_credit,
                    0.00 AS fare_difference_amount,
                    0.00 AS service_fee_amount,
                    0.00 AS customer_penalty_amount,
                    0.00 AS supplier_penalty_amount,
                    0.00 AS expected_supplier_refund_amount,
                    0.00 AS customer_refund_amount,
                    0.00 AS supplier_refund_amount,
                    "" AS refund_source_account,
                    "" AS refund_destination_detail,
                    "" AS transfer_reference,
                    sat.ticket_remarks,
                    CASE
                        WHEN LOWER(COALESCE(bs.service_status, "")) = "cancelled" THEN "Cancel"
                        ELSE "Issue"
                    END AS transaction_type
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                WHERE b.branch_id IN (' . implode(', ', $fallbackBranchPlaceholders) . ')
                  AND bs.is_active = 1'
                . $fallbackBookingSql
                . ' AND NOT EXISTS (
                    SELECT 1
                    FROM booking_service_events bse_existing
                    WHERE bse_existing.booking_service_id = bs.id
                      AND bse_existing.event_status = "posted"
                )'
                . $fallbackDateSql;

        $outerFilters = ['transaction_type IN ("Issue", "Reissue", "Refund")'];
        if (in_array($transactionType, ['issue', 'reissue', 'refund'], true)) {
            $outerFilters[] = 'LOWER(transaction_type) = :transaction_type';
            $params['transaction_type'] = $transactionType;
        }

        $sql = 'SELECT *
                FROM (' . $unionSql . ') register_rows
                WHERE ' . implode(' AND ', $outerFilters) . '
                ORDER BY transaction_date DESC, booking_reference DESC, line_reference ASC, transaction_type ASC';

        return $this->fetchRows($sql, $params);
    }

    public function financialCorrectionRegister(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        int $businessSourceId = 0,
        string $bookingReference = ''
    ): array {
        [$clause, $params] = $this->branchScope($branchIds);
        $filters = [
            'sfc.branch_id ' . $clause,
        ];

        if ($dateFrom !== null) {
            $filters[] = 'sfc.correction_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $filters[] = 'sfc.correction_date <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($businessSourceId > 0) {
            $filters[] = 'b.business_source_id = :business_source_id';
            $params['business_source_id'] = $businessSourceId;
        }
        $bookingReference = trim($bookingReference);
        if ($bookingReference !== '') {
            $filters[] = 'sfc.booking_reference LIKE :booking_reference';
            $params['booking_reference'] = '%' . $bookingReference . '%';
        }

        $sql = 'SELECT
                    sfc.id,
                    sfc.branch_id,
                    br.name AS branch_name,
                    sfc.booking_id,
                    sfc.booking_reference,
                    sfc.service_line_reference,
                    sfc.service_type,
                    sfc.correction_date,
                    COALESCE(bs.currency, "PKR") AS currency,
                    sfc.correction_reason,
                    sfc.correction_note,
                    sfc.prior_sale_price,
                    sfc.new_sale_price,
                    sfc.prior_purchase_cost,
                    sfc.new_purchase_cost,
                    sfc.prior_service_charge,
                    sfc.new_service_charge,
                    sfc.prior_discount_amount,
                    sfc.new_discount_amount,
                    sfc.prior_vat_amount,
                    sfc.new_vat_amount,
                    sfc.prior_final_sale_price,
                    sfc.new_final_sale_price,
                    sfc.released_customer_credit_amount,
                    sfc.released_supplier_credit_amount,
                    sfc.created_at,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    COALESCE(u.name, u.username, "System") AS edited_by
                FROM service_financial_corrections sfc
                INNER JOIN bookings b ON b.id = sfc.booking_id
                INNER JOIN branches br ON br.id = sfc.branch_id
                LEFT JOIN booking_services bs ON bs.id = sfc.booking_service_id
                LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                LEFT JOIN users u ON u.id = sfc.created_by_user_id
                WHERE ' . implode(' AND ', $filters) . '
                ORDER BY sfc.correction_date DESC, sfc.id DESC';

        return $this->fetchRows($sql, $params);
    }

    public function bspSettlementSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    sat.airline,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(sat.sale_amount, bs.sale_price)) AS gross_sales,
                    SUM(COALESCE(sat.ticket_tax, 0)) AS ticket_tax,
                    SUM(COALESCE(sat.ticket_vat, 0)) AS ticket_vat,
                    SUM(COALESCE(sat.ticket_commission, 0) + COALESCE(bs.commission, 0)) AS total_commission,
                    SUM(COALESCE(sat.supplier_cost, bs.purchase_cost)) AS supplier_cost,
                    SUM(COALESCE(so.net_payable_amount, 0)) AS outstanding_settlement
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency, sat.airline
                  ORDER BY br.name ASC, sat.airline ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function ticketTaxVatSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    sat.airline,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(sat.ticket_tax, 0)) AS ticket_tax,
                    SUM(COALESCE(sat.ticket_vat, 0)) AS ticket_vat,
                    SUM(COALESCE(bs.taxes, 0)) AS service_tax,
                    SUM(COALESCE(bs.vat, 0)) AS service_vat
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency, sat.airline
                  ORDER BY br.name ASC, sat.airline ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlineWiseProfitability(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableInInvoiceCurrencyFormula = $this->servicePayableInInvoiceCurrencyFormula('bs', 'so');
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    sat.airline,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(cri.due_amount, ' . $receivableFormula . ')) AS receivable_amount,
                    SUM(' . $payableInInvoiceCurrencyFormula . ') AS payable_amount,
                    SUM(COALESCE(bs.service_charge, 0)) AS service_charge,
                    SUM(COALESCE(bs.discount_amount, 0)) AS discount_amount
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = COALESCE(NULLIF(bs.cost_currency, ""), bs.currency)
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency, sat.airline
                  ORDER BY br.name ASC, sat.airline ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function ticketingOutstandingReport(array $branchIds, string $asOfDate): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bp.lead_traveler_name,
                    bs.currency,
                    sat.airline,
                    sat.ticket_number,
                    cri.outstanding_amount AS customer_outstanding,
                    cri.due_date AS customer_due_date,
                    so.net_payable_amount AS supplier_outstanding,
                    so.due_date AS supplier_due_date,
                    DATEDIFF(:as_of_date_calc, COALESCE(cri.due_date, so.due_date, :as_of_date_fallback)) AS overdue_days
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN customer_receivable_items cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1
                  AND (COALESCE(cri.outstanding_amount, 0) > 0 OR COALESCE(so.net_payable_amount, 0) > 0)
                ORDER BY br.name ASC, bs.currency ASC, sat.airline ASC, sat.ticket_number ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            'as_of_date_calc' => $asOfDate,
            'as_of_date_fallback' => $asOfDate,
        ], $params));

        return $statement->fetchAll() ?: [];
    }

    public function branchWiseTicketingSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(sat.sale_amount, bs.sale_price)) AS gross_sales,
                    SUM(COALESCE(sat.ticket_commission, 0) + COALESCE(bs.commission, 0)) AS total_commission,
                    SUM(COALESCE(cri.outstanding_amount, 0)) AS customer_outstanding,
                    SUM(COALESCE(so.net_payable_amount, 0)) AS supplier_outstanding
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN customer_receivable_items cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency
                  ORDER BY br.name ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function ticketAuditControlRegister(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bp.lead_traveler_name,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.pnr,
                    sat.ticket_number,
                    sat.sector_from,
                    sat.sector_to,
                    sat.departure_date,
                    bs.service_status,
                    bs.is_active,
                    COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                    COALESCE(cri.status, "no_receivable") AS customer_status,
                    COALESCE(so.status, "no_payable") AS supplier_status,
                    CASE WHEN COALESCE(sat.ticket_number, "") = "" THEN "Missing Ticket No" ELSE "" END AS ticket_no_flag,
                    CASE WHEN COALESCE(sat.pnr, "") = "" THEN "Missing PNR" ELSE "" END AS pnr_flag,
                    CASE WHEN COALESCE(sat.airline, "") = "" THEN "Missing Airline" ELSE "" END AS airline_flag
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN customer_receivable_items cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.departure_date DESC, sat.airline ASC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    private function branchScope(array $branchIds, string $prefix = 'branch_'): array
    {
        if ($branchIds === []) {
            return ['IN (NULL)', []];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($branchIds) as $index => $branchId) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $branchId;
        }

        return ['IN (' . implode(', ', $placeholders) . ')', $params];
    }

    private function bookingDateWindow(string $column, ?string $dateFrom, ?string $dateTo, array &$params, string $prefix = ''): string
    {
        $sql = '';
        if ($dateFrom !== null) {
            $sql .= ' AND ' . $column . ' >= :' . $prefix . 'date_from';
            $params[$prefix . 'date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $sql .= ' AND ' . $column . ' <= :' . $prefix . 'date_to';
            $params[$prefix . 'date_to'] = $dateTo;
        }

        return $sql;
    }

    private function ticketDateWindow(?string $dateFrom, ?string $dateTo, array &$params): string
    {
        return $this->bookingDateWindow('COALESCE(sat.departure_date, b.booking_date)', $dateFrom, $dateTo, $params);
    }

    private function receivableAggregateSql(): string
    {
        return '(SELECT
                    booking_reference,
                    service_line_reference,
                    currency,
                    SUM(due_amount) AS due_amount,
                    SUM(outstanding_amount) AS outstanding_amount
                 FROM customer_receivable_items
                 GROUP BY booking_reference, service_line_reference, currency)';
    }

    private function payableAggregateSql(): string
    {
        return '(SELECT
                    booking_reference,
                    service_line_reference,
                    currency,
                    SUM(gross_amount) AS gross_amount,
                    SUM(net_payable_amount) AS net_payable_amount
                 FROM supplier_obligations
                 GROUP BY booking_reference, service_line_reference, currency)';
    }

    private function serviceReceivableFormula(string $alias): string
    {
        return 'CASE
                    WHEN ' . $alias . '.service_type = "air ticket" THEN
                        COALESCE(' . $alias . '.purchase_cost, 0)
                        + COALESCE(' . $alias . '.service_charge, 0)
                        - COALESCE(' . $alias . '.discount_amount, 0)
                    ELSE
                        COALESCE(' . $alias . '.sale_price, 0)
                        + COALESCE(' . $alias . '.service_charge, 0)
                        - COALESCE(' . $alias . '.discount_amount, 0)
                END';
    }

    private function servicePayableFormula(string $alias): string
    {
        return 'CASE
                    WHEN ' . $alias . '.service_type = "air ticket" THEN
                        COALESCE(' . $alias . '.purchase_cost, 0)
                    ELSE
                        COALESCE(' . $alias . '.purchase_cost, 0)
                END';
    }

    private function servicePayableInInvoiceCurrencyFormula(string $serviceAlias, string $payableAlias): string
    {
        $rawPayable = 'COALESCE(' . $payableAlias . '.gross_amount, '
            . $this->servicePayableFormula($serviceAlias) . ')';
        $payableCurrency = 'COALESCE(NULLIF(' . $payableAlias . '.currency, ""), NULLIF('
            . $serviceAlias . '.cost_currency, ""), ' . $serviceAlias . '.currency)';

        return 'CASE
                    WHEN ' . $payableCurrency . ' = ' . $serviceAlias . '.currency THEN
                        ' . $rawPayable . '
                    WHEN COALESCE(' . $serviceAlias . '.pricing_exchange_rate, 0) > 0 THEN
                        ROUND(' . $rawPayable . ' * ' . $serviceAlias . '.pricing_exchange_rate, 2)
                    WHEN ' . $serviceAlias . '.final_sale_price IS NOT NULL
                         AND ' . $serviceAlias . '.net_profit_loss IS NOT NULL THEN
                        GREATEST(ROUND(
                            ' . $serviceAlias . '.final_sale_price
                            - ' . $serviceAlias . '.net_profit_loss,
                            2
                        ), 0)
                    ELSE 0
                END';
    }

    private function fetchRows(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }
}
