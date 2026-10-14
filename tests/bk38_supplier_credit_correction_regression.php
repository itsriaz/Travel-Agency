<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$service = new \App\Services\ServiceWorkspaceService($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$customers = new \App\Repositories\CustomerPaymentRepository($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$booking = $db->query('SELECT id, booking_reference FROM bookings WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
$line = $db->query('SELECT id, line_reference FROM booking_services WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
if ($booking === false || $line === false) {
    echo '[SKIP] BK-000038 is absent from this local database.' . PHP_EOL;
    exit(0);
}
$reference = (string) $booking['booking_reference'];
$lineReference = (string) $line['line_reference'];
$cancel = $events->latestPostedEvent(38, 'cancel');
$beforePayload = json_decode((string) ($cancel['payload_json'] ?? ''), true);
if (! is_array($beforePayload)
    || (float) ($beforePayload['expected_supplier_refund_source_amount'] ?? 0) !== 50242.0
    || (float) ($beforePayload['expected_supplier_refund_booking_amount'] ?? 0) !== 653.0) {
    echo '[SKIP] BK-000038 no longer has the original PKR 50,242 / AED 653 credit.' . PHP_EOL;
    exit(0);
}

$receivableBefore = $customers->findReceivableByServiceLine($reference, $lineReference);
$obligationBefore = $suppliers->findObligationByServiceLine($reference, $lineReference);
$customerRefundBefore = 0;
$supplierRefundBefore = 0;
foreach ($events->postedEventsForService(38) as $event) {
    if ((string) ($event['event_type'] ?? '') !== 'refund') {
        continue;
    }
    $customerRefundBefore += (float) ($event['customer_refund_amount'] ?? 0);
    $supplierRefundBefore += (float) ($event['supplier_refund_amount'] ?? 0);
}
$assert($customerRefundBefore === 600.0 && $supplierRefundBefore === 653.0,
    'Fixture has the posted customer refund and supplier credit');
$treasuryBefore = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
$journalBefore = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
$oldAdvance = $suppliers->findAdvanceById(49);
$oldAdvanceAvailable = (float) ($oldAdvance['available_amount'] ?? -1);

$db->beginTransaction();
try {
    // Hypothetical confirmed values exercise the edit without persisting them.
    $correction = [
        'booking_id' => 38, 'service_id' => 38,
        'correction_reason' => 'Rollback-only supplier credit regression',
        'correction_event_date' => date('Y-m-d'),
        'customer_penalty_amount' => 53, 'customer_penalty_currency' => 'AED',
        'agency_fee_refund_amount' => 0, 'agency_fee_refund_currency' => 'AED',
        'expected_supplier_refund_amount' => 54942, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 714,
        'expected_supplier_refund_rate_date' => date('Y-m-d'),
        'supplier_penalty_amount' => 36000, 'supplier_penalty_currency' => 'PKR',
        'customer_refund_amount' => 600, 'customer_refund_source_amount' => 600,
        'customer_refund_currency' => 'AED', 'refund_payment_method' => 'cash',
        'supplier_refund_amount' => 0, 'supplier_refund_source_amount' => 0,
        'supplier_refund_currency' => 'AED', 'supplier_refund_payment_method' => 'cash',
        'settlement_currency' => 'AED', 'refund_currency' => 'AED',
    ];
    $withoutEquivalent = $correction;
    unset($withoutEquivalent['expected_supplier_refund_equivalent_amount']);
    $withoutEquivalent['supplier_refund_conversion_mode'] = 'manual_equivalent';
    $withoutEquivalent['expected_supplier_refund_exchange_rate'] = '0.01299709';
    $missingEquivalentRejected = false;
    try {
        $service->correctPenaltyRefundWorkflow($withoutEquivalent, 1, [1, 2]);
    } catch (RuntimeException $error) {
        $missingEquivalentRejected = str_contains($error->getMessage(), 'equivalent amount in AED');
    }
    $assert($missingEquivalentRejected
        && (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn() === $journalBefore,
        'A saved rate cannot substitute for a missing confirmed AED equivalent');
    $sameEquivalent = array_replace($correction, [
        'expected_supplier_refund_amount' => 50942,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40000,
    ]);
    $service->correctPenaltyRefundWorkflow($sameEquivalent, 1, [1, 2]);
    $sameEquivalentReceivable = $customers->findReceivableByServiceLine($reference, $lineReference);
    $sameEquivalentObligation = $suppliers->findObligationByServiceLine($reference, $lineReference);
    $sameEquivalentState = $service->serviceState(38, [1, 2]);
    $sameEquivalentRow = array_values(array_filter((array) ($sameEquivalentState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === 38))[0] ?? [];
    $assert((float) $sameEquivalentReceivable['due_amount'] === (float) $receivableBefore['due_amount']
        && (float) $sameEquivalentObligation['gross_amount'] === (float) $obligationBefore['gross_amount'] - 700.0
        && (float) ($sameEquivalentRow['supplier_refund_total_source_amount'] ?? 0) === 50942.0
        && (float) ($sameEquivalentRow['supplier_refund_total_amount'] ?? 0) === 653.0,
        'PKR supplier credit can increase while AED equivalent and customer balance stay unchanged');
    $changedEquivalent = array_replace($correction, [
        'expected_supplier_refund_amount' => 51642,
        'expected_supplier_refund_equivalent_amount' => 661,
        'supplier_penalty_amount' => 39300,
    ]);
    $service->correctPenaltyRefundWorkflow($changedEquivalent, 1, [1, 2]);
    $changedEquivalentState = $service->serviceState(38, [1, 2]);
    $changedEquivalentRow = array_values(array_filter((array) ($changedEquivalentState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === 38))[0] ?? [];
    $assert((float) ($changedEquivalentRow['supplier_refund_total_source_amount'] ?? 0) === 51642.0
        && (float) ($changedEquivalentRow['supplier_refund_total_amount'] ?? 0) === 661.0
        && (float) $customers->findReceivableByServiceLine($reference, $lineReference)['due_amount']
            === (float) $receivableBefore['due_amount'] - 8.0,
        'Changing PKR and confirmed AED amounts moves each balance by its own entered difference');
    $service->correctPenaltyRefundWorkflow($correction, 1, [1, 2]);

    $receivableAfter = $customers->findReceivableByServiceLine($reference, $lineReference);
    $obligationAfter = $suppliers->findObligationByServiceLine($reference, $lineReference);
    $cancelAfter = $events->latestPostedEvent(38, 'cancel');
    $afterPayload = json_decode((string) $cancelAfter['payload_json'], true);
    $assert(abs((float) $receivableAfter['due_amount'] - ((float) $receivableBefore['due_amount'] - 61)) < 0.005
        && abs((float) $obligationAfter['gross_amount'] - ((float) $obligationBefore['gross_amount'] - 4700)) < 0.005,
        'Confirmed AED and PKR differences reach the correct customer and supplier balances');
    $assert((float) $afterPayload['expected_supplier_refund_source_amount'] === 54942.0
        && (float) $afterPayload['expected_supplier_refund_booking_amount'] === 714.0,
        'Cancellation stores both confirmed amounts, not a guessed rate');
    $assert((float) ($suppliers->findAdvanceById(49)['available_amount'] ?? -1) === $oldAdvanceAvailable,
        'Previously used supplier advance remains untouched');

    $supplierRefundAfter = 0.0;
    $customerRefundAfter = 0.0;
    foreach ($events->postedEventsForService(38) as $event) {
        if ((string) ($event['event_type'] ?? '') !== 'refund') {
            continue;
        }
        $customerRefundAfter += (float) ($event['customer_refund_amount'] ?? 0);
        $supplierRefundAfter += (float) ($event['supplier_refund_amount'] ?? 0);
    }
    $assert($customerRefundAfter === $customerRefundBefore && $supplierRefundAfter === 714.0,
        'Customer cash refund is preserved while total supplier credit increases only by AED 61');
    $state = $service->serviceState(38, [1, 2]);
    $serviceRows = (array) ($state['services'] ?? []);
    $serviceRow = array_values(array_filter($serviceRows, static fn (array $row): bool => (int) ($row['id'] ?? 0) === 38))[0] ?? [];
    $assert((float) ($serviceRow['supplier_refund_total_amount'] ?? 0) === 714.0
        && (float) ($serviceRow['supplier_refund_total_source_amount'] ?? 0) === 54942.0,
        'Edit form data retains the cumulative PKR and AED totals after the delta event');
    $service->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 55042,
        'expected_supplier_refund_equivalent_amount' => 715,
        'supplier_penalty_amount' => 35900,
        'supplier_refund_amount' => 715,
        'supplier_refund_source_amount' => 55042,
    ]), 1, [1, 2]);
    $secondState = $service->serviceState(38, [1, 2]);
    $secondRows = (array) ($secondState['services'] ?? []);
    $secondRow = array_values(array_filter($secondRows, static fn (array $row): bool => (int) ($row['id'] ?? 0) === 38))[0] ?? [];
    $assert((float) ($secondRow['supplier_refund_total_amount'] ?? 0) === 715.0
        && (float) ($secondRow['supplier_refund_total_source_amount'] ?? 0) === 55042.0,
        'A later increase uses cumulative totals rather than the last delta alone');
    $assert((int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $treasuryBefore,
        'Supplier credit correction causes no treasury cash movement');

    $statement = $db->prepare(
        'SELECT je.id, SUM(jel.debit_amount) AS debit, SUM(jel.credit_amount) AS credit
         FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.id > :last_id GROUP BY je.id'
    );
    $statement->execute(['last_id' => $journalBefore]);
    $journals = $statement->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($journals) >= 4 && array_reduce($journals, static fn (bool $ok, array $row): bool =>
        $ok && abs((float) $row['debit'] - (float) $row['credit']) < 0.005, true),
        'Every new correction journal balances');
} finally {
    $db->rollBack();
}

echo 'All BK-000038 changes were rolled back.' . PHP_EOL;
