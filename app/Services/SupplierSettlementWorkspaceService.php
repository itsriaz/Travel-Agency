<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\SupplierRepository;
use RuntimeException;

final class SupplierSettlementWorkspaceService extends Service
{
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_METHODS = ['cash', 'bank_transfer', 'debit_card', 'credit_card'];
    private const ALLOWED_STATUSES = ['paid', 'void'];

    public function recordSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $payload = $this->validatedPaymentPayload($input);

        $repository = new SupplierRepository($this->app);
        $paymentNo = $repository->nextSupplierPaymentNumber();
        $paymentId = $repository->createSupplierPayment(array_merge($payload, [
            'supplier_id' => (int) $supplier['id'],
            'branch_id' => (int) $booking['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'payment_no' => $paymentNo,
            'actor_user_id' => $actorUserId,
        ]));

        if ($payload['status'] !== 'void') {
            (new AccountingRepository($this->app))->postSupplierPaymentRecorded([
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'payment_no' => $paymentNo,
                'paid_amount' => $payload['paid_amount'],
                'charges_amount' => $payload['charges_amount'],
                'payment_method' => $payload['payment_method'],
                'entry_date' => $payload['payment_date'],
                'currency' => $payload['currency'],
                'actor_user_id' => $actorUserId,
            ]);
        }

        return [
            'payment' => $repository->findSupplierPaymentById($paymentId),
            'booking_id' => (int) $booking['id'],
        ];
    }

    public function allocateSupplierPayment(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $paymentId = (int) ($input['supplier_payment_id'] ?? 0);
        $repository = new SupplierRepository($this->app);
        $payment = $repository->findSupplierPaymentById($paymentId);
        if ($payment === null || (string) $payment['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected supplier payment does not belong to this booking.');
        }

        $allocationLines = $this->normalizedAllocationLines($input);
        if ($allocationLines === []) {
            throw new RuntimeException('Enter at least one supplier allocation amount greater than zero.');
        }

        $accounting = new AccountingRepository($this->app);
        $allocationCount = 0;

        foreach ($allocationLines as $line) {
            $obligation = $repository->findObligationById((int) $line['obligation_id']);
            if ($obligation === null || (string) $obligation['booking_reference'] !== (string) $booking['booking_reference']) {
                throw new RuntimeException('One of the selected supplier obligations does not belong to this booking.');
            }

            $allocationId = $repository->allocateSupplierPayment(
                $paymentId,
                (int) $line['obligation_id'],
                (float) $line['amount'],
                $line['exchange_rate'],
                $line['note'],
                $actorUserId
            );

            $accounting->postSupplierPaymentAllocation([
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'source_reference' => (string) $payment['payment_no'] . '-ALLOC-' . $allocationId,
                'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null,
                'supplier_obligation_id' => (int) $line['obligation_id'],
                'allocated_amount' => (float) $line['amount'],
                'entry_date' => (string) $payment['payment_date'],
                'currency' => (string) ($obligation['currency'] ?? $payment['currency']),
                'actor_user_id' => $actorUserId,
            ]);

            $allocationCount++;
        }

        return [
            'booking_id' => (int) $booking['id'],
            'allocation_count' => $allocationCount,
        ];
    }

    public function recordSupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $currency = $this->normalizeCurrency((string) ($input['advance_currency'] ?? 'PKR'));
        $depositAmount = $this->positiveMoney($input['advance_amount'] ?? 0, 'Advance amount');

        $repository = new SupplierRepository($this->app);
        $advanceId = $repository->registerAdvance([
            'supplier_id' => (int) $supplier['id'],
            'branch_id' => (int) $booking['branch_id'],
            'currency' => $currency,
            'deposit_amount' => $depositAmount,
            'available_amount' => $depositAmount,
            'reference_no' => $this->optionalText($input['advance_reference_number'] ?? null, 100),
            'remarks' => $this->optionalText($input['advance_remarks'] ?? null, 4000),
            'received_at' => $this->normalizeDate((string) ($input['advance_date'] ?? ''), 'Advance date'),
            'actor_user_id' => $actorUserId,
        ]);

