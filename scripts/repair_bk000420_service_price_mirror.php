<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', ['apply', 'actor-user-id::']);
$apply = array_key_exists('apply', $options);
$actorUserId = max(1, (int) ($options['actor-user-id'] ?? 1));
$bookingReference = 'BK-000420';
$expected = [
    'service_type' => 'visa',
    'currency' => 'AED',
    'cost_currency' => 'AED',
    'sale_price' => 490.00,
    'purchase_cost' => 380.00,
    'service_charge' => 370.00,
    'discount_amount' => 0.00,
    'vat' => 0.00,
    'final_sale_price' => 750.00,
];

$moneyEquals = static fn (mixed $actual, float $wanted): bool => abs((float) $actual - $wanted) <= 0.005;

$statement = $db->prepare(
    'SELECT bs.id,
            bs.booking_id,
            bs.branch_id,
            bs.line_reference,
            bs.service_type,
            bs.currency,
            bs.cost_currency,
            bs.pricing_exchange_rate,
            bs.pricing_rate_effective_date,
            bs.sale_price,
            bs.purchase_cost,
            bs.service_charge,
            bs.discount_amount,
            bs.vat,
            bs.final_sale_price,
            bs.net_profit_loss
     FROM booking_services bs
     INNER JOIN bookings b ON b.id = bs.booking_id
     WHERE b.booking_reference = :booking_reference
       AND bs.line_reference = "SV-001"
     LIMIT 1'
);
$statement->execute(['booking_reference' => $bookingReference]);
$service = $statement->fetch(PDO::FETCH_ASSOC);

echo 'BK-000420 non-air service price-mirror repair' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

if ($service === false) {
    fwrite(STDERR, '[ABORT] BK-000420 / SV-001 was not found.' . PHP_EOL);
    exit(1);
}

echo 'Current: ' . json_encode($service, JSON_UNESCAPED_SLASHES) . PHP_EOL;

$alreadyAligned = $moneyEquals($service['sale_price'], $expected['purchase_cost'])
    && $moneyEquals($service['purchase_cost'], $expected['purchase_cost'])
    && $moneyEquals($service['service_charge'], $expected['service_charge'])
    && $moneyEquals($service['final_sale_price'], $expected['final_sale_price']);
if ($alreadyAligned) {
    echo '[NO CHANGE] BK-000420 is already synchronized.' . PHP_EOL;
    exit(0);
}

$matchesExpectedCase = strtolower(trim((string) $service['service_type'])) === $expected['service_type']
    && strtoupper(trim((string) $service['currency'])) === $expected['currency']
    && strtoupper(trim((string) $service['cost_currency'])) === $expected['cost_currency']
    && $moneyEquals($service['sale_price'], $expected['sale_price'])
    && $moneyEquals($service['purchase_cost'], $expected['purchase_cost'])
    && $moneyEquals($service['service_charge'], $expected['service_charge'])
    && $moneyEquals($service['discount_amount'], $expected['discount_amount'])
    && $moneyEquals($service['vat'], $expected['vat'])
    && $moneyEquals($service['final_sale_price'], $expected['final_sale_price']);

if (! $matchesExpectedCase) {
    fwrite(STDERR, '[ABORT] Current values do not match the client-confirmed BK-000420 case; nothing was changed.' . PHP_EOL);
    exit(1);
}

$derivedInvoice = round(
    (float) $service['purchase_cost']
    + (float) $service['service_charge']
    + (float) $service['vat']
    - (float) $service['discount_amount'],
    2
);
if (! $moneyEquals($derivedInvoice, (float) $service['final_sale_price'])) {
    fwrite(STDERR, '[ABORT] Correct cost/charge components do not reproduce the current customer invoice.' . PHP_EOL);
    exit(1);
}

echo 'Planned correction: synchronize legacy sale_price mirror from AED 490.00 to AED 380.00.' . PHP_EOL;
echo 'Unaffected: AED 750.00 customer invoice, receipts, AED 380.00 supplier obligation, payments, and treasury.' . PHP_EOL;

if (! $apply) {
    echo 'Dry run complete. Re-run with --apply after confirming a production database backup.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();

try {
    (new \App\Services\ServiceWorkspaceService($app))->correctFinancials([
        'booking_id' => (int) $service['booking_id'],
        'service_id' => (int) $service['id'],
        'corrected_invoice_currency' => (string) $service['currency'],
        'corrected_cost_currency' => (string) $service['cost_currency'],
        'corrected_pricing_exchange_rate' => (string) $service['pricing_exchange_rate'],
        'corrected_pricing_rate_effective_date' => (string) $service['pricing_rate_effective_date'],
        'corrected_cost_basis' => (string) $service['purchase_cost'],
        'corrected_service_charge' => (string) $service['service_charge'],
        'corrected_discount_amount' => (string) $service['discount_amount'],
        'financial_correction_date' => date('Y-m-d'),
        'financial_correction_reason' => 'Synchronize legacy non-air cost mirror after confirmed financial correction',
        'financial_correction_note' => 'BK-000420 remains AED 750 customer invoice: AED 380 supplier cost plus AED 370 service charge.',
    ], $actorUserId, [(int) $service['branch_id']]);

    $statement->execute(['booking_reference' => $bookingReference]);
    $updated = $statement->fetch(PDO::FETCH_ASSOC);
    if ($updated === false
        || ! $moneyEquals($updated['sale_price'], 380.00)
        || ! $moneyEquals($updated['purchase_cost'], 380.00)
        || ! $moneyEquals($updated['service_charge'], 370.00)
        || ! $moneyEquals($updated['final_sale_price'], 750.00)) {
        throw new RuntimeException('Post-correction verification did not match the approved BK-000420 values.');
    }

    $db->commit();
    echo '[APPLIED] BK-000420 service values are synchronized through an audited financial correction.' . PHP_EOL;
    echo 'Updated: ' . json_encode($updated, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, '[ROLLED BACK] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
