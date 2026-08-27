<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MasterDataRepository;
use App\Repositories\SalaryRepository;
use App\Repositories\TreasuryRepository;
use App\Repositories\UserRepository;
use PDO;
use RuntimeException;

final class SalaryAdminService extends Service
{
    public function record(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist()) {
            throw new RuntimeException('Salary module tables are not available until the latest migration is applied.');
        }

        $masterData = new MasterDataRepository($this->app);
        $validBranchIds = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            array_values(array_filter(
                $masterData->rows('branches'),
                static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $accessibleBranchIds, true)
            ))
        );

        $branchId = (int) ($input['branch_id'] ?? 0);
        if (! in_array($branchId, $validBranchIds, true)) {
            throw new RuntimeException('Please select an accessible branch for the salary record.');
        }

        $currency = mb_strtoupper(trim((string) ($input['currency'] ?? 'PKR')));
        $validCurrencies = array_map(
            static fn (array $row): string => mb_strtoupper((string) ($row['code'] ?? '')),
            $masterData->rows('currencies')
        );
        if (! in_array($currency, $validCurrencies, true)) {
            throw new RuntimeException('Please select a valid salary currency.');
        }

        $selectedUserId = (int) ($input['employee_user_id'] ?? 0);
        $linkedBusinessSourceRows = $repository->linkedBusinessSourceRowsForUser($selectedUserId);
        if ($linkedBusinessSourceRows === []) {
            throw new RuntimeException('Please select a linked employee before saving salary.');
        }

        $businessSourceId = 0;
        if (count($linkedBusinessSourceRows) === 1) {
            $businessSourceId = (int) ($linkedBusinessSourceRows[0]['id'] ?? 0);
        } else {
            foreach ($linkedBusinessSourceRows as $linkedRow) {
                $linkedText = strtoupper(trim(
                    (string) ($linkedRow['name'] ?? '') . ' ' . (string) ($linkedRow['code'] ?? '')
                ));
                if ($linkedText !== '' && str_contains($linkedText, $currency)) {
                    $businessSourceId = (int) ($linkedRow['id'] ?? 0);
                    break;
                }
            }
        }

        $businessSource = $masterData->find('business_sources', $businessSourceId);
        if ($businessSourceId <= 0 || $businessSource === null || (int) ($businessSource['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('No linked sales account matched the selected employee and salary currency.');
        }

        $salaryMonth = $this->normalizeMonth((string) ($input['salary_month'] ?? ''));
        $recordDate = $this->normalizeDate((string) ($input['record_date'] ?? ''));
        $basicSalaryAmount = $this->moneyValue($input['basic_salary_amount'] ?? 0);
        $bonusAmount = $this->moneyValue($input['bonus_amount'] ?? 0);
        $notes = $this->optionalText($input['notes'] ?? null, 4000);

        $grossDebitAmount = round(
            $basicSalaryAmount + $bonusAmount,
            2
        );
        if ($grossDebitAmount <= 0.005) {
            throw new RuntimeException('Salary record must contain at least one positive debit amount.');
        }

        if ($repository->duplicateRecordExists($branchId, $businessSourceId, $salaryMonth, $currency)) {
            throw new RuntimeException('A salary record for this employee, branch, month, and currency already exists.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $salaryRecordId = $repository->createRecord([
                'salary_month' => $salaryMonth,
                'record_date' => $recordDate,
                'branch_id' => $branchId,
                'business_source_id' => $businessSourceId,
                'currency' => $currency,
                'basic_salary_amount' => $basicSalaryAmount,
                'bonus_amount' => $bonusAmount,
                'service_profit_amount' => 0,
                'customer_penalty_profit_amount' => 0,
                'gross_debit_amount' => $grossDebitAmount,
                'outstanding_amount' => $grossDebitAmount,
                'status' => 'open',
                'notes' => $notes,
                'entered_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'recorded',
                'salary_record_id' => $salaryRecordId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function updateRecord(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist()) {
            throw new RuntimeException('Salary module tables are not available until the latest migration is applied.');
        }

        $recordId = (int) ($input['salary_record_id'] ?? 0);
        $existingRecord = $repository->findRecord($recordId, $accessibleBranchIds);
        if ($existingRecord === null) {
            throw new RuntimeException('The selected salary record was not found.');
        }
        if (strtolower(str_replace(' ', '_', (string) ($existingRecord['status'] ?? 'open'))) === 'void') {
            throw new RuntimeException('Voided salary debit records cannot be edited.');
        }

        $masterData = new MasterDataRepository($this->app);
        $validBranchIds = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            array_values(array_filter(
                $masterData->rows('branches'),
                static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $accessibleBranchIds, true)
            ))
        );

        $branchId = (int) ($input['branch_id'] ?? 0);
        if (! in_array($branchId, $validBranchIds, true)) {
            throw new RuntimeException('Please select an accessible branch for the salary record.');
        }

        $currency = mb_strtoupper(trim((string) ($input['currency'] ?? 'PKR')));
        $validCurrencies = array_map(
            static fn (array $row): string => mb_strtoupper((string) ($row['code'] ?? '')),
            $masterData->rows('currencies')
        );
        if (! in_array($currency, $validCurrencies, true)) {
            throw new RuntimeException('Please select a valid salary currency.');
        }

        $selectedUserId = (int) ($input['employee_user_id'] ?? 0);
        $linkedBusinessSourceRows = $repository->linkedBusinessSourceRowsForUser($selectedUserId);
        if ($linkedBusinessSourceRows === []) {
            throw new RuntimeException('Please select a linked employee before saving salary.');
        }

        $businessSourceId = 0;
        if (count($linkedBusinessSourceRows) === 1) {
            $businessSourceId = (int) ($linkedBusinessSourceRows[0]['id'] ?? 0);
        } else {
            foreach ($linkedBusinessSourceRows as $linkedRow) {
                $linkedText = strtoupper(trim(
                    (string) ($linkedRow['name'] ?? '') . ' ' . (string) ($linkedRow['code'] ?? '')
                ));
                if ($linkedText !== '' && str_contains($linkedText, $currency)) {
                    $businessSourceId = (int) ($linkedRow['id'] ?? 0);
                    break;
                }
            }
        }

        $businessSource = $masterData->find('business_sources', $businessSourceId);
        if ($businessSourceId <= 0 || $businessSource === null || (int) ($businessSource['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('No linked sales account matched the selected employee and salary currency.');
        }

        $salaryMonth = $this->normalizeMonth((string) ($input['salary_month'] ?? ''));
        $recordDate = $this->normalizeDate((string) ($input['record_date'] ?? ''));
        $basicSalaryAmount = $this->moneyValue($input['basic_salary_amount'] ?? 0);
        $bonusAmount = $this->moneyValue($input['bonus_amount'] ?? 0);
        $notes = $this->optionalText($input['notes'] ?? null, 4000);

        $grossDebitAmount = round(
            $basicSalaryAmount + $bonusAmount,
            2
        );
        if ($grossDebitAmount <= 0.005) {
            throw new RuntimeException('Salary record must contain at least one positive debit amount.');
        }

        if ($repository->duplicateRecordExists($branchId, $businessSourceId, $salaryMonth, $currency, $recordId)) {
            throw new RuntimeException('A salary record for this employee, branch, month, and currency already exists.');
        }

        $existingCredits = round(
            (float) ($existingRecord['paid_credit_amount'] ?? 0) + (float) ($existingRecord['deduction_credit_amount'] ?? 0),
            2
        );
        if ($grossDebitAmount + 0.005 < $existingCredits) {
            throw new RuntimeException('Salary debit cannot be reduced below its already recorded salary credits and deductions.');
        }

        $outstandingAmount = round($grossDebitAmount - $existingCredits, 2);
        $status = $outstandingAmount <= 0.005
            ? 'paid'
            : ($existingCredits > 0.005 ? 'partially_paid' : 'open');

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $repository->updateRecord([
                'id' => $recordId,
                'salary_month' => $salaryMonth,
                'record_date' => $recordDate,
                'branch_id' => $branchId,
                'business_source_id' => $businessSourceId,
                'currency' => $currency,
                'basic_salary_amount' => $basicSalaryAmount,
                'bonus_amount' => $bonusAmount,
                'service_profit_amount' => 0,
                'customer_penalty_profit_amount' => 0,
                'gross_debit_amount' => $grossDebitAmount,
                'outstanding_amount' => $outstandingAmount,
                'status' => $status,
                'notes' => $notes,
                'updated_by_user_id' => $actorUserId,
            ]);
            $repository->refreshRecordTotals($recordId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'updated',
                'salary_record_id' => $recordId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function pay(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist()) {
            throw new RuntimeException('Salary module tables are not available until the latest migration is applied.');
        }

        $salaryRecordId = (int) ($input['salary_record_id'] ?? 0);
        $record = $repository->findRecord($salaryRecordId, $accessibleBranchIds);
        if ($record === null) {
            throw new RuntimeException('Please select a valid salary record to settle.');
        }
        if (strtolower(str_replace(' ', '_', (string) ($record['status'] ?? 'open'))) === 'void') {
            throw new RuntimeException('Voided salary records cannot receive new salary credits.');
        }

        $paymentDate = $this->normalizeDate((string) ($input['payment_date'] ?? ''));
        $paymentCurrency = $this->normalizeCurrency((string) ($input['payment_currency'] ?? ($record['currency'] ?? 'PKR')));
        $sourcePaidAmount = $this->moneyValue($input['paid_amount'] ?? 0);
        $exchangeRateToSalary = $this->normalizeOptionalExchangeRate((string) ($input['exchange_rate_to_salary'] ?? ''));
        $paidAmount = $sourcePaidAmount;
        $deductionAmount = $this->moneyValue($input['deduction_amount'] ?? 0);
        $deductionReason = $this->optionalText($input['deduction_reason'] ?? null, 255);
        $referenceNumber = $this->optionalText($input['reference_number'] ?? null, 120);
        $remarks = $this->optionalText($input['remarks'] ?? null, 4000);

        $recordCurrency = $this->normalizeCurrency((string) ($record['currency'] ?? 'PKR'));
        if ($sourcePaidAmount > 0.005 && $paymentCurrency !== $recordCurrency) {
            if ($exchangeRateToSalary === null || $exchangeRateToSalary <= 0) {
                throw new RuntimeException(sprintf('Enter a valid exchange rate: 1 %s = ? %s.', $paymentCurrency, $recordCurrency));
            }
            $paidAmount = round($sourcePaidAmount * $exchangeRateToSalary, 2);
        } elseif ($sourcePaidAmount > 0.005) {
            $exchangeRateToSalary = 1.0;
        } else {
            $paymentCurrency = $recordCurrency;
            $exchangeRateToSalary = null;
        }

        if ($paidAmount <= 0.005 && $deductionAmount <= 0.005) {
            throw new RuntimeException('Please enter a paid amount or a deduction amount.');
        }
        if ($deductionAmount > 0.005 && $deductionReason === null) {
            throw new RuntimeException('Please enter the reason for the employee deduction / penalty.');
        }

        $totalCredit = round($paidAmount + $deductionAmount, 2);
        $currentOutstanding = round((float) ($record['outstanding_amount'] ?? 0), 2);
        if ($totalCredit - $currentOutstanding > 0.005) {
            throw new RuntimeException('Payment and deduction exceed the current employee outstanding amount.');
        }

        $masterData = new MasterDataRepository($this->app);
        $paymentMethod = null;
        $treasuryAccountId = null;
        $paymentBranchId = (int) ($record['branch_id'] ?? 0);
        if ($paidAmount > 0.005) {
            $paymentMethodCode = mb_strtolower(trim((string) ($input['payment_method'] ?? '')));
            $validPaymentMethods = array_map(
                static fn (array $row): string => mb_strtolower((string) ($row['code'] ?? '')),
                $masterData->rows('payment_methods')
            );
            if (! in_array($paymentMethodCode, $validPaymentMethods, true)) {
                throw new RuntimeException('Please select a valid salary payment method.');
            }

            $treasuryAccountId = max(0, (int) ($input['treasury_account_id'] ?? 0));
            $treasuryAccount = (new TreasuryRepository($this->app))->validatePaymentTreasuryAccount(
                $treasuryAccountId,
                (int) ($record['branch_id'] ?? 0),
                $paymentCurrency,
                $paymentMethodCode,
                false,
                false,
                true,
                true
            );
            if ((int) ($treasuryAccount['id'] ?? 0) <= 0) {
                throw new RuntimeException('Please select a valid source account for the salary payment.');
            }

            $paymentMethod = $paymentMethodCode;
            $treasuryAccountId = (int) ($treasuryAccount['id'] ?? 0);
            $paymentBranchId = (int) ($treasuryAccount['branch_id'] ?? $paymentBranchId);
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $paymentId = $repository->createPayment([
                'salary_record_id' => $salaryRecordId,
                'payment_date' => $paymentDate,
                'branch_id' => $paymentBranchId,
                'currency' => $paymentCurrency,
                'payment_currency' => $paymentCurrency,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'paid_amount' => $paidAmount,
                'source_paid_amount' => $sourcePaidAmount > 0.005 ? $sourcePaidAmount : 0,
                'exchange_rate_to_salary' => $exchangeRateToSalary,
                'deduction_amount' => $deductionAmount,
                'deduction_reason' => $deductionReason,
                'reference_number' => $referenceNumber,
                'remarks' => $remarks,
                'status' => 'posted',
                'entered_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
            $repository->refreshRecordTotals($salaryRecordId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'paid',
                'salary_payment_id' => $paymentId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function updatePayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist()) {
            throw new RuntimeException('Salary module tables are not available until the latest migration is applied.');
        }

        $paymentId = (int) ($input['salary_payment_id'] ?? 0);
        $existingPayment = $repository->findPayment($paymentId, $accessibleBranchIds);
        if ($existingPayment === null) {
            throw new RuntimeException('The selected salary credit entry was not found.');
        }

        if (strtolower(str_replace(' ', '_', (string) ($existingPayment['status'] ?? 'posted'))) === 'void') {
            throw new RuntimeException('Voided salary credit entries cannot be edited.');
        }

        $salaryRecordId = (int) ($input['salary_record_id'] ?? 0);
        $targetRecord = $repository->findRecord($salaryRecordId, $accessibleBranchIds);
        if ($targetRecord === null) {
            throw new RuntimeException('Please select a valid salary record to settle.');
        }
        if (strtolower(str_replace(' ', '_', (string) ($targetRecord['status'] ?? 'open'))) === 'void') {
            throw new RuntimeException('Voided salary records cannot receive new salary credits.');
        }

        $paymentDate = $this->normalizeDate((string) ($input['payment_date'] ?? ''));
        $paymentCurrency = $this->normalizeCurrency((string) ($input['payment_currency'] ?? ($targetRecord['currency'] ?? 'PKR')));
        $sourcePaidAmount = $this->moneyValue($input['paid_amount'] ?? 0);
        $exchangeRateToSalary = $this->normalizeOptionalExchangeRate((string) ($input['exchange_rate_to_salary'] ?? ''));
        $paidAmount = $sourcePaidAmount;
        $deductionAmount = $this->moneyValue($input['deduction_amount'] ?? 0);
        $deductionReason = $this->optionalText($input['deduction_reason'] ?? null, 255);
        $referenceNumber = $this->optionalText($input['reference_number'] ?? null, 120);
        $remarks = $this->optionalText($input['remarks'] ?? null, 4000);

        $recordCurrency = $this->normalizeCurrency((string) ($targetRecord['currency'] ?? 'PKR'));
        if ($sourcePaidAmount > 0.005 && $paymentCurrency !== $recordCurrency) {
            if ($exchangeRateToSalary === null || $exchangeRateToSalary <= 0) {
                throw new RuntimeException(sprintf('Enter a valid exchange rate: 1 %s = ? %s.', $paymentCurrency, $recordCurrency));
            }
            $paidAmount = round($sourcePaidAmount * $exchangeRateToSalary, 2);
        } elseif ($sourcePaidAmount > 0.005) {
            $exchangeRateToSalary = 1.0;
        } else {
            $paymentCurrency = $recordCurrency;
            $exchangeRateToSalary = null;
        }

        if ($paidAmount <= 0.005 && $deductionAmount <= 0.005) {
            throw new RuntimeException('Please enter a paid amount or a deduction amount.');
        }
        if ($deductionAmount > 0.005 && $deductionReason === null) {
            throw new RuntimeException('Please enter the reason for the employee deduction / penalty.');
        }

        $otherCredits = $repository->paymentCreditTotalsExcludingPayment($salaryRecordId, $paymentId);
        $targetGrossDebitAmount = round((float) ($targetRecord['gross_debit_amount'] ?? 0), 2);
        $newTotalCredit = round($paidAmount + $deductionAmount, 2);
        $otherCreditTotal = round(
            (float) ($otherCredits['paid_total'] ?? 0) + (float) ($otherCredits['deduction_total'] ?? 0),
            2
        );
        if ($otherCreditTotal + $newTotalCredit - $targetGrossDebitAmount > 0.005) {
            throw new RuntimeException('Payment and deduction exceed the current employee salary debit amount.');
        }

        $masterData = new MasterDataRepository($this->app);
        $paymentBranchId = (int) ($targetRecord['branch_id'] ?? 0);
        $paymentMethod = null;
        $treasuryAccountId = null;
        if ($paidAmount > 0.005) {
            $paymentMethodCode = mb_strtolower(trim((string) ($input['payment_method'] ?? '')));
            $validPaymentMethods = array_map(
                static fn (array $row): string => mb_strtolower((string) ($row['code'] ?? '')),
                $masterData->rows('payment_methods')
            );
            if (! in_array($paymentMethodCode, $validPaymentMethods, true)) {
                throw new RuntimeException('Please select a valid salary payment method.');
            }

            $treasuryAccountId = max(0, (int) ($input['treasury_account_id'] ?? 0));
            $treasuryAccount = (new TreasuryRepository($this->app))->validatePaymentTreasuryAccount(
                $treasuryAccountId,
                $paymentBranchId,
                $paymentCurrency,
                $paymentMethodCode,
                false,
                false,
                true,
                true
            );
            if ((int) ($treasuryAccount['id'] ?? 0) <= 0) {
                throw new RuntimeException('Please select a valid source account for the salary payment.');
            }

            $paymentMethod = $paymentMethodCode;
            $treasuryAccountId = (int) ($treasuryAccount['id'] ?? 0);
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $oldRecordId = (int) ($existingPayment['salary_record_id'] ?? 0);
            $repository->updatePayment([
                'id' => $paymentId,
                'salary_record_id' => $salaryRecordId,
                'payment_date' => $paymentDate,
                'branch_id' => $paymentBranchId,
                'currency' => $paymentCurrency,
                'payment_currency' => $paymentCurrency,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'paid_amount' => $paidAmount,
                'source_paid_amount' => $sourcePaidAmount > 0.005 ? $sourcePaidAmount : 0,
                'exchange_rate_to_salary' => $exchangeRateToSalary,
                'deduction_amount' => $deductionAmount,
                'deduction_reason' => $deductionReason,
                'reference_number' => $referenceNumber,
                'remarks' => $remarks,
                'updated_by_user_id' => $actorUserId,
            ]);
            $repository->refreshRecordTotals($salaryRecordId);
            if ($oldRecordId > 0 && $oldRecordId !== $salaryRecordId) {
                $repository->refreshRecordTotals($oldRecordId);
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'updated',
                'salary_payment_id' => $paymentId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function voidPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist() || ! $repository->paymentStatusColumnExists()) {
            throw new RuntimeException('Salary payment voiding is not available until the latest migration is applied.');
        }

        $paymentId = (int) ($input['salary_payment_id'] ?? 0);
        $existingPayment = $repository->findPayment($paymentId, $accessibleBranchIds);
        if ($existingPayment === null) {
            throw new RuntimeException('The selected salary credit entry was not found.');
        }

        if (strtolower(str_replace(' ', '_', (string) ($existingPayment['status'] ?? 'posted'))) === 'void') {
            throw new RuntimeException('This salary credit entry has already been voided.');
        }

        $voidReason = $this->optionalText($input['void_reason'] ?? null, 500);
        if ($voidReason === null || mb_strlen(trim($voidReason)) < 5) {
            throw new RuntimeException('Please enter a short reason before voiding this salary credit entry.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $repository->voidPayment($paymentId, $voidReason, $actorUserId);
            $repository->refreshRecordTotals((int) ($existingPayment['salary_record_id'] ?? 0));

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'voided',
                'salary_payment_id' => $paymentId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function voidRecord(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist() || ! $repository->recordVoidColumnsExist()) {
            throw new RuntimeException('Salary record voiding is not available until the latest migration is applied.');
        }

        $recordId = (int) ($input['salary_record_id'] ?? 0);
        $existingRecord = $repository->findRecord($recordId, $accessibleBranchIds);
        if ($existingRecord === null) {
            throw new RuntimeException('The selected salary record was not found.');
        }

        if (strtolower(str_replace(' ', '_', (string) ($existingRecord['status'] ?? 'open'))) === 'void') {
            throw new RuntimeException('This salary record has already been voided.');
        }

        $voidReason = $this->optionalText($input['void_reason'] ?? null, 500);
        if ($voidReason === null || mb_strlen(trim($voidReason)) < 5) {
            throw new RuntimeException('Please enter a short reason before voiding this salary record.');
        }

        if ($repository->activePaymentCountForRecord($recordId) > 0) {
            throw new RuntimeException('Void the linked salary payment first, or use Void All if you want to clear both together.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $repository->voidRecord($recordId, $voidReason, $actorUserId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'voided',
                'salary_record_id' => $recordId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function voidAll(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->tablesExist() || ! $repository->paymentStatusColumnExists() || ! $repository->recordVoidColumnsExist()) {
            throw new RuntimeException('Salary void-all is not available until the latest migrations are applied.');
        }

        $recordId = (int) ($input['salary_record_id'] ?? 0);
        $existingRecord = $repository->findRecord($recordId, $accessibleBranchIds);
        if ($existingRecord === null) {
            throw new RuntimeException('The selected salary record was not found.');
        }

        if (strtolower(str_replace(' ', '_', (string) ($existingRecord['status'] ?? 'open'))) === 'void') {
            throw new RuntimeException('This salary record has already been voided.');
        }

        $voidReason = $this->optionalText($input['void_reason'] ?? null, 500);
        if ($voidReason === null || mb_strlen(trim($voidReason)) < 5) {
            throw new RuntimeException('Please enter a short reason before voiding this salary record and its payments.');
        }

        $activePaymentIds = $repository->activePaymentIdsForRecord($recordId);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            foreach ($activePaymentIds as $paymentId) {
                $repository->voidPayment($paymentId, $voidReason, $actorUserId);
            }
            $repository->refreshRecordTotals($recordId);
            $repository->voidRecord($recordId, $voidReason, $actorUserId);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'voided_all',
                'salary_record_id' => $recordId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function saveEmployeeLink(array $input, int $actorUserId): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->employeeLinkTableExists()) {
            throw new RuntimeException('Employee sales-account links are not available until the latest migration is applied.');
        }

        $userId = (int) ($input['user_id'] ?? 0);
        $businessSourceId = (int) ($input['business_source_id'] ?? 0);
        $notes = $this->optionalText($input['notes'] ?? null, 255);

        $user = (new UserRepository($this->app))->findById($userId);
        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Please select a valid active employee user.');
        }

        $businessSource = (new MasterDataRepository($this->app))->find('business_sources', $businessSourceId);
        if ($businessSource === null || (int) ($businessSource['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Please select a valid active sales account.');
        }

        $repository->saveEmployeeLink([
            'user_id' => $userId,
            'business_source_id' => $businessSourceId,
            'notes' => $notes,
            'linked_by_user_id' => $actorUserId,
        ]);

        return [
            'action' => 'linked',
            'user_id' => $userId,
            'business_source_id' => $businessSourceId,
        ];
    }

    public function unlinkEmployeeLink(array $input): array
    {
        $repository = new SalaryRepository($this->app);
        if (! $repository->employeeLinkTableExists()) {
            throw new RuntimeException('Employee sales-account links are not available until the latest migration is applied.');
        }

        $linkId = (int) ($input['link_id'] ?? 0);
        $link = $repository->findEmployeeLink($linkId);
        if ($link === null || (int) ($link['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('The selected employee sales-account link was not found.');
        }

        $repository->deactivateEmployeeLink($linkId);

        return [
            'action' => 'unlinked',
            'link_id' => $linkId,
        ];
    }

    private function normalizeMonth(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}\-\d{2}$/', $value)) {
            throw new RuntimeException('Salary month must be selected in YYYY-MM format.');
        }

        [$year, $month] = array_map('intval', explode('-', $value));
        if ($year < 2000 || $month < 1 || $month > 12) {
            throw new RuntimeException('Salary month is not valid.');
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value)) {
            throw new RuntimeException('Please select a valid date.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (! checkdate($month, $day, $year)) {
            throw new RuntimeException('Please select a valid date.');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function normalizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            throw new RuntimeException('Please select a valid currency.');
        }

        return $value;
    }

    private function normalizeOptionalExchangeRate(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new RuntimeException('Exchange rate must be numeric.');
        }

        $rate = round((float) $value, 8);
        if ($rate <= 0) {
            throw new RuntimeException('Exchange rate must be greater than zero.');
        }

        return $rate;
    }

    private function moneyValue(mixed $value): float
    {
        return round(max((float) $value, 0), 2);
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('Entered text is too long.');
        }

        return $text;
    }
}
