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

$reportService = is_file(BASE_PATH . '/app/Services/ReportService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/ReportService.php')
    : '';
$reportRepository = is_file(BASE_PATH . '/app/Repositories/ReportRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php')
    : '';
$reportsView = is_file(BASE_PATH . '/app/Views/reports/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/index.php')
    : '';
$dashboardView = is_file(BASE_PATH . '/app/Views/dashboard/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/dashboard/index.php')
    : '';

$check(
    'Reminder Hub is registered as a report option',
    str_contains($reportService, "'reminder_hub' => 'Reminder Hub'")
);
$check(
    'Reminder Hub repository query exists',
    str_contains($reportRepository, 'function reminderHub')
        && str_contains($reportRepository, 'FROM booking_reminders brm')
);
$check(
    'Reminder Hub report mapping and filters exist in service layer',
    str_contains($reportService, "case 'reminder_hub':")
        && str_contains($reportService, 'reminderStatusOptions')
        && str_contains($reportService, 'reminderPriorityOptions')
        && str_contains($reportService, 'reminderTypeFilterOptions')
        && str_contains($reportService, 'reminderServiceTypeOptions')
        && str_contains($reportService, 'reminderHubReport')
        && str_contains($reportService, "customer_edit' => '1'")
);
$check(
    'Reports screen renders Reminder Hub filter controls, queue styling, and quick contact actions',
    str_contains($reportsView, "selectedReport === 'reminder_hub'")
        && str_contains($reportsView, 'name="reminder_status"')
        && str_contains($reportsView, 'name="reminder_priority"')
        && str_contains($reportsView, 'name="reminder_type"')
        && str_contains($reportsView, 'name="reminder_service_type"')
        && str_contains($reportsView, 'name="reminder_search"')
        && str_contains($reportsView, "'reminder_hub']")
        && str_contains($reportsView, 'reminder-column--title')
        && str_contains($reportsView, 'reminder-hub-table')
        && str_contains($reportsView, 'reminder-hub-action')
        && str_contains($reportsView, 'https://wa.me/')
        && str_contains($reportsView, 'reminder-contact-quicklink')
);
$check(
    'Reminder Hub filters are enforced in its repository query',
    str_contains($reportRepository, 'brm.priority = :reminder_priority')
        && str_contains($reportRepository, 'b.business_source_id = :reminder_business_source_id')
        && str_contains($reportRepository, '= :reminder_customer_name')
        && str_contains($reportRepository, 'LEFT JOIN business_sources bs_src')
);
$check(
    'Dashboard provides a direct Reminder Hub shortcut',
    str_contains($dashboardView, "/reports?report=reminder_hub")
);

if ($failures !== []) {
    echo PHP_EOL . 'Reminder Hub readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Reminder Hub readiness passed.' . PHP_EOL;
