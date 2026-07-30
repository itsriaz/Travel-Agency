<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App) ? $app : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$options = getopt('', ['apply', 'actor-user-id::']);
$apply = array_key_exists('apply', $options);
$actorUserId = max(1, (int) ($options['actor-user-id'] ?? 1));
$moneyEquals = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) <= 0.005;

$plans = [
    ['booking' => 'BK-000307', 'receipt' => 'RCPT-000290', 'amount' => 103500.00, 'treatment' => 'reverse_only', 'valid_pkr_allocated' => 104000.00],
    ['booking' => 'BK-000316', 'receipt' => 'RCPT-000298', 'amount' => 134000.00, 'treatment' => 'migrate_and_allocate', 'valid_pkr_allocated' => 0.00],
    ['booking' => 'BK-000322', 'receipt' => 'RCPT-000304', 'amount' => 33000.00, 'treatment' => 'migrate_and_allocate', 'valid_pkr_allocated' => 0.00],
    ['booking' => 'BK-000354', 'receipt' => 'RCPT-000328', 'amount' => 23000.00, 'treatment' => 'reverse_only', 'valid_pkr_allocated' => 23000.00],
    ['booking' => 'BK-000362', 'receipt' => 'RCPT-000333', 'amount' => 130000.00, 'treatment' => 'reverse_only', 'valid_pkr_allocated' => 130000.00],
    ['booking' => 'BK-000381', 'receipt' => 'RCPT-000352', 'amount' => 70000.00, 'treatment' => 'reverse_only', 'valid_pkr_allocated' => 70000.00],
    ['booking' => 'BK-000382', 'receipt' => 'RCPT-000353', 'amount' => 50000.00, 'treatment' => 'reverse_only', 'valid_pkr_allocated' => 100000.00],
];
$pending = [
    ['booking' => 'BK-000270', 'receipt' => 'RCPT-000264', 'amount' => 844.00],
    ['booking' => 'BK-000221', 'receipt' => 'RCPT-000219', 'amount' => 1330.00],
    ['booking' => 'BK-000041', 'receipt' => 'RCPT-000041', 'amount' => 10.00],
];

$receiptQuery = $db->prepare(
    'SELECT r.*, ta.account_name AS treasury_account_name
     FROM customer_receipts r
     LEFT JOIN treasury_accounts ta ON ta.id = r.treasury_account_id
     WHERE r.receipt_no = :receipt_no AND r.booking_reference = :booking_reference
     LIMIT 1'
);
$serviceQuery = $db->prepare(
    'SELECT bs.id, bs.branch_id, b.branch_id AS booking_branch_id, bs.line_reference, bs.currency
     FROM booking_services bs
     INNER JOIN bookings b ON b.id = bs.booking_id
     WHERE b.booking_reference = :booking_reference AND bs.is_active = 1
     ORDER BY bs.id ASC LIMIT 1'
);
$receivableQuery = $db->prepare(
    'SELECT * FROM customer_receivable_items
     WHERE booking_reference = :booking_reference AND service_line_reference = :line_reference AND due_group = "service_sale"
     LIMIT 1'
);
$pkrAllocatedQuery = $db->prepare(
    'SELECT COALESCE(SUM(a.receivable_amount_allocated), 0)
     FROM customer_receipt_allocations a
     INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
     WHERE r.booking_reference = :booking_reference AND r.currency = "PKR" AND r.status <> "void"'
);

echo 'Wrong-currency customer receipt repair (2026-07-21)' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

$resolvedPlans = [];
foreach ($plans as $plan) {
    $receiptQuery->execute(['receipt_no' => $plan['receipt'], 'booking_reference' => $plan['booking']]);
    $receipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $serviceQuery->execute(['booking_reference' => $plan['booking']]);
    $service = $serviceQuery->fetch(PDO::FETCH_ASSOC);
    if ($receipt === false || $service === false) {
        throw new RuntimeException('[ABORT] Missing approved receipt/service for ' . $plan['booking'] . '.');
    }

    $existingCorrection = null;
    try {
        $existingCorrection = (new \App\Repositories\CustomerReceiptCorrectionRepository($app))->findCurrencyCorrection((int) $receipt['id']);
    } catch (Throwable $exception) {
        throw new RuntimeException('[ABORT] Run migration 20260721_000055 before this repair.');
    }
    if ($existingCorrection !== null) {
        echo '[ALREADY CORRECTED] ' . $plan['booking'] . ' / ' . $plan['receipt'] . PHP_EOL;
        continue;
    }

    $matches = strtoupper((string) $receipt['currency']) === 'AED'
        && strtoupper((string) $service['currency']) === 'PKR'
        && $moneyEquals($receipt['received_amount'], $plan['amount'])
        && $moneyEquals($receipt['allocated_amount'], 0.00)
        && $moneyEquals($receipt['unallocated_amount'], $plan['amount'])
        && $moneyEquals($receipt['returned_amount'], 0.00)
        && strtolower((string) $receipt['status']) !== 'void'
        && (string) $receipt['treasury_account_name'] === 'Cash-UAE-AED';
    if (! $matches) {
        throw new RuntimeException('[ABORT] ' . $plan['booking'] . ' no longer matches the audited wrong-currency state.');
    }

    $receivableQuery->execute([
        'booking_reference' => $plan['booking'],
        'line_reference' => $service['line_reference'],
    ]);
    $receivable = $receivableQuery->fetch(PDO::FETCH_ASSOC);
    if ($receivable === false || strtoupper((string) $receivable['currency']) !== 'PKR') {
        throw new RuntimeException('[ABORT] Correct PKR receivable is missing for ' . $plan['booking'] . '.');
    }

    $pkrAllocatedQuery->execute(['booking_reference' => $plan['booking']]);
    $validPkrAllocated = (float) $pkrAllocatedQuery->fetchColumn();
    if (! $moneyEquals($validPkrAllocated, $plan['valid_pkr_allocated'])) {
        throw new RuntimeException('[ABORT] Existing valid PKR receipt truth changed for ' . $plan['booking'] . '.');
    }
    if ($plan['treatment'] === 'migrate_and_allocate' && (float) $receivable['outstanding_amount'] + 0.005 < $plan['amount']) {
        throw new RuntimeException('[ABORT] PKR outstanding is too small to recreate ' . $plan['receipt'] . '.');
    }

    echo sprintf(
        '[READY] %s / %s: remove false AED %s; %s.%s',
        $plan['booking'],
        $plan['receipt'],
        number_format($plan['amount'], 2),
        $plan['treatment'] === 'migrate_and_allocate' ? 'recreate and allocate the same nominal amount in PKR' : 'keep the later valid PKR receipt',
        PHP_EOL
    );
    $resolvedPlans[] = $plan + ['receipt_id' => (int) $receipt['id'], 'service' => $service, 'receivable' => $receivable];
}

