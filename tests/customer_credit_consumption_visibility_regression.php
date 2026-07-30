<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];
$check = static function (string $label, bool $passed, mixed $details = null) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label;
    if ($details !== null) {
        echo ' - ' . json_encode($details, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Consumed customer refund-credit visibility regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$booking = $db->query(
    'SELECT id, branch_id FROM bookings WHERE booking_reference = "BK-000270" LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$receiptTruth = $db->query(
    'SELECT
        r.id,
        r.receipt_no,
        r.allocated_amount,
        r.unallocated_amount,
        r.status,
        COALESCE(SUM(CASE WHEN cri.booking_reference = "BK-000277" THEN a.allocated_amount ELSE 0 END), 0) AS applied_to_bk000277
     FROM customer_receipts r
     LEFT JOIN customer_receipt_allocations a ON a.customer_receipt_id = r.id
     LEFT JOIN customer_receivable_items cri ON cri.id = a.customer_receivable_item_id
     WHERE r.booking_reference = "BK-000270"
       AND r.receipt_no = "RCPT-000264"
     GROUP BY r.id, r.receipt_no, r.allocated_amount, r.unallocated_amount, r.status'
)->fetch(PDO::FETCH_ASSOC) ?: [];

$check(
    'RCPT-000264 has no available credit after AED 844 is applied to BK-000277',
    round((float) ($receiptTruth['unallocated_amount'] ?? -1), 2) === 0.00
        && round((float) ($receiptTruth['applied_to_bk000277'] ?? 0), 2) === 844.00
        && (string) ($receiptTruth['status'] ?? '') === 'fully_allocated',
    $receiptTruth
);

$serviceState = (new \App\Services\ServiceWorkspaceService($app))->serviceState(
    (int) ($booking['id'] ?? 0),
    [(int) ($booking['branch_id'] ?? 0)]
);
$serviceRows = (array) ($serviceState['services'] ?? []);
$service = $serviceRows[0] ?? [];
$check(
    'Cancelled BK-000270 workspace no longer advertises AED 844 as available customer credit',
    round((float) ($service['customer_refundable_credit_amount'] ?? -1), 2) === 0.00,
    ['customer_refundable_credit_amount' => $service['customer_refundable_credit_amount'] ?? null]
);

$paymentPreview = (new \App\Services\CustomerPaymentFoundationService($app))->buildWorkspacePreview(
    'BK-000270',
    null,
    [(int) ($booking['branch_id'] ?? 0)]
);
$workspaceCredit = round((float) ($paymentPreview['summary']['customerCredit']['AED'] ?? 0), 2);
$check(
    'Payment Summary also hides the consumed AED 844 credit',
    $workspaceCredit === 0.00,
    ['workspace_customer_credit' => $workspaceCredit]
);

$payableRefundRows = (new \App\Repositories\ReportRepository($app))->payableRefunds(
    [(int) ($booking['branch_id'] ?? 0)],
    null,
    null,
    0,
    'BK-000270'
);
$bk270RefundPayable = round(array_sum(array_map(
    static fn (array $row): float => (float) ($row['customer_refund_payable'] ?? 0),
    $payableRefundRows
)), 2);
$check(
    'Payable Refunds no longer reports AED 844 owed after cross-booking application',
    $bk270RefundPayable === 0.00,
    ['rows' => $payableRefundRows, 'customer_refund_payable' => $bk270RefundPayable]
);

$accountLedger = (new \App\Services\AccountLedgerService($app))->report([
    'dateFrom' => '',
    'dateTo' => '',
    'currency' => 'AED',
    'businessSourceId' => 0,
    'customerName' => '',
    'bookingReference' => 'BK-000270',
], [(int) ($booking['branch_id'] ?? 0)]);
$accountSummary = (array) ($accountLedger['summaryRows'][0] ?? []);
$check(
    'Account Ledger pending refund position also follows zero current credit',
    ! str_contains((string) ($accountSummary['pending_status'] ?? ''), 'Cust. refund'),
    $accountSummary
);

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Consumed customer refund-credit visibility regression failed: '
        . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Consumed customer refund-credit visibility regression passed.' . PHP_EOL;
