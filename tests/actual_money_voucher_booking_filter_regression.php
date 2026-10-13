<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\ReportRepository;

$app = App::bootstrap(BASE_PATH);
$repository = new ReportRepository($app);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$bookingRows = $repository->actualMoneyVoucherLedger([1, 2], null, null, '', '', 0, 'BK-000529');
$receiptReferences = array_column($bookingRows, 'booking_reference');
sort($receiptReferences);
$assert(
    $receiptReferences === ['RCPT-000592', 'RCPT-000759'],
    'Booking search must find both actual AED customer receipts, not mistake receipt numbers for booking references.'
);
$assert(
    array_sum(array_map(static fn (array $row): float => (float) $row['debit_amount'], $bookingRows)) === 5150.0
        && count(array_filter($bookingRows, static fn (array $row): bool => $row['currency'] === 'AED')) === 2,
    'BK-000529 actual money must show AED 4,150 plus AED 1,000.'
);

$receiptRows = $repository->actualMoneyVoucherLedger([1, 2], null, null, '', '', 0, 'RCPT-000759');
$assert(count($receiptRows) === 1 && $receiptRows[0]['booking_reference'] === 'RCPT-000759',
    'Searching by displayed receipt number must remain supported.');
$assert($repository->actualMoneyVoucherLedger([1], null, null, '', '', 0, 'BK-000529') === [],
    'A booking search must not bypass the accessible branch scope.');

echo '[PASS] BK-000529 booking and receipt searches show actual money without crossing branch scope.' . PHP_EOL;
