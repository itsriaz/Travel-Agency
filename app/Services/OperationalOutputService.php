<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\TravelerRepository;
use RuntimeException;

final class OperationalOutputService extends Service
{
    private const OUTPUT_TYPES = [
        'invoice' => 'Customer Invoice',
        'customer_receipt' => 'Customer Receipt',
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
        int $actorUserId
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

        $selectedReceipt = $this->resolveReceipt($type, $receiptId, $customerPaymentFoundation['receipts'] ?? []);
        $selectedSupplierPayment = $this->resolveSupplierPayment($type, $supplierPaymentId, $supplierFoundation['payments'] ?? []);

        AuditLog::record($this->app, 'output.viewed', [
            'user_id' => $actorUserId,
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'output_type' => $type,
            'customer_receipt_id' => $selectedReceipt['id'] ?? null,
            'supplier_payment_id' => $selectedSupplierPayment['id'] ?? null,
        ]);

        return [
            'title' => self::OUTPUT_TYPES[$type],
            'outputType' => $type,
            'outputTypeLabel' => self::OUTPUT_TYPES[$type],
            'booking' => $booking,
            'travelers' => $travelers,
            'services' => $services,
            'customerPaymentFoundation' => $customerPaymentFoundation,
            'supplierFoundation' => $supplierFoundation,
            'selectedReceipt' => $selectedReceipt,
            'selectedSupplierPayment' => $selectedSupplierPayment,
            'branchBranding' => $this->branchBranding($booking),
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

    private function resolveReceipt(string $type, ?int $receiptId, array $receipts): ?array
    {
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

    private function branchBranding(array $booking): array
    {
        $countryCode = strtoupper((string) ($booking['country_code'] ?? ''));
        $countryName = match ($countryCode) {
            'PK' => 'Pakistan',
            'AE' => 'UAE',
            default => $countryCode,
        };

        return [
            'name' => (string) ($booking['branch_name'] ?? 'Travel Agency Branch'),
            'city' => (string) ($booking['branch_city'] ?? ''),
            'country' => $countryName,
            'countryCode' => $countryCode,
            'baseCurrency' => (string) ($booking['base_currency'] ?? 'PKR'),
            'tagline' => 'Travel Agency Operations and Accounting System',
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
