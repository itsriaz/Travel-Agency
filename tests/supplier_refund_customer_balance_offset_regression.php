<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Services\BookingWorkspaceService;
use App\Services\CustomerReceiptWorkspaceService;
use App\Services\ServiceWorkspaceService;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$actorId = (int) $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = "super_admin" AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
if ($actorId <= 0) {
    fwrite(STDERR, "An active super admin is needed for the rollback test.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
};

$today = date('Y-m-d');
$serviceWorkspace = new ServiceWorkspaceService($app);
$customerRepository = new CustomerPaymentRepository($app);
$events = new BookingServiceEventRepository($app);
$accounting = new AccountingRepository($app);
$db->beginTransaction();
try {
    $booking = (new BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Customer offset rollback fixture',
        'remarks' => 'Rollback-only supplier refund offset fixture',
    ], $actorId, [1, 2])['booking'];
    $bookingId = (int) $booking['id'];
    $reference = (string) $booking['booking_reference'];
    $service = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01258',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED',
        'service_charge_exchange_rate' => '1',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Customer offset supplier ' . bin2hex(random_bytes(4)),
        'service_passenger_name' => 'Customer offset passenger',
        'sale_price' => '39745',
        'purchase_cost' => '39745',
        'service_charge' => '80',
        'final_sale_price' => '600',
        'ticket_pnr' => 'CO' . random_int(100000, 999999),
        'ticket_number' => 'CO-' . random_int(100000, 999999),
        'ticket_airline' => 'TEST AIR',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
    ], $actorId, [1, 2])['service'];
    $serviceId = (int) $service['id'];
    $line = (string) $service['line_reference'];
    (new CustomerReceiptWorkspaceService($app))->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => 550,
        'payment_method' => 'cash',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Rollback-only customer offset receipt',
    ], $actorId, [1, 2]);
    $serviceWorkspace->cancelService([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'cancel_reason' => 'Rollback-only customer offset cancellation',
        'cancel_event_date' => $today,
    ], $actorId, [1, 2]);
    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'settlement_reason' => 'Rollback-only customer offset settlement',
        'settlement_event_date' => $today,
        'settlement_currency' => 'AED',
        'settlement_exchange_rate' => '0.01258',
        'customer_penalty_amount' => 0,
        'customer_penalty_currency' => 'AED',
        'agency_fee_refund_amount' => 0,
        'expected_supplier_refund_amount' => 4045,
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 50,
        'supplier_penalty_amount' => 35700,
        'supplier_penalty_currency' => 'PKR',
    ], $actorId, [1, 2]);

    $receivable = $customerRepository->findReceivableByServiceLine($reference, $line);
    $cancelEvent = $events->latestPostedEvent($serviceId, 'cancel');
    $payload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
    $assert($receivable !== null && $cancelEvent !== null && is_array($payload)
        && abs((float) $receivable['due_amount'] - 550) < 0.005
        && abs((float) $receivable['allocated_amount'] - 550) < 0.005,
        'Fresh fixture settles the customer invoice at AED 550');

    // Stage the historical pending state inside the rollback transaction only.
    $customerRepository->syncReceivableItem([
        'branch_id' => 2,
        'booking_reference' => $reference,
        'service_line_reference' => $line,
        'due_group' => 'service_sale',
        'currency' => 'AED',
        'due_amount' => 600,
        'due_date' => $today,
        'status' => 'open',
        'remarks' => 'Rollback-only pending customer equivalent',
        'actor_user_id' => $actorId,
    ]);
    $restoreJournalId = $accounting->postReceivableAdjusted([
        'branch_id' => 2,
        'booking_reference' => $reference,
        'service_line_reference' => $line,
        'customer_receivable_item_id' => (int) $receivable['id'],
        'adjustment_amount' => 50,
        'entry_date' => $today,
        'currency' => 'AED',
        'actor_user_id' => $actorId,
        'narration' => 'Rollback-only legacy customer balance restoration',
    ]);
    $payload['customer_final_charge_amount'] = 600;
    $payload['currency_correction']['customer_cancellation_settlement_pending'] = true;
    $payload['journal_entry_ids'][] = $restoreJournalId;
    unset($payload['customer_offset_version']);
    $events->updateCancellationFinancials((int) $cancelEvent['id'], [
        'penalty_amount' => $cancelEvent['penalty_amount'],
        'customer_credit_amount' => $cancelEvent['customer_credit_amount'],
        'supplier_credit_amount' => $cancelEvent['supplier_credit_amount'],
        'journal_entry_id' => $cancelEvent['journal_entry_id'],
        'payload_json' => $payload,
    ]);

    $read = static function () use ($db, $reference, $line, $serviceId): array {
        $item = $db->prepare('SELECT currency, due_amount, allocated_amount, outstanding_amount FROM customer_receivable_items WHERE booking_reference = ? AND service_line_reference = ?');
        $item->execute([$reference, $line]);
        $obligation = $db->prepare('SELECT currency, gross_amount, net_payable_amount FROM supplier_obligations WHERE booking_reference = ? AND service_line_reference = ?');
        $obligation->execute([$reference, $line]);
        $event = $db->prepare('SELECT payload_json FROM booking_service_events WHERE booking_service_id = ? AND event_type = "cancel" AND event_status = "posted" ORDER BY id DESC LIMIT 1');
        $event->execute([$serviceId]);
        $journals = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE booking_reference = ?');
        $journals->execute([$reference]);
        return [
            $item->fetch(PDO::FETCH_ASSOC),
            $obligation->fetch(PDO::FETCH_ASSOC),
            json_decode((string) $event->fetchColumn(), true),
            (int) $journals->fetchColumn(),
        ];
    };
    [$beforeReceivable, $beforeObligation, $beforePayload, $beforeJournals] = $read();
    $assert(($beforePayload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true
        && abs((float) $beforeReceivable['outstanding_amount'] - 50) < 0.005
        && abs((float) $beforeObligation['net_payable_amount'] - 35700) < 0.005,
        'Pending fixture has AED 50 outstanding and PKR 35,700 unpaid supplier cost');

    $input = [
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'correction_reason' => 'Confirmed supplier refund equivalent applied to customer invoice',
        'correction_event_date' => $today,
        'settlement_currency' => 'AED', 'settlement_exchange_rate' => 1,
        'customer_penalty_amount' => 0, 'customer_penalty_currency' => 'AED', 'customer_penalty_exchange_rate' => 1,
        'expected_supplier_refund_amount' => 4045, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 50, 'expected_supplier_refund_equivalent_currency' => 'AED',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
        'supplier_penalty_amount' => 35700, 'supplier_penalty_currency' => 'PKR',
        'agency_fee_refund_amount' => 0, 'customer_refund_amount' => 0, 'supplier_refund_amount' => 0,
    ];
    try {
        $serviceWorkspace->correctPenaltyRefundWorkflow(array_replace($input, [
            'expected_supplier_refund_equivalent_amount' => '',
        ]), $actorId, [2]);
        throw new RuntimeException('Blank customer equivalent was accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'equivalent amount in AED')
            || str_contains($error->getMessage(), 'confirmed supplier refund equivalent'),
            'Pending settlement requires a staff-entered customer-currency equivalent');
    }

    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [2]);
    [$afterReceivable, $afterObligation, $afterPayload, $afterJournals] = $read();
    $assert(abs((float) $afterReceivable['due_amount'] - 550) < 0.005
        && abs((float) $afterReceivable['allocated_amount'] - 550) < 0.005
        && abs((float) $afterReceivable['outstanding_amount']) < 0.005,
        'PKR supplier refund equivalent clears AED 50 customer outstanding');
    $assert($afterObligation === $beforeObligation
        && abs((float) $afterPayload['expected_supplier_refund_source_amount'] - 4045) < 0.005
        && $afterPayload['expected_supplier_refund_currency'] === 'PKR'
        && abs((float) $afterPayload['expected_supplier_refund_equivalent_amount'] - 50) < 0.005
        && (int) $afterPayload['supplier_credit_recording_version'] === 1
        && abs((float) $afterPayload['supplier_payable_reduced_source_amount'] - 4045) < 0.005,
        'PKR supplier position stays unchanged and both currency values are saved');
    $assert($afterJournals === $beforeJournals + 1
        && ($afterPayload['currency_correction']['customer_cancellation_settlement_pending'] ?? true) === false,
        'Only one customer adjustment journal is posted');

    $offsetJournalId = (int) end($afterPayload['customer_offset_journal_ids']);
    $lines = $db->prepare('SELECT je.currency, coa.code AS account_code FROM journal_entries je JOIN journal_entry_lines jl ON jl.journal_entry_id = je.id JOIN chart_of_accounts coa ON coa.id = jl.account_id WHERE je.id = ? ORDER BY jl.id');
    $lines->execute([$offsetJournalId]);
    $journalLines = $lines->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($journalLines) === 2
        && array_column($journalLines, 'currency') === ['AED', 'AED']
        && array_column($journalLines, 'account_code') === ['AR_CONTROL', 'SERVICE_REVENUE'],
        'Customer adjustment posts AED receivable and revenue, not cash or supplier entries');

    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [2]);
    [, , , $repeatJournals] = $read();
    $assert($repeatJournals === $afterJournals, 'Repeating the same edit creates no duplicate journal');

    $input['expected_supplier_refund_equivalent_amount'] = 70;
    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [2]);
    [$creditReceivable, , $creditPayload] = $read();
    $assert(abs((float) $creditReceivable['due_amount'] - 530) < 0.005
        && abs((float) $creditReceivable['allocated_amount'] - 530) < 0.005
        && abs((float) $creditPayload['available_customer_refund_credit'] - 20) < 0.005,
        'AED 20 excess is customer credit, not cash paid out');

    $input['expected_supplier_refund_equivalent_amount'] = 50;
    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [2]);
    [$restoredReceivable, $restoredObligation, $restoredPayload] = $read();
    $assert(abs((float) $restoredReceivable['due_amount'] - 550) < 0.005
        && abs((float) $restoredReceivable['allocated_amount'] - 550) < 0.005
        && abs((float) $restoredPayload['available_customer_refund_credit']) < 0.005
        && $restoredObligation === $beforeObligation,
        'Decreasing the equivalent restores customer allocation without changing supplier truth');
    $db->rollBack();
    echo "Transaction rolled back.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
