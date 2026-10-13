<?php

declare(strict_types=1);

namespace App\Repositories;

final class SalaryRepository extends BaseRepository
{
    public function recordVoidColumnsExist(): bool
    {
        return $this->columnExists('employee_salary_records', 'void_reason')
            && $this->columnExists('employee_salary_records', 'voided_by_user_id')
            && $this->columnExists('employee_salary_records', 'voided_at');
    }

    public function paymentFxColumnsExist(): bool
    {
        return $this->columnExists('employee_salary_payments', 'payment_currency')
            && $this->columnExists('employee_salary_payments', 'source_paid_amount')
            && $this->columnExists('employee_salary_payments', 'exchange_rate_to_salary');
    }

    public function paymentStatusColumnExists(): bool
    {
        return $this->columnExists('employee_salary_payments', 'status');
    }

    public function tablesExist(): bool
    {
        return $this->tableExists('employee_salary_records')
            && $this->tableExists('employee_salary_payments');
    }

    public function recordRows(array $accessibleBranchIds): array
    {
        if ($accessibleBranchIds === [] || ! $this->tablesExist()) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                r.id,
                r.salary_month,
                r.record_date,
                r.branch_id,
                b.name AS branch_name,
                r.business_source_id,
                bs.name AS employee_name,
                bs.name AS business_source_name,
                r.currency,
                r.basic_salary_amount,
                r.bonus_amount,
                r.service_profit_amount,
                r.customer_penalty_profit_amount,
                r.gross_debit_amount,
                r.paid_credit_amount,
                r.deduction_credit_amount,
                r.outstanding_amount,
                r.status,
                " . ($this->recordVoidColumnsExist() ? 'r.void_reason, r.voided_by_user_id, r.voided_at,' : 'NULL AS void_reason, NULL AS voided_by_user_id, NULL AS voided_at,') . "
                r.notes,
                r.created_at,
                r.updated_at
             FROM employee_salary_records r
             INNER JOIN branches b ON b.id = r.branch_id
             INNER JOIN business_sources bs ON bs.id = r.business_source_id
             WHERE r.branch_id IN ({$placeholders})
             ORDER BY r.salary_month DESC, r.id DESC"
        );
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function paymentRows(array $accessibleBranchIds): array
    {
        if ($accessibleBranchIds === [] || ! $this->tablesExist()) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $hasPaymentFx = $this->paymentFxColumnsExist();
        $hasPaymentStatus = $this->paymentStatusColumnExists();
        $paymentStatusSelect = $hasPaymentStatus
            ? 'p.status AS status,
                p.void_reason,
                p.voided_by_user_id,
                p.voided_at,'
            : '"posted" AS status,
                NULL AS void_reason,
                NULL AS voided_by_user_id,
                NULL AS voided_at,';
        $paymentFxSelect = $hasPaymentFx
            ? "COALESCE(NULLIF(p.payment_currency, ''), p.currency, r.currency) AS payment_currency,
                COALESCE(p.source_paid_amount, p.paid_amount) AS source_paid_amount,
                p.exchange_rate_to_salary,"
            : "COALESCE(p.currency, r.currency) AS payment_currency,
                p.paid_amount AS source_paid_amount,
                NULL AS exchange_rate_to_salary,";
        $statement = $this->db->prepare(
            "SELECT
                p.id,
                p.salary_record_id,
                p.payment_date,
                p.branch_id,
                pb.name AS payment_branch_name,
                r.currency AS currency,
                r.currency AS record_currency,
                {$paymentFxSelect}
                p.payment_method,
                COALESCE(pm.name, REPLACE(p.payment_method, '_', ' '), 'Deduction') AS payment_method_label,
                p.treasury_account_id,
                ta.account_name AS treasury_account_name,
                p.paid_amount,
                p.deduction_amount,
                p.deduction_reason,
                p.reference_number,
                p.remarks,
                {$paymentStatusSelect}
                r.salary_month,
                r.record_date,
                r.business_source_id,
                bs.name AS employee_name,
                bs.name AS business_source_name,
                rb.name AS record_branch_name
             FROM employee_salary_payments p
             INNER JOIN employee_salary_records r ON r.id = p.salary_record_id
             INNER JOIN business_sources bs ON bs.id = r.business_source_id
             INNER JOIN branches rb ON rb.id = r.branch_id
             INNER JOIN branches pb ON pb.id = p.branch_id
             LEFT JOIN payment_methods pm ON pm.code = p.payment_method
             LEFT JOIN treasury_accounts ta ON ta.id = p.treasury_account_id
             WHERE r.branch_id IN ({$placeholders})
             ORDER BY p.payment_date DESC, p.id DESC"
        );
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function openRecordRows(array $accessibleBranchIds): array
    {
        if ($accessibleBranchIds === [] || ! $this->tablesExist()) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                r.id,
                r.salary_month,
                r.branch_id,
                b.name AS branch_name,
                r.business_source_id,
                bs.name AS employee_name,
                bs.name AS business_source_name,
                r.currency,
                r.gross_debit_amount,
                r.paid_credit_amount,
                r.deduction_credit_amount,
                r.outstanding_amount,
                r.status
             FROM employee_salary_records r
             INNER JOIN branches b ON b.id = r.branch_id
             INNER JOIN business_sources bs ON bs.id = r.business_source_id
             WHERE r.branch_id IN ({$placeholders})
               AND LOWER(REPLACE(COALESCE(r.status, 'open'), ' ', '_')) <> 'void'
               AND r.outstanding_amount > 0.005
             ORDER BY r.salary_month DESC, r.id DESC"
        );
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function findRecord(int $id, array $accessibleBranchIds): ?array
    {
        if ($accessibleBranchIds === [] || ! $this->tablesExist()) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT *
             FROM employee_salary_records
             WHERE id = ?
               AND branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $params = [$id, ...array_map('intval', $accessibleBranchIds)];
        $statement->execute($params);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function duplicateRecordExists(int $branchId, int $businessSourceId, string $salaryMonth, string $currency, ?int $excludeRecordId = null): bool
    {
        $sql = 'SELECT id
             FROM employee_salary_records
             WHERE branch_id = :branch_id
               AND business_source_id = :business_source_id
               AND salary_month = :salary_month
               AND currency = :currency';
        $params = [
            'branch_id' => $branchId,
            'business_source_id' => $businessSourceId,
            'salary_month' => $salaryMonth,
            'currency' => $currency,
        ];
        if ($excludeRecordId !== null && $excludeRecordId > 0) {
            $sql .= ' AND id <> :exclude_record_id';
            $params['exclude_record_id'] = $excludeRecordId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->db->prepare(
            $sql
        );
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function createRecord(array $payload): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO employee_salary_records (
                salary_month,
                record_date,
                branch_id,
                business_source_id,
                currency,
                basic_salary_amount,
                bonus_amount,
                service_profit_amount,
                customer_penalty_profit_amount,
                gross_debit_amount,
                paid_credit_amount,
                deduction_credit_amount,
                outstanding_amount,
                status,
                notes,
                entered_by_user_id,
                updated_by_user_id
            ) VALUES (
                :salary_month,
                :record_date,
                :branch_id,
                :business_source_id,
                :currency,
                :basic_salary_amount,
                :bonus_amount,
                :service_profit_amount,
                :customer_penalty_profit_amount,
                :gross_debit_amount,
                0,
                0,
                :outstanding_amount,
                :status,
                :notes,
                :entered_by_user_id,
                :updated_by_user_id
            )'
        );
        $statement->execute($payload);

        return (int) $this->db->lastInsertId();
    }

    public function createPayment(array $payload): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO employee_salary_payments (
                salary_record_id,
                payment_date,
                branch_id,
                currency,
                payment_currency,
                payment_method,
                treasury_account_id,
                paid_amount,
                source_paid_amount,
                exchange_rate_to_salary,
                deduction_amount,
                deduction_reason,
                reference_number,
                remarks,
                status,
                entered_by_user_id,
                updated_by_user_id
            ) VALUES (
                :salary_record_id,
                :payment_date,
                :branch_id,
                :currency,
                :payment_currency,
                :payment_method,
                :treasury_account_id,
                :paid_amount,
                :source_paid_amount,
                :exchange_rate_to_salary,
                :deduction_amount,
                :deduction_reason,
                :reference_number,
                :remarks,
                :status,
                :entered_by_user_id,
                :updated_by_user_id
            )'
        );
        $statement->execute($payload);

        return (int) $this->db->lastInsertId();
    }

