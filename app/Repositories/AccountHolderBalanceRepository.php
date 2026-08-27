<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use PDO;
use RuntimeException;

final class AccountHolderBalanceRepository extends BaseRepository
{
    public function availableBalances(int $branchId, int $businessSourceId): array
    {
        if ($branchId <= 0 || $businessSourceId <= 0) {
            return [];
        }

        $businessSourceStatement = $this->db->prepare('SELECT name FROM business_sources WHERE id = :id LIMIT 1');
        $businessSourceStatement->execute(['id' => $businessSourceId]);
        $businessSourceName = trim((string) ($businessSourceStatement->fetchColumn() ?: ''));
        if ($businessSourceName === '') {
            return [];
        }

        $hasTreasuryBusinessSource = $this->columnExists('treasury_transactions', 'business_source_id');
        $hasTreasuryCounterparty = $this->columnExists('treasury_transactions', 'counterparty_name');
        $hasTreasuryNarration = $this->columnExists('treasury_transactions', 'narration');
        $treasuryConditions = [];
        $treasuryParams = ['treasury_branch_id' => $branchId];
        if ($hasTreasuryBusinessSource) {
            $treasuryConditions[] = 'tt.business_source_id = :treasury_business_source_id';
            $treasuryParams['treasury_business_source_id'] = $businessSourceId;
        }
        if ($hasTreasuryCounterparty) {
            $legacyPrefixCondition = $hasTreasuryBusinessSource ? 'tt.business_source_id IS NULL AND ' : '';
            $treasuryConditions[] = '('
                . $legacyPrefixCondition
                . 'LOWER(COALESCE(tt.counterparty_name, "")) = LOWER(:legacy_business_source_name_exact)'
                . ')';
            $treasuryParams['legacy_business_source_name_exact'] = $businessSourceName;
        }
        if ($hasTreasuryNarration) {
            $legacyPrefixCondition = $hasTreasuryBusinessSource ? 'tt.business_source_id IS NULL AND ' : '';
            $treasuryConditions[] = '('
                . $legacyPrefixCondition
                . 'LOWER(COALESCE(tt.narration, "")) LIKE CONCAT("%", LOWER(:legacy_business_source_name_like), "%")'
                . ')';
            $treasuryParams['legacy_business_source_name_like'] = $businessSourceName;
        }

        if ($treasuryConditions === []) {
            return [];
        }

        $balancesByCurrency = [];

        $treasuryStatement = $this->db->prepare(
            'SELECT
                tt.currency,
                ROUND(SUM(CASE WHEN tt.transaction_type = "adjustment_increase" THEN tt.amount ELSE 0 END), 2) AS total_increase,
                ROUND(SUM(CASE WHEN tt.transaction_type = "adjustment_decrease" THEN tt.amount ELSE 0 END), 2) AS total_decrease
             FROM treasury_transactions tt
             WHERE tt.status = "posted"
               AND tt.branch_id = :treasury_branch_id
               AND (' . implode(' OR ', $treasuryConditions) . ')
             GROUP BY tt.currency'
        );
        $treasuryStatement->execute($treasuryParams);
        foreach ($treasuryStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency === '') {
                continue;
            }
            $balancesByCurrency[$currency] = [
                'currency' => $currency,
                'total_increase' => round((float) ($row['total_increase'] ?? 0), 2),
                'total_decrease' => round((float) ($row['total_decrease'] ?? 0), 2),
                'total_applied' => 0.0,
            ];
        }

