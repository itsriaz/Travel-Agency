<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountLedgerRepository;

final class AccountLedgerService extends Service
{
    public function report(array $filters, array $branchIds): array
    {
        $data = (new AccountLedgerRepository($this->app))->reportData($branchIds, $filters);

        return $this->buildReport($data);
    }

    /**
     * Public so the accounting scenarios can be regression-tested without
     * creating or mutating customer/supplier operational records.
     */
    public function buildReport(array $data): array
    {
        $positions = array_values(array_filter(
            (array) ($data['positions'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $customerReceipts = array_values(array_filter(
            (array) ($data['customerReceipts'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $customerAdvanceMovements = array_values(array_filter(
            (array) ($data['customerAdvanceMovements'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $refundMovements = array_values(array_filter(
            (array) ($data['refundMovements'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $customerCreditTransfers = array_values(array_filter(
            (array) ($data['customerCreditTransfers'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $reissueAdjustments = array_values(array_filter(
            (array) ($data['reissueAdjustments'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $accountSupplierSettlements = array_values(array_filter(
            (array) ($data['accountSupplierSettlements'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));

        $rows = [];
        $positionSummaries = [];

        foreach ($positions as $position) {
            $summaryKey = $this->summaryKey($position);
            $currency = $this->currency($position);
            $supplierPosition = $position;
            $supplierPosition['currency'] = strtoupper(trim((string) ($position['cost_currency'] ?? $currency))) ?: $currency;
            $supplierSummaryKey = $this->summaryKey($supplierPosition);
            $reconciliation = $this->supplierPaymentReconciliation($position);
            $grossSupplierPaid = $reconciliation['grossSupplierPaid'];
            $crossBookingSupplierCredit = min(
                max($this->amount($position['cross_booking_supplier_credit_applied'] ?? 0), 0.0),
                $grossSupplierPaid
            );
            $cashSupplierPaid = round(max($grossSupplierPaid - $crossBookingSupplierCredit, 0.0), 2);

            if ($cashSupplierPaid > 0.005) {
                $paymentReference = trim((string) ($position['supplier_payment_references'] ?? ''));
                $rows[] = $this->movementRow(
                    $supplierPosition,
                    (string) (($position['supplier_payment_date'] ?? '') !== ''
                        ? $position['supplier_payment_date']
                        : (($position['cancellation_date'] ?? '') !== ''
                            ? $position['cancellation_date']
                            : ($position['booking_date'] ?? ''))),
                    $paymentReference !== '' ? $paymentReference : (string) ($position['booking_reference'] ?? ''),
                    'Supplier payment made',
                    (string) ($position['supplier_name'] ?? 'Supplier'),
                    0.0,
                    $cashSupplierPaid,
                    20,
                    $reconciliation['status'],
                    $reconciliation['note']
                );
            }

            if ($crossBookingSupplierCredit > 0.005) {
                $creditReference = trim((string) ($position['cross_booking_supplier_credit_references'] ?? ''));
                $rows[] = $this->movementRow(
                    $supplierPosition,
                    (string) (($position['cross_booking_supplier_credit_date'] ?? '') !== ''
                        ? $position['cross_booking_supplier_credit_date']
                        : ($position['booking_date'] ?? '')),
                    $creditReference !== '' ? $creditReference : (string) ($position['booking_reference'] ?? ''),
                    'Supplier credit applied',
                    (string) ($position['supplier_name'] ?? 'Supplier'),
                    0.0,
                    0.0,
                    21,
                    'Non-cash Credit',
                    sprintf(
                        '%s %s retained with the supplier was applied to this booking; no cash or bank account moved.',
                        $this->currency($supplierPosition),
                        $this->money($crossBookingSupplierCredit)
                    )
                );
            }

            $customerOutstanding = max($this->amount($position['current_customer_outstanding'] ?? 0), 0.0);
            $supplierPayable = max($this->amount($position['current_supplier_payable'] ?? 0), 0.0);
            $supplierPenalty = max($this->amount($position['supplier_penalty_amount'] ?? 0), 0.0);
            $supplierRefundExpected = max($this->amount($position['expected_supplier_refund_amount'] ?? 0), 0.0);
            $supplierRefundReceived = max($this->amount($position['supplier_refund_received'] ?? 0), 0.0);
            $supplierRefundBacked = max($grossSupplierPaid - $supplierPenalty, 0.0);
            $supplierRefundEntitlement = min($supplierRefundExpected, $supplierRefundBacked);
            $supplierRefundReceivable = max($supplierRefundEntitlement - $supplierRefundReceived, 0.0);

            $customerRefundCredit = max($this->amount($position['available_customer_refund_credit'] ?? 0), 0.0);
            $customerRefundPaid = max($this->amount($position['customer_refund_paid'] ?? 0), 0.0);
            $currentCustomerCredit = array_key_exists('current_customer_unallocated_credit', $position)
                ? max($this->amount($position['current_customer_unallocated_credit']), 0.0)
                : $customerRefundCredit;
            $customerRefundPayable = min(
                max($customerRefundCredit - $customerRefundPaid, 0.0),
                $currentCustomerCredit
            );

            $hasCancellation = (int) ($position['cancellation_event_id'] ?? 0) > 0;
            $supplierPenaltyInInvoiceCurrency = $supplierPenalty;
            if ($this->currency($supplierPosition) !== $currency) {
                $supplierPenaltyInInvoiceCurrency = round(
                    $supplierPenalty * max((float) ($position['pricing_exchange_rate'] ?? 1), 0),
                    2
                );
            }
            $recognizedProfit = $hasCancellation
                ? round(
                    $this->amount($position['customer_final_charge_amount'] ?? 0)
                    - $supplierPenaltyInInvoiceCurrency,
                    2
                )
                : $this->amount($position['original_profit_loss'] ?? 0);

            if (! isset($positionSummaries[$summaryKey])) {
                $positionSummaries[$summaryKey] = $this->emptySummary($position, $currency);
            }
            if (! isset($positionSummaries[$supplierSummaryKey])) {
                $positionSummaries[$supplierSummaryKey] = $this->emptySummary(
                    $supplierPosition,
                    $this->currency($supplierPosition)
                );
            }

            $positionSummaries[$summaryKey]['customerReceivable'] += $customerOutstanding;
            $positionSummaries[$summaryKey]['invoiceAmount'] += max($this->amount($position['current_customer_due'] ?? 0), 0.0);
            $positionSummaries[$summaryKey]['totalSaleAmount'] += max($this->amount($position['original_invoice_amount'] ?? 0), 0.0);
            $positionSummaries[$summaryKey]['customerRefundPayable'] += $customerRefundPayable;
            $positionSummaries[$summaryKey]['recognizedProfit'] += $recognizedProfit;
            $positionSummaries[$summaryKey]['invoiceReferences'][(string) ($position['booking_reference'] ?? '')] = true;
            $positionSummaries[$supplierSummaryKey]['supplierPayable'] += $supplierPayable;
            $positionSummaries[$supplierSummaryKey]['supplierRefundReceivable'] += $supplierRefundReceivable;
            $positionSummaries[$supplierSummaryKey]['invoiceReferences'][(string) ($position['booking_reference'] ?? '')] = true;
            $positionSummaries[$supplierSummaryKey]['controlWarnings'] += $reconciliation['hasControlWarning'] ? 1 : 0;
        }

        foreach ($customerReceipts as $receipt) {
            $rows[] = $this->movementRow(
                $receipt,
                (string) ($receipt['movement_date'] ?? ''),
                (string) ($receipt['movement_reference'] ?? ''),
                'Customer payment received',
                (string) ($receipt['customer_name'] ?? 'Customer'),
                $this->amount($receipt['debit_amount'] ?? 0),
                0.0,
                10,
                'Received',
                $this->movementDetail($receipt)
            );
        }

        foreach ($customerAdvanceMovements as $movement) {
            $movementType = (string) ($movement['movement_type'] ?? '');
            $isReturn = $movementType === 'Customer advance returned';
            $isApplication = $movementType === 'Customer advance applied';
            $row = $this->movementRow(
                $movement,
                (string) ($movement['movement_date'] ?? ''),
                (string) ($movement['movement_reference'] ?? ''),
                $isApplication
                    ? 'Customer advance applied to ' . (string) ($movement['target_booking_reference'] ?? 'invoice')
                    : ($isReturn ? 'Customer advance returned' : 'Customer advance received'),
                (string) ($movement['customer_name'] ?? 'Customer'),
                $this->amount($movement['debit_amount'] ?? 0),
                $this->amount($movement['credit_amount'] ?? 0),
                $isApplication ? 14 : ($isReturn ? 12 : 11),
                $isApplication ? 'Advance Applied' : ($isReturn ? 'Returned' : 'Received'),
                $this->movementDetail($movement)
            );
            if ($isApplication) {
                $row['is_internal_transfer'] = true;
                $row['is_customer_advance_transfer'] = true;
            }
            $rows[] = $row;
        }

        foreach ($refundMovements as $movement) {
            $supplierRefund = $this->amount($movement['supplier_refund_amount'] ?? 0);
            if ($supplierRefund > 0.005) {
                $supplierMovement = $movement;
                $supplierMovement['currency'] = strtoupper(trim((string) ($movement['supplier_currency'] ?? $movement['currency'] ?? 'PKR'))) ?: 'PKR';
                $supplierRefundRetainedAsCredit = (string) ($movement['payment_method'] ?? '') === 'supplier_credit';
                $rows[] = $this->movementRow(
                    $supplierMovement,
                    (string) ($movement['movement_date'] ?? ''),
                    (string) ($movement['movement_reference'] ?? ''),
                    $supplierRefundRetainedAsCredit
                        ? 'Supplier refund retained as supplier credit'
                        : 'Supplier refund received',
                    (string) ($movement['supplier_name'] ?? 'Supplier'),
                    $supplierRefundRetainedAsCredit ? 0.0 : $supplierRefund,
                    0.0,
                    30,
                    $supplierRefundRetainedAsCredit ? 'Non-cash Credit' : 'Received',
                    $supplierRefundRetainedAsCredit
                        ? $this->currency($supplierMovement) . ' ' . $this->money($supplierRefund) . ' available with supplier'
                        : $this->movementDetail($movement)
                );
            }

            $customerRefund = $this->amount($movement['customer_refund_amount'] ?? 0);
            if ($customerRefund > 0.005) {
                $rows[] = $this->movementRow(
                    $movement,
                    (string) ($movement['movement_date'] ?? ''),
                    (string) ($movement['movement_reference'] ?? ''),
                    'Customer refund paid',
                    (string) ($movement['customer_name'] ?? 'Customer'),
                    0.0,
                    $customerRefund,
                    40,
                    'Paid',
                    $this->movementDetail($movement)
                );
            }
        }

        foreach ($customerCreditTransfers as $transfer) {
            $isSource = (string) ($transfer['transfer_direction'] ?? '') === 'source';
            $counterpartBooking = (string) ($transfer['counterpart_booking_reference'] ?? '');
            $row = $this->movementRow(
                $transfer,
                (string) ($transfer['movement_date'] ?? ''),
                (string) ($transfer['movement_reference'] ?? ''),
                $isSource
                    ? 'Customer credit applied to ' . $counterpartBooking
                    : 'Customer credit received from ' . $counterpartBooking,
                (string) ($transfer['customer_name'] ?? 'Customer'),
                $this->amount($transfer['debit_amount'] ?? 0),
                $this->amount($transfer['credit_amount'] ?? 0),
                $isSource ? 45 : 15,
                'Credit Applied',
                $this->movementDetail($transfer)
            );
            $row['is_internal_transfer'] = true;
            if ((string) ($transfer['receipt_purpose'] ?? '') === 'customer_advance') {
                $row['is_customer_advance_transfer'] = true;
                $row['ledger_entry'] = $isSource
                    ? 'Customer advance applied to ' . $counterpartBooking
                    : 'Customer advance applied to invoice';
            }
            $rows[] = $row;
        }

        foreach ($reissueAdjustments as $adjustment) {
            $payload = json_decode((string) ($adjustment['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $supplierCharge = $this->amount($payload['supplier_cost_difference_amount'] ?? $adjustment['fare_difference_amount'] ?? 0);
            $agencyFee = $this->amount($payload['agency_service_fee_amount'] ?? $adjustment['service_fee_amount'] ?? 0);
            $customerDelta = $this->amount($payload['customer_delta'] ?? (
                (float) ($adjustment['fare_difference_amount'] ?? 0)
                + (float) ($adjustment['service_fee_amount'] ?? 0)
            ));
            $supplierCurrency = (string) ($payload['supplier_currency'] ?? $adjustment['currency'] ?? 'PKR');
            $agencyCurrency = (string) ($payload['agency_service_fee_currency'] ?? $adjustment['currency'] ?? 'PKR');
            $row = $this->movementRow(
                $adjustment,
                (string) ($adjustment['movement_date'] ?? ''),
                (string) ($adjustment['movement_reference'] ?? ''),
                'Ticket reissued — invoice adjusted by ' . $this->currency($adjustment) . ' ' . $this->money($customerDelta),
                (string) ($adjustment['customer_name'] ?? 'Customer'),
                0.0,
                0.0,
                25,
                'Invoice Adjusted',
                'Supplier charge ' . $supplierCurrency . ' ' . $this->money($supplierCharge)
                    . ' | Agency fee ' . $agencyCurrency . ' ' . $this->money($agencyFee)
            );
            $row['is_financial_position_only'] = true;
            $rows[] = $row;
        }

        foreach ($accountSupplierSettlements as $settlement) {
            $row = $this->movementRow(
                $settlement,
                (string) ($settlement['movement_date'] ?? ''),
                (string) ($settlement['movement_reference'] ?? ''),
                'Account balance applied to linked supplier',
                (string) ($settlement['supplier_name'] ?? 'Supplier'),
                0.0,
                $this->amount($settlement['allocated_amount'] ?? 0),
                22,
                'Adjusted',
                'Non-cash account and supplier balance adjustment'
            );
            $row['is_internal_transfer'] = true;
            $row['is_account_supplier_settlement'] = true;
            $rows[] = $row;
        }

        usort($rows, static function (array $left, array $right): int {
            $dateCompare = strcmp((string) ($left['entry_date_raw'] ?? ''), (string) ($right['entry_date_raw'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $bookingCompare = (int) ($left['booking_id'] ?? 0) <=> (int) ($right['booking_id'] ?? 0);
            if ($bookingCompare !== 0) {
                return $bookingCompare;
            }

            $sortCompare = (int) ($left['ledger_sort_order'] ?? 50) <=> (int) ($right['ledger_sort_order'] ?? 50);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return strcmp((string) ($left['transaction_reference'] ?? ''), (string) ($right['transaction_reference'] ?? ''));
        });

        $runningBalances = [];
        foreach ($rows as &$row) {
            $summaryKey = (string) ($row['summary_drilldown_key'] ?? '');
            $runningBalances[$summaryKey] = round(
                (float) ($runningBalances[$summaryKey] ?? 0)
                + (float) ($row['raw_debit_amount'] ?? 0)
                - (float) ($row['raw_credit_amount'] ?? 0),
                2
            );
            $row['raw_balance_amount'] = $runningBalances[$summaryKey];
            $row['balance_amount'] = $this->balance($runningBalances[$summaryKey]);

            if (! isset($positionSummaries[$summaryKey])) {
                $positionSummaries[$summaryKey] = $this->emptySummary($row, $this->currency($row));
            }
            if (! (bool) ($row['is_internal_transfer'] ?? false)) {
                $positionSummaries[$summaryKey]['cashDebit'] += (float) ($row['raw_debit_amount'] ?? 0);
                $positionSummaries[$summaryKey]['cashCredit'] += (float) ($row['raw_credit_amount'] ?? 0);
            } elseif (
                ! (bool) ($row['is_customer_advance_transfer'] ?? false)
                && str_starts_with((string) ($row['ledger_entry'] ?? ''), 'Customer credit received from')
            ) {
                // This is not new cash, but it is settlement received by the target
                // booking. Include it in that booking's Debit summary.
                $positionSummaries[$summaryKey]['customerCreditAppliedIn'] += (float) ($row['raw_debit_amount'] ?? 0);
            }
            if ((bool) ($row['is_account_supplier_settlement'] ?? false)) {
                $positionSummaries[$summaryKey]['accountSupplierSettlement'] += (float) ($row['raw_credit_amount'] ?? 0);
            }
            switch ((string) ($row['ledger_entry'] ?? '')) {
                case 'Customer payment received':
                    $positionSummaries[$summaryKey]['customerPaid'] += (float) ($row['raw_debit_amount'] ?? 0);
                    break;
                case 'Customer advance received':
                    $positionSummaries[$summaryKey]['customerAdvanceReceived'] += (float) ($row['raw_debit_amount'] ?? 0);
                    break;
                case 'Customer advance returned':
                    $positionSummaries[$summaryKey]['customerAdvanceReturned'] += (float) ($row['raw_credit_amount'] ?? 0);
                    break;
                case 'Supplier payment made':
                    $positionSummaries[$summaryKey]['supplierPaid'] += (float) ($row['raw_credit_amount'] ?? 0);
                    break;
                case 'Supplier refund received':
                    $positionSummaries[$summaryKey]['supplierRefundReceived'] += (float) ($row['raw_debit_amount'] ?? 0);
                    break;
                case 'Customer refund paid':
                    $positionSummaries[$summaryKey]['customerRefundPaid'] += (float) ($row['raw_credit_amount'] ?? 0);
                    break;
            }
            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            if ($bookingReference !== '' && strtoupper($bookingReference) !== 'ADVANCE') {
                $positionSummaries[$summaryKey]['invoiceReferences'][$bookingReference] = true;
            }
        }
        unset($row);

        $summaryRows = $this->summaryRows($positionSummaries);
        return [
            'rows' => $rows,
            'summaryRows' => $summaryRows,
            'summaryCards' => $this->profitSummaryCards($positionSummaries),
        ];
    }

    /**
     * Keep the top-level Account Ledger summary concise while showing the
     * commercial position for the selected scope in each native currency.
     * Internal credit transfers, refunds, and reversals are deliberately not
     * added to sales or receipts, so these totals do not inflate money values.
     */
    private function profitSummaryCards(array $summaries): array
    {
        $totalsByCurrency = [];
        foreach ($summaries as $summary) {
            $currency = strtoupper(trim((string) ($summary['currency'] ?? 'PKR'))) ?: 'PKR';
            if (! isset($totalsByCurrency[$currency])) {
                $totalsByCurrency[$currency] = [
                    'sale' => 0.0,
                    'received' => 0.0,
                    'outstanding' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $totalsByCurrency[$currency]['sale'] += (float) ($summary['totalSaleAmount'] ?? 0);
            $totalsByCurrency[$currency]['received'] += (float) ($summary['customerPaid'] ?? 0);
            $totalsByCurrency[$currency]['outstanding'] += (float) ($summary['customerReceivable'] ?? 0);
            $totalsByCurrency[$currency]['profit'] += (float) ($summary['recognizedProfit'] ?? 0);
        }

        ksort($totalsByCurrency);

        $cards = [];
        foreach ($totalsByCurrency as $currency => $totals) {
            $cards[] = $this->card(
                'Total Sale / ' . $currency,
                $this->money(round($totals['sale'], 2)),
                'account_summary',
                'Account Summary'
            );
            $cards[] = $this->card(
                'Total Received / ' . $currency,
                $this->money(round($totals['received'], 2)),
                'account_summary',
                'Account Summary'
            );
            $cards[] = $this->card(
                'Total Outstanding / ' . $currency,
                $this->money(round($totals['outstanding'], 2)),
                'account_summary',
                'Account Summary'
            );
            $cards[] = $this->card(
                'Total Profit / ' . $currency,
                $this->balance(round($totals['profit'], 2), 'Profit', 'Loss'),
                'account_summary',
                'Account Summary'
            );
        }

        return $cards;
    }

    private function supplierPaymentReconciliation(array $position): array
    {
        $originalCost = max($this->amount($position['original_supplier_cost'] ?? 0), 0.0);
        $currentAllocated = max($this->amount($position['current_supplier_paid'] ?? 0), 0.0);
        $releasedPaymentCredit = max($this->amount($position['released_supplier_payment_credit'] ?? 0), 0.0);
        $expectedRefundCredit = max($this->amount($position['expected_supplier_refund_credit'] ?? 0), 0.0);
        $supplierRefundReceived = max($this->amount($position['supplier_refund_received'] ?? 0), 0.0);
        $supplierPenalty = max($this->amount($position['supplier_penalty_amount'] ?? 0), 0.0);

        $directlySupported = round($currentAllocated + $releasedPaymentCredit, 2);
        $creditSupported = round($currentAllocated + $expectedRefundCredit, 2);
        $refundSupported = round($currentAllocated + $supplierRefundReceived, 2);
        $grossSupplierPaid = max($directlySupported, $creditSupported, $refundSupported);

        $cancellationSettlementTotal = round($supplierPenalty + max(
            $this->amount($position['expected_supplier_refund_amount'] ?? 0),
            $supplierRefundReceived
        ), 2);
        if (
            $cancellationSettlementTotal > 0.005
            && ($currentAllocated > 0.005 || $releasedPaymentCredit > 0.005 || $expectedRefundCredit > 0.005 || $supplierRefundReceived > 0.005)
        ) {
            $grossSupplierPaid = max($grossSupplierPaid, $cancellationSettlementTotal);
        }

        if ($originalCost > 0.005 && $grossSupplierPaid > $originalCost) {
            $grossSupplierPaid = $originalCost;
        }
        $grossSupplierPaid = round($grossSupplierPaid, 2);

        $wasReconstructed = $grossSupplierPaid > $directlySupported + 0.005;
        $hasControlWarning = $wasReconstructed
            && $releasedPaymentCredit <= 0.005
            && $expectedRefundCredit <= 0.005;

        $status = $wasReconstructed ? 'Reconciled' : 'Paid';
        $note = '';
        if ($wasReconstructed) {
            $note = sprintf(
                'Gross supplier settlement reconciled from %s retained/allocated and %s refundable or refunded.',
                $this->money($currentAllocated),
                $this->money(max($grossSupplierPaid - $currentAllocated, 0))
            );
        }

        if ($hasControlWarning) {
            $status = 'Reconciled - review';
            $note .= ($note !== '' ? ' ' : '')
                . 'The gross historical payment is not fully preserved in the current supplier allocation rows.';
        }

        return [
            'grossSupplierPaid' => $grossSupplierPaid,
            'status' => $status,
            'note' => $note,
            'hasControlWarning' => $hasControlWarning,
        ];
    }

    private function movementRow(
        array $source,
        string $date,
        string $reference,
        string $event,
        string $counterparty,
        float $debit,
        float $credit,
        int $sortOrder,
        string $status,
        string $note
    ): array {
        $currency = $this->currency($source);
        $customerName = trim((string) ($source['customer_name'] ?? $source['lead_traveler_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Booking Party';
        }

        return [
            'booking_id' => (int) ($source['booking_id'] ?? 0),
            'branch_id' => (int) ($source['branch_id'] ?? 0),
            'branch_name' => (string) ($source['branch_name'] ?? ''),
            'business_source_name' => (string) (($source['business_source_name'] ?? '') !== ''
                ? $source['business_source_name']
                : 'Unassigned Account'),
            'lead_traveler_id' => (int) ($source['lead_traveler_id'] ?? 0),
            'lead_traveler_name' => $customerName,
            'contact_mobile' => (string) (($source['contact_mobile'] ?? '') !== '' ? $source['contact_mobile'] : 'N/A'),
            'booking_reference' => (string) ($source['booking_reference'] ?? ''),
            'booking_reference_href' => (int) ($source['booking_id'] ?? 0) > 0
                ? url('/workspace?booking_id=' . (int) $source['booking_id'])
                : '',
            'entry_date_raw' => $date,
            'booking_date' => $date,
            'transaction_reference' => $reference !== '' ? $reference : 'N/A',
            'ledger_entry' => $event,
            'counterparty' => $counterparty !== '' ? $counterparty : 'N/A',
            'passenger_name' => (string) (($source['passenger_name'] ?? '') !== '' ? $source['passenger_name'] : $customerName),
            'pnr' => (string) (($source['pnr'] ?? '') !== '' ? $source['pnr'] : 'N/A'),
            'route' => (string) (($source['route'] ?? '') !== '' ? $source['route'] : 'N/A'),
            'currency' => $currency,
            'settlement_status' => $status,
            'debit_amount' => $this->money($debit),
            'credit_amount' => $this->money($credit),
            'raw_debit_amount' => round($debit, 2),
            'raw_credit_amount' => round($credit, 2),
            'balance_amount' => $this->balance($debit - $credit),
            'raw_balance_amount' => round($debit - $credit, 2),
            'transaction_note' => $note !== '' ? $note : 'N/A',
            'ledger_sort_order' => $sortOrder,
            'summary_drilldown_key' => $this->summaryKey($source),
        ];
    }

    private function movementDetail(array $row): string
    {
        $parts = [];
        $method = trim((string) ($row['payment_method'] ?? ''));
        if ($method !== '') {
            $parts[] = ucwords(str_replace('_', ' ', $method));
        }
        $treasuryAccount = trim((string) ($row['treasury_account_name'] ?? ''));
        if ($treasuryAccount !== '') {
            $parts[] = $treasuryAccount;
        }
        $externalReference = trim((string) ($row['external_reference'] ?? ''));
        if ($externalReference !== '') {
            $parts[] = $externalReference;
        }

        return $parts !== [] ? implode(' | ', $parts) : 'N/A';
    }

    private function summaryRows(array $summaries): array
    {
        $rows = [];
        foreach ($summaries as $summaryKey => $summary) {
            $cashBalance = round(
                (float) ($summary['cashDebit'] ?? 0)
                - (float) ($summary['cashCredit'] ?? 0),
                2
            );
            $pending = [];
            foreach ([
                'Cust. receivable' => (float) ($summary['customerReceivable'] ?? 0),
                'Supp. payable' => (float) ($summary['supplierPayable'] ?? 0),
                'Supp. refund due' => (float) ($summary['supplierRefundReceivable'] ?? 0),
                'Cust. refund due' => (float) ($summary['customerRefundPayable'] ?? 0),
            ] as $label => $amount) {
                if ($amount > 0.005) {
                    $pending[] = $label . ' ' . $this->money($amount);
                }
            }

            $rows[] = [
                'summary_drilldown_key' => $summaryKey,
                'summary_drilldown_label' => (string) ($summary['accountName'] ?? '')
                    . ' / ' . (string) ($summary['customerName'] ?? '')
                    . ' / ' . (string) ($summary['currency'] ?? 'PKR'),
                'business_source_name' => (string) ($summary['accountName'] ?? ''),
                'lead_traveler_name' => (string) ($summary['customerName'] ?? ''),
                'contact_mobile' => (string) ($summary['contactMobile'] ?? 'N/A'),
                'currency' => (string) ($summary['currency'] ?? 'PKR'),
                'total_debit' => $this->money(
                    (float) ($summary['customerPaid'] ?? 0)
                    + (float) ($summary['customerAdvanceReceived'] ?? 0)
                    + (float) ($summary['customerCreditAppliedIn'] ?? 0)
                ),
                'total_credit' => $this->money(
                    (float) ($summary['supplierPaid'] ?? 0)
                    + (float) ($summary['customerAdvanceReturned'] ?? 0)
                    + (float) ($summary['accountSupplierSettlement'] ?? 0)
                ),
                'invoice_amount' => $this->money((float) ($summary['invoiceAmount'] ?? 0)),
                'outstanding_amount' => $this->money((float) ($summary['customerReceivable'] ?? 0)),
                'customer_paid' => $this->money((float) ($summary['customerPaid'] ?? 0)),
                'supplier_paid' => $this->money((float) ($summary['supplierPaid'] ?? 0)),
                'supplier_refund_received' => $this->money((float) ($summary['supplierRefundReceived'] ?? 0)),
                'customer_refund_paid' => $this->money((float) ($summary['customerRefundPaid'] ?? 0)),
                'cash_balance' => $this->balance($cashBalance),
                'recognized_profit_loss' => $this->money((float) ($summary['recognizedProfit'] ?? 0)),
                'pending_status' => $pending !== [] ? implode(' | ', $pending) : 'Settled',
                'invoice_count' => (string) count(array_filter(
                    array_keys((array) ($summary['invoiceReferences'] ?? [])),
                    static fn (string $reference): bool => trim($reference) !== ''
                )),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return [
                (string) ($left['business_source_name'] ?? ''),
                (string) ($left['lead_traveler_name'] ?? ''),
                (string) ($left['currency'] ?? ''),
            ] <=> [
                (string) ($right['business_source_name'] ?? ''),
                (string) ($right['lead_traveler_name'] ?? ''),
                (string) ($right['currency'] ?? ''),
            ];
        });

        return $rows;
    }

    private function summaryCards(array $summaries, int $controlWarningCount): array
    {
        $totals = [];
        foreach ($summaries as $summary) {
            $currency = (string) ($summary['currency'] ?? 'PKR');
            if (! isset($totals[$currency])) {
                $totals[$currency] = [
                    'cashDebit' => 0.0,
                    'cashCredit' => 0.0,
                    'customerPaid' => 0.0,
                    'supplierPaid' => 0.0,
                    'supplierRefundReceived' => 0.0,
                    'customerRefundPaid' => 0.0,
                    'recognizedProfit' => 0.0,
                    'customerReceivable' => 0.0,
                    'supplierPayable' => 0.0,
                    'supplierRefundReceivable' => 0.0,
                    'customerRefundPayable' => 0.0,
                ];
            }

            foreach (array_keys($totals[$currency]) as $key) {
                $totals[$currency][$key] += (float) ($summary[$key] ?? 0);
            }
        }

        ksort($totals);
        $cards = [];
        foreach ($totals as $currency => $currencyTotals) {
            $cashBalance = round($currencyTotals['cashDebit'] - $currencyTotals['cashCredit'], 2);
            $cards[] = $this->card('Customer Paid / ' . $currency, $this->money($currencyTotals['customerPaid']), 'cash', 'Actual Money Movement');
            $cards[] = $this->card('Supplier Paid / ' . $currency, $this->money($currencyTotals['supplierPaid']), 'cash', 'Actual Money Movement');
            $cards[] = $this->card('Supplier Refund Received / ' . $currency, $this->money($currencyTotals['supplierRefundReceived']), 'refunds', 'Refund Movement');
            $cards[] = $this->card('Customer Refund Paid / ' . $currency, $this->money($currencyTotals['customerRefundPaid']), 'refunds', 'Refund Movement');
            $cards[] = $this->card('Closing Balance / ' . $currency, $this->balance($cashBalance), 'cash', 'Actual Money Movement');
            $cards[] = $this->card(
                'Recognized Result / ' . $currency,
                $this->balance($currencyTotals['recognizedProfit'], 'Profit', 'Loss'),
                'result',
                'Booking Result'
            );
            $cards[] = $this->card('Customer Receivable / ' . $currency, $this->money($currencyTotals['customerReceivable']), 'pending', 'Pending Settlements');
            $cards[] = $this->card('Supplier Payable / ' . $currency, $this->money($currencyTotals['supplierPayable']), 'pending', 'Pending Settlements');
            $cards[] = $this->card('Supplier Refund Receivable / ' . $currency, $this->money($currencyTotals['supplierRefundReceivable']), 'pending', 'Pending Settlements');
            $cards[] = $this->card('Customer Refund Payable / ' . $currency, $this->money($currencyTotals['customerRefundPayable']), 'pending', 'Pending Settlements');
        }

        if ($controlWarningCount > 0) {
            $cards[] = [
                'label' => 'Reconciliation Controls',
                'value' => (string) $controlWarningCount,
                'note' => 'Historical gross supplier payments were reconstructed from retained cost and refund evidence. Review the marked rows.',
                'tone' => 'warning',
            ];
        }

        return $cards;
    }

    private function card(string $label, string $value, string $group, string $groupTitle): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'group' => $group,
            'group_title' => $groupTitle,
        ];
    }

    private function emptySummary(array $source, string $currency): array
    {
        return [
            'accountName' => (string) (($source['business_source_name'] ?? '') !== ''
                ? $source['business_source_name']
                : 'Unassigned Account'),
            'customerName' => (string) (($source['customer_name'] ?? $source['lead_traveler_name'] ?? '') !== ''
                ? ($source['customer_name'] ?? $source['lead_traveler_name'])
                : 'Booking Party'),
            'contactMobile' => (string) (($source['contact_mobile'] ?? '') !== '' ? $source['contact_mobile'] : 'N/A'),
            'currency' => $currency,
            'cashDebit' => 0.0,
            'cashCredit' => 0.0,
            'customerPaid' => 0.0,
            'customerAdvanceReceived' => 0.0,
            'customerAdvanceReturned' => 0.0,
            'invoiceAmount' => 0.0,
            'totalSaleAmount' => 0.0,
            'customerCreditAppliedIn' => 0.0,
            'accountSupplierSettlement' => 0.0,
            'supplierPaid' => 0.0,
            'supplierRefundReceived' => 0.0,
            'customerRefundPaid' => 0.0,
            'recognizedProfit' => 0.0,
            'customerReceivable' => 0.0,
            'supplierPayable' => 0.0,
            'supplierRefundReceivable' => 0.0,
            'customerRefundPayable' => 0.0,
            'invoiceReferences' => [],
            'controlWarnings' => 0,
        ];
    }

    private function summaryKey(array $row): string
    {
        return implode('|', [
            mb_strtolower(trim((string) ($row['business_source_name'] ?? 'Unassigned Account'))),
            (string) ((int) ($row['lead_traveler_id'] ?? 0)),
            mb_strtolower(trim((string) ($row['customer_name'] ?? $row['lead_traveler_name'] ?? 'Booking Party'))),
            $this->currency($row),
        ]);
    }

    private function currency(array $row): string
    {
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));

        return $currency !== '' ? $currency : 'PKR';
    }

    private function amount(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2);
    }

    private function balance(float $amount, string $debitLabel = 'Dr', string $creditLabel = 'Cr'): string
    {
        $amount = round($amount, 2);
        if (abs($amount) < 0.005) {
            return '0.00';
        }

        return $this->money(abs($amount)) . ' ' . ($amount > 0 ? $debitLabel : $creditLabel);
    }
}
