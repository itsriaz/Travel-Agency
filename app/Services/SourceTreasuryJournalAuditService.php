<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SourceTreasuryJournalAuditRepository;

final class SourceTreasuryJournalAuditService
{
    private const RECEIPT_FUNDING_TYPES = [
        'customer_receipt_recorded',
        'customer_receipt_amount_corrected',
        'customer_receipt_account_corrected',
        'booking_branch_receipt_reversal',
        'customer_receipt_wrong_currency_reversed',
        'customer_receipt_void_reversed',
        'customer_receipt_void_reversal',
        'customer_advance_correction_reversal',
    ];

    private const SUPPLIER_FUNDING_TYPES = [
        'supplier_payment_recorded',
        'supplier_payment_treasury_funded',
        'supplier_payment_void_reversed',
        'supplier_advance_correction_reversal',
    ];

    private const OTHER_ASSET_TYPES = [
        'customer_advance_refunded',
        'customer_advance_return_correction_reversal',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function run(): array
    {
        $repository = new SourceTreasuryJournalAuditRepository($this->app);

        return self::reconcile(
            $repository->customerReceipts(),
            $repository->supplierPayments(),
            $repository->customerReceiptJournalLines(),
            $repository->supplierPaymentJournalLines(),
            $repository->treasuryLinkedAccountIds()
        );
    }

    public static function reconcile(
        array $receipts,
        array $payments,
        array $receiptJournalLines,
        array $paymentJournalLines,
        array $treasuryAccountIds
    ): array {
        $assetAccounts = array_fill_keys(array_map('intval', $treasuryAccountIds), true);
        $result = [
            'checked' => ['customer_receipt' => 0, 'supplier_payment' => 0],
            'skipped_non_cash_bank' => ['customer_receipt' => 0, 'supplier_payment' => 0],
            'exceptions' => [],
            'warnings' => [],
        ];

        self::reconcileType(
            'customer_receipt', $receipts, $receiptJournalLines,
            self::RECEIPT_FUNDING_TYPES, $assetAccounts, $result
        );
        self::reconcileType(
            'supplier_payment', $payments, $paymentJournalLines,
            self::SUPPLIER_FUNDING_TYPES, $assetAccounts, $result
        );

        return $result;
    }

    private static function reconcileType(
        string $type,
        array $sources,
        array $journalLines,
        array $fundingTypes,
        array $assetAccounts,
        array &$result
    ): void {
        $linesBySource = [];
        foreach ($journalLines as $line) {
            $sourceId = (int) ($line['source_id'] ?? 0);
            if ($sourceId > 0) {
                $linesBySource[$sourceId][] = $line;
            }
        }

        $sourceIds = [];
        foreach ($sources as $source) {
            $sourceId = (int) ($source['id'] ?? 0);
            $sourceIds[$sourceId] = true;
            if (! in_array((string) ($source['payment_method'] ?? ''), ['cash', 'bank_transfer'], true)) {
                $result['skipped_non_cash_bank'][$type]++;
                continue;
            }

            $result['checked'][$type]++;
            $allLines = $linesBySource[$sourceId] ?? [];
            $status = (string) ($source['status'] ?? '');
            $void = $status === 'void';
            $reversalJournalId = (int) ($source['reversal_journal_entry_id'] ?? 0);
            $assetLines = [];
            $journalIds = [];
            $sourceTypes = [];
            $netByAccount = [];
            $netByBranch = [];
            $unknownAssetTypes = [];
            foreach ($allLines as $line) {
                $journalId = (int) ($line['journal_id'] ?? 0);
                $sourceType = (string) ($line['source_type'] ?? '');
                $journalIds[$journalId] = true;
                $sourceTypes[$sourceType] = true;
                if (! self::isTreasuryAssetLine($line, $assetAccounts)) {
                    continue;
                }
                $documentedManualReversal = $void && $reversalJournalId > 0
                    && $journalId === $reversalJournalId
                    && $sourceType === 'data_integrity_reversal';
                if (! in_array($sourceType, $fundingTypes, true) && ! $documentedManualReversal) {
                    if (! in_array($sourceType, self::OTHER_ASSET_TYPES, true)) {
                        $unknownAssetTypes[$sourceType] = true;
                    }
                    continue;
                }
                $assetLines[] = self::assetEvidence($line);
                $key = self::accountCurrencyKey($line);
                $netByAccount[$key] = ($netByAccount[$key] ?? 0) + self::netCents($line);
                $branchKey = $key . '|' . (int) ($line['branch_id'] ?? 0);
                $netByBranch[$branchKey] = ($netByBranch[$branchKey] ?? 0) + self::netCents($line);
            }

            $currency = strtoupper((string) ($source['currency'] ?? ''));
            $accountCurrency = strtoupper((string) ($source['account_currency'] ?? ''));
            $accountId = (int) ($source['account_id'] ?? 0);
            $accountBranchId = (int) ($source['account_branch_id'] ?? 0);
            $sourceBranchId = (int) ($source['branch_id'] ?? 0);
            $selectedKey = $accountId . '|' . $accountCurrency;
            $actualCents = (int) ($netByAccount[$selectedKey] ?? 0);
            $sourceAmountCents = self::cents($source['amount'] ?? 0);
            $chargesCents = self::cents($source['charges_amount'] ?? 0);
            $crossCurrency = $type === 'supplier_payment' && $accountCurrency !== $currency;
            $expectedCents = $void ? 0 : ($type === 'customer_receipt'
                ? $sourceAmountCents - $chargesCents
                : ($crossCurrency ? null : -($sourceAmountCents + $chargesCents)));
            $reasons = [];

            if ($accountId <= 0 || $accountBranchId <= 0 || $accountCurrency === '') {
                $reasons[] = 'selected_treasury_account_unmapped';
            }
            if ($type === 'customer_receipt' && $accountCurrency !== $currency) {
                $reasons[] = 'receipt_treasury_currency_mismatch';
            }
            if (! isset($sourceTypes[$type === 'customer_receipt'
                ? 'customer_receipt_recorded' : 'supplier_payment_recorded'])) {
                $reasons[] = 'recording_journal_missing';
            }
            if ($assetLines === []) {
                $reasons[] = 'treasury_asset_journal_missing';
            }
            if ($type === 'supplier_payment' && ! $void
                && ($crossCurrency || $accountBranchId !== $sourceBranchId)
                && ! isset($sourceTypes['supplier_payment_treasury_funded'])) {
                $reasons[] = 'treasury_funding_journal_missing';
            }
            if ($expectedCents !== null && abs($actualCents - $expectedCents) > 0) {
                $reasons[] = 'selected_treasury_amount_mismatch';
            }
            if ($crossCurrency && ! $void && $actualCents >= 0) {
                $reasons[] = 'selected_treasury_outflow_missing';
            }
            foreach ($netByAccount as $key => $netCents) {
                if ($key !== $selectedKey && $netCents !== 0) {
                    $reasons[] = 'other_treasury_balance_not_reversed';
                    break;
                }
            }
            $expectedJournalBranch = $type === 'customer_receipt' ? $sourceBranchId : $accountBranchId;
            foreach ($netByBranch as $key => $netCents) {
                if ($void && $netCents !== 0 && $actualCents === 0) {
                    $reasons[] = 'void_treasury_branch_balance_not_reversed';
                    break;
                }
                if ($netCents !== 0 && str_starts_with($key, $selectedKey . '|')
                    && $key !== $selectedKey . '|' . $expectedJournalBranch && ! $void) {
                    $reasons[] = 'treasury_journal_branch_mismatch';
                    break;
                }
            }
            if ($unknownAssetTypes !== []) {
                $reasons[] = 'unclassified_asset_journal';
            }

            $evidence = [
                'type' => $type,
                'source_id' => $sourceId,
                'source_reference' => (string) ($source['source_reference'] ?? ''),
                'status' => $status,
                'branch_id' => $sourceBranchId,
                'currency' => $currency,
                'treasury_account_id' => (int) ($source['treasury_account_id'] ?? 0),
                'treasury_branch_id' => $accountBranchId,
                'treasury_currency' => $accountCurrency,
                'selected_account_id' => $accountId,
                'expected_amount' => $expectedCents === null ? null : round($expectedCents / 100, 2),
                'actual_amount' => round($actualCents / 100, 2),
                'journal_ids' => array_values(array_map('intval', array_keys($journalIds))),
                'asset_lines' => $assetLines,
            ];
            if ($reasons !== []) {
                $result['exceptions'][] = ['reasons' => array_values(array_unique($reasons))] + $evidence;
            } elseif ($crossCurrency && ! $void) {
                $result['warnings'][] = ['reason' => 'cross_currency_treasury_amount_not_stored'] + $evidence;
            }
        }

        foreach ($linesBySource as $sourceId => $lines) {
            if (isset($sourceIds[$sourceId])) {
                continue;
            }
            $netByAccountBranch = [];
            $journalIds = [];
            foreach ($lines as $line) {
                if (! self::isTreasuryAssetLine($line, $assetAccounts)) {
                    continue;
                }
                $sourceType = (string) ($line['source_type'] ?? '');
                if (in_array($sourceType, $fundingTypes, true) || $sourceType === 'data_integrity_reversal') {
                    $key = self::accountCurrencyKey($line) . '|' . (int) ($line['branch_id'] ?? 0);
                    $netByAccountBranch[$key] = ($netByAccountBranch[$key] ?? 0) + self::netCents($line);
                    $journalIds[(int) ($line['journal_id'] ?? 0)] = true;
                }
            }
            if ($journalIds === []) {
                continue;
            }
            $nonzeroBalances = array_filter($netByAccountBranch, static fn (int $amount): bool => $amount !== 0);
            $finding = [
                'type' => $type,
                'source_id' => $sourceId,
                'account_branch_balances' => array_map(
                    static fn (int $amount): float => round($amount / 100, 2),
                    $nonzeroBalances
                ),
                'journal_ids' => array_values(array_map('intval', array_keys($journalIds))),
            ];
            if ($nonzeroBalances === []) {
                $result['warnings'][] = ['reason' => 'reversed_historical_orphan'] + $finding;
            } else {
                $result['exceptions'][] = ['reasons' => ['orphan_journal_has_treasury_balance']] + $finding;
            }
        }
    }

    private static function isTreasuryAssetLine(array $line, array $assetAccounts): bool
    {
        return isset($assetAccounts[(int) ($line['account_id'] ?? 0)])
            || in_array((string) ($line['account_code'] ?? ''), ['CASH_ON_HAND', 'BANK_CLEARING', 'CARD_CLEARING'], true);
    }

    private static function assetEvidence(array $line): array
    {
        return [
            'journal_id' => (int) ($line['journal_id'] ?? 0),
            'source_type' => (string) ($line['source_type'] ?? ''),
            'branch_id' => (int) ($line['branch_id'] ?? 0),
            'currency' => (string) ($line['currency'] ?? ''),
            'account_id' => (int) ($line['account_id'] ?? 0),
            'account_code' => (string) ($line['account_code'] ?? ''),
            'debit' => round(self::cents($line['debit_amount'] ?? 0) / 100, 2),
            'credit' => round(self::cents($line['credit_amount'] ?? 0) / 100, 2),
        ];
    }

    private static function accountCurrencyKey(array $line): string
    {
        return (int) ($line['account_id'] ?? 0) . '|' . strtoupper((string) ($line['currency'] ?? ''));
    }

    private static function netCents(array $line): int
    {
        return self::cents($line['debit_amount'] ?? 0) - self::cents($line['credit_amount'] ?? 0);
    }

    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
