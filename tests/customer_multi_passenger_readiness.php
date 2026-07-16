<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$workspaceController = file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php') ?: '';
$travelerRepository = file_get_contents(BASE_PATH . '/app/Repositories/TravelerRepository.php') ?: '';
$serviceWorkspaceService = file_get_contents(BASE_PATH . '/app/Services/ServiceWorkspaceService.php') ?: '';
$paymentFoundation = file_get_contents(BASE_PATH . '/app/Services/CustomerPaymentFoundationService.php') ?: '';
$accountStatement = file_get_contents(BASE_PATH . '/app/Views/workspace/output.php') ?: '';
$reportRepository = file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php') ?: '';
$reportService = file_get_contents(BASE_PATH . '/app/Services/ReportService.php') ?: '';
$receiptWorkspaceService = file_get_contents(BASE_PATH . '/app/Services/CustomerReceiptWorkspaceService.php') ?: '';
$workspaceJs = file_get_contents(BASE_PATH . '/public/assets/js/workspace.js') ?: '';
$stationView = file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php') ?: '';

$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Customer multi-passenger readiness' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$check(
    'Existing booking resolves the saved lead customer before any query-string customer',
    str_contains($workspaceController, "if ((int) (\$currentBookingRecord['id'] ?? 0) > 0 && \$currentLeadTravelerId > 0)")
);
$check(
    'Additional passenger attachment preserves manual customer identity when no traveler-profile lead exists',
    str_contains($travelerRepository, 'synchronizeLeadTraveler($bookingId, false)')
        && str_contains($travelerRepository, 'if ($leadTravelerId <= 0 && ! $clearWhenMissing)')
);
$check(
    'Service passenger attachment restores the saved lead traveler relationship first',
    str_contains($serviceWorkspaceService, 'travelerAttachedToBooking($bookingId, $leadTravelerId)')
        && str_contains($serviceWorkspaceService, '$role = $leadTravelerId > 0 && $travelerId === $leadTravelerId')
);
$check(
    'Every service receivable carries its passenger name into customer payment output',
    str_contains($paymentFoundation, '\'passengerName\' => $servicePassengerDirectory[$lineReference] ?? \'\'')
);
$check(
    'Account statement invoice exposure displays the passenger column',
    str_contains($accountStatement, '<th>Passenger</th>')
        && str_contains($accountStatement, "['passengerName']")
);
$check(
    'Customer reports load the stable lead traveler identifier',
    substr_count($reportRepository, 'b.lead_traveler_id,') >= 2
);
$check(
    'Customer report drilldown groups by stable lead traveler identifier',
    str_contains($reportService, '\'id:\' . $customerTravelerId')
);
$check(
    'Receipt workspace supports whole-invoice and passenger-specific allocation scopes',
    str_contains($receiptWorkspaceService, "private const ALLOWED_RECEIPT_SCOPES = ['whole_invoice', 'passenger_specific']")
        && str_contains($receiptWorkspaceService, 'resolveReceiptAllocationTargetId(')
);
$check(
    'Workspace payment panel exposes passenger-specific receipt targeting controls',
    str_contains($stationView, 'name="receipt_scope"')
        && str_contains($stationView, 'name="target_receivable_item_id"')
);
$check(
    'Workspace frontend rebuilds current-booking passenger receivable targets for payment scope',
    str_contains($workspaceJs, 'const currentBookingReceivableTargets = (currency = \'\') => {')
        && str_contains($workspaceJs, 'const syncReceiptScopeTargets = () => {')
);

if ($failures !== []) {
    echo PHP_EOL . 'Customer multi-passenger readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Customer multi-passenger readiness passed.' . PHP_EOL;
return 0;
