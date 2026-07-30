<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
use RuntimeException;
use Throwable;

final class CommercialObligationSyncService extends Service
{
    public function syncForServiceId(int $serviceId, int $actorUserId, array $postingContext = []): void
    {
        $traceId = $this->supplierAdvanceTraceId();
        /** @var \PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
        $service = (new BookingServiceRepository($this->app))->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException('The selected service line could not be loaded for commercial sync.');
        }

        $booking = (new BookingRepository($this->app))->findBookingById((int) $service['booking_id']);
        if ($booking === null) {
            throw new RuntimeException('The booking linked to this service line could not be loaded.');
        }

        $reissueMirrors = (new BookingServiceEventRepository($this->app))->separateReissueMirrorAdjustments($serviceId);
        $receivableAmount = round(max($this->receivableAmount($service) - (float) ($reissueMirrors['customer_mirror_amount'] ?? 0), 0), 2);
        $payableAmount = round(max($this->payableAmount($service) - (float) ($reissueMirrors['supplier_mirror_amount'] ?? 0), 0), 2);
        $invoiceCurrency = (string) ($service['currency'] ?? 'PKR');
        $costCurrency = (string) ($service['cost_currency'] ?? $invoiceCurrency);
        $this->supplierAdvanceTraceLog('syncForServiceId', 'commercial_sync_entry', [
            'trace_id' => $traceId,
            'booking_id' => (int) ($service['booking_id'] ?? 0),
            'booking_service_id' => (int) ($service['id'] ?? 0),
            'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
            'supplier_id' => ! empty($service['supplier_id']) ? (int) $service['supplier_id'] : null,
            'branch_id' => (int) ($service['branch_id'] ?? 0),
            'currency' => $costCurrency,
            'invoice_currency' => $invoiceCurrency,
            'purchase_cost' => $payableAmount,
            'mkt_fare' => round((float) ($service['sale_price'] ?? 0), 2),
            'service_type' => (string) ($service['service_type'] ?? ''),
            'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'service_line_reference' => (string) ($service['line_reference'] ?? ''),
            'action' => 'method_entered',
        ]);

        $customerRepository = new CustomerPaymentRepository($this->app);
        $receivableSync = $customerRepository->syncReceivableItem([
            'branch_id' => (int) $service['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'service_line_reference' => (string) $service['line_reference'],
            'due_group' => 'service_sale',
            'currency' => $invoiceCurrency,
            'due_amount' => (int) ($service['is_active'] ?? 1) === 1 ? $receivableAmount : 0,
            'due_date' => $service['due_date'] ?? null,
            'status' => 'open',
            'remarks' => $this->financialRemarks('Customer receivable synced from service line', $service),
            'actor_user_id' => $actorUserId,
        ]);

        $supplierRepository = new SupplierRepository($this->app);
        $this->supplierAdvanceTraceLog('syncForServiceId', 'before_sync_obligation', [
            'trace_id' => $traceId,
            'booking_id' => (int) ($service['booking_id'] ?? 0),
            'booking_service_id' => (int) ($service['id'] ?? 0),
            'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
            'supplier_id' => ! empty($service['supplier_id']) ? (int) $service['supplier_id'] : null,
            'branch_id' => (int) ($service['branch_id'] ?? 0),
            'currency' => $costCurrency,
            'invoice_currency' => $invoiceCurrency,
            'purchase_cost' => $payableAmount,
            'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'service_line_reference' => (string) ($service['line_reference'] ?? ''),
            'action' => 'calling_syncObligation',
        ]);
        $obligationSync = $supplierRepository->syncObligation([
            'supplier_id' => ! empty($service['supplier_id']) ? (int) $service['supplier_id'] : null,
            'branch_id' => (int) $service['branch_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'service_line_reference' => (string) $service['line_reference'],
            'obligation_group' => 'service_cost',
            'currency' => $costCurrency,
            'gross_amount' => (int) ($service['is_active'] ?? 1) === 1 ? $payableAmount : 0,
            'due_date' => $service['due_date'] ?? null,
            'remarks' => $this->financialRemarks('Supplier payable synced from service line', $service),
            'actor_user_id' => $actorUserId,
        ]);
        $obligationRecord = is_array($obligationSync['record'] ?? null) ? $obligationSync['record'] : null;
        $obligationId = (int) ($obligationRecord['id'] ?? 0);
        $obligationAction = (string) ($obligationSync['action'] ?? '');
        $this->supplierAdvanceTraceLog('syncForServiceId', 'after_sync_obligation', [
            'trace_id' => $traceId,
            'booking_id' => (int) ($service['booking_id'] ?? 0),
            'booking_service_id' => (int) ($service['id'] ?? 0),
            'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
            'supplier_id' => ! empty($service['supplier_id']) ? (int) $service['supplier_id'] : null,
            'branch_id' => (int) ($service['branch_id'] ?? 0),
            'currency' => $costCurrency,
            'invoice_currency' => $invoiceCurrency,
            'purchase_cost' => $payableAmount,
            'obligation_id' => $obligationId > 0 ? $obligationId : null,
            'action' => $obligationAction !== '' ? $obligationAction : gettype($obligationSync),
            'net_payable_amount' => isset($obligationRecord['net_payable_amount']) ? round((float) $obligationRecord['net_payable_amount'], 2) : null,
            'advance_applied_amount' => isset($obligationRecord['advance_applied_amount']) ? round((float) $obligationRecord['advance_applied_amount'], 2) : null,
            'status' => (string) ($obligationRecord['status'] ?? ''),
        ]);

        $entryDate = (string) ($postingContext['entry_date'] ?? $booking['booking_date'] ?? date('Y-m-d'));
        $accountingRepository = new AccountingRepository($this->app);

        if ($receivableSync !== null) {
            $delta = round((float) ($receivableSync['delta_amount'] ?? 0), 2);
            $priorInvoiceCurrency = strtoupper(trim((string) ($postingContext['prior_invoice_currency'] ?? $invoiceCurrency)));
            $invoiceCurrencyChanged = (bool) ($postingContext['financial_correction'] ?? false)
                && $priorInvoiceCurrency !== strtoupper(trim($invoiceCurrency))
                && (string) ($receivableSync['action'] ?? '') === 'updated';

            if ($invoiceCurrencyChanged) {
                $priorAmount = round((float) ($receivableSync['prior_amount'] ?? 0), 2);
                $newAmount = round((float) ($receivableSync['record']['due_amount'] ?? 0), 2);
                $receivableId = $receivableSync['record']['id'] ?? null;

                if ($priorAmount > 0) {
                    $accountingRepository->postReceivableAdjusted([
                        'branch_id' => (int) $service['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'source_reference' => (string) $service['line_reference'] . '-CUR-OLD',
                        'service_line_reference' => (string) $service['line_reference'],
                        'customer_receivable_item_id' => $receivableId,
                        'adjustment_amount' => -$priorAmount,
                        'entry_date' => $entryDate,
                        'currency' => $priorInvoiceCurrency,
                        'narration' => 'Original-currency receivable reversed by service financial correction',
                        'actor_user_id' => $actorUserId,
                    ]);
                }

                if ($newAmount > 0) {
                    $accountingRepository->postReceivableAdjusted([
                        'branch_id' => (int) $service['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'source_reference' => (string) $service['line_reference'] . '-CUR-NEW',
                        'service_line_reference' => (string) $service['line_reference'],
                        'customer_receivable_item_id' => $receivableId,
                        'adjustment_amount' => $newAmount,
                        'entry_date' => $entryDate,
                        'currency' => $invoiceCurrency,
                        'narration' => 'New-currency receivable recognized by service financial correction',
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            } elseif ((string) ($receivableSync['action'] ?? '') === 'created' && $delta > 0) {
                $accountingRepository->postReceivableCreated([
                    'branch_id' => (int) $service['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_line_reference' => (string) $service['line_reference'],
                    'customer_receivable_item_id' => $receivableSync['record']['id'] ?? null,
                    'gross_amount' => $delta,
                    'entry_date' => $entryDate,
                    'currency' => $invoiceCurrency,
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
                    'currency' => $invoiceCurrency,
                    'actor_user_id' => $actorUserId,
                ]);
            }
        }

        if ($obligationSync !== null) {
            $delta = round((float) ($obligationSync['delta_amount'] ?? 0), 2);
            $priorCostCurrency = strtoupper(trim((string) ($postingContext['prior_cost_currency'] ?? $costCurrency)));
            $costCurrencyChanged = (bool) ($postingContext['financial_correction'] ?? false)
                && $priorCostCurrency !== strtoupper(trim($costCurrency))
                && (string) ($obligationSync['action'] ?? '') === 'updated';

            if ($costCurrencyChanged) {
                $priorAmount = round((float) ($obligationSync['prior_amount'] ?? 0), 2);
                $newAmount = round((float) ($obligationSync['record']['gross_amount'] ?? 0), 2);
                $obligationIdForPosting = $obligationSync['record']['id'] ?? null;

                if ($priorAmount > 0) {
                    $accountingRepository->postPayableAdjusted([
                        'branch_id' => (int) $service['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'source_reference' => (string) $service['line_reference'] . '-CUR-OLD',
                        'service_line_reference' => (string) $service['line_reference'],
                        'supplier_obligation_id' => $obligationIdForPosting,
                        'adjustment_amount' => -$priorAmount,
                        'entry_date' => $entryDate,
                        'currency' => $priorCostCurrency,
                        'narration' => 'Original-currency payable reversed by service financial correction',
                        'actor_user_id' => $actorUserId,
                    ]);
                }

                if ($newAmount > 0) {
                    $accountingRepository->postPayableAdjusted([
                        'branch_id' => (int) $service['branch_id'],
                        'booking_reference' => (string) $booking['booking_reference'],
                        'source_reference' => (string) $service['line_reference'] . '-CUR-NEW',
                        'service_line_reference' => (string) $service['line_reference'],
                        'supplier_obligation_id' => $obligationIdForPosting,
                        'adjustment_amount' => $newAmount,
                        'entry_date' => $entryDate,
                        'currency' => $costCurrency,
                        'narration' => 'New-currency payable recognized by service financial correction',
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            } elseif ((string) ($obligationSync['action'] ?? '') === 'created' && $delta > 0) {
                $accountingRepository->postPayableCreated([
                    'branch_id' => (int) $service['branch_id'],
                    'booking_reference' => (string) $booking['booking_reference'],
                    'service_line_reference' => (string) $service['line_reference'],
                    'supplier_obligation_id' => $obligationSync['record']['id'] ?? null,
                    'gross_amount' => $delta,
                    'entry_date' => $entryDate,
                    'currency' => $costCurrency,
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
                    'currency' => $costCurrency,
                    'actor_user_id' => $actorUserId,
                ]);
            }
        }

        $supplierId = ! empty($service['supplier_id']) ? (int) $service['supplier_id'] : 0;
        $branchId = (int) ($service['branch_id'] ?? 0);
        $currency = $costCurrency;
        $autoAdvanceApplication = null;
        if ($obligationId > 0) {
            $this->supplierAdvanceTraceLog('syncForServiceId', 'auto_apply_start', [
                'trace_id' => $traceId,
                'booking_id' => (int) ($service['booking_id'] ?? 0),
                'booking_service_id' => (int) ($service['id'] ?? 0),
                'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'currency' => $costCurrency,
                'invoice_currency' => $invoiceCurrency,
                'purchase_cost' => $payableAmount,
                'obligation_id' => $obligationId,
                'action' => 'calling_auto_apply',
                'reason' => $obligationAction === 'created' ? 'created_obligation_trigger' : 'updated_obligation_reconcile_trigger',
            ]);

            // This is supplier advance application against supplier payable, not a customer payment.
            $autoAdvanceApplication = $supplierRepository->autoApplyAvailableAdvanceToObligation($obligationId, $actorUserId);
            $appliedAmountDelta = round((float) ($autoAdvanceApplication['applied_amount_delta'] ?? 0), 2);
            $supplierPaymentCreditAppliedAmount = round((float) ($autoAdvanceApplication['supplier_payment_credit_applied_amount'] ?? 0), 2);
            if ($appliedAmountDelta !== 0.0) {
                $updatedObligation = is_array($autoAdvanceApplication['obligation'] ?? null)
                    ? $autoAdvanceApplication['obligation']
                    : $obligationRecord;

                if ($appliedAmountDelta > 0) {
                    $accountingRepository->postSupplierAdvanceApplication([
                        'branch_id' => (int) ($service['branch_id'] ?? 0),
                        'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                        'source_reference' => (string) ($service['line_reference'] ?? ''),
                        'service_line_reference' => (string) ($service['line_reference'] ?? ''),
                        'supplier_obligation_id' => $obligationId,
                        'amount' => $appliedAmountDelta,
                        'entry_date' => $entryDate,
                        'currency' => (string) ($updatedObligation['currency'] ?? $costCurrency),
                        'actor_user_id' => $actorUserId,
                    ]);
                } else {
                    $accountingRepository->postSupplierAdvanceApplicationAdjusted([
                        'branch_id' => (int) ($service['branch_id'] ?? 0),
                        'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                        'source_reference' => (string) ($service['line_reference'] ?? ''),
                        'service_line_reference' => (string) ($service['line_reference'] ?? ''),
                        'supplier_obligation_id' => $obligationId,
                        'adjustment_amount' => $appliedAmountDelta,
                        'entry_date' => $entryDate,
                        'currency' => (string) ($updatedObligation['currency'] ?? $costCurrency),
                        'actor_user_id' => $actorUserId,
                    ]);
                }

                $obligationRecord = $updatedObligation;
                $this->supplierAdvanceTraceLog('syncForServiceId', 'auto_apply_result', [
                    'trace_id' => $traceId,
                    'booking_id' => (int) ($service['booking_id'] ?? 0),
                    'booking_service_id' => (int) ($service['id'] ?? 0),
                    'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                    'currency' => (string) ($updatedObligation['currency'] ?? $costCurrency),
                    'purchase_cost' => round((float) ($updatedObligation['net_payable_amount'] ?? 0), 2),
                    'obligation_id' => $obligationId,
                    'action' => 'advance_reconciled',
                    'applied_amount_delta' => $appliedAmountDelta,
                    'advance_applied_amount' => round((float) ($updatedObligation['advance_applied_amount'] ?? 0), 2),
                    'status' => (string) ($updatedObligation['status'] ?? ''),
                    'reason' => $appliedAmountDelta > 0
                        ? 'supplier_advance_application_posted'
                        : 'supplier_advance_reversal_posted',
                ]);
            }

            if ($supplierPaymentCreditAppliedAmount > 0.005) {
                $updatedObligation = is_array($autoAdvanceApplication['obligation'] ?? null)
                    ? $autoAdvanceApplication['obligation']
                    : $obligationRecord;
                $accountingRepository->postSupplierAdvanceApplication([
                    'branch_id' => (int) ($service['branch_id'] ?? 0),
                    'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                    'source_reference' => (string) ($service['line_reference'] ?? '') . '-PAY-CREDIT',
                    'service_line_reference' => (string) ($service['line_reference'] ?? ''),
                    'supplier_obligation_id' => $obligationId,
                    'amount' => $supplierPaymentCreditAppliedAmount,
                    'entry_date' => $entryDate,
                    'currency' => (string) ($updatedObligation['currency'] ?? $costCurrency),
                    'narration' => 'Existing supplier payment credit applied against corrected payable',
                    'actor_user_id' => $actorUserId,
                ]);
                $obligationRecord = $updatedObligation;
            }
        }

        $eligibilityReason = '';
        $eligibleForFutureDeduction = true;
        if ($supplierId <= 0) {
            $eligibleForFutureDeduction = false;
            $eligibilityReason = 'supplier_id_missing';
        } elseif ($branchId <= 0) {
            $eligibleForFutureDeduction = false;
            $eligibilityReason = 'branch_id_missing';
        } elseif ($currency === '') {
            $eligibleForFutureDeduction = false;
            $eligibilityReason = 'currency_missing';
        } elseif ($payableAmount <= 0) {
            $eligibleForFutureDeduction = false;
            $eligibilityReason = 'purchase_cost_payable_amount_lte_zero';
        } elseif ($obligationId <= 0) {
            $eligibleForFutureDeduction = false;
            $eligibilityReason = 'obligation_id_not_available';
        }

        if (! $eligibleForFutureDeduction) {
            $this->supplierAdvanceTraceLog('syncForServiceId', 'eligibility_skip', [
                'trace_id' => $traceId,
                'booking_id' => (int) ($service['booking_id'] ?? 0),
                'booking_service_id' => (int) ($service['id'] ?? 0),
                'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'currency' => $currency,
                'purchase_cost' => $payableAmount,
                'obligation_id' => $obligationId > 0 ? $obligationId : null,
                'action' => 'eligible_for_future_deduction=false',
                'reason' => $eligibilityReason,
            ]);
        }

        $advanceLookup = null;
        if ($supplierId > 0 && $branchId > 0 && $currency !== '') {
            try {
                $advanceLookup = $supplierRepository->traceAvailableSupplierAdvances($supplierId, $branchId, $currency);
                $this->supplierAdvanceTraceLog('syncForServiceId', 'available_advances_lookup', [
                    'trace_id' => $traceId,
                    'booking_id' => (int) ($service['booking_id'] ?? 0),
                    'booking_service_id' => (int) ($service['id'] ?? 0),
                    'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                    'currency' => $currency,
                    'purchase_cost' => $payableAmount,
                    'obligation_id' => $obligationId > 0 ? $obligationId : null,
                    'action' => 'trace_lookup_completed',
                    'advance_row_count' => (int) ($advanceLookup['advance_row_count'] ?? 0),
                    'matching_advance_total' => round((float) ($advanceLookup['total_available_amount'] ?? 0), 2),
                    'first_advance_id' => (int) ($advanceLookup['first_advance_id'] ?? 0) > 0 ? (int) $advanceLookup['first_advance_id'] : null,
                    'first_advance_available_amount' => isset($advanceLookup['first_advance_available_amount']) ? round((float) $advanceLookup['first_advance_available_amount'], 2) : null,
                    'reason' => 'fifo_order_received_at_asc_id_asc',
                ]);
            } catch (Throwable $exception) {
                $this->supplierAdvanceTraceLog('syncForServiceId', 'trace_error', [
                    'trace_id' => $traceId,
                    'booking_id' => (int) ($service['booking_id'] ?? 0),
                    'booking_service_id' => (int) ($service['id'] ?? 0),
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                    'currency' => $currency,
                    'purchase_cost' => $payableAmount,
                    'obligation_id' => $obligationId > 0 ? $obligationId : null,
                    'action' => 'advance_lookup_failed',
                    'reason' => $exception->getMessage(),
                ]);
            }
        } else {
            $this->supplierAdvanceTraceLog('syncForServiceId', 'available_advances_lookup_skipped', [
                'trace_id' => $traceId,
                'booking_id' => (int) ($service['booking_id'] ?? 0),
                'booking_service_id' => (int) ($service['id'] ?? 0),
                'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'currency' => $currency,
                'purchase_cost' => $payableAmount,
                'obligation_id' => $obligationId > 0 ? $obligationId : null,
                'action' => 'trace_lookup_skipped',
                'reason' => $supplierId <= 0 ? 'supplier_id_missing' : ($branchId <= 0 ? 'branch_id_missing' : 'currency_missing'),
            ]);
        }

        $this->supplierAdvanceTraceLog('syncForServiceId', 'method_presence', [
            'trace_id' => $traceId,
            'booking_id' => (int) ($service['booking_id'] ?? 0),
            'booking_service_id' => (int) ($service['id'] ?? 0),
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'branch_id' => $branchId > 0 ? $branchId : null,
            'currency' => $currency,
            'purchase_cost' => $payableAmount,
            'obligation_id' => $obligationId > 0 ? $obligationId : null,
            'action' => 'reference_only',
            'reason' => 'supplier_application_method=SupplierRepository::autoApplyAvailableAdvanceToObligation; accounting_method=AccountingRepository::postSupplierAdvanceApplication; currency_rule=same_currency_only_for_auto_apply',
        ]);

        $advanceCount = (int) ($advanceLookup['advance_row_count'] ?? 0);
        $advanceTotal = round((float) ($advanceLookup['total_available_amount'] ?? 0), 2);
        if ($eligibleForFutureDeduction && $advanceCount <= 0) {
            $eligibleForFutureDeduction = false;
            $eligibilityReason = 'no_available_advance_rows_found';
        } elseif ($eligibleForFutureDeduction) {
            $eligibilityReason = 'all_future_deduction_requirements_met';
        }

        $this->supplierAdvanceTraceLog('syncForServiceId', 'route_summary', [
            'trace_id' => $traceId,
            'booking_id' => (int) ($service['booking_id'] ?? 0),
            'booking_service_id' => (int) ($service['id'] ?? 0),
            'supplier_name' => (string) ($service['supplier_name_snapshot'] ?? $service['supplier_name'] ?? ''),
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'branch_id' => $branchId > 0 ? $branchId : null,
            'currency' => $currency,
            'purchase_cost' => $payableAmount,
            'obligation_id' => $obligationId > 0 ? $obligationId : null,
            'action' => $obligationAction !== '' ? $obligationAction : 'none',
            'advance_row_count' => $advanceCount,
            'matching_advance_total' => $advanceTotal,
            'eligible_for_future_deduction' => $eligibleForFutureDeduction,
            'reason' => ($autoAdvanceApplication !== null && (float) ($autoAdvanceApplication['applied_amount_delta'] ?? 0) !== 0.0)
                ? 'advance_reconciled_on_current_save'
                : $eligibilityReason,
        ]);

        (new CounterpartyOffsetService($this->app))->autoSettleForFinancialContext(
            (int) ($booking['business_source_id'] ?? 0),
            $supplierId,
            $branchId,
            [$invoiceCurrency, $costCurrency],
            $entryDate,
            $actorUserId
        );

        AuditLog::record($this->app, 'commercial.obligations.synced', [
            'user_id' => $actorUserId,
            'booking_id' => (int) $service['booking_id'],
            'booking_reference' => (string) $booking['booking_reference'],
            'service_id' => (int) $service['id'],
            'service_line_reference' => (string) $service['line_reference'],
            'receivable_amount' => $receivableAmount,
            'payable_amount' => $payableAmount,
        ]);
            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
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

        $purchaseCost = round((float) ($service['purchase_cost'] ?? 0), 2);
        $mktFare = round((float) ($service['sale_price'] ?? 0), 2);

        return $purchaseCost > 0.005 ? $purchaseCost : $mktFare;
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

    private function supplierAdvanceTraceId(): string
    {
        $traceId = (string) ($_SERVER['SUPPLIER_ADVANCE_TRACE_V2_ID'] ?? '');
        if ($traceId !== '') {
            return $traceId;
        }

        $traceId = 'svc-' . date('YmdHis') . '-' . substr((string) microtime(true), -6);
        $_SERVER['SUPPLIER_ADVANCE_TRACE_V2_ID'] = $traceId;

        return $traceId;
    }

    private function supplierAdvanceTraceLog(string $method, string $step, array $context): void
    {
        if (! app_debug_tools_enabled()) {
            return;
        }

        $parts = [
            'timestamp=' . date('Y-m-d H:i:s'),
            'trace_id=' . ($context['trace_id'] ?? $this->supplierAdvanceTraceId()),
            'method=CommercialObligationSyncService::' . $method,
            'step=' . $step,
        ];

        foreach ($context as $key => $value) {
            if ($key === 'trace_id' || $value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $parts[] = $key . '=' . str_replace(["\r", "\n"], [' ', ' '], (string) $value);
        }

        $line = '[SUPPLIER_ADVANCE_TRACE_V2] ' . implode('; ', $parts);
        $this->writeSupplierAdvanceTraceLine($line);
        error_log($line);
    }

    private function writeSupplierAdvanceTraceLine(string $line): void
    {
        if (! app_debug_tools_enabled()) {
            return;
        }

        $logDirectory = dirname(__DIR__, 2) . '/storage/logs';
        $logFile = $logDirectory . '/supplier_advance_trace_v2.log';

        try {
            if (! is_dir($logDirectory) && ! @mkdir($logDirectory, 0777, true) && ! is_dir($logDirectory)) {
                throw new RuntimeException('Unable to create trace log directory.');
            }

            if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to append trace log line.');
            }
        } catch (Throwable $exception) {
            error_log('[SUPPLIER_ADVANCE_TRACE_V2] file_log_error; method=CommercialObligationSyncService::writeSupplierAdvanceTraceLine; message=' . str_replace(["\r", "\n"], [' ', ' '], $exception->getMessage()));
        }
    }
}
