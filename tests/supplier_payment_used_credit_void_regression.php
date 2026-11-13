<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\AccountingRepository;
use App\Repositories\ReportRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TreasuryRepository;
use App\Services\SupplierLedgerService;
use App\Services\SupplierCancellationCreditVoidService;
use App\Services\SupplierPaymentVoidImpactService;
use App\Services\SupplierSettlementWorkspaceService;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$previewService = new SupplierPaymentVoidImpactService($app);
$voidService = new SupplierSettlementWorkspaceService($app);
$treasury = new TreasuryRepository($app);
$adminId = (int) $db->query(
    'SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code = "super_admin" ORDER BY u.id LIMIT 1'
)->fetchColumn();
$assert($adminId > 0, 'An active financial administrator exists for rollback-only tests.');

$customerTotals = static function () use ($db): array {
    return $db->query(
        'SELECT branch_id, currency, COUNT(*) AS row_count,
                COALESCE(SUM(due_amount), 0) AS due,
                COALESCE(SUM(outstanding_amount), 0) AS outstanding
         FROM customer_receivable_items GROUP BY branch_id, currency ORDER BY branch_id, currency'
    )->fetchAll(PDO::FETCH_ASSOC);
};
$supplierLedgerBalance = static function (int $supplierId, string $currency) use ($app): array {
    $report = (new SupplierLedgerService($app))->report([
        'supplierId' => $supplierId,
        'currency' => $currency,
    ], [1, 2]);
    $rows = $report['rows'];
    return [
        'row_count' => count($rows),
        'ending_balance' => $rows === [] ? 0.0 : (float) $rows[array_key_last($rows)]['raw_balance_amount'],
    ];
};
$obligationPayables = static function (array $ids) use ($db): array {
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = $db->prepare(
        "SELECT id, net_payable_amount FROM supplier_obligations WHERE id IN ({$placeholders}) ORDER BY id"
    );
    $statement->execute($ids);
    $result = [];
    foreach ($statement as $row) {
        $result[(int) $row['id']] = (float) $row['net_payable_amount'];
    }
    return $result;
};

