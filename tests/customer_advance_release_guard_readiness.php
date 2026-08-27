<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
};

echo 'Customer advance release guard readiness' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$repository = is_file(BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php')
    : '';
$repairScript = is_file(BASE_PATH . '/scripts/release_customer_advance_auto_allocations.php')
    ? (string) file_get_contents(BASE_PATH . '/scripts/release_customer_advance_auto_allocations.php')
    : '';

$methodStart = strpos($repository, 'public function releaseSpecificReceiptAllocations');
$methodEnd = strpos($repository, 'public function linkedPartyOffsetAmountForReceivableItem', $methodStart !== false ? $methodStart : 0);
$methodSource = $methodStart !== false && $methodEnd !== false
    ? substr($repository, $methodStart, $methodEnd - $methodStart)
    : '';

$check(
    'Receipt release helper enforces customer-advance purpose itself',
    str_contains($methodSource, "receipt_purpose")
        && str_contains($methodSource, "!== 'customer_advance'")
        && str_contains($methodSource, 'Only customer advance receipts can release customer advance allocations.')
);

$check(
    'Repair script still pre-filters customer advances before calling the helper',
    str_contains($repairScript, "receipt_purpose")
        && str_contains($repairScript, "!== 'customer_advance'")
        && str_contains($repairScript, 'releaseSpecificReceiptAllocations(')
);

echo PHP_EOL;
echo empty($failures)
    ? 'Customer advance release guard readiness passed.' . PHP_EOL
    : 'Failures:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;

return empty($failures) ? 0 : 1;
