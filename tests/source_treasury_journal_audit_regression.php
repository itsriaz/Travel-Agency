<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Services\SourceTreasuryJournalAuditService;

$receipt = static fn (array $overrides = []): array => array_replace([
    'id' => 1, 'source_reference' => 'RCPT-TEST', 'branch_id' => 1, 'currency' => 'PKR',
    'amount' => 100, 'charges_amount' => 0, 'status' => 'fully_allocated',
    'payment_method' => 'cash', 'treasury_account_id' => 10,
    'account_branch_id' => 1, 'account_currency' => 'PKR', 'account_id' => 18,
    'reversal_journal_entry_id' => null,
], $overrides);
$payment = static fn (array $overrides = []): array => array_replace([
    'id' => 2, 'source_reference' => 'SPAY-TEST', 'branch_id' => 1, 'currency' => 'PKR',
    'amount' => 1000, 'charges_amount' => 0, 'status' => 'fully_allocated',
    'payment_method' => 'cash', 'treasury_account_id' => 10,
    'account_branch_id' => 1, 'account_currency' => 'PKR', 'account_id' => 18,
    'reversal_journal_entry_id' => null,
], $overrides);
$line = static function (
    int $sourceId, int $journalId, string $sourceType, int $branchId,
    string $currency, int $accountId, float $debit, float $credit
): array {
    return [
        'source_id' => $sourceId, 'journal_id' => $journalId, 'source_type' => $sourceType,
        'branch_id' => $branchId, 'currency' => $currency, 'account_id' => $accountId,
        'account_code' => $accountId === 99 ? 'SUPPLIER_PAYMENT_FX_CLEARING' : 'CASH_TEST_' . $accountId,
        'debit_amount' => $debit, 'credit_amount' => $credit,
    ];
};
$audit = static fn (
    array $receipts = [], array $payments = [], array $receiptLines = [], array $paymentLines = []
): array => SourceTreasuryJournalAuditService::reconcile($receipts, $payments, $receiptLines, $paymentLines, [18, 19]);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};
$hasReason = static function (array $result, string $reason): bool {
    foreach ($result['exceptions'] as $finding) {
        if (in_array($reason, $finding['reasons'], true)) {
            return true;
        }
    }
    return false;
};

$recorded = $line(1, 1, 'customer_receipt_recorded', 1, 'PKR', 18, 100, 0);
$result = $audit([$receipt()], [], [$recorded]);
$assert($result['exceptions'] === [], 'Normal receipt funds its selected account.');

$result = $audit(
    [$receipt(['amount' => 120])],
    [],
    [$recorded, $line(1, 2, 'customer_receipt_amount_corrected', 1, 'PKR', 18, 20, 0)]
);
$assert($result['exceptions'] === [], 'Receipt increase is reconciled through its delta journal.');

$result = $audit(
    [$receipt(['amount' => 80])],
    [],
    [$recorded, $line(1, 2, 'customer_receipt_amount_corrected', 1, 'PKR', 18, 0, 20)]
);
$assert($result['exceptions'] === [], 'Receipt decrease is reconciled through its delta journal.');

$result = $audit(
    [$receipt(['treasury_account_id' => 11, 'account_id' => 19])],
    [],
    [
        $recorded,
        $line(1, 2, 'customer_receipt_account_corrected', 1, 'PKR', 18, 0, 100),
        $line(1, 2, 'customer_receipt_account_corrected', 1, 'PKR', 19, 100, 0),
    ]
);
$assert($result['exceptions'] === [], 'Account correction leaves cash in only the new account.');

$result = $audit([$receipt(['status' => 'void'])], [], [
    $recorded, $line(1, 3, 'customer_receipt_void_reversed', 1, 'PKR', 18, 0, 100),
]);
$assert($result['exceptions'] === [], 'Voided receipt has zero remaining cash effect.');

$result = $audit([$receipt()], [], [$line(1, 1, 'customer_receipt_recorded', 1, 'PKR', 19, 100, 0)]);
$assert($hasReason($result, 'selected_treasury_amount_mismatch'), 'Wrong receipt account is detected.');

