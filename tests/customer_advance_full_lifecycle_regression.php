<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];

$fixture = $db->query(
    'SELECT cri.id AS receivable_id, cri.booking_reference, cri.currency, cri.outstanding_amount,
            b.id AS booking_id, b.branch_id, b.lead_traveler_id AS traveler_id,
            b.business_source_id, COALESCE(NULLIF(bp.lead_traveler_name, ""), t.full_name) AS customer_name,
            ta.id AS treasury_account_id, ta.account_name AS treasury_account_name, u.id AS user_id
     FROM customer_receivable_items cri
     INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
     LEFT JOIN booking_parties bp ON bp.booking_id = b.id
     LEFT JOIN travelers t ON t.id = b.lead_traveler_id
     INNER JOIN treasury_accounts ta ON ta.branch_id = b.branch_id
        AND ta.currency = cri.currency AND ta.is_active = 1
     CROSS JOIN users u
     WHERE cri.outstanding_amount > 0.01
       AND b.business_source_id IS NOT NULL
       AND b.lead_traveler_id IS NOT NULL
       AND u.is_active = 1
     ORDER BY cri.id DESC, ta.id
     LIMIT 1'
)->fetch();

if (! is_array($fixture)) {
    fwrite(STDERR, "Customer advance lifecycle regression skipped: no open receivable fixture was available.\n");
    exit(1);
}

$branchId = (int) $fixture['branch_id'];
$travelerId = (int) $fixture['traveler_id'];
$businessSourceId = (int) $fixture['business_source_id'];
$treasuryAccountId = (int) $fixture['treasury_account_id'];
$treasuryAccountName = (string) $fixture['treasury_account_name'];
$userId = (int) $fixture['user_id'];
$receivableId = (int) $fixture['receivable_id'];
$bookingReference = (string) $fixture['booking_reference'];
$customerName = (string) $fixture['customer_name'];
$currency = (string) $fixture['currency'];
$invoiceOutstanding = round((float) $fixture['outstanding_amount'], 2);
$returnAmount = 51.25;
$advanceAmount = round($invoiceOutstanding + $returnAmount, 2);

$money = static fn (string $value): float => round((float) str_replace([',', ' Dr', ' Cr'], '', $value), 2);
$summaryTotals = static function (array $report, string $currency) use ($money): array {
    $debit = 0.0;
    $credit = 0.0;
    foreach ((array) ($report['summaryRows'] ?? []) as $row) {
        if ((string) ($row['currency'] ?? '') !== $currency) {
            continue;
        }
        $debit += $money((string) ($row['total_debit'] ?? '0'));
        $credit += $money((string) ($row['total_credit'] ?? '0'));
    }
    return [round($debit, 2), round($credit, 2)];
};

