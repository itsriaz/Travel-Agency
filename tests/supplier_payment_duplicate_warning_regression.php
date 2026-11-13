<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new SupplierRepository($app);
$sample = $db->query(
    'SELECT p.payment_no, p.supplier_id, p.branch_id, p.currency, p.paid_amount,
            p.payment_date, p.treasury_account_id, p.reference_number
     FROM supplier_payments p
     WHERE p.status <> "void" AND p.paid_amount > 0
       AND (SELECT COUNT(*) FROM supplier_payments other
            WHERE other.supplier_id = p.supplier_id
              AND other.currency = p.currency
              AND other.paid_amount = p.paid_amount
              AND other.status <> "void") = 1
     ORDER BY p.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($sample), 'An active payment with a unique supplier/currency/amount exists for the read-only test.');

$differentDate = (new DateTimeImmutable((string) $sample['payment_date']))->modify('+12 days')->format('Y-m-d');
$matches = $repository->similarSupplierPayments(
    (int) $sample['supplier_id'],
    (string) $sample['currency'],
    (float) $sample['paid_amount'],
    $differentDate,
    (int) $sample['treasury_account_id'],
    (string) $sample['reference_number'],
    [(int) $sample['branch_id']]
);
$assert(in_array($sample['payment_no'], array_column($matches, 'payment_no'), true),
    'A similar payment is found even when the newly entered payment date differs.');
$assert($repository->similarSupplierPayments(
    (int) $sample['supplier_id'],
    (string) $sample['currency'],
    (float) $sample['paid_amount'],
    $differentDate,
    null,
    null,
    []
) === [], 'No payment numbers are disclosed without an accessible branch.');

$wrongAmount = $repository->similarSupplierPayments(
    (int) $sample['supplier_id'],
    (string) $sample['currency'],
    (float) $sample['paid_amount'] + 0.01,
    $differentDate,
    null,
    null,
    [(int) $sample['branch_id']]
);
$assert(! in_array($sample['payment_no'], array_column($wrongAmount, 'payment_no'), true),
    'A different amount does not flag the sample payment.');

$void = $db->query(
    'SELECT payment_no, supplier_id, branch_id, currency, paid_amount, payment_date
     FROM supplier_payments WHERE status = "void" AND paid_amount > 0
     ORDER BY id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (is_array($void)) {
    $matches = $repository->similarSupplierPayments(
        (int) $void['supplier_id'],
        (string) $void['currency'],
        (float) $void['paid_amount'],
        (string) $void['payment_date'],
        null,
        null,
        [(int) $void['branch_id']]
    );
    $assert(! in_array($void['payment_no'], array_column($matches, 'payment_no'), true),
        'A voided payment is not suggested as a live duplicate.');
}

$route = file_get_contents(BASE_PATH . '/public/index.php');
$globalView = file_get_contents(BASE_PATH . '/app/Views/reports/global_supplier_settlement.php');
$workspaceView = file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php');
$assert(str_contains((string) $route, "'/suppliers/payments/similar'"),
    'Authenticated similarity route is registered.');
$assert(substr_count((string) $globalView . (string) $workspaceView, 'data-supplier-duplicate-check-url') === 3,
    'Global, simple booking, and manual booking payment forms use the warning.');
