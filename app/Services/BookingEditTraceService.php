<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\BookingRepository;
use App\Repositories\BookingEditTraceRepository;
use Throwable;

final class BookingEditTraceService
{
    private static ?self $active = null;
    private array $steps = [];
    private array $before = [];
    private string $traceId;
    private string $groupId;
    private bool $finished = false;

    private function __construct(
        private readonly App $app,
        private readonly string $action,
        private readonly int $actorId,
        private readonly int $bookingId,
        private readonly array $accessibleBranchIds,
        array $input
    ) {
        $this->traceId = bin2hex(random_bytes(12));
        $group = trim((string) ($input['edit_trace_group_id'] ?? ''));
        $this->groupId = preg_match('/^[a-zA-Z0-9-]{12,80}$/', $group) === 1 ? $group : $this->traceId;
        $this->before = $this->snapshot($bookingId);
        $this->append('started', [
            'submitted' => self::submittedFields($input),
            'before' => $this->before,
        ]);
    }

    public static function begin(App $app, string $action, array $input, int $actorId, array $accessibleBranchIds): self
    {
        self::$active = new self($app, $action, $actorId, (int) ($input['booking_id'] ?? 0), $accessibleBranchIds, $input);

        return self::$active;
    }

    public static function checkpoint(string $service, string $result, array $details = []): void
    {
        if (self::$active === null) {
            return;
        }

        self::$active->steps[] = [
            'service' => $service,
            'result' => $result,
            'details' => $details,
        ];
    }

