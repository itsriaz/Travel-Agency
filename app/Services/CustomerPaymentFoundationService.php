<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ExchangeRateRepository;

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
        foreach ((new BookingServiceRepository($this->app))->servicesByBookingReference($bookingReference) as $serviceRow) {
            $serviceDirectory[(string) $serviceRow['line_reference']] = ucwords((string) $serviceRow['service_type']);
        }

        $serviceReceivables = array_map(
            static function (array $row) use ($serviceDirectory): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'serviceType' => $serviceDirectory[(string) ($row['service_line_reference'] ?? '')] ?? 'Service',
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
                    'currency' => (string) $row['currency'],
                    'receivedAmount' => (float) $row['received_amount'],
                    'allocatedAmount' => (float) $row['allocated_amount'],
                    'unallocatedAmount' => (float) $row['unallocated_amount'],
                    'paymentMethod' => (string) $row['payment_method'],
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
                    'receiptStatusRaw' => (string) ($row['receipt_status'] ?? ''),
                    'receiptStatus' => ucwords(str_replace('_', ' ', (string) ($row['receipt_status'] ?? ''))),
                    'bookingReference' => $receivableBookingReference,
                    'receivableItemId' => (int) ($row['receivable_item_id'] ?? 0),
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'serviceType' => $serviceType,
                    'passengerName' => (string) ($row['passenger_name'] ?? ''),
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
            $invoiceOutstanding[$currency] = ($invoiceOutstanding[$currency] ?? 0) + (float) $receivableRow['outstandingAmount'];
            $customerOutstanding[$currency] = ($customerOutstanding[$currency] ?? 0) + (float) $receivableRow['outstandingAmount'];
        }
        foreach ($receipts as $receiptRow) {
            $currency = (string) $receiptRow['currency'];
            $customerCredit[$currency] = ($customerCredit[$currency] ?? 0) + (float) $receiptRow['unallocatedAmount'];
        }
        foreach ($allocations as $allocationRow) {
            if ((string) ($allocationRow['bookingReference'] ?? '') !== $bookingReference) {
                continue;
            }

            $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocationRow['receiptStatusRaw'] ?? ''))));
            if ($receiptStatusRaw === 'void') {
                continue;
            }

            $currency = (string) ($allocationRow['receivableCurrency'] ?? $allocationRow['currency'] ?? '');
            if ($currency === '') {
                continue;
            }

            $invoiceReceived[$currency] = ($invoiceReceived[$currency] ?? 0)
                + (float) ($allocationRow['receivableAmountAllocated'] ?? $allocationRow['allocatedAmount'] ?? 0);
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

        $summary = [
            'totalReceivable' => (float) ($summaryRow['total_receivable'] ?? 0),
            'totalReceived' => (float) ($summaryRow['total_received'] ?? 0),
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
                static function (array $row) use ($serviceDirectory, $bookingReference): array {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'bookingId' => (int) ($row['booking_id'] ?? 0),
                        'bookingReference' => (string) ($row['booking_reference'] ?? ''),
                        'bookingDate' => (string) ($row['booking_date'] ?? ''),
                        'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                        'serviceType' => $serviceDirectory[(string) ($row['service_line_reference'] ?? '')] ?? 'Service',
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
                $repository->openReceivablesForLeadTraveler(
                    $customerTravelerId,
                    $accessibleBranchIds,
                    $currentBookingContext
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
            'summary' => $summary,
            'rules' => [
                'Payment is captured first at booking level, then allocated to service receivable items.',
                'One receipt can settle many service lines and one service line can be settled by many receipts.',
                'Unallocated receipt balance remains customer credit until allocation is posted later.',
            ],
        ];
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
        ];
    }
}
