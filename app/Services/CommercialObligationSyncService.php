<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
use RuntimeException;

final class CommercialObligationSyncService extends Service
{
    public function syncForServiceId(int $serviceId, int $actorUserId): void
    {
        $service = (new BookingServiceRepository($this->app))->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException('The selected service line could not be loaded for commercial sync.');
        }

        $booking = (new BookingRepository($this->app))->findBookingById((int) $service['booking_id']);
        if ($booking === null) {
            throw new RuntimeException('The booking linked to this service line could not be loaded.');
        }

        $receivableAmount = $this->receivableAmount($service);
        $payableAmount = $this->payableAmount($service);

        $customerRepository = new CustomerPaymentRepository($this->app);
        $receivableSync = $customerRepository->syncReceivableItem([
            'branch_id' => (int) $service['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'service_line_reference' => (string) $service['line_reference'],
            'due_group' => 'service_sale',
            'currency' => (string) $service['currency'],
            'due_amount' => (int) ($service['is_active'] ?? 1) === 1 ? $receivableAmount : 0,
            'due_date' => $service['due_date'] ?? null,
            'status' => 'open',
            'remarks' => $this->financialRemarks('Customer receivable synced from service line', $service),
            'actor_user_id' => $actorUserId,
        ]);

        $supplierRepository = new SupplierRepository($this->app);
        $obligationSync = $supplierRepository->syncObligation([
            'supplier_id' => ! empty($service['supplier_id']) ? (int) $service['supplier_id'] : null,
            'branch_id' => (int) $service['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'service_line_reference' => (string) $service['line_reference'],
            'obligation_group' => 'service_cost',
            'currency' => (string) $service['currency'],
            'gross_amount' => (int) ($service['is_active'] ?? 1) === 1 ? $payableAmount : 0,
            'due_date' => $service['due_date'] ?? null,
            'remarks' => $this->financialRemarks('Supplier payable synced from service line', $service),
            'actor_user_id' => $actorUserId,
        ]);

        $entryDate = (string) ($booking['booking_date'] ?? date('Y-m-d'));
        $accountingRepository = new AccountingRepository($this->app);

        if ($receivableSync !== null) {
            $delta = round((float) ($receivableSync['delta_amount'] ?? 0), 2);
            if ((string) ($receivableSync['action'] ?? '') === 'created' && $delta > 0) {
                $accountingRepository->postReceivableCreated([
                    'branch_id' => (int) $service['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_line_reference' => (string) $service['line_reference'],
                    'customer_receivable_item_id' => $receivableSync['record']['id'] ?? null,
                    'gross_amount' => $delta,
                    'entry_date' => $entryDate,
                    'currency' => (string) $service['currency'],
                    'actor_user_id' => $actorUserId,
                ]);
            } elseif ($delta !== 0.0) {
                $accountingRepository->postReceivableAdjusted([
                    'branch_id' => (int) $service['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_line_reference' => (string) $service['line_reference'],
                    'customer_receivable_item_id' => $receivableSync['record']['id'] ?? null,
                    'adjustment_amount' => $delta,
                    'entry_date' => $entryDate,
                    'currency' => (string) $service['currency'],
                    'actor_user_id' => $actorUserId,
                ]);
            }
        }

        if ($obligationSync !== null) {
            $delta = round((float) ($obligationSync['delta_amount'] ?? 0), 2);
            if ((string) ($obligationSync['action'] ?? '') === 'created' && $delta > 0) {
                $accountingRepository->postPayableCreated([
                    'branch_id' => (int) $service['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_line_reference' => (string) $service['line_reference'],
                    'supplier_obligation_id' => $obligationSync['record']['id'] ?? null,
                    'gross_amount' => $delta,
                    'entry_date' => $entryDate,
                    'currency' => (string) $service['currency'],
                    'actor_user_id' => $actorUserId,
                ]);
            } elseif ($delta !== 0.0) {
                $accountingRepository->postPayableAdjusted([
                    'branch_id' => (int) $service['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_line_reference' => (string) $service['line_reference'],
                    'supplier_obligation_id' => $obligationSync['record']['id'] ?? null,
                    'adjustment_amount' => $delta,
                    'entry_date' => $entryDate,
                    'currency' => (string) $service['currency'],
                    'actor_user_id' => $actorUserId,
                ]);
            }
        }

        AuditLog::record($this->app, 'commercial.obligations.synced', [
            'user_id' => $actorUserId,
            'booking_id' => (int) $service['booking_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'service_id' => (int) $service['id'],
            'service_line_reference' => (string) $service['line_reference'],
            'receivable_amount' => $receivableAmount,
            'payable_amount' => $payableAmount,
        ]);
    }

    private function receivableAmount(array $service): float
    {
        if (array_key_exists('final_sale_price', $service) && $service['final_sale_price'] !== null && $service['final_sale_price'] !== '') {
            return round((float) $service['final_sale_price'], 2);
        }

        if ((string) ($service['service_type'] ?? '') === 'air ticket') {
            return round(
                $this->airTicketPayableAmount($service)
                + (float) ($service['service_charge'] ?? 0)
                - (float) ($service['discount_amount'] ?? 0),
                2
            );
        }

        return round(
            (float) ($service['sale_price'] ?? 0)
            + (float) ($service['service_charge'] ?? 0)
            - (float) ($service['discount_amount'] ?? 0),
            2
        );
    }

    private function payableAmount(array $service): float
    {
        if ((string) ($service['service_type'] ?? '') === 'air ticket') {
            return $this->airTicketPayableAmount($service);
        }

        return round((float) ($service['purchase_cost'] ?? 0), 2);
    }

    private function airTicketPayableAmount(array $service): float
    {
        return round((float) ($service['purchase_cost'] ?? 0), 2);
    }

    private function financialRemarks(string $prefix, array $service): string
    {
        $serviceType = ucwords((string) ($service['service_type'] ?? 'service'));
        $lineReference = (string) ($service['line_reference'] ?? 'SV-DRAFT');

        return $prefix . ' [' . $lineReference . ' / ' . $serviceType . ']';
    }
}