$result = $audit([$receipt()]);
$assert($hasReason($result, 'recording_journal_missing'), 'Missing receipt journal is detected.');

$supplierRecorded = $line(2, 4, 'supplier_payment_recorded', 2, 'PKR', 99, 0, 1000);
$supplierFunded = $line(2, 5, 'supplier_payment_treasury_funded', 1, 'PKR', 18, 0, 1000);
$result = $audit([], [$payment(['branch_id' => 2, 'account_branch_id' => 1])], [], [
    $supplierRecorded, $supplierFunded,
]);
$assert($result['exceptions'] === [], 'Cross-branch supplier payment uses its treasury-funding journal.');

$result = $audit([], [$payment(['branch_id' => 2, 'account_branch_id' => 1])], [], [$supplierRecorded]);
$assert($hasReason($result, 'treasury_funding_journal_missing'), 'Missing cross-branch funding is detected.');

$supplierCash = $line(2, 4, 'supplier_payment_recorded', 1, 'PKR', 18, 0, 1000);
$supplierReversal = $line(2, 5, 'supplier_payment_void_reversed', 1, 'PKR', 18, 1000, 0);
$result = $audit([], [$payment(['status' => 'void', 'reversal_journal_entry_id' => 5])], [], [
    $supplierCash, $supplierReversal,
]);
$assert($result['exceptions'] === [], 'One supplier void reversal cancels the original cash outflow.');

$result = $audit([], [$payment(['status' => 'void', 'reversal_journal_entry_id' => 6])], [], [
    $supplierCash, $supplierReversal,
    $line(2, 6, 'supplier_payment_void_reversed', 1, 'PKR', 18, 1000, 0),
]);
$assert($hasReason($result, 'selected_treasury_amount_mismatch'), 'Duplicate supplier void reversal is detected.');

$result = $audit([], [$payment(['status' => 'void', 'reversal_journal_entry_id' => 5])], [], [
    $supplierCash, $line(2, 5, 'data_integrity_reversal', 1, 'PKR', 18, 1000, 0),
]);
$assert($result['exceptions'] === [], 'An explicitly linked manual reversal cancels a voided payment.');

$result = $audit([], [$payment(['status' => 'void', 'reversal_journal_entry_id' => 6])], [], [
    $supplierCash, $line(2, 5, 'data_integrity_reversal', 1, 'PKR', 18, 1000, 0),
]);
$assert($hasReason($result, 'unclassified_asset_journal'), 'Unlinked manual reversal is not silently accepted.');

$result = $audit([], [$payment([
    'branch_id' => 2, 'currency' => 'AED', 'amount' => 10,
    'account_branch_id' => 1, 'account_currency' => 'PKR',
])], [], [
    $line(2, 4, 'supplier_payment_recorded', 2, 'AED', 99, 0, 10),
    $line(2, 5, 'supplier_payment_treasury_funded', 1, 'PKR', 18, 0, 770),
]);
$assert($result['exceptions'] === []
    && ($result['warnings'][0]['reason'] ?? '') === 'cross_currency_treasury_amount_not_stored',
    'Cross-currency funding is traced without inventing an expected FX amount.');

$result = $audit([], [], [
    $line(50, 1, 'customer_receipt_recorded', 1, 'PKR', 18, 100, 0),
    $line(50, 2, 'data_integrity_reversal', 1, 'PKR', 18, 0, 100),
]);
$assert($result['exceptions'] === []
    && ($result['warnings'][0]['reason'] ?? '') === 'reversed_historical_orphan',
    'An orphaned receipt with an exact reversal is a warning.');

$result = $audit([], [], [
    $line(50, 1, 'customer_receipt_recorded', 1, 'PKR', 18, 100, 0),
    $line(50, 2, 'data_integrity_reversal', 1, 'PKR', 19, 0, 100),
]);
$assert($hasReason($result, 'orphan_journal_has_treasury_balance'),
    'Opposite orphan movements in different accounts do not cancel.');
