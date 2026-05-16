<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ExchangeRateRepository;
use PDO;
use RuntimeException;

final class CustomerReceiptWorkspaceService extends Service
{
    private const ALLOWED_CURRENCIES = ['PKR', 'AED', 'USD'];
    private const ALLOWED_METHODS = ['cash', 'bank_transfer', 'debit_card', 'credit_card'];
    private const ALLOWED_STATUSES = ['received', 'void'];

    public function saveReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        if (($input['settlement_mode'] ?? 'normal') === 'exchange') {
            return $this->saveExchangeSettlement($input, $actorUserId, $accessibleBranchIds);
        }

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot record receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $payload = $this->validatedReceiptPayload($input);
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $receiptNo = $repository->nextReceiptNumber();

            $receiptId = $repository->createReceipt(array_merge($payload, [
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'receipt_no' => $receiptNo,
                'actor_user_id' => $actorUserId,
            ]));

            if ($payload['status'] !== 'void') {
                $accountingRepository->postCustomerReceiptRecorded([
                    'branch_id' => (int) $booking['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'customer_receipt_id' => $receiptId,
                    'receipt_no' => $receiptNo,
                    'received_amount' => $payload['received_amount'],
                    'charges_amount' => $payload['charges_amount'],
                    'payment_method' => $payload['payment_method'],
                    'entry_date' => $payload['receipt_date'],
                    'currency' => $payload['currency'],
                    'actor_user_id' => $actorUserId,
                ]);

                $this->autoAllocateCurrentCurrencyReceipt(
                    $receiptId,
                    $receiptNo,
                    $booking,
                    $accessibleBranchIds,
                    $payload['currency'],
                    $payload['receipt_date'],
                    $actorUserId,
                    $repository,
                    $accountingRepository
                );
            }

            if ($payload['due_date'] !== null) {
                $bookingRepository->updateBookingDueDate(
                    $bookingId,
                    $payload['due_date'],
                    $actorUserId
                );
                $repository->updateOpenReceivableDueDates(
                    (string) $booking['booking_reference'],
                    $payload['due_date'],
                    $actorUserId
                );
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $repository->findReceiptById($receiptId),
                'booking_id' => $bookingId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Customer receipt could not be saved.', 0, $exception);
        }
    }

    public function allocateReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $receiptId = (int) ($input['customer_receipt_id'] ?? 0);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot allocate receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $paymentRepository = new CustomerPaymentRepository($this->app);
        $receipt = $paymentRepository->findReceiptById($receiptId);
        if ($receipt === null || (string) $receipt['booking_reference'] !== (string) $booking['booking_reference']) {
            throw new RuntimeException('The selected receipt does not belong to this booking.');
        }

        $allocationLines = $this->normalizedAllocationLines($input);
        if ($allocationLines === []) {
            throw new RuntimeException('Enter at least one allocation amount greater than zero.');
        }

        $accountingRepository = new AccountingRepository($this->app);
        $allocationCount = 0;

        foreach ($allocationLines as $line) {
            $receivable = $paymentRepository->findReceivableById((int) $line['receivable_id']);
            if ($receivable === null || (string) $receivable['booking_reference'] !== (string) $booking['booking_reference']) {
                throw new RuntimeException('One of the selected due items does not belong to this booking.');
            }

            $allocationPayload = $this->buildExplicitAllocationPayload($receipt, $receivable, $line);
            $allocationPayload['receipt_id'] = $receiptId;
            $allocationPayload['receivable_item_id'] = (int) $line['receivable_id'];
            $allocationPayload['receivable_amount_to_settle'] = (float) $line['amount'];
            $allocationPayload['allocation_note'] = $line['note'];
            $allocationPayload['actor_user_id'] = $actorUserId;

            $allocationResult = $paymentRepository->allocateReceiptExplicit($allocationPayload);
            $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
            $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
            if ($allocationId <= 0 || $allocatedAmount <= 0) {
                throw new RuntimeException('Receipt allocation could not be completed.');
            }

            $accountingRepository->postCustomerReceiptAllocation([
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'source_reference' => (string) $receipt['receipt_no'] . '-ALLOC-' . $allocationId,
                'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                'customer_receivable_item_id' => (int) $line['receivable_id'],
                'customer_receipt_id' => $receiptId,
                'allocated_amount' => $allocatedAmount,
                'entry_date' => (string) $receipt['receipt_date'],
                'currency' => (string) ($receivable['currency'] ?? $receipt['currency']),
                'actor_user_id' => $actorUserId,
            ]);

            $allocationCount++;
        }

