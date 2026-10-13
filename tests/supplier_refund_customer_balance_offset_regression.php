<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Services\ServiceWorkspaceService;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$booking = $db->query('SELECT id, branch_id FROM bookings WHERE booking_reference = "BK-000064"')->fetch(PDO::FETCH_ASSOC);
$service = $db->query('SELECT id FROM booking_services WHERE booking_id = 64 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($booking === false || $service === false) {
    fwrite(STDERR, "BK-000064 local fixture is unavailable.\n");
    exit(1);
}
$bookingId = (int) $booking['id'];
$serviceId = (int) $service['id'];
$actorId = (int) $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = "super_admin" AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
if ($actorId <= 0) {
    fwrite(STDERR, "An active super admin is needed for the rollback test.\n");
    exit(1);
}
$input = [
    'booking_id' => $bookingId, 'service_id' => $serviceId,
    'correction_reason' => 'Confirmed supplier refund equivalent applied to customer invoice',
    'correction_event_date' => date('Y-m-d'),
    'settlement_currency' => 'AED', 'settlement_exchange_rate' => 1,
    'customer_penalty_amount' => 0, 'customer_penalty_currency' => 'AED', 'customer_penalty_exchange_rate' => 1,
    'expected_supplier_refund_amount' => 4045, 'expected_supplier_refund_currency' => 'PKR',
    'expected_supplier_refund_equivalent_amount' => 50, 'supplier_refund_conversion_mode' => 'manual_equivalent',
    'supplier_penalty_amount' => 35700, 'supplier_penalty_currency' => 'PKR',
    'agency_fee_refund_amount' => 0, 'customer_refund_amount' => 0, 'supplier_refund_amount' => 0,
];
$read = static function () use ($db, $serviceId): array {
    $receivable = $db->query('SELECT currency, due_amount, allocated_amount, outstanding_amount FROM customer_receivable_items WHERE booking_reference = "BK-000064" AND service_line_reference = "SV-001"')->fetch(PDO::FETCH_ASSOC);
    $obligation = $db->query('SELECT currency, gross_amount, net_payable_amount FROM supplier_obligations WHERE booking_reference = "BK-000064" AND service_line_reference = "SV-001"')->fetch(PDO::FETCH_ASSOC);
    $statement = $db->prepare('SELECT payload_json FROM booking_service_events WHERE booking_service_id = ? AND event_type = "cancel" AND event_status = "posted" ORDER BY id DESC LIMIT 1');
    $statement->execute([$serviceId]);
    $payload = json_decode((string) $statement->fetchColumn(), true);
    $journals = (int) $db->query('SELECT COUNT(*) FROM journal_entries WHERE booking_reference = "BK-000064"')->fetchColumn();
    return [$receivable, $obligation, $payload, $journals];
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
};
$db->beginTransaction();
try {
    [$beforeReceivable, $beforeObligation, $beforePayload, $beforeJournals] = $read();
    $assert(($beforePayload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true
        && (float) $beforeReceivable['outstanding_amount'] === 50.0, 'Historical customer settlement is pending at AED 50');
    $serviceWorkspace = new ServiceWorkspaceService($app);
    try {
        $serviceWorkspace->correctPenaltyRefundWorkflow(array_replace($input, [
            'expected_supplier_refund_equivalent_amount' => '',
        ]), $actorId, [(int) $booking['branch_id']]);
        throw new RuntimeException('Blank customer equivalent was accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'Enter the confirmed supplier refund equivalent'),
            'Pending settlement requires a staff-entered customer-currency equivalent');
    }
    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [(int) $booking['branch_id']]);
    [$afterReceivable, $afterObligation, $afterPayload, $afterJournals] = $read();
    $assert((float) $afterReceivable['due_amount'] === 550.0
        && (float) $afterReceivable['allocated_amount'] === 550.0
        && (float) $afterReceivable['outstanding_amount'] === 0.0,
        'PKR supplier refund equivalent clears AED 50 customer outstanding');
    $assert($afterObligation === $beforeObligation
        && (float) $afterPayload['expected_supplier_refund_source_amount'] === 4045.0
        && $afterPayload['expected_supplier_refund_currency'] === 'PKR'
        && (float) $afterPayload['expected_supplier_refund_equivalent_amount'] === 50.0
        && (int) $afterPayload['supplier_credit_recording_version'] === 1
        && (float) $afterPayload['supplier_payable_reduced_source_amount'] === 4045.0,
        'PKR supplier position stays unchanged and both currency values are saved');
    $assert($afterJournals === $beforeJournals + 1
        && ($afterPayload['currency_correction']['customer_cancellation_settlement_pending'] ?? true) === false,
        'Only one customer adjustment journal is posted');
    $state = $serviceWorkspace->serviceState($bookingId, [(int) $booking['branch_id']]);
    $serviceState = array_values(array_filter($state['services'], static fn (array $row): bool => (int) $row['id'] === $serviceId))[0] ?? null;
    $assert($serviceState !== null
        && $serviceState['supplier_credit_auto_recording'] === true
        && (float) $serviceState['supplier_refundable_credit_amount'] === 0.0
        && (float) $serviceState['customer_refund_summary']['remaining'] === 0.0,
        'Workspace shows no second supplier refund and no customer payout due');
    $offsetJournalId = (int) end($afterPayload['customer_offset_journal_ids']);
    $journalLines = $db->query('SELECT je.currency, coa.code AS account_code, jl.debit_amount, jl.credit_amount
        FROM journal_entries je JOIN journal_entry_lines jl ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jl.account_id
        WHERE je.id = ' . $offsetJournalId . ' ORDER BY jl.id')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($journalLines) === 2
        && $journalLines[0]['currency'] === 'AED' && $journalLines[1]['currency'] === 'AED'
        && array_column($journalLines, 'account_code') === ['AR_CONTROL', 'SERVICE_REVENUE'],
        'Customer adjustment posts only AED receivable and revenue, not cash or supplier entries');
    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [(int) $booking['branch_id']]);
    [, , , $repeatJournals] = $read();
    $assert($repeatJournals === $afterJournals, 'Repeating the same edit creates no duplicate financial journal');
    $input['expected_supplier_refund_equivalent_amount'] = 70;
    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [(int) $booking['branch_id']]);
    [$creditReceivable, , $creditPayload] = $read();
    $assert((float) $creditReceivable['due_amount'] === 530.0
        && (float) $creditReceivable['allocated_amount'] === 530.0
        && (float) $creditPayload['available_customer_refund_credit'] === 20.0,
        'Excess AED 20 is released as customer credit, not paid cash');
    $creditState = $serviceWorkspace->serviceState($bookingId, [(int) $booking['branch_id']]);
    $creditService = array_values(array_filter($creditState['services'], static fn (array $row): bool => (int) $row['id'] === $serviceId))[0] ?? null;
    $assert($creditService !== null && (float) $creditService['customer_refund_summary']['remaining'] === 20.0,
        'Workspace shows AED 20 as remaining customer refund credit');
    $input['expected_supplier_refund_equivalent_amount'] = 50;
    $serviceWorkspace->correctPenaltyRefundWorkflow($input, $actorId, [(int) $booking['branch_id']]);
    [$restoredReceivable, $restoredObligation, $restoredPayload] = $read();
    $assert((float) $restoredReceivable['due_amount'] === 550.0
        && (float) $restoredReceivable['allocated_amount'] === 550.0
        && (float) $restoredPayload['available_customer_refund_credit'] === 0.0
        && $restoredObligation === $beforeObligation,
        'Editing again restores the released credit without touching supplier truth');
    $db->rollBack();
    echo "Transaction rolled back.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
