<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\SupplierPaymentVoidImpactRepository;
use App\Helpers\AuditLog;
use RuntimeException;

final class SupplierCancellationCreditVoidService extends Service
{
    public function prepare(array $preview, array $input, array $accessibleBranchIds): array
    {
        if (! $this->app->get('db')->inTransaction()) {
            throw new RuntimeException('Cancellation credit void must run in one financial transaction.');
        }
        $events = $preview['cancellation_events'] ?? [];
        if ($events === []) {
            return [];
        }
        if (! ($preview['cancellation_events_complete'] ?? false)) {
            throw new RuntimeException('A cancellation credit could not be matched to its event. Nothing was voided.');
        }
        if ((string) ($input['confirm_remove_cancellation_credit'] ?? '') !== '1') {
            throw new RuntimeException('Review Void Impact and confirm removal of this payment\'s cancellation credit before voiding.');
        }
        $entered = $input['cancellation_credit_equivalent'] ?? null;
        if (! is_array($entered)) {
            throw new RuntimeException('Enter the exact booking-currency credit reduction for every affected cancellation.');
        }

        $eventRepository = new BookingServiceEventRepository($this->app);
        $impactRepository = new SupplierPaymentVoidImpactRepository($this->app);
        $paymentId = (int) $preview['payment']['id'];
        $prepared = [];
        foreach ($events as $event) {
            $eventId = (int) $event['event_id'];
            if ($event['has_posted_supplier_refund']) {
                throw new RuntimeException('Cancellation event ' . $eventId . ' already has a posted supplier refund. Review that refund before voiding its funding payment.');
            }
            $rawValue = $entered[$eventId] ?? null;
            $rawEquivalent = is_scalar($rawValue) ? trim((string) $rawValue) : '';
            if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $rawEquivalent)) {
                throw new RuntimeException('Enter an exact amount with at most two decimal places for cancellation event ' . $eventId . '.');
            }
            $equivalent = round((float) $rawEquivalent, 2);
            $totalEquivalent = round((float) $event['recorded_booking_equivalent'], 2);
            $selectedAmount = round((float) $event['selected_source_amount'], 2);
            $otherAmount = round((float) $event['other_source_amount'], 2);
            $totalSource = round((float) $event['recorded_source_amount'], 2);
            if ($selectedAmount <= 0 || ! is_finite($equivalent) || $equivalent <= 0
                || $equivalent > $totalEquivalent + 0.005
                || abs($selectedAmount + $otherAmount - $totalSource) > 0.01) {
                throw new RuntimeException('Cancellation event ' . $eventId . ' does not reconcile with the entered credit reduction.');
            }
            if (($otherAmount <= 0.005 && abs($equivalent - $totalEquivalent) > 0.005)
                || ($otherAmount > 0.005 && $equivalent >= $totalEquivalent - 0.005)) {
                throw new RuntimeException('The entered equivalent must preserve the other payment\'s share of cancellation event ' . $eventId . '.');
            }
            if ((string) $event['booking_currency'] === (string) $event['recorded_source_currency']
                && abs($equivalent - $selectedAmount) > 0.005) {
                throw new RuntimeException('Cancellation event ' . $eventId . ' uses one currency; its credit reduction must equal the payment share.');
            }

            $locked = $eventRepository->lockPostedEventById($eventId, 'cancel');
            if ($locked === null
                || hash('sha256', (string) ($locked['payload_json'] ?? '')) !== (string) $event['payload_hash']
                || ! in_array((int) $locked['branch_id'], array_map('intval', $accessibleBranchIds), true)
                || abs((float) $locked['supplier_credit_amount'] - $totalEquivalent) > 0.005) {
                throw new RuntimeException('A cancellation changed during review. Reload Void Impact; nothing was voided.');
            }
            $payload = json_decode((string) $locked['payload_json'], true);
            if (! is_array($payload)
                || (string) ($payload['supplier_credit_recorded_currency'] ?? '') !== (string) $event['recorded_source_currency']
                || abs((float) ($payload['supplier_credit_recorded_source_amount'] ?? -1) - $totalSource) > 0.005
                || abs((float) ($payload['supplier_credit_recorded_booking_amount'] ?? -1) - $totalEquivalent) > 0.005
                || (float) ($payload['released_supplier_credit_source_amount'] ?? -1) + 0.005 < $selectedAmount
                || (float) ($payload['released_supplier_credit'] ?? -1) + 0.005 < $equivalent
                || (float) ($payload['released_supplier_payment_credit'] ?? -1) + 0.005 < $selectedAmount
                || (float) ($payload['supplier_credit_converted_payment_amount'] ?? -1) + 0.005 < $selectedAmount
                || ! in_array($paymentId, array_map('intval', (array) ($payload['released_supplier_payment_ids'] ?? [])), true)) {
                throw new RuntimeException('Cancellation event ' . $eventId . ' no longer matches its recorded supplier credit. Nothing was voided.');
            }
            foreach ([
                'expected_supplier_refund_source_amount',
                'expected_supplier_refund_credit_source_amount',
            ] as $key) {
                if (! isset($payload[$key]) || (float) $payload[$key] + 0.005 < $selectedAmount) {
                    throw new RuntimeException('Cancellation event ' . $eventId . ' cannot safely reduce its expected supplier refund in the source currency.');
                }
            }
            foreach ([
                'expected_supplier_refund_amount',
                'expected_supplier_refund_equivalent_amount',
                'expected_supplier_refund_booking_amount',
                'expected_supplier_refund_credit',
            ] as $key) {
                if (! isset($payload[$key]) || (float) $payload[$key] + 0.005 < $equivalent) {
                    throw new RuntimeException('Cancellation event ' . $eventId . ' cannot safely reduce its expected supplier refund in the booking currency.');
                }
            }

