<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Authorization;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Repositories\BusinessSourceRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\SalaryRepository;
use App\Repositories\TreasuryRepository;
use App\Services\SalaryAccountMatcher;
use App\Services\SalaryAdminService;
use App\Services\SalaryOperationTraceService;
use RuntimeException;

final class SalaryController extends BaseController
{
    public function index(): string
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $repository = new SalaryRepository($this->app);
        $masterData = new MasterDataRepository($this->app);
        $activeTab = (string) ($_GET['tab'] ?? 'record');
        if (! in_array($activeTab, ['record', 'pay'], true)) {
            $activeTab = 'record';
        }

        $recordRows = $repository->recordRows($accessibleBranchIds);
        $paymentRows = $repository->paymentRows($accessibleBranchIds);
        $openRecordRows = $repository->openRecordRows($accessibleBranchIds);
        $accountRows = (new BusinessSourceRepository($this->app))->activeOptions();
        $accountGroupKeys = SalaryAccountMatcher::groupKeys($accountRows);
        $employeePerformanceRows = $this->employeePerformanceRows(
            $repository->employeePerformanceRows(
                $accessibleBranchIds,
                date('Y-m-01'),
                date('Y-m-t')
            ),
            $accountGroupKeys
        );
        $editingRecordId = max(0, (int) ($_GET['edit_record_id'] ?? 0));
        $editingPaymentId = max(0, (int) ($_GET['edit_payment_id'] ?? 0));
        $branchOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) ($row['id'] ?? ''),
                'label' => trim((string) ($row['name'] ?? '') . (! empty($row['city']) ? ' - ' . (string) $row['city'] : '')),
            ],
            array_values(array_filter(
                $masterData->rows('branches'),
                static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $accessibleBranchIds, true)
            ))
        );
        $accountOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) ($row['id'] ?? ''),
                'label' => (string) ($row['name'] ?? 'Account'),
                'group_key' => (string) ($accountGroupKeys[(int) ($row['id'] ?? 0)] ?? ''),
            ],
            $accountRows
        );
        $editingRecord = null;
        foreach ($recordRows as $row) {
            if ((int) ($row['id'] ?? 0) !== $editingRecordId) {
                continue;
            }

            $editingRecord = [
                'id' => (int) ($row['id'] ?? 0),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'business_source_id' => (int) ($row['business_source_id'] ?? 0),
                'salary_month' => date('Y-m', strtotime((string) ($row['salary_month'] ?? date('Y-m-01')))),
                'record_date' => (string) ($row['record_date'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'basic_salary_amount' => number_format((float) ($row['basic_salary_amount'] ?? 0), 2, '.', ''),
                'bonus_amount' => number_format((float) ($row['bonus_amount'] ?? 0), 2, '.', ''),
                'customer_penalty_profit_amount' => number_format((float) ($row['customer_penalty_profit_amount'] ?? 0), 2, '.', ''),
                'notes' => (string) ($row['notes'] ?? ''),
            ];
            break;
        }
        $editingPayment = null;
        foreach ($paymentRows as $row) {
            if ((int) ($row['id'] ?? 0) !== $editingPaymentId) {
                continue;
            }

            $editingPayment = [
                'id' => (int) ($row['id'] ?? 0),
                'salary_record_id' => (int) ($row['salary_record_id'] ?? 0),
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'record_currency' => (string) ($row['record_currency'] ?? $row['currency'] ?? 'PKR'),
                'payment_currency' => (string) ($row['payment_currency'] ?? $row['currency'] ?? 'PKR'),
                'payment_method' => (string) ($row['payment_method'] ?? ''),
                'treasury_account_id' => (int) ($row['treasury_account_id'] ?? 0),
                'paid_amount' => number_format((float) ($row['source_paid_amount'] ?? $row['paid_amount'] ?? 0), 2, '.', ''),
                'salary_paid_amount' => number_format((float) ($row['paid_amount'] ?? 0), 2, '.', ''),
                'exchange_rate_to_salary' => (string) (($row['exchange_rate_to_salary'] ?? null) !== null ? number_format((float) $row['exchange_rate_to_salary'], 8, '.', '') : ''),
                'deduction_amount' => number_format((float) ($row['deduction_amount'] ?? 0), 2, '.', ''),
                'deduction_reason' => (string) ($row['deduction_reason'] ?? ''),
                'reference_number' => (string) ($row['reference_number'] ?? ''),
                'remarks' => (string) ($row['remarks'] ?? ''),
                'status' => (string) ($row['status'] ?? 'posted'),
            ];
            break;
        }
        if ($editingPayment !== null && $editingRecord === null) {
            foreach ($recordRows as $linkedRecord) {
                if ((int) ($linkedRecord['id'] ?? 0) !== (int) ($editingPayment['salary_record_id'] ?? 0)) {
                    continue;
                }
                $editingRecord = [
                    'id' => (int) ($linkedRecord['id'] ?? 0),
                    'branch_id' => (int) ($linkedRecord['branch_id'] ?? 0),
                    'business_source_id' => (int) ($linkedRecord['business_source_id'] ?? 0),
                    'salary_month' => date('Y-m', strtotime((string) ($linkedRecord['salary_month'] ?? date('Y-m-01')))),
                    'record_date' => (string) ($linkedRecord['record_date'] ?? ''),
                    'currency' => (string) ($linkedRecord['currency'] ?? 'PKR'),
                    'basic_salary_amount' => number_format((float) ($linkedRecord['basic_salary_amount'] ?? 0), 2, '.', ''),
                    'bonus_amount' => number_format((float) ($linkedRecord['bonus_amount'] ?? 0), 2, '.', ''),
                    'customer_penalty_profit_amount' => number_format((float) ($linkedRecord['customer_penalty_profit_amount'] ?? 0), 2, '.', ''),
                    'notes' => (string) ($linkedRecord['notes'] ?? ''),
                    'status' => (string) ($linkedRecord['status'] ?? 'open'),
                ];
                break;
            }
        }
        $currencyOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) ($row['code'] ?? ''),
                'label' => (string) ($row['code'] ?? '') . ' - ' . (string) ($row['name'] ?? ''),
            ],
            $masterData->rows('currencies')
        );
        $paymentMethodOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) ($row['code'] ?? ''),
                'label' => (string) ($row['name'] ?? ''),
            ],
            $masterData->rows('payment_methods')
        );
        $treasuryAccountOptions = array_map(
            static function (array $row): array {
                $branchName = trim((string) ($row['branch_name'] ?? ''));
                $accountName = trim((string) ($row['account_name'] ?? ''));
                $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));

                return [
                    'value' => (string) ($row['id'] ?? ''),
                    'label' => trim($accountName . ($branchName !== '' ? ' - ' . $branchName : '') . ' (' . $currency . ')'),
                    'currency' => $currency,
                    'branch_id' => (string) ($row['branch_id'] ?? ''),
                ];
            },
            array_values(array_filter(
                (new TreasuryRepository($this->app))->accounts($accessibleBranchIds),
                static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1
            ))
        );

        return $this->view('control/salaries', [
            'title' => 'Salary',
            'user' => Auth::user(),
            'salaryTablesReady' => $repository->tablesExist(),
            'salaryActiveTab' => $activeTab,
            'salaryRecordRows' => $recordRows,
            'salaryPaymentRows' => $paymentRows,
            'salaryOpenRecordRows' => $openRecordRows,
            'salaryBranchOptions' => $branchOptions,
            'salaryAccountOptions' => $accountOptions,
            'salaryAccountGroupKeys' => $accountGroupKeys,
            'salaryCurrencyOptions' => $currencyOptions,
            'salaryPaymentMethodOptions' => $paymentMethodOptions,
            'salaryTreasuryAccountOptions' => $treasuryAccountOptions,
            'salaryEmployeePerformanceRows' => $employeePerformanceRows,
            'salarySummaryCards' => $this->summaryCards($recordRows),
            'salaryEditingRecord' => $editingRecord,
            'salaryEditingPayment' => $editingPayment,
        ]);
    }

    public function saveRecord(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $this->runTraced('salary.record.create', fn (): array => (new SalaryAdminService($this->app))->record(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary record saved successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect('/salaries?tab=record');
    }

    public function updateRecord(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $this->runTraced('salary.record.update', fn (): array => (new SalaryAdminService($this->app))->updateRecord(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary record updated successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect('/salaries?tab=record');
    }

    public function voidRecord(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $recordId = max(0, (int) ($_POST['salary_record_id'] ?? 0));
        $redirect = '/salaries?tab=record';

        try {
            $this->runTraced('salary.record.void', fn (): array => (new SalaryAdminService($this->app))->voidRecord(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary record voided successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            if ($recordId > 0) {
                $redirect = '/salaries?tab=record&edit_record_id=' . $recordId;
            }
        }

        $this->redirect($redirect);
    }

    public function savePayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $this->runTraced('salary.payment.create', fn (): array => (new SalaryAdminService($this->app))->pay(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary payment / deduction saved successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect('/salaries?tab=pay');
    }

    public function updatePayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $this->runTraced('salary.payment.update', fn (): array => (new SalaryAdminService($this->app))->updatePayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary credit entry updated successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect('/salaries?tab=pay');
    }

    public function voidPayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $paymentId = max(0, (int) ($_POST['salary_payment_id'] ?? 0));
        $recordId = max(0, (int) ($_POST['salary_record_id'] ?? 0));
        $redirect = '/salaries?tab=pay';

        try {
            $this->runTraced('salary.payment.void', fn (): array => (new SalaryAdminService($this->app))->voidPayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary credit entry voided successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            if ($paymentId > 0) {
                $redirect = '/salaries?tab=pay&edit_payment_id=' . $paymentId . ($recordId > 0 ? '&edit_record_id=' . $recordId : '');
            }
        }

        $this->redirect($redirect);
    }

    public function voidAll(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $recordId = max(0, (int) ($_POST['salary_record_id'] ?? 0));
        $paymentId = max(0, (int) ($_POST['salary_payment_id'] ?? 0));
        $redirect = '/salaries?tab=pay';

        try {
            $this->runTraced('salary.record.void_all', fn (): array => (new SalaryAdminService($this->app))->voidAll(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            ));
            Flash::success('Salary record and linked salary payments were voided successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            if ($recordId > 0 || $paymentId > 0) {
                $redirect = '/salaries?tab=pay'
                    . ($paymentId > 0 ? '&edit_payment_id=' . $paymentId : '')
                    . ($recordId > 0 ? '&edit_record_id=' . $recordId : '');
            }
        }

        $this->redirect($redirect);
    }

    private function runTraced(string $action, callable $operation): array
    {
        return SalaryOperationTraceService::run(
            $this->app,
            $action,
            $_POST,
            (int) Auth::id(),
            Authorization::accessibleBranchIds(),
            $operation
        );
    }

    private function summaryCards(array $recordRows): array
    {
        $grossByCurrency = [];
        $paidByCurrency = [];
        $deductionByCurrency = [];
        $outstandingByCurrency = [];

        foreach ($recordRows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR'))) ?: 'PKR';
            $grossByCurrency[$currency] = round(($grossByCurrency[$currency] ?? 0) + (float) ($row['gross_debit_amount'] ?? 0), 2);
            $paidByCurrency[$currency] = round(($paidByCurrency[$currency] ?? 0) + (float) ($row['paid_credit_amount'] ?? 0), 2);
            $deductionByCurrency[$currency] = round(($deductionByCurrency[$currency] ?? 0) + (float) ($row['deduction_credit_amount'] ?? 0), 2);
            $outstandingByCurrency[$currency] = round(($outstandingByCurrency[$currency] ?? 0) + (float) ($row['outstanding_amount'] ?? 0), 2);
        }

        return [
            ['label' => 'Salary Debit Recorded', 'value' => $this->formatCurrencyMap($grossByCurrency)],
            ['label' => 'Salary Credit Paid', 'value' => $this->formatCurrencyMap($paidByCurrency)],
            ['label' => 'Salary Deductions', 'value' => $this->formatCurrencyMap($deductionByCurrency)],
            ['label' => 'Outstanding Salary', 'value' => $this->formatCurrencyMap($outstandingByCurrency)],
        ];
    }

    private function formatCurrencyMap(array $totals): string
    {
        if ($totals === []) {
            return 'PKR 0.00';
        }

        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = $currency . ' ' . number_format((float) $amount, 2);
        }

        return implode(' | ', $parts);
    }

    private function employeePerformanceRows(array $rows, array $accountGroupKeys): array
    {
        return array_map(function (array $row) use ($accountGroupKeys): array {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency === '') {
                $currency = $this->detectBusinessSourceCurrency(
                    (string) ($row['business_source_name'] ?? ''),
                    (string) ($row['business_source_code'] ?? '')
                );
            }

            $totalSale = round((float) ($row['total_sale'] ?? 0), 2);
            $totalOutstanding = round((float) ($row['total_outstanding'] ?? 0), 2);
            $monthSale = round((float) ($row['month_sale'] ?? 0), 2);
            $monthOutstanding = round((float) ($row['month_outstanding'] ?? 0), 2);
            $monthProfit = round((float) ($row['month_profit'] ?? 0), 2);

            return [
                'business_source_id' => (int) ($row['business_source_id'] ?? 0),
                'business_source_name' => (string) ($row['business_source_name'] ?? 'Sales Account'),
                'business_source_code' => (string) ($row['business_source_code'] ?? ''),
                'account_group_key' => (string) ($accountGroupKeys[(int) ($row['business_source_id'] ?? 0)] ?? ''),
                'currency' => $currency,
                'month_label' => date('M Y'),
                'total_sale' => $totalSale,
                'total_sale_display' => $currency !== '' ? $currency . ' ' . number_format($totalSale, 2) : number_format($totalSale, 2),
                'total_outstanding' => $totalOutstanding,
                'total_outstanding_display' => $currency !== '' ? $currency . ' ' . number_format($totalOutstanding, 2) : number_format($totalOutstanding, 2),
                'month_sale' => $monthSale,
                'month_sale_display' => $currency !== '' ? $currency . ' ' . number_format($monthSale, 2) : number_format($monthSale, 2),
                'month_outstanding' => $monthOutstanding,
                'month_outstanding_display' => $currency !== '' ? $currency . ' ' . number_format($monthOutstanding, 2) : number_format($monthOutstanding, 2),
                'month_profit' => $monthProfit,
                'month_profit_display' => $currency !== '' ? $currency . ' ' . number_format($monthProfit, 2) : number_format($monthProfit, 2),
            ];
        }, $rows);
    }

    private function detectBusinessSourceCurrency(string $name, string $code): string
    {
        $text = strtoupper(trim($name . ' ' . $code));
        foreach (['PKR', 'AED', 'USD'] as $currency) {
            if (str_contains($text, $currency)) {
                return $currency;
            }
        }

        return '';
    }
}
