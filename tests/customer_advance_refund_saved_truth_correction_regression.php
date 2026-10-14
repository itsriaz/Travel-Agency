<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
};

echo 'Customer advance refund saved-truth correction regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$fixture = $db->query(
    'SELECT b.branch_id, b.lead_traveler_id AS traveler_id, b.business_source_id,
            ta.id AS treasury_account_id, ta.account_name, ta.currency, u.id AS user_id
     FROM bookings b
     INNER JOIN treasury_accounts ta ON ta.branch_id = b.branch_id
        AND ta.is_active = 1
     CROSS JOIN users u
     WHERE b.lead_traveler_id IS NOT NULL
       AND b.business_source_id IS NOT NULL
       AND u.is_active = 1
     ORDER BY b.id DESC, ta.id ASC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if (! is_array($fixture)) {
    echo '[INFO] No suitable booking fixture was available.' . PHP_EOL;
    return 0;
}

$branchId = (int) $fixture['branch_id'];
$travelerId = (int) $fixture['traveler_id'];
$businessSourceId = (int) $fixture['business_source_id'];
$currency = strtoupper((string) $fixture['currency']);
$userId = (int) $fixture['user_id'];
$primaryTreasuryAccountId = (int) $fixture['treasury_account_id'];
$paymentMethod = 'cash';
$advanceAmount = 1500.00;
$refundAmount = 600.00;

$eligibleAccounts = (new \App\Repositories\TreasuryRepository($app))
    ->eligiblePaymentTreasuryAccounts($branchId, $currency, $paymentMethod);
$alternateTreasuryAccountId = 0;
foreach ($eligibleAccounts as $account) {
    if ((int) ($account['id'] ?? 0) !== $primaryTreasuryAccountId) {
        $alternateTreasuryAccountId = (int) ($account['id'] ?? 0);
        break;
    }
}

$otherBranchId = (int) ($db->query(
    'SELECT id
     FROM branches
     WHERE id <> ' . $branchId . '
     ORDER BY id ASC
     LIMIT 1'
)->fetchColumn() ?: 0);

$otherCurrency = $currency === 'PKR' ? 'AED' : 'PKR';

$db->beginTransaction();

