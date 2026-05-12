<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use RuntimeException;

final class ExchangeRateRepository extends BaseRepository
{
    public function getExactRate(string $fromCurrency, string $toCurrency, string $effectiveDate): ?array
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        $effectiveDate = trim($effectiveDate);

        if ($fromCurrency === '' || $toCurrency === '' || $effectiveDate === '') {
            return null;
        }

        if ($fromCurrency === $toCurrency) {
            return [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'exchange_rate' => 1.0,
                'effective_date' => $effectiveDate,
                'is_derived' => false,
                'stored_from_currency' => $fromCurrency,
                'stored_to_currency' => $toCurrency,
                'stored_rate_value' => 1.0,
            ];
        }

        $direct = $this->findExactStoredRate($fromCurrency, $toCurrency, $effectiveDate);
        if ($direct !== null) {
            return [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'exchange_rate' => round((float) $direct['rate_value'], 8),
                'effective_date' => $effectiveDate,
                'is_derived' => false,
                'stored_from_currency' => $fromCurrency,
                'stored_to_currency' => $toCurrency,
                'stored_rate_value' => round((float) $direct['rate_value'], 8),
            ];
        }

        $reverse = $this->findExactStoredRate($toCurrency, $fromCurrency, $effectiveDate);
        if ($reverse === null) {
            return null;
        }

        $storedRate = (float) ($reverse['rate_value'] ?? 0);
        if ($storedRate <= 0) {
            return null;
        }

        return [
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'exchange_rate' => round(1 / $storedRate, 8),
            'effective_date' => $effectiveDate,
            'is_derived' => true,
            'stored_from_currency' => $toCurrency,
            'stored_to_currency' => $fromCurrency,
            'stored_rate_value' => round($storedRate, 8),
        ];
    }

    public function requireExactRate(string $fromCurrency, string $toCurrency, string $effectiveDate): array
    {
        $rate = $this->getExactRate($fromCurrency, $toCurrency, $effectiveDate);
        if ($rate === null) {
            throw new RuntimeException('FX_RATE_REQUIRED: Today\'s exchange rate is required.');
        }

        return $rate;
    }

    public function upsertDailyRate(
        string $fromCurrency,
        string $toCurrency,
        string $effectiveDate,
        float $rate,
        ?int $branchId = null,
        ?int $userId = null
    ): void {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        $effectiveDate = trim($effectiveDate);

        if ($fromCurrency === '' || $toCurrency === '' || $effectiveDate === '') {
            throw new RuntimeException('Exchange-rate pair and date are required.');
        }

        if ($rate <= 0) {
            throw new RuntimeException('Exchange rate must be greater than zero.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO exchange_rates (
                from_currency, to_currency, rate_value, effective_date, rate_source, notes, is_active
             ) VALUES (
                :from_currency, :to_currency, :rate_value, :effective_date, :rate_source, :notes, 1
             )
             ON DUPLICATE KEY UPDATE
                rate_value = VALUES(rate_value),
                rate_source = VALUES(rate_source),
                notes = VALUES(notes),
                is_active = VALUES(is_active)'
        );
        $statement->execute([
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'rate_value' => round($rate, 8),
            'effective_date' => $effectiveDate,
            'rate_source' => 'manual_settlement',
            'notes' => $branchId !== null ? 'Settlement rate for branch ' . $branchId : 'Settlement rate',
        ]);

        AuditLog::record($this->app, 'exchange_rate.upserted', [
            'user_id' => $userId,
            'branch_id' => $branchId,
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'exchange_rate' => round($rate, 8),
            'effective_date' => $effectiveDate,
        ]);
    }

    public function latestRatesToTarget(array $fromCurrencies, string $targetCurrency, string $asOfDate): array
    {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $normalized = [];
        foreach ($fromCurrencies as $currency) {
            $value = strtoupper(trim((string) $currency));
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        if ($normalized === []) {
            return [];
        }

        $currencies = array_keys($normalized);
        $placeholders = implode(', ', array_fill(0, count($currencies), '?'));
        $sql = 'SELECT er.from_currency, er.rate_value
                FROM exchange_rates er
                INNER JOIN (
                    SELECT from_currency, MAX(effective_date) AS effective_date
                    FROM exchange_rates
                    WHERE to_currency = ?
                      AND is_active = 1
                      AND effective_date <= ?
                      AND from_currency IN (' . $placeholders . ')
                    GROUP BY from_currency
                ) latest
                    ON latest.from_currency = er.from_currency
                   AND latest.effective_date = er.effective_date
                WHERE er.to_currency = ?
                  AND er.is_active = 1';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([$targetCurrency, $asOfDate], $currencies, [$targetCurrency]));

        $rates = [];
        foreach (($statement->fetchAll() ?: []) as $row) {
            $fromCurrency = strtoupper(trim((string) ($row['from_currency'] ?? '')));
            if ($fromCurrency === '') {
                continue;
            }

            $rates[$fromCurrency] = (float) ($row['rate_value'] ?? 0);
        }

        if ($targetCurrency !== '') {
            $rates[$targetCurrency] = 1.0;
        }

        return $rates;
    }

    private function findExactStoredRate(string $fromCurrency, string $toCurrency, string $effectiveDate): ?array
    {
        $statement = $this->db->prepare(
            'SELECT from_currency, to_currency, rate_value, effective_date
             FROM exchange_rates
             WHERE from_currency = :from_currency
               AND to_currency = :to_currency
               AND effective_date = :effective_date
               AND is_active = 1
             LIMIT 1'
        );
        $statement->execute([
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'effective_date' => $effectiveDate,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }
}