            $selectedCreditIds = [];
            foreach ($event['funding'] as $funding) {
                if ((string) $funding['currency'] !== (string) $event['recorded_source_currency']) {
                    throw new RuntimeException('Cancellation event ' . $eventId . ' mixes credit currencies. Nothing was voided.');
                }
                if ((float) $funding['amount'] > 0.005 && (string) $funding['payment_no'] === '') {
                    throw new RuntimeException('Cancellation event ' . $eventId . ' has credit without a traceable payment source. Nothing was voided.');
                }
                if ($funding['is_selected']) {
                    $selectedCreditIds[] = (int) $funding['credit_id'];
                }
            }
            if ($selectedCreditIds === [] || array_diff($selectedCreditIds,
                array_map('intval', (array) ($payload['supplier_credit_converted_advance_ids'] ?? []))) !== []) {
                throw new RuntimeException('Cancellation event ' . $eventId . ' lost its payment-funded credit link. Nothing was voided.');
            }

            $netAdvance = 0.0;
            $netPayable = 0.0;
            $obligationIds = [];
            foreach ($impactRepository->cancellationReleaseJournalLines($eventId) as $line) {
                if ((int) $line['branch_id'] !== (int) $locked['branch_id']
                    || (string) $line['currency'] !== (string) $event['recorded_source_currency']
                    || (string) $line['booking_reference'] !== (string) $locked['booking_reference']
                    || (string) $line['service_line_reference'] !== (string) $locked['service_line_reference']) {
                    throw new RuntimeException('Cancellation release journal dimensions do not match event ' . $eventId . '.');
                }
                $obligationIds[(int) $line['supplier_obligation_id']] = true;
                $amount = round((float) $line['debit_amount'] - (float) $line['credit_amount'], 2);
                if ((string) $line['account_code'] === 'SUPPLIER_ADVANCES') {
                    $netAdvance = round($netAdvance + $amount, 2);
                } elseif ((string) $line['account_code'] === 'AP_CONTROL') {
                    $netPayable = round($netPayable - $amount, 2);
                } else {
                    throw new RuntimeException('Cancellation release journal has an unexpected account for event ' . $eventId . '.');
                }
            }
            $obligationId = (int) array_key_first($obligationIds);
            if (count($obligationIds) !== 1 || $obligationId <= 0
                || abs($netAdvance - $netPayable) > 0.01
                || abs($netAdvance - (float) $payload['released_supplier_credit_source_amount']) > 0.01
                || $netAdvance + 0.005 < $selectedAmount) {
                throw new RuntimeException('Cancellation release journal does not reconcile for event ' . $eventId . '. Nothing was voided.');
            }
            $prepared[] = compact('eventId', 'locked', 'payload', 'selectedAmount', 'equivalent', 'selectedCreditIds', 'obligationId');
        }

        $represented = array_merge(...array_map(
            static fn (array $item): array => $item['selectedCreditIds'], $prepared
        ));
        sort($represented);
        $expected = array_map('intval', (array) $preview['cancellation_credit_ids']);
        sort($expected);
        if ($represented !== $expected) {
            throw new RuntimeException('Not all cancellation credits were accounted for. Nothing was voided.');
        }

        return $prepared;
    }

    public function post(array $prepared, array $payment, int $actorUserId): array
    {
        if (! $this->app->get('db')->inTransaction()) {
            throw new RuntimeException('Cancellation credit void must run in one financial transaction.');
        }
        $accounting = new AccountingRepository($this->app);
        $events = new BookingServiceEventRepository($this->app);
        $journalIds = [];
        foreach ($prepared as $item) {
            $event = $item['locked'];
            $payload = $item['payload'];
            $eventId = (int) $item['eventId'];
            $sourceAmount = (float) $item['selectedAmount'];
            $equivalent = (float) $item['equivalent'];
            $expectedSourceBefore = (float) $payload['expected_supplier_refund_source_amount'];
            $expectedBookingBefore = (float) $payload['expected_supplier_refund_amount'];
            $journalId = $accounting->postSupplierSettlementReleaseVoidAdjustment([
                'branch_id' => (int) $event['branch_id'],
                'booking_reference' => (string) $event['booking_reference'],
                'service_line_reference' => (string) $event['service_line_reference'],
                'supplier_obligation_id' => (int) $item['obligationId'],
                'currency' => (string) $payload['supplier_credit_recorded_currency'],
                'amount' => $sourceAmount,
                'source_reference' => 'VOID-' . (string) $payment['payment_no'] . '-CANCEL-' . $eventId,
                'entry_date' => date('Y-m-d'),
                'actor_user_id' => $actorUserId,
            ]);
            foreach ([
                'released_supplier_credit_source_amount',
                'released_supplier_payment_credit',
                'supplier_credit_recorded_source_amount',
                'supplier_credit_converted_payment_amount',
                'expected_supplier_refund_source_amount',
                'expected_supplier_refund_credit_source_amount',
            ] as $key) {
                $payload[$key] = $this->subtractRecordedAmount(
                    (float) $payload[$key], $sourceAmount, $key, $eventId
                );
            }
            foreach ([
                'released_supplier_credit',
                'supplier_credit_recorded_booking_amount',
                'expected_supplier_refund_amount',
                'expected_supplier_refund_equivalent_amount',
                'expected_supplier_refund_booking_amount',
                'expected_supplier_refund_credit',
            ] as $key) {
                $payload[$key] = $this->subtractRecordedAmount(
                    (float) $payload[$key], $equivalent, $key, $eventId
                );
            }
            $payload['supplier_credit_converted_advance_ids'] = array_values(array_diff(
                array_map('intval', (array) $payload['supplier_credit_converted_advance_ids']),
                $item['selectedCreditIds']
            ));
            $payload['released_supplier_payment_ids'] = array_values(array_diff(
                array_map('intval', (array) $payload['released_supplier_payment_ids']),
                [(int) $payment['id']]
            ));
            $payload['journal_entry_ids'] = array_values(array_unique(array_merge(
                array_map('intval', (array) ($payload['journal_entry_ids'] ?? [])), [$journalId]
            )));
            $payload['supplier_payment_void_adjustments'][] = [
                'supplier_payment_id' => (int) $payment['id'],
                'source_amount' => $sourceAmount,
                'booking_equivalent_removed' => $equivalent,
                'expected_source_before' => $expectedSourceBefore,
                'expected_booking_before' => $expectedBookingBefore,
                'journal_entry_id' => $journalId,
                'actor_user_id' => $actorUserId,
                'posted_on' => date('Y-m-d'),
            ];
            $newBookingCredit = $this->subtractRecordedAmount(
                (float) $event['supplier_credit_amount'], $equivalent, 'supplier_credit_amount', $eventId
            );
            $events->reduceCancellationCreditForSupplierPaymentVoid($eventId, $newBookingCredit, $payload);
            AuditLog::recordRequired($this->app, 'supplier.payment.cancellation_credit_removed_for_void', [
                'user_id' => $actorUserId,
                'supplier_payment_id' => (int) $payment['id'],
                'payment_no' => (string) $payment['payment_no'],
                'service_event_id' => $eventId,
                'booking_reference' => (string) $event['booking_reference'],
                'source_currency' => (string) $payload['supplier_credit_recorded_currency'],
                'source_amount_removed' => $sourceAmount,
                'booking_currency' => (string) $event['currency'],
                'booking_equivalent_removed' => $equivalent,
                'credit_before' => (float) $event['supplier_credit_amount'],
                'credit_after' => $newBookingCredit,
                'expected_source_before' => $expectedSourceBefore,
                'expected_source_after' => (float) $payload['expected_supplier_refund_source_amount'],
                'expected_booking_before' => $expectedBookingBefore,
                'expected_booking_after' => (float) $payload['expected_supplier_refund_amount'],
                'credit_ids_removed' => $item['selectedCreditIds'],
                'journal_entry_id' => $journalId,
            ]);
            $journalIds[] = $journalId;
        }

        return $journalIds;
    }

    private function subtractRecordedAmount(float $before, float $remove, string $field, int $eventId): float
    {
        $after = round($before - $remove, 2);
        if ($after < -0.005) {
            throw new RuntimeException('Cancellation event ' . $eventId . ' cannot reduce ' . $field . ' below zero. Nothing was voided.');
        }

        return $after <= 0.005 ? 0.0 : $after;
    }
}
