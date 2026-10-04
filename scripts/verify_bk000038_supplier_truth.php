<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$one = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$near = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) < .005;
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$obligation = $one('SELECT currency, gross_amount, net_payable_amount FROM supplier_obligations WHERE id = 38');
$check(($obligation['currency'] ?? '') === 'PKR' && $near($obligation['gross_amount'] ?? 0, 40700)
    && $near($obligation['net_payable_amount'] ?? -1, 0), 'Supplier obligation is not settled at PKR 40,700');
$allocation = $one('SELECT a.allocated_amount, p.currency, p.status, p.converted_advance_amount
    FROM supplier_payment_allocations a JOIN supplier_payments p ON p.id = a.supplier_payment_id
    WHERE a.supplier_obligation_id = 38');
$check(($allocation['currency'] ?? '') === 'PKR' && $near($allocation['allocated_amount'] ?? 0, 40700)
    && $near($allocation['converted_advance_amount'] ?? 0, 50242),
    'Original PKR supplier payment was not released into credit correctly');
$falsePayment = $one('SELECT status, reversal_journal_entry_id FROM supplier_payments WHERE id = 8');
$check(($falsePayment['status'] ?? '') === 'void' && (int) ($falsePayment['reversal_journal_entry_id'] ?? 0) > 0,
    'Incorrect AED supplier payment or cash reversal remains active');
$cashReversal = $one('SELECT je.currency, SUM(CASE WHEN coa.code LIKE "CASH_%" THEN jel.debit_amount ELSE 0 END) AS cash_debit,
        SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.credit_amount ELSE 0 END) AS advance_credit
    FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE je.id = ?', [(int) ($falsePayment['reversal_journal_entry_id'] ?? 0)]);
$check(($cashReversal['currency'] ?? '') === 'AED' && $near($cashReversal['cash_debit'] ?? 0, 1188)
    && $near($cashReversal['advance_credit'] ?? 0, 1188), 'False AED cash journal was not fully reversed');
$supplierRefundCount = $one('SELECT COUNT(*) AS total FROM booking_service_events
    WHERE booking_service_id = 38 AND booking_reference = "BK-000038"
      AND event_type = "refund" AND event_status = "posted"
      AND supplier_refund_amount > 0.005');
$supplierRefund = $one('SELECT id, currency, supplier_refund_amount, journal_entry_id, payload_json
    FROM booking_service_events
    WHERE booking_service_id = 38 AND booking_reference = "BK-000038"
      AND event_type = "refund" AND event_status = "posted"
      AND supplier_refund_amount > 0.005 ORDER BY id DESC LIMIT 1');
$refundPayload = json_decode((string) ($supplierRefund['payload_json'] ?? '{}'), true);
$refundEventId = (int) ($supplierRefund['id'] ?? 0);
$check((int) ($supplierRefundCount['total'] ?? 0) === 1
    && ($supplierRefund['currency'] ?? '') === 'AED'
    && $near($supplierRefund['supplier_refund_amount'] ?? 0, 653)
    && ($supplierRefund['journal_entry_id'] ?? null) === null
    && ($refundPayload['refund_scope'] ?? '') === 'supplier'
    && ($refundPayload['supplier_refund_payment_method'] ?? '') === 'supplier_credit',
    'Expected one noncash PKR-source supplier refund with AED customer reference');
$advanceReference = 'SUPPLIER-POOL-PKR-REFUND-' . $refundEventId;
$advance = $one('SELECT id, currency, deposit_amount, available_amount FROM supplier_advances
    WHERE reference_no = ?', [$advanceReference]);
$advanceUse = $one('SELECT COALESCE(SUM(COALESCE(advance_amount_consumed, applied_amount)), 0) AS consumed_amount,
        COALESCE(MIN(COALESCE(advance_amount_consumed, applied_amount)), 0) AS minimum_consumption
    FROM supplier_advance_applications WHERE supplier_advance_id = ?', [(int) ($advance['id'] ?? 0)]);
$available = (float) ($advance['available_amount'] ?? -1);
$consumed = (float) ($advanceUse['consumed_amount'] ?? -1);
$check(($advance['currency'] ?? '') === 'PKR' && $near($advance['deposit_amount'] ?? 0, 50242)
    && $available >= -0.005 && $available <= 50242.005
    && (float) ($advanceUse['minimum_consumption'] ?? -1) >= -0.005
    && $near($available + $consumed, 50242),
    'Supplier PKR advance pool does not reconcile with its recorded applications');
$falseAdvance = $one('SELECT currency, deposit_amount, available_amount
    FROM supplier_advances WHERE id = 2');
$check(($falseAdvance['currency'] ?? '') === 'AED'
    && $near($falseAdvance['deposit_amount'] ?? -1, 0)
    && $near($falseAdvance['available_amount'] ?? -1, 0),
    'Incorrect AED supplier advance was not neutralized');
$cancel = $one('SELECT payload_json FROM booking_service_events WHERE id = 1');
$cancelPayload = json_decode((string) ($cancel['payload_json'] ?? '{}'), true);
$check(($cancelPayload['supplier_penalty_currency'] ?? '') === 'PKR'
    && $near($cancelPayload['supplier_cost_basis'] ?? 0, 90942)
    && $near($cancelPayload['supplier_penalty_source_amount'] ?? 0, 40700)
    && $near($cancelPayload['expected_supplier_refund_source_amount'] ?? 0, 50242),
    'Cancellation source amounts are not PKR 90,942 / 40,700 / 50,242');
$detail = $one('SELECT refund_currency, refund_source_amount FROM booking_service_refund_details
    WHERE service_event_id = ?', [$refundEventId]);
$check(($detail['refund_currency'] ?? '') === 'PKR' && $near($detail['refund_source_amount'] ?? 0, 50242),
    'Supplier refund detail does not record PKR 50,242');
$customerRefund = $one('SELECT currency, customer_refund_amount FROM booking_service_events
    WHERE id = 47 AND booking_reference = "BK-000038" AND event_status = "posted"');
$check(($customerRefund['currency'] ?? '') === 'AED' && $near($customerRefund['customer_refund_amount'] ?? 0, 600),
    'Original AED 600 customer refund changed');

$ledger = (new \App\Services\SupplierLedgerService($app))->report(['bookingReference' => 'BK-000038'], [2]);
$rows = (array) ($ledger['rows'] ?? []);
$cards = array_column((array) ($ledger['summaryCards'] ?? []), 'value', 'label');
$check($rows !== [] && ! array_filter($rows, static fn (array $row): bool => ($row['currency'] ?? '') !== 'PKR')
    && ($cards['Supplier Balance / PKR'] ?? '') === 'PKR 50,242.00 Advance',
    'BK-000038 supplier ledger has an AED entry or wrong PKR balance');

$journals = $db->query('SELECT je.id, je.currency, ROUND(SUM(jel.debit_amount), 2) AS debits,
        ROUND(SUM(jel.credit_amount), 2) AS credits
    FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    WHERE je.source_reference IN (
        "BK38-PKR-SUPPLIER-REPAIR",
        "VOID-FALSE-BK38-AED-SUPPLIER-CASH")
    GROUP BY je.id, je.currency')->fetchAll(PDO::FETCH_ASSOC);
$check(count($journals) === 2, 'Expected PKR release and false cash reversal journals are incomplete');
foreach ($journals as $journal) {
    $check($near($journal['debits'], (float) $journal['credits']),
        'Unbalanced repair journal #' . $journal['id']);
}

if ($failures !== []) {
    fwrite(STDERR, '[FAIL] ' . implode(PHP_EOL . '[FAIL] ', $failures) . PHP_EOL);
    exit(1);
}
echo '[PASS] BK-000038 supplier source, advance, cash reversal, customer refund, and ledger reconcile.' . PHP_EOL;