        return [
            'booking_id' => $bookingId,
            'allocation_count' => $allocationCount,
        ];
    }

    private function validatedReceiptPayload(array $input): array
    {
        $currency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? 'PKR'));
        $paymentMethod = $this->normalizeMethod((string) ($input['payment_method'] ?? 'cash'));
        $status = $this->normalizeStatus((string) ($input['receipt_status'] ?? 'received'));
        $receivedAmount = $this->moneyValue($input['received_amount'] ?? 0, 'Received amount');
        $chargesAmount = $this->nonNegativeMoneyValue($input['charges_amount'] ?? 0, 'Charges');
        $exchangeRate = $this->normalizeOptionalExchangeRate((string) ($input['exchange_rate_to_booking'] ?? ''));

        return [
            'receipt_date' => $this->normalizeDate((string) ($input['receipt_date'] ?? ''), 'Receipt date'),
            'currency' => $currency,
            'received_amount' => $receivedAmount,
            'due_date' => $this->normalizeOptionalDate((string) ($input['due_date'] ?? '')),
            'payment_method' => $paymentMethod,
            'reference_number' => $this->optionalText($input['reference_number'] ?? null, 100),
            'bank_card_detail' => $this->optionalText($input['bank_card_detail'] ?? null, 190),
            'charges_amount' => $chargesAmount,
            'status' => $status,
            'exchange_rate_to_booking' => $exchangeRate,
            'remarks' => $this->optionalText($input['receipt_remarks'] ?? null, 4000),
        ];
    }

    private function normalizedAllocationLines(array $input): array
    {
        $receivableIds = $input['allocation_receivable_id'] ?? [];
        $amounts = $input['allocation_amount'] ?? [];
        $notes = $input['allocation_note'] ?? [];
        $rates = $input['allocation_exchange_rate'] ?? [];
        $currencies = $input['allocation_receivable_currency'] ?? [];
        $rateFromCurrencies = $input['allocation_rate_from_currency'] ?? [];
        $rateToCurrencies = $input['allocation_rate_to_currency'] ?? [];
        $rateDates = $input['allocation_exchange_rate_effective_date'] ?? [];

        if (! is_array($receivableIds) || ! is_array($amounts)) {
            return [];
        }

        $lines = [];
        foreach ($receivableIds as $index => $receivableId) {
            $amount = is_array($amounts) ? ($amounts[$index] ?? null) : null;
            if (! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $lines[] = [
                'receivable_id' => (int) $receivableId,
                'amount' => round((float) $amount, 2),
                'note' => $this->optionalText($notes[$index] ?? null, 190),
                'exchange_rate' => $this->normalizeOptionalExchangeRate((string) ($rates[$index] ?? '')),
                'receivable_currency' => is_array($currencies) ? (string) ($currencies[$index] ?? '') : '',
                'rate_from_currency' => is_array($rateFromCurrencies) ? strtoupper(trim((string) ($rateFromCurrencies[$index] ?? ''))) : '',
                'rate_to_currency' => is_array($rateToCurrencies) ? strtoupper(trim((string) ($rateToCurrencies[$index] ?? ''))) : '',
                'exchange_rate_effective_date' => is_array($rateDates) ? $this->normalizeOptionalDate((string) ($rateDates[$index] ?? '')) : null,
            ];
        }

        return $lines;
    }

    private function saveExchangeSettlement(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $bookingRepository = new BookingRepository($this->app);
        if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            throw new RuntimeException('You cannot record receipts for a booking outside your accessible branches.');
        }

        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The selected booking could not be loaded.');
        }

        $payload = $this->validatedReceiptPayload($input);
        $settlement = $this->validatedExchangeSettlementPayload($input, $payload);
        $repository = new CustomerPaymentRepository($this->app);
        $accountingRepository = new AccountingRepository($this->app);
        $exchangeRateRepository = new ExchangeRateRepository($this->app);
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $targetReceivable = $this->findAccessibleSettlementTarget(
                $repository,
                $booking,
                $accessibleBranchIds,
                $settlement['target_receivable_id']
            );

            if ($payload['currency'] !== $settlement['payment_currency']) {
                throw new RuntimeException('PAYMENT_CURRENCY_MISMATCH: Payment currency does not match the settlement request.');
            }

            if ($settlement['payment_currency'] !== $settlement['target_currency']) {
                if ($settlement['exchange_rate'] === null) {
                    $exactRate = $exchangeRateRepository->getExactRate(
                        $settlement['rate_from_currency'],
                        $settlement['rate_to_currency'],
                        $settlement['exchange_rate_effective_date']
                    );
                    if ($exactRate === null) {
                        throw new RuntimeException('FX_RATE_EXPIRED_OR_MISSING: Today\'s exchange rate is required.');
                    }

                    $settlement['exchange_rate'] = (float) ($exactRate['exchange_rate'] ?? 0);
                }

                $exchangeRateRepository->upsertDailyRate(
                    $settlement['rate_from_currency'],
                    $settlement['rate_to_currency'],
                    $settlement['exchange_rate_effective_date'],
                    $settlement['exchange_rate'],
                    (int) $booking['branch_id'],
                    $actorUserId
                );
            } else {
                $settlement['exchange_rate'] = 1.0;
            }

            $receiptNo = $repository->nextReceiptNumber();
            $receiptId = $repository->createReceipt(array_merge($payload, [
                'branch_id' => (int) $booking['branch_id'],
                'booking_reference' => (string) $booking['booking_reference'],
                'receipt_no' => $receiptNo,
                'actor_user_id' => $actorUserId,
            ]));

            if ($payload['status'] !== 'void') {
                $accountingRepository->postCustomerReceiptRecorded([
                    'branch_id' => (int) $booking['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'customer_receipt_id' => $receiptId,
                    'receipt_no' => $receiptNo,
                    'received_amount' => $payload['received_amount'],
                    'charges_amount' => $payload['charges_amount'],
                    'payment_method' => $payload['payment_method'],
                    'entry_date' => $payload['receipt_date'],
                    'currency' => $payload['currency'],
                    'actor_user_id' => $actorUserId,
                ]);

                $targetAllocation = $repository->allocateReceiptExplicit([
                    'receipt_id' => $receiptId,
                    'receivable_item_id' => (int) $targetReceivable['id'],
                    'receivable_amount_to_settle' => $settlement['target_receivable_amount_to_settle'],
                    'payment_amount_to_consume' => $settlement['target_payment_amount_to_consume'],
                    'payment_currency' => $settlement['payment_currency'],
                    'rate_from_currency' => $settlement['rate_from_currency'],
                    'rate_to_currency' => $settlement['rate_to_currency'],
                    'exchange_rate' => $settlement['exchange_rate'],
                    'exchange_rate_effective_date' => $settlement['exchange_rate_effective_date'],
                    'allocation_note' => 'Exchange settlement target allocation.',
                    'actor_user_id' => $actorUserId,
                ]);
                $allocationId = (int) ($targetAllocation['allocation_id'] ?? 0);
                $allocatedAmount = round((float) ($targetAllocation['allocated_amount'] ?? 0), 2);
                if ($allocationId <= 0 || $allocatedAmount <= 0) {
                    throw new RuntimeException('Exchange settlement target could not be allocated.');
                }

                $accountingRepository->postCustomerReceiptAllocation([
                    'branch_id' => (int) ($targetReceivable['branch_id'] ?? $booking['branch_id']),
                    'booking_reference' => (string) ($targetReceivable['booking_reference'] ?? $booking['booking_reference']),
                    'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
                    'service_line_reference' => (string) ($targetReceivable['service_line_reference'] ?? '') !== '' ? (string) $targetReceivable['service_line_reference'] : null,
                    'customer_receivable_item_id' => (int) $targetReceivable['id'],
                    'customer_receipt_id' => $receiptId,
                    'allocated_amount' => $allocatedAmount,
                    'entry_date' => $payload['receipt_date'],
                    'currency' => (string) ($targetReceivable['currency'] ?? $payload['currency']),
                    'actor_user_id' => $actorUserId,
                ]);

                $this->autoAllocateCurrentCurrencyReceipt(
                    $receiptId,
                    $receiptNo,
                    $booking,
                    $accessibleBranchIds,
                    $payload['currency'],
                    $payload['receipt_date'],
                    $actorUserId,
                    $repository,
                    $accountingRepository,
                    [(int) $targetReceivable['id']]
                );
            }

            if ($payload['due_date'] !== null) {
                $bookingRepository->updateBookingDueDate($bookingId, $payload['due_date'], $actorUserId);
                $repository->updateOpenReceivableDueDates(
                    (string) $booking['booking_reference'],
                    $payload['due_date'],
                    $actorUserId
                );
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'receipt' => $repository->findReceiptById($receiptId),
                'booking_id' => $bookingId,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Exchange settlement could not be saved.', 0, $exception);
        }
    }

    private function validatedExchangeSettlementPayload(array $input, array $receiptPayload): array
    {
        $targetReceivableId = (int) ($input['settlement_target_receivable_id'] ?? 0);
        if ($targetReceivableId <= 0) {
            throw new RuntimeException('Please choose a settlement target.');
        }

        $paymentCurrency = $this->normalizeCurrency((string) ($input['receipt_currency'] ?? 'PKR'));
        $targetCurrency = $this->normalizeCurrency((string) ($input['settlement_target_currency'] ?? 'PKR'));
        $rateDate = $this->normalizeDate(
            (string) ($input['settlement_exchange_rate_effective_date'] ?? $receiptPayload['receipt_date']),
            'Settlement rate date'
        );

        $rateFromCurrency = strtoupper(trim((string) ($input['settlement_rate_from_currency'] ?? '')));
        $rateToCurrency = strtoupper(trim((string) ($input['settlement_rate_to_currency'] ?? '')));
        $exchangeRate = $this->normalizeOptionalExchangeRate((string) ($input['settlement_exchange_rate'] ?? ''));
        $targetReceivableAmount = $this->moneyValue(
            $input['settlement_target_receivable_amount'] ?? 0,
            'Settlement target amount'
        );
        $targetPaymentAmount = $this->moneyValue(
            $input['settlement_target_payment_amount'] ?? 0,
            'Settlement payment amount'
        );

        if ($paymentCurrency === $targetCurrency) {
            $rateFromCurrency = $targetCurrency;
            $rateToCurrency = $paymentCurrency;
            $exchangeRate = 1.0;
        } elseif ($rateFromCurrency === '' || $rateToCurrency === '') {
            throw new RuntimeException('CROSS_CURRENCY_REQUIRES_EXPLICIT_ALLOCATION: Settlement target and exchange rate are required.');
        }

        return [
            'target_receivable_id' => $targetReceivableId,
            'target_currency' => $targetCurrency,
            'payment_currency' => $paymentCurrency,
            'target_receivable_amount_to_settle' => $targetReceivableAmount,
            'target_payment_amount_to_consume' => $targetPaymentAmount,
            'rate_from_currency' => $rateFromCurrency,
            'rate_to_currency' => $rateToCurrency,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_effective_date' => $rateDate,
        ];
    }

    private function findAccessibleSettlementTarget(
        CustomerPaymentRepository $repository,
        array $booking,
        array $accessibleBranchIds,
        int $targetReceivableId
    ): array {
        $leadTravelerId = (int) ($booking['lead_traveler_id'] ?? 0);
        if ($leadTravelerId <= 0) {
            throw new RuntimeException('The booking customer could not be resolved for settlement.');
        }

        $receivables = $repository->openReceivablesForLeadTraveler(
            $leadTravelerId,
            $accessibleBranchIds,
            [
                'booking_id' => (int) ($booking['id'] ?? 0),
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'booking_date' => (string) ($booking['booking_date'] ?? ''),
            ]
        );

        foreach ($receivables as $receivable) {
            if ((int) ($receivable['id'] ?? 0) === $targetReceivableId) {
                if ((string) ($receivable['booking_reference'] ?? '') !== (string) ($booking['booking_reference'] ?? '')) {
                    throw new RuntimeException('Phase 1 exchange settlement only supports the current invoice.');
                }

                return $receivable;
            }
        }

        throw new RuntimeException('The selected settlement target is not available for this customer.');
    }

    private function autoAllocateCurrentCurrencyReceipt(
        int $receiptId,
        string $receiptNo,
        array $booking,
        array $accessibleBranchIds,
        string $receiptCurrency,
        string $receiptDate,
        int $actorUserId,
        CustomerPaymentRepository $paymentRepository,
        AccountingRepository $accountingRepository,
        array $skipReceivableIds = []
    ): void {
        $leadTravelerId = (int) ($booking['lead_traveler_id'] ?? 0);
        $currentBookingContext = [
            'booking_id' => (int) ($booking['id'] ?? 0),
            'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'booking_date' => (string) ($booking['booking_date'] ?? ''),
        ];

       $currentBookingOpenReceivables = $leadTravelerId > 0
    ? $paymentRepository->openReceivablesForLeadTraveler(
        $leadTravelerId,
        $accessibleBranchIds,
        $currentBookingContext,
        $receiptCurrency
    )
    : $paymentRepository->openReceivablesForBooking((string) $booking['booking_reference']);

$allCustomerOpenReceivables = $leadTravelerId > 0
    ? $paymentRepository->openReceivablesForLeadTraveler(
        $leadTravelerId,
        $accessibleBranchIds,
        [],
        $receiptCurrency
    )
    : $currentBookingOpenReceivables;

$currentBookingId = (int) ($booking['id'] ?? 0);
$currentBookingReference = (string) ($booking['booking_reference'] ?? '');
$currentBookingReceivablesById = [];
$otherReceivablesById = [];

foreach (array_merge($currentBookingOpenReceivables, $allCustomerOpenReceivables) as $receivable) {
    $receivableId = (int) ($receivable['id'] ?? 0);
    if ($receivableId <= 0) {
        continue;
    }

    if ((string) ($receivable['currency'] ?? '') !== $receiptCurrency) {
        continue;
    }

    $isCurrentBookingReceivable = ((int) ($receivable['booking_id'] ?? 0) === $currentBookingId)
        || ((string) ($receivable['booking_reference'] ?? '') === $currentBookingReference);

    if ($isCurrentBookingReceivable) {
        $currentBookingReceivablesById[$receivableId] = $receivable;
        continue;
    }

    $otherReceivablesById[$receivableId] = $receivable;
}

$currentBookingReceivables = array_values($currentBookingReceivablesById);
$otherReceivables = array_values($otherReceivablesById);

usort($otherReceivables, static function (array $left, array $right): int {
    $leftDueDate = (string) ($left['due_date'] ?? '');
    $rightDueDate = (string) ($right['due_date'] ?? '');

    if ($leftDueDate !== $rightDueDate) {
        if ($leftDueDate === '') {
            return 1;
        }

        if ($rightDueDate === '') {
            return -1;
        }

        return strcmp($leftDueDate, $rightDueDate);
    }

    $leftBookingDate = (string) ($left['booking_date'] ?? '');
    $rightBookingDate = (string) ($right['booking_date'] ?? '');

    if ($leftBookingDate !== $rightBookingDate) {
        return strcmp($leftBookingDate, $rightBookingDate);
    }

    $leftBookingId = (int) ($left['booking_id'] ?? 0);
    $rightBookingId = (int) ($right['booking_id'] ?? 0);

    if ($leftBookingId !== $rightBookingId) {
        return $leftBookingId <=> $rightBookingId;
    }

    return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
});

$openReceivables = array_values(array_merge($currentBookingReceivables, $otherReceivables));

        foreach ($openReceivables as $receivable) {
            if (in_array((int) ($receivable['id'] ?? 0), $skipReceivableIds, true)) {
                continue;
            }

            if ((string) ($receivable['currency'] ?? '') !== $receiptCurrency) {
                continue;
            }

            $receipt = $paymentRepository->findReceiptById($receiptId);
            if ($receipt === null) {
                throw new RuntimeException('The saved receipt could not be reloaded for auto allocation.');
            }

            $remainingReceiptAmount = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
            $remainingReceivableAmount = round((float) ($receivable['outstanding_amount'] ?? 0), 2);
            if ($remainingReceiptAmount <= 0.005 || $remainingReceivableAmount <= 0.005) {
                continue;
            }

            $allocationAmount = round(min($remainingReceiptAmount, $remainingReceivableAmount), 2);
            if ($allocationAmount <= 0.005) {
                continue;
            }

            $allocationResult = $paymentRepository->allocateReceiptExplicit([
                'receipt_id' => $receiptId,
                'receivable_item_id' => (int) $receivable['id'],
                'receivable_amount_to_settle' => $allocationAmount,
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $receiptCurrency,
                'rate_to_currency' => $receiptCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $receiptDate,
                'allocation_note' => $isCurrentBookingReceivable
    ? 'Auto allocated to current invoice from workspace quick receive.'
    : 'Remaining amount from receipt ' . $receiptNo . ' for invoice ' . (string) ($booking['booking_reference'] ?? '') . ' applied to previous/open invoice ' . (string) ($receivable['booking_reference'] ?? '') . '.',
                'actor_user_id' => $actorUserId,
            ]);
            $allocationId = (int) ($allocationResult['allocation_id'] ?? 0);
            $allocatedAmount = round((float) ($allocationResult['allocated_amount'] ?? 0), 2);
            if ($allocationId <= 0 || $allocatedAmount <= 0) {
                throw new RuntimeException('Receipt auto allocation could not be completed.');
            }

            $accountingRepository->postCustomerReceiptAllocation([
                'branch_id' => (int) ($receivable['branch_id'] ?? $booking['branch_id']),
                'booking_reference' => (string) ($receivable['booking_reference'] ?? $booking['booking_reference']),
                'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
                'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
                'customer_receivable_item_id' => (int) $receivable['id'],
                'customer_receipt_id' => $receiptId,
                'allocated_amount' => $allocatedAmount,
                'entry_date' => $receiptDate,
                'currency' => (string) ($receivable['currency'] ?? $receiptCurrency),
                'actor_user_id' => $actorUserId,
            ]);
        }
    }

    private function buildExplicitAllocationPayload(array $receipt, array $receivable, array $line): array
    {
        $receiptCurrency = strtoupper(trim((string) ($receipt['currency'] ?? '')));
        $receivableCurrency = strtoupper(trim((string) ($receivable['currency'] ?? '')));
        $rateDate = (string) ($line['exchange_rate_effective_date'] ?? '') !== ''
            ? (string) $line['exchange_rate_effective_date']
            : (string) ($receipt['receipt_date'] ?? date('Y-m-d'));

        if ($receiptCurrency === $receivableCurrency) {
            return [
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $receivableCurrency,
                'rate_to_currency' => $receiptCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $rateDate,
            ];
        }

        $rateFromCurrency = trim((string) ($line['rate_from_currency'] ?? ''));
        $rateToCurrency = trim((string) ($line['rate_to_currency'] ?? ''));
        $exchangeRate = $line['exchange_rate'] ?? null;

        if ($rateFromCurrency !== '' && $rateToCurrency !== '' && $exchangeRate !== null) {
            return [
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $rateFromCurrency,
                'rate_to_currency' => $rateToCurrency,
                'exchange_rate' => (float) $exchangeRate,
                'exchange_rate_effective_date' => $rateDate,
            ];
        }

        $exactRate = (new ExchangeRateRepository($this->app))->requireExactRate(
            $receivableCurrency,
            $receiptCurrency,
            $rateDate
        );

        return [
            'payment_currency' => $receiptCurrency,
            'rate_from_currency' => (string) ($exactRate['from_currency'] ?? $receivableCurrency),
            'rate_to_currency' => (string) ($exactRate['to_currency'] ?? $receiptCurrency),
            'exchange_rate' => (float) ($exactRate['exchange_rate'] ?? 0),
            'exchange_rate_effective_date' => (string) ($exactRate['effective_date'] ?? $rateDate),
        ];
    }

    private function normalizeCurrency(string $value): string
    {
        $currency = strtoupper(trim($value));
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new RuntimeException('Please select a valid receipt currency.');
        }

        return $currency;
    }

    private function normalizeMethod(string $value): string
    {
        $method = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new RuntimeException('Please select a valid payment method.');
        }

        return $method;
    }

    private function normalizeStatus(string $value): string
    {
        $status = str_replace(' ', '_', mb_strtolower(trim($value)));
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new RuntimeException('Please select a valid receipt status.');
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
            throw new RuntimeException('Exchange rate must be greater than zero.');
        }

        return round((float) $trimmed, 8);
    }

    private function normalizeOptionalDate(string $value): ?string
    {
        $date = trim($value);
        if ($date === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Due date is invalid.');
        }

        return $date;
    }

    private function moneyValue(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException($label . ' must be greater than zero.');
        }

        return round((float) $value, 2);
    }

    private function nonNegativeMoneyValue(mixed $value, string $label): float
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
            throw new RuntimeException('One of the receipt values exceeds the allowed length.');
        }

        return $text;
    }
}
