<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;

    if (! $passed) {
        $failures[] = $label;
    }
};

$workspaceView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$outputView = is_file(BASE_PATH . '/app/Views/workspace/output.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/output.php')
    : '';
$bookingRepository = is_file(BASE_PATH . '/app/Repositories/BookingRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/BookingRepository.php')
    : '';

$check(
    'Service sales person field is hidden but kept in markup',
    str_contains($workspaceView, 'legacy-field--sales" hidden aria-hidden="true"')
        && str_contains($workspaceView, '<span>Sales Person</span>')
);

$check(
    'Customer receipt output shows the booking user name',
    str_contains($outputView, '$receivedBy = trim')
        && str_contains($outputView, '<span>Received By</span>')
        && str_contains($outputView, '$receivedBy !== \'\' ? $receivedBy : \'Authorized Staff\'')
);

$check(
    'Booking repository loads created and updated user display names',
    str_contains($bookingRepository, 'AS created_by_name')
        && str_contains($bookingRepository, 'AS updated_by_name')
        && str_contains($bookingRepository, 'LEFT JOIN users created_user')
        && str_contains($bookingRepository, 'LEFT JOIN users updated_user')
);

if ($failures !== []) {
    echo PHP_EOL . 'Workspace salesperson receipt readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    return 1;
}

echo PHP_EOL . 'Workspace salesperson receipt readiness passed.' . PHP_EOL;
return 0;
