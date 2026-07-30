<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Core\App;

$options = getopt('', ['apply', 'test-rollback', 'actor-user-id:', 'help']);
if (isset($options['help'])) {
    echo 'Usage: php scripts/repair_production_financial_data_20260720.php [--apply|--test-rollback] --actor-user-id=ID' . PHP_EOL;
    echo 'Default mode is a read-only dry run. --test-rollback executes and verifies every change, then rolls back.' . PHP_EOL;
    exit(0);
}

$apply = array_key_exists('apply', $options);
$testRollback = array_key_exists('test-rollback', $options);
if ($apply && $testRollback) {
    fwrite(STDERR, 'Choose either --apply or --test-rollback, not both.' . PHP_EOL);
    exit(1);
}

$actorUserId = isset($options['actor-user-id']) ? (int) $options['actor-user-id'] : 0;
if (($apply || $testRollback) && $actorUserId <= 0) {
    fwrite(STDERR, '--actor-user-id is required when executing repairs.' . PHP_EOL);
    exit(1);
}

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$accounting = new AccountingRepository($app);
$mode = $apply ? 'APPLY' : ($testRollback ? 'TEST-ROLLBACK' : 'DRY-RUN');
$epsilon = 0.005;
$repairDate = '2026-07-20';

$currencyRepairBookings = [
    'BK-000298', 'BK-000307', 'BK-000316', 'BK-000322',
    'BK-000342', 'BK-000349', 'BK-000350', 'BK-000354',
    'BK-000362', 'BK-000378', 'BK-000381', 'BK-000382',
    'BK-000395', 'BK-000403', 'BK-000441', 'BK-000450',
];

$fetchOne = static function (string $sql, array $parameters = []) use ($db): ?array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
};

$fetchAll = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$execute = static function (string $sql, array $parameters = []) use ($db): int {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->rowCount();
};

$money = static fn (mixed $value): float => round((float) $value, 2);
$sameMoney = static fn (mixed $left, mixed $right): bool => abs(round((float) $left, 2) - round((float) $right, 2)) <= 0.005;

$journalMarkerExists = static function (string $sourceReference) use ($fetchOne): bool {
    return $fetchOne(
        'SELECT id FROM journal_entries WHERE source_reference = :source_reference LIMIT 1',
        ['source_reference' => $sourceReference]
    ) !== null;
};

$accountNet = static function (
    string $bookingReference,
    string $lineReference,
    string $currency,
    string $accountCode
) use ($db): float {
    $statement = $db->prepare(
        'SELECT COALESCE(ROUND(SUM(jel.debit_amount - jel.credit_amount), 2), 0)
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.booking_reference = :booking_reference
           AND jel.service_line_reference = :line_reference
           AND je.currency = :currency
           AND coa.code = :account_code'
    );
    $statement->execute([
        'booking_reference' => $bookingReference,
        'line_reference' => $lineReference,
        'currency' => $currency,
        'account_code' => $accountCode,
    ]);
    return round((float) $statement->fetchColumn(), 2);
};

$findExactJournal = static function (
    string $bookingReference,
    string $sourceType,
    string $sourceReference,
    string $currency,
    string $narration,
    float $amount
) use ($fetchAll, $sameMoney): int {
    $rows = $fetchAll(
        'SELECT je.id, SUM(jel.debit_amount) AS debit_total, SUM(jel.credit_amount) AS credit_total
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.booking_reference = :booking_reference
           AND je.source_type = :source_type
           AND je.source_reference = :source_reference
           AND je.currency = :currency
           AND je.narration = :narration
         GROUP BY je.id',
        [
            'booking_reference' => $bookingReference,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'currency' => $currency,
            'narration' => $narration,
        ]
    );
    $matches = array_values(array_filter($rows, static fn (array $row): bool =>
        $sameMoney($row['debit_total'] ?? 0, $amount) && $sameMoney($row['credit_total'] ?? 0, $amount)
    ));
    if (count($matches) !== 1) {
        throw new RuntimeException(sprintf(
            'Expected exactly one journal for %s / %s / %s; found %d.',
            $bookingReference,
            $sourceType,
            $sourceReference,
            count($matches)
        ));
    }
    return (int) $matches[0]['id'];
};

