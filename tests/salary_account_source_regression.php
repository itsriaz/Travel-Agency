<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Controllers\SalaryController;
use App\Repositories\BusinessSourceRepository;
use App\Repositories\SalaryRepository;
use App\Services\SalaryAccountMatcher;
use App\Services\SalaryAdminService;
use App\Services\SalaryOperationTraceService;
use App\Services\ReportService;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$keys = SalaryAccountMatcher::groupKeys([
    ['id' => 1, 'name' => 'ABUBAKAR PKR SALES'],
    ['id' => 2, 'name' => 'abubakar AED sales'],
    ['id' => 3, 'name' => 'Walking Client'],
    ['id' => 4, 'name' => 'ABUBAKAR AED SALES EXTRA'],
]);
$assert($keys[1] === $keys[2], 'Same account name with PKR/AED pairs in the snapshot');
$assert($keys[1] !== $keys[3] && $keys[1] !== $keys[4], 'Unrelated accounts are not paired');
$ambiguous = SalaryAccountMatcher::groupKeys([
    ['id' => 1, 'name' => 'A PKR Sales'],
    ['id' => 2, 'name' => 'A AED Sales'],
    ['id' => 3, 'name' => 'A AED Sales'],
]);
$assert($ambiguous[1] !== $ambiguous[2], 'Duplicate same-currency names do not silently combine');

$app = App::bootstrap(BASE_PATH);
$db = $app->get('db');
$accounts = (new BusinessSourceRepository($app))->activeOptions();
$account = current(array_filter($accounts, static fn (array $row): bool => (string) ($row['code'] ?? '') !== 'walking_client'));
$branchId = (int) $db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
$actorId = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if (! is_array($account) || $branchId <= 0 || $actorId <= 0) {
    throw new RuntimeException('Local salary regression needs an active account, branch and user.');
}

$accountId = (int) $account['id'];
$repository = new SalaryRepository($app);
$service = new SalaryAdminService($app);
$performanceRows = $repository->employeePerformanceRows([$branchId], date('Y-m-01'), date('Y-m-t'));
$assert(count(array_filter($performanceRows, static fn (array $row): bool => (int) ($row['business_source_id'] ?? 0) === $accountId)) > 0, 'Sales snapshot reads the account without a user link');
$accountGroupKeys = SalaryAccountMatcher::groupKeys($accounts);
$mappedPerformanceRows = (new ReflectionMethod(SalaryController::class, 'employeePerformanceRows'))
    ->invoke(new SalaryController($app), $performanceRows, $accountGroupKeys);