foreach (['SPAY-000263', 'SPAY-000268'] as $paymentNo) {
    $statement = $db->prepare('SELECT id, status FROM supplier_payments WHERE payment_no = :payment_no');
    $statement->execute(['payment_no' => $paymentNo]);
    $payment = $statement->fetch(PDO::FETCH_ASSOC);
    $paymentId = (int) ($payment['id'] ?? 0);
    if ($paymentId <= 0) {
        echo '[SKIP] ', $paymentNo, ' is not present in this database copy.', PHP_EOL;
        continue;
    }
    if ((string) ($payment['status'] ?? '') === 'void') {
        echo '[SKIP] ', $paymentNo, ' was already voided in this database copy.', PHP_EOL;
        continue;
    }

    $db->beginTransaction();
    try {
        $impact = $previewService->preview($paymentId, [1, 2]);
        $assert($impact['advance_applications'] !== [], $paymentNo . ' has used credit to exercise.');
        $expectedReopen = [];
        foreach ($impact['direct_allocations'] as $row) {
            $id = (int) $row['obligation_id'];
            $expectedReopen[$id] = ($expectedReopen[$id] ?? 0) + (float) $row['amount'];
        }
        foreach ($impact['advance_applications'] as $row) {
            $id = (int) $row['obligation_id'];
            $expectedReopen[$id] = ($expectedReopen[$id] ?? 0) + (float) $row['obligation_amount'];
        }
        $beforePayables = $obligationPayables(array_keys($expectedReopen));
        $beforeCustomer = $customerTotals();
        $beforeSupplierLedger = $supplierLedgerBalance(
            (int) $impact['payment']['supplier_id'],
            (string) $impact['payment']['currency']
        );
        $treasuryId = (int) ($impact['payment']['treasury_account_id'] ?? 0);
        $beforeCash = $treasuryId > 0 ? $treasury->currentBalanceForAccountId($treasuryId) : null;
        $journalWatermark = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => $paymentId,
                'void_reason' => 'Regression duplicate payment void',
            ], $adminId, [1, 2]);
            throw new RuntimeException('Used credit was voided without confirmation.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'Review & Void'),
                $paymentNo . ' rejects void without explicit credit-use confirmation.');
        }
        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => $paymentId,
                'void_reason' => 'Regression duplicate payment void',
                'confirm_reopen_payables' => '1',
                'impact_token' => 'stale',
            ], $adminId, [1, 2]);
            throw new RuntimeException('Used credit was voided with a stale preview.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'Review & Void'),
                $paymentNo . ' rejects a stale impact token.');
        }

        $result = $voidService->voidGlobalSupplierPayment([
            'supplier_payment_id' => $paymentId,
            'void_reason' => 'Regression duplicate payment void',
            'confirm_reopen_payables' => '1',
            'impact_token' => $impact['impact_token'],
        ], $adminId, [1, 2]);
        $assert((string) $result['payment_no'] === $paymentNo,
            $paymentNo . ' void posts successfully after explicit confirmation.');

        $afterPayables = $obligationPayables(array_keys($expectedReopen));
        foreach ($expectedReopen as $obligationId => $amount) {
            $assert(abs(($afterPayables[$obligationId] ?? 0) - ($beforePayables[$obligationId] ?? 0) - $amount) <= 0.01,
                $paymentNo . ' reopens only the expected amount on supplier obligation ' . $obligationId . '.');
        }
        $assert($customerTotals() === $beforeCustomer,
            $paymentNo . ' leaves customer invoices and outstanding balances unchanged by branch and currency.');
        $afterSupplierLedger = $supplierLedgerBalance(
            (int) $impact['payment']['supplier_id'],
            (string) $impact['payment']['currency']
        );
        $assert(abs($afterSupplierLedger['ending_balance'] - $beforeSupplierLedger['ending_balance']
            - (float) $impact['payment']['paid_amount']) <= 0.01,
            $paymentNo . ' supplier ledger ending balance rises only by the voided payment.');
        if ($beforeCash !== null) {
            $afterCash = $treasury->currentBalanceForAccountId($treasuryId);
            $expectedCash = (float) $impact['payment']['paid_amount'] + (float) $impact['payment']['charges_amount'];
            $assert(abs($afterCash - $beforeCash - $expectedCash) <= 0.01,
                $paymentNo . ' restores exactly its original treasury outflow.');
        }
        foreach ($impact['linked_advances'] as $advance) {
            $statement = $db->prepare('SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = ?');
            $statement->execute([(int) $advance['id']]);
            $after = $statement->fetch(PDO::FETCH_ASSOC);
            $assert((float) $after['deposit_amount'] === 0.0 && (float) $after['available_amount'] === 0.0,
                $paymentNo . ' neutralizes linked credit ' . $advance['id'] . '.');
        }
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM supplier_advance_applications aa
             INNER JOIN supplier_advances sa ON sa.id = aa.supplier_advance_id
             WHERE sa.source_supplier_payment_id = ?'
        );
        $statement->execute([$paymentId]);
        $assert((int) $statement->fetchColumn() === 0,
            $paymentNo . ' removes active uses of its voided credit while retaining audit evidence.');

        $statement = $db->prepare(
            'SELECT je.branch_id, je.currency,
                    ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS difference
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE je.id > :watermark
             GROUP BY je.branch_id, je.currency'
        );
        $statement->execute(['watermark' => $journalWatermark]);
        foreach ($statement as $row) {
            $assert(abs((float) $row['difference']) <= 0.01,
                $paymentNo . ' reversal journals balance in branch ' . $row['branch_id'] . ' / ' . $row['currency'] . '.');
        }
        $statement = $db->prepare(
            'SELECT ROUND(SUM(jel.credit_amount - jel.debit_amount), 2)
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id > :watermark AND coa.code = "AP_CONTROL"'
        );
        $statement->execute(['watermark' => $journalWatermark]);
        $payableJournalIncrease = (float) $statement->fetchColumn();
        $assert(abs($payableJournalIncrease - array_sum($expectedReopen)) <= 0.01,
            $paymentNo . ' payable-control journal movement matches reopened supplier payables.');

        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => $paymentId,
                'void_reason' => 'Duplicate repeat attempt',
                'confirm_reopen_payables' => '1',
                'impact_token' => $impact['impact_token'],
            ], $adminId, [1, 2]);
            throw new RuntimeException('A second void was accepted.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'already void'),
                $paymentNo . ' cannot post a second void.');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$cancellationSource = $db->query(
    'SELECT p.id FROM supplier_payments p
     INNER JOIN supplier_advances sa ON sa.source_supplier_payment_id = p.id
     WHERE p.payment_no = "SPAY-000232" AND sa.reference_no LIKE "CANCEL-CREDIT-%"
     LIMIT 1'
)->fetchColumn();
if ($cancellationSource !== false) {
    $db->beginTransaction();
    try {
        $impact = $previewService->preview((int) $cancellationSource, [1, 2]);
        $assert($impact['cancellation_credit_ids'] !== [],
            'SPAY-000232 preview identifies both cancellation credits.');
        $customerBefore = $customerTotals();
        $reportRepository = new ReportRepository($app);
        $supplierReceivableBefore = $reportRepository->supplierReceivable([1, 2], null, null, '', 349);
        $payableRefundsBefore = $reportRepository->payableRefunds([1, 2], null, null, 349);
        $ledgerBefore = $supplierLedgerBalance((int) $impact['payment']['supplier_id'], 'PKR');
        $bookingLedger = static function (string $bookingReference) use ($app): array {
            $report = (new SupplierLedgerService($app))->report([
                'supplierId' => 349,
                'currency' => 'PKR',
                'bookingReference' => $bookingReference,
            ], [1, 2]);
            $rows = $report['rows'];
            return [
                'balance' => $rows === [] ? 0.0 : (float) $rows[array_key_last($rows)]['raw_balance_amount'],
                'types' => array_column($rows, 'source_entry_type'),
            ];
        };
        $cancelledBookingBefore = [
            'BK-001030' => $bookingLedger('BK-001030'),
            'BK-001032' => $bookingLedger('BK-001032'),
        ];
        $cashBefore = $treasury->currentBalanceForAccountId((int) $impact['payment']['treasury_account_id']);
        $cancellationBefore = $db->query(
            'SELECT id, penalty_amount, customer_credit_amount, payload_json
             FROM booking_service_events WHERE id IN (69, 71) ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $otherPaymentBefore = $db->query(
            'SELECT paid_amount, allocated_amount, unallocated_amount, status
             FROM supplier_payments WHERE payment_no = "SPAY-000235"'
        )->fetch(PDO::FETCH_ASSOC);
        $otherCreditBefore = $db->query(
            'SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = 63'
        )->fetch(PDO::FETCH_ASSOC);
        $expectedReopen = [];
        foreach ($impact['direct_allocations'] as $row) {
            $id = (int) $row['obligation_id'];
            $expectedReopen[$id] = ($expectedReopen[$id] ?? 0) + (float) $row['amount'];
        }
        foreach ($impact['advance_applications'] as $row) {
            $id = (int) $row['obligation_id'];
            $expectedReopen[$id] = ($expectedReopen[$id] ?? 0) + (float) $row['obligation_amount'];
        }
        $payablesBefore = $obligationPayables(array_keys($expectedReopen));
        $journalWatermark = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => (int) $cancellationSource,
                'void_reason' => 'Rollback-only cancellation credit safety check',
                'confirm_reopen_payables' => '1',
                'impact_token' => $impact['impact_token'],
            ], $adminId, [1, 2]);
            throw new RuntimeException('Cancellation credit was voided without confirmation.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'confirm removal'),
                'SPAY-000232 refuses to remove cancellation credit without explicit confirmation.');
        }
        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => (int) $cancellationSource,
                'void_reason' => 'Rollback-only invalid equivalent check',
                'confirm_reopen_payables' => '1',
                'confirm_remove_cancellation_credit' => '1',
                'impact_token' => $impact['impact_token'],
                'cancellation_credit_equivalent' => [69 => '2885', 71 => '117'],
            ], $adminId, [1, 2]);
            throw new RuntimeException('Another payment share was removed from the shared cancellation.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'preserve the other payment'),
                'SPAY-000232 refuses an AED reduction that would erase SPAY-000235 credit.');
        }
        $postedRefundImpact = $impact;
        $postedRefundImpact['cancellation_events'][0]['has_posted_supplier_refund'] = true;
        try {
            (new SupplierCancellationCreditVoidService($app))->prepare($postedRefundImpact, [
                'confirm_remove_cancellation_credit' => '1',
                'cancellation_credit_equivalent' => [69 => '2885', 71 => '25'],
            ], [1, 2]);
            throw new RuntimeException('A separate posted supplier refund was ignored.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'posted supplier refund'),
                'A separately posted supplier refund stops cancellation-credit removal for review.');
        }
        $result = $voidService->voidGlobalSupplierPayment([
            'supplier_payment_id' => (int) $cancellationSource,
            'void_reason' => 'Rollback-only duplicate payment test',
            'confirm_reopen_payables' => '1',
            'confirm_remove_cancellation_credit' => '1',
            'impact_token' => $impact['impact_token'],
            'cancellation_credit_equivalent' => [69 => '2885', 71 => '25'],
        ], $adminId, [1, 2]);
        $assert((string) $result['payment_no'] === 'SPAY-000232',
            'SPAY-000232 void posts with an explicitly entered test-only AED 25 shared-event reduction.');
        foreach ($expectedReopen as $obligationId => $amount) {
            $after = $obligationPayables([$obligationId]);
            $assert(abs(($after[$obligationId] ?? 0) - ($payablesBefore[$obligationId] ?? 0) - $amount) <= 0.01,
                'SPAY-000232 reopens the exact payable on obligation ' . $obligationId . '.');
        }
        $assert($customerTotals() === $customerBefore,
            'SPAY-000232 void leaves every customer invoice unchanged by branch and currency.');
        $assert($reportRepository->supplierReceivable([1, 2], null, null, '', 349) === $supplierReceivableBefore
            && $reportRepository->payableRefunds([1, 2], null, null, 349) === $payableRefundsBefore,
            'Supplier receivable and payable-refund reports gain no phantom balance from the void.');
        $assert(abs($treasury->currentBalanceForAccountId((int) $impact['payment']['treasury_account_id'])
            - $cashBefore - 250000.0) <= 0.01,
            'SPAY-000232 void restores only its PKR 250,000 cash outflow.');
        $ledgerAfter = $supplierLedgerBalance((int) $impact['payment']['supplier_id'], 'PKR');
        $assert(abs($ledgerAfter['ending_balance'] - $ledgerBefore['ending_balance'] - 250000.0) <= 0.01,
            'Supplier full-ledger ending balance changes by exactly the voided PKR payment.');
        foreach ($cancelledBookingBefore as $bookingReference => $beforeBookingLedger) {
            $afterBookingLedger = $bookingLedger($bookingReference);
            $assert(abs($afterBookingLedger['balance'] - $beforeBookingLedger['balance']) <= 0.01
                && in_array('Supplier Cancellation Credit Removed', $afterBookingLedger['types'], true),
                $bookingReference . ' booking ledger stays balanced and shows its credit-removal row.');
        }
        $events = $db->query(
            'SELECT id, supplier_credit_amount, payload_json FROM booking_service_events
             WHERE id IN (69, 71) ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $event69 = json_decode((string) $events[0]['payload_json'], true);
        $event71 = json_decode((string) $events[1]['payload_json'], true);
        $assert(abs((float) $events[0]['supplier_credit_amount']) <= 0.01
            && abs((float) $event69['supplier_credit_recorded_source_amount']) <= 0.01
            && abs((float) $event69['expected_supplier_refund_source_amount']) <= 0.01
            && abs((float) $event69['expected_supplier_refund_amount']) <= 0.01,
            'Fully duplicate-funded cancellation loses only its recorded PKR/AED credit.');
        $assert(abs((float) $events[1]['supplier_credit_amount'] - 92.0) <= 0.01
            && abs((float) $event71['supplier_credit_recorded_source_amount'] - 7056.0) <= 0.01
            && abs((float) $event71['expected_supplier_refund_source_amount'] - 7056.0) <= 0.01
            && abs((float) $event71['expected_supplier_refund_amount'] - 92.0) <= 0.01,
            'Shared cancellation retains SPAY-000235 PKR 7,056 and the test-entered AED 92 remainder.');
        foreach ([0 => $event69, 1 => $event71] as $index => $afterPayload) {
            $beforePayload = json_decode((string) $cancellationBefore[$index]['payload_json'], true);
            $assert((float) $cancellationBefore[$index]['penalty_amount']
                    === (float) $db->query('SELECT penalty_amount FROM booking_service_events WHERE id = '
                        . (int) $cancellationBefore[$index]['id'])->fetchColumn()
                && (float) $cancellationBefore[$index]['customer_credit_amount']
                    === (float) $db->query('SELECT customer_credit_amount FROM booking_service_events WHERE id = '
                        . (int) $cancellationBefore[$index]['id'])->fetchColumn()
                && (float) $beforePayload['supplier_cost_basis'] === (float) $afterPayload['supplier_cost_basis']
                && (float) $beforePayload['supplier_delta'] === (float) $afterPayload['supplier_delta'],
                'Cancellation event ' . $cancellationBefore[$index]['id'] . ' keeps customer and service-cost terms unchanged.');
        }
        $otherPaymentAfter = $db->query(
            'SELECT paid_amount, allocated_amount, unallocated_amount, status
             FROM supplier_payments WHERE payment_no = "SPAY-000235"'
        )->fetch(PDO::FETCH_ASSOC);
        $otherCreditAfter = $db->query(
            'SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = 63'
        )->fetch(PDO::FETCH_ASSOC);
        $assert($otherPaymentAfter === $otherPaymentBefore && $otherCreditAfter === $otherCreditBefore,
            'The other real payment and its cancellation credit are not altered.');
        $statement = $db->prepare(
            'SELECT COUNT(DISTINCT je.id) AS journal_count,
                    SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.debit_amount ELSE 0 END) AS payable_debit,
                    SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.credit_amount ELSE 0 END) AS credit_removed
             FROM journal_entries je
             JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id > :watermark AND je.source_type = "supplier_settlement_release_void_adjustment"'
        );
        $statement->execute(['watermark' => $journalWatermark]);
        $adjustment = $statement->fetch(PDO::FETCH_ASSOC);
        $assert((int) $adjustment['journal_count'] === 2
            && abs((float) $adjustment['payable_debit'] - 224120.0) <= 0.01
            && abs((float) $adjustment['credit_removed'] - 224120.0) <= 0.01,
            'Two cancellation journals reverse exactly this payment\'s PKR 224,120 credit.');
        $statement = $db->prepare(
            'SELECT je.branch_id, je.currency,
                    ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS difference
             FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE je.id > :watermark GROUP BY je.branch_id, je.currency'
        );
        $statement->execute(['watermark' => $journalWatermark]);
        foreach ($statement as $row) {
            $assert(abs((float) $row['difference']) <= 0.01,
                'SPAY-000232 void journals balance in branch ' . $row['branch_id']
                . ' / ' . $row['currency'] . '.');
        }
        $statement = $db->prepare(
            'SELECT jel.supplier_obligation_id,
                    ROUND(SUM(jel.credit_amount - jel.debit_amount), 2) AS payable_change
             FROM journal_entries je
             JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id > :watermark AND coa.code = "AP_CONTROL"
               AND jel.supplier_obligation_id IN (1025, 1027)
             GROUP BY jel.supplier_obligation_id'
        );
        $statement->execute(['watermark' => $journalWatermark]);
        foreach ($statement as $row) {
            $assert(abs((float) $row['payable_change']) <= 0.01,
                'Cancelled booking obligation ' . $row['supplier_obligation_id'] . ' has no phantom payable after void.');
        }

        $otherPaymentId = (int) $db->query(
            'SELECT id FROM supplier_payments WHERE payment_no = "SPAY-000235"'
        )->fetchColumn();
        $otherImpact = $previewService->preview($otherPaymentId, [1, 2]);
        $remainingEvent = array_values(array_filter($otherImpact['cancellation_events'],
            static fn (array $event): bool => (int) $event['event_id'] === 71));
        $assert(count($remainingEvent) === 1
            && abs((float) $remainingEvent[0]['selected_source_amount'] - 7056.0) <= 0.01
            && abs((float) $remainingEvent[0]['recorded_source_amount'] - 7056.0) <= 0.01,
            'A later void preview sees only the surviving payment share of the shared cancellation.');
        $otherVoid = $voidService->voidGlobalSupplierPayment([
            'supplier_payment_id' => $otherPaymentId,
            'void_reason' => 'Rollback-only sequential shared-credit test',
            'confirm_reopen_payables' => '1',
            'confirm_remove_cancellation_credit' => '1',
            'impact_token' => $otherImpact['impact_token'],
            'cancellation_credit_equivalent' => [71 => '92'],
        ], $adminId, [1, 2]);
        $assert((string) $otherVoid['payment_no'] === 'SPAY-000235',
            'The second funding payment can later be voided with its remaining exact AED share.');
        $event71AfterSecondVoid = $db->query(
            'SELECT supplier_credit_amount FROM booking_service_events WHERE id = 71'
        )->fetchColumn();
        $assert(abs((float) $event71AfterSecondVoid) <= 0.01 && $customerTotals() === $customerBefore,
            'Sequential shared-credit void reaches zero without changing customer invoices.');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$reactivatedCandidate = $db->query(
    'SELECT id, branch_id, currency FROM supplier_payments
     WHERE payment_no = "SPAY-000263" AND status <> "void" LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (is_array($reactivatedCandidate)) {
    $db->beginTransaction();
    try {
        $paymentId = (int) $reactivatedCandidate['id'];
        $impact = $previewService->preview($paymentId, [1, 2]);
        (new AccountingRepository($app))->postJournalEntry([
            'branch_id' => (int) $reactivatedCandidate['branch_id'],
            'booking_reference' => null,
            'source_type' => 'supplier_payment_void_reversed',
            'source_reference' => 'ROLLBACK-ONLY-EXISTING-VOID-EVIDENCE',
            'entry_date' => date('Y-m-d'),
            'currency' => (string) $reactivatedCandidate['currency'],
            'narration' => 'Rollback-only existing void evidence guard',
            'actor_user_id' => $adminId,
        ], [
            ['account_code' => 'AP_CONTROL', 'supplier_payment_id' => $paymentId,
                'line_description' => 'Rollback-only debit', 'debit_amount' => 1, 'credit_amount' => 0],
            ['account_code' => 'SUPPLIER_ADVANCES', 'supplier_payment_id' => $paymentId,
                'line_description' => 'Rollback-only credit', 'debit_amount' => 0, 'credit_amount' => 1],
        ]);
        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => $paymentId,
                'void_reason' => 'Rollback-only previous reversal guard',
                'confirm_reopen_payables' => '1',
                'impact_token' => $impact['impact_token'],
            ], $adminId, [1, 2]);
            throw new RuntimeException('A previously reversed payment was reversed again.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'void-journal evidence'),
                'An active payment with old void-journal evidence cannot restore cash twice.');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$refundSource = $db->query(
    'SELECT p.id FROM supplier_payments p
     INNER JOIN supplier_advances sa ON sa.source_supplier_payment_id = p.id
     WHERE p.status <> "void" AND sa.reference_no LIKE "REFUND-EVT-%"
     LIMIT 1'
)->fetchColumn();
if ($refundSource !== false) {
    $db->beginTransaction();
    try {
        $impact = $previewService->preview((int) $refundSource, [1, 2]);
        $assert($impact['unsupported_credit_sources'] !== [],
            'Preview identifies a separately recorded supplier refund credit.');
        try {
            $voidService->voidGlobalSupplierPayment([
                'supplier_payment_id' => (int) $refundSource,
                'void_reason' => 'Regression source safety check',
                'confirm_reopen_payables' => '1',
                'impact_token' => $impact['impact_token'],
            ], $adminId, [1, 2]);
            throw new RuntimeException('A separately recorded refund credit was voided without source review.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'separately recorded supplier refund credit'),
                'Void refuses to guess how to reassign a separately recorded refund credit.');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$bookingPayment = $db->query(
    'SELECT p.id, p.payment_no, p.branch_id, p.treasury_account_id, p.paid_amount,
            p.charges_amount, b.id AS booking_id
     FROM supplier_payments p
     INNER JOIN bookings b ON b.booking_reference = p.booking_reference
     WHERE p.status <> "void" AND p.payment_scope <> "global"
       AND NOT EXISTS (
           SELECT 1 FROM supplier_advances sa WHERE sa.source_supplier_payment_id = p.id
       )
     ORDER BY p.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (is_array($bookingPayment)) {
    $db->beginTransaction();
    try {
        $beforeCustomer = $customerTotals();
        $treasuryId = (int) $bookingPayment['treasury_account_id'];
        $beforeCash = $treasuryId > 0 ? $treasury->currentBalanceForAccountId($treasuryId) : null;
        $result = $voidService->voidSupplierPayment([
            'booking_id' => (int) $bookingPayment['booking_id'],
            'supplier_payment_id' => (int) $bookingPayment['id'],
            'void_reason' => 'Rollback-only booking payment regression',
        ], $adminId, [(int) $bookingPayment['branch_id']]);
        $assert((int) $result['supplier_payment_id'] === (int) $bookingPayment['id'],
            'Booking-linked supplier payment follows the same audited void path.');
        $assert($customerTotals() === $beforeCustomer,
            'Booking-linked void leaves customer invoices unchanged.');
        if ($beforeCash !== null) {
            $expectedCash = (float) $bookingPayment['paid_amount'] + (float) $bookingPayment['charges_amount'];
            $assert(abs($treasury->currentBalanceForAccountId($treasuryId) - $beforeCash - $expectedCash) <= 0.01,
                'Booking-linked void restores its original treasury outflow.');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$fxFixture = $db->query(
    'SELECT p.id AS payment_id, p.supplier_id, sa.id AS advance_id, sa.branch_id AS advance_branch_id,
            sa.available_amount
     FROM supplier_payments p
     INNER JOIN supplier_advances sa ON sa.source_supplier_payment_id = p.id
     WHERE p.status <> "void" AND p.payment_scope = "global"
       AND p.currency = "AED" AND sa.currency = "AED"
       AND sa.reference_no LIKE "SPAY-%" AND sa.available_amount >= 10
     ORDER BY sa.available_amount DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$fxObligation = $db->query(
    'SELECT id, branch_id, booking_reference, service_line_reference, net_payable_amount
     FROM supplier_obligations
     WHERE currency = "PKR" AND status = "open" AND net_payable_amount >= 770
     ORDER BY id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (is_array($fxFixture) && is_array($fxObligation)) {
    $db->beginTransaction();
    try {
        // The supplier identity change is test-only and is rolled back with every financial posting below.
        $statement = $db->prepare('UPDATE supplier_obligations SET supplier_id = ? WHERE id = ?');
        $statement->execute([(int) $fxFixture['supplier_id'], (int) $fxObligation['id']]);
        $repository = new SupplierRepository($app);
        $accounting = new AccountingRepository($app);
        $application = $repository->applyCrossCurrencyAdvanceToObligation(
            (int) $fxFixture['advance_id'], (int) $fxObligation['id'], 770,
            'AED', 'PKR', 77, date('Y-m-d'), 'Rollback-only FX void regression', $adminId
        );
        $assert(abs((float) $application['advance_amount_consumed'] - 10) <= 0.01,
            'Synthetic cross-currency application consumes AED 10 for a PKR 770 payable.');
        $reference = 'SADV-FX-' . $fxFixture['advance_id'] . '-' . $fxObligation['id'];
        $fxJournalStart = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
        $accounting->postCrossCurrencySupplierAdvanceConversion([
            'source_branch_id' => (int) $fxFixture['advance_branch_id'],
            'destination_branch_id' => (int) $fxObligation['branch_id'],
            'source_currency' => 'AED', 'destination_currency' => 'PKR',
            'source_amount' => 10, 'destination_amount' => 770,
            'source_reference' => $reference, 'entry_date' => date('Y-m-d'), 'actor_user_id' => $adminId,
        ]);
        $accounting->postSupplierAdvanceApplication([
            'branch_id' => (int) $fxObligation['branch_id'],
            'booking_reference' => (string) $fxObligation['booking_reference'],
            'source_reference' => $reference, 'entry_date' => date('Y-m-d'),
            'currency' => 'PKR', 'amount' => 770,
            'service_line_reference' => (string) $fxObligation['service_line_reference'],
            'supplier_obligation_id' => (int) $fxObligation['id'], 'actor_user_id' => $adminId,
        ]);

        $impact = $previewService->preview((int) $fxFixture['payment_id'], [1, 2]);
        $assert(count($impact['advance_applications']) >= 1,
            'Void impact discovers the cross-currency credit use.');
        $journalWatermark = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
        $voidService->voidGlobalSupplierPayment([
            'supplier_payment_id' => (int) $fxFixture['payment_id'],
            'void_reason' => 'Rollback-only cross currency void regression',
            'confirm_reopen_payables' => '1',
            'impact_token' => $impact['impact_token'],
        ], $adminId, [1, 2]);
        $statement = $db->prepare('SELECT net_payable_amount FROM supplier_obligations WHERE id = ?');
        $statement->execute([(int) $fxObligation['id']]);
        $assert(abs((float) $statement->fetchColumn() - (float) $fxObligation['net_payable_amount']) <= 0.01,
            'Cross-currency void reopens the PKR payable by its entered equivalent.');
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM journal_entries WHERE id > :watermark
             AND source_type = "supplier_advance_fx_reversal"'
        );
        $statement->execute(['watermark' => $journalWatermark]);
        $assert((int) $statement->fetchColumn() === 2,
            'Cross-currency void reverses both AED and PKR FX clearing legs.');
        $statement = $db->prepare(
            'SELECT je.branch_id, je.currency, coa.code,
                    ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id > :watermark
               AND je.source_type IN ("supplier_advance_fx_conversion", "supplier_advance_fx_reversal")
             GROUP BY je.branch_id, je.currency, coa.code'
        );
        $statement->execute(['watermark' => $fxJournalStart]);
        foreach ($statement as $row) {
            $assert(abs((float) $row['net']) <= 0.01,
                'Original and reversal FX journals net to zero for '
                . $row['branch_id'] . ' / ' . $row['currency'] . ' / ' . $row['code'] . '.');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}