    public function refreshRecordTotals(int $salaryRecordId): void
    {
        $paymentStatusCondition = $this->paymentStatusColumnExists()
            ? 'WHERE salary_record_id = :salary_record_id_sum
                  AND LOWER(REPLACE(COALESCE(status, "posted"), " ", "_")) <> "void"'
            : 'WHERE salary_record_id = :salary_record_id_sum';
        $statement = $this->db->prepare(
            'UPDATE employee_salary_records r
             LEFT JOIN (
                SELECT
                    salary_record_id,
                    ROUND(SUM(paid_amount), 2) AS paid_total,
                    ROUND(SUM(deduction_amount), 2) AS deduction_total
                FROM employee_salary_payments
                ' . $paymentStatusCondition . '
                GROUP BY salary_record_id
             ) p ON p.salary_record_id = r.id
             SET r.paid_credit_amount = COALESCE(p.paid_total, 0),
                 r.deduction_credit_amount = COALESCE(p.deduction_total, 0),
                 r.outstanding_amount = ROUND(r.gross_debit_amount - COALESCE(p.paid_total, 0) - COALESCE(p.deduction_total, 0), 2),
                 r.status = CASE
                    WHEN ROUND(r.gross_debit_amount - COALESCE(p.paid_total, 0) - COALESCE(p.deduction_total, 0), 2) <= 0.005 THEN "paid"
                    WHEN COALESCE(p.paid_total, 0) + COALESCE(p.deduction_total, 0) > 0.005 THEN "partially_paid"
                    ELSE "open"
                 END
             WHERE r.id = :salary_record_id_row'
        );
        $statement->execute([
            'salary_record_id_sum' => $salaryRecordId,
            'salary_record_id_row' => $salaryRecordId,
        ]);
    }

