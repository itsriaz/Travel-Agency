<?php

declare(strict_types=1);

// Read-only verification of the reviewed local/production replay, not a repair.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$one = static function (string $sql, array $params = []) use ($db): ?array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
};
$check = static function (bool $valid, string $message): void {
    if (! $valid) { throw new RuntimeException($message); }
    echo '[PASS] ' . $message . PHP_EOL;
};

try {
    $migration = $one('SELECT migration_name FROM migrations WHERE migration_name = ?', [
        '20260923_000077_counterparty_offset_cross_dimension.php',
    ]);
    $check($migration !== null, 'Cross-dimension linked-party migration is applied');

    $fare = $one('SELECT bs.sale_price, bs.vat_input, bs.final_sale_price, bs.purchase_cost, bs.net_profit_loss
        FROM booking_services bs JOIN bookings b ON b.id = bs.booking_id WHERE b.booking_reference = ?', ['BK-000517']);
    $check($fare !== null && (float) $fare['sale_price'] === 1351.0 && (float) $fare['vat_input'] === 0.0
        && (float) $fare['final_sale_price'] === 1450.0 && (float) $fare['purchase_cost'] === 1351.0
        && (float) $fare['net_profit_loss'] === 99.0,
        'BK-000517 Market Fare/VAT reclassification preserves invoice and margin');

    $bk897Customer = $one('SELECT currency, due_amount, allocated_amount, outstanding_amount
        FROM customer_receivable_items WHERE booking_reference = ?', ['BK-000897']);
    $bk897Supplier = $one('SELECT currency, gross_amount, net_payable_amount
        FROM supplier_obligations WHERE booking_reference = ?', ['BK-000897']);
    $check($bk897Customer !== null && $bk897Customer['currency'] === 'PKR'
        && (float) $bk897Customer['due_amount'] === 30000.0
        && (float) $bk897Customer['allocated_amount'] === 0.0
        && (float) $bk897Customer['outstanding_amount'] === 30000.0
        && $bk897Supplier !== null && $bk897Supplier['currency'] === 'PKR'
        && (float) $bk897Supplier['gross_amount'] === 24000.0
        && (float) $bk897Supplier['net_payable_amount'] === 0.0,
        'BK-000897 cancellation balances are restored without inventing customer payment');

    $bk221Receipt = $one('SELECT currency, receipt_date, received_amount, allocated_amount,
            unallocated_amount, returned_amount, treasury_account_id
        FROM customer_receipts WHERE booking_reference = ? AND status <> "void"', ['BK-000221']);
    $bk221Supplier = $one('SELECT currency, gross_amount, net_payable_amount
        FROM supplier_obligations WHERE booking_reference = ?', ['BK-000221']);
    $check($bk221Receipt !== null && $bk221Receipt['currency'] === 'AED'
        && $bk221Receipt['receipt_date'] === '2026-07-04'
        && (float) $bk221Receipt['received_amount'] === 1660.0
        && (float) $bk221Receipt['allocated_amount'] === 360.0
        && (float) $bk221Receipt['unallocated_amount'] === 1300.0
        && (float) $bk221Receipt['returned_amount'] === 0.0
        && (int) $bk221Receipt['treasury_account_id'] === 34
        && $bk221Supplier !== null && $bk221Supplier['currency'] === 'PKR'
        && (float) $bk221Supplier['gross_amount'] === 20000.0
        && (float) $bk221Supplier['net_payable_amount'] === 20000.0,
        'BK-000221 confirmed AED cash, AED credit and PKR supplier payable reconcile');

    $sourceReceipt = $one('SELECT currency, received_amount, allocated_amount, unallocated_amount,
            returned_amount, status FROM customer_receipts WHERE id = ?', [92]);
    $targetService = $one('SELECT service_status FROM booking_services WHERE id = ?', [805]);
    $targetInvoice = $one('SELECT currency, due_amount, allocated_amount, outstanding_amount
        FROM customer_receivable_items WHERE booking_reference = ?', ['BK-000795']);
    $targetSupplier = $one('SELECT currency, gross_amount, net_payable_amount
        FROM supplier_obligations WHERE booking_reference = ?', ['BK-000795']);
    $check($sourceReceipt !== null && $sourceReceipt['currency'] === 'AED'
        && (float) $sourceReceipt['received_amount'] === 1280.0
        && (float) $sourceReceipt['allocated_amount'] === 1081.0
        && (float) $sourceReceipt['unallocated_amount'] === 199.0
        && (float) $sourceReceipt['returned_amount'] === 0.0
        && $targetService !== null && $targetService['service_status'] === 'Open'
        && $targetInvoice !== null && $targetInvoice['currency'] === 'AED'
        && (float) $targetInvoice['due_amount'] === 1081.0
        && (float) $targetInvoice['allocated_amount'] === 1081.0
        && (float) $targetInvoice['outstanding_amount'] === 0.0
        && $targetSupplier !== null && $targetSupplier['currency'] === 'PKR'
        && (float) $targetSupplier['gross_amount'] === 83012.0,
        'BK-000795 restored invoice uses AED 1,081 of BK-000087 receipt once');

    $refundState = (new \App\Services\ServiceWorkspaceService($app))->serviceState(87, [2]);
    $refundSummary = (array) (($refundState['services'][0] ?? [])['customer_refund_summary'] ?? []);
    $refundRows = (new \App\Repositories\ReportRepository($app))->payableRefunds([2], null, null, 0, 'BK-000087');
    $refundReport = $refundRows[0] ?? [];
    $check(($refundSummary['currency'] ?? '') === 'AED'
        && (float) ($refundSummary['entitlement'] ?? 0) === 1280.0
        && (float) ($refundSummary['paid'] ?? -1) === 0.0
        && (float) ($refundSummary['remaining'] ?? 0) === 199.0
        && (float) ($refundReport['customer_refund_expected'] ?? 0) === 1280.0
        && (float) ($refundReport['customer_refund_payable'] ?? 0) === 199.0,
        'BK-000087 historical AED 1,280 entitlement is distinct from AED 199 payable now');

    $funding = $one('SELECT COUNT(*) AS payment_count, ROUND(SUM(jel.credit_amount), 2) AS cash_credit
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.source_type = "supplier_payment_treasury_funded"
          AND je.branch_id = 1 AND je.currency = "PKR"
          AND jel.account_id = 18 AND jel.supplier_payment_id BETWEEN 175 AND 196');
    $check($funding !== null && (int) $funding['payment_count'] === 22
        && (float) $funding['cash_credit'] === 19457566.0,
        'Twenty-two AL SAUDIA payments credit Swat PKR cash for PKR 19,457,566');

    $unbalanced = $one('SELECT COUNT(*) AS total FROM (
        SELECT je.id FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.source_type = "supplier_payment_treasury_funded"
          AND jel.supplier_payment_id BETWEEN 175 AND 196
        GROUP BY je.id HAVING ABS(SUM(jel.debit_amount) - SUM(jel.credit_amount)) > 0.005
    ) AS bad');
    $check((int) ($unbalanced['total'] ?? -1) === 0, 'AL SAUDIA funding journals are balanced');

    $supplierReceipt = $one('SELECT COUNT(*) AS total,
            SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.supplier_refund_payment_method")) = "supplier_credit" THEN 1 ELSE 0 END) AS credit_events,
            SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.supplier_refund_payment_method")) IN ("cash", "bank_transfer") THEN 1 ELSE 0 END) AS money_events
        FROM booking_service_events
        WHERE booking_reference = ? AND event_type = "refund" AND event_status = "posted"
          AND supplier_refund_amount > 0', ['BK-000038']);
    echo '[REVIEW] BK-000038 posted supplier refund events: ' . (int) ($supplierReceipt['total'] ?? 0)
        . ' (retained credit ' . (int) ($supplierReceipt['credit_events'] ?? 0)
        . ', cash/bank ' . (int) ($supplierReceipt['money_events'] ?? 0)
        . '); retained credit is not a cash receipt.' . PHP_EOL;
    $legacyTransfers = $one('SELECT COUNT(*) AS total, ROUND(SUM(destination_amount), 2) AS pkr_total
        FROM treasury_transactions WHERE id IN (5, 6, 9) AND status = "posted"
          AND destination_journal_entry_id IS NULL');
    echo '[REVIEW] Unjournaled historical transfers: ' . (int) ($legacyTransfers['total'] ?? 0)
        . ', destination PKR ' . (string) ($legacyTransfers['pkr_total'] ?? '0')
        . '; no backfill without real-world evidence.' . PHP_EOL;
    echo 'Reviewed replay verification passed; review items remain outside this repair.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
