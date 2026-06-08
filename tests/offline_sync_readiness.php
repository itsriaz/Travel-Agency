<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$offlineController = is_file(BASE_PATH . '/app/Controllers/OfflineController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/OfflineController.php')
    : '';
$offlineService = is_file(BASE_PATH . '/app/Services/OfflineWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/OfflineWorkspaceService.php')
    : '';
$workspaceView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$workspaceScript = is_file(BASE_PATH . '/public/assets/js/workspace.js')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js')
    : '';

$check('Offline snapshot route is registered', str_contains($publicIndex, "/offline/snapshot"));
$check('Offline ping route is registered', str_contains($publicIndex, "/offline/ping"));
$check('Offline draft sync route is registered', str_contains($publicIndex, "/offline/drafts/sync"));
$check('Offline controller exposes ping action', str_contains($offlineController, 'function ping'));
$check('Offline controller exposes snapshot action', str_contains($offlineController, 'function snapshot'));
$check('Offline controller exposes sync action', str_contains($offlineController, 'function syncDrafts'));
$check('Offline service allows traveler drafts', str_contains($offlineService, "'traveler.create'"));
$check('Offline service blocks booking drafts', ! str_contains($offlineService, "'booking.create'"));
$check('Offline service blocks service drafts', ! str_contains($offlineService, "'service.create'"));
$check('Workspace exposes offline snapshot URL', str_contains($workspaceView, 'data-offline-snapshot-url'));
$check('Workspace exposes offline ping URL', str_contains($workspaceView, 'data-offline-ping-url'));
$check('Workspace exposes offline sync URL', str_contains($workspaceView, 'data-offline-sync-url'));
$check('Workspace exposes offline queue controls', str_contains($workspaceView, 'data-offline-action="sync"'));
$check('Workspace exposes toggleable offline queue preview', str_contains($workspaceView, 'data-offline-queue-preview'));
$check('Workspace does not expose offline booking draft control', ! str_contains($workspaceView, 'data-offline-action="save-booking"'));
$check('Workspace exposes offline search results panel', str_contains($workspaceView, 'data-offline-search-results'));
$check('Workspace exposes offline edit lock notice', str_contains($workspaceView, 'data-offline-edit-lock'));
$check('Workspace script can fetch offline snapshots', str_contains($workspaceScript, 'const fetchOfflineSnapshot = async () =>'));
$check('Workspace script can toggle offline queue preview', str_contains($workspaceScript, 'const toggleOfflineQueuePreview = () =>'));
$check('Workspace script renders offline queue preview', str_contains($workspaceScript, 'const renderOfflineQueuePreview = () =>'));
$check('Workspace script does not queue offline booking drafts', ! str_contains($workspaceScript, 'const saveBookingDraftOffline = () =>'));
$check('Workspace script does not collect offline service drafts', ! str_contains($workspaceScript, 'const collectOfflineServiceDraftPayload = () =>'));
$check('Workspace script detects browser offline state', str_contains($workspaceScript, 'window.navigator.onLine === false'));
$check('Workspace script verifies server reachability for offline mode', str_contains($workspaceScript, 'const refreshWorkspaceConnectivity = async () =>'));
$check('Workspace script suppresses autosave while offline', str_contains($workspaceScript, 'browserIsOffline()'));
$check('Workspace script toggles customer save buttons by connection state', str_contains($workspaceScript, 'const syncNewCustomerSaveMode = () =>'));
$check('Workspace script locks booking/service/payment edits while offline', str_contains($workspaceScript, 'const syncOfflineEditLockMode = () =>'));
$check('Workspace script prevents locked offline form submits', str_contains($workspaceScript, 'const preventOfflineLockedSubmit = (event) =>'));
$check('Workspace script preserves previous disabled states during offline lock', str_contains($workspaceScript, 'offlinePreviousDisabled'));
$check('Workspace hides offline customer save by default', str_contains($workspaceView, 'data-offline-action="save-customer" hidden'));
$check('Workspace script can queue offline traveler drafts', str_contains($workspaceScript, 'const saveTravelerDraftOffline = () =>'));
$check('Workspace script can sync offline queue', str_contains($workspaceScript, 'const syncOfflineQueue = async () =>'));
$check('Workspace script can search offline snapshot bookings', str_contains($workspaceScript, 'const searchOfflineSnapshotBookings = (query) =>'));
$check('Workspace script integrates offline snapshot into customer lookup', str_contains($workspaceScript, 'const integrateOfflineSnapshot = (snapshot) =>'));
$check('Workspace script labels snapshot-sourced records', str_contains($workspaceScript, "const snapshotSourceLabel = 'Snapshot'"));
$check('Workspace script shows snapshot freshness age', str_contains($workspaceScript, 'const formatOfflineAge = (value) =>'));

if ($failures !== []) {
    echo PHP_EOL . 'Offline sync readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Offline sync readiness passed.' . PHP_EOL;