    public function updateRecord(array $payload): void
    {
        $statement = $this->db->prepare(
            'UPDATE employee_salary_records
             SET salary_month = :salary_month,
                 record_date = :record_date,
                 branch_id = :branch_id,
                 business_source_id = :business_source_id,
                 currency = :currency,
                 basic_salary_amount = :basic_salary_amount,
                 bonus_amount = :bonus_amount,
                 service_profit_amount = :service_profit_amount,
                 customer_penalty_profit_amount = :customer_penalty_profit_amount,
                 gross_debit_amount = :gross_debit_amount,
                 outstanding_amount = :outstanding_amount,
                 status = :status,
                 notes = :notes,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute($payload);
    }

    public function findPayment(int $id, array $accessibleBranchIds): ?array
    {
        if ($accessibleBranchIds === [] || ! $this->tablesExist()) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $hasPaymentFx = $this->paymentFxColumnsExist();
        $hasPaymentStatus = $this->paymentStatusColumnExists();
        $paymentStatusSelect = $hasPaymentStatus
            ? 'p.status AS status,
                p.void_reason,
                p.voided_by_user_id,
                p.voided_at,'
            : '"posted" AS status,
                NULL AS void_reason,
                NULL AS voided_by_user_id,
                NULL AS voided_at,';
        $paymentFxSelect = $hasPaymentFx
            ? "COALESCE(NULLIF(p.payment_currency, ''), p.currency, r.currency) AS payment_currency,"
            : "COALESCE(p.currency, r.currency) AS payment_currency,";
        $statement = $this->db->prepare(
            "SELECT
                p.*,
                {$paymentStatusSelect}
                r.salary_month,
                r.record_date,
                r.branch_id AS record_branch_id,
                r.business_source_id,
                r.currency AS record_currency,
                {$paymentFxSelect}
                r.gross_debit_amount,
                r.paid_credit_amount,
                r.deduction_credit_amount,
                r.outstanding_amount,
                bs.name AS business_source_name
             FROM employee_salary_payments p
             INNER JOIN employee_salary_records r ON r.id = p.salary_record_id
             INNER JOIN business_sources bs ON bs.id = r.business_source_id
             WHERE p.id = ?
               AND r.branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $params = [$id, ...array_map('intval', $accessibleBranchIds)];
        $statement->execute($params);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function paymentCreditTotalsExcludingPayment(int $salaryRecordId, int $excludePaymentId): array
    {
        $statusWhere = $this->paymentStatusColumnExists()
            ? 'AND LOWER(REPLACE(COALESCE(status, "posted"), " ", "_")) <> "void"'
            : '';
        $statement = $this->db->prepare(
            'SELECT
                ROUND(COALESCE(SUM(paid_amount), 0), 2) AS paid_total,
                ROUND(COALESCE(SUM(deduction_amount), 0), 2) AS deduction_total
             FROM employee_salary_payments
             WHERE salary_record_id = :salary_record_id
               AND id <> :exclude_payment_id
               ' . $statusWhere
        );
        $statement->execute([
            'salary_record_id' => $salaryRecordId,
            'exclude_payment_id' => $excludePaymentId,
        ]);

        $row = $statement->fetch();

        return [
            'paid_total' => round((float) ($row['paid_total'] ?? 0), 2),
            'deduction_total' => round((float) ($row['deduction_total'] ?? 0), 2),
        ];
    }

    public function updatePayment(array $payload): void
    {
        $statement = $this->db->prepare(
            'UPDATE employee_salary_payments
             SET salary_record_id = :salary_record_id,
                 payment_date = :payment_date,
                 branch_id = :branch_id,
                 currency = :currency,
                 payment_currency = :payment_currency,
                 payment_method = :payment_method,
                 treasury_account_id = :treasury_account_id,
                 paid_amount = :paid_amount,
                 source_paid_amount = :source_paid_amount,
                 exchange_rate_to_salary = :exchange_rate_to_salary,
                 deduction_amount = :deduction_amount,
                 deduction_reason = :deduction_reason,
                 reference_number = :reference_number,
                 remarks = :remarks,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute($payload);
    }

    public function voidPayment(int $paymentId, string $voidReason, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE employee_salary_payments
             SET status = "void",
                 void_reason = :void_reason,
                 voided_by_user_id = :voided_by_user_id,
                 voided_at = NOW(),
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id
               AND LOWER(REPLACE(COALESCE(status, "posted"), " ", "_")) <> "void"'
        );
        $statement->execute([
            'id' => $paymentId,
            'void_reason' => $voidReason,
            'voided_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function activePaymentCountForRecord(int $salaryRecordId): int
    {
        $statusWhere = $this->paymentStatusColumnExists()
            ? 'AND LOWER(REPLACE(COALESCE(status, "posted"), " ", "_")) <> "void"'
            : '';
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM employee_salary_payments
             WHERE salary_record_id = :salary_record_id
             ' . $statusWhere
        );
        $statement->execute(['salary_record_id' => $salaryRecordId]);

        return (int) $statement->fetchColumn();
    }

    public function activePaymentIdsForRecord(int $salaryRecordId): array
    {
        $statusWhere = $this->paymentStatusColumnExists()
            ? 'AND LOWER(REPLACE(COALESCE(status, "posted"), " ", "_")) <> "void"'
            : '';
        $statement = $this->db->prepare(
            'SELECT id
             FROM employee_salary_payments
             WHERE salary_record_id = :salary_record_id
             ' . $statusWhere . '
             ORDER BY payment_date ASC, id ASC'
        );
        $statement->execute(['salary_record_id' => $salaryRecordId]);

        return array_map('intval', array_column($statement->fetchAll() ?: [], 'id'));
    }

    public function voidRecord(int $recordId, string $voidReason, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE employee_salary_records
             SET status = "void",
                 void_reason = :void_reason,
                 voided_by_user_id = :voided_by_user_id,
                 voided_at = NOW(),
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id
               AND LOWER(REPLACE(COALESCE(status, "open"), " ", "_")) <> "void"'
        );
        $statement->execute([
            'id' => $recordId,
            'void_reason' => $voidReason,
            'voided_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function employeePerformanceRows(array $accessibleBranchIds, string $monthStart, string $monthEnd): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $serviceChargeCurrencyExpression = $this->columnExists('booking_services', 'service_charge_currency')
            ? 'COALESCE(NULLIF(bs.service_charge_currency, ""), bs.currency)'
            : 'bs.currency';

        $buildBranchScope = static function (array $branchIds, string $prefix): array {
            $placeholders = [];
            $params = [];
            foreach (array_values($branchIds) as $index => $branchId) {
                $key = $prefix . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $branchId;
            }

            return ['IN (' . implode(', ', $placeholders) . ')', $params];
        };

        [$overallCurrencyClause, $overallCurrencyParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_overall_currency_branch_');
        [$monthCurrencyClause, $monthCurrencyParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_month_currency_branch_');
        [$serviceCurrencyClause, $serviceCurrencyParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_service_currency_branch_');
        [$penaltyCurrencyClause, $penaltyCurrencyParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_penalty_currency_branch_');
        [$overallMetricsClause, $overallMetricsParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_overall_metrics_branch_');
        [$monthMetricsClause, $monthMetricsParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_month_metrics_branch_');
        [$serviceProfitClause, $serviceProfitParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_service_profit_branch_');
        [$penaltyProfitClause, $penaltyProfitParams] = $buildBranchScope($accessibleBranchIds, 'salary_perf_penalty_profit_branch_');

        $params = array_merge(
            $overallCurrencyParams,
            $monthCurrencyParams,
            $serviceCurrencyParams,
            $penaltyCurrencyParams,
            $overallMetricsParams,
            $monthMetricsParams,
            $serviceProfitParams,
            $penaltyProfitParams,
            [
                'salary_perf_month_currency_start' => $monthStart,
                'salary_perf_month_currency_end' => $monthEnd,
                'salary_perf_service_currency_start' => $monthStart,
                'salary_perf_service_currency_end' => $monthEnd,
                'salary_perf_penalty_currency_start' => $monthStart,
                'salary_perf_penalty_currency_end' => $monthEnd,
                'salary_perf_month_metrics_start' => $monthStart,
                'salary_perf_month_metrics_end' => $monthEnd,
                'salary_perf_service_profit_start' => $monthStart,
                'salary_perf_service_profit_end' => $monthEnd,
                'salary_perf_penalty_profit_start' => $monthStart,
                'salary_perf_penalty_profit_end' => $monthEnd,
            ]
        );

        $statement = $this->db->prepare(
            'SELECT
                bs.id AS business_source_id,
                bs.name AS business_source_name,
                bs.code AS business_source_code,
                cur.currency,
                COALESCE(overall_metrics.total_sale, 0) AS total_sale,
                COALESCE(overall_metrics.total_outstanding, 0) AS total_outstanding,
                COALESCE(month_metrics.month_sale, 0) AS month_sale,
                COALESCE(month_metrics.month_outstanding, 0) AS month_outstanding,
                COALESCE(month_profit.month_profit, 0) AS month_profit
             FROM business_sources bs
             LEFT JOIN (
                SELECT currency_rows.business_source_id, currency_rows.currency
                FROM (
                    SELECT b.business_source_id, receivable.currency
                    FROM bookings b
                    INNER JOIN (
                        SELECT
                            booking_reference,
                            service_line_reference,
                            currency,
                            MAX(due_amount) AS current_due_amount,
                            MAX(outstanding_amount) AS current_outstanding_amount
                        FROM customer_receivable_items
                        GROUP BY booking_reference, service_line_reference, currency
                    ) receivable ON receivable.booking_reference = b.booking_reference
                    WHERE b.branch_id ' . $overallCurrencyClause . '
                    GROUP BY b.business_source_id, receivable.currency

                    UNION

                    SELECT b.business_source_id, receivable.currency
                    FROM bookings b
                    INNER JOIN (
                        SELECT
                            booking_reference,
                            service_line_reference,
                            currency,
                            MAX(due_amount) AS current_due_amount,
                            MAX(outstanding_amount) AS current_outstanding_amount
                        FROM customer_receivable_items
                        GROUP BY booking_reference, service_line_reference, currency
                    ) receivable ON receivable.booking_reference = b.booking_reference
                    WHERE b.branch_id ' . $monthCurrencyClause . '
                      AND b.booking_date >= :salary_perf_month_currency_start
                      AND b.booking_date <= :salary_perf_month_currency_end
                    GROUP BY b.business_source_id, receivable.currency

                    UNION

                    SELECT b.business_source_id, ' . $serviceChargeCurrencyExpression . ' AS currency
                    FROM bookings b
                    INNER JOIN booking_services bs ON bs.booking_id = b.id
                    WHERE b.branch_id ' . $serviceCurrencyClause . '
                      AND b.booking_date >= :salary_perf_service_currency_start
                      AND b.booking_date <= :salary_perf_service_currency_end
                      AND bs.is_active = 1
                    GROUP BY b.business_source_id, ' . $serviceChargeCurrencyExpression . '

                    UNION

                    SELECT b.business_source_id, e.currency
                    FROM booking_service_events e
                    INNER JOIN bookings b ON b.id = e.booking_id
                    WHERE b.branch_id ' . $penaltyCurrencyClause . '
                      AND e.event_type = "cancel"
                      AND e.event_status = "posted"
                      AND e.event_date >= :salary_perf_penalty_currency_start
                      AND e.event_date <= :salary_perf_penalty_currency_end
                    GROUP BY b.business_source_id, e.currency
                ) currency_rows
                GROUP BY currency_rows.business_source_id, currency_rows.currency
             ) cur ON cur.business_source_id = bs.id
             LEFT JOIN (
                SELECT
                    b.business_source_id,
                    receivable.currency,
                    ROUND(SUM(receivable.current_due_amount), 2) AS total_sale,
                    ROUND(SUM(receivable.current_outstanding_amount), 2) AS total_outstanding
                FROM bookings b
                INNER JOIN (
                    SELECT
                        booking_reference,
                        service_line_reference,
                        currency,
                        MAX(due_amount) AS current_due_amount,
                        MAX(outstanding_amount) AS current_outstanding_amount
                    FROM customer_receivable_items
                    GROUP BY booking_reference, service_line_reference, currency
                ) receivable ON receivable.booking_reference = b.booking_reference
                WHERE b.branch_id ' . $overallMetricsClause . '
                GROUP BY b.business_source_id, receivable.currency
             ) overall_metrics
                ON overall_metrics.business_source_id = bs.id
               AND overall_metrics.currency <=> cur.currency
             LEFT JOIN (
                SELECT
                    b.business_source_id,
                    receivable.currency,
                    ROUND(SUM(receivable.current_due_amount), 2) AS month_sale,
                    ROUND(SUM(receivable.current_outstanding_amount), 2) AS month_outstanding
                FROM bookings b
                INNER JOIN (
                    SELECT
                        booking_reference,
                        service_line_reference,
                        currency,
                        MAX(due_amount) AS current_due_amount,
                        MAX(outstanding_amount) AS current_outstanding_amount
                    FROM customer_receivable_items
                    GROUP BY booking_reference, service_line_reference, currency
                ) receivable ON receivable.booking_reference = b.booking_reference
                WHERE b.branch_id ' . $monthMetricsClause . '
                  AND b.booking_date >= :salary_perf_month_metrics_start
                  AND b.booking_date <= :salary_perf_month_metrics_end
                GROUP BY b.business_source_id, receivable.currency
             ) month_metrics
                ON month_metrics.business_source_id = bs.id
               AND month_metrics.currency <=> cur.currency
             LEFT JOIN (
                SELECT
                    profit_rows.business_source_id,
                    profit_rows.currency,
                    ROUND(SUM(profit_rows.amount), 2) AS month_profit
                FROM (
                    SELECT
                        b.business_source_id,
                        ' . $serviceChargeCurrencyExpression . ' AS currency,
                        COALESCE(bs.service_charge, 0) AS amount
                    FROM bookings b
                    INNER JOIN booking_services bs ON bs.booking_id = b.id
                    WHERE b.branch_id ' . $serviceProfitClause . '
                      AND b.booking_date >= :salary_perf_service_profit_start
                      AND b.booking_date <= :salary_perf_service_profit_end
                      AND bs.is_active = 1

                    UNION ALL

                    SELECT
                        b.business_source_id,
                        e.currency,
                        COALESCE(
                            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                            e.penalty_amount,
                            0
                        ) AS amount
                    FROM booking_service_events e
                    INNER JOIN bookings b ON b.id = e.booking_id
                    WHERE b.branch_id ' . $penaltyProfitClause . '
                      AND e.event_type = "cancel"
                      AND e.event_status = "posted"
                      AND e.event_date >= :salary_perf_penalty_profit_start
                      AND e.event_date <= :salary_perf_penalty_profit_end
                ) profit_rows
                GROUP BY profit_rows.business_source_id, profit_rows.currency
             ) month_profit
                ON month_profit.business_source_id = bs.id
               AND month_profit.currency <=> cur.currency
             WHERE bs.is_active = 1
             ORDER BY bs.name ASC, cur.currency ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

}