$assertActor = static function () use ($actorUserId, $fetchOne, $apply, $testRollback): void {
    if (! $apply && ! $testRollback) {
        return;
    }
    $actor = $fetchOne('SELECT id FROM users WHERE id = :id AND is_active = 1', ['id' => $actorUserId]);
    if ($actor === null) {
        throw new RuntimeException('The supplied actor user does not exist or is inactive.');
    }
};

$bk38Desired = static function () use ($fetchOne, $db, $sameMoney): bool {
    $receivable = $fetchOne(
        'SELECT due_amount, allocated_amount, outstanding_amount, status
         FROM customer_receivable_items
         WHERE booking_reference = "BK-000038" AND service_line_reference = "SV-001"'
    );
    $receipt = $fetchOne(
        'SELECT id, received_amount, allocated_amount, unallocated_amount, returned_amount, status
         FROM customer_receipts WHERE booking_reference = "BK-000038" AND receipt_no = "RCPT-000038"'
    );
    $obligation = $fetchOne(
        'SELECT id, gross_amount, net_payable_amount, status
         FROM supplier_obligations
         WHERE booking_reference = "BK-000038" AND service_line_reference = "SV-001"'
    );
    $payment = $fetchOne(
        'SELECT id, paid_amount, allocated_amount, unallocated_amount, converted_advance_amount, status
         FROM supplier_payments WHERE booking_reference = "BK-000038" AND payment_no = "SPAY-000007"'
    );
    if ($receivable === null || $receipt === null || $obligation === null || $payment === null) {
        return false;
    }
    $allocationStatement = $db->prepare(
        'SELECT COALESCE(SUM(receivable_amount_allocated), 0)
         FROM customer_receipt_allocations WHERE customer_receipt_id = :receipt_id'
    );
    $allocationStatement->execute(['receipt_id' => (int) $receipt['id']]);
    $customerAllocationTotal = round((float) $allocationStatement->fetchColumn(), 2);
    $supplierAllocationStatement = $db->prepare(
        'SELECT COALESCE(SUM(allocated_amount), 0)
         FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
    );
    $supplierAllocationStatement->execute(['payment_id' => (int) $payment['id']]);
    $supplierAllocationTotal = round((float) $supplierAllocationStatement->fetchColumn(), 2);

    return $sameMoney($receivable['due_amount'], 580)
        && $sameMoney($receivable['allocated_amount'], 580)
        && $sameMoney($receivable['outstanding_amount'], 0)
        && (string) $receivable['status'] === 'paid'
        && $sameMoney($receipt['received_amount'], 1180)
        && $sameMoney($receipt['allocated_amount'], 580)
        && $sameMoney($receipt['unallocated_amount'], 0)
        && $sameMoney($receipt['returned_amount'], 600)
        && (string) $receipt['status'] === 'fully_allocated'
        && $sameMoney($customerAllocationTotal, 580)
        && $sameMoney($obligation['gross_amount'], 535)
        && $sameMoney($obligation['net_payable_amount'], 0)
        && (string) $obligation['status'] === 'paid'
        && $sameMoney($payment['paid_amount'], 1188)
        && $sameMoney($payment['allocated_amount'], 535)
        && $sameMoney($payment['unallocated_amount'], 0)
        && $sameMoney($payment['converted_advance_amount'], 653)
        && (string) $payment['status'] === 'fully_allocated'
        && $sameMoney($supplierAllocationTotal, 535);
};

