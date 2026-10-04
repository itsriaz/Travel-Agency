<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$service = (new ReflectionClass(\App\Services\ServiceWorkspaceService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'preserveLockedSectionAFields');

$posted = [
    'ticket_fare' => '999',
    'other_fare' => '999',
    'spyi_amount' => '999',
    'yq_amount' => '999',
    'vat_input' => '999',
    'soto_fare' => '999',
    'aq_yr_pk_amount' => '999',
    'oth_amount' => '999',
    'taxes' => '999',
    'sale_price' => '1351',
    'cost_currency' => 'AED',
];
$stored = [
    'fare' => '1.25',
    'other_fare' => '2.25',
    'spyi_amount' => '3.25',
    'yq_amount' => '4.25',
    'vat_input' => '5.25',
    'soto_fare' => '6.25',
    'aq_yr_pk_amount' => '7.25',
    'oth_amount' => '8.25',
    'taxes' => '9.25',
];
$saved = $method->invoke($service, $posted, $stored);
foreach (['ticket_fare' => 'fare', 'other_fare' => 'other_fare', 'spyi_amount' => 'spyi_amount',
    'yq_amount' => 'yq_amount', 'vat_input' => 'vat_input', 'soto_fare' => 'soto_fare',
    'aq_yr_pk_amount' => 'aq_yr_pk_amount', 'oth_amount' => 'oth_amount', 'taxes' => 'taxes'] as $postedField => $storedField) {
    if ($saved[$postedField] !== $stored[$storedField]) {
        throw new RuntimeException("Existing {$postedField} value was not preserved.");
    }
}
if ($saved['sale_price'] !== '1351' || $saved['cost_currency'] !== 'AED') {
    throw new RuntimeException('Market Fare or Cost Currency was unexpectedly locked.');
}

$new = $method->invoke($service, $posted, null);
foreach ($stored as $storedField => $_) {
    $postedField = $storedField === 'fare' ? 'ticket_fare' : $storedField;
    if ($new[$postedField] !== '0') {
        throw new RuntimeException("New {$postedField} must start at zero.");
    }
}
if ($new['sale_price'] !== '1351' || $new['cost_currency'] !== 'AED') {
    throw new RuntimeException('Market Fare or Cost Currency was unexpectedly locked on new services.');
}

echo "Section A lock preserves historical fields and leaves Market Fare and Cost Currency editable.\n";
