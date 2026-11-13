<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\TreasuryRepository;
use App\Services\SupplierPaymentVoidImpactService;
use App\Services\SupplierPaymentVoidReplacementService;
use App\Services\SupplierSettlementWorkspaceService;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$previewService = new SupplierPaymentVoidImpactService($app);
$voidService = new SupplierSettlementWorkspaceService($app);
$treasury = new TreasuryRepository($app);
$adminId = (int) $db->query('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
    WHERE u.is_active = 1 AND r.code = "super_admin" ORDER BY u.id LIMIT 1')->fetchColumn();
$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};
$assert($adminId > 0, 'A financial administrator exists for rollback-only void tests.');
$customerSnapshot = static function () use ($db): array {
    return $db->query('SELECT branch_id, currency, COUNT(*) AS rows_count,
        SUM(due_amount) AS due, SUM(outstanding_amount) AS outstanding
        FROM customer_receivable_items GROUP BY branch_id, currency ORDER BY branch_id, currency')
        ->fetchAll(PDO::FETCH_ASSOC);
};
$paymentId = static function (string $number) use ($db): int {
    $statement = $db->prepare('SELECT id FROM supplier_payments WHERE payment_no = ?');
    $statement->execute([$number]);
    return (int) $statement->fetchColumn();
};
$payable = static function (int $id) use ($db): float {
    $statement = $db->prepare('SELECT net_payable_amount FROM supplier_obligations WHERE id = ?');
    $statement->execute([$id]);
    return (float) $statement->fetchColumn();
};
$advanceAvailable = static function (int $id) use ($db): float {
    $statement = $db->prepare('SELECT available_amount FROM supplier_advances WHERE id = ?');
    $statement->execute([$id]);
    return (float) $statement->fetchColumn();
};
$journalBalanced = static function (int $watermark) use ($db, $assert): void {
    $statement = $db->prepare('SELECT je.id, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS difference
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.id > ? GROUP BY je.id');
    $statement->execute([$watermark]);
    $count = 0;
    foreach ($statement as $row) {
        $assert(abs((float) $row['difference']) <= 0.01, 'New journal #' . $row['id'] . ' balances.');
        $count++;
    }
    $assert($count > 0, 'The void and replacement posted journal evidence.');
};

$fullId = $paymentId('SPAY-000271');
$assert($fullId > 0, 'The local full-cover example is present.');
$db->beginTransaction();
try {
    $paymentStatement = $db->prepare('SELECT supplier_id, branch_id, currency FROM supplier_payments WHERE id = ?');
    $paymentStatement->execute([$fullId]);
    $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
    $uncoveredPreview = $previewService->preview($fullId, [1, 2]);
    $reopenedTotal = array_sum(array_column($uncoveredPreview['replacement']['obligations'], 'reopened_amount'));
    $assert($reopenedTotal > 0, 'The payment has supplier payables to reopen.');
    $insert = $db->prepare('INSERT INTO supplier_advances
        (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no,
         received_at, payment_method, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, "TEST-ROLLBACK-FULL-COVER", CURDATE(), "cash", ?)');
    $insert->execute([(int) $payment['supplier_id'], (int) $payment['branch_id'],
        (string) $payment['currency'], $reopenedTotal, $reopenedTotal, $adminId]);
    $syntheticAdvanceId = (int) $db->lastInsertId();
    $fullPreview = $previewService->preview($fullId, [1, 2]);
    $proposedAmount = array_sum(array_column($fullPreview['replacement']['lines'], 'amount'));
    $assert(abs($proposedAmount - $reopenedTotal) < 0.01,
        'Review proposes full coverage from test-only credit without automatically applying it.');
    $assert(count(array_unique(array_column($fullPreview['replacement']['lines'], 'advance_id'))) === 1
        && (int) $fullPreview['replacement']['lines'][0]['advance_id'] === $syntheticAdvanceId,
        'Review identifies the test-only advance as the funding source.');
    $beforeCustomer = $customerSnapshot();
    $beforeOtherAdvance = $advanceAvailable($syntheticAdvanceId);
    $beforeCash = $treasury->currentBalanceForAccountId((int) $fullPreview['payment']['treasury_account_id']);
    $journalWatermark = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
    $input = [
        'supplier_payment_id' => $fullId,
        'void_reason' => 'Regression duplicate payment',
        'confirm_reopen_payables' => '1',
        'impact_token' => $fullPreview['impact_token'],
        'replacement_token' => $fullPreview['replacement']['token'],
        'apply_replacement_advances' => '1',
        'advance_apply_amount' => '999999',
    ];
    try {
        $voidService->voidGlobalSupplierPayment(array_replace($input, ['replacement_token' => 'stale']), $adminId, [1, 2]);
        throw new RuntimeException('A stale replacement preview was accepted.');
    } catch (RuntimeException $exception) {
        // A forged preview must fail before any posting.
        if (! str_contains($exception->getMessage(), 'changed during review')) {
            throw $exception;
        }
    }
    $assert($advanceAvailable($syntheticAdvanceId) === $beforeOtherAdvance,
        'A stale confirmation changes no other advance.');
    $db->prepare('UPDATE supplier_advances SET available_amount = available_amount - 1 WHERE id = ?')
        ->execute([$syntheticAdvanceId]);
    try {
        $voidService->voidGlobalSupplierPayment($input, $adminId, [1, 2]);
        throw new RuntimeException('A changed source advance was accepted from a stale review.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'changed during review'),
            'A changed available-advance balance invalidates the reviewed plan.');
    } finally {
        $db->prepare('UPDATE supplier_advances SET available_amount = available_amount + 1 WHERE id = ?')
            ->execute([$syntheticAdvanceId]);
    }
    $result = $voidService->voidGlobalSupplierPayment($input, $adminId, [1, 2]);
    $assert(count($result['replacement']['applications']) === count($fullPreview['replacement']['lines']),
        'Confirmed void applies every reviewed replacement exactly once.');
    $assert(min(array_column($result['replacement']['applications'], 'journal_entry_id')) > $journalWatermark,
        'Each replacement links to its posted journal entry for audit.');
    $assert(abs($advanceAvailable($syntheticAdvanceId) - ($beforeOtherAdvance - $proposedAmount)) < 0.01,
        'Only the reviewed amount is consumed from test-only credit.');
    foreach ($result['replacement']['obligations'] as $obligation) {
        $assert(abs((float) $obligation['open']) < 0.01 && abs($payable((int) $obligation['obligation_id'])) < 0.01,
            $obligation['booking_reference'] . ' is fully covered with no false open balance.');
    }
    $assert($customerSnapshot() === $beforeCustomer, 'Customer invoices remain unchanged by void and replacement.');
    $assert(abs($treasury->currentBalanceForAccountId((int) $fullPreview['payment']['treasury_account_id'])
        - $beforeCash - (float) $fullPreview['payment']['paid_amount']
        - (float) $fullPreview['payment']['charges_amount']) < 0.01,
        'Treasury restores the voided cash outflow only; replacement moves no cash.');
    $journalBalanced($journalWatermark);
} finally {
    $db->rollBack();
}

$bookingPayment = $db->query('SELECT p.id, p.branch_id, p.supplier_id, p.currency, b.id AS booking_id
    FROM supplier_payments p JOIN bookings b ON b.booking_reference = p.booking_reference
    WHERE p.payment_no = "SPAY-000006" AND p.status <> "void"')->fetch(PDO::FETCH_ASSOC);
$assert(is_array($bookingPayment), 'A booking-linked payment exists for the second void entry point.');
$db->beginTransaction();
try {
    $insert = $db->prepare('INSERT INTO supplier_advances
        (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no,
         received_at, payment_method, created_by_user_id)
        VALUES (?, ?, ?, 1000, 1000, "TEST-ROLLBACK-REPLACEMENT", CURDATE(), "cash", ?)');
    $insert->execute([(int) $bookingPayment['supplier_id'], (int) $bookingPayment['branch_id'],
        (string) $bookingPayment['currency'], $adminId]);
    $syntheticAdvanceId = (int) $db->lastInsertId();
    $preview = $previewService->preview((int) $bookingPayment['id'], [(int) $bookingPayment['branch_id']]);
    $assert($preview['replacement']['can_apply'] && count($preview['replacement']['lines']) === 1,
        'Booking-linked review shows the test-only available advance and its exact partial coverage.');
    $beforeCustomer = $customerSnapshot();
    $result = $voidService->voidSupplierPayment([
        'booking_id' => (int) $bookingPayment['booking_id'],
        'supplier_payment_id' => (int) $bookingPayment['id'],
        'void_reason' => 'Rollback-only booking replacement',
        'confirm_reopen_payables' => '1',
        'impact_token' => $preview['impact_token'],
        'replacement_token' => $preview['replacement']['token'],
        'apply_replacement_advances' => '1',
    ], $adminId, [(int) $bookingPayment['branch_id']]);
    $assert(count($result['replacement']['applications']) === 1
        && abs($advanceAvailable($syntheticAdvanceId)) < 0.01,
        'Booking-linked void applies only the user-confirmed AED 1,000 credit.');
    $assert(abs((float) $result['replacement']['obligations'][0]['open'] - 420) < 0.01,
        'Booking-linked outcome leaves AED 420 payable instead of claiming full settlement.');
    $assert($customerSnapshot() === $beforeCustomer, 'Booking-linked replacement leaves customer invoices intact.');
} finally {
    $db->rollBack();
}

$db->beginTransaction();
try {
    $optOutPreview = $previewService->preview($fullId, [1, 2]);
    $beforeCustomer = $customerSnapshot();
    $result = $voidService->voidGlobalSupplierPayment([
        'supplier_payment_id' => $fullId,
        'void_reason' => 'Regression opt out of replacement',
        'confirm_reopen_payables' => '1',
        'impact_token' => $optOutPreview['impact_token'],
    ], $adminId, [1, 2]);
    $assert($result['replacement']['applications'] === [], 'Leaving the option unchecked applies no other advance.');
    $assert(abs(array_sum(array_column($result['replacement']['obligations'], 'open')) - $reopenedTotal) < 0.01,
        'Opt-out reports the exact reopened payable without claiming it was covered.');
    $assert($customerSnapshot() === $beforeCustomer, 'Opt-out leaves customer invoices intact.');
} finally {
    $db->rollBack();
}

$partialId = $paymentId('SPAY-000215');
$assert($partialId > 0, 'A real short-credit case exists.');
$partialPreview = $previewService->preview($partialId, [1, 2]);
$assert($partialPreview['replacement']['can_apply']
    && array_sum(array_column($partialPreview['replacement']['lines'], 'amount')) === 563.0,
    'Short-credit preview proposes AED 563 and leaves the rest open.');
$db->beginTransaction();
try {
    $beforeCustomer = $customerSnapshot();
    $beforeAdvance = $advanceAvailable(68);
    $journalWatermark = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
    $result = $voidService->voidGlobalSupplierPayment([
        'supplier_payment_id' => $partialId,
        'void_reason' => 'Regression partial replacement',
        'confirm_reopen_payables' => '1',
        'impact_token' => $partialPreview['impact_token'],
        'replacement_token' => $partialPreview['replacement']['token'],
        'apply_replacement_advances' => '1',
    ], $adminId, [1, 2]);
    $assert(abs(array_sum(array_column($result['replacement']['applications'], 'amount')) - 563) < 0.01,
        'Only the available AED 563 is applied; no excess advance is invented.');
    $assert(abs($advanceAvailable(68) - ($beforeAdvance - 563)) < 0.01,
        'The actual AED advance falls by exactly AED 563.');
    $openTotal = array_sum(array_column($result['replacement']['obligations'], 'open'));
    $assert($openTotal > 0 && abs($openTotal - 4437) < 0.01,
        'The outcome explicitly retains AED 4,437 of supplier payable.');
    $assert(str_contains(SupplierPaymentVoidReplacementService::outcomeMessage('SPAY-000215', $result['replacement']),
        '4,437.00 remains open'), 'The user-facing message does not claim partial cover is full settlement.');
    $assert($customerSnapshot() === $beforeCustomer, 'Partial replacement does not alter customer invoices.');
    $journalBalanced($journalWatermark);
} finally {
    $db->rollBack();
}

$multiId = $paymentId('SPAY-000267');
$multiPreview = $previewService->preview($multiId, [1, 2]);
$assert(count(array_unique(array_column($multiPreview['replacement']['lines'], 'advance_id'))) > 1,
    'A real multi-source replacement plan draws from distinct existing advances.');
$db->beginTransaction();
try {
    $beforeCustomer = $customerSnapshot();
    $result = $voidService->voidGlobalSupplierPayment([
        'supplier_payment_id' => $multiId,
        'void_reason' => 'Regression multi-source replacement',
        'confirm_reopen_payables' => '1',
        'impact_token' => $multiPreview['impact_token'],
        'replacement_token' => $multiPreview['replacement']['token'],
        'apply_replacement_advances' => '1',
    ], $adminId, [1, 2]);
    $assert(count($result['replacement']['applications']) === count($multiPreview['replacement']['lines']),
        'Every reviewed multi-source line posts exactly once.');
    foreach ($result['replacement']['obligations'] as $obligation) {
        $assert(abs($payable((int) $obligation['obligation_id']) - (float) $obligation['open']) < 0.01,
            $obligation['booking_reference'] . ' keeps the exact confirmed remaining payable.');
    }
    $assert($customerSnapshot() === $beforeCustomer, 'Multi-source replacement leaves customer invoices intact.');
} finally {
    $db->rollBack();
}

$noCreditId = $paymentId('SPAY-000263');
$noCreditPreview = $previewService->preview($noCreditId, [1, 2]);
$assert(! $noCreditPreview['replacement']['can_apply'], 'A payment with no matching advance offers no replacement choice.');
$db->beginTransaction();
try {
    try {
        $voidService->voidGlobalSupplierPayment([
            'supplier_payment_id' => $noCreditId,
            'void_reason' => 'Regression forged replacement',
            'confirm_reopen_payables' => '1',
            'impact_token' => $noCreditPreview['impact_token'],
            'replacement_token' => $noCreditPreview['replacement']['token'],
            'apply_replacement_advances' => '1',
        ], $adminId, [1, 2]);
        throw new RuntimeException('An unavailable replacement was accepted.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'changed during review'),
            'A forged replacement option with no eligible credit is rejected.');
    }
} finally {
    $db->rollBack();
}