foreach ($pending as $item) {
    $receiptQuery->execute(['receipt_no' => $item['receipt'], 'booking_reference' => $item['booking']]);
    $receipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    if ($receipt === false
        || strtoupper((string) $receipt['currency']) !== 'AED'
        || ! $moneyEquals($receipt['unallocated_amount'], $item['amount'])
        || strtolower((string) $receipt['status']) === 'void') {
        throw new RuntimeException('[ABORT] Protected pending credit changed: ' . $item['booking'] . ' / ' . $item['receipt'] . '.');
    }
    echo '[PROTECTED] ' . $item['booking'] . ' / ' . $item['receipt'] . ' remains pending AED ' . number_format($item['amount'], 2) . '.' . PHP_EOL;
}

$receiptQuery->execute(['receipt_no' => 'RCPT-000200', 'booking_reference' => 'BK-000196']);
$fxReceipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);
$fxRecognition = $fxReceipt !== false
    ? (new \App\Repositories\CustomerReceiptCorrectionRepository($app))->findIncomeRecognition((int) $fxReceipt['id'])
    : null;
if ($fxReceipt === false) {
    throw new RuntimeException('[ABORT] BK-000196 / RCPT-000200 was not found.');
}
if ($fxRecognition === null && (! $moneyEquals($fxReceipt['received_amount'], 500.00)
    || ! $moneyEquals($fxReceipt['allocated_amount'], 485.00)
    || ! $moneyEquals($fxReceipt['unallocated_amount'], 15.00)
    || strtoupper((string) $fxReceipt['currency']) !== 'AED')) {
    throw new RuntimeException('[ABORT] BK-000196 no longer matches the client-confirmed AED 15 FX-profit state.');
}
echo $fxRecognition === null
    ? '[READY] BK-000196 / RCPT-000200: reclassify AED 15.00 from customer credit to FX gain.' . PHP_EOL
    : '[ALREADY CORRECTED] BK-000196 AED 15.00 FX gain.' . PHP_EOL;

if (! $apply) {
    echo 'Dry run complete. No data changed. Take a production backup, then rerun with --apply.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $service = new \App\Services\CustomerReceiptCurrencyCorrectionService($app);
    if ($fxRecognition !== null) {
        (new \App\Repositories\CustomerReceiptCorrectionRepository($app))->normalizeReceiptStatus((int) $fxReceipt['id']);
    }
    foreach ($resolvedPlans as $plan) {
        $service->correctReleasedReceipt([
            'receipt_id' => $plan['receipt_id'],
            'new_currency' => 'PKR',
            'new_branch_id' => (int) $plan['service']['booking_branch_id'],
            'target_receivable_id' => (int) $plan['receivable']['id'],
            'service_line_reference' => (string) $plan['service']['line_reference'],
            'treatment' => $plan['treatment'],
            'entry_date' => date('Y-m-d'),
            'reason' => 'Client-confirmed receipt currency entry error; AED entry corrected to PKR without exchange conversion.',
        ], $actorUserId);
    }
    if ($fxRecognition === null) {
        $service->recognizeFxGain(
            (int) $fxReceipt['id'],
            15.00,
            'Client-confirmed currency conversion difference recognized as foreign exchange profit.',
            $actorUserId,
            date('Y-m-d')
        );
    }

    foreach ($pending as $item) {
        $receiptQuery->execute(['receipt_no' => $item['receipt'], 'booking_reference' => $item['booking']]);
        $receipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);
        if ($receipt === false || ! $moneyEquals($receipt['unallocated_amount'], $item['amount'])) {
            throw new RuntimeException('Protected pending credit changed during repair: ' . $item['booking'] . '.');
        }
    }
    $db->commit();
    echo '[APPLIED] Seven wrong-currency receipt states corrected; AED 15 recognized as FX gain.' . PHP_EOL;
    echo '[UNCHANGED] AED 844, AED 1,330, and AED 10 remain pending.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[ROLLED BACK] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
