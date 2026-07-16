<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\TreasuryRepository;

final class CustomerPaymentFoundationService extends Service
{
    public function buildWorkspacePreview(
        string $bookingReference,
        ?int $customerTravelerId = null,
        array $accessibleBranchIds = [],
        array $currentBookingContext = []
    ): array
    {
        $repository = new CustomerPaymentRepository($this->app);
        $currentBookingContext = $this->normalizeBookingContext($bookingReference, $currentBookingContext);
        $serviceDirectory = [];
        $servicePassengerDirectory = [];
        $bookingServices = (new BookingServiceRepository($this->app))->servicesByBookingReference($bookingReference);
        foreach ($bookingServices as $serviceRow) {
            $lineReference = (string) $serviceRow['line_reference'];
            $serviceDirectory[$lineReference] = ucwords((string) $serviceRow['service_type']);
            $servicePassengerDirectory[$lineReference] = trim((string) ($serviceRow['passenger_name'] ?? $serviceRow['passenger_name_snapshot'] ?? ''));
        }

        $serviceReceivables = array_map(
            static function (array $row) use ($serviceDirectory, $servicePassengerDirectory): array {
                $lineReference = (string) ($row['service_line_reference'] ?? '');
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'serviceLineReference' => $lineReference,
                    'serviceType' => $serviceDirectory[$lineReference] ?? 'Service',
                    'passengerName' => $servicePassengerDirectory[$lineReference] ?? '',
                    'currency' => (string) ($row['currency'] ?? ''),
                    'dueAmount' => (float) ($row['due_amount'] ?? 0),
                    'allocatedAmount' => (float) ($row['allocated_amount'] ?? 0),
                    'outstandingAmount' => (float) ($row['outstanding_amount'] ?? 0),
                    'status' => ucwords(str_replace('_', ' ', (string) ($row['latest_status'] ?? 'open'))),
                    'nextDueDate' => (string) ($row['next_due_date'] ?? ''),
                ];
            },
            $repository->serviceWiseOutstanding($bookingReference)
        );

