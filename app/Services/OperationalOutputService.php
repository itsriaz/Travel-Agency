<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRefundDetailRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;

final class OperationalOutputService extends Service
{
    private const OUTPUT_TYPES = [
        'invoice' => 'Customer Invoice',
        'customer_receipt' => 'Customer Receipt',
        'booking_summary_receipt' => 'Booking Summary Receipt',
        'customer_settlement_receipt' => 'Customer Payment Receipt',
        'reissue_voucher' => 'Ticket Reissue Voucher',
        'service_refund_receipt' => 'Cancellation / Refund Receipt',
        'supplier_voucher' => 'Supplier Voucher / Payment Document',
        'account_statement' => 'Account Statement',
        'itinerary' => 'Itinerary',
        'booking_confirmation' => 'Booking Confirmation',
    ];

    public function buildOutputDocument(
        int $bookingId,
        string $outputType,
        array $accessibleBranchIds,
        ?int $receiptId,
        ?int $supplierPaymentId,
        ?int $refundEventId,
        int $actorUserId,
        ?int $serviceEventId = null
    ): array {
        $type = $this->normalizeOutputType($outputType);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot open outputs for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded for output.');
        }

        $bookingReference = (string) $booking['booking_reference'];
        $travelers = (new TravelerRepository($this->app))->travelersForBooking($bookingId);
        $services = (new BookingServiceRepository($this->app))->servicesForBooking($bookingId);
        $eventRepository = new BookingServiceEventRepository($this->app);
        $serviceEvents = [];
        foreach ($services as $service) {
            $serviceId = (int) ($service['id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            foreach ($eventRepository->postedEventsForService($serviceId) as $event) {
                $serviceEvents[] = $event;
            }
        }
        $customerPaymentFoundation = (new CustomerPaymentFoundationService($this->app))->buildWorkspacePreview(
            $bookingReference,
            (int) ($booking['lead_traveler_id'] ?? 0) > 0 ? (int) $booking['lead_traveler_id'] : null,
            $accessibleBranchIds,
            [
                'booking_id' => (int) ($booking['id'] ?? 0),
                'booking_reference' => $bookingReference,
                'booking_date' => (string) ($booking['booking_date'] ?? ''),
            ]
        );
        $supplierFoundation = (new SupplierFoundationService($this->app))->buildWorkspacePreview($bookingReference);

        $selectedReceipt = $this->resolveReceipt(
            $type,
            $receiptId,
            $customerPaymentFoundation['receipts'] ?? [],
            $customerPaymentFoundation['invoicePaymentHistory'] ?? [],
            $bookingReference
        );
        if ($type === 'booking_summary_receipt' && $selectedReceipt === null) {
            $invoiceReceivableTotals = is_array($customerPaymentFoundation['summary']['invoiceReceivable'] ?? null)
                ? $customerPaymentFoundation['summary']['invoiceReceivable']
                : [];
            $receiptCurrency = (string) (
                array_key_first($invoiceReceivableTotals)
                ?? ($booking['currency'] ?? '')
                ?: 'PKR'
            );
            $selectedReceipt = [
                'id' => null,
                'receiptNo' => $bookingReference,
                'receiptDate' => (string) ($booking['booking_date'] ?? ''),
                'currency' => $receiptCurrency,
                'tenderedAmount' => 0.0,
                'receivedAmount' => 0.0,
                'allocatedAmount' => 0.0,
                'returnedAmount' => 0.0,
                'paymentMethod' => 'No payment received',
                'referenceNumber' => '',
                'bankCardDetail' => '',
                'remarks' => '',
                'status' => 'Booking recorded',
                'statusRaw' => 'booking_recorded',
                'allocationIds' => [],
                'isBookingSummary' => true,
            ];
        }
        $selectedSupplierPayment = $this->resolveSupplierPayment($type, $supplierPaymentId, $supplierFoundation['payments'] ?? []);
        $selectedRefundEvent = $this->resolveRefundEvent($type, $refundEventId, $services, $bookingId);
        $selectedRefundDetail = $selectedRefundEvent !== null
            ? (new BookingServiceRefundDetailRepository($this->app))->findByServiceEventId((int) ($selectedRefundEvent['id'] ?? 0))
            : null;
        $selectedReissueEvent = $this->resolveReissueEvent($type, $serviceEventId, $serviceEvents, $bookingId);
        if ($type === 'reissue_voucher' && $selectedReissueEvent !== null) {
            $serviceEvents = [$selectedReissueEvent];
        }

        AuditLog::record($this->app, 'output.viewed', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'output_type' => $type,
            'customer_receipt_id' => $selectedReceipt['id'] ?? null,
            'supplier_payment_id' => $selectedSupplierPayment['id'] ?? null,
            'service_refund_event_id' => $selectedRefundEvent['id'] ?? null,
            'service_reissue_event_id' => $selectedReissueEvent['id'] ?? null,
        ]);

