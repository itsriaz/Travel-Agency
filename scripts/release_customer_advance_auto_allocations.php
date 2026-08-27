<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$receiptNo = strtoupper(trim((string) ($argv[1] ?? '')));
$apply = in_array('--apply', $argv, true);

if ($receiptNo === '') {
    fwrite(STDERR, "Usage: php scripts/release_customer_advance_auto_allocations.php RCPT-000790 [--apply]\n");
    exit(1);
}

$repository = new \App\Repositories\CustomerPaymentRepository($app);
$accountingRepository = new \App\Repositories\AccountingRepository($app);

$statement = $db->prepare(
    'SELECT id, branch_id, booking_reference, receipt_no, currency, receipt_purpose, status
     FROM customer_receipts
     WHERE UPPER(receipt_no) = :receipt_no
     LIMIT 1'
);
$statement->execute([
    'receipt_no' => $receiptNo,
]);
$receipt = $statement->fetch(PDO::FETCH_ASSOC);

if (! is_array($receipt)) {
    fwrite(STDERR, "Customer receipt {$receiptNo} was not found.\n");
    exit(1);
}

if ((string) ($receipt['receipt_purpose'] ?? '') !== 'customer_advance') {
    fwrite(STDERR, "Customer receipt {$receiptNo} is not a customer advance.\n");
    exit(1);
}

$db->beginTransaction();

try {
    $result = $repository->releaseSpecificReceiptAllocations(
        (int) $receipt['id'],
        null,
        0,
        'Release unintended automatic customer advance allocation'
    );

    foreach ((array) ($result['released_allocations'] ?? []) as $allocation) {
        $releasedAmount = round((float) ($allocation['released_amount'] ?? 0), 2);
        if ($releasedAmount <= 0.005) {
            continue;
        }

        $accountingRepository->postCustomerReceiptAllocationRelease([
            'branch_id' => (int) ($allocation['branch_id'] ?? (int) ($receipt['branch_id'] ?? 0)),
            'booking_reference' => (string) ($allocation['booking_reference'] ?? ''),
            'source_reference' => (string) ($receipt['receipt_no'] ?? $receiptNo) . '-ALLOC-REL-' . (int) ($allocation['allocation_id'] ?? 0),
            'service_line_reference' => (string) ($allocation['service_line_reference'] ?? '') !== ''
                ? (string) $allocation['service_line_reference']
                : null,
            'customer_receivable_item_id' => (int) ($allocation['customer_receivable_item_id'] ?? 0),
            'released_amount' => $releasedAmount,
            'entry_date' => date('Y-m-d'),
            'currency' => (string) ($allocation['currency'] ?? $receipt['currency'] ?? 'PKR'),
            'actor_user_id' => 0,
            'narration' => 'Customer advance allocation released back to manual advance pool',
        ]);
    }

    $summary = [
        'receipt_no' => (string) ($receipt['receipt_no'] ?? $receiptNo),
        'released_amount' => round((float) ($result['released_amount'] ?? 0), 2),
        'released_payment_amount' => round((float) ($result['released_payment_amount'] ?? 0), 2),
        'allocation_count_released' => (int) ($result['allocation_count_released'] ?? 0),
        'new_allocated_amount' => round((float) ($result['receipt']['allocated_amount'] ?? 0), 2),
        'new_unallocated_amount' => round((float) ($result['receipt']['unallocated_amount'] ?? 0), 2),
        'new_status' => (string) ($result['receipt']['status'] ?? ''),
        'released_allocations' => array_values(array_map(
            static function (array $row): array {
                return [
                    'allocation_id' => (int) ($row['allocation_id'] ?? 0),
                    'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                    'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'released_amount' => round((float) ($row['released_amount'] ?? 0), 2),
                ];
            },
            (array) ($result['released_allocations'] ?? [])
        )),
    ];

    if ($apply) {
        $db->commit();
        echo json_encode(['mode' => 'applied'] + $summary, JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    $db->rollBack();
    echo json_encode(['mode' => 'dry-run'] + $summary, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (\Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