        (new AccountingRepository($this->app))->postSupplierAdvanceDeposit([
            'branch_id' => (int) $booking['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'source_reference' => 'SADV-' . $advanceId,
            'entry_date' => $this->normalizeDate((string) ($input['advance_date'] ?? ''), 'Advance date'),
            'currency' => $currency,
            'amount' => $depositAmount,
            'actor_user_id' => $actorUserId,
        ]);

        return [
            'advance' => $repository->findAdvanceById($advanceId),
            'booking_id' => (int) $booking['id'],
        ];
    }

    public function recordGlobalSupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $branchId = $this->resolveAccessibleBranchId((int) ($input['branch_id'] ?? 0), $accessibleBranchIds);
        $supplier = $this->resolveSupplier($input, $accessibleBranchIds);
        $this->assertSupplierAllowedForBranch($supplier, $branchId);
        $currency = $this->normalizeCurrency((string) ($input['advance_currency'] ?? 'PKR'));
        $depositAmount = $this->positiveMoney($input['advance_amount'] ?? 0, 'Advance amount');
        $advanceDate = $this->normalizeDate((string) ($input['advance_date'] ?? ''), 'Advance date');

        $repository = new SupplierRepository($this->app);
        $advanceId = $repository->registerAdvance([
            'supplier_id' => (int) $supplier['id'],
            'branch_id' => $branchId,
            'currency' => $currency,
            'deposit_amount' => $depositAmount,
            'available_amount' => $depositAmount,
            'reference_no' => $this->optionalText($input['advance_reference_number'] ?? null, 100),
            'remarks' => $this->optionalText($input['advance_remarks'] ?? null, 4000),
            'received_at' => $advanceDate,
            'actor_user_id' => $actorUserId,
        ]);

        (new AccountingRepository($this->app))->postSupplierAdvanceDeposit([
            'branch_id' => $branchId,
            'booking_reference' => null,
            'source_reference' => 'SADV-' . $advanceId,
            'entry_date' => $advanceDate,
            'currency' => $currency,
            'amount' => $depositAmount,
            'actor_user_id' => $actorUserId,
            'narration' => 'Global prepaid supplier payment recorded',
        ]);