        if ($this->tableExists('account_holder_balance_applications')) {
            $applicationStatement = $this->db->prepare(
                'SELECT
                    ahba.source_currency AS currency,
                    ROUND(SUM(ahba.source_amount), 2) AS total_applied
                 FROM account_holder_balance_applications ahba
                 INNER JOIN customer_receipts cr ON cr.id = ahba.customer_receipt_id
                 WHERE ahba.branch_id = :application_branch_id
                   AND ahba.business_source_id = :application_business_source_id
                   AND cr.status <> "void"
                 GROUP BY ahba.source_currency'
            );
            $applicationStatement->execute([
                'application_branch_id' => $branchId,
                'application_business_source_id' => $businessSourceId,
            ]);

            foreach ($applicationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency === '') {
                    continue;
                }
                if (! isset($balancesByCurrency[$currency])) {
                    $balancesByCurrency[$currency] = [
                        'currency' => $currency,
                        'total_increase' => 0.0,
                        'total_decrease' => 0.0,
                        'total_applied' => 0.0,
                    ];
                }
                $balancesByCurrency[$currency]['total_applied'] = round((float) ($row['total_applied'] ?? 0), 2);
            }
        }

        $balances = [];
        foreach ($balancesByCurrency as $currency => $row) {
            $availableAmount = round(
                (float) ($row['total_increase'] ?? 0)
                - (float) ($row['total_decrease'] ?? 0)
                - (float) ($row['total_applied'] ?? 0),
                2
            );
            if ($availableAmount <= 0.005) {
                continue;
            }
            $row['available_amount'] = $availableAmount;
            $balances[] = $row;
        }

        usort($balances, static fn (array $left, array $right): int => strcmp((string) ($left['currency'] ?? ''), (string) ($right['currency'] ?? '')));

        return $balances;
    }

    public function availableAmount(int $branchId, int $businessSourceId, string $currency): float
    {
        $currency = strtoupper(trim($currency));
        foreach ($this->availableBalances($branchId, $businessSourceId) as $row) {
            if (strtoupper((string) ($row['currency'] ?? '')) === $currency) {
                return round((float) ($row['available_amount'] ?? 0), 2);
            }
        }

        return 0.0;
    }

    public function recordApplication(array $data): int
    {
        if (! $this->tableExists('account_holder_balance_applications')) {
            throw new RuntimeException('Account-holder balance applications table is not available.');
        }

        return $this->transaction(function () use ($data): int {
            $statement = $this->db->prepare(
                'INSERT INTO account_holder_balance_applications (
                    branch_id, business_source_id, customer_receipt_id, booking_reference,
                    source_currency, target_currency, source_amount, target_amount,
                    exchange_rate, applied_date, remarks, created_by_user_id
                 ) VALUES (
                    :branch_id, :business_source_id, :customer_receipt_id, :booking_reference,
                    :source_currency, :target_currency, :source_amount, :target_amount,
                    :exchange_rate, :applied_date, :remarks, :created_by_user_id
                 )'
            );
            $statement->execute([
                'branch_id' => (int) ($data['branch_id'] ?? 0),
                'business_source_id' => (int) ($data['business_source_id'] ?? 0),
                'customer_receipt_id' => (int) ($data['customer_receipt_id'] ?? 0),
                'booking_reference' => ($data['booking_reference'] ?? null) !== null && trim((string) ($data['booking_reference'] ?? '')) !== ''
                    ? trim((string) $data['booking_reference'])
                    : null,
                'source_currency' => strtoupper(trim((string) ($data['source_currency'] ?? 'PKR'))),
                'target_currency' => strtoupper(trim((string) ($data['target_currency'] ?? 'PKR'))),
                'source_amount' => round((float) ($data['source_amount'] ?? 0), 2),
                'target_amount' => round((float) ($data['target_amount'] ?? 0), 2),
                'exchange_rate' => round((float) ($data['exchange_rate'] ?? 1), 8),
                'applied_date' => (string) ($data['applied_date'] ?? date('Y-m-d')),
                'remarks' => ($data['remarks'] ?? null) !== null ? mb_substr(trim((string) $data['remarks']), 0, 500) : null,
                'created_by_user_id' => isset($data['actor_user_id']) ? (int) $data['actor_user_id'] : null,
            ]);

            $applicationId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'account_holder.balance_applied', [
                'user_id' => $data['actor_user_id'] ?? null,
                'application_id' => $applicationId,
                'branch_id' => (int) ($data['branch_id'] ?? 0),
                'business_source_id' => (int) ($data['business_source_id'] ?? 0),
                'customer_receipt_id' => (int) ($data['customer_receipt_id'] ?? 0),
                'source_currency' => strtoupper(trim((string) ($data['source_currency'] ?? 'PKR'))),
                'target_currency' => strtoupper(trim((string) ($data['target_currency'] ?? 'PKR'))),
                'source_amount' => round((float) ($data['source_amount'] ?? 0), 2),
                'target_amount' => round((float) ($data['target_amount'] ?? 0), 2),
                'exchange_rate' => round((float) ($data['exchange_rate'] ?? 1), 8),
            ]);

            return $applicationId;
        });
    }
}
