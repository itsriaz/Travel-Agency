<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReportRepository;
use App\Repositories\SupplierRepository;

final class SupplierLedgerService extends Service
{
    public function report(array $filters, array $branchIds): array
    {
        $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
        $rows = (new ReportRepository($this->app))->supplierLedger(
            $branchIds,
            $filters['dateFrom'] ?? null,
            $filters['dateTo'] ?? null,
            '',
            (string) ($filters['airline'] ?? ''),
            (int) ($filters['supplierId'] ?? 0),
            (int) ($filters['businessSourceId'] ?? 0),
            (string) ($filters['bookingReference'] ?? '')
        );

        return $this->buildReport($rows, $currency, trim((string) ($filters['bookingReference'] ?? '')) !== '');
    }

    /**
     * Supplier-ledger presentation is intentionally independent from the
     * customer and agency account ledgers.
     *
     * Client convention:
     * - Debit: supplier invoice, adjustment, or refund/value received.
     * - Credit: payment/value given to the supplier.
     */
    public function buildReport(array $rows, string $currencyFilter = '', bool $bookingScoped = false): array
    {
        // The full supplier account already includes the original advance payment.
        // A booking-only view excludes that payment, so it needs the application
        // row to show how its invoice was settled.
        $rows = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => ! is_array($row)
                || (
                    ($bookingScoped || (string) ($row['entry_type'] ?? '') !== 'Advance Applied')
                    && ! (
                        (string) ($row['entry_type'] ?? '') === 'Payable Adjustment'
                        && (
                            str_contains((string) ($row['source_reference'] ?? ''), '-CUR-OLD')
                            || trim((string) ($row['narration'] ?? '')) === 'Original-currency payable reversed by service financial correction'
                        )
                    )
                )
        ));
        $rows = $this->consolidateSupplierPaymentComponents($rows);
        $rows = $this->consolidateSupplierPayableState($rows);
        $rows = $this->dropStaleAdvanceAdjustmentsAfterCurrencyCorrection($rows);
        $rows = $this->reconcileHistoricalCancellationGross($rows);
        $currencyFilter = strtoupper(trim($currencyFilter));
        if ($currencyFilter !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (mixed $row): bool => is_array($row) && $this->currency($row) === $currencyFilter
            ));
        }
        $paymentRepository = new SupplierRepository($this->app);
        $paymentCache = [];
        $debitSummary = [];
        $creditSummary = [];
        $advanceUsedSummary = [];
        $advanceReturnedSummary = [];
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
            $displayDebitAmount = $debitAmount;
            $displayCreditAmount = $creditAmount;
            $effectiveDebitAmount = $debitAmount;
            $effectiveCreditAmount = $creditAmount;

            $bookingId = (int) ($row['booking_id'] ?? 0);
            $supplierPaymentId = (int) ($row['supplier_payment_id'] ?? 0);
            $journalEntryId = (int) ($row['journal_entry_id'] ?? 0);
            $postedByName = trim((string) ($row['posted_by_name'] ?? ''));
            $narration = trim((string) ($row['narration'] ?? ''));
            $bookingReferenceHref = '';
            if ($supplierPaymentId > 0) {
                $bookingReferenceHref = url('/suppliers/settlements/global?posted_payment_id=' . $supplierPaymentId);
            } elseif ($bookingId > 0) {
                $bookingReferenceHref = url('/workspace?booking_id=' . $bookingId);
            }

            $payment = null;
            if ($supplierPaymentId > 0) {
                if (! array_key_exists($supplierPaymentId, $paymentCache)) {
                    $paymentCache[$supplierPaymentId] = $paymentRepository->findSupplierPaymentById($supplierPaymentId);
                }
                $payment = $paymentCache[$supplierPaymentId];
            }

            $sourceEntryType = trim((string) ($row['entry_type'] ?? ''));
            if ($sourceEntryType === '') {
                $sourceEntryType = 'Supplier Ledger Entry';
            }
            $paymentStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($row['payment_status'] ?? $payment['status'] ?? ''))));
            $isVoidedPayment = $supplierPaymentId > 0
                && $paymentStatus === 'void'
                && in_array($sourceEntryType, ['Supplier Payment', 'Supplier Advance / Overpayment', 'Customer Paid Supplier'], true);
            $isVoidedPaymentReversal = $supplierPaymentId > 0
                && $paymentStatus === 'void'
                && $sourceEntryType === 'Supplier Payment Reversed';
            if ($isVoidedPaymentReversal) {
                continue;
            }
            if ($bookingScoped && $isVoidedPayment) {
                // Keep voided payment history in the full account, not a booking's financial movements.
                continue;
            }
            $entryType = $this->particular($row, $sourceEntryType);
            $trace = '';
            $sourceReference = trim((string) ($row['source_reference'] ?? ''));
            $isSupplierCurrencyCorrection = $sourceEntryType === 'Payable Adjustment'
                && (
                    str_contains(mb_strtolower($narration), 'currency correction')
                    || str_contains(mb_strtolower($narration), 'service financial correction')
                    || str_contains(mb_strtolower($narration), 'new-currency payable')
                    || str_contains(mb_strtolower($narration), 'original-currency payable')
                    || str_contains($sourceReference, '-CUR-')
                );
            if (
                in_array($sourceEntryType, ['Supplier Refund Received', 'Supplier Refund Retained as Credit'], true)
                && $narration !== ''
            ) {
                $trace = $narration;
            } elseif (
                in_array($sourceEntryType, ['Supplier Refund Received', 'Supplier Refund Retained as Credit'], true)
            ) {
                $trace = 'Cancellation settlement';
            } elseif ($isSupplierCurrencyCorrection && $postedByName !== '') {
                $trace = 'Currency correction by ' . $postedByName;
            } elseif (in_array($sourceEntryType, ['Payable Created', 'Payable Adjustment'], true)) {
                $trace = 'Default currency ' . $currency;
            } elseif ($supplierPaymentId > 0) {
                $trace = 'Payment #' . $supplierPaymentId;
            } elseif ($bookingId > 0) {
                $trace = 'Booking #' . $bookingId;
            } elseif ($narration !== '') {
                $trace = $narration;
            }
            if ($isVoidedPayment) {
                $effectiveDebitAmount = 0.0;
                $effectiveCreditAmount = 0.0;
                $entryType = 'Supplier payment voided';
                $voidReason = trim((string) ($row['void_reason'] ?? $payment['void_reason'] ?? ''));
                $voidedAmount = $creditAmount > 0.005 ? $creditAmount : $debitAmount;
                $traceParts = [];
                if ($voidReason !== '') {
                    $traceParts[] = 'Voided: ' . $voidReason;
                } else {
                    $traceParts[] = 'Voided payment';
                }
                if ($voidedAmount > 0.005) {
                    $traceParts[] = 'Original amount ' . $currency . ' ' . $this->money($voidedAmount);
                }
                $trace = implode(' | ', $traceParts);
            }

            $runningBalances[$ledgerKey] = round(
                ($runningBalances[$ledgerKey] ?? 0.0) + $effectiveDebitAmount - $effectiveCreditAmount,
                2
            );

            $reportRows[] = [
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'supplier_name' => $supplierName,
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'ledger_date' => (string) (($row['ledger_date'] ?? '') !== '' ? $row['ledger_date'] : 'N/A'),
                'booking_id' => $bookingId,
                'booking_reference' => (string) (($row['booking_reference'] ?? '') !== '' ? $row['booking_reference'] : 'N/A'),
                'booking_reference_href' => $bookingReferenceHref,
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'N/A'),
                'supplier_payment_id' => $supplierPaymentId,
                'journal_entry_id' => $journalEntryId,
                'posted_by_name' => $postedByName !== '' ? $postedByName : 'System',
                'narration' => $narration,
                'currency' => $currency,
                'raw_debit_amount' => $effectiveDebitAmount,
                'raw_credit_amount' => $effectiveCreditAmount,
                'raw_balance_amount' => $runningBalances[$ledgerKey],
                'debit_amount' => $this->money($displayDebitAmount),
                'credit_amount' => $this->money($displayCreditAmount),
                'balance_amount' => $this->balance($runningBalances[$ledgerKey]),
                'entry_type' => $entryType,
                'source_entry_type' => $sourceEntryType,
                'trace' => $trace,
                'reconciliation_status' => (string) ($row['reconciliation_status'] ?? ''),
                'reconciliation_note' => (string) ($row['reconciliation_note'] ?? ''),
                'exclude_from_footer_totals' => $isVoidedPayment ? 1 : 0,
            ];

            if ($isVoidedPayment) {
                $creditSummary[$currency] ??= 0.0;
                continue;
            }
            switch ($sourceEntryType) {
                case 'Payable Created':
                case 'Payable Adjustment':
                case 'Payable Reversed on Cancellation':
                case 'Supplier Penalty Retained':
                    $debitSummary[$currency] = round(
                        ($debitSummary[$currency] ?? 0.0) + $effectiveDebitAmount - $effectiveCreditAmount,
                        2
                    );
                    break;
                case 'Supplier Payment':
                case 'Supplier Advance / Overpayment':
                case 'Customer Paid Supplier':
                    $creditSummary[$currency] = round(($creditSummary[$currency] ?? 0.0) + $effectiveCreditAmount, 2);
                    break;
                case 'Advance Applied':
                    if ($bookingScoped) {
                        $advanceUsedSummary[$currency] = round(($advanceUsedSummary[$currency] ?? 0.0) + $effectiveCreditAmount, 2);
                    }
                    break;
                case 'Supplier Credit Moved to Advance':
                    if ($bookingScoped) {
                        $advanceReturnedSummary[$currency] = round(($advanceReturnedSummary[$currency] ?? 0.0) + $effectiveDebitAmount, 2);
                    }
                    break;
            }
        }

        $balanceSummary = [];
        foreach ($runningBalances as $ledgerKey => $balance) {
            $parts = explode('|', (string) $ledgerKey);
            $currency = (string) end($parts);
            if ($currency === '') {
                $currency = 'PKR';
            }
            $balanceSummary[$currency] = round(($balanceSummary[$currency] ?? 0.0) + (float) $balance, 2);
        }

        return [
            'rows' => $reportRows,
            'summaryCards' => array_merge(
                $this->summaryCards('Supplier Debit', $debitSummary, $currencyFilter),
                $this->summaryCards('Gross Paid to Supplier', $creditSummary, $currencyFilter),
                $advanceUsedSummary !== [] ? $this->summaryCards('Advance Used', $advanceUsedSummary, $currencyFilter) : [],
                $advanceReturnedSummary !== [] ? $this->summaryCards('Returned to Advance', $advanceReturnedSummary, $currencyFilter) : [],
                $this->balanceSummaryCards($balanceSummary, $currencyFilter)
            ),
        ];
    }

    private function particular(array $row, string $sourceEntryType): string
    {
        $customerName = trim((string) ($row['customer_name'] ?? ''));

        return match ($sourceEntryType) {
            'Payable Created' => 'Supplier invoice recorded',
            'Payable Adjustment' => $this->amount($row['debit_amount'] ?? 0) > $this->amount($row['credit_amount'] ?? 0)
                ? 'Supplier credit note received'
                : 'Supplier debit note recorded',
            'Payable Reversed on Cancellation' => 'Supplier invoice reversed on cancellation',
            'Supplier Payment' => 'Supplier payment made',
            'Supplier Credit Applied' => 'Supplier credit applied',
            'Supplier Payment Reversed' => 'Supplier payment reversed',
            'Supplier Penalty Retained' => 'Supplier cancellation charge',
            'Supplier Refund Received' => 'Supplier refund received',
            'Supplier Refund Retained as Credit' => 'Supplier refund retained as supplier credit',
            'Supplier Credit Moved to Advance' => 'Supplier refund moved to advance',
            'Advance Applied' => 'Supplier advance applied',
            'Refund Advance Applied' => 'Supplier refund credit applied',
            'Advance Adjustment' => 'Supplier advance adjustment',
            'Customer Paid Supplier' => $customerName !== '' ? 'Customer ' . $customerName . ' paid supplier.' : 'Customer paid supplier.',
            'Supplier Advance / Overpayment' => 'Supplier advance paid',
            'Linked Account Balance Adjustment' => 'Linked account balance adjustment',
            default => $sourceEntryType,
        };
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
        $liveAllocationIdsCache = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $paymentId = (int) ($row['supplier_payment_id'] ?? 0);
            $entryType = (string) ($row['entry_type'] ?? '');
            if (
                $paymentId <= 0
                || ! in_array($entryType, ['Supplier Payment', 'Supplier Advance / Overpayment', 'Customer Paid Supplier'], true)
            ) {
                continue;
            }

            $key = implode('|', [(string) $paymentId, $this->currency($row)]);
            $groups[$key][] = $index;
        }

        foreach ($groups as $indexes) {
            $paymentId = (int) ($rows[$indexes[0]]['supplier_payment_id'] ?? 0);
            $preferredAllocationKeeperIndex = null;
            if ($paymentId > 0 && ! array_key_exists($paymentId, $liveAllocationIdsCache)) {
                $liveAllocationIdsCache[$paymentId] = $this->activeAllocationIdsForPayment($paymentId);
            }
            $liveAllocationIds = $liveAllocationIdsCache[$paymentId] ?? [];

            $allocationDerivedIndexes = array_values(array_filter(
                $indexes,
                fn (int $index): bool => $this->isAllocationDerivedPaymentRow($rows[$index] ?? [])
            ));
            if ($allocationDerivedIndexes !== []) {
                if ($liveAllocationIds === []) {
                    if (! array_key_exists($paymentId, $paymentCache)) {
                        $paymentCache[$paymentId] = $paymentRepository->findSupplierPaymentById($paymentId);
                    }
                    $convertedToAdvance = round((float) ($paymentCache[$paymentId]['converted_advance_amount'] ?? 0), 2);
                    // Cancellation can remove the live allocation while moving
                    // that same paid money to reusable supplier credit. Its
                    // historical payment row must remain in the ledger.
                    if ($convertedToAdvance > 0.005) {
                        $preferredAllocationKeeperIndex = $allocationDerivedIndexes[0];
                    }
                }
                if ($liveAllocationIds === [] && $preferredAllocationKeeperIndex === null) {
                    foreach ($allocationDerivedIndexes as $index) {
                        unset($rows[$index]);
                    }
                    $indexes = array_values(array_filter(
                        $indexes,
                        static fn (int $index): bool => isset($rows[$index])
                    ));
                    if ($indexes === []) {
                        continue;
                    }
                } elseif ($liveAllocationIds !== []) {
                    $matchedAllocationIndexes = [];
                    foreach ($allocationDerivedIndexes as $index) {
                        $allocationId = $this->allocationIdFromPaymentRow($rows[$index] ?? []);
                        if ($allocationId > 0 && in_array($allocationId, $liveAllocationIds, true)) {
                            $matchedAllocationIndexes[] = $index;
                        }
                    }
                    if ($matchedAllocationIndexes === []) {
                        usort($allocationDerivedIndexes, function (int $left, int $right) use ($rows): int {
                            $leftAllocationId = $this->allocationIdFromPaymentRow($rows[$left] ?? []);
                            $rightAllocationId = $this->allocationIdFromPaymentRow($rows[$right] ?? []);
                            if ($leftAllocationId !== $rightAllocationId) {
                                return $rightAllocationId <=> $leftAllocationId;
                            }

                            return ((int) ($rows[$right]['journal_entry_id'] ?? 0)) <=> ((int) ($rows[$left]['journal_entry_id'] ?? 0));
                        });
                        $matchedAllocationIndexes = array_slice($allocationDerivedIndexes, 0, count($liveAllocationIds));
                    }
                    foreach ($allocationDerivedIndexes as $index) {
                        if (in_array($index, $matchedAllocationIndexes, true)) {
                            continue;
                        }
                        unset($rows[$index]);
                    }
                    $indexes = array_values(array_filter(
                        $indexes,
                        static fn (int $index): bool => isset($rows[$index])
                    ));
                    if ($indexes === []) {
                        continue;
                    }
                }

                $survivingAllocationIndexes = array_values(array_filter(
                    $indexes,
                    static fn (int $index): bool => isset($rows[$index])
                ));
                if ($survivingAllocationIndexes !== []) {
                    usort($survivingAllocationIndexes, static function (int $left, int $right) use ($rows): int {
                        return ((int) ($rows[$right]['journal_entry_id'] ?? 0)) <=> ((int) ($rows[$left]['journal_entry_id'] ?? 0));
                    });
                    $preferredAllocationKeeperIndex = $survivingAllocationIndexes[0];
                }
            }

            $customerPaidSupplierIndexes = array_values(array_filter(
                $indexes,
                static fn (int $index): bool => (string) ($rows[$index]['entry_type'] ?? '') === 'Customer Paid Supplier'
            ));
            $paymentIndexes = array_values(array_filter(
                $indexes,
                static fn (int $index): bool => (string) ($rows[$index]['entry_type'] ?? '') === 'Supplier Payment'
            ));
            $advanceIndexes = array_values(array_filter(
                $indexes,
                static fn (int $index): bool => (string) ($rows[$index]['entry_type'] ?? '') === 'Supplier Advance / Overpayment'
            ));

            $keeperIndex = (int) ($customerPaidSupplierIndexes[0] ?? $preferredAllocationKeeperIndex ?? $paymentIndexes[0] ?? $advanceIndexes[0] ?? $indexes[0]);
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
            $hasDirectCustomerPayment = $customerPaidSupplierIndexes !== [];
            $rows[$keeperIndex]['entry_type'] = $hasDirectCustomerPayment ? 'Customer Paid Supplier' : 'Supplier Payment';

            if ($paymentId > 0) {
                if (! array_key_exists($paymentId, $paymentCache)) {
                    $paymentCache[$paymentId] = $paymentRepository->findSupplierPaymentById($paymentId);
                }
                $payment = $paymentCache[$paymentId];
                $paymentMethod = str_replace(' ', '_', mb_strtolower(trim((string) ($payment['payment_method'] ?? ''))));
                $paymentRemarks = mb_strtolower(trim((string) ($payment['remarks'] ?? '')));
                if (
                    $rows[$keeperIndex]['entry_type'] !== 'Customer Paid Supplier'
                    && (
                        $paymentMethod === 'customer_paid_supplier'
                        || str_contains($paymentRemarks, 'customer paid supplier directly')
                    )
                ) {
                    $rows[$keeperIndex]['entry_type'] = 'Customer Paid Supplier';
                }
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

    private function activeAllocationIdsForPayment(int $paymentId): array
    {
        if ($paymentId <= 0) {
            return [];
        }

        $db = $this->app->get('db');
        $statement = $db->prepare(
            'SELECT id
             FROM supplier_payment_allocations
             WHERE supplier_payment_id = :supplier_payment_id
             ORDER BY id ASC'
        );
        $statement->execute(['supplier_payment_id' => $paymentId]);

        return array_values(array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []));
    }

    private function isAllocationDerivedPaymentRow(array $row): bool
    {
        $sourceReference = trim((string) ($row['source_reference'] ?? ''));
        $narration = mb_strtolower(trim((string) ($row['narration'] ?? '')));

        return preg_match('/-(?:REALLOC|ALLOC)-\d+$/', $sourceReference) === 1
            || str_contains($narration, 'allocated to payable')
            || str_contains($narration, 'reconciled internally');
    }

    private function allocationIdFromPaymentRow(array $row): int
    {
        $sourceReference = trim((string) ($row['source_reference'] ?? ''));
        if ($sourceReference !== '' && preg_match('/-(?:REALLOC|ALLOC)-(\d+)$/', $sourceReference, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    /**
     * A single service line can accumulate multiple payable-state movements:
     * the original payable plus later delta adjustments. The supplier ledger
     * should show the net payable state for that line, not only the last
     * adjustment row, otherwise a later +50 adjustment can wrongly replace an
     * original 440 payable and leave a fake 50-payable line in the report.
     */
    private function consolidateSupplierPayableState(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $groupKey = implode('|', [
                (string) ($row['supplier_id'] ?? 0),
                (string) ($row['booking_reference'] ?? ''),
                (string) ($row['service_line_reference'] ?? ''),
            ]);
            $sourceEntryType = (string) ($row['source_entry_type'] ?? $row['entry_type'] ?? '');

            if (! in_array($sourceEntryType, ['Payable Created', 'Payable Adjustment'], true)) {
                continue;
            }

            $groups[$groupKey][] = $index;
        }

        foreach ($groups as $groupKey => $indexes) {
            if (count($indexes) <= 1) {
                continue;
            }

            $currencyCorrectionNewIndexes = array_values(array_filter(
                $indexes,
                static function (int $index) use ($rows): bool {
                    $sourceReference = (string) ($rows[$index]['source_reference'] ?? '');
                    $narration = trim((string) ($rows[$index]['narration'] ?? ''));

                    return str_contains($sourceReference, '-CUR-NEW')
                        || $narration === 'New-currency payable recognized by service financial correction';
                }
            ));
            if ($currencyCorrectionNewIndexes !== []) {
                $keeperIndex = (int) end($currencyCorrectionNewIndexes);
                $keeperCurrency = $this->currency($rows[$keeperIndex] ?? []);
                $keeperJournalEntryId = (int) ($rows[$keeperIndex]['journal_entry_id'] ?? 0);

                foreach ($indexes as $index) {
                    if ($index === $keeperIndex) {
                        continue;
                    }

                    if (
                        $keeperCurrency !== ''
                        && $this->currency($rows[$index] ?? []) === $keeperCurrency
                        && ! $this->isCurrencyCorrectionPayableRow($rows[$index] ?? [])
                        && (int) ($rows[$index]['journal_entry_id'] ?? 0) > $keeperJournalEntryId
                    ) {
                        $rows[$keeperIndex]['debit_amount'] = $this->amount($rows[$keeperIndex]['debit_amount'] ?? 0)
                            + $this->amount($rows[$index]['debit_amount'] ?? 0);
                        $rows[$keeperIndex]['credit_amount'] = $this->amount($rows[$keeperIndex]['credit_amount'] ?? 0)
                            + $this->amount($rows[$index]['credit_amount'] ?? 0);
                    }

                    unset($rows[$index]);
                }

                $rows[$keeperIndex]['entry_type'] = 'Payable Created';
                $rows[$keeperIndex]['source_entry_type'] = 'Payable Created';

                continue;
            }

            // Keep payable adjustments visible as separate ledger movements so
            // the supplier ledger matches the accountant-facing source ledger
            // instead of silently netting debit/credit notes into the invoice.
            continue;
        }

        return array_values($rows);
    }

    /**
     * When a supplier payable changes currency, older advance-adjustment rows
     * from the previous payable currency can remain attached to the same
     * booking/service line. They are reconciliation artifacts, not a second
     * live payable, and must not survive in the supplier ledger beside the
     * new-currency payable truth.
     */
    private function dropStaleAdvanceAdjustmentsAfterCurrencyCorrection(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $groupKey = implode('|', [
                (string) ($row['supplier_id'] ?? 0),
                (string) ($row['booking_reference'] ?? ''),
                (string) ($row['service_line_reference'] ?? ''),
            ]);
            $groups[$groupKey][] = $index;
        }

        foreach ($groups as $indexes) {
            $currencyCorrectionPayableIndexes = array_values(array_filter(
                $indexes,
                fn (int $index): bool => $this->isCurrencyCorrectionPayableRow($rows[$index] ?? [])
            ));
            if ($currencyCorrectionPayableIndexes === []) {
                continue;
            }

            $keeperIndex = (int) end($currencyCorrectionPayableIndexes);
            $livePayableCurrency = $this->currency($rows[$keeperIndex] ?? []);
            if ($livePayableCurrency === '') {
                continue;
            }

            foreach ($indexes as $index) {
                $sourceEntryType = (string) ($rows[$index]['source_entry_type'] ?? $rows[$index]['entry_type'] ?? '');
                if ($sourceEntryType !== 'Advance Adjustment') {
                    continue;
                }

                $adjustmentCurrency = $this->currency($rows[$index] ?? []);
                if ($adjustmentCurrency === $livePayableCurrency) {
                    continue;
                }

                unset($rows[$index]);
            }
        }

        return array_values($rows);
    }

    private function isCurrencyCorrectionPayableRow(array $row): bool
    {
        $sourceEntryType = (string) ($row['source_entry_type'] ?? $row['entry_type'] ?? '');
        if (! in_array($sourceEntryType, ['Payable Created', 'Payable Adjustment'], true)) {
            return false;
        }

        $sourceReference = (string) ($row['source_reference'] ?? '');
        $narration = trim((string) ($row['narration'] ?? ''));

        return str_contains($sourceReference, '-CUR-NEW')
            || $narration === 'New-currency payable recognized by service financial correction';
    }

    /**
     * Some older datasets stored cancellation-era supplier payment/reversal
     * rows as net values after part of the amount had already been classified
     * into refund or retained-credit movements. For the supplier ledger we
     * must rebuild the gross money-out and gross payable-reversal values from
     * the evidenced penalty + refund split so the lifecycle reads correctly.
     */
    private function reconcileHistoricalCancellationGross(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $groups[$this->movementGroupKey($row)][] = $index;
        }

        foreach ($groups as $indexes) {
            $paymentIndexes = [];
            $reversalIndexes = [];
            $evidencedGross = 0.0;

            foreach ($indexes as $index) {
                $entryType = (string) ($rows[$index]['source_entry_type'] ?? $rows[$index]['entry_type'] ?? '');
                if ($entryType === 'Supplier Payment') {
                    $paymentIndexes[] = $index;
                    continue;
                }
                if ($entryType === 'Payable Reversed on Cancellation') {
                    $reversalIndexes[] = $index;
                    continue;
                }
                if (in_array($entryType, ['Supplier Penalty Retained', 'Supplier Refund Received', 'Supplier Refund Retained as Credit'], true)) {
                    $evidencedGross = round($evidencedGross + $this->amount($rows[$index]['credit_amount'] ?? 0), 2);
                }
            }

            if ($evidencedGross <= 0.005) {
                continue;
            }

            foreach ($paymentIndexes as $index) {
                $storedGross = $this->amount($rows[$index]['debit_amount'] ?? 0);
                if ($storedGross + 0.005 >= $evidencedGross) {
                    continue;
                }

                $rows[$index]['debit_amount'] = $evidencedGross;
                $rows[$index]['reconciliation_status'] = 'Reconciled - review';
                $rows[$index]['reconciliation_note'] = 'Gross supplier payment rebuilt from cancellation penalty and refund evidence.';
            }

            foreach ($reversalIndexes as $index) {
                $storedGross = $this->amount($rows[$index]['debit_amount'] ?? 0);
                if ($storedGross + 0.005 >= $evidencedGross) {
                    continue;
                }

                $rows[$index]['debit_amount'] = $evidencedGross;
                $rows[$index]['reconciliation_status'] = 'Reconciled - review';
                $rows[$index]['reconciliation_note'] = 'Gross payable reversal rebuilt from cancellation penalty and refund evidence.';
            }
        }

        return array_values($rows);
    }

    private function movementGroupKey(array $row): string
    {
        return implode('|', [
            (string) ($row['supplier_id'] ?? 0),
            (string) ($row['booking_reference'] ?? ''),
            (string) ($row['service_line_reference'] ?? ''),
            $this->currency($row),
        ]);
    }

    private function summaryCards(string $labelPrefix, array $totals, string $fallbackCurrency = ''): array
    {
        if ($totals === []) {
            $currency = $fallbackCurrency !== '' ? $fallbackCurrency : 'PKR';
            return [['label' => $labelPrefix . ' / ' . $currency, 'value' => $currency . ' 0.00']];
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

    private function balanceSummaryCards(array $totals, string $fallbackCurrency = ''): array
    {
        if ($totals === []) {
            $currency = $fallbackCurrency !== '' ? $fallbackCurrency : 'PKR';
            return [['label' => 'Supplier Balance / ' . $currency, 'value' => $currency . ' 0.00']];
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