        return [
            'title' => self::OUTPUT_TYPES[$type],
            'outputType' => $type,
            'outputTypeLabel' => self::OUTPUT_TYPES[$type],
            'booking' => $booking,
            'travelers' => $travelers,
            'services' => $services,
            'serviceEvents' => $serviceEvents,
            'customerPaymentFoundation' => $customerPaymentFoundation,
            'supplierFoundation' => $supplierFoundation,
            'selectedReceipt' => $selectedReceipt,
            'selectedSupplierPayment' => $selectedSupplierPayment,
            'selectedRefundEvent' => $selectedRefundEvent,
            'selectedRefundDetail' => $selectedRefundDetail,
            'selectedReissueEvent' => $selectedReissueEvent,
            'branchBranding' => $this->branchBranding($booking),
            'branchDirectory' => array_map(
                fn (array $branch): array => $this->withBranchContact($branch),
                $bookingRepository->activeBranchDirectory()
            ),
            'summary' => $this->summary($services, $customerPaymentFoundation, $supplierFoundation),
            'generatedAt' => date('Y-m-d H:i'),
            'backUrl' => url('/workspace?booking_id=' . $bookingId . '#dock-panel-print'),
        ];
    }

    private function normalizeOutputType(string $value): string
    {
        $type = trim($value);
        if (! array_key_exists($type, self::OUTPUT_TYPES)) {
            throw new RuntimeException('Please select a valid output document.');
        }

        return $type;
    }

    private function resolveReceipt(
        string $type,
        ?int $receiptId,
        array $receipts,
        array $invoicePaymentHistory,
        string $bookingReference
    ): ?array
    {
        if ($type === 'customer_settlement_receipt') {
            return $this->resolveSettlementReceipt($receiptId, $invoicePaymentHistory, $bookingReference);
        }

        if ($type !== 'customer_receipt') {
            return null;
        }

        if ($receipts === []) {
            throw new RuntimeException('No customer receipts are available for this booking yet.');
        }

        if ($receiptId === null || $receiptId <= 0) {
            return $receipts[0];
        }

        foreach ($receipts as $receipt) {
            if ((int) ($receipt['id'] ?? 0) === $receiptId) {
                return $receipt;
            }
        }

        throw new RuntimeException('The selected customer receipt could not be opened for this booking.');
    }

    /**
     * Build a printable receipt identity for a non-cash settlement. Customer credit and
     * customer advance are allocations of existing money, so they correctly have no new
     * customer_receipts header. The printable voucher is therefore derived from the
     * authoritative allocation rows instead of inventing a second cash receipt.
     */
    private function resolveSettlementReceipt(?int $receiptId, array $invoicePaymentHistory, string $bookingReference): array
    {
        $eligibleRows = array_values(array_filter(
            $invoicePaymentHistory,
            static function (array $row) use ($bookingReference, $receiptId): bool {
                $status = str_replace(' ', '_', mb_strtolower(trim((string) ($row['receiptStatusRaw'] ?? ''))));
                if ($status === 'void' || (string) ($row['bookingReference'] ?? '') !== $bookingReference) {
                    return false;
                }

                return $receiptId === null || $receiptId <= 0 || (int) ($row['receiptId'] ?? 0) === $receiptId;
            }
        ));

        if ($eligibleRows === []) {
            throw new RuntimeException(
                'No customer payment, advance, or credit application is recorded for this booking. Open the booking receipt instead.'
            );
        }

        usort($eligibleRows, static fn (array $left, array $right): int =>
            ((int) ($right['allocationId'] ?? 0)) <=> ((int) ($left['allocationId'] ?? 0))
        );
        $latestRow = $eligibleRows[0];
        $latestAllocatedAt = trim((string) ($latestRow['allocatedAt'] ?? ''));
        if ($latestAllocatedAt !== '') {
            $eligibleRows = array_values(array_filter(
                $eligibleRows,
                static fn (array $row): bool => trim((string) ($row['allocatedAt'] ?? '')) === $latestAllocatedAt
            ));
        } elseif ($receiptId === null || $receiptId <= 0) {
            $latestReceiptId = (int) ($latestRow['receiptId'] ?? 0);
            $eligibleRows = array_values(array_filter(
                $eligibleRows,
                static fn (array $row): bool => (int) ($row['receiptId'] ?? 0) === $latestReceiptId
            ));
        }

        $allocationIds = [];
        $receiptNumbers = [];
        $sourceBookings = [];
        $purposes = [];
        $currencies = [];
        $allocatedTotal = 0.0;
        foreach ($eligibleRows as $row) {
            $allocationId = (int) ($row['allocationId'] ?? 0);
            if ($allocationId > 0) {
                $allocationIds[$allocationId] = true;
            }
            $receiptNo = trim((string) ($row['receiptNo'] ?? ''));
            if ($receiptNo !== '') {
                $receiptNumbers[$receiptNo] = true;
            }
            $sourceBooking = trim((string) ($row['receiptBookingReference'] ?? ''));
            if ($sourceBooking !== '' && $sourceBooking !== $bookingReference) {
                $sourceBookings[$sourceBooking] = true;
            }
            $purpose = str_replace(' ', '_', mb_strtolower(trim((string) ($row['receiptPurpose'] ?? 'booking_payment'))));
            $purposes[$purpose !== '' ? $purpose : 'booking_payment'] = true;
            $currency = trim((string) ($row['receivableCurrency'] ?? $row['currency'] ?? ''));
            if ($currency !== '') {
                $currencies[$currency] = true;
            }
            $allocatedTotal += (float) ($row['receivableAmountAllocated'] ?? $row['allocatedAmount'] ?? 0);
        }

        $allocationIdList = array_map('intval', array_keys($allocationIds));
        sort($allocationIdList);
        $receiptNumberList = array_keys($receiptNumbers);
        $sourceBookingList = array_keys($sourceBookings);
        $currency = (string) (array_key_first($currencies) ?? 'PKR');
        $onlyPurpose = count($purposes) === 1 ? (string) array_key_first($purposes) : 'combined_settlement';
        $paymentMethod = $sourceBookingList !== []
            ? 'customer_credit'
            : ($onlyPurpose === 'customer_advance' ? 'customer_advance' : 'customer_credit');
        $reference = $receiptNumberList !== []
            ? implode(' / ', $receiptNumberList)
            : $bookingReference;
        $receiptDate = $latestAllocatedAt !== '' ? substr($latestAllocatedAt, 0, 10) : (string) ($latestRow['receiptDate'] ?? date('Y-m-d'));
        $remarks = $sourceBookingList !== []
            ? 'Customer credit applied from ' . implode(', ', $sourceBookingList)
            : ($onlyPurpose === 'customer_advance' ? 'Customer advance applied to this invoice' : 'Customer credit applied to this invoice');

        return [
            'id' => count($receiptNumbers) === 1 ? (int) ($latestRow['receiptId'] ?? 0) : 0,
            'receiptNo' => $receiptNumberList !== [] ? implode(' / ', $receiptNumberList) : $bookingReference,
            'receiptDate' => $receiptDate,
            'receiptPurpose' => $onlyPurpose,
            'currency' => $currency,
            'tenderedAmount' => round($allocatedTotal, 2),
            'receivedAmount' => round($allocatedTotal, 2),
            'allocatedAmount' => round($allocatedTotal, 2),
            'unallocatedAmount' => 0.0,
            'returnedAmount' => 0.0,
            'paymentMethod' => $paymentMethod,
            'referenceNumber' => $reference,
            'bankCardDetail' => '',
            'chargesAmount' => 0.0,
            'status' => 'Received',
            'statusRaw' => 'received',
            'remarks' => $remarks,
            'allocationIds' => $allocationIdList,
        ];
    }

    private function resolveSupplierPayment(string $type, ?int $supplierPaymentId, array $payments): ?array
    {
        if ($type !== 'supplier_voucher') {
            return null;
        }

        if ($payments === []) {
            throw new RuntimeException('No supplier payments are available for this booking yet.');
        }

        if ($supplierPaymentId === null || $supplierPaymentId <= 0) {
            return $payments[0];
        }

        foreach ($payments as $payment) {
            if ((int) ($payment['id'] ?? 0) === $supplierPaymentId) {
                return $payment;
            }
        }

        throw new RuntimeException('The selected supplier payment could not be opened for this booking.');
    }

    private function resolveRefundEvent(string $type, ?int $refundEventId, array $services, int $bookingId): ?array
    {
        if ($type !== 'service_refund_receipt') {
            return null;
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        if ($refundEventId !== null && $refundEventId > 0) {
            $event = $eventRepository->findPostedEventById($refundEventId, 'refund');
            if ($event !== null && (int) ($event['booking_id'] ?? 0) === $bookingId) {
                return $event;
            }
        }

        foreach ($services as $service) {
            $serviceId = (int) ($service['id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $event = $eventRepository->latestPostedEvent($serviceId, 'refund');
            if ($event !== null) {
                return $event;
            }
        }

        throw new RuntimeException('No posted service refund is available for this booking yet.');
    }

    private function resolveReissueEvent(string $type, ?int $eventId, array $events, int $bookingId): ?array
    {
        if ($type !== 'reissue_voucher') {
            return null;
        }

        foreach (array_reverse($events) as $event) {
            if ((string) ($event['event_type'] ?? '') !== 'reissue'
                || (int) ($event['booking_id'] ?? 0) !== $bookingId) {
                continue;
            }
            if ($eventId === null || $eventId <= 0 || (int) ($event['id'] ?? 0) === $eventId) {
                return $event;
            }
        }

        throw new RuntimeException('The selected ticket reissue could not be opened for this booking.');
    }

    private function branchBranding(array $booking): array
    {
        $countryCode = strtoupper((string) ($booking['country_code'] ?? ''));
        $countryName = match ($countryCode) {
            'PK' => 'Pakistan',
            'AE' => 'UAE',
            default => $countryCode,
        };

        return $this->withBranchContact([
            'id' => (int) ($booking['branch_id'] ?? 0),
            'code' => (string) ($booking['branch_code'] ?? ''),
            'name' => (string) ($booking['branch_name'] ?? 'Travel Agency Branch'),
            'city' => (string) ($booking['branch_city'] ?? ''),
            'country_code' => $countryCode,
            'country' => $countryName,
            'countryCode' => $countryCode,
            'baseCurrency' => (string) ($booking['base_currency'] ?? 'PKR'),
            'tagline' => 'Travel Agency Operations and Accounting System',
        ]);
    }

    private function withBranchContact(array $branch): array
    {
        $code = mb_strtolower(trim((string) ($branch['code'] ?? '')));
        $profiles = config('branches.receipt_contacts', []);
        $contact = is_array($profiles[$code] ?? null) ? $profiles[$code] : [];

        return [
            ...$branch,
            'receipt_name' => trim((string) ($contact['display_name'] ?? '')),
            'contact' => $contact,
        ];
    }

    private function summary(array $services, array $customerPaymentFoundation, array $supplierFoundation): array
    {
        $serviceTotals = [];
        $payableDirectory = [];
        foreach (($supplierFoundation['obligations'] ?? []) as $obligation) {
            $serviceLineReference = (string) ($obligation['serviceLineReference'] ?? '');
            $currency = (string) ($obligation['currency'] ?? '');
            if ($serviceLineReference === '' || $currency === '') {
                continue;
            }

            $payableDirectory[$serviceLineReference . '|' . $currency] = ($payableDirectory[$serviceLineReference . '|' . $currency] ?? 0.0)
                + (float) ($obligation['grossAmount'] ?? 0);
        }

        foreach (($customerPaymentFoundation['serviceReceivables'] ?? []) as $receivable) {
            $currency = (string) ($receivable['currency'] ?? '');
            if ($currency === '') {
                continue;
            }

            if (! isset($serviceTotals[$currency])) {
                $serviceTotals[$currency] = ['receivable' => 0.0, 'payable' => 0.0, 'profit' => 0.0];
            }

            $receivableAmount = (float) ($receivable['dueAmount'] ?? 0);
            $serviceLineReference = (string) ($receivable['serviceLineReference'] ?? '');
            $payableAmount = (float) ($payableDirectory[$serviceLineReference . '|' . $currency] ?? 0.0);

            $serviceTotals[$currency]['receivable'] += $receivableAmount;
            $serviceTotals[$currency]['payable'] += $payableAmount;
            $serviceTotals[$currency]['profit'] += $receivableAmount - $payableAmount;
        }

        return [
            'serviceTotals' => $serviceTotals,
            'invoiceReceivable' => $customerPaymentFoundation['summary']['invoiceReceivable'] ?? [],
            'invoiceOutstanding' => $customerPaymentFoundation['summary']['invoiceOutstanding'] ?? [],
            'invoiceReceived' => $customerPaymentFoundation['summary']['invoiceReceived'] ?? [],
            'previousBalance' => $customerPaymentFoundation['summary']['previousBalance'] ?? [],
            'customerOutstanding' => $customerPaymentFoundation['summary']['customerOutstanding'] ?? [],
            'customerCredit' => $customerPaymentFoundation['summary']['customerCredit'] ?? [],
            'supplierOutstanding' => $this->sumByCurrency(
                $supplierFoundation['obligations'] ?? [],
                'currency',
                static fn (array $row): float => (float) ($row['netPayableAmount'] ?? 0)
            ),
            'supplierPaid' => $this->sumByCurrency(
                $supplierFoundation['payments'] ?? [],
                'currency',
                static fn (array $row): float => (float) ($row['paidAmount'] ?? 0)
            ),
        ];
    }

    private function sumByCurrency(array $rows, string $currencyKey, callable $amountResolver): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $currency = trim((string) ($row[$currencyKey] ?? ''));
            if ($currency === '') {
                continue;
            }

            $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) $amountResolver($row);
        }

        return $totals;
    }
}
