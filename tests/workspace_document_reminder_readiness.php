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

$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$workspaceController = is_file(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    : '';
$workspaceView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$workspaceIndexView = is_file(BASE_PATH . '/app/Views/workspace/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/index.php')
    : '';
$documentService = is_file(BASE_PATH . '/app/Services/DocumentWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/DocumentWorkspaceService.php')
    : '';
$reminderService = is_file(BASE_PATH . '/app/Services/ReminderWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/ReminderWorkspaceService.php')
    : '';
$reminderRepository = is_file(BASE_PATH . '/app/Repositories/BookingReminderRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/BookingReminderRepository.php')
    : '';

$check('Document upload route is registered', str_contains($publicIndex, "/workspace/documents/upload"));
$check('Document revoke route is registered', str_contains($publicIndex, "/workspace/documents/revoke"));
$check('Document download route is registered', str_contains($publicIndex, "/workspace/documents/download"));
$check('Reminder save route is registered', str_contains($publicIndex, "/workspace/reminders/save"));
$check('Reminder complete route is registered', str_contains($publicIndex, "/workspace/reminders/complete"));
$check('Reminder dismiss route is registered', str_contains($publicIndex, "/workspace/reminders/dismiss"));

$check(
    'Workspace controller exposes document and reminder actions',
    str_contains($workspaceController, 'function uploadDocument')
        && str_contains($workspaceController, 'function revokeDocument')
        && str_contains($workspaceController, 'function downloadDocument')
        && str_contains($workspaceController, 'function saveReminder')
        && str_contains($workspaceController, 'function completeReminder')
        && str_contains($workspaceController, 'function dismissReminder')
);
$check(
    'Document actions return users to the documents dock',
    str_contains($workspaceController, '#dock-panel-documents')
);
$check(
    'Reminder actions return users to the reminders dock',
    str_contains($workspaceController, '#dock-panel-reminders')
);

$check(
    'Workspace exposes live document upload and register controls',
    str_contains($workspaceView, 'data-documents-upload-form')
        && str_contains($workspaceView, 'data-documents-pending-message')
        && str_contains($workspaceView, 'workspace-document-target-map')
        && str_contains($workspaceView, '/workspace/documents/revoke')
        && str_contains($workspaceView, 'Upload Document')
);
$check(
    'Workspace exposes reminder editing state and reminder options',
    str_contains($workspaceIndexView, 'reminderTypeOptions')
        && str_contains($workspaceIndexView, 'reminderChannels')
        && str_contains($workspaceController, 'reminder_edit')
);
$check(
    'Workspace renders a visible reminders panel with manual form, open/closed lists, and alert actions',
    str_contains($workspaceView, 'id="dock-panel-reminders"')
        && str_contains($workspaceView, 'data-reminder-form')
        && str_contains($workspaceView, 'data-reminder-focus="task"')
        && str_contains($workspaceView, 'url(\'/workspace/reminders/save\')')
        && str_contains($workspaceView, 'url(\'/workspace/reminders/complete\')')
        && str_contains($workspaceView, 'url(\'/workspace/reminders/dismiss\')')
        && str_contains($workspaceView, 'OPEN REMINDERS')
        && str_contains($workspaceView, 'CLOSED REMINDERS')
        && str_contains($workspaceView, 'RECEIVABLE ALERTS')
        && str_contains($workspaceView, 'data-receivable-alert-table')
        && str_contains($workspaceView, 'data-reminder-closed-table')
);

$check(
    'Document service audits upload, replacement, revoke, and download',
    str_contains($documentService, "document.uploaded")
        && str_contains($documentService, "document.replaced")
        && str_contains($documentService, "document.revoked")
        && str_contains($documentService, "document.downloaded")
);
$check(
    'Document service enforces secure file validation and linked-target compatibility',
    str_contains($documentService, 'Only PDF, JPG, JPEG, PNG, and WEBP files are allowed.')
        && str_contains($documentService, 'The uploaded file extension does not match its detected file type.')
        && str_contains($documentService, 'This document type cannot be linked to the selected record.')
);

$check(
    'Reminder service audits create, update, complete, and dismiss actions',
    str_contains($reminderService, "reminder.created")
        && str_contains($reminderService, "reminder.updated")
        && str_contains($reminderService, "reminder.' . \$status")
        && str_contains($reminderService, "return \$this->transitionReminder((int) (\$input['reminder_id'] ?? 0), 'completed'")
        && str_contains($reminderService, "return \$this->transitionReminder((int) (\$input['reminder_id'] ?? 0), 'dismissed'")
);
$check(
    'Reminder service protects system-generated reminders and keeps system upserts active',
    str_contains($reminderService, 'System-generated reminders cannot be edited manually.')
        && str_contains($reminderService, 'upsertSystemReminder')
        && str_contains($reminderService, 'completeInactiveSystemReminders')
);
$check(
    'Reminder repository persists lifecycle transitions',
    str_contains($reminderRepository, 'function createReminder')
        && str_contains($reminderRepository, 'function updateReminder')
        && str_contains($reminderRepository, 'function markReminderStatus')
        && str_contains($reminderRepository, "in_array(\$existingStatus, ['completed', 'dismissed'], true)")
        && str_contains($reminderRepository, 'AND system_generated = 1')
        && str_contains($reminderRepository, 'AND status IN ("open", "due")')
);

if ($failures !== []) {
    echo PHP_EOL . 'Workspace document/reminder readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Workspace document/reminder readiness passed.' . PHP_EOL;
