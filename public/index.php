<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ControlController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\OfflineController;
use App\Controllers\ReportsController;
use App\Controllers\SecurityController;
use App\Controllers\TreasuryController;
use App\Controllers\WorkspaceController;
use App\Core\App;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\FinancialAdminMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PasswordChangeRequiredMiddleware;
use App\Middleware\SuperAdminMiddleware;
use App\Middleware\TwoFactorSetupRequiredMiddleware;
use App\Middleware\TwoFactorVerifiedMiddleware;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
app_send_security_headers();
$router = new Router($app);

$router->get('/health', [HealthController::class, 'show']);
$router->get('/', [DashboardController::class, 'index'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/login', [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
$router->post('/login', [AuthController::class, 'login'], [GuestMiddleware::class]);
$router->get('/forgot-password', [AuthController::class, 'showForgotPassword'], [GuestMiddleware::class]);
$router->post('/forgot-password', [AuthController::class, 'forgotPassword'], [GuestMiddleware::class]);
$router->get('/reset-password', [AuthController::class, 'showResetPassword'], [GuestMiddleware::class]);
$router->post('/reset-password', [AuthController::class, 'resetPassword'], [GuestMiddleware::class]);
$router->get('/force-password-change', [AuthController::class, 'showForcePasswordChange'], [AuthMiddleware::class]);
$router->post('/force-password-change', [AuthController::class, 'forcePasswordChange'], [AuthMiddleware::class]);
$router->get('/2fa/setup', [AuthController::class, 'showTwoFactorSetup'], [AuthMiddleware::class]);
$router->post('/2fa/setup', [AuthController::class, 'completeTwoFactorSetup'], [AuthMiddleware::class]);
$router->get('/2fa/verify', [AuthController::class, 'showTwoFactorVerify'], [AuthMiddleware::class]);
$router->post('/2fa/verify', [AuthController::class, 'verifyTwoFactor'], [AuthMiddleware::class]);
$router->get('/2fa/recovery-codes', [AuthController::class, 'showRecoveryCodes'], [AuthMiddleware::class]);
$router->get('/security', [SecurityController::class, 'settings'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/security/trusted-devices/revoke', [SecurityController::class, 'revokeTrustedDevice'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/security/logout-all-devices', [SecurityController::class, 'logoutAllDevices'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/security/2fa/re-enroll', [SecurityController::class, 'reEnrollTwoFactor'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/security/backup-codes/regenerate', [SecurityController::class, 'regenerateBackupCodes'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/admin/security', [SecurityController::class, 'adminPanel'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/master-data', [ControlController::class, 'masterData'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/accounting-engine', [ControlController::class, 'accountingEngine'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/treasury/accounts', [TreasuryController::class, 'accounts'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/expenses', [ControlController::class, 'expenses'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/expenses/attachment/download', [ControlController::class, 'downloadExpenseAttachment'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/reports', [ReportsController::class, 'index'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/reports/export.csv', [ReportsController::class, 'exportCsv'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/reports/supplier-prepaid-receipt', [ReportsController::class, 'supplierPrepaidReceipt'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/master-data/save', [ControlController::class, 'saveMasterData'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/master-data/delete', [ControlController::class, 'deleteMasterData'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/accounting-engine/save', [ControlController::class, 'saveAccountingEngine'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/treasury/accounts/save', [TreasuryController::class, 'saveAccount'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/accounting-engine/delete', [ControlController::class, 'deleteAccountingEngine'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/expenses/save', [ControlController::class, 'saveExpenses'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/expenses/delete', [ControlController::class, 'deleteExpenses'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/admin/security/force-password-reset', [SecurityController::class, 'adminForcePasswordReset'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/admin/security/reset-2fa', [SecurityController::class, 'adminResetTwoFactor'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/admin/security/revoke-trusted-devices', [SecurityController::class, 'adminRevokeTrustedDevices'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/admin/security/role-branch-access', [SecurityController::class, 'adminUpdateRoleBranchAccess'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
$router->get('/workspace', [WorkspaceController::class, 'index'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/save', [WorkspaceController::class, 'save'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/autosave/invoice', [WorkspaceController::class, 'autosaveInvoice'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/autosave/service', [WorkspaceController::class, 'autosaveService'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/workspace/customers/dues-finder', [WorkspaceController::class, 'customerDuesFinder'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/workspace/suppliers/history-finder', [WorkspaceController::class, 'supplierHistoryFinder'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/register', [WorkspaceController::class, 'registerSupplier'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/travelers/save', [WorkspaceController::class, 'saveTraveler'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/travelers/attach', [WorkspaceController::class, 'attachTraveler'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/travelers/remove', [WorkspaceController::class, 'removeTraveler'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/services/save', [WorkspaceController::class, 'saveService'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/services/cancel', [WorkspaceController::class, 'cancelService'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->post('/workspace/services/cancellation-financials', [WorkspaceController::class, 'settleCancellationFinancials'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->post('/workspace/services/refund', [WorkspaceController::class, 'refundService'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->post('/workspace/services/reissue', [WorkspaceController::class, 'reissueService'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->post('/workspace/services/deactivate', [WorkspaceController::class, 'deactivateService'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->get('/suppliers/advances/available', [WorkspaceController::class, 'supplierAvailableAdvance'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/payments/receipts/save', [WorkspaceController::class, 'saveReceipt'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/payments/exchange-rate/save', [WorkspaceController::class, 'saveExchangeRate'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/payments/receipts/void', [WorkspaceController::class, 'voidReceipt'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->post('/workspace/payments/receipts/metadata-save', [WorkspaceController::class, 'updateReceiptMetadata'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/payments/allocations/save', [WorkspaceController::class, 'allocateReceipt'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/payments/save', [WorkspaceController::class, 'saveSupplierPayment'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/payments/simple-save', [WorkspaceController::class, 'saveSimplePostpaidSupplierPayment'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/payments/metadata-save', [WorkspaceController::class, 'updateSupplierPaymentMetadata'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/payments/allocate', [WorkspaceController::class, 'allocateSupplierPayment'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/payments/void', [WorkspaceController::class, 'voidSupplierPayment'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class, FinancialAdminMiddleware::class]);
$router->post('/workspace/suppliers/advances/save', [WorkspaceController::class, 'saveSupplierAdvance'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/suppliers/advances/save', [WorkspaceController::class, 'saveGlobalSupplierAdvance'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/suppliers/advances/apply', [WorkspaceController::class, 'applySupplierAdvance'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/documents/upload', [WorkspaceController::class, 'uploadDocument'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/documents/revoke', [WorkspaceController::class, 'revokeDocument'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/workspace/documents/download', [WorkspaceController::class, 'downloadDocument'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/reminders/save', [WorkspaceController::class, 'saveReminder'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/reminders/complete', [WorkspaceController::class, 'completeReminder'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/workspace/reminders/dismiss', [WorkspaceController::class, 'dismissReminder'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/workspace/output', [WorkspaceController::class, 'output'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->get('/offline/snapshot', [OfflineController::class, 'snapshot'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);
$router->post('/offline/drafts/sync', [OfflineController::class, 'syncDrafts'], [AuthMiddleware::class, PasswordChangeRequiredMiddleware::class, TwoFactorSetupRequiredMiddleware::class, TwoFactorVerifiedMiddleware::class]);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    app_path(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')
);
