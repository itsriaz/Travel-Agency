<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ReportRepository;
use App\Repositories\TreasuryRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$account = $db->query(
    'SELECT ta.id, ta.branch_id, ta.currency, ta.linked_account_id, br.name AS branch_name
     FROM treasury_accounts ta
     INNER JOIN branches br ON br.id = ta.branch_id
     WHERE ta.id = 33 AND ta.is_active = 1 AND ta.currency = "PKR"'
)->fetch(PDO::FETCH_ASSOC);
if ($account === false || (int) $account['branch_id'] !== 1) {
    throw new RuntimeException('The rollback-only fixture requires active Swat PKR cash account 33.');
}

$db->beginTransaction();
try {
    $bookingRepository = new BookingRepository($app);
    $payments = new CustomerPaymentRepository($app);
    $accounting = new AccountingRepository($app);
    $reports = new ReportRepository($app);
    $treasury = new TreasuryRepository($app);
    $date = date('Y-m-d');
    $beforeBalance = $treasury->currentBalanceForAccountId(33);
    $cashIn = static function (array $rows): float {
        return array_reduce($rows, static fn (float $total, array $row): float => $total + (float) ($row['cash_in_amount'] ?? 0), 0.0);
    };
    $swatCashFlowBefore = $cashIn($reports->cashFlow([1], $date, $date)['receipts']);
    $nobleCashFlowBefore = $cashIn($reports->cashFlow([2], $date, $date)['receipts']);
    $beforePosition = null;
    foreach ($reports->cashBankPosition([1], $date, 'PKR') as $row) {
        if (($row['account_name'] ?? '') === 'Cash-SWT-PKR') {
            $beforePosition = (float) $row['balance'];
            break;
        }
    }
    if ($beforePosition === null) {
        throw new RuntimeException('Swat PKR cash position was not found.');
    }

    $bookingId = $bookingRepository->createBooking([
        'branch_id' => 2,
        'business_source_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $date,
        'due_date' => null,
        'departure_date' => null,
        'return_date' => null,
        'remarks' => 'Rollback-only cross-branch cash ledger regression',
        'actor_user_id' => 10,
    ], [
        'party_label' => 'TEST',
        'lead_traveler_name' => 'CROSS BRANCH CASH TEST',
        'contact_mobile' => null,
        'passport_number' => null,
        'notes' => null,
    ]);
    $booking = $bookingRepository->findBookingById($bookingId);
    $reference = (string) ($booking['booking_reference'] ?? '');
    if ($reference === '') {
        throw new RuntimeException('Test booking reference was not created.');
    }
    $receiptNo = 'TEST-CROSS-CASH-' . $bookingId;
    $receiptId = $payments->createReceipt([
        'branch_id' => 2,
        'business_source_id' => 1,
        'booking_reference' => $reference,
        'receipt_no' => $receiptNo,
        'receipt_date' => $date,
        'currency' => 'PKR',
        'received_amount' => 17,
        'payment_method' => 'cash',
        'treasury_account_id' => 33,
        'actor_user_id' => 10,
    ]);
    $accounting->postCustomerReceiptRecorded([
        'branch_id' => 2,
        'booking_reference' => $reference,
        'customer_receipt_id' => $receiptId,
        'receipt_no' => $receiptNo,
        'received_amount' => 17,
        'charges_amount' => 0,
        'payment_method' => 'cash',
        'treasury_account_id' => 33,
        'entry_date' => $date,
        'currency' => 'PKR',
        'actor_user_id' => 10,
    ]);

    $swatRows = array_values(array_filter(
        $reports->cashBankLedger([1], $date, $date, 'PKR', 'customer', null, 33),
        static fn (array $row): bool => ($row['reference'] ?? '') === $receiptNo
    ));
    $nobleRows = array_values(array_filter(
        $reports->cashBankLedger([2], $date, $date, 'PKR', 'customer'),
        static fn (array $row): bool => ($row['reference'] ?? '') === $receiptNo
    ));
    $afterPosition = null;
    foreach ($reports->cashBankPosition([1], $date, 'PKR') as $row) {
        if (($row['account_name'] ?? '') === 'Cash-SWT-PKR') {
            $afterPosition = (float) $row['balance'];
            break;
        }
    }
    if (count($swatRows) !== 1
        || count($nobleRows) !== 0
        || (int) $swatRows[0]['branch_id'] !== 1
        || abs((float) $swatRows[0]['debit_amount'] - 17) > 0.005
        || !str_contains((string) $swatRows[0]['description'], 'Booking branch:')
        || abs($treasury->currentBalanceForAccountId(33) - $beforeBalance - 17) > 0.005
        || abs($cashIn($reports->cashFlow([1], $date, $date)['receipts']) - $swatCashFlowBefore - 17) > 0.005
        || abs($cashIn($reports->cashFlow([2], $date, $date)['receipts']) - $nobleCashFlowBefore) > 0.005
        || $afterPosition === null
        || abs($afterPosition - $beforePosition - 17) > 0.005
        || abs($accounting->receiptAssetBalance($receiptId, (int) $account['linked_account_id'], 1, 'PKR') - 17) > 0.005
    ) {
        throw new RuntimeException('Cross-branch receipt was omitted, duplicated, or attributed to the wrong cash branch.');
    }

    echo '[PASS] Noble booking receipt appears once in Swat cash ledger, position, and account balance.', PHP_EOL;
} finally {
    $db->rollBack();
}
