<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new \App\Repositories\ReportRepository($app);
$service = new \App\Services\ReportService($app);
$rowsMethod = new ReflectionMethod($service, 'customerLedgerRowsForCustomer');
$balanceMethod = new ReflectionMethod($service, 'applyCustomerLedgerRunningBalances');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$bookingRow = static function (string $reference) use ($repository, $assert): array {
    $rows = array_values(array_filter(
        $repository->customerLedger([1, 2], null, null, '', 0, '', $reference),
        static fn (array $row): bool => ($row['booking_reference'] ?? '') === $reference
            && ! isset($row['row_type'])
    ));
    $assert(count($rows) === 1, $reference . ' must have one customer receivable report row.');

    return $rows[0];
};

$bk221 = $bookingRow('BK-000221');
$assert($bk221['currency'] === 'AED', 'BK-000221 customer invoice must remain AED.');
$assert((float) $bk221['refund_customer_final_charge_amount'] === 360.0, 'Saved AED 360 final charge must reach the report.');
$assert((float) $bk221['refund_customer_refund_expected'] === 1300.0, 'Customer refund entitlement must be AED 1,300, not supplier PKR.');
$assert((float) $bk221['refund_supplier_penalty_amount'] === 20000.0, 'Supplier penalty must remain PKR 20,000 in its own report field.');
$refundStatement = $db->prepare(
    'SELECT e.customer_refund_amount, d.refund_source_amount, d.refund_currency,
            d.refund_payment_method, ta.currency AS treasury_currency, je.source_type,
            (SELECT COALESCE(SUM(jel.debit_amount), 0)
             FROM journal_entry_lines jel
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.journal_entry_id = je.id AND coa.code = "CUSTOMER_CREDIT") AS liability_debit,
            (SELECT COALESCE(SUM(jel.credit_amount), 0)
             FROM journal_entry_lines jel
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.journal_entry_id = je.id AND coa.code = ta.account_code) AS treasury_credit
     FROM booking_service_events e
     INNER JOIN booking_service_refund_details d ON d.service_event_id = e.id
     INNER JOIN treasury_accounts ta ON ta.id = d.treasury_account_id
     INNER JOIN journal_entries je ON je.id = e.journal_entry_id
     WHERE e.booking_reference = :booking_reference
       AND e.event_type = "refund" AND e.event_status = "posted"
       AND e.customer_refund_amount > 0.005'
);
$refundStatement->execute(['booking_reference' => 'BK-000221']);
$postedRefunds = $refundStatement->fetchAll(PDO::FETCH_ASSOC);
$assert(count($postedRefunds) === 1, 'BK-000221 must have one posted customer cash refund.');
$postedRefund = $postedRefunds[0];
$assert(
    $postedRefund['refund_currency'] === 'AED'
    && $postedRefund['treasury_currency'] === 'AED'
    && $postedRefund['refund_payment_method'] === 'cash'
    && $postedRefund['source_type'] === 'service_refund_posted'
    && abs((float) $postedRefund['customer_refund_amount'] - 1300.0) <= 0.005
    && abs((float) $postedRefund['refund_source_amount'] - 1300.0) <= 0.005
    && abs((float) $postedRefund['liability_debit'] - 1300.0) <= 0.005
    && abs((float) $postedRefund['treasury_credit'] - 1300.0) <= 0.005,
    'BK-000221 AED 1,300 cash refund must have matching treasury and liability journal evidence.'
);
$assert((float) $bk221['refund_customer_refund_posted'] === 1300.0, 'The ledger must include the evidenced AED 1,300 customer refund.');

$ledgerRows = $rowsMethod->invoke($service, $bk221, []);
$balanceMethod->invokeArgs($service, [&$ledgerRows]);
$refundRows = array_values(array_filter(
    $ledgerRows,
    static fn (array $row): bool => str_starts_with((string) ($row['ledger_entry'] ?? ''), 'Refund calculated after')
));
$assert(count($refundRows) === 1, 'BK-000221 must show one refund calculation row.');
$assert(($refundRows[0]['ledger_entry'] ?? '') === 'Refund calculated after 360.00 cancellation deduction', 'Customer deduction must be AED 360.');
$assert(($refundRows[0]['currency'] ?? '') === 'AED', 'Customer refund row must use AED.');
$assert((float) ($refundRows[0]['raw_debit_amount'] ?? 0) === 1300.0, 'Calculated AED 1,300 refund must be a customer-ledger debit.');
$paidRefundRows = array_values(array_filter(
    $ledgerRows,
    static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Refund paid to customer'
));
$assert(count($paidRefundRows) === 1, 'BK-000221 must show one cash refund payment row.');
$assert(($paidRefundRows[0]['currency'] ?? '') === 'AED', 'Cash refund payment must remain in AED.');
$assert((float) ($paidRefundRows[0]['raw_credit_amount'] ?? 0) === 1300.0, 'The AED 1,300 payment must close the calculated refund.');
$assert(abs((float) ($paidRefundRows[0]['raw_balance_amount'] ?? 0)) <= 0.005, 'No customer refund may remain due after the AED 1,300 payment.');
$assert(! in_array('Cancellation balance due', array_column($ledgerRows, 'ledger_entry'), true), 'Supplier PKR penalty must not create customer AED balance due.');

$bk38 = $bookingRow('BK-000038');
$assert((float) $bk38['refund_customer_refund_expected'] === 600.0, 'Legacy supplier PKR credit must convert only through the saved AED equivalent.');
$assert((float) $bk38['refund_customer_refund_posted'] === 600.0, 'Supplier-only refund must not be counted as customer cash.');
$assert($bk38['refund_customer_refund_currency'] === 'AED', 'Supplier-only PKR event must not change customer refund currency.');

$bk87 = $bookingRow('BK-000087');
$assert((float) $bk87['refund_customer_refund_expected'] === 1280.0, 'Refunded AED 100 service fee must remain in customer entitlement.');
$assert((float) $bk87['refund_customer_refund_posted'] === 0.0, 'Transferred customer credit is not a cash refund.');

echo '[PASS] BK-000221 AED 1,300 refund and AED 360 charge never mix with PKR 20,000 supplier penalty.' . PHP_EOL;
echo '[PASS] BK-000038 supplier-only PKR credit cannot masquerade as customer refund.' . PHP_EOL;
echo '[PASS] BK-000087 refunded service fee remains in customer AED entitlement.' . PHP_EOL;
