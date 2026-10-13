<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ReportRepository;
use App\Services\CustomerReceiptWorkspaceService;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
$db = $app->get('db');
$db->beginTransaction();

try {
    $bookings = new BookingRepository($app);
    $payments = new CustomerPaymentRepository($app);
    $accounting = new AccountingRepository($app);
    $service = new CustomerReceiptWorkspaceService($app);
    $today = date('Y-m-d');
    $bookingData = [
        'branch_id' => 2, 'business_source_id' => 1, 'booking_status' => 'draft',
        'booking_date' => $today, 'due_date' => null, 'departure_date' => null,
        'return_date' => null, 'remarks' => 'Rollback-only bank receipt branch test', 'actor_user_id' => 10,
    ];
    $party = [
        'party_label' => 'TEST', 'lead_traveler_name' => 'BANK BRANCH CORRECTION TEST',
        'contact_mobile' => null, 'passport_number' => null, 'notes' => null,
    ];
    $bookingId = $bookings->createBooking($bookingData, $party);
    $reference = (string) $bookings->findBookingById($bookingId)['booking_reference'];
    $insertService = $db->prepare(
        'INSERT INTO booking_services
         (booking_id, branch_id, line_reference, service_type, currency, cost_currency, service_charge_currency)
         VALUES (:booking_id, 2, "SV-001", "other", "AED", "PKR", "AED")'
    );
    $insertService->execute(['booking_id' => $bookingId]);
    $receiptNo = 'TEST-BANK-' . $bookingId;
    $receiptId = $payments->createReceipt([
        'branch_id' => 2, 'business_source_id' => 1, 'booking_reference' => $reference,
        'receipt_no' => $receiptNo, 'receipt_date' => $today, 'currency' => 'AED',
        'received_amount' => 23, 'payment_method' => 'bank_transfer',
        'treasury_account_id' => 36, 'actor_user_id' => 10,
    ]);
    $originalId = $accounting->postCustomerReceiptRecorded([
        'branch_id' => 2, 'booking_reference' => $reference, 'customer_receipt_id' => $receiptId,
        'receipt_no' => $receiptNo, 'received_amount' => 23, 'charges_amount' => 0,
        'payment_method' => 'bank_transfer', 'treasury_account_id' => 36,
        'entry_date' => $today, 'currency' => 'AED', 'actor_user_id' => 10,
    ]);
    $bankAccountId = (int) $db->query('SELECT linked_account_id FROM treasury_accounts WHERE id = 36')->fetchColumn();
    $cashAccountId = (int) $db->query('SELECT linked_account_id FROM treasury_accounts WHERE id = 82')->fetchColumn();

    $bookingData['branch_id'] = 1;
    $bookings->updateBooking($bookingId, $bookingData, $party);
    $receipt = $payments->findReceiptById($receiptId);
    $journalBranch = (int) $db->query('SELECT branch_id FROM journal_entries WHERE id = ' . $originalId)->fetchColumn();
    $reclass = $db->prepare(
        'SELECT branch_id FROM journal_entries
         WHERE booking_reference = :reference AND source_type = "booking_branch_credit_reclassified"
         ORDER BY id'
    );
    $reclass->execute(['reference' => $reference]);
    if ((int) $receipt['branch_id'] !== 1 || (int) $receipt['treasury_account_id'] !== 36
        || $journalBranch !== 2 || array_map('intval', $reclass->fetchAll(PDO::FETCH_COLUMN)) !== [2, 1]
        || abs($accounting->receiptAssetBalance($receiptId, $bankAccountId, 2, 'AED') - 23) > 0.005
    ) {
        throw new RuntimeException('Branch move changed the bank balance or failed to reclassify customer credit.');
    }
    echo '[PASS] Bank receipt stays in its actual account while booking credit moves to the corrected branch.', PHP_EOL;

    $bookingData['branch_id'] = 2;
    $bookings->updateBooking($bookingId, $bookingData, $party);
    $bookingData['branch_id'] = 1;
    $bookings->updateBooking($bookingId, $bookingData, $party);
    $receipt = $payments->findReceiptById($receiptId);
    $creditByBranch = $db->prepare(
        'SELECT je.branch_id, SUM(jel.credit_amount - jel.debit_amount) AS net_credit
         FROM journal_entry_lines jel
         INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE jel.customer_receipt_id = :receipt_id AND coa.code = "CUSTOMER_CREDIT"
         GROUP BY je.branch_id'
    );
    $creditByBranch->execute(['receipt_id' => $receiptId]);
    $credits = [];
    foreach ($creditByBranch->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $credits[(int) $row['branch_id']] = (float) $row['net_credit'];
    }
    if ((int) $receipt['branch_id'] !== 1 || (int) $receipt['treasury_account_id'] !== 36
        || abs($accounting->receiptAssetBalance($receiptId, $bankAccountId, 2, 'AED') - 23) > 0.005
        || abs(($credits[1] ?? 0) - 23) > 0.005 || abs($credits[2] ?? 0) > 0.005
    ) {
        throw new RuntimeException('Repeated booking moves reassigned a retained bank receipt.');
    }
    echo '[PASS] Repeated booking branch changes do not move bank cash without an account correction.', PHP_EOL;

    $service->correctReceiptAmount([
        'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
        'expected_received_amount' => 23, 'corrected_received_amount' => 24,
        'correction_reason' => 'Verified original bank deposit amount',
    ], 10, [1, 2]);
    if (abs($accounting->receiptAssetBalance($receiptId, $bankAccountId, 2, 'AED') - 24) > 0.005) {
        throw new RuntimeException('Cross-branch amount correction did not stay on the bank branch.');
    }
    echo '[PASS] Later amount correction keeps bank cash and booking credit in their respective branches.', PHP_EOL;

    $service->correctReceiptAccount([
        'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
        'expected_treasury_account_id' => 36, 'corrected_treasury_account_id' => 82,
        'correction_reason' => 'Actual payment was received in Swat cash',
    ], 10, [1, 2]);
    $receipt = $payments->findReceiptById($receiptId);
    if ((int) $receipt['treasury_account_id'] !== 82 || (string) $receipt['payment_method'] !== 'cash'
        || abs($accounting->receiptAssetBalance($receiptId, $bankAccountId, 2, 'AED')) > 0.005
        || abs($accounting->receiptAssetBalance($receiptId, $cashAccountId, 1, 'AED') - 24) > 0.005
    ) {
        throw new RuntimeException('Explicit account correction did not preserve the receipt and cash balances.');
    }
    echo '[PASS] Explicit account correction reclassifies AED 24 from bank to cash without changing the receipt amount.', PHP_EOL;

    $report = new ReportRepository($app);
    $bankRows = array_values(array_filter(
        $report->cashBankLedger([1, 2], null, null, 'AED', 'all', null, 36),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $reference
    ));
    $cashRows = array_values(array_filter(
        $report->cashBankLedger([1, 2], null, null, 'AED', 'all', null, 82),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $reference
    ));
    $bankNet = array_sum(array_map(static fn (array $row): float => (float) $row['debit_amount'] - (float) $row['credit_amount'], $bankRows));
    $cashNet = array_sum(array_map(static fn (array $row): float => (float) $row['debit_amount'] - (float) $row['credit_amount'], $cashRows));
    if (count($bankRows) < 3 || count($cashRows) < 1 || abs($bankNet) > 0.005 || abs($cashNet - 24) > 0.005) {
        throw new RuntimeException('Detailed cash/bank ledger lost historical receipt-account movements.');
    }
    echo '[PASS] Detailed treasury ledger shows the original bank entry and both sides of the account correction.', PHP_EOL;

    $bookingData['branch_id'] = 2;
    $bookings->updateBooking($bookingId, $bookingData, $party);
    $receipt = $payments->findReceiptById($receiptId);
    if ((int) $receipt['branch_id'] !== 2 || (int) $receipt['treasury_account_id'] !== 82
        || abs($accounting->receiptAssetBalance($receiptId, $cashAccountId, 1, 'AED') - 24) > 0.005
    ) {
        throw new RuntimeException('Repeated branch move rewrote the explicitly corrected cash account.');
    }
    echo '[PASS] Repeated branch changes retain an explicitly corrected account and its historical journals.', PHP_EOL;

    $service->correctReceiptAmount([
        'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
        'expected_received_amount' => 24, 'corrected_received_amount' => 25,
        'correction_reason' => 'Verified cash count after branch correction',
    ], 10, [1, 2]);
    if (abs($accounting->receiptAssetBalance($receiptId, $cashAccountId, 1, 'AED') - 25) > 0.005) {
        throw new RuntimeException('Cash amount correction after repeated branch change used the booking branch for the asset.');
    }
    echo '[PASS] Corrected cash account still receives later amount adjustments after another branch change.', PHP_EOL;

    $service->voidReceipt([
        'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
        'void_reason' => 'Rollback test of an incorrectly recorded receipt',
        'no_money_received_confirmed' => '1',
    ], 10, [1, 2]);
    $creditNet = $db->prepare(
        'SELECT je.branch_id, SUM(jel.credit_amount - jel.debit_amount) AS net_credit
         FROM journal_entry_lines jel
         INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE jel.customer_receipt_id = :receipt_id AND coa.code = "CUSTOMER_CREDIT"
         GROUP BY je.branch_id'
    );
    $creditNet->execute(['receipt_id' => $receiptId]);
    foreach ($creditNet->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (abs((float) $row['net_credit']) > 0.005) {
            throw new RuntimeException('Voided receipt left a customer-credit balance in branch ' . $row['branch_id']);
        }
    }
    if (abs($accounting->receiptAssetBalance($receiptId, $bankAccountId, 2, 'AED')) > 0.005
        || abs($accounting->receiptAssetBalance($receiptId, $cashAccountId, 1, 'AED')) > 0.005
        || (string) $payments->findReceiptById($receiptId)['status'] !== 'void'
    ) {
        throw new RuntimeException('Voided corrected receipt left a cash or bank balance.');
    }
    echo '[PASS] Voiding an erroneous corrected receipt reverses all linked journals in their original branches.', PHP_EOL;

    $historicalJournals = $db->prepare(
        'SELECT DISTINCT je.id, je.branch_id
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE jel.customer_receipt_id = :receipt_id
         ORDER BY je.id'
    );
    $historicalJournals->execute(['receipt_id' => $receiptId]);
    $before = $historicalJournals->fetchAll(PDO::FETCH_ASSOC);
    $bookingData['branch_id'] = 1;
    $bookings->updateBooking($bookingId, $bookingData, $party);
    $historicalJournals->execute(['receipt_id' => $receiptId]);
    if ($historicalJournals->fetchAll(PDO::FETCH_ASSOC) !== $before) {
        throw new RuntimeException('Moving a booking rewrote historical journals for its voided receipt.');
    }
    echo '[PASS] Later booking edits do not relabel cash or credit journals of a voided receipt.', PHP_EOL;
} finally {
    $db->rollBack();
}