        return [
            'advance' => $repository->findAdvanceById($advanceId),
            'branch_id' => $branchId,
            'supplier_name' => (string) ($supplier['name'] ?? 'Supplier'),
            'currency' => $currency,
            'amount' => $depositAmount,
        ];
    }

    public function applySupplierAdvance(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $booking = $this->loadBooking((int) ($input['booking_id'] ?? 0), $accessibleBranchIds);
        $advanceId = (int) ($input['supplier_advance_id'] ?? 0);
        $obligationId = (int) ($input['supplier_obligation_id'] ?? 0);
        $amount = $this->positiveMoney($input['advance_apply_amount'] ?? 0, 'Advance application amount');

        $repository = new SupplierRepository($this->app);
        $advance = $repository->findAdvanceById($advanceId);
        $obligation = $repository->findObligationById($obligationId);

        if ($advance === null || $obligation === null || (string) $obligation['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected advance application pair is invalid for this booking.');
        }

        $repository->applyAdvanceToObligation($advanceId, $obligationId, $amount, $actorUserId);

        (new AccountingRepository($this->app))->postSupplierAdvanceApplication([
            'branch_id' => (int) $booking['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'source_reference' => 'SADV-APPLY-' . $advanceId . '-' . $obligationId,
            'entry_date' => date('Y-m-d'),
            'currency' => (string) $advance['currency'],
            'amount' => $amount,
            'service_line_reference' => (string) ($obligation['service_line_reference'] ?? '') !== '' ? (string) $obligation['service_line_reference'] : null,
            'supplier_obligation_id' => $obligationId,
            'actor_user_id' => $actorUserId,
        ]);

        return [
            'booking_id' => (int) $booking['id'],
        ];
    }

    private function loadBooking(int $bookingId, array $accessibleBranchIds): array
    {
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot manage supplier settlement for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        return $booking;
    }

    private function resolveAccessibleBranchId(int $branchId, array $accessibleBranchIds): int
    {
        if ($branchId <= 0 || ! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch.');
        }

        return $branchId;
    }

    private function resolveSupplier(array $input, array $accessibleBranchIds): array
    {
        $supplierId = (int) ($input['supplier_id'] ?? 0);
        $supplierName = trim((string) ($input['supplier_name'] ?? ''));
        $repository = new SupplierRepository($this->app);

        if ($supplierId > 0) {
            $supplier = $repository->findSupplierById($supplierId);
            if ($supplier !== null) {
                return $supplier;
            }
        }

        $supplier = $repository->findAccessibleSupplierByName($supplierName, $accessibleBranchIds);
        if ($supplier === null) {
            throw new RuntimeException('Please select a valid accessible supplier.');
        }

        return $supplier;
    }

    private function assertSupplierAllowedForBranch(array $supplier, int $branchId): void
    {
        if ((int) ($supplier['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Please select an active supplier.');
        }

        $supplierBranchId = (int) ($supplier['branch_id'] ?? 0);
        if ($supplierBranchId > 0 && $supplierBranchId !== $branchId) {
            throw new RuntimeException('The selected supplier is not available for the chosen branch.');
        }
    }

    private function validatedPaymentPayload(array $input): array
    {
        return [
            'payment_date' => $this->normalizeDate((string) ($input['supplier_payment_date'] ?? ''), 'Supplier payment date'),
            'currency' => $this->normalizeCurrency((string) ($input['supplier_payment_currency'] ?? 'PKR')),
            'paid_amount' => $this->positiveMoney($input['supplier_paid_amount'] ?? 0, 'Supplier paid amount'),
            'payment_method' => $this->normalizeMethod((string) ($input['supplier_payment_method'] ?? 'cash')),
            'reference_number' => $this->optionalText($input['supplier_reference_number'] ?? null, 100),
            'bank_card_detail' => $this->optionalText($input['supplier_bank_card_detail'] ?? null, 190),
            'charges_amount' => $this->nonNegativeMoney($input['supplier_charges_amount'] ?? 0, 'Supplier charges'),
            'status' => $this->normalizeStatus((string) ($input['supplier_payment_status'] ?? 'paid')),
            'exchange_rate_to_booking' => $this->normalizeOptionalExchangeRate((string) ($input['supplier_exchange_rate_to_booking'] ?? '')),
            'remarks' => $this->optionalText($input['supplier_payment_remarks'] ?? null, 4000),
        ];
    }

    private function normalizedAllocationLines(array $input): array
    {
        $ids = $input['supplier_allocation_obligation_id'] ?? [];
        $amounts = $input['supplier_allocation_amount'] ?? [];
        $notes = $input['supplier_allocation_note'] ?? [];
        $rates = $input['supplier_allocation_exchange_rate'] ?? [];
        if (! is_array($ids) || ! is_array($amounts)) {
            return [];
        }

        $lines = [];
        foreach ($ids as $index => $id) {
            $amount = $amounts[$index] ?? null;
            if (! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $lines[] = [
                'obligation_id' => (int) $id,
                'amount' => round((float) $amount, 2),
                'note' => $this->optionalText($notes[$index] ?? null, 190),
                'exchange_rate' => $this->normalizeOptionalExchangeRate((string) ($rates[$index] ?? '')),
            ];
        }

        return $lines;
    }

    private function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid supplier currency.');
        }

        return $currency;
    }

    private function normalizeMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new RuntimeException('Please select a valid supplier payment method.');
        }

        return $method;
    }

    private function normalizeStatus(string $value): string
    {
        $status = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new RuntimeException('Please select a valid supplier payment status.');
        }

        return $status;
    }

    private function normalizeDate(string $value, string $label): string
    {
        $date = trim($value);
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException($label . ' is required.');
        }

        return $date;
    }

    private function normalizeOptionalExchangeRate(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed) || (float) $trimmed <= 0) {
            throw new RuntimeException('Supplier exchange rate must be greater than zero.');
        }

        return round((float) $trimmed, 8);
    }

    private function positiveMoney(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException($label . ' must be greater than zero.');
        }

        return round((float) $value, 2);
    }

    private function nonNegativeMoney(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new RuntimeException($label . ' cannot be negative.');
        }

        return round((float) $value, 2);
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('One of the supplier settlement values exceeds the allowed length.');
        }

        return $text;
    }
}