$selectedGroupKey = $accountGroupKeys[$accountId] ?? '';
$assert($selectedGroupKey !== '' && count(array_filter(
    $mappedPerformanceRows,
    static fn (array $row): bool => (string) ($row['account_group_key'] ?? '') === $selectedGroupKey
)) > 0, 'Selected salary account matches mapped sales snapshot rows');
$pairedGroupKeys = array_count_values(array_values($accountGroupKeys));
$pairedGroupKey = array_key_first(array_filter(
    $pairedGroupKeys,
    static fn (int $count, string $key): bool => $count >= 2 && str_starts_with($key, 'currency-name:'),
    ARRAY_FILTER_USE_BOTH
));
$pairedSnapshotIds = array_unique(array_map(
    static fn (array $row): int => (int) ($row['business_source_id'] ?? 0),
    array_filter($mappedPerformanceRows, static fn (array $row): bool => (string) ($row['account_group_key'] ?? '') === $pairedGroupKey)
));
$assert($pairedGroupKey !== null && count($pairedSnapshotIds) >= 2, 'PKR and AED account pair both appear in the mapped sales snapshot');
$auditBefore = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event_name LIKE 'salary.%'")->fetchColumn();
$db->beginTransaction();
try {
    $record = $service->record([
        'branch_id' => $branchId,
        'business_source_id' => $accountId,
        'salary_month' => '2099-12',
        'record_date' => '2099-12-01',
        'currency' => 'PKR',
        'basic_salary_amount' => 100,
        'bonus_amount' => 20,
    ], $actorId, [$branchId]);
    $recordId = (int) $record['salary_record_id'];
    $saved = $repository->findRecord($recordId, [$branchId]);
    $assert((int) ($saved['business_source_id'] ?? 0) === $accountId, 'Salary uses the selected account ID directly');
    $assert((float) ($saved['gross_debit_amount'] ?? 0) === 120.0, 'Manual bonus is included once');
    $listed = current(array_filter($repository->recordRows([$branchId]), static fn (array $row): bool => (int) ($row['id'] ?? 0) === $recordId));
    $assert(is_array($listed) && (string) ($listed['employee_name'] ?? '') === (string) $account['name'], 'Salary ledger displays its saved account without a link');

    $service->updateRecord([
        'salary_record_id' => $recordId,
        'branch_id' => $branchId,
        'business_source_id' => $accountId,
        'salary_month' => '2099-12',
        'record_date' => '2099-12-01',
        'currency' => 'PKR',
        'basic_salary_amount' => 110,
        'bonus_amount' => 20,
    ], $actorId, [$branchId]);
    $assert((float) ($repository->findRecord($recordId, [$branchId])['gross_debit_amount'] ?? 0) === 130.0, 'Salary edit retains account identity');

    $payment = $service->pay([
        'salary_record_id' => $recordId,
        'payment_date' => '2099-12-02',
        'payment_currency' => 'PKR',
        'paid_amount' => 0,
        'deduction_amount' => 10,
        'deduction_reason' => 'Regression test deduction',
    ], $actorId, [$branchId]);
    $assert((float) ($repository->findRecord($recordId, [$branchId])['outstanding_amount'] ?? 0) === 120.0, 'Salary credit still updates outstanding');

    $report = (new ReportService($app))->reportState([
        'report' => 'salary_report',
        'branch_id' => $branchId,
        'business_source_id' => $accountId,
        'date_from' => '2099-12-01',
        'date_to' => '2099-12-31',
    ], [$branchId], $actorId);
    $assert(count(array_filter($report['rows'] ?? [], static fn (array $row): bool => (int) ($row['salary_record_id'] ?? 0) === $recordId)) > 0, 'Salary report filters by account ID without a user link');

    $service->voidAll([
        'salary_record_id' => $recordId,
        'void_reason' => 'Regression test cleanup',
    ], $actorId, [$branchId]);
    $assert((string) ($repository->findRecord($recordId, [$branchId])['status'] ?? '') === 'void', 'Void All still voids the salary debit');
    $assert((string) ($repository->findPayment((int) $payment['salary_payment_id'], [$branchId])['status'] ?? '') === 'void', 'Void All still voids linked credits');

    $auditAfter = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event_name LIKE 'salary.%'")->fetchColumn();
    $assert($auditAfter - $auditBefore === 4, 'Every tested write has a transaction-bound audit entry');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$auditPersisted = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event_name LIKE 'salary.%'")->fetchColumn();
$assert($auditPersisted === $auditBefore, 'Regression test rolls back every salary and audit write');

try {
    SalaryOperationTraceService::run($app, 'salary.record.create', [
        'branch_id' => $branchId,
        'business_source_id' => 0,
        'currency' => 'PKR',
        'notes' => 'Do not log this private text',
    ], $actorId, [$branchId], static fn (): array => $service->record([
        'branch_id' => $branchId,
        'business_source_id' => 0,
        'currency' => 'PKR',
    ], $actorId, [$branchId]));
    throw new RuntimeException('Invalid salary account was accepted.');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'active account'), 'Invalid account is rejected before salary posting');
}
$tracePath = app_log_path('salary-operations-' . date('Ymd') . '.jsonl');
$lines = array_slice(file($tracePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -2);
$events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
$assert(count($events) === 2 && ($events[0]['status'] ?? '') === 'started' && ($events[1]['status'] ?? '') === 'rejected'
    && ($events[0]['trace_id'] ?? '') === ($events[1]['trace_id'] ?? ''), 'Rejected salary request has a paired diagnostic trace');
$assert(! str_contains(implode("\n", $lines), 'Do not log this private text'), 'Diagnostic trace excludes private free-text notes');
