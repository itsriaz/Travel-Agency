<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;

    if (! $passed) {
        $failures[] = $label;
    }
};

$script = is_file(BASE_PATH . '/scripts/reset_dummy_business_data.php')
    ? (string) file_get_contents(BASE_PATH . '/scripts/reset_dummy_business_data.php')
    : '';

$check(
    'Dummy reset script refuses production',
    str_contains($script, 'app_is_production()')
        && str_contains($script, 'Refusing to reset dummy data while APP_ENV=production.')
);

$check(
    'Dummy reset script clears customer and supplier master rows',
    str_contains($script, "'travelers'")
        && str_contains($script, "'suppliers'")
);

$check(
    'Dummy reset script clears financial and treasury rows',
    str_contains($script, "'customer_receipts'")
        && str_contains($script, "'supplier_payments'")
        && str_contains($script, "'supplier_advances'")
        && str_contains($script, "'treasury_transactions'")
        && str_contains($script, "'treasury_accounts'")
        && str_contains($script, "'journal_entry_lines'")
        && str_contains($script, "'journal_entries'")
);

$check(
    'Dummy reset script clears newer refund detail rows before parent reset',
    str_contains($script, "'booking_service_refund_details'")
        && strpos($script, "'booking_service_refund_details'") < strpos($script, "'booking_service_events'")
);

$check(
    'Dummy reset script resets business number sequences',
    str_contains($script, "'booking.reference.sequence'")
        && str_contains($script, "'customer.receipt.sequence'")
        && str_contains($script, "'supplier.code.sequence'")
        && str_contains($script, "'supplier.payment.sequence'")
);

if ($failures !== []) {
    echo PHP_EOL . 'Dummy reset readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Dummy reset readiness passed.' . PHP_EOL;
