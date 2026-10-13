<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();

try {
    $bookingRepository = new BookingRepository($app);
    $payments = new CustomerPaymentRepository($app);
    $accounting = new AccountingRepository($app);
    $date = date('Y-m-d');
    $bookingData = [
        'branch_id' => 2,
        'business_source_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $date,
        'due_date' => null,
        'departure_date' => null,
        'return_date' => null,
        'remarks' => 'Rollback-only branch correction regression',
        'actor_user_id' => 10,
    ];
    $party = [
        'party_label' => 'TEST',
        'lead_traveler_name' => 'BRANCH CORRECTION TEST',
        'contact_mobile' => null,
        'passport_number' => null,
        'notes' => null,
    ];
    $bookingId = $bookingRepository->createBooking($bookingData, $party);
    $booking = $bookingRepository->findBookingById($bookingId);
    if ($booking === null) {
        throw new RuntimeException('Test booking was not created.');
    }
    $reference = (string) $booking['booking_reference'];
    $service = $db->prepare(
        'INSERT INTO booking_services
         (booking_id, branch_id, line_reference, service_type, currency, cost_currency, service_charge_currency)
         VALUES (:booking_id, 2, :line_reference, :service_type, :currency, :cost_currency, :service_charge_currency)'
    );
    $service->execute([
        'booking_id' => $bookingId,
        'line_reference' => 'SV-001',
        'service_type' => 'other',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'service_charge_currency' => 'AED',
    ]);
    $serviceId = (int) $db->lastInsertId();
    $receiptId = $payments->createReceipt([
        'branch_id' => 2,
        'business_source_id' => 1,
        'booking_reference' => $reference,
        'receipt_no' => 'TEST-BRANCH-' . $bookingId,
        'receipt_date' => $date,
        'currency' => 'AED',
        'received_amount' => 23,
        'payment_method' => 'cash',
        'treasury_account_id' => 34,
        'actor_user_id' => 10,
    ]);
    $originalJournalId = $accounting->postCustomerReceiptRecorded([
        'branch_id' => 2,
        'booking_reference' => $reference,
        'customer_receipt_id' => $receiptId,
        'receipt_no' => 'TEST-BRANCH-' . $bookingId,
        'received_amount' => 23,
        'charges_amount' => 0,
        'payment_method' => 'cash',
        'treasury_account_id' => 34,
        'entry_date' => $date,
        'currency' => 'AED',
        'actor_user_id' => 10,
    ]);

    $bookingData['branch_id'] = 1;
    $bookingRepository->updateBooking($bookingId, $bookingData, $party);
    $saved = $bookingRepository->findBookingById($bookingId);
    $receipt = $db->query('SELECT branch_id, treasury_account_id FROM customer_receipts WHERE id = ' . $receiptId)->fetch(PDO::FETCH_ASSOC);
    $serviceBranch = $db->query('SELECT branch_id FROM booking_services WHERE id = ' . $serviceId)->fetchColumn();
    $journals = $db->prepare(
        'SELECT id, branch_id, source_type FROM journal_entries
         WHERE booking_reference = :booking_reference AND id >= :original_journal_id ORDER BY id ASC'
    );
    $journals->execute(['booking_reference' => $reference, 'original_journal_id' => $originalJournalId]);
    $rows = $journals->fetchAll(PDO::FETCH_ASSOC);
    $types = [];
    foreach ($rows as $row) {
        $types[(string) $row['source_type']][] = (int) $row['branch_id'];
    }
    $cashBalances = $db->prepare(
        'SELECT jel.account_id, SUM(jel.debit_amount - jel.credit_amount) AS net_amount
         FROM journal_entry_lines jel
         INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
         WHERE je.booking_reference = :booking_reference
           AND jel.account_id IN (
               SELECT linked_account_id FROM treasury_accounts WHERE id IN (34, 82)
           )
         GROUP BY jel.account_id'
    );
    $cashBalances->execute(['booking_reference' => $reference]);
    $netByAccount = [];
    foreach ($cashBalances->fetchAll(PDO::FETCH_ASSOC) as $balance) {
        $netByAccount[(int) $balance['account_id']] = (float) $balance['net_amount'];
    }
    $oldCashAccountId = (int) $db->query('SELECT linked_account_id FROM treasury_accounts WHERE id = 34')->fetchColumn();
    $newCashAccountId = (int) $db->query('SELECT linked_account_id FROM treasury_accounts WHERE id = 82')->fetchColumn();
    if ((int) ($saved['branch_id'] ?? 0) !== 1
        || (int) ($receipt['branch_id'] ?? 0) !== 1
        || (int) ($receipt['treasury_account_id'] ?? 0) !== 82
        || (int) $serviceBranch !== 1
        || ($types['booking_branch_receipt_reversal'] ?? []) !== [2]
        || ($types['customer_receipt_recorded'] ?? []) !== [2, 1]
        || abs(($netByAccount[$oldCashAccountId] ?? 0.0)) > 0.005
        || abs(($netByAccount[$newCashAccountId] ?? 0.0) - 23.0) > 0.005
    ) {
        throw new RuntimeException('Booking, receipt, service, and cash journals did not move together.');
    }
    echo '[PASS] Branch correction moves booking and cash receipt, reverses old cash, and posts new cash without changing the amount.', PHP_EOL;

    $bookingData['branch_id'] = 2;
    $bookingRepository->updateBooking($bookingId, $bookingData, $party);
    $bookingData['branch_id'] = 1;
    $bookingRepository->updateBooking($bookingId, $bookingData, $party);
    $repeatReceipt = $db->query('SELECT branch_id, treasury_account_id FROM customer_receipts WHERE id = ' . $receiptId)->fetch(PDO::FETCH_ASSOC);
    $repeatJournals = $db->prepare(
        'SELECT branch_id, source_type FROM journal_entries
         WHERE booking_reference = :booking_reference
           AND source_type IN ("customer_receipt_recorded", "booking_branch_receipt_reversal")
         ORDER BY id ASC'
    );
    $repeatJournals->execute(['booking_reference' => $reference]);
    $recordedBranches = [];
    $reversedBranches = [];
    foreach ($repeatJournals->fetchAll(PDO::FETCH_ASSOC) as $journal) {
        if ($journal['source_type'] === 'customer_receipt_recorded') {
            $recordedBranches[] = (int) $journal['branch_id'];
        } else {
            $reversedBranches[] = (int) $journal['branch_id'];
        }
    }
    $cashBalances->execute(['booking_reference' => $reference]);
    $netByAccount = [];
    foreach ($cashBalances->fetchAll(PDO::FETCH_ASSOC) as $balance) {
        $netByAccount[(int) $balance['account_id']] = (float) $balance['net_amount'];
    }
    if ((int) ($repeatReceipt['branch_id'] ?? 0) !== 1
        || (int) ($repeatReceipt['treasury_account_id'] ?? 0) !== 82
        || $recordedBranches !== [2, 1, 2, 1]
        || $reversedBranches !== [2, 1, 2]
        || abs(($netByAccount[$oldCashAccountId] ?? 0.0)) > 0.005
        || abs(($netByAccount[$newCashAccountId] ?? 0.0) - 23.0) > 0.005
    ) {
        throw new RuntimeException('Repeated branch corrections lost history or duplicated cash.');
    }
    echo '[PASS] Repeated branch changes retain every historical cash journal and leave one AED 23 receipt in the current branch.', PHP_EOL;
} finally {
    $db->rollBack();
}