$repairBk38 = static function (bool $executeChanges) use (
    $fetchOne,
    $fetchAll,
    $execute,
    $findExactJournal,
    $journalMarkerExists,
    $bk38Desired,
    $accounting,
    $actorUserId,
    $repairDate,
    $sameMoney,
    $db,
    $app
): array {
    if ($bk38Desired()) {
        return ['booking' => 'BK-000038', 'status' => 'already_correct'];
    }

    $service = $fetchOne(
        'SELECT bs.id, bs.branch_id, bs.supplier_id, bs.line_reference, bs.service_status, bs.currency,
                bs.final_sale_price, bs.purchase_cost
         FROM booking_services bs
         INNER JOIN bookings b ON b.id = bs.booking_id
         WHERE b.booking_reference = "BK-000038" AND bs.line_reference = "SV-001"'
    );
    $receivable = $fetchOne(
        'SELECT * FROM customer_receivable_items
         WHERE booking_reference = "BK-000038" AND service_line_reference = "SV-001"'
    );
    $receipt = $fetchOne(
        'SELECT * FROM customer_receipts
         WHERE booking_reference = "BK-000038" AND receipt_no = "RCPT-000038"'
    );
    $obligation = $fetchOne(
        'SELECT * FROM supplier_obligations
         WHERE booking_reference = "BK-000038" AND service_line_reference = "SV-001"'
    );
    if ($service === null || $receivable === null || $receipt === null || $obligation === null) {
        throw new RuntimeException('BK-000038 core service, receivable, receipt, or obligation is missing.');
    }
    if ((string) $service['service_status'] !== 'Cancelled'
        || (string) $service['currency'] !== 'AED'
        || ! $sameMoney($service['final_sale_price'], 1188)
        || ! $sameMoney($receipt['received_amount'], 1180)
        || ! $sameMoney($receipt['returned_amount'], 600)) {
        throw new RuntimeException('BK-000038 does not match the client-approved AED 1,188 / 1,180 / 600 scenario.');
    }
    if (! $sameMoney($receipt['allocated_amount'], 53)
        || ! $sameMoney($receipt['unallocated_amount'], 527)
        || ! $sameMoney($receivable['due_amount'], 1188)
        || ! $sameMoney($receivable['outstanding_amount'], 0)) {
        throw new RuntimeException('BK-000038 is neither in the known production state nor the approved repaired state.');
    }
    $allocationTotalRow = $fetchOne(
        'SELECT COALESCE(SUM(receivable_amount_allocated), 0) AS total
         FROM customer_receipt_allocations WHERE customer_receipt_id = :receipt_id',
        ['receipt_id' => (int) $receipt['id']]
    );
    if (! $sameMoney($allocationTotalRow['total'] ?? 0, 53)) {
        throw new RuntimeException('BK-000038 customer allocation rows are not in the expected AED 53 pre-repair state.');
    }

    $targets = [
        ['service_receivable_adjusted', 'SV-001', 'Service receivable adjusted from service update', 1135.00, 'DATAFIX-BK000038-ERR-AR'],
        ['supplier_payable_adjusted', 'SV-001', 'Supplier payable adjusted from service update', 653.00, 'DATAFIX-BK000038-ERR-AP'],
        ['customer_receipt_recorded', 'RCPT-000150', 'Customer receipt recorded at booking level', 1135.00, 'DATAFIX-BK000038-ERR-RCPT'],
        ['customer_receipt_allocated', 'RCPT-000150-ALLOC-103', 'Customer receipt allocated to receivable item', 1135.00, 'DATAFIX-BK000038-ERR-ALLOC'],
    ];
    foreach ($targets as &$target) {
        $target[] = $findExactJournal('BK-000038', $target[0], $target[1], 'AED', $target[2], $target[3]);
        if ($journalMarkerExists($target[4])) {
            throw new RuntimeException('BK-000038 contains a partial prior repair marker: ' . $target[4]);
        }
    }
    unset($target);

    if (! $executeChanges) {
        return [
            'booking' => 'BK-000038',
            'status' => 'repair_required',
            'customer' => 'retain AED 580 (53 customer penalty + 527 supplier penalty recovery), refund AED 600',
            'supplier' => 'record AED 1,188 paid, AED 535 retained, AED 653 refunded',
            'journals_to_reverse' => array_column($targets, 5),
        ];
    }

    foreach ($targets as $target) {
        $accounting->reverseJournalEntry((int) $target[5], [
            'source_type' => 'data_integrity_reversal',
            'source_reference' => $target[4],
            'entry_date' => $repairDate,
            'narration' => 'Production data repair reversal for BK-000038 journal ' . $target[5],
            'actor_user_id' => $actorUserId,
        ]);
    }

    $execute(
        'UPDATE customer_receivable_items
         SET due_amount = 580.00, allocated_amount = 580.00, outstanding_amount = 0.00,
             status = "paid",
             remarks = "Cancelled service settlement fully covered: customer penalty AED 53 plus supplier penalty recovery AED 527; no outstanding receivable."
         WHERE id = :id',
        ['id' => (int) $receivable['id']]
    );
    $execute(
        'UPDATE customer_receipts
         SET allocated_amount = 580.00, unallocated_amount = 0.00, returned_amount = 600.00, status = "fully_allocated"
         WHERE id = :id',
        ['id' => (int) $receipt['id']]
    );
    $execute(
        'INSERT INTO customer_receipt_allocations (
            customer_receipt_id, customer_receivable_item_id, allocated_amount,
            receivable_currency, receivable_amount_allocated, payment_currency, payment_amount_consumed,
            allocation_note, exchange_rate_used, rate_from_currency, rate_to_currency, exchange_rate,
            exchange_rate_effective_date, allocated_at, created_by_user_id
         ) VALUES (
            :receipt_id, :receivable_id, 527.00,
            "AED", 527.00, "AED", 527.00,
            "Cancellation settlement allocation: supplier penalty recovery retained from original customer payment.",
            1.00000000, "AED", "AED", 1.00000000,
            "2026-06-26", "2026-06-26 19:12:41", :actor_user_id
         )',
        [
            'receipt_id' => (int) $receipt['id'],
            'receivable_id' => (int) $receivable['id'],
            'actor_user_id' => $actorUserId,
        ]
    );
    $customerAllocationId = (int) $db->lastInsertId();

    $accounting->postReceivableAdjusted([
        'branch_id' => (int) $service['branch_id'],
        'booking_reference' => 'BK-000038',
        'source_reference' => 'DATAFIX-BK000038-CANCEL-CHARGE',
        'service_line_reference' => 'SV-001',
        'customer_receivable_item_id' => (int) $receivable['id'],
        'adjustment_amount' => 527.00,
        'entry_date' => '2026-06-26',
        'currency' => 'AED',
        'narration' => 'Recognize supplier penalty recovery retained from customer on cancellation',
        'actor_user_id' => $actorUserId,
    ]);
    $accounting->postCustomerReceiptAllocation([
        'branch_id' => (int) $service['branch_id'],
        'booking_reference' => 'BK-000038',
        'source_reference' => 'DATAFIX-BK000038-CANCEL-ALLOC-' . $customerAllocationId,
        'service_line_reference' => 'SV-001',
        'customer_receivable_item_id' => (int) $receivable['id'],
        'customer_receipt_id' => (int) $receipt['id'],
        'allocated_amount' => 527.00,
        'entry_date' => '2026-06-26',
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Allocate supplier penalty recovery from original customer payment',
    ]);

    $payment = $fetchOne('SELECT * FROM supplier_payments WHERE payment_no = "SPAY-000007"');
    if ($payment !== null) {
        throw new RuntimeException('SPAY-000007 already exists while BK-000038 is not in the complete repaired state.');
    }
    $treasury = $fetchOne(
        'SELECT id FROM treasury_accounts
         WHERE branch_id = :branch_id AND currency = "AED" AND account_type = "cash" AND is_active = 1
         ORDER BY id ASC LIMIT 1',
        ['branch_id' => (int) $service['branch_id']]
    );
    if ($treasury === null) {
        throw new RuntimeException('No active AED cash treasury account exists for BK-000038 branch.');
    }
    $execute(
        'INSERT INTO supplier_payments (
            supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
            paid_amount, allocated_amount, unallocated_amount, payment_method, treasury_account_id,
            charges_amount, status, exchange_rate_to_booking, remarks, payment_scope,
            converted_advance_amount, converted_advance_id, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, "BK-000038", "SPAY-000007", "2026-06-26", "AED",
            1188.00, 535.00, 0.00, "cash", :treasury_account_id,
            0.00, "fully_allocated", 1.00000000,
            "Historical supplier payment reconstructed from client-approved evidence: AED 535 retained and AED 653 refunded.",
            "booking", 653.00, NULL, :actor_user_id
         )',
        [
            'supplier_id' => (int) $service['supplier_id'],
            'branch_id' => (int) $service['branch_id'],
            'treasury_account_id' => (int) $treasury['id'],
            'actor_user_id' => $actorUserId,
        ]
    );
    $supplierPaymentId = (int) $db->lastInsertId();
    $execute(
        'UPDATE supplier_obligations
         SET gross_amount = 535.00, advance_applied_amount = 0.00, net_payable_amount = 0.00,
             status = "paid",
             remarks = "Cancelled service supplier charge AED 535 fully settled from original AED 1,188 payment; AED 653 refunded."
         WHERE id = :id',
        ['id' => (int) $obligation['id']]
    );
    $execute(
        'INSERT INTO supplier_payment_allocations (
            supplier_payment_id, supplier_obligation_id, allocated_amount, allocation_note,
            exchange_rate_used, allocated_at, created_by_user_id
         ) VALUES (
            :payment_id, :obligation_id, 535.00,
            "Supplier cancellation charge settled from original AED 1,188 payment.",
            1.00000000, "2026-06-26 19:12:41", :actor_user_id
         )',
        [
            'payment_id' => $supplierPaymentId,
            'obligation_id' => (int) $obligation['id'],
            'actor_user_id' => $actorUserId,
        ]
    );
    $supplierAllocationId = (int) $db->lastInsertId();
    $execute(
        'INSERT INTO supplier_advances (
            supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no,
            remarks, received_at, created_by_user_id, source_supplier_payment_id
         ) VALUES (
            :supplier_id, :branch_id, "AED", 653.00, 0.00, "SPAY-000007-REFUNDED",
            "AED 653 excess from original supplier payment was fully returned by supplier; no advance remains.",
            "2026-06-26", :actor_user_id, :payment_id
         )',
        [
            'supplier_id' => (int) $service['supplier_id'],
            'branch_id' => (int) $service['branch_id'],
            'actor_user_id' => $actorUserId,
            'payment_id' => $supplierPaymentId,
        ]
    );
    $supplierAdvanceId = (int) $db->lastInsertId();
    $execute(
        'UPDATE supplier_payments SET converted_advance_id = :advance_id WHERE id = :payment_id',
        ['advance_id' => $supplierAdvanceId, 'payment_id' => $supplierPaymentId]
    );

    $accounting->postSupplierPaymentRecorded([
        'branch_id' => (int) $service['branch_id'],
        'booking_reference' => 'BK-000038',
        'payment_no' => 'DATAFIX-BK000038-SPAY',
        'supplier_payment_id' => $supplierPaymentId,
        'paid_amount' => 1188.00,
        'charges_amount' => 0.00,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $treasury['id'],
        'entry_date' => '2026-06-26',
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Historical supplier payment reconstructed from cancellation and refund evidence',
    ]);
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => (int) $service['branch_id'],
        'booking_reference' => 'BK-000038',
        'source_reference' => 'DATAFIX-BK000038-SPAY-ALLOC-' . $supplierAllocationId,
        'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => (int) $obligation['id'],
        'supplier_payment_id' => $supplierPaymentId,
        'allocated_amount' => 535.00,
        'entry_date' => '2026-06-26',
        'currency' => 'AED',
        'actor_user_id' => $actorUserId,
        'narration' => 'Allocate supplier cancellation charge from original payment',
    ]);

    AuditLog::record($app, 'data_repair.bk_000038_completed', [
        'user_id' => $actorUserId,
        'booking_reference' => 'BK-000038',
        'customer_received' => 1180.00,
        'customer_penalty' => 53.00,
        'supplier_penalty_recovered' => 527.00,
        'customer_refund' => 600.00,
        'supplier_paid' => 1188.00,
        'supplier_penalty' => 535.00,
        'supplier_refund' => 653.00,
        'result' => 45.00,
    ]);

    if (! $bk38Desired()) {
        throw new RuntimeException('BK-000038 failed post-repair verification.');
    }
    return ['booking' => 'BK-000038', 'status' => 'repaired_and_verified'];
};