    public static function observeBrowserState(App $app, array $input, int $actorId, array $accessibleBranchIds): bool
    {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $stage = (string) ($input['stage'] ?? '');
        if ($bookingId <= 0 || ! in_array($stage, [
            'service_loaded', 'service_currency_changed', 'refund_field_changed', 'refund_submitted',
            'refund_form_opened', 'edit_booking_opened', 'invoice_edit_field_changed', 'invoice_edit_submitted',
        ], true)) {
            return false;
        }
        $repository = new BookingRepository($app);
        if (! $repository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
            return false;
        }
        $booking = $repository->findBookingById($bookingId);
        if ($booking === null) {
            return false;
        }

        $group = trim((string) ($input['group_id'] ?? ''));
        $traceId = bin2hex(random_bytes(12));
        $state = is_array($input['state'] ?? null) ? $input['state'] : [];
        $observed = self::submittedFields($state);
        $observed['booking_id'] = (string) $bookingId;
        foreach ($observed as $key => $value) {
            $observed[$key] = substr($value, 0, 80);
        }
        $changedField = (string) ($input['field'] ?? '');
        if ($changedField !== '' && array_key_exists($changedField, $observed)) {
            $observed['changed_field'] = $changedField;
        }
        self::writeRecord([
            'version' => 1,
            'time' => date('c'),
            'trace_id' => $traceId,
            'group_id' => preg_match('/^[a-zA-Z0-9-]{12,80}$/', $group) === 1 ? $group : $traceId,
            'request_id' => app_request_id(),
            'action' => 'workspace.browser.' . $stage,
            'status' => 'observed_unsaved',
            'actor_user_id' => $actorId,
            'booking_id' => $bookingId,
            'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'payload' => [
                'observed' => $observed,
                'note' => 'Browser state only; no financial posting is implied.',
            ],
        ]);
        return true;
    }

    public function finish(string $status, ?int $savedBookingId = null, ?Throwable $error = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $after = $this->snapshot($savedBookingId ?: $this->bookingId);
        $changes = self::changes($this->before, $after);
        if ($changes !== [] && in_array($status, ['rejected', 'failed'], true)) {
            $status = 'partial_commit';
        }
        $this->append($status, [
            'steps' => $this->steps,
            'after' => $after,
            'changes' => $changes,
            'error' => $error?->getMessage(),
        ], $savedBookingId ?: $this->bookingId);
        if (self::$active === $this) {
            self::$active = null;
        }
    }

    private function snapshot(int $bookingId): array
    {
        try {
            if ($bookingId <= 0 || ! (new BookingRepository($this->app))->bookingExistsInBranches($bookingId, $this->accessibleBranchIds)) {
                return [];
            }
            return (new BookingEditTraceRepository($this->app))->snapshot($bookingId);
        } catch (Throwable $error) {
            return ['snapshot_error' => $error->getMessage()];
        }
    }

    private function append(string $status, array $payload, ?int $bookingId = null): void
    {
        $record = [
            'version' => 1,
            'time' => date('c'),
            'trace_id' => $this->traceId,
            'group_id' => $this->groupId,
            'request_id' => app_request_id(),
            'action' => $this->action,
            'status' => $status,
            'actor_user_id' => $this->actorId,
            'booking_id' => $bookingId ?? $this->bookingId,
            'booking_reference' => (string) ($payload['after']['booking']['booking_reference']
                ?? $this->before['booking']['booking_reference'] ?? ''),
            'payload' => $payload,
        ];
        self::writeRecord($record);
    }

    private static function writeRecord(array $record): void
    {
        $path = app_log_path('booking-edit-trace-' . date('Ymd') . '.jsonl');
        try {
            $directory = dirname($path);
            if (! is_dir($directory)) {
                @mkdir($directory, 0770, true);
            }
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Diagnostic trace file is not writable.');
            }
        } catch (Throwable $error) {
            app_write_log('booking_edit_trace.write_failed', $error->getMessage(), [
                'trace_id' => $record['trace_id'] ?? null,
                'action' => $record['action'] ?? null,
            ]);
        }
    }

    private static function submittedFields(array $input): array
    {
        $allowed = [
            'booking_id', 'service_id', 'branch_id', 'auto_branch_id', 'booking_status',
            'business_source_id', 'selected_customer_id', 'booking_date', 'due_date',
            'currency', 'cost_currency', 'service_charge_currency', 'sale_price', 'purchase_cost',
            'service_charge', 'discount_amount', 'final_sale_price', 'pricing_exchange_rate',
            'service_charge_exchange_rate', 'corrected_invoice_currency', 'corrected_cost_currency',
            'corrected_service_charge_currency', 'corrected_cost_basis', 'corrected_service_charge',
            'corrected_discount_amount', 'corrected_final_sale_price',
            'corrected_pricing_exchange_rate', 'corrected_aed_to_pkr_exchange_rate',
            'corrected_service_charge_exchange_rate',
            'supplier_advance_fx_use', 'supplier_advance_fx_advance_id',
            'supplier_advance_fx_rate', 'supplier_advance_fx_rate_date',
            'cancel_event_date', 'refund_event_date', 'correction_event_date', 'settlement_event_date',
            'settlement_currency', 'settlement_exchange_rate', 'settlement_edit_mode',
            'customer_penalty_amount', 'customer_penalty_currency', 'customer_penalty_source_amount',
            'customer_penalty_exchange_rate', 'agency_fee_refund_amount', 'agency_fee_refund_currency',
            'expected_supplier_refund_amount', 'expected_supplier_refund_currency',
            'expected_supplier_refund_source_amount', 'expected_supplier_refund_exchange_rate',
            'expected_supplier_refund_equivalent_amount', 'expected_supplier_refund_rate_date',
            'supplier_penalty_amount', 'supplier_penalty_currency', 'supplier_penalty_source_amount',
            'supplier_penalty_exchange_rate', 'customer_refund_amount', 'customer_refund_currency',
            'customer_refund_source_amount', 'customer_refund_exchange_rate',
            'supplier_refund_amount', 'supplier_refund_currency', 'supplier_refund_source_amount',
            'supplier_refund_exchange_rate', 'refund_edit_scope', 'refund_payment_method',
            'supplier_refund_payment_method', 'refund_treasury_account_id',
            'supplier_refund_treasury_account_id', 'supplier_refund_amount_basis',
            'supplier_refund_conversion_mode', 'customer_refund_treatment',
            'refund_event_id', 'service_event_id',
            'receipt_action', 'receipt_scope', 'receipt_currency', 'received_amount',
            'payment_method', 'treasury_account_id', 'customer_credit_receipt_id',
            'customer_credit_apply_amount', 'advance_receipt_id', 'advance_apply_amount',
        ];
        $result = [];
        foreach ($allowed as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                $result[$key] = (string) $input[$key];
            }
        }

        return $result;
    }

    private static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (['booking', 'services', 'receivables', 'service_events', 'refund_details', 'supplier_advances', 'customer_receipts', 'receipt_allocations',
            'supplier_payments', 'supplier_obligations', 'supplier_allocations', 'supplier_advance_applications',
            'journals', 'journal_lines', 'corrections'] as $section) {
            if (($before[$section] ?? null) !== ($after[$section] ?? null)) {
                $changes[] = $section;
            }
        }

        return $changes;
    }
}