try {
    $workspace = new \App\Services\CustomerReceiptWorkspaceService($app);
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $treasury = new \App\Repositories\TreasuryRepository($app);

    if ($alternateTreasuryAccountId <= 0) {
        $token = strtoupper(bin2hex(random_bytes(4)));
        $alternateTreasuryAccountId = $treasury->saveAccount([
            'branch_id' => $branchId,
            'account_type' => 'cash',
            'account_name' => 'ARC ' . $token,
            'currency' => $currency,
            'opening_balance' => '0.00',
            'opening_balance_date' => date('Y-m-d'),
            'is_active' => '1',
            'created_by_user_id' => $userId,
        ], [$branchId]);
    }

    $advance = $workspace->recordCustomerAdvance([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'business_source_id' => $businessSourceId,
        'receipt_currency' => $currency,
        'received_amount' => $advanceAmount,
        'receipt_date' => date('Y-m-d'),
        'payment_method' => $paymentMethod,
        'treasury_account_id' => $primaryTreasuryAccountId,
        'receipt_status' => 'received',
        'receipt_remarks' => 'Advance refund saved-truth correction regression',
    ], $userId, [$branchId]);

    $receiptId = (int) ($advance['receipt_id'] ?? 0);
    if ($receiptId <= 0) {
        throw new RuntimeException('The regression could not create a customer advance receipt.');
    }

    $workspace->refundCustomerAdvance([
        'advance_refund_receipt_id' => $receiptId,
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'receipt_currency' => $currency,
        'advance_refund_amount' => $refundAmount,
        'advance_refund_date' => date('Y-m-d'),
        'advance_refund_method' => $paymentMethod,
        'advance_refund_treasury_account_id' => $primaryTreasuryAccountId,
        'advance_refund_reason' => 'Original returned advance for correction regression',
        'advance_refund_remarks' => 'Original returned advance for correction regression',
    ], $userId, [$branchId]);

    $refund = $db->query(
        'SELECT id, journal_entry_id
         FROM customer_advance_refunds
         WHERE customer_receipt_id = ' . $receiptId . '
         ORDER BY id DESC
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (! is_array($refund)) {
        throw new RuntimeException('The regression could not load the created returned advance.');
    }

    $refundId = (int) $refund['id'];
    $refundJournalEntryId = (int) ($refund['journal_entry_id'] ?? 0);
    if ($refundJournalEntryId <= 0) {
        throw new RuntimeException('The regression could not load the original return journal.');
    }

    $baselineReceipt = $payments->findReceiptById($receiptId) ?? [];
    $baselineReturnedAmount = round((float) ($baselineReceipt['returned_amount'] ?? 0), 2);
    $baselineUnallocatedAmount = round((float) ($baselineReceipt['unallocated_amount'] ?? 0), 2);

    $mutate = $db->prepare(
        'UPDATE customer_advance_refunds
         SET branch_id = :branch_id,
             currency = :currency,
             treasury_account_id = :treasury_account_id
         WHERE id = :id'
    );
    $mutate->execute([
        'branch_id' => $otherBranchId > 0 ? $otherBranchId : $branchId,
        'currency' => $otherCurrency,
        'treasury_account_id' => $primaryTreasuryAccountId,
        'id' => $refundId,
    ]);

    $rejectedOtherCustomer = false;
    try {
        $workspace->correctCustomerAdvanceRefund([
            'customer_advance_refund_id' => $refundId,
            'branch_id' => $branchId,
            'traveler_id' => $travelerId + 1000000,
            'receipt_currency' => $currency,
            'advance_refund_amount' => $refundAmount,
            'advance_refund_date' => date('Y-m-d'),
            'advance_refund_method' => $paymentMethod,
            'advance_refund_treasury_account_id' => $alternateTreasuryAccountId,
            'correction_reason' => 'Unauthorized customer probe',
        ], $userId, [$branchId]);
    } catch (RuntimeException $exception) {
        $rejectedOtherCustomer = str_contains($exception->getMessage(), 'does not match this customer');
    }
    $check('A different customer cannot correct the returned advance', $rejectedOtherCustomer);

    $workspace->correctCustomerAdvanceRefund([
        'customer_advance_refund_id' => $refundId,
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'receipt_currency' => $currency,
        'advance_refund_amount' => $refundAmount,
        'advance_refund_date' => date('Y-m-d'),
        'advance_refund_method' => $paymentMethod,
        'advance_refund_treasury_account_id' => $alternateTreasuryAccountId,
        'correction_reason' => 'Regression repairs wrongly saved branch/currency/account on returned advance',
    ], $userId, [$branchId]);

    $correctedRefund = $db->query(
        'SELECT branch_id, traveler_id, currency, amount, treasury_account_id, journal_entry_id
         FROM customer_advance_refunds
         WHERE id = ' . $refundId
    )->fetch(PDO::FETCH_ASSOC);
    $check(
        'Returned advance correction rewrites the saved refund row back to the parent advance branch/currency/account',
        is_array($correctedRefund)
            && (int) ($correctedRefund['branch_id'] ?? 0) === $branchId
            && (int) ($correctedRefund['traveler_id'] ?? 0) === $travelerId
            && strtoupper((string) ($correctedRefund['currency'] ?? '')) === $currency
            && abs((float) ($correctedRefund['amount'] ?? 0) - $refundAmount) <= 0.005
            && (int) ($correctedRefund['treasury_account_id'] ?? 0) === $alternateTreasuryAccountId
            && (int) ($correctedRefund['journal_entry_id'] ?? 0) > 0,
        is_array($correctedRefund) ? 'branch=' . (string) ($correctedRefund['branch_id'] ?? '')
            . ', currency=' . (string) ($correctedRefund['currency'] ?? '')
            . ', account=' . (string) ($correctedRefund['treasury_account_id'] ?? '')
            : 'no corrected refund row loaded'
    );

    $auditColumns = [
        'old_currency' => (bool) $db->query("SHOW COLUMNS FROM customer_advance_corrections LIKE 'old_currency'")->fetch(),
        'new_currency' => (bool) $db->query("SHOW COLUMNS FROM customer_advance_corrections LIKE 'new_currency'")->fetch(),
    ];
    $audit = $db->query(
        'SELECT old_journal_entry_id, reversal_journal_entry_id, new_journal_entry_id'
        . ($auditColumns['old_currency'] ? ', old_currency' : '')
        . ($auditColumns['new_currency'] ? ', new_currency' : '')
        . ' FROM customer_advance_corrections
           WHERE customer_advance_refund_id = ' . $refundId . '
             AND correction_type = "advance_returned"
           ORDER BY id DESC
           LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $journalRows = $db->query(
        'SELECT id, branch_id, currency FROM journal_entries
         WHERE id IN (' . implode(', ', array_map('intval', [
             $refundJournalEntryId,
             (int) ($audit['reversal_journal_entry_id'] ?? 0),
             (int) ($audit['new_journal_entry_id'] ?? 0),
         ])) . ')'
    )->fetchAll(PDO::FETCH_ASSOC);
    $journalRowsById = array_column($journalRows, null, 'id');
    $journalMetadataValid = true;
    foreach ([$refundJournalEntryId, (int) ($audit['reversal_journal_entry_id'] ?? 0), (int) ($audit['new_journal_entry_id'] ?? 0)] as $journalId) {
        $journal = $journalRowsById[$journalId] ?? null;
        if (! is_array($journal)
            || (int) ($journal['branch_id'] ?? 0) !== $branchId
            || strtoupper((string) ($journal['currency'] ?? '')) !== $currency
        ) {
            $journalMetadataValid = false;
        }
    }
    $check('Original, reversal, and corrected journals remain in the real branch/currency', $journalMetadataValid);
    $check(
        'Returned advance correction keeps the reversal/new journal audit trail complete',
        is_array($audit)
            && (int) ($audit['old_journal_entry_id'] ?? 0) === $refundJournalEntryId
            && (int) ($audit['reversal_journal_entry_id'] ?? 0) > 0
            && (int) ($audit['new_journal_entry_id'] ?? 0) > 0
            && (! $auditColumns['old_currency'] || strtoupper((string) ($audit['old_currency'] ?? '')) === $otherCurrency)
            && (! $auditColumns['new_currency'] || strtoupper((string) ($audit['new_currency'] ?? '')) === $currency),
        is_array($audit)
            ? 'old=' . (string) ($audit['old_journal_entry_id'] ?? '')
                . ', reversal=' . (string) ($audit['reversal_journal_entry_id'] ?? '')
                . ', new=' . (string) ($audit['new_journal_entry_id'] ?? '')
            : 'no correction audit row loaded'
    );

    $correctedReceipt = $payments->findReceiptById($receiptId) ?? [];
    $check(
        'Returned advance correction preserves the parent advance balances',
        abs((float) ($correctedReceipt['returned_amount'] ?? 0) - $baselineReturnedAmount) <= 0.005
            && abs((float) ($correctedReceipt['unallocated_amount'] ?? 0) - $baselineUnallocatedAmount) <= 0.005,
        'returned=' . (string) ($correctedReceipt['returned_amount'] ?? '')
            . ', unallocated=' . (string) ($correctedReceipt['unallocated_amount'] ?? '')
    );
} catch (Throwable $exception) {
    $check('Saved-truth returned-advance correction probe completed without exception', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
echo empty($failures)
    ? 'Customer advance refund saved-truth correction regression passed.' . PHP_EOL
    : 'Failures:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;

exit(empty($failures) ? 0 : 1);
