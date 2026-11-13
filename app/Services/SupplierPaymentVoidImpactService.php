<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SupplierPaymentVoidImpactRepository;
use App\Repositories\SupplierRepository;
use RuntimeException;

final class SupplierPaymentVoidImpactService
{
    public function __construct(private readonly App $app)
    {
    }

    public function preview(int $paymentId, array $accessibleBranchIds): array
    {
        if ($paymentId <= 0) {
            throw new RuntimeException('Select a valid supplier payment.');
        }

        $supplierRepository = new SupplierRepository($this->app);
        $payment = $supplierRepository->findSupplierPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException('The selected supplier payment could not be found.');
        }
        if (! in_array((int) $payment['branch_id'], array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('You cannot review a supplier payment outside your accessible branches.');
        }

        $impactRepository = new SupplierPaymentVoidImpactRepository($this->app);
        $allocations = $impactRepository->directAllocations($paymentId);
        $advances = $impactRepository->linkedAdvances($paymentId, (int) ($payment['converted_advance_id'] ?? 0));
        $applications = $impactRepository->advanceApplications(array_column($advances, 'id'));

        $accessibleIds = array_map('intval', $accessibleBranchIds);
        foreach (array_merge($allocations, $advances, $applications) as $row) {
            if (! in_array((int) $row['branch_id'], $accessibleIds, true)) {
                throw new RuntimeException('This payment affected another branch. An administrator with access to both branches must review it.');
            }
        }

        $supplier = $supplierRepository->findSupplierById((int) $payment['supplier_id']);
        $directTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $allocations)), 2);
        $creditTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['deposit_amount'], $advances)), 2);
        $creditUsed = round(array_sum(array_map(static fn (array $row): float => (float) $row['advance_amount'], $applications)), 2);
        $creditAvailable = round(array_sum(array_map(static fn (array $row): float => (float) $row['available_amount'], $advances)), 2);
        $paidAmount = round((float) $payment['paid_amount'], 2);
        $returnedAmount = round((float) ($payment['returned_amount'] ?? 0), 2);
        $paymentCurrency = strtoupper((string) $payment['currency']);
        $mixedCurrencies = false;
        foreach ($allocations as $allocation) {
            $mixedCurrencies = $mixedCurrencies || strtoupper((string) $allocation['currency']) !== $paymentCurrency;
        }
        foreach ($advances as $advance) {
            $mixedCurrencies = $mixedCurrencies || strtoupper((string) $advance['currency']) !== $paymentCurrency;
        }
        $usedByAdvance = [];
        foreach ($applications as $application) {
            $advanceId = (int) $application['supplier_advance_id'];
            $usedByAdvance[$advanceId] = round(($usedByAdvance[$advanceId] ?? 0)
                + (float) $application['advance_amount'], 2);
        }
        $creditReconciles = true;
        foreach ($advances as $advance) {
            $creditReconciles = $creditReconciles && abs((float) $advance['deposit_amount']
                - (float) $advance['available_amount'] - ($usedByAdvance[(int) $advance['id']] ?? 0)) <= 0.01;
        }
        $unsupportedCreditSources = [];
        $cancellationCreditIds = [];
        $cancellationEventIds = [];
        foreach ($advances as $advance) {
            $reference = (string) ($advance['reference_no'] ?? '');
            if (str_starts_with($reference, 'CANCEL-CREDIT-')) {
                $cancellationCreditIds[] = (int) $advance['id'];
                $eventId = substr($reference, strlen('CANCEL-CREDIT-'));
                if (ctype_digit($eventId) && (int) $eventId > 0) {
                    $cancellationEventIds[] = (int) $eventId;
                }
            } elseif (! str_starts_with($reference, 'SPAY-')) {
                $unsupportedCreditSources[] = $reference;
            }
        }
        $cancellationEvents = [];
        foreach ($impactRepository->cancellationCreditEvents($cancellationEventIds) as $row) {
            if (! in_array((int) $row['branch_id'], $accessibleIds, true)) {
                throw new RuntimeException('A cancellation funded by this payment belongs to another branch. An administrator with access to both branches must review it.');
            }
            $eventId = (int) $row['id'];
            if (! isset($cancellationEvents[$eventId])) {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                $payload = is_array($payload) ? $payload : [];
                $cancellationEvents[$eventId] = [
                    'event_id' => $eventId,
                    'booking_reference' => (string) $row['booking_reference'],
                    'event_date' => (string) $row['event_date'],
                    'event_status' => (string) $row['event_status'],
                    'has_posted_supplier_refund' => (int) $row['has_posted_supplier_refund'] === 1,
                    'payload_hash' => hash('sha256', (string) ($row['payload_json'] ?? '')),
                    'booking_currency' => (string) $row['booking_currency'],
                    'recorded_booking_equivalent' => round((float) $row['supplier_credit_amount'], 2),
                    'recorded_source_currency' => (string) ($payload['supplier_credit_recorded_currency'] ?? ''),
                    'recorded_source_amount' => round((float) ($payload['supplier_credit_recorded_source_amount'] ?? 0), 2),
                    'selected_source_amount' => 0.0,
                    'other_source_amount' => 0.0,
                    'funding' => [],
                ];
            }
            if ((int) ($row['credit_id'] ?? 0) <= 0) {
                continue;
            }
            $amount = round((float) $row['deposit_amount'], 2);
            $isSelected = (int) $row['source_supplier_payment_id'] === $paymentId;
            $key = $isSelected ? 'selected_source_amount' : 'other_source_amount';
            $cancellationEvents[$eventId][$key] = round($cancellationEvents[$eventId][$key] + $amount, 2);
            $cancellationEvents[$eventId]['funding'][] = [
                'credit_id' => (int) $row['credit_id'],
                'payment_no' => (string) ($row['source_payment_no'] ?? ''),
                'currency' => (string) $row['credit_currency'],
                'amount' => $amount,
                'is_selected' => $isSelected,
            ];
        }
        $cancellationEvents = array_values($cancellationEvents);
        $representedCreditIds = [];
        foreach ($cancellationEvents as $event) {
            foreach ($event['funding'] as $funding) {
                if ($funding['is_selected']) {
                    $representedCreditIds[] = (int) $funding['credit_id'];
                }
            }
        }
        sort($representedCreditIds);
        sort($cancellationCreditIds);
        $cancellationEventsComplete = $representedCreditIds === $cancellationCreditIds;

        $impactToken = hash('sha256', json_encode([
            'payment' => [$payment['id'], $payment['status'], $payment['paid_amount'], $payment['allocated_amount'], $payment['unallocated_amount']],
            'direct_allocations' => $allocations,
            'linked_advances' => $advances,
            'advance_applications' => $applications,
            'unsupported_credit_sources' => $unsupportedCreditSources,
            'cancellation_credit_ids' => $cancellationCreditIds,
            'cancellation_events' => $cancellationEvents,
            'cancellation_events_complete' => $cancellationEventsComplete,
        ], JSON_THROW_ON_ERROR));

        $result = [
            'payment' => $payment,
            'impact_token' => $impactToken,
            'supplier_name' => (string) ($supplier['name'] ?? 'Supplier'),
            'direct_allocations' => $allocations,
            'linked_advances' => $advances,
            'advance_applications' => $applications,
            'unsupported_credit_sources' => $unsupportedCreditSources,
            'cancellation_credit_ids' => $cancellationCreditIds,
            'cancellation_events' => $cancellationEvents,
            'cancellation_events_complete' => $cancellationEventsComplete,
            'totals' => [
                'paid' => $paidAmount,
                'returned' => $returnedAmount,
                'direct_allocated' => $directTotal,
                'credit_created' => $creditTotal,
                'credit_used' => $creditUsed,
                'credit_available' => $creditAvailable,
                'unallocated' => round((float) $payment['unallocated_amount'], 2),
                'mixed_currencies' => $mixedCurrencies,
                'source_reconciles' => ! $mixedCurrencies && abs($paidAmount - $returnedAmount - $directTotal - $creditTotal
                    - (float) $payment['unallocated_amount']) <= 0.01,
                'credit_reconciles' => $creditReconciles,
            ],
        ];
        $result['replacement'] = (new SupplierPaymentVoidReplacementService($this->app))
            ->plan($result, $accessibleBranchIds);

        return $result;
    }
}
