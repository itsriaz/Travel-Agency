<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SalaryRepository;
use RuntimeException;
use Throwable;

final class SalaryOperationTraceService
{
    public static function run(App $app, string $action, array $input, int $actorId, array $branchIds, callable $operation): array
    {
        $traceId = bin2hex(random_bytes(12));
        $recordId = max(0, (int) ($input['salary_record_id'] ?? 0));
        $paymentId = max(0, (int) ($input['salary_payment_id'] ?? 0));
        $before = self::snapshot($app, $recordId, $paymentId, $branchIds);
        $common = [
            'time' => date('c'),
            'trace_id' => $traceId,
            'request_id' => app_request_id(),
            'action' => $action,
            'actor_user_id' => $actorId,
        ];
        self::write($common + [
            'status' => 'started',
            'submitted' => self::selectedFields($input),
            'before' => $before,
        ]);

        try {
            $result = $operation();
            if ((int) ($result['salary_record_id'] ?? 0) > 0) {
                $recordId = (int) $result['salary_record_id'];
            }
            if ((int) ($result['salary_payment_id'] ?? 0) > 0) {
                $paymentId = (int) $result['salary_payment_id'];
            }
            self::write(array_merge($common, [
                'time' => date('c'),
                'status' => 'committed',
                'result' => $result,
                'after' => self::snapshot($app, $recordId, $paymentId, $branchIds),
            ]));

            return $result;
        } catch (Throwable $error) {
            self::write(array_merge($common, [
                'time' => date('c'),
                'status' => $error instanceof RuntimeException ? 'rejected' : 'failed',
                'error' => $error instanceof RuntimeException
                    ? $error->getMessage()
                    : 'Unexpected salary operation failure.',
                'error_type' => get_class($error),
                'after' => self::snapshot($app, $recordId, $paymentId, $branchIds),
            ]));
            throw $error;
        }
    }

    private static function snapshot(App $app, int $recordId, int $paymentId, array $branchIds): array
    {
        try {
            $repository = new SalaryRepository($app);
            if (! $repository->tablesExist()) {
                return [];
            }

            return [
                'record' => $recordId > 0 ? self::selectedFields($repository->findRecord($recordId, $branchIds) ?? []) : null,
                'payment' => $paymentId > 0 ? self::selectedFields($repository->findPayment($paymentId, $branchIds) ?? []) : null,
            ];
        } catch (Throwable $error) {
            return ['snapshot_error_type' => get_class($error)];
        }
    }

    private static function selectedFields(array $row): array
    {
        $keys = [
            'id', 'salary_record_id', 'salary_payment_id', 'business_source_id', 'branch_id',
            'salary_month', 'record_date', 'payment_date', 'currency', 'payment_currency',
            'basic_salary_amount', 'bonus_amount', 'gross_debit_amount', 'paid_credit_amount',
            'deduction_credit_amount', 'outstanding_amount', 'paid_amount', 'source_paid_amount',
            'deduction_amount', 'exchange_rate_to_salary', 'treasury_account_id', 'payment_method',
            'status',
        ];
        $selected = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                $selected[$key] = substr((string) $row[$key], 0, 80);
            }
        }
        return $selected;
    }

    private static function write(array $entry): void
    {
        try {
            $path = app_log_path('salary-operations-' . date('Ymd') . '.jsonl');
            $directory = dirname($path);
            if (! is_dir($directory)) {
                @mkdir($directory, 0770, true);
            }
            $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Salary diagnostic trace is not writable.');
            }
        } catch (Throwable $error) {
            app_write_log('salary_operation_trace.write_failed', $error->getMessage(), [
                'trace_id' => $entry['trace_id'] ?? null,
                'action' => $entry['action'] ?? null,
            ]);
        }
    }
}