$db->beginTransaction();
try {
    $paymentRepository = new \App\Repositories\CustomerPaymentRepository($app);
    $reportRepository = new \App\Repositories\ReportRepository($app);
    $accountService = new \App\Services\AccountLedgerService($app);
    $workspaceService = new \App\Services\CustomerReceiptWorkspaceService($app);

    $accountFilters = [
        'businessSourceId' => $businessSourceId,
        'customerName' => $customerName,
        'currency' => $currency,
    ];
    [$baselineDebit, $baselineCredit] = $summaryTotals(
        $accountService->report($accountFilters, [$branchId]),
        $currency
    );

    $advance = $workspaceService->recordCustomerAdvance([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'business_source_id' => $businessSourceId,
        'receipt_currency' => $currency,
        'received_amount' => $advanceAmount,
        'receipt_date' => date('Y-m-d'),
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'receipt_status' => 'received',
        'receipt_remarks' => 'Full lifecycle regression fixture',
    ], $userId, [$branchId]);

    if (abs((float) ($advance['allocated_amount'] ?? 0) - $invoiceOutstanding) > 0.005
        || (int) ($advance['allocation_count'] ?? 0) !== 1
        || abs((float) ($advance['unallocated_amount'] ?? 0) - $returnAmount) > 0.005
    ) {
        $failures[] = 'Recording the customer advance did not automatically settle the open invoice first.';
    }

    $workspaceService->refundCustomerAdvance([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'receipt_currency' => $currency,
        'advance_refund_receipt_id' => (int) $advance['receipt_id'],
        'advance_refund_amount' => $returnAmount,
        'advance_refund_date' => date('Y-m-d'),
        'advance_refund_method' => 'cash',
        'advance_refund_treasury_account_id' => $treasuryAccountId,
        'advance_refund_reason' => 'Lifecycle regression return',
    ], $userId, [$branchId]);

    $receipt = $paymentRepository->findReceiptById((int) $advance['receipt_id']);
    if (! is_array($receipt)
        || (int) ($receipt['business_source_id'] ?? 0) !== $businessSourceId
        || abs((float) ($receipt['allocated_amount'] ?? 0) - $invoiceOutstanding) > 0.005
        || abs((float) ($receipt['returned_amount'] ?? 0) - $returnAmount) > 0.005
        || abs((float) ($receipt['unallocated_amount'] ?? 0)) > 0.005
    ) {
        $failures[] = 'The advance receipt did not conserve received, applied, and returned value.';
    }

    $receivable = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId)->fetch();
    if (! is_array($receivable) || abs((float) $receivable['outstanding_amount']) > 0.005) {
        $failures[] = 'The customer invoice did not close after applying the advance.';
    }

    $availableAdvances = $paymentRepository->availableCustomerAdvances($branchId, $travelerId, $currency);
    if (array_filter($availableAdvances, static fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $advance['receipt_id'])) {
        $failures[] = 'A fully applied/returned advance remained selectable as available credit.';
    }

    $advanceLedgerRows = array_values(array_filter(
        $reportRepository->customerAdvanceLedger([$branchId], null, null, $currency, $customerName),
        static fn (array $row): bool => (int) ($row['receipt_id'] ?? 0) === (int) $advance['receipt_id']
    ));
    $advanceReceived = array_sum(array_column($advanceLedgerRows, 'received_amount'));
    $advanceApplied = array_sum(array_column($advanceLedgerRows, 'applied_amount'));
    $advanceReturned = array_sum(array_column($advanceLedgerRows, 'returned_amount'));
    if (abs($advanceReceived - $advanceAmount) > 0.005
        || abs($advanceApplied - $invoiceOutstanding) > 0.005
        || abs($advanceReturned - $returnAmount) > 0.005
        || abs($advanceReceived - $advanceApplied - $advanceReturned) > 0.005
    ) {
        $failures[] = 'The Customer Advance Ledger did not close at zero.';
    }

    $customerState = (new \App\Services\ReportService($app))->reportState([
        'report' => 'customer_detail_ledger',
        'branch_id' => $branchId,
        'currency' => $currency,
        'business_source_id' => $businessSourceId,
        'customer_name' => $customerName,
        'booking_reference' => $bookingReference,
        'as_of_date' => date('Y-m-d'),
    ], [$branchId], $userId);
    $customerAdvanceRows = array_values(array_filter(
        (array) ($customerState['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['ledger_entry'] ?? '') === 'Customer advance applied to invoice'
    ));
    if (count($customerAdvanceRows) !== 1
        || abs((float) ($customerAdvanceRows[0]['raw_debit_amount'] ?? 0) - $invoiceOutstanding) > 0.005
    ) {
        $failures[] = 'The Customer Detail Ledger did not show the applied advance as Debit.';
    }

    $customerWideState = (new \App\Services\ReportService($app))->reportState([
        'report' => 'customer_detail_ledger',
        'branch_id' => $branchId,
        'currency' => $currency,
        'business_source_id' => $businessSourceId,
        'customer_name' => $customerName,
        'as_of_date' => date('Y-m-d'),
    ], [$branchId], $userId);
    $advanceReceiptRows = array_values(array_filter(
        (array) ($customerWideState['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['ledger_entry'] ?? '') === 'Customer advance received'
            && (string) ($row['transaction_reference'] ?? '') === (string) $advance['receipt_no']
    ));
    if (count($advanceReceiptRows) !== 1
        || abs((float) ($advanceReceiptRows[0]['raw_debit_amount'] ?? 0) - $advanceAmount) > 0.005
    ) {
        $failures[] = 'The Customer Detail Ledger did not show the original advance receipt once as Debit.';
    }

    $customerWideTransferRows = array_values(array_filter(
        (array) ($customerWideState['rows'] ?? []),
        static fn (array $row): bool => (bool) ($row['is_customer_advance_transfer'] ?? false)
    ));
    if ($customerWideTransferRows !== []) {
        $failures[] = 'The customer-wide ledger exposed an internal advance-to-invoice application line.';
    }

    $runningBalance = 0.0;
    foreach ((array) ($customerWideState['rows'] ?? []) as $ledgerRow) {
        $runningBalance = round(
            $runningBalance
            + (float) ($ledgerRow['raw_debit_amount'] ?? 0)
            - (float) ($ledgerRow['raw_credit_amount'] ?? 0),
            2
        );
        if (abs((float) ($ledgerRow['raw_balance_amount'] ?? 0) - $runningBalance) > 0.005) {
            $failures[] = 'The Customer Detail Ledger running balance was not chronological and cumulative.';
            break;
        }
    }

    $accountReport = $accountService->report($accountFilters, [$branchId]);
    [$finalDebit, $finalCredit] = $summaryTotals($accountReport, $currency);
    if (abs(($finalDebit - $baselineDebit) - $advanceAmount) > 0.005
        || abs(($finalCredit - $baselineCredit) - $returnAmount) > 0.005
    ) {
        $failures[] = 'The Account Ledger summary inflated the advance when it was applied internally.';
    }

    $advanceAccountRows = array_values(array_filter(
        (array) ($accountReport['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['transaction_reference'] ?? '') === (string) $advance['receipt_no']
            || str_starts_with((string) ($row['transaction_reference'] ?? ''), 'ADV-XFER-')
            || ((string) ($row['booking_reference'] ?? '') === $bookingReference
                && (string) ($row['ledger_entry'] ?? '') === 'Customer advance applied to invoice')
    ));
    $movementNet = round(array_sum(array_column($advanceAccountRows, 'raw_debit_amount'))
        - array_sum(array_column($advanceAccountRows, 'raw_credit_amount')), 2);
    if (abs($movementNet - $invoiceOutstanding) > 0.005) {
        $failures[] = 'The Account Ledger advance movements did not preserve the net retained cash.';
    }

    $refundEntry = $db->query(
        'SELECT id
         FROM customer_advance_refunds
         WHERE customer_receipt_id = ' . (int) $advance['receipt_id'] . '
         ORDER BY id DESC
         LIMIT 1'
    )->fetch();
    if (! is_array($refundEntry)) {
        $failures[] = 'The returned customer advance entry could not be loaded for correction.';
    } else {
        $workspaceService->correctCustomerAdvanceRefund([
            'customer_advance_refund_id' => (int) $refundEntry['id'],
            'branch_id' => $branchId,
            'traveler_id' => $travelerId,
            'receipt_currency' => $currency,
            'advance_refund_amount' => 0,
            'advance_refund_date' => date('Y-m-d'),
            'advance_refund_method' => 'cash',
            'advance_refund_treasury_account_id' => $treasuryAccountId,
            'correction_reason' => 'Regression: original return was entered by mistake',
        ], $userId, [$branchId]);

        $correctedReceipt = $paymentRepository->findReceiptById((int) $advance['receipt_id']);
        $correctedRefund = $db->query(
            'SELECT amount, journal_entry_id
             FROM customer_advance_refunds
             WHERE id = ' . (int) $refundEntry['id']
        )->fetch();
        if (! is_array($correctedReceipt)
            || abs((float) ($correctedReceipt['returned_amount'] ?? 0)) > 0.005
            || abs((float) ($correctedReceipt['unallocated_amount'] ?? 0) - $returnAmount) > 0.005
            || ! is_array($correctedRefund)
            || abs((float) ($correctedRefund['amount'] ?? 0)) > 0.005
            || $correctedRefund['journal_entry_id'] !== null
        ) {
            $failures[] = 'Correcting the mistaken return to zero did not restore the available advance exactly.';
        }

        $correctedAvailable = $paymentRepository->availableCustomerAdvances($branchId, $travelerId, $currency);
        $restoredAdvance = array_values(array_filter(
            $correctedAvailable,
            static fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $advance['receipt_id']
        ));
        if (count($restoredAdvance) !== 1
            || abs((float) ($restoredAdvance[0]['unallocated_amount'] ?? 0) - $returnAmount) > 0.005
        ) {
            $failures[] = 'The zero return correction did not make the restored customer advance selectable.';
        }

        $correctionAudit = $db->query(
            'SELECT new_amount, reversal_journal_entry_id, new_journal_entry_id
             FROM customer_advance_corrections
             WHERE customer_advance_refund_id = ' . (int) $refundEntry['id'] . '
               AND correction_type = "advance_returned"
             ORDER BY id DESC
             LIMIT 1'
        )->fetch();
        if (! is_array($correctionAudit)
            || abs((float) ($correctionAudit['new_amount'] ?? 0)) > 0.005
            || (int) ($correctionAudit['reversal_journal_entry_id'] ?? 0) <= 0
            || $correctionAudit['new_journal_entry_id'] !== null
        ) {
            $failures[] = 'The zero return correction audit trail or journal reversal is incomplete.';
        } else {
            $restoredTreasuryDescription = $db->prepare(
                'SELECT line_description
                 FROM journal_entry_lines
                 WHERE journal_entry_id = :journal_entry_id
                   AND debit_amount > 0
                 LIMIT 1'
            );
            $restoredTreasuryDescription->execute([
                'journal_entry_id' => (int) $correctionAudit['reversal_journal_entry_id'],
            ]);
            if ((string) $restoredTreasuryDescription->fetchColumn()
                !== 'Correction reversal: Funds restored to ' . $treasuryAccountName
            ) {
                $failures[] = 'The return correction did not identify the treasury account receiving the restored funds.';
            }
        }
    }

    $imbalancedJournalCount = (int) $db->query(
        'SELECT COUNT(*) FROM (
            SELECT je.id
            FROM journal_entries je
            INNER JOIN journal_entry_lines jl ON jl.journal_entry_id = je.id
            GROUP BY je.id
            HAVING ABS(SUM(jl.debit_amount) - SUM(jl.credit_amount)) > 0.005
        ) imbalanced'
    )->fetchColumn();
    if ($imbalancedJournalCount !== 0) {
        $failures[] = 'The lifecycle introduced an imbalanced journal entry.';
    }
} catch (Throwable $exception) {
    $messages = [];
    do {
        $messages[] = $exception->getMessage();
        $exception = $exception->getPrevious();
    } while ($exception !== null);
    $failures[] = implode(' <- ', $messages);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Customer advance full lifecycle regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Advance receipt retained its Account Holder and conserved value.' . PHP_EOL;
echo '[PASS] Recording the advance automatically closed the oldest matching customer receivable.' . PHP_EOL;
echo '[PASS] Customer Detail Ledger shows the advance once and maintains a chronological running balance.' . PHP_EOL;
echo '[PASS] Applied/returned advance closed in the Customer Advance Ledger and is no longer selectable.' . PHP_EOL;
echo '[PASS] Account Ledger shows receipt, internal application, and return without inflating summary cash.' . PHP_EOL;
echo '[PASS] A mistaken full advance return can be corrected to zero and restores available credit.' . PHP_EOL;
echo '[PASS] All journals remained balanced and the fixture was rolled back.' . PHP_EOL;
