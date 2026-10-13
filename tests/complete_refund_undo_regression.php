<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1);
});
$db = $app->get('db');
$workspace = new \App\Services\ServiceWorkspaceService($app);
$services = new \App\Repositories\BookingServiceRepository($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$customers = new \App\Repositories\CustomerPaymentRepository($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$treasury = new \App\Repositories\TreasuryRepository($app);
$reports = new \App\Repositories\ReportRepository($app);
$accountLedger = new \App\Repositories\AccountLedgerRepository($app);
$reportService = new \App\Services\ReportService($app);
$customerLedgerMethod = new ReflectionMethod($reportService, 'customerLedgerReport');
$today = date('Y-m-d');
final class RecoveryAuditFailurePdo extends PDO
{
    public function __construct(private PDO $inner) {}
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollBack(): bool { return $this->inner->rollBack(); }
    public function exec(string $statement): int|false { return $this->inner->exec($statement); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (str_contains($query, 'VALUES (:event, :actor')) { throw new PDOException('Simulated mandatory recovery audit failure'); }
        return $this->inner->prepare($query,$options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        return $fetchMode === null ? $this->inner->query($query) : $this->inner->query($query,$fetchMode,...$args);
    }
    public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }
    public function getAttribute(int $attribute): mixed { return $this->inner->getAttribute($attribute); }
}
$assert = static function (bool $ok, string $name): void {
    if (! $ok) { throw new RuntimeException('[FAIL] ' . $name); }
    echo '[PASS] ', $name, PHP_EOL;
};
$snapshot = static function () use ($db): array {
    $hashes = [];
    foreach (['bookings','booking_services','booking_service_events','customer_receipts','customer_receipt_allocations','customer_receipt_allocation_movements',
        'customer_receivable_items','supplier_obligations','supplier_payments','supplier_payment_allocations',
        'supplier_advances','supplier_advance_applications','treasury_accounts','treasury_transactions',
        'journal_entries','journal_entry_lines','audit_logs'] as $table) {
        $hashes[$table] = hash('sha256', json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
    }
    return $hashes;
};
$blocked = static function (array $input, array $branches, string $name) use ($workspace, $snapshot, $assert): void {
    $before = $snapshot();
    $failed = false;
    try { $workspace->undoMistakenRefund($input, 1, $branches); }
    catch (RuntimeException $e) { $failed = true; echo '  Guard: ', $e->getMessage(), PHP_EOL; }
    $assert($failed && $before === $snapshot(), $name . ' leaves all records unchanged');
};
$ledger = static function (string $reference) use ($db): array {
    $s = $db->prepare('SELECT c.code,j.currency,ROUND(SUM(l.debit_amount-l.credit_amount),2) balance
        FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id=j.id
        JOIN chart_of_accounts c ON c.id=l.account_id WHERE j.booking_reference=?
        GROUP BY c.code,j.currency HAVING ABS(balance)>0.005 ORDER BY c.code,j.currency');
    $s->execute([$reference]); return $s->fetchAll(PDO::FETCH_ASSOC);
};
$db->beginTransaction();
try {
    $result = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id'=>2,'booking_status'=>'draft','booking_date'=>$today,'party_label'=>'Lead Traveler / Booking Party',
        'lead_traveler_name'=>'Complete Refund Undo Regression','remarks'=>'Rollback-only fixture',
    ],1,[1,2]);
    $bookingId = (int) $result['booking']['id']; $reference = $result['booking']['booking_reference'];
    $serviceInput = ['booking_id'=>$bookingId,'service_type'=>'visa','service_status'=>'Reserved','currency'=>'AED',
        'cost_currency'=>'AED','supplier_name'=>'Undo Supplier '.random_int(100000,999999),
        'sale_price'=>1000,'service_charge'=>100,'final_sale_price'=>1100,
        'visa_country'=>'UAE','visa_type'=>'Visit Visa','remarks'=>'Undo fixture'];
    $result = $workspace->saveService($serviceInput,1,[1,2]);
    $service = $result['service']; $id = (int) $service['id']; $line = $service['line_reference'];
    $db->exec('SAVEPOINT reopened_cross_currency_visibility');
    $db->prepare('UPDATE booking_services SET cost_currency = ?, purchase_cost = ? WHERE id = ?')
        ->execute(['PKR', 77000, $id]);
    $visibleService = $workspace->serviceState($bookingId, [1, 2])['services'][0] ?? [];
    $assert((int) ($visibleService['id'] ?? 0) === $id
        && (string) ($visibleService['currency'] ?? '') === 'AED'
        && (string) ($visibleService['cost_currency'] ?? '') === 'PKR'
        && (float) ($visibleService['purchase_cost'] ?? 0) === 77000.0
        && (float) ($visibleService['final_sale_price'] ?? 0) === 1100.0
        && (int) ($visibleService['latest_cancel_event_id'] ?? -1) === 0
        && ($visibleService['latest_cancel_expected_supplier_refund_conversion_unverified'] ?? null) === false,
        'Cross-currency service without an active cancellation keeps its financial values visible');
    $db->exec('ROLLBACK TO SAVEPOINT reopened_cross_currency_visibility');
    $db->exec('RELEASE SAVEPOINT reopened_cross_currency_visibility');
    (new \App\Services\CustomerReceiptWorkspaceService($app))->saveReceipt([
        'booking_id'=>$bookingId,'receipt_date'=>$today,'receipt_currency'=>'AED','received_amount'=>1100,
        'payment_method'=>'cash','receipt_status'=>'received','receipt_remarks'=>'Original customer payment',
    ],1,[1,2]);
    $cash = $treasury->defaultTreasuryAccountForPayment(2,'AED','cash');
    $supplierBankId = $treasury->saveAccount([
        'branch_id' => 2, 'account_type' => 'bank',
        'account_name' => 'Refund recovery bank ' . strtoupper(bin2hex(random_bytes(4))),
        'currency' => 'AED', 'opening_balance' => 0, 'is_active' => 1,
        'created_by_user_id' => 1,
    ], [1, 2]);
    $obligation = $suppliers->findObligationByServiceLine($reference,$line);
    $paymentNo = $suppliers->nextSupplierPaymentNumber();
    $paymentData = ['supplier_id'=>$service['supplier_id'],'branch_id'=>2,'booking_reference'=>$reference,
        'payment_no'=>$paymentNo,'payment_date'=>$today,'currency'=>'AED','payment_scope'=>'booking',
        'paid_amount'=>1000,'payment_method'=>'cash','treasury_account_id'=>$cash['id'],'charges_amount'=>0,
        'status'=>'paid','actor_user_id'=>1];
    $paymentId = $suppliers->createSupplierPayment($paymentData);
    $accounting->postSupplierPaymentRecorded(array_merge($paymentData,['supplier_payment_id'=>$paymentId,'entry_date'=>$today]));
    $allocationId = $suppliers->allocateSupplierPayment($paymentId,(int)$obligation['id'],1000,1,'Original allocation',1);
    $accounting->postSupplierPaymentAllocation(['branch_id'=>2,'booking_reference'=>$reference,
        'source_reference'=>$paymentNo.'-ALLOC-'.$allocationId,'service_line_reference'=>$line,
        'supplier_obligation_id'=>$obligation['id'],'supplier_payment_id'=>$paymentId,'allocated_amount'=>1000,
        'entry_date'=>$today,'currency'=>'AED','actor_user_id'=>1]);
    $originalLedger = $ledger($reference);
    $originalCash = $treasury->currentBalanceForAccountId((int) $cash['id']);
    $originalSupplierBank = $treasury->currentBalanceForAccountId($supplierBankId);
    $workspace->cancelService(['booking_id'=>$bookingId,'service_id'=>$id,'cancel_reason'=>'Wrong booking selected','cancel_event_date'=>$today],1,[1,2]);
    $workspace->settleCancellationFinancials(['booking_id'=>$bookingId,'service_id'=>$id,'settlement_reason'=>'Mistaken cancellation',
        'settlement_event_date'=>$today,'customer_penalty_amount'=>50,'expected_supplier_refund_amount'=>900],1,[1,2]);
    $db->exec('SAVEPOINT automatic_supplier_credit_undo');
    $workspace->refundService(['booking_id'=>$bookingId,'service_id'=>$id,
        'refund_reason'=>'Mistaken customer payout with automatic supplier credit',
        'refund_event_date'=>$today,'customer_refund_amount'=>175,'customer_refund_treatment'=>'pay_now',
        'supplier_refund_amount'=>0,'refund_payment_method'=>'cash','refund_treasury_account_id'=>$cash['id']],1,[1,2]);
    $automaticCancel = $events->latestPostedEvent($id,'cancel');
    $automaticRefund = $events->latestPostedEvent($id,'refund');
    $workspace->undoMistakenRefund(['booking_id'=>$bookingId,'service_id'=>$id,
        'cancel_event_id'=>$automaticCancel['id'],'undo_last_refund_event_id'=>$automaticRefund['id'],
        'undo_reason'=>'Rollback-only automatic supplier credit undo','undo_mistake_confirmed'=>1],1,[1,2]);
    $automaticAr = $customers->findReceivableByServiceLine($reference,$line);
    $automaticAp = $suppliers->findObligationByServiceLine($reference,$line);
    $assert($ledger($reference)===$originalLedger
        && $treasury->currentBalanceForAccountId((int)$cash['id'])===$originalCash
        && $treasury->currentBalanceForAccountId($supplierBankId)===$originalSupplierBank
        && (float)$automaticAr['outstanding_amount']===0.0
        && (float)$automaticAp['net_payable_amount']===0.0
        && $events->latestPostedEvent($id,'refund')===null
        && $services->findServiceById($id)['service_status']==='Reserved',
        'Current automatic supplier credit and customer payout undo restore original financial positions');
    $db->exec('ROLLBACK TO SAVEPOINT automatic_supplier_credit_undo');
    $db->exec('RELEASE SAVEPOINT automatic_supplier_credit_undo');
    // This fixture exercises an older cash-refund record, not the new
    // credit-by-default cancellation workflow.
    $legacyCancel = $events->latestPostedEvent($id, 'cancel');
    $legacyPayload = json_decode((string) ($legacyCancel['payload_json'] ?? '{}'), true) ?: [];
    $suppliers->reverseRetainedRefundAdvanceConversion(
        (array) ($legacyPayload['supplier_credit_converted_advance_ids'] ?? []),
        1,
        'Rollback-only historical cash-refund fixture'
    );
    foreach ([
        'supplier_credit_recording_version',
        'supplier_credit_recorded_source_amount',
        'supplier_credit_recorded_booking_amount',
        'supplier_credit_recorded_currency',
        'supplier_credit_converted_payment_amount',
        'supplier_credit_converted_advance_ids',
    ] as $legacyField) {
        unset($legacyPayload[$legacyField]);
    }
    $events->updateCancellationFinancials((int) $legacyCancel['id'], [
        'penalty_amount' => $legacyCancel['penalty_amount'],
        'customer_credit_amount' => $legacyCancel['customer_credit_amount'],
        'supplier_credit_amount' => $legacyCancel['supplier_credit_amount'],
        'journal_entry_id' => $legacyCancel['journal_entry_id'],
        'reason' => $legacyCancel['reason'],
        'notes' => $legacyCancel['notes'],
        'payload_json' => $legacyPayload,
    ]);
    foreach ([100,75] as $amount) {
        $workspace->refundService(['booking_id'=>$bookingId,'service_id'=>$id,'refund_reason'=>'Mistaken posted customer refund',
            'refund_event_date'=>$today,'customer_refund_amount'=>$amount,'customer_refund_treatment'=>'pay_now',
            'supplier_refund_amount'=>0,'refund_payment_method'=>'cash','refund_treasury_account_id'=>$cash['id']],1,[1,2]);
    }
    $workspace->refundService(['booking_id'=>$bookingId,'service_id'=>$id,'refund_reason'=>'Mistaken posted supplier receipt',
        'refund_event_date'=>$today,'customer_refund_amount'=>0,'supplier_refund_amount'=>100,
        'supplier_refund_payment_method'=>'bank_transfer','supplier_refund_treasury_account_id'=>$supplierBankId],1,[1,2]);
    $cancel = $events->latestPostedEvent($id,'cancel'); $refund = $events->latestPostedEvent($id,'refund');
    $undo = ['booking_id'=>$bookingId,'service_id'=>$id,'cancel_event_id'=>$cancel['id'],
        'undo_last_refund_event_id'=>$refund['id'],'undo_reason'=>'Wrong booking; no money actually changed hands','undo_mistake_confirmed'=>1];
    $blocked(array_replace($undo,['undo_mistake_confirmed'=>0]),[1,2],'Missing mistake confirmation');
    $blocked($undo,[1],'Cross-branch authorization rejection');
    $blocked(array_replace($undo,['undo_last_refund_event_id'=>0]),[1,2],'Stale refund history');
    $blocked(array_replace($undo,['cancel_event_id'=>1]),[1,2],'Foreign cancellation event');

    $db->exec('SAVEPOINT used_credit_fixture');
    $other = $workspace->saveService(array_merge($serviceInput,['service_status'=>'Open','supplier_name'=>'Undo Other '.random_int(100000,999999)]),1,[1,2])['service'];
    $otherAr = $customers->findReceivableByServiceLine($reference,$other['line_reference']);
    $credit = $customers->availableReceiptCreditsForBooking($reference,'AED')[0];
    $customers->allocateReceiptExplicit(['receipt_id'=>$credit['id'],'receivable_item_id'=>$otherAr['id'],
        'payment_currency'=>'AED','receivable_amount_to_settle'=>50,'payment_amount_to_consume'=>50,
        'rate_from_currency'=>'AED','rate_to_currency'=>'AED','exchange_rate'=>1,'exchange_rate_effective_date'=>$today,
        'allocation_note'=>'Credit already used elsewhere','actor_user_id'=>1]);
    $blocked($undo,[1,2],'Credit already used elsewhere rolls back refund reversals and settlement restoration');
    $db->exec('ROLLBACK TO SAVEPOINT used_credit_fixture'); $db->exec('RELEASE SAVEPOINT used_credit_fixture');

    $recovery = new \App\Repositories\RefundRecoveryRepository($app);
    $db->exec('SAVEPOINT treasury_branch_guard');
    $customerEvent = array_values(array_filter($events->postedEventsForService($id),static fn(array $e):bool => (float)$e['customer_refund_amount']>0.005))[0];
    $foreignCash = $treasury->defaultTreasuryAccountForPayment(1,'PKR','cash');
    $branchPayload = json_decode($customerEvent['payload_json'],true);
    $branchPayload['supplier_refund_detail']['treasury_account_id']=$foreignCash['id'];
    $db->prepare('UPDATE booking_service_events SET payload_json=? WHERE id=?')->execute([json_encode($branchPayload),$customerEvent['id']]);
    $recovery->assertTreasuryBranches($recovery->snapshot($service,$reference),[2]);
    $assert(true,'Unused other-side refund fields do not falsely reject the actual source branch');
    $branchPayload['refund_detail']['treasury_account_id']=$foreignCash['id'];
    $db->prepare('UPDATE booking_service_events SET payload_json=? WHERE id=?')->execute([json_encode($branchPayload),$customerEvent['id']]);
    $blocked($undo,[2],'Actual refund treasury branch is enforced independently of booking branch');
    $db->exec('ROLLBACK TO SAVEPOINT treasury_branch_guard'); $db->exec('RELEASE SAVEPOINT treasury_branch_guard');
    $db->exec('SAVEPOINT exact_receipt_matching');
    $secondReceiptId = $customers->createReceipt(['branch_id'=>2,'booking_reference'=>$reference,
        'receipt_no'=>$customers->nextReceiptNumber(),'receipt_date'=>$today,'currency'=>'AED',
        'received_amount'=>30,'payment_method'=>'cash','status'=>'received','actor_user_id'=>1]);
    $db->prepare('UPDATE customer_receipts SET returned_amount=30,unallocated_amount=0,status="returned" WHERE id=?')->execute([$secondReceiptId]);
    $consumed = $customers->applyRefundAgainstBookingReceiptCredit($reference,'AED',100,1,'Exact matching fixture',false);
    $customers->restoreRefundToBookingReceiptCredit($reference,'AED',100,1,'Exact receipt reversal',false,$consumed['receipt_consumptions']);
    $s=$db->prepare('SELECT returned_amount FROM customer_receipts WHERE id=?');$s->execute([$secondReceiptId]);
    $assert((float)$s->fetchColumn()===30.0,'Refund reversal restores its own receipt and does not take a different receipt refund');
    $db->exec('ROLLBACK TO SAVEPOINT exact_receipt_matching'); $db->exec('RELEASE SAVEPOINT exact_receipt_matching');
    $app->set('db',new RecoveryAuditFailurePdo($db));
    try { $blocked($undo,[1,2],'Mandatory audit failure rolls back financial changes'); }
    finally { $app->set('db',$db); }
    foreach ([['moved','moved'], ['moved','not_moved'], ['not_moved','moved'], ['not_moved','not_moved']] as [$customerMoney,$supplierMoney]) {
        $db->exec('SAVEPOINT recovery_cycle');
        $cancelledLedger = $ledger($reference);
        $cancelledCash = $treasury->currentBalanceForAccountId((int) $cash['id']);
        $originalRows = $recovery->snapshot($service,$reference);
        $workspace->undoMistakenRefund(array_merge($undo,['undo_customer_money'=>$customerMoney,'undo_supplier_money'=>$supplierMoney]),1,[1,2]);
        if ($customerMoney === 'not_moved' && $supplierMoney === 'not_moved') {
            $db->exec('SAVEPOINT reopened_cross_currency_after_undo');
            $db->prepare('UPDATE booking_services SET cost_currency = ?, purchase_cost = ? WHERE id = ?')
                ->execute(['PKR', 77000, $id]);
            $reopenedService = $workspace->serviceState($bookingId, [1, 2])['services'][0] ?? [];
            $assert((int) ($reopenedService['id'] ?? 0) === $id
                && (string) ($reopenedService['service_status'] ?? '') === 'Reserved'
                && (string) ($reopenedService['cost_currency'] ?? '') === 'PKR'
                && (float) ($reopenedService['purchase_cost'] ?? 0) === 77000.0
                && (float) ($reopenedService['final_sale_price'] ?? 0) === 1100.0
                && (int) ($reopenedService['latest_cancel_event_id'] ?? -1) === 0,
                'Reopened cross-currency service remains visible after cancellation undo');
            $db->exec('ROLLBACK TO SAVEPOINT reopened_cross_currency_after_undo');
            $db->exec('RELEASE SAVEPOINT reopened_cross_currency_after_undo');
        }
        $restoredAr = $customers->findReceivableByServiceLine($reference,$line);
        $restoredAp = $suppliers->findObligationByServiceLine($reference,$line);
        $arOutstanding = 0.0;
        $apOutstanding = 0.0;
        $assert((float)$restoredAr['outstanding_amount'] === $arOutstanding && (float)$restoredAp['net_payable_amount'] === $apOutstanding,
            'Recorded refund reversal restores invoice and payable regardless of obsolete money fields '.$customerMoney.'/'.$supplierMoney);
        $assert($treasury->currentBalanceForAccountId((int)$cash['id']) === $originalCash - $arOutstanding + $apOutstanding,
            'Recorded refund reversal restores the original cash balance');
        $assert($treasury->currentBalanceForAccountId($supplierBankId) === $originalSupplierBank,
            'Recorded supplier refund reversal removes money from its original bank account');
        $balances = array_column($ledger($reference),'balance','code');
        $assert((float)($balances['AR_CONTROL']??0) === $arOutstanding && -(float)($balances['AP_CONTROL']??0) === $apOutstanding,
            'Recorded refund reversal reconciles control journals to receivable/payable records');
        $movements = $accountLedger->reportData([1,2],['bookingReference'=>$reference])['refundMovements'];
        $assert(abs(array_sum(array_column($movements,'customer_refund_amount'))-$arOutstanding)<0.005
            && abs(array_sum(array_column($movements,'supplier_refund_amount'))-$apOutstanding)<0.005,
            'Account ledger excludes fully reversed refund movements');
        $supplierRows = $reports->supplierLedger([1,2],null,null,'AED','',(int)$service['supplier_id'],0,$reference);
        $supplierRefundRows = array_filter($supplierRows,static fn(array $r):bool => $r['entry_type']==='Supplier Refund Received');
        $assert(abs(array_sum(array_column($supplierRefundRows,'credit_amount'))-$apOutstanding)<0.005,
            'Supplier ledger excludes the reversed refund receipt');
        $customerRows = $reports->customerLedger([1,2],null,null,'AED',0,'',$reference);
        $assert(abs(array_sum(array_column($customerRows,'preserved_customer_refund_amount'))-$arOutstanding)<0.005
            && array_sum(array_column($customerRows,'refund_event_count')) == 0,
            'Reversed cash is separate from active cancellation entitlement');
        [$customerReportRows] = $customerLedgerMethod->invoke($reportService,$customerRows,['AED'=>77,'PKR'=>1],'customer');
        $customerBalance = array_sum(array_column($customerReportRows,'raw_credit_amount'))
            - array_sum(array_column($customerReportRows,'raw_debit_amount'));
        $assert(abs($customerBalance-$arOutstanding)<0.005,
            'Customer itemized ledger matches restored invoice outstanding');
        $flow = $reports->cashFlow([2],$today,$today);
        $reversedEventIds = array_column($movements,'movement_id');
        $db->exec('SAVEPOINT preserved_cash_report');
        foreach ($reversedEventIds as $eventId) {
            $db->prepare('UPDATE booking_service_events SET payload_json=JSON_REMOVE(payload_json,"$.actual_money_preserved") WHERE id=?')->execute([$eventId]);
        }
        $withoutPreserved = $reports->cashFlow([2],$today,$today);
        $assert(abs(array_sum(array_column($withoutPreserved['receipts'],'cash_in_amount'))
                - array_sum(array_column($flow['receipts'],'cash_in_amount')) - $arOutstanding)<0.005
            && abs(array_sum(array_column($withoutPreserved['supplierPayments'],'cash_out_amount'))
                - array_sum(array_column($flow['supplierPayments'],'cash_out_amount')) - $apOutstanding)<0.005,
            'Cash-flow totals exclude reversed refund movements');
        $db->exec('ROLLBACK TO SAVEPOINT preserved_cash_report'); $db->exec('RELEASE SAVEPOINT preserved_cash_report');
        $history = $recovery->history($id,$bookingId);
        $auditId = (int)$history[0]['id'];
        $assert(($history[0]['reversal_mode'] ?? '') === 'recorded_transactions'
            && ($history[0]['customer_money'] ?? null) === null
            && ($history[0]['supplier_money'] ?? null) === null,
            'Complete undo audit records automatic reversal without operator money choices');
        $restore = ['booking_id'=>$bookingId,'service_id'=>$id,'reversal_audit_id'=>$auditId,
            'restore_reason'=>'The full reversal was selected by mistake','restore_confirmed'=>1];
        $db->exec('SAVEPOINT later_change');
        $db->prepare('UPDATE booking_services SET remarks=? WHERE id=?')->execute(['Later edit must not be lost',$id]);
        $later = $snapshot(); $failed = false;
        try { $workspace->restoreReversedRefund($restore,1,[1,2]); } catch (RuntimeException $error) { $failed = true; }
        $assert($failed && $later === $snapshot(),'Restoration refuses later edits without changing any records');
        $db->exec('ROLLBACK TO SAVEPOINT later_change'); $db->exec('RELEASE SAVEPOINT later_change');
        if ($customerMoney==='moved' && $supplierMoney==='moved') {
            foreach ([[array_replace($restore,['restore_confirmed'=>0]),[1,2]],[$restore,[1]],
                [array_replace($restore,['reversal_audit_id'=>0]),[1,2]]] as [$badRestore,$branchScope]) {
                $unchanged = $snapshot(); $failed = false;
                try { $workspace->restoreReversedRefund($badRestore,1,$branchScope); } catch (RuntimeException $error) { $failed = true; }
                $assert($failed && $unchanged===$snapshot(),'Restoration confirms scope, audit ownership and user confirmation');
            }
            $app->set('db',new RecoveryAuditFailurePdo($db));
            $unchanged = $snapshot(); $failed = false;
            try { $workspace->restoreReversedRefund($restore,1,[1,2]); } catch (RuntimeException $error) { $failed = true; }
            finally { $app->set('db',$db); }
            $assert($failed && $unchanged===$snapshot(),'Mandatory restoration audit failure rolls back snapshots and journals');
        }
        $workspace->restoreReversedRefund($restore,1,[1,2]);
        $restoredRows = $recovery->snapshot($service,$reference);
        unset($originalRows['journal_entries'],$originalRows['journal_entry_lines'],$restoredRows['journal_entries'],$restoredRows['journal_entry_lines']);
        $assert($originalRows === $restoredRows,'Restore reverses the entire operation including original allocation IDs and event states');
        $restoredReport = $accountLedger->reportData([1,2],['bookingReference'=>$reference])['refundMovements'];
        $assert(array_sum(array_column($restoredReport,'customer_refund_amount'))===175.0
            && array_sum(array_column($restoredReport,'supplier_refund_amount'))===100.0,
            'Restore reports original posted refunds once without preserved-money duplication');
        $assert($cancelledLedger === $ledger($reference) && $cancelledCash === $treasury->currentBalanceForAccountId((int)$cash['id']),
            'Restoration appends balanced compensating journals and returns to the exact cancelled ledger/cash state');
        $afterRestore = $snapshot(); $workspace->restoreReversedRefund($restore,1,[1,2]);
        $assert($afterRestore === $snapshot(),'Repeated restoration is idempotent');
        $workspace->undoMistakenRefund(array_merge($undo,['undo_customer_money'=>$customerMoney,'undo_supplier_money'=>$supplierMoney]),1,[1,2]);
        $secondAudit = (int)$recovery->history($id,$bookingId)[0]['id'];
        $workspace->restoreReversedRefund(array_replace($restore,['reversal_audit_id'=>$secondAudit]),1,[1,2]);
        $assert($secondAudit!==$auditId && $cancelledLedger===$ledger($reference),'Second recovery cycle uses a new audit reference without duplicate financial effects');
        $db->exec('ROLLBACK TO SAVEPOINT recovery_cycle'); $db->exec('RELEASE SAVEPOINT recovery_cycle');
    }

    $db->exec('SAVEPOINT legacy_basis');
    $legacyPayload = json_decode($cancel['payload_json'],true); unset($legacyPayload['prior_customer_due_amount']);
    $db->prepare('UPDATE booking_service_events SET payload_json=? WHERE id=?')->execute([json_encode($legacyPayload),$cancel['id']]);
    $blocked($undo,[1,2],'Legacy missing original invoice basis produces safe review error rather than due zero');
    $db->exec('ROLLBACK TO SAVEPOINT legacy_basis'); $db->exec('RELEASE SAVEPOINT legacy_basis');

    $workspace->undoMistakenRefund($undo,1,[1,2]);
    $assert($services->findServiceById($id)['service_status']==='Reserved','Original service status restored');
    $ar = $customers->findReceivableByServiceLine($reference,$line);
    $ap = $suppliers->findObligationByServiceLine($reference,$line);
    $assert((float)$ar['due_amount']===1100.0 && (float)$ar['allocated_amount']===1100.0 && (float)$ar['outstanding_amount']===0.0,'Original invoice and customer payment allocations restored');
    $history = $db->prepare('SELECT action, receivable_amount_before, receivable_amount_after, payment_amount_before, payment_amount_after
        FROM customer_receipt_allocation_movements WHERE customer_receivable_item_id=? ORDER BY id');
    $history->execute([(int)$ar['id']]);
    $movements = $history->fetchAll(PDO::FETCH_ASSOC);
    $releasedMovements = array_values(array_filter($movements, static fn (array $row): bool => $row['action'] === 'released'));
    $lastMovement = $movements[count($movements) - 1] ?? [];
    $assert(count($movements) === 3
        && count($releasedMovements) === 1
        && (float)$releasedMovements[0]['receivable_amount_before'] === 1100.0
        && (float)$releasedMovements[0]['receivable_amount_after'] === 250.0
        && ($lastMovement['action'] ?? '') === 'allocated'
        && (float)($lastMovement['payment_amount_after'] ?? 0) === 850.0,
        'Customer receipt allocation release and restoration retain immutable movement history');
    $assert((float)$ap['gross_amount']===1000.0 && (float)$ap['net_payable_amount']===0.0,'Original supplier cost and payment allocation restored');
    $assert($events->latestPostedEvent($id,'cancel')===null && $events->latestPostedEvent($id,'refund')===null,'All mistaken cancellation and partial customer/supplier refund events reversed');
    $assert($originalLedger===$ledger($reference),'Original booking ledger balances restored with reversal history');
    $assert($originalCash===$treasury->currentBalanceForAccountId((int) $cash['id']),'Recorded cash balance returns to pre-mistake value');
    $assert($originalSupplierBank===$treasury->currentBalanceForAccountId($supplierBankId),'Recorded supplier bank balance returns to pre-mistake value');
    $after = $snapshot(); $workspace->undoMistakenRefund($undo,1,[1,2]);
    $assert($after===$snapshot(),'Repeated submit makes no additional entries or audit events');

    $unpaid = $workspace->saveService(array_merge($serviceInput,['service_status'=>'Open','supplier_name'=>'Undo Unpaid '.random_int(100000,999999)]),1,[1,2])['service'];
    $unpaidId = (int)$unpaid['id'];
    $workspace->cancelService(['booking_id'=>$bookingId,'service_id'=>$unpaidId,'cancel_reason'=>'Unpaid wrong booking','cancel_event_date'=>$today],1,[1,2]);
    $operationalCancel = $events->latestPostedEvent($unpaidId,'cancel');
    $workspace->undoMistakenRefund(['booking_id'=>$bookingId,'service_id'=>$unpaidId,'cancel_event_id'=>$operationalCancel['id'],
        'undo_last_refund_event_id'=>0,'undo_reason'=>'Operational cancellation entered by mistake','undo_mistake_confirmed'=>1],1,[1,2]);
    $assert($services->findServiceById($unpaidId)['service_status']==='Open','Operational-only mistaken cancellation undoes in one action');
    $workspace->cancelService(['booking_id'=>$bookingId,'service_id'=>$unpaidId,'cancel_reason'=>'Unpaid settled mistake','cancel_event_date'=>$today],1,[1,2]);
    $workspace->settleCancellationFinancials(['booking_id'=>$bookingId,'service_id'=>$unpaidId,'settlement_reason'=>'Unpaid cancellation mistake',
        'settlement_event_date'=>$today,'customer_penalty_amount'=>50,'expected_supplier_refund_amount'=>900],1,[1,2]);
    $unpaidCancel = $events->latestPostedEvent($unpaidId,'cancel');
    $workspace->undoMistakenRefund(['booking_id'=>$bookingId,'service_id'=>$unpaidId,'cancel_event_id'=>$unpaidCancel['id'],
        'undo_last_refund_event_id'=>0,'undo_reason'=>'No customer refund was paid; mistaken booking','undo_mistake_confirmed'=>1],1,[1,2]);
    $unpaidAr = $customers->findReceivableByServiceLine($reference,$unpaid['line_reference']);
    $assert((float)$unpaidAr['due_amount']===1100.0 && (float)$unpaidAr['allocated_amount']===0.0
        && $services->findServiceById($unpaidId)['service_status']==='Open','Unpaid settled cancellation restores original outstanding invoice without inventing payments');
    echo 'Complete refund undo regression passed. All fixture data rolled back.',PHP_EOL;
} finally { if ($db->inTransaction()) { $db->rollBack(); } }
