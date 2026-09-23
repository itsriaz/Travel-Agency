<?php

declare(strict_types=1);

namespace App\Services;

final class LinkedPartyLedgerService extends Service
{
    /** Build a read-only statement; neither source ledger nor its allocations are changed. */
    public function buildReport(array $accountRows, array $supplierRows, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        $movements = [];
        foreach ($accountRows as $row) {
            $entry = (string) ($row['ledger_entry'] ?? '');
            $sourceDebit = (float) ($row['raw_debit_amount'] ?? 0);
            $sourceCredit = (float) ($row['raw_credit_amount'] ?? 0);
            $debit = 0.0;
            $credit = 0.0;

            if ((bool) ($row['is_financial_position_only'] ?? false)) {
                $debit = $sourceDebit;
                $credit = $sourceCredit;
            } elseif (in_array($entry, ['Customer payment received', 'Customer advance received'], true)) {
                $credit = $sourceDebit;
            } elseif (in_array($entry, ['Customer refund paid', 'Customer advance returned'], true)) {
                $debit = $sourceCredit;
            } elseif ((bool) ($row['is_internal_transfer'] ?? false)
                && str_starts_with($entry, 'Customer credit ')) {
                $debit = $sourceCredit;
                $credit = $sourceDebit;
            } else {
                continue;
            }

            if (abs($debit) + abs($credit) < 0.005) {
                continue;
            }
            $movements[] = $this->statementRow($row, 'account', $debit, $credit);
        }

        foreach ($supplierRows as $row) {
            // Supplier Ledger presents invoices as Debit and payments as Credit.
            // Reverse that display orientation for the agency's combined position.
            $debit = (float) ($row['raw_credit_amount'] ?? 0);
            $credit = (float) ($row['raw_debit_amount'] ?? 0);
            if (abs($debit) + abs($credit) < 0.005) {
                continue;
            }
            $movements[] = $this->statementRow($row, 'supplier', $debit, $credit);
        }

        usort($movements, static fn (array $left, array $right): int => [
            $left['entry_date_raw'], $left['branch_id'], $left['currency'], $left['source_order'], $left['transaction_reference'],
        ] <=> [
            $right['entry_date_raw'], $right['branch_id'], $right['currency'], $right['source_order'], $right['transaction_reference'],
        ]);

        $currency = strtoupper(trim($currency));
        $opening = [];
        $openingTemplates = [];
        $displayRows = [];
        $totals = [];
        foreach ($movements as $row) {
            if ($currency !== '' && $row['currency'] !== $currency) {
                continue;
            }
            $key = $row['branch_id'] . '|' . $row['currency'];
            $delta = $row['raw_debit_amount'] - $row['raw_credit_amount'];
            if ($dateFrom !== null && $row['entry_date_raw'] < $dateFrom) {
                $opening[$key] = round(($opening[$key] ?? 0.0) + $delta, 2);
                $openingTemplates[$key] = $row;
                $totals[$key]['branch_name'] = $row['branch_name'];
                $totals[$key]['currency'] = $row['currency'];
                continue;
            }
            if ($dateTo !== null && $row['entry_date_raw'] > $dateTo) {
                continue;
            }
            $totals[$key]['branch_name'] = $row['branch_name'];
            $totals[$key]['currency'] = $row['currency'];
            $totals[$key]['debit'] = round(($totals[$key]['debit'] ?? 0.0) + $row['raw_debit_amount'], 2);
            $totals[$key]['credit'] = round(($totals[$key]['credit'] ?? 0.0) + $row['raw_credit_amount'], 2);
            $displayRows[] = $row;
        }

        $running = $opening;
        $openingShown = [];
        $rows = [];
        foreach ($displayRows as $row) {
            $key = $row['branch_id'] . '|' . $row['currency'];
            if ($dateFrom !== null && ! isset($openingShown[$key]) && abs($opening[$key] ?? 0.0) > 0.005) {
                $rows[] = $this->openingRow($row, $dateFrom, $running[$key]);
                $openingShown[$key] = true;
            }
            $running[$key] = round(($running[$key] ?? 0.0) + $row['raw_debit_amount'] - $row['raw_credit_amount'], 2);
            $row['raw_balance_amount'] = $running[$key];
            $row['balance_amount'] = $this->balance($running[$key]);
            $rows[] = $row;
        }

        foreach ($opening as $key => $balance) {
            if (! isset($openingShown[$key]) && abs($balance) > 0.005) {
                $rows[] = $this->openingRow($openingTemplates[$key], (string) $dateFrom, $balance);
            }
        }
        usort($rows, static fn (array $left, array $right): int => [
            $left['entry_date_raw'],
            $left['branch_id'],
            $left['currency'],
            $left['ledger_entry'] === 'Opening balance' ? 0 : 1,
            $left['source_order'] ?? 0,
            $left['transaction_reference'] ?? '',
        ] <=> [
            $right['entry_date_raw'],
            $right['branch_id'],
            $right['currency'],
            $right['ledger_entry'] === 'Opening balance' ? 0 : 1,
            $right['source_order'] ?? 0,
            $right['transaction_reference'] ?? '',
        ]);

        $footerRows = [];
        foreach ($totals as $key => $total) {
            $balance = round(($opening[$key] ?? 0.0) + ($total['debit'] ?? 0.0) - ($total['credit'] ?? 0.0), 2);
            $footerRows[] = [
                '_label' => 'Total ' . $total['branch_name'] . ' / ' . $total['currency'],
                'currency' => $total['currency'],
                'debit_amount' => $this->money((float) ($total['debit'] ?? 0.0)),
                'credit_amount' => $this->money((float) ($total['credit'] ?? 0.0)),
                'balance_amount' => $this->balance($balance),
            ];
        }

        return ['rows' => $rows, 'footerRows' => $footerRows];
    }