        $receipts = array_map(
            static function (array $row): array {
                $statusRaw = (string) ($row['status'] ?? '');
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'receiptNo' => (string) $row['receipt_no'],
                    'receiptDate' => (string) $row['receipt_date'],
                    'receiptPurpose' => (string) ($row['receipt_purpose'] ?? 'booking_payment'),
                    'currency' => (string) $row['currency'],
                    'tenderedAmount' => (float) ($row['tendered_amount'] ?? $row['received_amount'] ?? 0),
                    'receivedAmount' => (float) $row['received_amount'],
                    'allocatedAmount' => (float) $row['allocated_amount'],
                    'unallocatedAmount' => (float) $row['unallocated_amount'],
                    'returnedAmount' => (float) ($row['returned_amount'] ?? 0),
                    'paymentMethod' => (string) $row['payment_method'],
                    'treasuryAccountId' => (int) ($row['treasury_account_id'] ?? 0),
                    'treasuryAccountName' => (string) ($row['treasury_account_name'] ?? ''),
                    'treasuryAccountType' => (string) ($row['treasury_account_type'] ?? ''),
                    'referenceNumber' => (string) ($row['reference_number'] ?? ''),
                    'bankCardDetail' => (string) ($row['bank_card_detail'] ?? ''),
                    'chargesAmount' => (float) ($row['charges_amount'] ?? 0),
                    'status' => ucwords(str_replace('_', ' ', $statusRaw)),
                    'statusRaw' => $statusRaw,
                    'exchangeRateToBooking' => (float) ($row['exchange_rate_to_booking'] ?? 0),
                    'remarks' => (string) ($row['remarks'] ?? ''),
                    'voidReason' => (string) ($row['void_reason'] ?? ''),
                    'voidedByUserId' => (int) ($row['voided_by_user_id'] ?? 0),
                    'voidedAt' => (string) ($row['voided_at'] ?? ''),
                    'reversalReference' => (string) ($row['reversal_reference'] ?? ''),
                    'reversalJournalEntryId' => (int) ($row['reversal_journal_entry_id'] ?? 0),
                ];
            },
            $repository->receiptHistory($bookingReference)
        );
        $internalSettlementReceipts = $this->internalSettlementAdjustmentReceipts($bookingServices, $receipts);
        $receipts = $this->annotateInternalSettlementReceipts($receipts, $internalSettlementReceipts);

        $allocations = array_map(
            static function (array $row) use ($serviceDirectory, $bookingReference): array {
                $dueAmount = (float) ($row['due_amount'] ?? 0);
                $allocatedAmount = (float) ($row['allocated_amount'] ?? 0);
                $receivableBookingReference = (string) ($row['receivable_booking_reference'] ?? $bookingReference);
                $serviceType = (string) ($row['service_type'] ?? '');
                if ($serviceType === '') {
                    $serviceType = $serviceDirectory[(string) ($row['service_line_reference'] ?? '')] ?? 'Service';
                } else {
                    $serviceType = ucwords($serviceType);
                }

                return [
                    'allocationId' => (int) ($row['allocation_id'] ?? 0),
                    'receiptId' => (int) ($row['receipt_id'] ?? 0),
                    'receiptNo' => (string) $row['receipt_no'],
                    'receiptDate' => (string) ($row['receipt_date'] ?? ''),
                    'receiptPurpose' => (string) ($row['receipt_purpose'] ?? 'booking_payment'),
                    'receiptStatusRaw' => (string) ($row['receipt_status'] ?? ''),
                    'receiptStatus' => ucwords(str_replace('_', ' ', (string) ($row['receipt_status'] ?? ''))),
                    'bookingReference' => $receivableBookingReference,
                    'receivableItemId' => (int) ($row['receivable_item_id'] ?? 0),
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'serviceType' => $serviceType,
                    'passengerName' => (string) ($row['passenger_name'] ?? ''),
                    'allocationTrail' => (string) ($row['allocation_note'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'allocatedAmount' => $allocatedAmount,
                    'receivableCurrency' => (string) (($row['receivable_currency'] ?? '') !== '' ? $row['receivable_currency'] : ($row['currency'] ?? '')),
                    'receivableAmountAllocated' => (float) (($row['receivable_amount_allocated'] ?? null) !== null ? $row['receivable_amount_allocated'] : $allocatedAmount),
                    'paymentCurrency' => (string) (($row['payment_currency'] ?? '') !== '' ? $row['payment_currency'] : ($row['currency'] ?? '')),
                    'paymentAmountConsumed' => (float) (($row['payment_amount_consumed'] ?? null) !== null ? $row['payment_amount_consumed'] : $allocatedAmount),
                    'exchangeRateUsed' => (float) ($row['exchange_rate_used'] ?? 0),
                    'rateFromCurrency' => (string) ($row['rate_from_currency'] ?? ''),
                    'rateToCurrency' => (string) ($row['rate_to_currency'] ?? ''),
                    'exchangeRate' => (float) (($row['exchange_rate'] ?? null) !== null ? $row['exchange_rate'] : ($row['exchange_rate_used'] ?? 0)),
                    'exchangeRateEffectiveDate' => (string) ($row['exchange_rate_effective_date'] ?? ''),
                    'allocationPercent' => $dueAmount > 0 ? round(($allocatedAmount / $dueAmount) * 100, 2) : 0.00,
                    'allocationTrail' => (string) ($row['allocation_note'] ?? ''),
                    'allocatedAt' => (string) ($row['allocated_at'] ?? ''),
                    'remainingAfterAllocation' => (float) ($row['remaining_after_allocation'] ?? 0),
                    'currentOutstandingAmount' => (float) ($row['outstanding_amount'] ?? 0),
                    'currentDueAmount' => $dueAmount,
                    'receivableStatus' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                    'allocationType' => $receivableBookingReference === $bookingReference ? 'Current Invoice' : 'Previous Outstanding',
                ];
            },
            $repository->allocationHistory($bookingReference)
        );
        $allocations = $this->annotateInternalSettlementAllocations($allocations, $internalSettlementReceipts);

        $summaryRow = $repository->bookingOutstandingSummary($bookingReference);
        $customerOutstanding = [];
        $customerCredit = [];
        $invoiceReceivable = [];
        $invoiceOutstanding = [];
        $invoiceReceived = [];
        $fullCustomerOutstanding = $customerTravelerId !== null && $customerTravelerId > 0
            ? $repository->fullOutstandingByLeadTraveler($customerTravelerId, $accessibleBranchIds)
            : [];
        $previousBalance = $customerTravelerId !== null && $customerTravelerId > 0
            ? $repository->outstandingByLeadTraveler($customerTravelerId, $accessibleBranchIds, $bookingReference, $currentBookingContext)
            : [];
        foreach ($serviceReceivables as $receivableRow) {
            $currency = (string) $receivableRow['currency'];
            $invoiceReceivable[$currency] = ($invoiceReceivable[$currency] ?? 0) + (float) $receivableRow['dueAmount'];
            $invoiceReceived[$currency] = ($invoiceReceived[$currency] ?? 0) + (float) $receivableRow['allocatedAmount'];
            $invoiceOutstanding[$currency] = ($invoiceOutstanding[$currency] ?? 0) + (float) $receivableRow['outstandingAmount'];
            $customerOutstanding[$currency] = ($customerOutstanding[$currency] ?? 0) + (float) $receivableRow['outstandingAmount'];
        }
        $creditCurrencies = [];
        foreach ($receipts as $receiptRow) {
            $currency = trim((string) ($receiptRow['currency'] ?? ''));
            if ($currency !== '') {
                $creditCurrencies[$currency] = true;
            }
        }
        foreach ($serviceReceivables as $receivableRow) {
            $currency = trim((string) ($receivableRow['currency'] ?? ''));
            if ($currency !== '') {
                $creditCurrencies[$currency] = true;
            }
        }
        $accountingRepository = new AccountingRepository($this->app);
        foreach (array_keys($creditCurrencies) as $currency) {
            $customerCredit[$currency] = $accountingRepository->accountNetBalanceForBooking(
                $bookingReference,
                $currency,
                'CUSTOMER_CREDIT'
            );
        }
        foreach ($this->cancellationCustomerCreditOverstatementByCurrency($bookingServices) as $currency => $overstatedAmount) {
            $currency = trim((string) $currency);
            if ($currency === '' || $overstatedAmount <= 0.005) {
                continue;
            }

            $customerCredit[$currency] = round(max((float) ($customerCredit[$currency] ?? 0) - $overstatedAmount, 0), 2);
        }
        $invoiceCurrency = '';
        foreach ($serviceReceivables as $receivableRow) {
            $candidateCurrency = trim((string) ($receivableRow['currency'] ?? ''));
            if ($candidateCurrency !== '') {
                $invoiceCurrency = $candidateCurrency;
                break;
            }
        }
        if ($invoiceCurrency === '' && $invoiceOutstanding !== []) {
            $invoiceCurrency = (string) array_key_first($invoiceOutstanding);
        }
        if ($invoiceCurrency === '' && $invoiceReceivable !== []) {
            $invoiceCurrency = (string) array_key_first($invoiceReceivable);
        }
        if ($invoiceCurrency === '' && $invoiceReceived !== []) {
            $invoiceCurrency = (string) array_key_first($invoiceReceived);
        }
        if ($invoiceCurrency === '') {
            $invoiceCurrency = 'PKR';
        }

        $asOfDate = trim((string) ($currentBookingContext['booking_date'] ?? ''));
        if ($asOfDate === '') {
            $asOfDate = date('Y-m-d');
        }

        $invoiceOutstandingPkrRate = null;
        $invoiceOutstandingPkrEquivalent = null;
        $ratesToPkr = (new ExchangeRateRepository($this->app))->latestRatesToTarget([$invoiceCurrency], 'PKR', $asOfDate);
        $candidateRate = $ratesToPkr[$invoiceCurrency] ?? null;
        if (is_numeric($candidateRate) && (float) $candidateRate > 0) {
            $invoiceOutstandingPkrRate = round((float) $candidateRate, 8);
            $invoiceOutstandingPkrEquivalent = round(
                ((float) ($invoiceOutstanding[$invoiceCurrency] ?? 0)) * $invoiceOutstandingPkrRate,
                2
            );
        }

        $displayTotalReceived = 0.0;
        foreach ($receipts as $receiptRow) {
            if ((bool) ($receiptRow['isInternalSettlementAdjustment'] ?? false)) {
                continue;
            }

            $displayTotalReceived += (float) ($receiptRow['displayReceivedAmount'] ?? $receiptRow['receivedAmount'] ?? 0);
        }

        $summary = [
            'totalReceivable' => (float) ($summaryRow['total_receivable'] ?? 0),
            'totalReceived' => round($displayTotalReceived, 2),
            'invoiceCurrency' => $invoiceCurrency,
            'previousBalance' => $previousBalance,
            'fullCustomerOutstanding' => $fullCustomerOutstanding,
            'invoiceReceivable' => $invoiceReceivable,
            'invoiceOutstanding' => $invoiceOutstanding,
            'invoiceReceived' => $invoiceReceived,
            'invoiceOutstandingPkrRate' => $invoiceOutstandingPkrRate,
            'invoiceOutstandingPkrEquivalent' => $invoiceOutstandingPkrEquivalent,
            'customerOutstanding' => $customerOutstanding,
            'bookingOutstanding' => $customerOutstanding,
            'customerCredit' => $customerCredit,
            'receiptCount' => (int) ($summaryRow['receipt_count'] ?? 0),
            'allocationCount' => count($allocations),
        ];

        $openReceivables = array_map(
            static function (array $row) use ($serviceDirectory): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'serviceType' => $serviceDirectory[(string) ($row['service_line_reference'] ?? '')] ?? 'Service',
                    'currency' => (string) ($row['currency'] ?? ''),
                    'dueAmount' => (float) ($row['due_amount'] ?? 0),
                    'allocatedAmount' => (float) ($row['allocated_amount'] ?? 0),
                    'outstandingAmount' => (float) ($row['outstanding_amount'] ?? 0),
                    'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                    'nextDueDate' => (string) ($row['due_date'] ?? ''),
                    'remarks' => (string) ($row['remarks'] ?? ''),
                ];
            },
            $repository->openReceivablesForBooking($bookingReference)
        );

        $customerOpenReceivables = [];
        if ($customerTravelerId !== null && $customerTravelerId > 0) {
            $customerOpenReceivables = array_map(
                static function (array $row) use ($bookingReference): array {
                    $serviceType = trim((string) ($row['service_type'] ?? ''));
                    if ($serviceType === '') {
                        $serviceType = 'Service';
                    } else {
                        $serviceType = ucwords(str_replace('_', ' ', $serviceType));
                    }

                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'bookingId' => (int) ($row['booking_id'] ?? 0),
                        'bookingReference' => (string) ($row['booking_reference'] ?? ''),
                        'bookingDate' => (string) ($row['booking_date'] ?? ''),
                        'branchName' => (string) ($row['branch_name'] ?? ''),
                        'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                        'serviceType' => $serviceType,
                        'passengerName' => (string) ($row['passenger_name'] ?? ''),
                        'currency' => (string) ($row['currency'] ?? ''),
                        'dueAmount' => (float) ($row['due_amount'] ?? 0),
                        'allocatedAmount' => (float) ($row['allocated_amount'] ?? 0),
                        'outstandingAmount' => (float) ($row['outstanding_amount'] ?? 0),
                        'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                        'nextDueDate' => (string) ($row['due_date'] ?? ''),
                        'remarks' => (string) ($row['remarks'] ?? ''),
                        'isCurrentBooking' => (string) ($row['booking_reference'] ?? '') === $bookingReference,
                    ];
                },
                $repository->openReceivablesDetailedForLeadTraveler(
                    $customerTravelerId,
                    $accessibleBranchIds,
                    null
                )
            );
        }

        $currentBookingReceivableItemIds = array_values(array_unique(array_filter(
            array_map(
                static fn (array $row): int => (int) ($row['id'] ?? 0),
                $repository->receivableItemsForBooking($bookingReference)
            ),
            static fn (int $id): bool => $id > 0
        )));
        $invoicePaymentHistory = array_map(
            static function (array $row) use ($serviceDirectory, $bookingReference): array {
                $dueAmount = (float) ($row['due_amount'] ?? 0);
                $allocatedAmount = (float) ($row['allocated_amount'] ?? 0);
                $receivableBookingReference = (string) ($row['receivable_booking_reference'] ?? $bookingReference);
                $serviceType = (string) ($row['service_type'] ?? '');
                if ($serviceType === '') {
                    $serviceType = $serviceDirectory[(string) ($row['service_line_reference'] ?? '')] ?? 'Service';
                } else {
                    $serviceType = ucwords($serviceType);
                }

                return [
                    'allocationId' => (int) ($row['allocation_id'] ?? 0),
                    'receiptId' => (int) ($row['receipt_id'] ?? 0),
                    'receiptNo' => (string) ($row['receipt_no'] ?? ''),
                    'receiptDate' => (string) ($row['receipt_date'] ?? ''),
                    'receiptPurpose' => (string) ($row['receipt_purpose'] ?? 'booking_payment'),
                    'receiptStatusRaw' => (string) ($row['receipt_status'] ?? ''),
                    'receiptStatus' => ucwords(str_replace('_', ' ', (string) ($row['receipt_status'] ?? ''))),
                    'receiptBookingReference' => (string) ($row['receipt_booking_reference'] ?? ''),
                    'bookingReference' => $receivableBookingReference,
                    'receivableItemId' => (int) ($row['receivable_item_id'] ?? 0),
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'serviceType' => $serviceType,
                    'passengerName' => (string) ($row['passenger_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'allocatedAmount' => $allocatedAmount,
                    'receivableCurrency' => (string) (($row['receivable_currency'] ?? '') !== '' ? $row['receivable_currency'] : ($row['currency'] ?? '')),
                    'receivableAmountAllocated' => (float) (($row['receivable_amount_allocated'] ?? null) !== null ? $row['receivable_amount_allocated'] : $allocatedAmount),
                    'paymentCurrency' => (string) (($row['payment_currency'] ?? '') !== '' ? $row['payment_currency'] : ($row['receipt_currency'] ?? $row['currency'] ?? '')),
                    'paymentAmountConsumed' => (float) (($row['payment_amount_consumed'] ?? null) !== null ? $row['payment_amount_consumed'] : $allocatedAmount),
                    'remainingAfterAllocation' => (float) ($row['remaining_after_allocation'] ?? 0),
                    'currentOutstandingAmount' => (float) ($row['outstanding_amount'] ?? 0),
                    'currentDueAmount' => $dueAmount,
                    'receivableStatus' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                ];
            },
            $repository->allocationHistoryForReceivableItems($currentBookingReceivableItemIds)
        );
        $invoicePaymentHistory = $this->annotateInternalSettlementAllocations($invoicePaymentHistory, $internalSettlementReceipts);

        $dailySettlementRates = [];
        $settlementDate = date('Y-m-d');
        foreach (['PKR', 'AED', 'USD'] as $fromCurrency) {
            foreach (['PKR', 'AED', 'USD'] as $toCurrency) {
                if ($fromCurrency === $toCurrency) {
                    continue;
                }

                $rate = (new ExchangeRateRepository($this->app))->getExactRate($fromCurrency, $toCurrency, $settlementDate);
                if ($rate === null) {
                    continue;
                }

                $dailySettlementRates[$fromCurrency . '->' . $toCurrency] = [
                    'fromCurrency' => (string) ($rate['from_currency'] ?? $fromCurrency),
                    'toCurrency' => (string) ($rate['to_currency'] ?? $toCurrency),
                    'exchangeRate' => (float) ($rate['exchange_rate'] ?? 0),
                    'effectiveDate' => (string) ($rate['effective_date'] ?? $settlementDate),
                    'isDerived' => (bool) ($rate['is_derived'] ?? false),
                ];
            }
        }

        $allocatableReceipts = array_values(array_filter(
            $receipts,
            static fn (array $row): bool => (float) ($row['unallocatedAmount'] ?? 0) > 0 && mb_strtolower((string) ($row['status'] ?? '')) !== 'void'
        ));

        $paymentTreasuryAccounts = [];
        $treasuryBranchIds = array_values(array_unique(array_filter(
            array_map('intval', $accessibleBranchIds),
            static fn (int $branchId): bool => $branchId > 0
        )));
        $currentBranchId = (int) ($currentBookingContext['branch_id'] ?? 0);
        if ($currentBranchId > 0 && ! in_array($currentBranchId, $treasuryBranchIds, true)) {
            $treasuryBranchIds[] = $currentBranchId;
        }

        if ($treasuryBranchIds !== []) {
            $paymentTreasuryAccounts = array_map(
                static function (array $row): array {
                    $bankName = trim((string) ($row['bank_name'] ?? ''));
                    $label = (string) ($row['account_name'] ?? '');
                    if ($bankName !== '' && $bankName !== $label) {
                        $label .= ' - ' . $bankName;
                    }

                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'branchId' => (int) ($row['branch_id'] ?? 0),
                        'accountName' => (string) ($row['account_name'] ?? ''),
                        'accountType' => (string) ($row['account_type'] ?? ''),
                        'currency' => (string) ($row['currency'] ?? 'PKR'),
                        'isDefault' => (int) ($row['is_default'] ?? 0) === 1,
                        'label' => trim($label),
                    ];
                },
                array_values(array_filter(
                    (new TreasuryRepository($this->app))->accounts($treasuryBranchIds),
                    static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1
                ))
            );
        }

        return [
            'bookingReference' => $bookingReference,
            'serviceReceivables' => $serviceReceivables,
            'openReceivables' => $openReceivables,
            'customerOpenReceivables' => $customerOpenReceivables,
            'invoicePaymentHistory' => $invoicePaymentHistory,
            'receipts' => $receipts,
            'allocatableReceipts' => $allocatableReceipts,
            'allocations' => $allocations,
            'dailySettlementRates' => $dailySettlementRates,
            'paymentTreasuryAccounts' => $paymentTreasuryAccounts,
            'summary' => $summary,
            'rules' => [
                'Payment is captured first at booking level, then allocated to service receivable items.',
                'One receipt can settle many service lines and one service line can be settled by many receipts.',
                'Unallocated receipt balance remains customer credit until allocation is posted later.',
            ],
        ];
    }

    /**
     * Cancellation settlement can post an internal receivable adjustment through the receipt/allocation
     * tables so the invoice closes cleanly. That row is not customer cash and must not inflate customer-
     * facing "amount received" figures.
     *
     * @param array<int,array<string,mixed>> $bookingServices
     * @param array<int,array<string,mixed>> $receipts
     * @return array<int,array{amount:float,currency:string,label:string}>
     */
    private function internalSettlementAdjustmentReceipts(array $bookingServices, array $receipts): array
    {
        $expectedAdjustments = $this->internalSettlementAdjustmentAmountsByCurrency($bookingServices);
        if ($expectedAdjustments === []) {
            return [];
        }

        $matchedReceipts = [];
        foreach ($receipts as $receiptRow) {
            $receiptId = (int) ($receiptRow['id'] ?? 0);
            $currency = trim((string) ($receiptRow['currency'] ?? ''));
            if ($receiptId <= 0 || $currency === '' || ! isset($expectedAdjustments[$currency])) {
                continue;
            }

            $statusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($receiptRow['statusRaw'] ?? $receiptRow['status'] ?? ''))));
            if ($statusRaw === 'void') {
                continue;
            }

            $returnedAmount = round((float) ($receiptRow['returnedAmount'] ?? 0), 2);
            if ($returnedAmount > 0.005) {
                continue;
            }

            $receiptAmount = round((float) ($receiptRow['tenderedAmount'] ?? $receiptRow['receivedAmount'] ?? 0), 2);
            $allocatedAmount = round((float) ($receiptRow['allocatedAmount'] ?? 0), 2);
            if ($receiptAmount <= 0.005 || abs($receiptAmount - $allocatedAmount) > 0.005) {
                continue;
            }

            foreach ($expectedAdjustments[$currency] as $index => $expectedAmount) {
                if (abs($receiptAmount - $expectedAmount) > 0.005) {
                    continue;
                }

                $matchedReceipts[$receiptId] = [
                    'amount' => $receiptAmount,
                    'currency' => $currency,
                    'label' => 'Cancellation Settlement Adjustment',
                ];
                unset($expectedAdjustments[$currency][$index]);
                if ($expectedAdjustments[$currency] === []) {
                    unset($expectedAdjustments[$currency]);
                }
                break;
            }
        }

        return $matchedReceipts;
    }

    /**
     * @param array<int,array<string,mixed>> $bookingServices
     * @return array<string,array<int,float>>
     */
    private function internalSettlementAdjustmentAmountsByCurrency(array $bookingServices): array
    {
        $eventRepository = new BookingServiceEventRepository($this->app);
        $amountsByCurrency = [];

        foreach ($bookingServices as $serviceRow) {
            $serviceId = (int) ($serviceRow['id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $latestCancelEvent = null;
            foreach ($eventRepository->postedEventsForService($serviceId) as $eventRow) {
                if ((string) ($eventRow['event_type'] ?? '') === 'cancel') {
                    $latestCancelEvent = $eventRow;
                }
            }

            if ($latestCancelEvent === null) {
                continue;
            }

            $currency = trim((string) ($latestCancelEvent['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }

            $payload = json_decode((string) ($latestCancelEvent['payload_json'] ?? ''), true);
            if (! is_array($payload)) {
                $payload = [];
            }

            $customerDelta = (float) ($payload['customer_delta'] ?? 0);
            $adjustmentAmount = round(abs($customerDelta), 2);
            if ($adjustmentAmount <= 0.005) {
                $releasedCredit = (float) (
                    $payload['released_customer_credit']
                    ?? $latestCancelEvent['customer_credit_amount']
                    ?? 0
                );
                $customerPenalty = (float) (
                    $payload['customer_penalty_amount']
                    ?? $latestCancelEvent['penalty_amount']
                    ?? 0
                );
                $adjustmentAmount = round(max($releasedCredit - $customerPenalty, 0), 2);
            }

            if ($adjustmentAmount <= 0.005) {
                continue;
            }

            $amountsByCurrency[$currency] ??= [];
            $amountsByCurrency[$currency][] = $adjustmentAmount;
        }

        return $amountsByCurrency;
    }

    /**
     * @param array<int,array<string,mixed>> $receipts
     * @param array<int,array{amount:float,currency:string,label:string}> $internalSettlementReceipts
     * @return array<int,array<string,mixed>>
     */
    private function annotateInternalSettlementReceipts(array $receipts, array $internalSettlementReceipts): array
    {
        if ($internalSettlementReceipts === []) {
            return array_map(static function (array $receiptRow): array {
                $receiptRow['displayReceivedAmount'] = (float) ($receiptRow['receivedAmount'] ?? 0);
                $receiptRow['displayTenderedAmount'] = (float) ($receiptRow['tenderedAmount'] ?? $receiptRow['receivedAmount'] ?? 0);
                $receiptRow['displayPaymentMethod'] = (string) ($receiptRow['paymentMethod'] ?? '');
                $receiptRow['displayStatus'] = (string) ($receiptRow['status'] ?? '');
                $receiptRow['isInternalSettlementAdjustment'] = false;

                return $receiptRow;
            }, $receipts);
        }

        return array_map(static function (array $receiptRow) use ($internalSettlementReceipts): array {
            $receiptId = (int) ($receiptRow['id'] ?? 0);
            $isInternal = $receiptId > 0 && isset($internalSettlementReceipts[$receiptId]);

            $receiptRow['isInternalSettlementAdjustment'] = $isInternal;
            $receiptRow['displayReceivedAmount'] = $isInternal ? 0.0 : (float) ($receiptRow['receivedAmount'] ?? 0);
            $receiptRow['displayTenderedAmount'] = $isInternal ? 0.0 : (float) ($receiptRow['tenderedAmount'] ?? $receiptRow['receivedAmount'] ?? 0);
            $receiptRow['displayPaymentMethod'] = $isInternal
                ? (string) ($internalSettlementReceipts[$receiptId]['label'] ?? 'Cancellation Settlement Adjustment')
                : (string) ($receiptRow['paymentMethod'] ?? '');
            $receiptRow['displayStatus'] = $isInternal
                ? 'Internal Adjustment'
                : (string) ($receiptRow['status'] ?? '');

            if ($isInternal && trim((string) ($receiptRow['remarks'] ?? '')) === '') {
                $receiptRow['remarks'] = 'Cancellation settlement adjustment.';
            }

            return $receiptRow;
        }, $receipts);
    }

    /**
     * @param array<int,array<string,mixed>> $allocations
     * @param array<int,array{amount:float,currency:string,label:string}> $internalSettlementReceipts
     * @return array<int,array<string,mixed>>
     */
    private function annotateInternalSettlementAllocations(array $allocations, array $internalSettlementReceipts): array
    {
        if ($internalSettlementReceipts === []) {
            return $allocations;
        }

        return array_map(static function (array $allocationRow) use ($internalSettlementReceipts): array {
            $receiptId = (int) ($allocationRow['receiptId'] ?? $allocationRow['receipt_id'] ?? 0);
            $isInternal = $receiptId > 0 && isset($internalSettlementReceipts[$receiptId]);
            $allocationRow['isInternalSettlementAdjustment'] = $isInternal;
            if ($isInternal) {
                $allocationRow['allocationType'] = 'Settlement Adjustment';
                $allocationRow['allocationTrail'] = 'Cancellation settlement adjusted invoice balance; no customer cash was received.';
            }

            return $allocationRow;
        }, $allocations);
    }

    /**
     * Older cancellation settlements sometimes released the full paid amount as customer credit even
     * when the true customer refund should be capped by expected supplier refund minus customer penalty.
     * The accounting ledger remains auditable; this method prevents already-refunded/over-released
     * cancellation credit from appearing as reusable customer credit in the workspace payment panel.
     *
     * @param array<int,array<string,mixed>> $bookingServices
     * @return array<string,float>
     */
    private function cancellationCustomerCreditOverstatementByCurrency(array $bookingServices): array
    {
        $eventRepository = new BookingServiceEventRepository($this->app);
        $overstatedByCurrency = [];

        foreach ($bookingServices as $serviceRow) {
            $serviceId = (int) ($serviceRow['id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $latestCancelEvent = null;
            foreach ($eventRepository->postedEventsForService($serviceId) as $eventRow) {
                $eventType = (string) ($eventRow['event_type'] ?? '');
                $currency = trim((string) ($eventRow['currency'] ?? ''));
                if ($currency === '') {
                    continue;
                }

                if ($eventType === 'cancel') {
                    $latestCancelEvent = $eventRow;
                }
            }

            if ($latestCancelEvent === null) {
                continue;
            }

            $payload = json_decode((string) ($latestCancelEvent['payload_json'] ?? ''), true);
            if (! is_array($payload)) {
                $payload = [];
            }

            $currency = trim((string) ($latestCancelEvent['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }

            $storedReleasedCredit = round((float) (
                $payload['available_customer_refund_credit']
                ?? $payload['released_customer_credit']
                ?? $latestCancelEvent['customer_credit_amount']
                ?? 0
            ), 2);
            if ($storedReleasedCredit <= 0.005) {
                continue;
            }

            $expectedSupplierRefund = round((float) (
                $payload['expected_supplier_refund_amount']
                ?? $payload['expected_supplier_refund_credit']
                ?? $payload['supplier_expected_refund_amount']
                ?? 0
            ), 2);
            if ($expectedSupplierRefund <= 0.005 && isset($payload['supplier_delta'])) {
                $supplierDelta = round((float) $payload['supplier_delta'], 2);
                if ($supplierDelta < -0.005) {
                    $expectedSupplierRefund = abs($supplierDelta);
                }
            }
            if ($expectedSupplierRefund <= 0.005) {
                continue;
            }

            $customerPenalty = round((float) (
                $payload['customer_penalty_amount']
                ?? $latestCancelEvent['penalty_amount']
                ?? 0
            ), 2);
            $trueReleasedCredit = round(max($expectedSupplierRefund - $customerPenalty, 0), 2);
            $overstatedAmount = round(max($storedReleasedCredit - $trueReleasedCredit, 0), 2);
            if ($overstatedAmount <= 0.005) {
                continue;
            }

            $overstatedByCurrency[$currency] = round(
                ($overstatedByCurrency[$currency] ?? 0.0) + $overstatedAmount,
                2
            );
        }

        return $overstatedByCurrency;
    }

    public function previousBalanceDirectory(
        array $travelerIds,
        array $accessibleBranchIds,
        ?string $excludeBookingReference = null,
        array $currentBookingContext = []
    ): array
    {
        return (new CustomerPaymentRepository($this->app))->outstandingDirectoryByLeadTravelers(
            $travelerIds,
            $accessibleBranchIds,
            $excludeBookingReference,
            $this->normalizeBookingContext($excludeBookingReference ?? '', $currentBookingContext)
        );
    }

    private function normalizeBookingContext(string $bookingReference, array $currentBookingContext): array
    {
        return [
            'booking_id' => (int) ($currentBookingContext['booking_id'] ?? 0),
            'booking_reference' => trim((string) ($currentBookingContext['booking_reference'] ?? $bookingReference)),
            'booking_date' => trim((string) ($currentBookingContext['booking_date'] ?? '')),
            'branch_id' => (int) ($currentBookingContext['branch_id'] ?? 0),
        ];
    }
}
