<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\TreasuryRepository;
use App\Services\SupplierPaymentVoidImpactService;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$service = new SupplierPaymentVoidImpactService($app);
$treasury = new TreasuryRepository($app);
$snapshot = static function () use ($db, $treasury): array {
    $tables = [
        'supplier_payments' => 'paid_amount',
        'supplier_payment_allocations' => 'allocated_amount',
        'supplier_advances' => 'available_amount',
        'supplier_advance_applications' => 'applied_amount',
        'supplier_obligations' => 'net_payable_amount',
        'customer_receivable_items' => 'outstanding_amount',
        'journal_entries' => 'id',
    ];
    $result = [];
    foreach ($tables as $table => $column) {
        $result[$table] = $db->query("SELECT COUNT(*) AS row_count, COALESCE(SUM({$column}), 0) AS total FROM {$table}")
            ->fetch(PDO::FETCH_ASSOC);
    }
    foreach ($treasury->accounts([1, 2]) as $account) {
        $result['treasury:' . $account['id']] = (float) $account['current_balance'];
    }
    return $result;
};

$before = $snapshot();
$sample = $db->query(
    'SELECT p.id, p.branch_id
     FROM supplier_payments p
     INNER JOIN supplier_advances sa ON sa.source_supplier_payment_id = p.id
     INNER JOIN supplier_advance_applications aa ON aa.supplier_advance_id = sa.id
     WHERE p.status <> "void"
     ORDER BY p.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($sample), 'An active supplier payment with later credit use exists for impact preview.');

$paymentId = (int) $sample['id'];
$preview = $service->preview($paymentId, [1, 2]);
$assert((int) $preview['payment']['id'] === $paymentId, 'Preview identifies the selected payment.');
$assert($preview['advance_applications'] !== [], 'Preview lists later bookings that used this payment credit.');
$assert($preview['linked_advances'] !== [], 'Preview follows all credit records linked to this payment.');
$assert($preview['totals']['credit_reconciles'], 'Each linked credit reconciles independently to its applications and available balance.');

$duplicateIdStatement = $db->prepare('SELECT id FROM supplier_payments WHERE payment_no = ?');
$duplicateIdStatement->execute(['SPAY-000232']);
$duplicateId = (int) $duplicateIdStatement->fetchColumn();
if ($duplicateId > 0) {
    $duplicatePreview = $service->preview($duplicateId, [1, 2]);
    $events = array_column($duplicatePreview['cancellation_events'], null, 'event_id');
    $assert(isset($events[69], $events[71]), 'Duplicate payment preview identifies both cancellation events.');
    $assert(abs((float) $events[69]['selected_source_amount'] - 222176.0) <= 0.01
        && abs((float) $events[69]['other_source_amount']) <= 0.01
        && abs((float) $events[69]['recorded_booking_equivalent'] - 2885.0) <= 0.01,
        'First cancellation shows this payment as its sole PKR source and the whole AED equivalent.');
    $assert(abs((float) $events[71]['selected_source_amount'] - 1944.0) <= 0.01
        && abs((float) $events[71]['other_source_amount'] - 7056.0) <= 0.01
        && abs((float) $events[71]['recorded_booking_equivalent'] - 117.0) <= 0.01,
        'Shared cancellation distinguishes this payment from the other PKR source without inferring an AED share.');
    $otherFunding = array_values(array_filter($events[71]['funding'],
        static fn (array $funding): bool => ! $funding['is_selected']));
    $assert(count($otherFunding) === 1 && $otherFunding[0]['payment_no'] === 'SPAY-000235',
        'Shared cancellation identifies the other real funding payment.');
}

try {
    $service->preview($paymentId, []);
    throw new RuntimeException('Branch access was not enforced.');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'accessible branches'), 'Preview rejects callers without branch access.');
}

$after = $snapshot();
$assert($before === $after, 'Preview changes no supplier, customer, journal, or treasury totals.');

$route = file_get_contents(BASE_PATH . '/public/index.php');
$assert(str_contains($route, "'/suppliers/payments/void-impact'")
    && str_contains($route, 'FinancialAdminMiddleware::class'), 'Impact route requires financial-admin authorization.');
