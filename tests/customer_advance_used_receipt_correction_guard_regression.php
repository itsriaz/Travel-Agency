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

echo 'Customer advance used-receipt correction guard regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$fixture = $db->query(
    'SELECT cri.id AS receivable_id, cri.booking_reference, cri.currency, cri.outstanding_amount,
            b.id AS booking_id, b.branch_id, b.lead_traveler_id AS traveler_id, b.business_source_id,
            ta.id AS treasury_account_id, u.id AS user_id
     FROM customer_receivable_items cri
     INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
     INNER JOIN treasury_accounts ta ON ta.branch_id = b.branch_id
        AND ta.currency = cri.currency
        AND ta.is_active = 1
     CROSS JOIN users u
     WHERE cri.outstanding_amount > 0.01
       AND b.business_source_id IS NOT NULL
       AND b.lead_traveler_id IS NOT NULL
       AND u.is_active = 1
     ORDER BY cri.id DESC, ta.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if (! is_array($fixture)) {
    echo '[INFO] No suitable open receivable fixture was available.' . PHP_EOL;
    return 0;
}

$branchId = (int) $fixture['branch_id'];
$receivableId = (int) $fixture['receivable_id'];
$travelerId = (int) $fixture['traveler_id'];
$businessSourceId = (int) $fixture['business_source_id'];
$currency = (string) $fixture['currency'];
$userId = (int) $fixture['user_id'];
$primaryTreasuryAccountId = (int) $fixture['treasury_account_id'];
$paymentMethod = 'cash';
$amount = round((float) $fixture['outstanding_amount'] + 25.00, 2);

$eligibleAccounts = (new \App\Repositories\TreasuryRepository($app))
    ->eligiblePaymentTreasuryAccounts($branchId, $currency, $paymentMethod);
$alternateTreasuryAccountId = 0;
foreach ($eligibleAccounts as $account) {
    if ((int) ($account['id'] ?? 0) !== $primaryTreasuryAccountId) {
        $alternateTreasuryAccountId = (int) ($account['id'] ?? 0);
        break;
    }
}

$db->beginTransaction();

try {
    $workspace = new \App\Services\CustomerReceiptWorkspaceService($app);
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $treasury = new \App\Repositories\TreasuryRepository($app);

    if ($alternateTreasuryAccountId <= 0) {
        $alternateTreasuryAccountId = $treasury->saveAccount([
            'branch_id' => $branchId,
            'account_type' => 'cash',
            'account_name' => 'Advance Guard Alt Cash ' . date('His') . '-' . random_int(1000, 9999),
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
        'received_amount' => $amount,
        'receipt_date' => date('Y-m-d'),
        'payment_method' => $paymentMethod,
        'treasury_account_id' => $primaryTreasuryAccountId,
        'receipt_status' => 'received',
        'receipt_remarks' => 'Used advance correction guard regression',
    ], $userId, [$branchId]);

    $receiptId = (int) ($advance['receipt_id'] ?? 0);
    $applied = $workspace->applyCustomerAdvance([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'receipt_currency' => $currency,
        'advance_receipt_id' => $receiptId,
        'global_customer_receivable_id' => [$receivableId],
        'allocation_date' => date('Y-m-d'),
    ], $userId, [$branchId]);
    $check(
        'Fixture advance is genuinely used before correction',
        (int) ($applied['allocation_count'] ?? 0) === 1
            && abs((float) ($applied['allocated_amount'] ?? 0) - (float) $fixture['outstanding_amount']) <= 0.005
    );
    $before = $payments->findReceiptById($receiptId) ?? [];

    $accountChangeSucceeded = false;
    $accountChangeMessage = '';
    try {
        $workspace->correctCustomerAdvance([
            'customer_receipt_id' => $receiptId,
            'branch_id' => $branchId,
            'traveler_id' => $travelerId,
            'receipt_currency' => $currency,
            'advance_amount' => $amount,
            'advance_date' => date('Y-m-d'),
            'payment_method' => $paymentMethod,
            'treasury_account_id' => $alternateTreasuryAccountId,
            'correction_reason' => 'Regression moves used advance to the correct cash account',
        ], $userId, [$branchId]);
        $accountChangeSucceeded = true;
    } catch (RuntimeException $exception) {
        $accountChangeMessage = $exception->getMessage();
    }

    $afterAccountAttempt = $payments->findReceiptById($receiptId) ?? [];
    $check(
        'Used customer advance moves to the corrected cash account without changing the applied amount',
        $accountChangeSucceeded
            && (int) ($afterAccountAttempt['treasury_account_id'] ?? 0) === $alternateTreasuryAccountId
            && abs((float) ($afterAccountAttempt['allocated_amount'] ?? 0) - (float) ($before['allocated_amount'] ?? 0)) <= 0.005
            && abs((float) ($afterAccountAttempt['unallocated_amount'] ?? 0) - (float) ($before['unallocated_amount'] ?? 0)) <= 0.005,
        $accountChangeMessage
    );

    $receivableAfter = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId)->fetch(PDO::FETCH_ASSOC);
    $check('Correcting the cash account does not reopen the settled customer invoice',
        is_array($receivableAfter) && abs((float) ($receivableAfter['outstanding_amount'] ?? 0)) <= 0.005);

    $audit = $db->query(
        'SELECT old_journal_entry_id, reversal_journal_entry_id, new_journal_entry_id
         FROM customer_advance_corrections
         WHERE customer_receipt_id = ' . $receiptId . '
           AND correction_type = "advance_received"
         ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $oldJournalId = (int) ($audit['old_journal_entry_id'] ?? 0);
    $reversalJournalId = (int) ($audit['reversal_journal_entry_id'] ?? 0);
    $newJournalId = (int) ($audit['new_journal_entry_id'] ?? 0);
    $assetLine = $db->prepare(
        'SELECT account_id FROM journal_entry_lines
         WHERE journal_entry_id = :journal_entry_id AND line_description = :description
         LIMIT 1'
    );
    $assetLine->execute(['journal_entry_id' => $oldJournalId, 'description' => 'Customer receipt captured']);
    $oldAssetAccountId = (int) ($assetLine->fetchColumn() ?: 0);
    $assetLine->execute(['journal_entry_id' => $reversalJournalId, 'description' => 'Correction reversal: Customer receipt captured']);
    $reversedAssetAccountId = (int) ($assetLine->fetchColumn() ?: 0);
    $assetLine->execute(['journal_entry_id' => $newJournalId, 'description' => 'Customer receipt captured']);
    $newAssetAccountId = (int) ($assetLine->fetchColumn() ?: 0);
    $check('The correction reverses the old cash journal and posts to a different cash account',
        $oldJournalId > 0 && $reversalJournalId > 0 && $newJournalId > 0
            && $oldAssetAccountId > 0
            && $reversedAssetAccountId === $oldAssetAccountId
            && $newAssetAccountId > 0
            && $newAssetAccountId !== $oldAssetAccountId);

} catch (Throwable $exception) {
    $check('Used-advance correction guard probe completed without exception', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
echo empty($failures)
    ? 'Customer advance used-receipt correction guard regression passed.' . PHP_EOL
    : 'Failures:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;

exit(empty($failures) ? 0 : 1);