    /** Present the same linked relationship using the existing Supplier Ledger convention. */
    public function buildSupplierReport(
        array $accountRows,
        array $supplierRows,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency,
        string $supplierName
    ): array {
        $state = $this->buildReport($accountRows, $supplierRows, $dateFrom, $dateTo, $currency);
        $rows = [];
        foreach ($state['rows'] as $row) {
            $debit = (float) ($row['raw_credit_amount'] ?? 0);
            $credit = (float) ($row['raw_debit_amount'] ?? 0);
            $balance = -1 * (float) ($row['raw_balance_amount'] ?? 0);
            $row['raw_debit_amount'] = $debit;
            $row['raw_credit_amount'] = $credit;
            $row['raw_balance_amount'] = $balance;
            $row['debit_amount'] = $this->money($debit);
            $row['credit_amount'] = $this->money($credit);
            $row['balance_amount'] = $this->balance($balance);
            $row['supplier_name'] = $supplierName;
            $row['ledger_date'] = (string) ($row['booking_date'] ?? '');
            $row['entry_type'] = (string) ($row['ledger_entry'] ?? '');
            $row['trace'] = (string) ($row['transaction_note'] ?? '');
            $rows[] = $row;
        }

        $footerRows = [];
        foreach ($state['footerRows'] as $footerRow) {
            $debit = $this->number((string) ($footerRow['credit_amount'] ?? '0'));
            $credit = $this->number((string) ($footerRow['debit_amount'] ?? '0'));
            $balance = -1 * $this->number((string) ($footerRow['balance_amount'] ?? '0'));
            $footerRows[] = [
                '_label' => (string) ($footerRow['_label'] ?? 'Total'),
                'currency' => (string) ($footerRow['currency'] ?? ''),
                'debit_amount' => $this->money($debit),
                'credit_amount' => $this->money($credit),
                'balance_amount' => $this->balance($balance),
            ];
        }

        return [
            'rows' => $rows,
            'footerRows' => $footerRows,
            'summaryCards' => $this->supplierSummaryCards($footerRows),
        ];
    }

    private function openingRow(array $template, string $date, float $balance): array
    {
        $template['entry_date_raw'] = $date;
        $template['booking_date'] = $date;
        $template['booking_id'] = 0;
        $template['booking_reference'] = '-';
        $template['booking_reference_href'] = '';
        $template['transaction_reference'] = '-';
        $template['ledger_entry'] = 'Opening balance';
        $template['counterparty'] = '-';
        $template['passenger_name'] = '-';
        $template['pnr'] = '-';
        $template['route'] = '-';
        $template['debit_amount'] = '0';
        $template['credit_amount'] = '0';
        $template['raw_debit_amount'] = 0.0;
        $template['raw_credit_amount'] = 0.0;
        $template['balance_amount'] = $this->balance($balance);
        $template['raw_balance_amount'] = $balance;
        $template['transaction_note'] = 'Balance before the selected period';

        return $template;
    }