$loadCurrencyCases = static function () use ($fetchAll, $currencyRepairBookings): array {
    $placeholders = implode(',', array_fill(0, count($currencyRepairBookings), '?'));
    return $fetchAll(
        'SELECT sfc.*, bs.id AS service_id, bs.branch_id, bs.currency AS current_invoice_currency,
                bs.cost_currency AS current_cost_currency, bs.final_sale_price AS current_final_sale_price,
                bs.purchase_cost AS current_purchase_cost, bs.service_status, bs.is_active,
                cri.id AS receivable_id, cri.outstanding_amount,
                so.id AS obligation_id, so.net_payable_amount
         FROM service_financial_corrections sfc
         INNER JOIN booking_services bs ON bs.id = sfc.booking_service_id
         LEFT JOIN customer_receivable_items cri
           ON cri.booking_reference = sfc.booking_reference
          AND cri.service_line_reference = sfc.service_line_reference
          AND cri.due_group = "service_sale"
         LEFT JOIN supplier_obligations so
           ON so.booking_reference = sfc.booking_reference
          AND so.service_line_reference = sfc.service_line_reference
          AND so.obligation_group = "service_cost"
         WHERE sfc.booking_reference IN (' . $placeholders . ')
           AND (sfc.prior_invoice_currency <> sfc.new_invoice_currency
             OR sfc.prior_cost_currency <> sfc.new_cost_currency)
         ORDER BY sfc.booking_reference, sfc.id',
        $currencyRepairBookings
    );
};

