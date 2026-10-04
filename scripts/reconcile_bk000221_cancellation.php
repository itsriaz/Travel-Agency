<?php

declare(strict_types=1);

// Guarded historical correction; dry-run unless --apply is supplied.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Services\ServiceWorkspaceService;

$apply = in_array('--apply', $argv, true);
$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();

try {
    $check = static function (bool $condition, string $message): void {
        if (! $condition) { throw new RuntimeException($message); }
    };
    $query = static function (string $sql, array $params = []) use ($db): array {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };
    $bookings = $query('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000221']);
    $check(count($bookings) === 1 && (int) $bookings[0]['id'] === 221 && (int) $bookings[0]['branch_id'] === 2,
        'BK-000221 no longer matches the verified booking.');
    $service = (new BookingServiceRepository($app))->findServiceById(220);
    $check($service !== null && (int) $service['booking_id'] === 221 && $service['service_status'] === 'Cancelled'
        && $service['line_reference'] === 'SV-001' && $service['currency'] === 'AED'
        && $service['cost_currency'] === 'PKR' && abs((float) $service['final_sale_price'] - 1660) < .005
        && abs((float) $service['purchase_cost'] - 121500) < .005,
        'The original customer invoice or supplier cost differs from the reviewed record.');
    $events = new BookingServiceEventRepository($app);
    $cancel = $events->latestPostedEvent(220, 'cancel');
    $check($cancel !== null && (int) $cancel['id'] === 51 && $events->latestPostedEvent(220, 'refund') === null,
        'Cancellation/refund history changed; review before correction.');
    $payload = json_decode((string) $cancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $check(($payload['mode'] ?? '') === 'cancellation_financial_adjustment'
        && abs((float) ($payload['prior_customer_due_amount'] ?? -1) - 1660) < .005
        && abs((float) ($payload['supplier_cost_basis'] ?? -1) - 121500) < .005
        && abs((float) ($payload['expected_supplier_refund_source_amount'] ?? -1) - 101500) < .005
        && ($payload['expected_supplier_refund_currency'] ?? '') === 'PKR'
        && abs((float) ($payload['supplier_penalty_source_amount'] ?? -1) - 20000) < .005
        && abs((float) ($payload['customer_final_charge_amount'] ?? -1) - 20072) < .005
        && abs((float) ($payload['released_customer_credit'] ?? -1)) < .005,
        'The historical cancellation no longer matches the wrong-currency case.');
    $payments = new CustomerPaymentRepository($app);
    $receivable = $payments->findReceivableByServiceLine('BK-000221', 'SV-001');
    $obligation = (new SupplierRepository($app))->findObligationByServiceLine('BK-000221', 'SV-001');
    $check($receivable !== null && (int) $receivable['id'] === 220
        && $receivable['currency'] === 'AED' && abs((float) $receivable['due_amount'] - 20072) < .005
        && abs((float) $receivable['allocated_amount']) < .005
        && $obligation !== null && (int) $obligation['id'] === 220
        && $obligation['currency'] === 'AED' && abs((float) $obligation['gross_amount'] - 20000) < .005
        && abs((float) $obligation['advance_applied_amount']) < .005,
        'Current customer or supplier balance differs from the reviewed case.');
    $receipt = $payments->findReceiptById(219);
    $check($receipt !== null && $receipt['booking_reference'] === 'BK-000221'
        && $receipt['currency'] === 'AED' && $receipt['status'] === 'void'
        && abs((float) $receipt['received_amount'] - 1668) < .005,
        'Historical customer receipt changed; do not infer or post cash.');
    $check($query('SELECT id FROM customer_receipts WHERE booking_reference = ? AND status <> ? LIMIT 1',
        ['BK-000221', 'void']) === [],
        'A replacement receipt is now recorded; review customer cash before correction.');
    $check($query('SELECT id FROM supplier_payment_allocations WHERE supplier_obligation_id = ? LIMIT 1', [220]) === []
        && $query('SELECT id FROM supplier_advance_applications WHERE supplier_obligation_id = ? LIMIT 1', [220]) === [],
        'Supplier payments/advances changed; review their allocation first.');

    // These are confirmed amounts, not evidence that either refund has been paid/received.
    (new ServiceWorkspaceService($app))->correctPenaltyRefundWorkflow([
        'booking_id' => 221, 'service_id' => 220,
        'correction_event_date' => date('Y-m-d'),
        'correction_reason' => 'Correct legacy mixed-currency cancellation: PKR supplier penalty was posted as AED.',
        'customer_penalty_amount' => 18, 'customer_penalty_currency' => 'AED',
        'expected_supplier_refund_amount' => 101500, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 1318,
        'supplier_refund_conversion_mode' => 'manual_equivalent',
        'agency_fee_refund_amount' => 0,
        'customer_refund_amount' => 0, 'supplier_refund_amount' => 0,
    ], 1, [2]);

    $newReceivable = $payments->findReceivableByServiceLine('BK-000221', 'SV-001');
    $newObligation = (new SupplierRepository($app))->findObligationByServiceLine('BK-000221', 'SV-001');
    $newCancel = $events->latestPostedEvent(220, 'cancel');
    $newPayload = json_decode((string) ($newCancel['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $check($newReceivable !== null && $newReceivable['currency'] === 'AED'
        && abs((float) $newReceivable['due_amount'] - 360) < .005
        && abs((float) $newReceivable['allocated_amount']) < .005
        && $newObligation !== null && $newObligation['currency'] === 'PKR'
        && abs((float) $newObligation['gross_amount'] - 20000) < .005
        && abs((float) $newObligation['net_payable_amount'] - 20000) < .005
        && abs((float) ($newPayload['customer_penalty_amount'] ?? -1) - 18) < .005
        && abs((float) ($newPayload['expected_supplier_refund_source_amount'] ?? -1) - 101500) < .005
        && abs((float) ($newPayload['expected_supplier_refund_booking_amount'] ?? -1) - 1318) < .005
        && $events->latestPostedEvent(220, 'refund') === null,
        'Corrected currency, customer amount, supplier amount or refund history is inconsistent.');
    $check($payments->findReceiptById(219)['status'] === 'void',
        'The void customer receipt changed unexpectedly.');
    $controlBalance = static function (string $code, string $currency) use ($db): float {
        $statement = $db->prepare('SELECT COALESCE(ROUND(SUM(CASE WHEN c.code = :ap_code
            THEN l.credit_amount - l.debit_amount ELSE l.debit_amount - l.credit_amount END), 2), 0)
            FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
            JOIN chart_of_accounts c ON c.id = l.account_id
            WHERE j.booking_reference = :booking_reference AND j.currency = :currency AND c.code = :code');
        $statement->execute([
            'ap_code' => 'AP_CONTROL', 'booking_reference' => 'BK-000221',
            'currency' => $currency, 'code' => $code,
        ]);
        return (float) $statement->fetchColumn();
    };
    $check(abs($controlBalance('AR_CONTROL', 'AED') - 360) < .005
        && abs($controlBalance('AP_CONTROL', 'AED')) < .005
        && abs($controlBalance('AP_CONTROL', 'PKR') - 20000) < .005,
        'Control journals do not reconcile to the corrected customer and supplier positions.');
    AuditLog::recordRequired($app, 'service.cancellation_legacy_currency.reconciled', [
        'user_id' => 1, 'booking_id' => 221, 'booking_reference' => 'BK-000221',
        'service_id' => 220, 'cancel_event_id' => 51,
        'old_customer_receivable' => ['currency' => 'AED', 'amount' => 20072],
        'new_customer_receivable' => ['currency' => 'AED', 'amount' => 360],
        'old_supplier_obligation' => ['currency' => 'AED', 'amount' => 20000],
        'new_supplier_obligation' => ['currency' => 'PKR', 'amount' => 20000],
        'supplier_refund_expected' => ['currency' => 'PKR', 'amount' => 101500, 'aed_equivalent' => 1318],
        'customer_penalty' => ['currency' => 'AED', 'amount' => 18],
        'receipt_219_remains_void' => true, 'actual_refunds_posted' => false,
        'reason' => 'Correct wrongly labeled legacy cancellation without fabricating a receipt or refund.',
    ]);
    if ($apply) { $db->commit(); } else { $db->rollBack(); }
    echo ($apply ? 'APPLIED' : 'DRY RUN ROLLED BACK')
        . ': BK-000221 customer charge AED 360; supplier payable PKR 20,000; receipt remains void.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'No changes committed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
