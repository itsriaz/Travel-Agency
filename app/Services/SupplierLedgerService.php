<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReportRepository;
use App\Repositories\SupplierRepository;

final class SupplierLedgerService extends Service
{
    public function report(array $filters, array $branchIds): array
    {
        $rows = (new ReportRepository($this->app))->supplierLedger(
            $branchIds,
            $filters['dateFrom'] ?? null,
            $filters['dateTo'] ?? null,
            (string) ($filters['currency'] ?? ''),
            (string) ($filters['airline'] ?? ''),
            (int) ($filters['supplierId'] ?? 0),
            (int) ($filters['businessSourceId'] ?? 0),
            (string) ($filters['bookingReference'] ?? '')
        );

        return $this->buildReport($rows);
    }

    /**
     * Supplier-ledger presentation is intentionally independent from the
     * customer and agency account ledgers.
     *
     * Client convention:
     * - Debit: supplier invoice, adjustment, or refund/value received.
     * - Credit: payment/value given to the supplier.
     */
    public function buildReport(array $rows): array
    {
        $rows = $this->consolidateSupplierPaymentComponents($rows);
        $rows = $this->reconcileHistoricalSupplierPayments($rows);
        $debitSummary = [];
        $creditSummary = [];
        $balanceSummary = [];
        $runningBalances = [];
        $reportRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $currency = $this->currency($row);
            $supplierName = trim((string) ($row['supplier_name'] ?? ''));
            if ($supplierName === '') {
                $supplierName = 'Supplier pending';
            }

            $ledgerKey = implode('|', [
                (string) ($row['supplier_id'] ?? 0),
                $supplierName,
                $currency,
            ]);

            // The repository exposes the accounting-control orientation.
            // Swap it here only for the client's supplier-ledger convention.
            $debitAmount = $this->amount($row['credit_amount'] ?? 0);
            $creditAmount = $this->amount($row['debit_amount'] ?? 0);
            $runningBalances[$ledgerKey] = round(
                ($runningBalances[$ledgerKey] ?? 0.0) + $debitAmount - $creditAmount,
                2
            );

            $bookingId = (int) ($row['booking_id'] ?? 0);
            $supplierPaymentId = (int) ($row['supplier_payment_id'] ?? 0);
            $bookingReferenceHref = '';
            if ($supplierPaymentId > 0) {
                $bookingReferenceHref = url('/suppliers/settlements/global?posted_payment_id=' . $supplierPaymentId);
            } elseif ($bookingId > 0) {
                $bookingReferenceHref = url('/workspace?booking_id=' . $bookingId);
            }

            $sourceEntryType = trim((string) ($row['entry_type'] ?? ''));
            if ($sourceEntryType === '') {
                $sourceEntryType = 'Supplier Ledger Entry';
            }
            $entryType = $this->particular($sourceEntryType);

            $reportRows[] = [
                'supplier_name' => $supplierName,
                'ledger_date' => (string) (($row['ledger_date'] ?? '') !== '' ? $row['ledger_date'] : 'N/A'),
                'booking_id' => $bookingId,
                'booking_reference' => (string) (($row['booking_reference'] ?? '') !== '' ? $row['booking_reference'] : 'N/A'),
                'booking_reference_href' => $bookingReferenceHref,
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'N/A'),
                'supplier_payment_id' => $supplierPaymentId,
                'currency' => $currency,
                'raw_debit_amount' => $debitAmount,
                'raw_credit_amount' => $creditAmount,
                'raw_balance_amount' => $runningBalances[$ledgerKey],
                'debit_amount' => $this->money($debitAmount),
                'credit_amount' => $this->money($creditAmount),
                'balance_amount' => $this->balance($runningBalances[$ledgerKey]),
                'entry_type' => $entryType,
                'source_entry_type' => $sourceEntryType,
                'reconciliation_status' => (string) ($row['reconciliation_status'] ?? ''),
                'reconciliation_note' => (string) ($row['reconciliation_note'] ?? ''),
                'exclude_from_footer_totals' => 0,
            ];

