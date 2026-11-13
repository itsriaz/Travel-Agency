<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SupplierPaymentVoidImpactRepository;
use RuntimeException;

final class SupplierPaymentVoidReplacementService
{
    public function __construct(private readonly App $app)
    {
    }

    public function plan(array $impact, array $accessibleBranchIds): array
    {
        $payment = $impact['payment'];
        $paymentId = (int) $payment['id'];
        $supplierId = (int) $payment['supplier_id'];
        $released = [];
        foreach ($impact['direct_allocations'] as $row) {
            $id = (int) $row['obligation_id'];
            $released[$id] = round(($released[$id] ?? 0) + (float) $row['amount'], 2);
        }
        foreach ($impact['advance_applications'] as $row) {
            $id = (int) $row['obligation_id'];
            $released[$id] = round(($released[$id] ?? 0) + (float) $row['obligation_amount'], 2);
        }

        $repository = new SupplierPaymentVoidImpactRepository($this->app);
        $obligations = $repository->affectedObligations(array_keys($released));
        if (count($obligations) !== count($released)) {
            throw new RuntimeException('An affected supplier payable or booking is missing. Nothing can be voided from this preview.');
        }
        $accessibleIds = array_map('intval', $accessibleBranchIds);
        foreach ($obligations as &$obligation) {
            if ((int) $obligation['supplier_id'] !== $supplierId
                || ! in_array((int) $obligation['branch_id'], $accessibleIds, true)) {
                throw new RuntimeException('An affected supplier payable is outside this supplier or accessible branch.');
            }
            $obligation['reopened_amount'] = $released[(int) $obligation['id']];
            $obligation['current_payable'] = round((float) $obligation['net_payable_amount'], 2);
            $obligation['projected_payable'] = round($obligation['current_payable'] + $obligation['reopened_amount'], 2);
        }
        unset($obligation);

        $linkedIds = array_map('intval', array_column($impact['linked_advances'], 'id'));
        $advances = array_values(array_filter(
            $repository->otherAvailableAdvances($supplierId, $paymentId),
            static fn (array $row): bool => ! in_array((int) $row['id'], $linkedIds, true)
                && in_array((int) $row['branch_id'], $accessibleIds, true)
        ));
        $remainingByAdvance = [];
        foreach ($advances as $advance) {
            $remainingByAdvance[(int) $advance['id']] = round((float) $advance['available_amount'], 2);
        }

        $canApply = $impact['cancellation_events'] === [];
        $lines = [];
        foreach ($obligations as &$obligation) {
            $toCover = $canApply && (int) $obligation['booking_id'] > 0
                && (string) $obligation['status'] !== 'cancelled'
                ? $obligation['reopened_amount'] : 0.0;
            $obligation['covered_amount'] = 0.0;
            foreach ($advances as $advance) {
                if ($toCover <= 0.005) {
                    break;
                }
                $advanceId = (int) $advance['id'];
                if ((string) $advance['currency'] !== (string) $obligation['currency']) {
                    continue;
                }
                $amount = round(min($toCover, $remainingByAdvance[$advanceId]), 2);
                if ($amount <= 0.005) {
                    continue;
                }
                $lines[] = [
                    'advance_id' => $advanceId,
                    'source_label' => (string) $advance['source_label'],
                    'obligation_id' => (int) $obligation['id'],
                    'booking_id' => (int) $obligation['booking_id'],
                    'booking_reference' => (string) $obligation['booking_reference'],
                    'branch_id' => (int) $obligation['branch_id'],
                    'currency' => (string) $obligation['currency'],
                    'amount' => $amount,
                ];
                $remainingByAdvance[$advanceId] = round($remainingByAdvance[$advanceId] - $amount, 2);
                $toCover = round($toCover - $amount, 2);
                $obligation['covered_amount'] = round($obligation['covered_amount'] + $amount, 2);
            }
            $obligation['remaining_open'] = round($obligation['projected_payable'] - $obligation['covered_amount'], 2);
        }
        unset($obligation);

        $token = hash('sha256', json_encode([
            'payment_id' => $paymentId,
            'impact_token' => $impact['impact_token'],
            'obligations' => $obligations,
            'advances' => $advances,
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR));

        return [
            'token' => $token,
            'obligations' => $obligations,
            'other_advances' => $advances,
            'lines' => $lines,
            'can_apply' => $canApply && $lines !== [],
        ];
    }

    public static function outcomeMessage(string $paymentNo, array $replacement): string
    {
        $details = [];
        $openByCurrency = [];
        foreach ($replacement['obligations'] ?? [] as $obligation) {
            $used = [];
            foreach ($replacement['applications'] ?? [] as $application) {
                if ((int) $application['obligation_id'] === (int) $obligation['obligation_id']) {
                    $used[] = (string) $application['source_label'] . ' '
                        . number_format((float) $application['amount'], 2);
                }
            }
            $currency = (string) $obligation['currency'];
            $openByCurrency[$currency] = round(($openByCurrency[$currency] ?? 0)
                + (float) $obligation['open'], 2);
            $details[] = (string) $obligation['booking_reference'] . ' (payable #'
                . (int) $obligation['obligation_id'] . '): '
                . ($used === [] ? 'no replacement applied' : $currency . ' '
                    . number_format((float) $obligation['covered'], 2) . ' covered from ' . implode(', ', $used))
                . '; ' . $currency . ' ' . number_format((float) $obligation['open'], 2) . ' remains open';
        }

        $totals = [];
        foreach ($openByCurrency as $currency => $amount) {
            $totals[] = $currency . ' ' . number_format($amount, 2) . ' remains open';
        }

        return 'Supplier payment ' . $paymentNo . ' was voided. '
            . ($details === [] ? 'No supplier payable was affected.'
                : implode('. ', $details) . '. Total: ' . implode('; ', $totals) . '.');
    }
}
