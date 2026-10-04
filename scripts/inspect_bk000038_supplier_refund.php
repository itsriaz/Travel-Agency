<?php

declare(strict_types=1);

// Read-only comparison data for the guarded BK-000038 supplier repair.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--supplier-state')) {
    fwrite(STDERR, "Usage: php scripts/inspect_bk000038_supplier_refund.php [--supplier-state]\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$read = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$payload = static function (?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
};
$sourceFields = static function (array $value): array {
    return [
        'refund_currency' => $value['refund_currency'] ?? null,
        'refund_source_amount' => $value['refund_source_amount'] ?? null,
        'refund_booking_equivalent' => $value['refund_booking_equivalent'] ?? null,
    ];
};

$events = $read('SELECT id, booking_reference, booking_service_id, event_type, event_status,
        currency, customer_refund_amount, supplier_refund_amount, supplier_credit_amount,
        journal_entry_id, payload_json
    FROM booking_service_events
    WHERE (booking_reference = ? AND event_status = "posted") OR id = ? ORDER BY id',
    ['BK-000038', 119]);
$eventSummary = [];
foreach ($events as $event) {
    $eventPayload = $payload($event['payload_json']);
    $eventSummary[] = [
        'id' => (int) $event['id'],
        'booking_reference' => $event['booking_reference'],
        'booking_service_id' => (int) $event['booking_service_id'],
        'event_type' => $event['event_type'],
        'event_status' => $event['event_status'],
        'currency' => $event['currency'],
        'customer_refund_amount' => $event['customer_refund_amount'],
        'supplier_refund_amount' => $event['supplier_refund_amount'],
        'supplier_credit_amount' => $event['supplier_credit_amount'],
        'journal_entry_id' => $event['journal_entry_id'],
        'refund_scope' => $eventPayload['refund_scope'] ?? null,
        'supplier_refund_payment_method' => $eventPayload['supplier_refund_payment_method'] ?? null,
        'expected_supplier_refund_currency' => $eventPayload['expected_supplier_refund_currency'] ?? null,
        'expected_supplier_refund_source_amount' => $eventPayload['expected_supplier_refund_source_amount'] ?? null,
        'expected_supplier_refund_equivalent_amount' => $eventPayload['expected_supplier_refund_equivalent_amount'] ?? null,
        'supplier_penalty_source_amount' => $eventPayload['supplier_penalty_source_amount'] ?? null,
        'supplier_source_correction' => [
            'source_currency' => $eventPayload['supplier_source_correction']['source_currency'] ?? null,
            'source_amount' => $eventPayload['supplier_source_correction']['source_amount'] ?? null,
        ],
        'supplier_refund_detail' => $sourceFields((array) ($eventPayload['supplier_refund_detail'] ?? [])),
        'refund_detail' => $sourceFields((array) ($eventPayload['refund_detail'] ?? [])),
        'retained_credit_conversion' => [
            'confirmed_amount' => $eventPayload['retained_credit_conversion']['confirmed_amount'] ?? null,
            'converted_payment_credit_amount' => $eventPayload['retained_credit_conversion']['converted_payment_credit_amount'] ?? null,
        ],
    ];
}

$details = $read('SELECT service_event_id, refund_currency, refund_source_amount,
        refund_exchange_rate_to_booking FROM booking_service_refund_details WHERE service_event_id = ?', [119]);
$service = $read('SELECT id, supplier_id, cost_currency, purchase_cost
    FROM booking_services WHERE id = ?', [38]);
$obligation = $read('SELECT id, supplier_id, branch_id, booking_reference, currency,
        gross_amount, advance_applied_amount, net_payable_amount, status
    FROM supplier_obligations WHERE id = ?', [38]);
$payments = $read('SELECT id, supplier_id, branch_id, booking_reference, currency, paid_amount,
        allocated_amount, unallocated_amount, converted_advance_amount, status
    FROM supplier_payments WHERE id IN (?, ?) ORDER BY id', [8, 180]);
$paymentAllocations = $read('SELECT a.id, a.supplier_payment_id, p.currency AS payment_currency,
        a.supplier_obligation_id, a.allocated_amount
    FROM supplier_payment_allocations a
    JOIN supplier_payments p ON p.id = a.supplier_payment_id
    WHERE a.supplier_obligation_id = ? ORDER BY a.id', [38]);
$advances = $read('SELECT id, supplier_id, branch_id, currency, deposit_amount, available_amount,
        source_supplier_payment_id, reference_no
    FROM supplier_advances WHERE source_supplier_payment_id = ? ORDER BY id', [8]);
$advanceUse = $read('SELECT a.supplier_advance_id, COUNT(*) AS application_count,
        COALESCE(SUM(a.applied_amount), 0) AS applied_amount
    FROM supplier_advance_applications a
    JOIN supplier_advances sa ON sa.id = a.supplier_advance_id
    WHERE sa.source_supplier_payment_id = ? GROUP BY a.supplier_advance_id
    ORDER BY a.supplier_advance_id', [8]);
$cashJournals = $read('SELECT je.id, je.currency, je.source_reference,
        ROUND(SUM(jel.debit_amount), 2) AS total_debits,
        ROUND(SUM(jel.credit_amount), 2) AS total_credits
    FROM journal_entries je
    JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    WHERE je.source_reference IN (?, ?)
    GROUP BY je.id, je.currency, je.source_reference ORDER BY je.id',
    ['DATAFIX-BK000038-SPAY', 'VOID-FALSE-BK38-AED-SUPPLIER-CASH']);
$priorRepair = $read('SELECT id, currency FROM journal_entries WHERE source_reference = ?',
    ['BK38-PKR-SUPPLIER-REPAIR']);

$supplierState = [
    'supplier_service_38' => $service,
    'supplier_obligation_38' => $obligation,
    'supplier_payments_8_and_180' => $payments,
    'payment_allocations_for_obligation_38' => $paymentAllocations,
    'advances_from_supplier_payment_8' => $advances,
    'advance_use_totals_from_supplier_payment_8' => $advanceUse,
    'historical_aed_cash_journals' => $cashJournals,
    'prior_pkr_repair_journals' => $priorRepair,
];
$report = isset($argv[1])
    ? ['inspection_only' => true] + $supplierState
    : ['inspection_only' => true, 'events' => $eventSummary,
        'structured_refund_details_for_event_119' => $details] + $supplierState;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