            if ($this->countsAsOriginalInvoice($sourceEntryType)) {
                $debitSummary[$currency] = round(($debitSummary[$currency] ?? 0.0) + $debitAmount, 2);
            }
            if ($this->countsAsOriginalPayment($sourceEntryType)) {
                $creditSummary[$currency] = round(($creditSummary[$currency] ?? 0.0) + $creditAmount, 2);
            }
            $balanceSummary[$currency] = round(
                ($balanceSummary[$currency] ?? 0.0) + $debitAmount - $creditAmount,
                2
            );
        }

        return [
            'rows' => $reportRows,
            'summaryCards' => array_merge(
                $this->summaryCards('Supplier Debit', $debitSummary),
                $this->summaryCards('Supplier Credit', $creditSummary),
                $this->balanceSummaryCards($balanceSummary)
            ),
        ];
    }

    private function particular(string $sourceEntryType): string
    {
        return match ($sourceEntryType) {
            'Payable Created' => 'Supplier invoice recorded',
            'Payable Adjustment' => 'Supplier invoice adjustment',
            'Payable Reversed on Cancellation' => 'Supplier invoice reversed on cancellation',
            'Supplier Payment' => 'Supplier payment made',
            'Supplier Credit Applied' => 'Supplier credit applied',
            'Supplier Payment Reversed' => 'Supplier payment reversed',
            'Supplier Penalty Retained' => 'Supplier cancellation charge',
            'Supplier Refund Received' => 'Supplier refund received',
            'Supplier Refund Retained as Credit' => 'Supplier refund retained as supplier credit',
            'Advance Applied' => 'Supplier advance applied',
            'Advance Adjustment' => 'Supplier advance adjustment',
            'Customer Paid Supplier' => 'Customer paid supplier',
            'Supplier Advance / Overpayment' => 'Supplier advance paid',
            'Linked Account Balance Adjustment' => 'Linked account balance adjustment',
            default => $sourceEntryType,
        };
    }

    private function reconcileHistoricalSupplierPayments(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $this->sourceGroupKey($row);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'originalInvoice' => 0.0,
                    'currentPayment' => 0.0,
                    'cancellationReversal' => 0.0,
                    'penalty' => 0.0,
                    'refundReceived' => 0.0,
                    'paymentIndexes' => [],
                    'cancellationIndexes' => [],
                ];
            }

            $entryType = (string) ($row['entry_type'] ?? '');
            $sourceDebit = $this->amount($row['debit_amount'] ?? 0);
            $sourceCredit = $this->amount($row['credit_amount'] ?? 0);

            if ($this->countsAsOriginalInvoice($entryType)) {
                $groups[$key]['originalInvoice'] += $sourceCredit;
            }
            if ($this->countsAsOriginalPayment($entryType) || $entryType === 'Supplier Credit Applied') {
                $groups[$key]['currentPayment'] += $sourceDebit;
                $groups[$key]['paymentIndexes'][] = $index;
            }
            if ($entryType === 'Payable Reversed on Cancellation') {
                $groups[$key]['cancellationReversal'] += $sourceDebit;
                $groups[$key]['cancellationIndexes'][] = $index;
            } elseif ($entryType === 'Supplier Penalty Retained') {
                $groups[$key]['penalty'] += $sourceCredit;
            } elseif (in_array($entryType, ['Supplier Refund Received', 'Supplier Refund Retained as Credit'], true)) {
                $groups[$key]['refundReceived'] += $sourceCredit;
            }
        }

        foreach ($groups as $group) {
            $originalInvoice = round((float) $group['originalInvoice'], 2);
            $currentPayment = round((float) $group['currentPayment'], 2);
            $cancellationReversal = round((float) $group['cancellationReversal'], 2);
            $penalty = round((float) $group['penalty'], 2);
            $refundReceived = round((float) $group['refundReceived'], 2);

            // A cancellation reverses the supplier invoice position that was
            // established for the service. Historical events can retain only
            // the penalty as their cost basis after the obligation is reduced,
            // which would leave the received refund appearing as a false
            // supplier balance. Restore the reversal to the invoice amount for
            // this ledger without changing the underlying journals or events.
            $cancellationIndexes = (array) $group['cancellationIndexes'];
            if ($originalInvoice > 0.005 && $cancellationIndexes !== []) {
                $cancellationIndex = (int) end($cancellationIndexes);
                $rows[$cancellationIndex]['debit_amount'] = $originalInvoice;
                $cancellationReversal = $originalInvoice;
            }

            if (
                $originalInvoice <= 0.005
                || $cancellationReversal <= 0.005
                || $currentPayment <= 0.005
            ) {
                continue;
            }

            $refundEvidence = max($refundReceived, $cancellationReversal - $penalty, 0.0);
            $settlementEvidence = min(round($penalty + $refundEvidence, 2), $originalInvoice);
            $grossPayment = min(max($currentPayment, $settlementEvidence), $originalInvoice);
            $reconstructedAmount = round($grossPayment - $currentPayment, 2);
            if ($reconstructedAmount <= 0.005) {
                continue;
            }

            $paymentIndexes = (array) $group['paymentIndexes'];
            if ($paymentIndexes !== []) {
                $paymentIndex = (int) end($paymentIndexes);
                $rows[$paymentIndex]['debit_amount'] = round(
                    (float) ($rows[$paymentIndex]['debit_amount'] ?? 0) + $reconstructedAmount,
                    2
                );
                $rows[$paymentIndex]['reconciliation_status'] = 'Reconciled - review';
                $rows[$paymentIndex]['reconciliation_note'] =
                    'Gross historical supplier payment reconstructed from retained penalty and refundable/refunded value.';
            }
        }

        return $rows;
    }

    /**
     * A supplier payment can be split between an obligation allocation and a
     * converted supplier advance. Both components belong to the same money-out
     * event and must appear once in the supplier ledger. Keeping both rows
     * would duplicate the converted portion and inflate the running balance.
     */
    private function consolidateSupplierPaymentComponents(array $rows): array
    {
        $groups = [];
        $paymentRepository = new SupplierRepository($this->app);
        $paymentCache = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $paymentId = (int) ($row['supplier_payment_id'] ?? 0);
            $entryType = (string) ($row['entry_type'] ?? '');
            if (
                $paymentId <= 0
                || ! in_array($entryType, ['Supplier Payment', 'Supplier Advance / Overpayment'], true)
            ) {
                continue;
            }

            $key = implode('|', [(string) $paymentId, $this->currency($row)]);
            $groups[$key][] = $index;
        }

        foreach ($groups as $indexes) {
            $paymentIndexes = array_values(array_filter(
                $indexes,
                static fn (int $index): bool => (string) ($rows[$index]['entry_type'] ?? '') === 'Supplier Payment'
            ));
            $advanceIndexes = array_values(array_filter(
                $indexes,
                static fn (int $index): bool => (string) ($rows[$index]['entry_type'] ?? '') === 'Supplier Advance / Overpayment'
            ));

            $keeperIndex = (int) ($paymentIndexes[0] ?? $advanceIndexes[0] ?? $indexes[0]);
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            foreach ($indexes as $index) {
                $totalDebit += $this->amount($rows[$index]['debit_amount'] ?? 0);
                $totalCredit += $this->amount($rows[$index]['credit_amount'] ?? 0);
                if ($index !== $keeperIndex) {
                    unset($rows[$index]);
                }
            }

            $rows[$keeperIndex]['debit_amount'] = round($totalDebit, 2);
            $rows[$keeperIndex]['credit_amount'] = round($totalCredit, 2);
            $rows[$keeperIndex]['entry_type'] = 'Supplier Payment';

            $paymentId = (int) ($rows[$keeperIndex]['supplier_payment_id'] ?? 0);
            if ($paymentId > 0) {
                if (! array_key_exists($paymentId, $paymentCache)) {
                    $paymentCache[$paymentId] = $paymentRepository->findSupplierPaymentById($paymentId);
                }
                $payment = $paymentCache[$paymentId];
                $scope = str_replace(' ', '_', mb_strtolower(trim((string) ($payment['payment_scope'] ?? ''))));
                $paymentBookingReference = strtoupper(trim((string) ($payment['booking_reference'] ?? '')));
                if ($scope === 'global' || $paymentBookingReference === 'GLOBAL') {
                    $rows[$keeperIndex]['booking_id'] = 0;
                    $rows[$keeperIndex]['booking_reference'] = (string) ($payment['payment_no'] ?? ('Payment #' . $paymentId));
                    $rows[$keeperIndex]['service_line_reference'] = '';
                    $rows[$keeperIndex]['passenger_name'] = 'Supplier account';
                    $rows[$keeperIndex]['route'] = 'N/A';
                    $rows[$keeperIndex]['airline'] = 'N/A';
                }
            }
        }

        return array_values($rows);
    }

    private function sourceGroupKey(array $row): string
    {
        return implode('|', [
            (string) ($row['supplier_id'] ?? 0),
            (string) ($row['booking_reference'] ?? ''),
            (string) ($row['service_line_reference'] ?? ''),
            $this->currency($row),
        ]);
    }

    private function countsAsOriginalInvoice(string $entryType): bool
    {
        return in_array($entryType, [
            'Payable Created',
            'Payable Adjustment',
        ], true);
    }

    private function countsAsOriginalPayment(string $entryType): bool
    {
        return in_array($entryType, [
            'Supplier Payment',
            'Advance Applied',
            'Customer Paid Supplier',
            'Supplier Advance / Overpayment',
            'Linked Account Balance Adjustment',
        ], true);
    }

    private function summaryCards(string $labelPrefix, array $totals): array
    {
        if ($totals === []) {
            return [['label' => $labelPrefix, 'value' => 'PKR 0.00']];
        }

        $cards = [];
        foreach ($totals as $currency => $amount) {
            $cards[] = [
                'label' => $labelPrefix . ' / ' . $currency,
                'value' => $currency . ' ' . $this->money((float) $amount),
            ];
        }

        return $cards;
    }

    private function balanceSummaryCards(array $totals): array
    {
        if ($totals === []) {
            return [['label' => 'Supplier Balance', 'value' => 'PKR 0.00']];
        }

        $cards = [];
        foreach ($totals as $currency => $amount) {
            $amount = round((float) $amount, 2);
            $cards[] = [
                'label' => 'Supplier Balance / ' . $currency,
                'value' => $currency . ' ' . (abs($amount) <= 0.005 ? '0.00' : $this->balance($amount)),
            ];
        }

        return $cards;
    }

    private function currency(array $row): string
    {
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));

        return $currency !== '' ? $currency : 'PKR';
    }

    private function amount(mixed $value): float
    {
        return round(max((float) $value, 0.0), 2);
    }

    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2);
    }

    private function balance(float $amount): string
    {
        $amount = round($amount, 2);
        if (abs($amount) <= 0.005) {
            return '-';
        }

        return $this->money(abs($amount)) . ($amount > 0 ? ' Payable' : ' Advance');
    }
}