    private function statementRow(array $row, string $source, float $debit, float $credit): array
    {
        $date = (string) ($source === 'account' ? ($row['entry_date_raw'] ?? '') : ($row['ledger_date'] ?? ''));
        $reference = (string) ($source === 'account'
            ? ($row['transaction_reference'] ?? '')
            : ($row['booking_reference'] ?? ''));

        return [
            'branch_id' => (int) ($row['branch_id'] ?? 0),
            'branch_name' => (string) ($row['branch_name'] ?? ''),
            'entry_date_raw' => $date,
            'booking_date' => $date,
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'booking_reference' => (string) ($row['booking_reference'] ?? ''),
            'booking_reference_href' => (string) ($row['booking_reference_href'] ?? ''),
            'transaction_reference' => $reference,
            'ledger_entry' => (string) ($source === 'account' ? ($row['ledger_entry'] ?? '') : ($row['entry_type'] ?? '')),
            'lead_traveler_name' => (string) ($source === 'account'
                ? ($row['lead_traveler_name'] ?? '')
                : ($row['customer_name'] ?? $row['passenger_name'] ?? '')),
            'counterparty' => (string) ($source === 'account'
                ? ($row['counterparty'] ?? '')
                : ($row['customer_name'] ?? $row['passenger_name'] ?? '')),
            'passenger_name' => (string) ($row['passenger_name'] ?? ''),
            'pnr' => (string) ($row['pnr'] ?? 'N/A'),
            'route' => (string) ($row['route'] ?? 'N/A'),
            'currency' => strtoupper((string) ($row['currency'] ?? 'PKR')),
            'settlement_status' => (string) ($source === 'account' ? ($row['settlement_status'] ?? '') : 'Supplier'),
            'raw_debit_amount' => round($debit, 2),
            'raw_credit_amount' => round($credit, 2),
            'debit_amount' => $this->money($debit),
            'credit_amount' => $this->money($credit),
            'transaction_note' => (string) ($source === 'account' ? ($row['transaction_note'] ?? '') : ($row['trace'] ?? $row['narration'] ?? '')),
            'source_order' => $source === 'account' ? 0 : 1,
        ];
    }

    private function money(float $amount): string
    {
        return number_format(round($amount, 0), 0);
    }

    private function balance(float $amount): string
    {
        return number_format(round($amount, 0), 0, '.', '');
    }

    private function number(string $amount): float
    {
        return round((float) str_replace(',', '', $amount), 2);
    }

    private function supplierSummaryCards(array $footerRows): array
    {
        $totals = [];
        foreach ($footerRows as $footerRow) {
            $currency = strtoupper(trim((string) ($footerRow['currency'] ?? 'PKR'))) ?: 'PKR';
            $totals[$currency]['debit'] = round(
                ($totals[$currency]['debit'] ?? 0.0) + $this->number((string) ($footerRow['debit_amount'] ?? '0')),
                2
            );
            $totals[$currency]['credit'] = round(
                ($totals[$currency]['credit'] ?? 0.0) + $this->number((string) ($footerRow['credit_amount'] ?? '0')),
                2
            );
            $totals[$currency]['balance'] = round(
                ($totals[$currency]['balance'] ?? 0.0) + $this->number((string) ($footerRow['balance_amount'] ?? '0')),
                2
            );
        }
        ksort($totals);

        $cards = [];
        foreach ($totals as $currency => $total) {
            $balance = (float) ($total['balance'] ?? 0);
            $cards[] = ['label' => 'Supplier Debit / ' . $currency, 'value' => $currency . ' ' . $this->money((float) ($total['debit'] ?? 0))];
            $cards[] = ['label' => 'Supplier Credit / ' . $currency, 'value' => $currency . ' ' . $this->money((float) ($total['credit'] ?? 0))];
            $cards[] = [
                'label' => 'Supplier Balance / ' . $currency,
                'value' => $currency . ' ' . $this->money(abs($balance)) . ($balance > 0.005 ? ' Payable' : ($balance < -0.005 ? ' Receivable' : '')),
            ];
        }

        return $cards;
    }
}
