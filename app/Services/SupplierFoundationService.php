<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupplierRepository;

final class SupplierFoundationService extends Service
{
    public function buildWorkspacePreview(string $bookingReference): array
    {
        $repository = new SupplierRepository($this->app);
        $supplierRows = $repository->supplierSummaryByBookingReference($bookingReference);
        $paymentSummaryRows = $repository->paymentSummaryByBookingReference($bookingReference);
        $supplierKey = static fn (int $supplierId, string $currency): string => $supplierId . '|' . strtoupper(trim($currency));
        $suppliers = [];
        foreach ($supplierRows as $row) {
            $suppliers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? 'Supplier'),
                'mode' => (string) ($row['supplier_mode'] ?? 'normal_payable'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'advanceBalance' => 0.00,
                'grossObligation' => (float) ($row['total_gross'] ?? 0),
                'advanceApplied' => (float) ($row['total_advance_applied'] ?? 0),
                'netPayable' => (float) ($row['total_net_payable'] ?? 0),
                'totalPaid' => 0.00,
                'allocatedPaid' => 0.00,
                'unallocatedPaid' => 0.00,
                'balanceDue' => (float) ($row['total_net_payable'] ?? 0),
            ];
        }
        $supplierDirectory = [];
        foreach ($suppliers as $index => $supplier) {
            $supplierDirectory[$supplierKey((int) ($supplier['id'] ?? 0), (string) ($supplier['currency'] ?? ''))] = $index;
        }

        $advanceRows = $repository->advancesForBookingReference($bookingReference);
        $advances = [];
        foreach ($advanceRows as $row) {
            $advance = [
                'id' => (int) $row['id'],
                'supplierId' => (int) $row['supplier_id'],
                'supplier' => (string) $row['supplier_name'],
                'mode' => (string) $row['supplier_mode'],
                'currency' => (string) $row['currency'],
                'depositAmount' => (float) ($row['deposit_amount'] ?? 0),
                'availableAmount' => (float) ($row['available_amount'] ?? 0),
                'usedAmount' => max(0, (float) ($row['deposit_amount'] ?? 0) - (float) ($row['available_amount'] ?? 0)),
                'referenceNo' => (string) ($row['reference_no'] ?? ''),
                'receivedAt' => (string) ($row['received_at'] ?? ''),
                'remarks' => (string) ($row['remarks'] ?? ''),
            ];
            $advances[] = $advance;

            $key = $supplierKey((int) $advance['supplierId'], (string) $advance['currency']);
            if (isset($supplierDirectory[$key])) {
                $suppliers[$supplierDirectory[$key]]['advanceBalance'] += (float) $advance['availableAmount'];
            }
        }

        foreach ($paymentSummaryRows as $row) {
            $key = $supplierKey((int) ($row['supplier_id'] ?? 0), (string) ($row['currency'] ?? ''));
            if (! isset($supplierDirectory[$key])) {
                $suppliers[] = [
                    'id' => (int) ($row['supplier_id'] ?? 0),
                    'code' => (string) ($row['code'] ?? ''),
                    'name' => (string) ($row['supplier_name'] ?? 'Supplier'),
                    'mode' => (string) ($row['supplier_mode'] ?? 'normal_payable'),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'advanceBalance' => 0.00,
                    'grossObligation' => 0.00,
                    'advanceApplied' => 0.00,
                    'netPayable' => 0.00,
                    'totalPaid' => 0.00,
                    'allocatedPaid' => 0.00,
                    'unallocatedPaid' => 0.00,
                    'balanceDue' => 0.00,
                ];
                $supplierDirectory[$key] = array_key_last($suppliers);
            }

            $index = $supplierDirectory[$key];
            $suppliers[$index]['totalPaid'] += (float) ($row['total_paid_amount'] ?? 0);
            $suppliers[$index]['allocatedPaid'] += (float) ($row['total_allocated_amount'] ?? 0);
            $suppliers[$index]['unallocatedPaid'] += (float) ($row['total_unallocated_amount'] ?? 0);
        }

        $obligationAllocationRows = $repository->obligationAllocationSummaryByBookingReference($bookingReference);
        $obligationAllocationDirectory = [];
        foreach ($obligationAllocationRows as $row) {
            $obligationAllocationDirectory[(int) ($row['obligation_id'] ?? 0)] = round((float) ($row['total_allocated_amount'] ?? 0), 2);
        }
        $obligationRows = $repository->obligationsByBookingReference($bookingReference);
        $obligations = [];
        foreach ($obligationRows as $row) {
            $obligations[] = [
                'id' => (int) ($row['id'] ?? 0),
                'supplierId' => (int) ($row['supplier_id'] ?? 0),
                'supplier' => (string) $row['supplier_name'],
                'mode' => (string) $row['supplier_mode'],
                'bookingReference' => (string) $row['booking_reference'],
                'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                'currency' => (string) $row['currency'],
                'grossAmount' => (float) ($row['gross_amount'] ?? 0),
                'advanceAppliedAmount' => (float) ($row['advance_applied_amount'] ?? 0),
                'netPayableAmount' => (float) ($row['net_payable_amount'] ?? 0),
                'paymentAllocatedAmount' => (float) ($obligationAllocationDirectory[(int) ($row['id'] ?? 0)] ?? 0),
                'balanceDueAmount' => max(0, (float) ($row['net_payable_amount'] ?? 0)),
                'dueDate' => (string) ($row['due_date'] ?? ''),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                'remarks' => (string) ($row['remarks'] ?? ''),
            ];
        }

