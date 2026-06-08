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

$controlController = is_file(BASE_PATH . '/app/Controllers/ControlController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/ControlController.php')
    : '';
$accountingView = is_file(BASE_PATH . '/app/Views/control/accounting_engine_admin.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/control/accounting_engine_admin.php')
    : '';
$legacyAccountingViewExists = is_file(BASE_PATH . '/app/Views/control/accounting_engine.php');

$check(
    'Accounting engine no longer hardcodes BK-000001 preview dependency',
    ! str_contains($controlController, 'BK-000001')
);
$check(
    'Accounting engine controller accepts live preview booking selection input',
    str_contains($controlController, "preview_q")
        && str_contains($controlController, "preview_booking_id")
);
$check(
    'Accounting engine controller loads booking previews from real repositories',
    str_contains($controlController, 'new BookingRepository')
        && str_contains($controlController, 'new BookingServiceRepository')
        && str_contains($controlController, 'searchBookings')
        && str_contains($controlController, 'findBookingById')
        && str_contains($controlController, 'servicesByBookingReference')
);
$check(
    'Accounting engine view exposes booking preview selector UI',
    str_contains($accountingView, 'Booking Accounting Preview')
        && str_contains($accountingView, 'Choose a booking to preview')
        && str_contains($accountingView, 'Show Accounting')
        && str_contains($accountingView, 'Select a live booking to preview its posted accounting')
);
$check(
    'Accounting engine summary cards use business-friendly wording',
    str_contains($accountingView, 'Accounting Setup Sections')
        && str_contains($accountingView, 'Locked System Setup')
        && str_contains($accountingView, 'Posted Journal Lines')
);
$check(
    'Accounting engine duplicate legacy view has been removed',
    $legacyAccountingViewExists === false
);

if ($failures !== []) {
    echo PHP_EOL . 'Accounting engine readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Accounting engine readiness passed.' . PHP_EOL;
