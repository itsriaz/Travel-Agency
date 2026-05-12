<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountingRepository;

final class AccountingFoundationService extends Service
{
    public function buildWorkspacePreview(?string $bookingReference, array $serviceLines, array $supplierFoundation, array $customerPaymentFoundation): array
    {
        $receivableTotals = [];
        $receivedTotals = [];
        $outstandingTotals = [];
        $payableTotals = [];
        $supplierPaidTotals = [];
        $supplierOutstandingTotals = [];

        foreach (($customerPaymentFoundation['serviceReceivables'] ?? []) as $receivable) {
            $currency = (string) $receivable['currency'];
            $receivableTotals[$currency] = ($receivableTotals[$currency] ?? 0) + (float) $receivable['dueAmount'];
            $outstandingTotals[$currency] = ($outstandingTotals[$currency] ?? 0) + (float) $receivable['outstandingAmount'];
        }

        foreach (($customerPaymentFoundation['receipts'] ?? []) as $receipt) {
            $currency = (string) $receipt['currency'];
            $receivedTotals[$currency] = ($receivedTotals[$currency] ?? 0) + (float) $receipt['receivedAmount'];
        }

        foreach (($supplierFoundation['obligations'] ?? []) as $obligation) {
            $currency = (string) $obligation['currency'];
            $payableTotals[$currency] = ($payableTotals[$currency] ?? 0) + (float) $obligation['grossAmount'];
            $supplierPaidTotals[$currency] = ($supplierPaidTotals[$currency] ?? 0) + (float) $obligation['advanceAppliedAmount'];
            $supplierOutstandingTotals[$currency] = ($supplierOutstandingTotals[$currency] ?? 0) + (float) $obligation['netPayableAmount'];
        }

        $profitLossTotals = [];
        foreach ($receivableTotals as $currency => $receivableAmount) {
            $payableAmount = (float) ($payableTotals[$currency] ?? 0);
            $profitLossTotals[$currency] = round((float) $receivableAmount - $payableAmount, 2);
        }
        foreach ($payableTotals as $currency => $payableAmount) {
            if (array_key_exists($currency, $profitLossTotals)) {
                continue;
            }

            $profitLossTotals[$currency] = round(0 - (float) $payableAmount, 2);
        }
        $journalPreview = $bookingReference !== null && trim($bookingReference) !== ''
            ? (new AccountingRepository($this->app))->journalPreviewByBookingReference($bookingReference)
            : [];

        $rules = [
            'Journal preview now shows real posted booking entries only; no demo sample rows are used.',
            'Service creation should immediately create a receivable item and a balanced journal entry.',
            'Supplier obligation creation should immediately create a payable and a balanced journal entry.',
            'Customer receipt entry credits customer credit first, then allocation reduces AR through a separate journal entry.',
            'Supplier advance application debits AP and credits supplier advances so payable reduction remains visible.',
        ];

        if ($journalPreview === []) {
            if ($bookingReference !== null && trim($bookingReference) !== '') {
                $rules[] = 'No posted journal entries exist yet for this booking. Preview stays empty until a saved service, payable, receipt, or allocation creates real accounting entries.';
            } elseif ($serviceLines !== []) {
                $rules[] = 'Draft service values are visible operationally, but accounting preview stays empty until the booking and its financial events are saved.';
            } else {
                $rules[] = 'Open or save a booking with services or payments to generate a booking-based accounting preview.';
            }
        }

        return [
            'summary' => [
                'totalReceivable' => $receivableTotals,
                'totalPayable' => $payableTotals,
                'totalReceived' => $receivedTotals,
                'totalOutstanding' => $outstandingTotals,
                'totalSupplierPaid' => $supplierPaidTotals,
                'totalSupplierOutstanding' => $supplierOutstandingTotals,
                'profitLossSnapshot' => $profitLossTotals,
            ],
            'journalPreview' => $journalPreview,
            'rules' => $rules,
        ];
    }
}
