<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};
$money = static fn (mixed $value): float => round((float) $value, 2);

echo 'Linked-party end-to-end acceptance scenario' . PHP_EOL;

$actorId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
     ORDER BY (r.code = "super_admin") DESC, u.id
     LIMIT 1'
)->fetchColumn();
$treasuryAccount = $db->query(
    'SELECT ta.id, ta.branch_id, ta.account_type, ta.currency
     FROM treasury_accounts ta
     INNER JOIN branches b ON b.id = ta.branch_id AND b.is_active = 1
     WHERE ta.is_active = 1
       AND ta.currency = "PKR"
       AND ta.account_type IN ("cash", "bank")
     ORDER BY (ta.account_type = "cash") DESC, ta.is_default DESC, ta.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
if ($actorId <= 0 || (int) ($treasuryAccount['id'] ?? 0) <= 0) {
    throw new RuntimeException('A financial administrator and active PKR cash/bank account are required.');
}

$branchId = (int) $treasuryAccount['branch_id'];
$paymentMethod = (string) $treasuryAccount['account_type'] === 'bank' ? 'bank_transfer' : 'cash';
$protectedTables = [
    'customer_receipts',
    'customer_receipt_allocations',
    'supplier_payments',
    'supplier_payment_allocations',
    'treasury_transactions',
    'counterparty_offsets',
];
$beforeCounts = [];
foreach ($protectedTables as $table) {
    $beforeCounts[$table] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
$automaticBefore = (int) $db->query(
    'SELECT COUNT(*) FROM counterparty_offsets WHERE offset_no LIKE "AUTO-OFFSET-%"'
)->fetchColumn();

$db->beginTransaction();
try {
    $suffix = date('His') . random_int(100, 999);
    $today = date('Y-m-d');
    $accountName = 'QA Linked Account ' . $suffix;
    $supplierName = 'QA Linked Supplier ' . $suffix;
    $otherAccountName = 'QA Other Account ' . $suffix;
    $saleBooking = 'QA-LINK-SALE-' . $suffix;
    $purchaseBooking = 'QA-LINK-BUY-' . $suffix;

    $sourceStatement = $db->prepare(
        'INSERT INTO business_sources (code, name, is_system, is_active)
         VALUES (:code, :name, 0, 1)'
    );
    $sourceStatement->execute(['code' => 'QA-LEA-' . $suffix, 'name' => $accountName]);
    $businessSourceId = (int) $db->lastInsertId();
    $sourceStatement->execute(['code' => 'QA-LEO-' . $suffix, 'name' => $otherAccountName]);
    $otherBusinessSourceId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
    )->execute([
        'branch_id' => $branchId,
        'code' => 'QA-LES-' . $suffix,
        'name' => $supplierName,
    ]);
    $supplierId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id)
         VALUES (:business_source_id, :supplier_id, :actor_user_id)'
    )->execute([
        'business_source_id' => $businessSourceId,
        'supplier_id' => $supplierId,
        'actor_user_id' => $actorId,
    ]);
    $linkId = (int) $db->lastInsertId();

    $bookingStatement = $db->prepare(
        'INSERT INTO bookings (
            booking_reference, branch_id, business_source_id, booking_status, booking_date,
            created_by_user_id, updated_by_user_id
         ) VALUES (
            :booking_reference, :branch_id, :business_source_id, "open", :booking_date,
            :created_by_user_id, :updated_by_user_id
         )'
    );
    $partyStatement = $db->prepare(
        'INSERT INTO booking_parties (booking_id, lead_traveler_name, contact_mobile)
         VALUES (:booking_id, :lead_traveler_name, :contact_mobile)'
    );
    $serviceStatement = $db->prepare(
        'INSERT INTO booking_services (
            booking_id, branch_id, line_reference, service_type, supplier_id,
            supplier_name_snapshot, passenger_name_snapshot, currency, cost_currency,
            sale_price, purchase_cost, service_charge_currency, final_sale_price,
            net_profit_loss, service_status, created_by_user_id, updated_by_user_id
         ) VALUES (
            :booking_id, :branch_id, "SV-001", "air ticket", :supplier_id,
            :supplier_name, :passenger_name, "PKR", "PKR",
            :sale_price, :purchase_cost, "PKR", :final_sale_price,
            :net_profit_loss, "Open", :created_by_user_id, :updated_by_user_id
         )'
    );
    $ticketStatement = $db->prepare(
        'INSERT INTO service_air_ticket (
            booking_service_id, pnr, ticket_number, airline, sector_from, sector_to,
            fare, supplier_cost, sale_amount
         ) VALUES (
            :booking_service_id, :pnr, :ticket_number, :airline, "ISB", "DXB",
            :fare, :supplier_cost, :sale_amount
         )'
    );

    $bookingStatement->execute([
        'booking_reference' => $saleBooking,
        'branch_id' => $branchId,
        'business_source_id' => $businessSourceId,
        'booking_date' => $today,
        'created_by_user_id' => $actorId,
        'updated_by_user_id' => $actorId,
    ]);
    $saleBookingId = (int) $db->lastInsertId();
    $partyStatement->execute([
        'booking_id' => $saleBookingId,
        'lead_traveler_name' => 'QA Sale Passenger',
        'contact_mobile' => '03000000001',
    ]);
    $serviceStatement->execute([
        'booking_id' => $saleBookingId,
        'branch_id' => $branchId,
        'supplier_id' => null,
        'supplier_name' => 'External Supplier',
        'passenger_name' => 'QA Sale Passenger',
        'sale_price' => 1000,
        'purchase_cost' => 0,
        'final_sale_price' => 1000,
        'net_profit_loss' => 1000,
        'created_by_user_id' => $actorId,
        'updated_by_user_id' => $actorId,
    ]);
    $saleServiceId = (int) $db->lastInsertId();
    $ticketStatement->execute([
        'booking_service_id' => $saleServiceId,
        'pnr' => 'QASALE' . $suffix,
        'ticket_number' => 'T-SALE-' . $suffix,
        'airline' => 'QA Air',
        'fare' => 1000,
        'supplier_cost' => 0,
        'sale_amount' => 1000,
    ]);

    $bookingStatement->execute([
        'booking_reference' => $purchaseBooking,
        'branch_id' => $branchId,
        'business_source_id' => $otherBusinessSourceId,
        'booking_date' => $today,
        'created_by_user_id' => $actorId,
        'updated_by_user_id' => $actorId,
    ]);
    $purchaseBookingId = (int) $db->lastInsertId();
    $partyStatement->execute([
        'booking_id' => $purchaseBookingId,
        'lead_traveler_name' => 'QA Purchase Passenger',
        'contact_mobile' => '03000000002',
    ]);
    $serviceStatement->execute([
        'booking_id' => $purchaseBookingId,
        'branch_id' => $branchId,
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierName,
        'passenger_name' => 'QA Purchase Passenger',
        'sale_price' => 700,
        'purchase_cost' => 700,
        'final_sale_price' => 700,
        'net_profit_loss' => 0,
        'created_by_user_id' => $actorId,
        'updated_by_user_id' => $actorId,
    ]);
    $purchaseServiceId = (int) $db->lastInsertId();
    $ticketStatement->execute([
        'booking_service_id' => $purchaseServiceId,
        'pnr' => 'QABUY' . $suffix,
        'ticket_number' => 'T-BUY-' . $suffix,
        'airline' => 'QA Air',
        'fare' => 700,
        'supplier_cost' => 700,
        'sale_amount' => 700,
    ]);

    $db->prepare(
        'INSERT INTO customer_receivable_items (
            branch_id, booking_reference, service_line_reference, currency,
            due_amount, allocated_amount, outstanding_amount, status, created_by_user_id
         ) VALUES (
            :branch_id, :booking_reference, "SV-001", "PKR",
            1000, 0, 1000, "open", :created_by_user_id
         )'
    )->execute([
        'branch_id' => $branchId,
        'booking_reference' => $saleBooking,
        'created_by_user_id' => $actorId,
    ]);
    $receivableId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, currency,
            gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", "PKR",
            700, 0, 700, "open", :created_by_user_id
         )'
    )->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'booking_reference' => $purchaseBooking,
        'created_by_user_id' => $actorId,
    ]);
    $obligationId = (int) $db->lastInsertId();

    $accounting = new \App\Repositories\AccountingRepository($app);
    $saleJournalId = $accounting->postReceivableCreated([
        'branch_id' => $branchId,
        'booking_reference' => $saleBooking,
        'service_line_reference' => 'SV-001',
        'customer_receivable_item_id' => $receivableId,
        'gross_amount' => 1000,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);
    $purchaseJournalId = $accounting->postPayableCreated([
        'branch_id' => $branchId,
        'booking_reference' => $purchaseBooking,
        'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => $obligationId,
        'gross_amount' => 700,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);

    $offsetService = new \App\Services\CounterpartyOffsetService($app);
    $offset = $offsetService->settle([
        'link_id' => $linkId,
        'branch_id' => $branchId,
        'currency' => 'PKR',
        'offset_date' => $today,
        'account_allocations' => [$receivableId => 700],
        'payable_allocations' => [$obligationId => 700],
        'reference_no' => 'QA-MATCH-' . $suffix,
        'remarks' => 'QA itemized manual linked adjustment',
    ], $actorId, [$branchId]);
    $offsetId = (int) $offset['id'];

    $receiptService = new \App\Services\AccountHolderReceiptService($app);
    $receipt = $receiptService->record([
        'link_id' => $linkId,
        'branch_id' => $branchId,
        'currency' => 'PKR',
        'amount' => 300,
        'receipt_date' => $today,
        'payment_method' => $paymentMethod,
        'treasury_account_id' => (int) $treasuryAccount['id'],
        'reference_number' => 'QA-NET-' . $suffix,
        'remarks' => 'QA net balance received on account',
    ], $actorId, [$branchId]);
    $receiptId = (int) $receipt['receipt_id'];

    $accountAllocation = $db->query(
        'SELECT customer_receivable_item_id, allocated_amount
         FROM counterparty_offset_account_allocations
         WHERE counterparty_offset_id = ' . $offsetId
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $payableAllocation = $db->query(
        'SELECT supplier_obligation_id, allocated_amount
         FROM counterparty_offset_payable_allocations
         WHERE counterparty_offset_id = ' . $offsetId
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('Manual adjustment stores the exact selected customer and supplier documents',
        (int) ($accountAllocation['customer_receivable_item_id'] ?? 0) === $receivableId
        && $money($accountAllocation['allocated_amount'] ?? 0) === 700.0
        && (int) ($payableAllocation['supplier_obligation_id'] ?? 0) === $obligationId
        && $money($payableAllocation['allocated_amount'] ?? 0) === 700.0);

    $receivableState = $db->query(
        'SELECT allocated_amount, outstanding_amount, status
         FROM customer_receivable_items WHERE id = ' . $receivableId
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $payableState = $db->query(
        'SELECT net_payable_amount, status FROM supplier_obligations WHERE id = ' . $obligationId
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('Passenger receivable remains PKR 1,000 with no fabricated customer payment',
        $money($receivableState['allocated_amount'] ?? -1) === 0.0
        && $money($receivableState['outstanding_amount'] ?? 0) === 1000.0
        && (string) ($receivableState['status'] ?? '') === 'open'
        && (int) $db->query(
            'SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = ' . $receiptId
        )->fetchColumn() === 0);
    $check('Selected supplier invoice is fully matched without a supplier payment',
        $money($payableState['net_payable_amount'] ?? -1) === 0.0
        && (string) ($payableState['status'] ?? '') === 'paid'
        && (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn() === $beforeCounts['supplier_payments']);

    $receiptState = $db->query(
        'SELECT receipt_purpose, received_amount, allocated_amount, unallocated_amount, status
         FROM customer_receipts WHERE id = ' . $receiptId
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('PKR 300 is received on account and no ticket is guessed',
        (string) ($receiptState['receipt_purpose'] ?? '') === 'account_holder_receipt'
        && $money($receiptState['received_amount'] ?? 0) === 300.0
        && $money($receiptState['allocated_amount'] ?? -1) === 0.0
        && $money($receiptState['unallocated_amount'] ?? 0) === 300.0
        && (string) ($receiptState['status'] ?? '') === 'received');

    $journalByCode = static function (PDO $db, int $journalId): array {
        $rows = $db->query(
            'SELECT coa.code, SUM(jel.debit_amount) AS debit, SUM(jel.credit_amount) AS credit
             FROM journal_entry_lines jel
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.journal_entry_id = ' . $journalId . '
             GROUP BY coa.code'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, null, 'code');
    };
    $offsetJournal = $journalByCode($db, (int) $offset['journal_entry_id']);
    $receiptJournal = $journalByCode($db, (int) $receipt['journal_entry_id']);
    $check('Manual adjustment journal is PKR 700 AP debit against AR credit',
        $money($offsetJournal['AP_CONTROL']['debit'] ?? 0) === 700.0
        && $money($offsetJournal['AR_CONTROL']['credit'] ?? 0) === 700.0);
    $receiptDebit = array_sum(array_map(
        static fn (array $row): float => (float) ($row['debit'] ?? 0),
        $receiptJournal
    ));
    $receiptCredit = array_sum(array_map(
        static fn (array $row): float => (float) ($row['credit'] ?? 0),
        $receiptJournal
    ));
    $treasuryCodePrefix = (string) $treasuryAccount['account_type'] === 'bank' ? 'BANK_' : 'CASH_';
    $treasuryDebit = 0.0;
    foreach ($receiptJournal as $accountCode => $journalLine) {
        if (str_starts_with((string) $accountCode, $treasuryCodePrefix)) {
            $treasuryDebit += (float) ($journalLine['debit'] ?? 0);
        }
    }
    $check('Net receipt journal is PKR 300 cash/bank debit against customer credit',
        $money($receiptDebit) === 300.0
        && $money($receiptCredit) === 300.0
        && $money($treasuryDebit) === 300.0
        && $money($receiptJournal['CUSTOMER_CREDIT']['credit'] ?? 0) === 300.0);

    $accountReport = (new \App\Services\AccountLedgerService($app))->report([
        'currency' => 'PKR',
        'businessSourceId' => $businessSourceId,
    ], [$branchId]);
    $supplierReport = (new \App\Services\SupplierLedgerService($app))->report([
        'currency' => 'PKR',
        'supplierId' => $supplierId,
    ], [$branchId]);
    $linkedService = new \App\Services\LinkedPartyLedgerService($app);
    $linkedAccountReport = $linkedService->buildReport(
        (array) ($accountReport['rows'] ?? []),
        (array) ($supplierReport['rows'] ?? []),
        null,
        null,
        'PKR'
    );
    $linkedSupplierReport = $linkedService->buildSupplierReport(
        (array) ($accountReport['rows'] ?? []),
        (array) ($supplierReport['rows'] ?? []),
        null,
        null,
        'PKR',
        $supplierName
    );

    $movementTotals = [];
    foreach ((array) ($linkedAccountReport['rows'] ?? []) as $row) {
        $entry = (string) ($row['ledger_entry'] ?? '');
        $movementTotals[$entry]['debit'] = round(
            ($movementTotals[$entry]['debit'] ?? 0.0) + (float) ($row['raw_debit_amount'] ?? 0),
            2
        );
        $movementTotals[$entry]['credit'] = round(
            ($movementTotals[$entry]['credit'] ?? 0.0) + (float) ($row['raw_credit_amount'] ?? 0),
            2
        );
    }
    $saleInvoiceRows = array_values(array_filter(
        (array) ($linkedAccountReport['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $saleBooking
            && (float) ($row['raw_debit_amount'] ?? 0) === 1000.0
            && (float) ($row['raw_credit_amount'] ?? 0) === 0.0
    ));
    $purchaseInvoiceRows = array_values(array_filter(
        (array) ($linkedAccountReport['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === $purchaseBooking
            && (float) ($row['raw_debit_amount'] ?? 0) === 0.0
            && (float) ($row['raw_credit_amount'] ?? 0) === 700.0
    ));
    $check('Unified ledger shows the PKR 1,000 sale and PKR 700 supplier purchase',
        count($saleInvoiceRows) === 1 && count($purchaseInvoiceRows) === 1);
    $check('Unified ledger itemizes both non-cash adjustment legs without hiding them',
        $money($movementTotals['Account balance applied to linked supplier']['credit'] ?? 0) === 700.0
        && $money($movementTotals['Linked account balance adjustment']['debit'] ?? 0) === 700.0);
    $check('Unified ledger shows the PKR 300 receipt once and closes the linked net balance',
        $money($movementTotals['Net payment received']['credit'] ?? 0) === 300.0
        && count($linkedAccountReport['footerRows'] ?? []) === 1
        && (string) ($linkedAccountReport['footerRows'][0]['debit_amount'] ?? '') === '1,700'
        && (string) ($linkedAccountReport['footerRows'][0]['credit_amount'] ?? '') === '1,700'
        && (string) ($linkedAccountReport['footerRows'][0]['balance_amount'] ?? '') === '0');
    $check('Linked supplier view presents the same closed balance in supplier orientation',
        count($linkedSupplierReport['footerRows'] ?? []) === 1
        && (string) ($linkedSupplierReport['footerRows'][0]['balance_amount'] ?? '') === '0');

    $openMoney = (new \App\Services\ReportService($app))->reportState([
        'report' => 'unallocated_money',
        'branch_id' => $branchId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $receiptRows = array_values(array_filter(
        (array) ($openMoney['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['document_no'] ?? '') === (string) $receipt['receipt_no']
    ));
    $openMoneyRow = $receiptRows[0] ?? [];
    $check('Open-money report clearly identifies the PKR 300 as received on account',
        count($receiptRows) === 1
        && (string) ($openMoneyRow['source_type'] ?? '') === 'Account-Holder Payment on Account'
        && (string) ($openMoneyRow['status'] ?? '') === 'Received On Account'
        && $money($openMoneyRow['unallocated_amount'] ?? 0) === 300.0);
    $check('Automatic linked adjustment remains disabled',
        $offsetService->autoSettleLink($linkId, $today, $actorId) === []
        && (int) $db->query(
            'SELECT COUNT(*) FROM counterparty_offsets WHERE offset_no LIKE "AUTO-OFFSET-%"'
        )->fetchColumn() === $automaticBefore);

    $receiptVoid = $receiptService->void([
        'link_id' => $linkId,
        'customer_receipt_id' => $receiptId,
        'void_reason' => 'QA end-to-end receipt reversal',
    ], $actorId, [$branchId]);
    $offsetVoid = $offsetService->void(
        $offsetId,
        'QA end-to-end manual adjustment reversal',
        $actorId,
        [$branchId]
    );

    $restoredPayable = $db->query(
        'SELECT net_payable_amount, status FROM supplier_obligations WHERE id = ' . $obligationId
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $voidedReceiptStatus = (string) $db->query(
        'SELECT status FROM customer_receipts WHERE id = ' . $receiptId
    )->fetchColumn();
    $check('Reversals restore the PKR 700 payable and void the PKR 300 receipt',
        $money($restoredPayable['net_payable_amount'] ?? 0) === 700.0
        && (string) ($restoredPayable['status'] ?? '') === 'open'
        && $voidedReceiptStatus === 'void'
        && (string) ($offsetVoid['status'] ?? '') === 'void');

    $journalPairs = [
        [(int) $offset['journal_entry_id'], (int) ($offsetVoid['reversal_journal_entry_id'] ?? 0)],
        [(int) $receipt['journal_entry_id'], (int) ($receiptVoid['reversal_journal_entry_id'] ?? 0)],
    ];
    foreach ($journalPairs as [$originalJournalId, $reversalJournalId]) {
        $nonZeroAccounts = $db->query(
            'SELECT account_id
             FROM journal_entry_lines
             WHERE journal_entry_id IN (' . $originalJournalId . ', ' . $reversalJournalId . ')
             GROUP BY account_id
             HAVING ABS(SUM(debit_amount - credit_amount)) > 0.005'
        )->fetchAll(PDO::FETCH_COLUMN);
        $check('Original and reversal journal net to zero by account for JE-' . $originalJournalId,
            $reversalJournalId > 0 && $nonZeroAccounts === []);
    }

    $afterVoidAccountReport = (new \App\Services\AccountLedgerService($app))->report([
        'currency' => 'PKR',
        'businessSourceId' => $businessSourceId,
    ], [$branchId]);
    $afterVoidSupplierReport = (new \App\Services\SupplierLedgerService($app))->report([
        'currency' => 'PKR',
        'supplierId' => $supplierId,
    ], [$branchId]);
    $activeLinkedRows = $linkedService->buildReport(
        (array) ($afterVoidAccountReport['rows'] ?? []),
        (array) ($afterVoidSupplierReport['rows'] ?? []),
        null,
        null,
        'PKR'
    )['rows'] ?? [];
    $check('Voided receipt and adjustment disappear from the active unified ledger',
        count(array_filter(
            $activeLinkedRows,
            static fn (array $row): bool => in_array(
                (string) ($row['ledger_entry'] ?? ''),
                ['Net payment received', 'Account balance applied to linked supplier', 'Linked account balance adjustment'],
                true
            )
        )) === 0);

    $check('Original sale and purchase journals remain balanced during the scenario',
        $saleJournalId > 0
        && $purchaseJournalId > 0
        && (int) $db->query(
            'SELECT COUNT(*)
             FROM (
                SELECT journal_entry_id
                FROM journal_entry_lines
                WHERE journal_entry_id IN (' . $saleJournalId . ', ' . $purchaseJournalId . ')
                GROUP BY journal_entry_id
                HAVING ABS(SUM(debit_amount) - SUM(credit_amount)) > 0.005
             ) unbalanced'
        )->fetchColumn() === 0);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

foreach ($protectedTables as $table) {
    $check(
        'Rollback leaves ' . $table . ' unchanged',
        (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $beforeCounts[$table]
    );
}

if ($failures !== []) {
    fwrite(STDERR, 'Acceptance scenario failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Linked-party end-to-end acceptance scenario passed and rolled back.' . PHP_EOL;