$repairCurrencyCases = static function (bool $executeChanges) use (
    $loadCurrencyCases,
    $currencyRepairBookings,
    $fetchOne,
    $accountNet,
    $sameMoney,
    $accounting,
    $actorUserId,
    $app
): array {
    $rows = $loadCurrencyCases();
    $byBooking = [];
    foreach ($rows as $row) {
        $byBooking[(string) $row['booking_reference']][] = $row;
    }
    $results = [];
    foreach ($currencyRepairBookings as $bookingReference) {
        $transitions = $byBooking[$bookingReference] ?? [];
        if ($transitions === []) {
            throw new RuntimeException($bookingReference . ' has no recorded currency transition.');
        }
        $invoiceTransitions = array_values(array_filter($transitions, static fn (array $row): bool =>
            $row['prior_invoice_currency'] !== $row['new_invoice_currency']
        ));
        $costTransitions = array_values(array_filter($transitions, static fn (array $row): bool =>
            $row['prior_cost_currency'] !== $row['new_cost_currency']
        ));
        if (count($invoiceTransitions) !== 1 || count($costTransitions) !== 1) {
            throw new RuntimeException($bookingReference . ' must have exactly one invoice and one supplier currency transition.');
        }
        $invoice = $invoiceTransitions[0];
        $cost = $costTransitions[0];
        if ((string) $invoice['current_invoice_currency'] !== (string) $invoice['new_invoice_currency']
            || (string) $cost['current_cost_currency'] !== (string) $cost['new_cost_currency']
            || $invoice['receivable_id'] === null
            || $cost['obligation_id'] === null) {
            throw new RuntimeException($bookingReference . ' service/subledger currency state does not match its correction audit.');
        }
        $event = $fetchOne(
            'SELECT id FROM booking_service_events
             WHERE booking_service_id = :service_id AND event_status = "posted" LIMIT 1',
            ['service_id' => (int) $invoice['service_id']]
        );
        if ($event !== null) {
            throw new RuntimeException($bookingReference . ' now has a posted lifecycle event; automatic historical currency repair is blocked.');
        }

        $line = (string) $invoice['service_line_reference'];
        $oldInvoiceCurrency = (string) $invoice['prior_invoice_currency'];
        $newInvoiceCurrency = (string) $invoice['new_invoice_currency'];
        $oldInvoiceAmount = round((float) $invoice['prior_final_sale_price'], 2);
        $newInvoiceAmount = round((float) $invoice['new_final_sale_price'], 2);
        $oldCostCurrency = (string) $cost['prior_cost_currency'];
        $newCostCurrency = (string) $cost['new_cost_currency'];
        $oldCostAmount = round((float) $cost['prior_purchase_cost'], 2);
        $newCostAmount = round((float) $cost['new_purchase_cost'], 2);

        $desired = $sameMoney($accountNet($bookingReference, $line, $oldInvoiceCurrency, 'AR_CONTROL'), 0)
            && $sameMoney($accountNet($bookingReference, $line, $oldInvoiceCurrency, 'SERVICE_REVENUE'), 0)
            && $sameMoney($accountNet($bookingReference, $line, $oldCostCurrency, 'SERVICE_COST'), 0)
            && $sameMoney($accountNet($bookingReference, $line, $oldCostCurrency, 'AP_CONTROL'), 0)
            && $sameMoney($accountNet($bookingReference, $line, $newInvoiceCurrency, 'AR_CONTROL'), $invoice['outstanding_amount'])
            && $sameMoney($accountNet($bookingReference, $line, $newInvoiceCurrency, 'SERVICE_REVENUE'), -(float) $invoice['current_final_sale_price'])
            && $sameMoney($accountNet($bookingReference, $line, $newCostCurrency, 'SERVICE_COST'), $cost['current_purchase_cost'])
            && $sameMoney($accountNet($bookingReference, $line, $newCostCurrency, 'AP_CONTROL'), -(float) $cost['net_payable_amount']);
        if ($desired) {
            $results[] = ['booking' => $bookingReference, 'status' => 'already_correct'];
            continue;
        }

        $knownBroken = $sameMoney($accountNet($bookingReference, $line, $oldInvoiceCurrency, 'AR_CONTROL'), $oldInvoiceAmount)
            && $sameMoney($accountNet($bookingReference, $line, $oldInvoiceCurrency, 'SERVICE_REVENUE'), -$oldInvoiceAmount)
            && $sameMoney($accountNet($bookingReference, $line, $oldCostCurrency, 'SERVICE_COST'), $oldCostAmount)
            && $sameMoney($accountNet($bookingReference, $line, $oldCostCurrency, 'AP_CONTROL'), -$oldCostAmount)
            && $sameMoney($accountNet($bookingReference, $line, $newInvoiceCurrency, 'AR_CONTROL') + $newInvoiceAmount, $invoice['outstanding_amount'])
            && $sameMoney($accountNet($bookingReference, $line, $newInvoiceCurrency, 'SERVICE_REVENUE') - $newInvoiceAmount, -(float) $invoice['current_final_sale_price'])
            && $sameMoney($accountNet($bookingReference, $line, $newCostCurrency, 'SERVICE_COST') + $newCostAmount, $cost['current_purchase_cost'])
            && $sameMoney($accountNet($bookingReference, $line, $newCostCurrency, 'AP_CONTROL') - $newCostAmount, -(float) $cost['net_payable_amount']);
        if (! $knownBroken) {
            throw new RuntimeException($bookingReference . ' accounting balances do not match either the known broken state or the desired state.');
        }

        if ($executeChanges) {
            $common = [
                'branch_id' => (int) $invoice['branch_id'],
                'booking_reference' => $bookingReference,
                'service_line_reference' => $line,
                'entry_date' => (string) $invoice['correction_date'],
                'actor_user_id' => $actorUserId,
            ];
            $accounting->postReceivableAdjusted(array_merge($common, [
                'source_reference' => 'DATAFIX-CUR-' . str_replace('-', '', $bookingReference) . '-AR-OLD',
                'customer_receivable_item_id' => (int) $invoice['receivable_id'],
                'adjustment_amount' => -$oldInvoiceAmount,
                'currency' => $oldInvoiceCurrency,
                'narration' => 'Historical original-currency receivable reversal',
            ]));
            $accounting->postReceivableAdjusted(array_merge($common, [
                'source_reference' => 'DATAFIX-CUR-' . str_replace('-', '', $bookingReference) . '-AR-NEW',
                'customer_receivable_item_id' => (int) $invoice['receivable_id'],
                'adjustment_amount' => $newInvoiceAmount,
                'currency' => $newInvoiceCurrency,
                'narration' => 'Historical corrected-currency receivable recognition',
            ]));
            $costCommon = $common;
            $costCommon['entry_date'] = (string) $cost['correction_date'];
            $accounting->postPayableAdjusted(array_merge($costCommon, [
                'source_reference' => 'DATAFIX-CUR-' . str_replace('-', '', $bookingReference) . '-AP-OLD',
                'supplier_obligation_id' => (int) $cost['obligation_id'],
                'adjustment_amount' => -$oldCostAmount,
                'currency' => $oldCostCurrency,
                'narration' => 'Historical original-currency payable reversal',
            ]));
            $accounting->postPayableAdjusted(array_merge($costCommon, [
                'source_reference' => 'DATAFIX-CUR-' . str_replace('-', '', $bookingReference) . '-AP-NEW',
                'supplier_obligation_id' => (int) $cost['obligation_id'],
                'adjustment_amount' => $newCostAmount,
                'currency' => $newCostCurrency,
                'narration' => 'Historical corrected-currency payable recognition',
            ]));
            AuditLog::record($app, 'data_repair.service_currency_journals_completed', [
                'user_id' => $actorUserId,
                'booking_reference' => $bookingReference,
                'service_line_reference' => $line,
                'old_invoice_currency' => $oldInvoiceCurrency,
                'new_invoice_currency' => $newInvoiceCurrency,
                'old_cost_currency' => $oldCostCurrency,
                'new_cost_currency' => $newCostCurrency,
                'invoice_transition_amount' => $newInvoiceAmount,
                'cost_transition_amount' => $newCostAmount,
            ]);

            $postDesired = $sameMoney($accountNet($bookingReference, $line, $oldInvoiceCurrency, 'AR_CONTROL'), 0)
                && $sameMoney($accountNet($bookingReference, $line, $oldInvoiceCurrency, 'SERVICE_REVENUE'), 0)
                && $sameMoney($accountNet($bookingReference, $line, $oldCostCurrency, 'SERVICE_COST'), 0)
                && $sameMoney($accountNet($bookingReference, $line, $oldCostCurrency, 'AP_CONTROL'), 0)
                && $sameMoney($accountNet($bookingReference, $line, $newInvoiceCurrency, 'AR_CONTROL'), $invoice['outstanding_amount'])
                && $sameMoney($accountNet($bookingReference, $line, $newInvoiceCurrency, 'SERVICE_REVENUE'), -(float) $invoice['current_final_sale_price'])
                && $sameMoney($accountNet($bookingReference, $line, $newCostCurrency, 'SERVICE_COST'), $cost['current_purchase_cost'])
                && $sameMoney($accountNet($bookingReference, $line, $newCostCurrency, 'AP_CONTROL'), -(float) $cost['net_payable_amount']);
            if (! $postDesired) {
                throw new RuntimeException($bookingReference . ' failed post-repair currency-control verification.');
            }
        }
        $results[] = [
            'booking' => $bookingReference,
            'status' => $executeChanges ? 'repaired_and_verified' : 'repair_required',
            'invoice' => $oldInvoiceCurrency . ' ' . number_format($oldInvoiceAmount, 2) . ' -> ' . $newInvoiceCurrency . ' ' . number_format($newInvoiceAmount, 2),
            'supplier' => $oldCostCurrency . ' ' . number_format($oldCostAmount, 2) . ' -> ' . $newCostCurrency . ' ' . number_format($newCostAmount, 2),
        ];
    }
    return $results;
};

echo 'Production financial data repair 2026-07-20' . PHP_EOL;
echo 'Mode: ' . $mode . PHP_EOL;
echo 'Database: ' . (string) $db->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL . PHP_EOL;

try {
    $assertActor();
    if ($apply || $testRollback) {
        $db->beginTransaction();
    }

    $results = [];
    $results[] = $repairBk38($apply || $testRollback);
    array_push($results, ...$repairCurrencyCases($apply || $testRollback));

    foreach ($results as $result) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    if ($testRollback) {
        $db->rollBack();
        echo PHP_EOL . 'All repairs executed and verified successfully; transaction rolled back as requested.' . PHP_EOL;
    } elseif ($apply) {
        $db->commit();
        echo PHP_EOL . 'All repairs committed successfully.' . PHP_EOL;
    } else {
        echo PHP_EOL . 'Dry run complete. No database changes were made.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, PHP_EOL . 'REPAIR ABORTED: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'No transaction changes were committed.' . PHP_EOL);
    exit(1);
}