        $paymentRows = $repository->supplierPaymentHistory($bookingReference);
        $payments = array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'paymentNo' => (string) $row['payment_no'],
                    'paymentDate' => (string) $row['payment_date'],
                    'supplier' => (string) $row['supplier_name'],
                    'currency' => (string) $row['currency'],
                    'paidAmount' => (float) ($row['paid_amount'] ?? 0),
                    'allocatedAmount' => (float) ($row['allocated_amount'] ?? 0),
                    'unallocatedAmount' => (float) ($row['unallocated_amount'] ?? 0),
                    'paymentMethod' => (string) $row['payment_method'],
                    'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'paid'))),
                    'referenceNumber' => (string) ($row['reference_number'] ?? ''),
                    'exchangeRateToBooking' => (float) ($row['exchange_rate_to_booking'] ?? 0),
                ];
            },
            $paymentRows
        );

        $paymentAllocations = array_map(
            static function (array $row): array {
                return [
                    'allocatedAt' => (string) ($row['allocated_at'] ?? ''),
                    'paymentNo' => (string) $row['payment_no'],
                    'supplier' => (string) $row['supplier_name'],
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'allocatedAmount' => (float) ($row['allocated_amount'] ?? 0),
                    'exchangeRateUsed' => (float) ($row['exchange_rate_used'] ?? 0),
                    'allocationNote' => (string) ($row['allocation_note'] ?? ''),
                ];
            },
            $repository->paymentAllocationHistory($bookingReference)
        );

        $advanceApplications = array_map(
            static function (array $row): array {
                return [
                    'appliedAt' => (string) ($row['created_at'] ?? ''),
                    'supplier' => (string) $row['supplier_name'],
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'appliedAmount' => (float) ($row['applied_amount'] ?? 0),
                    'referenceNo' => (string) ($row['reference_no'] ?? ''),
                ];
            },
            $repository->advanceApplicationHistory($bookingReference)
        );

        $openObligations = array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'supplierId' => (int) $row['supplier_id'],
                    'supplier' => (string) $row['supplier_name'],
                    'mode' => (string) $row['supplier_mode'],
                    'serviceLineReference' => (string) ($row['service_line_reference'] ?? ''),
                    'currency' => (string) $row['currency'],
                    'grossAmount' => (float) ($row['gross_amount'] ?? 0),
                    'advanceAppliedAmount' => (float) ($row['advance_applied_amount'] ?? 0),
                    'netPayableAmount' => (float) ($row['net_payable_amount'] ?? 0),
                    'dueDate' => (string) ($row['due_date'] ?? ''),
                    'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                ];
            },
            $repository->openObligationsForBooking($bookingReference)
        );

        $allocatablePayments = array_values(array_filter(
            $payments,
            static fn (array $row): bool => (float) ($row['unallocatedAmount'] ?? 0) > 0 && mb_strtolower((string) ($row['status'] ?? '')) !== 'void'
        ));

        $totals = [
            'supplierCount' => count($suppliers),
            'openObligationCount' => count(array_filter($obligations, static fn (array $obligation): bool => (float) $obligation['netPayableAmount'] > 0)),
            'runningBalanceSupplierCount' => count(array_filter($suppliers, static fn (array $supplier): bool => $supplier['mode'] === 'running_balance')),
            'normalPayableSupplierCount' => count(array_filter($suppliers, static fn (array $supplier): bool => $supplier['mode'] === 'normal_payable')),
            'totalPayable' => array_sum(array_map(static fn (array $obligation): float => (float) $obligation['grossAmount'], $obligations)),
            'totalAdvanceApplied' => array_sum(array_map(static fn (array $obligation): float => (float) $obligation['advanceAppliedAmount'], $obligations)),
            'totalSupplierOutstanding' => array_sum(array_map(static fn (array $obligation): float => (float) $obligation['netPayableAmount'], $obligations)),
            'totalSupplierPaid' => array_sum(array_map(static fn (array $payment): float => (float) $payment['paidAmount'], $payments)),
            'totalAdvanceBalance' => array_sum(array_map(static fn (array $advance): float => (float) $advance['availableAmount'], $advances)),
        ];

        return [
            'suppliers' => $suppliers,
            'obligations' => $obligations,
            'openObligations' => $openObligations,
            'payments' => $payments,
            'allocatablePayments' => $allocatablePayments,
            'advances' => $advances,
            'advanceApplications' => $advanceApplications,
            'paymentAllocations' => $paymentAllocations,
            'totals' => $totals,
            'postingNotes' => [
                'Immediate payable should be created from each supplier obligation, not directly from the booking total.',
                'Running-balance suppliers must consume available advance first before leaving a net payable balance.',
                'One service line may generate multiple supplier obligations when operationally required.',
            ],
        ];
    }
}
