<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use App\Core\App;
use App\Repositories\ReportRepository;
use App\Repositories\TreasuryRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$treasury = new TreasuryRepository($app);
$reports = new ReportRepository($app);

$accounts = $db->query(
    'SELECT ta.id, ta.branch_id, ta.account_name, ta.currency, ta.opening_balance,
            ta.linked_account_id, coa.id AS chart_account_id
     FROM treasury_accounts ta
     LEFT JOIN chart_of_accounts coa ON coa.code = ta.account_code
     WHERE ta.account_name IN ("Cash-SWT-PKR", "Cash-UAE-AED")
     ORDER BY ta.account_name'
)->fetchAll(PDO::FETCH_ASSOC);

if (count($accounts) !== 2) {
    fwrite(STDERR, "Expected both named cash accounts; no reconciliation was attempted.\n");
    exit(1);
}

$ledgerRows = $reports->cashBankLedger(array_values(array_unique(array_map(
    static fn (array $account): int => (int) $account['branch_id'],
    $accounts
))), null, null);
$output = [
    'inspection_only' => true,
    'generated_at' => date(DATE_ATOM),
    'source_high_water_marks' => [],
    'historical_cross_currency_transfers' => $db->query(
        'SELECT id, status, transaction_date, from_treasury_account_id,
                to_treasury_account_id, source_currency, source_amount,
                destination_currency, destination_amount, journal_entry_id,
                destination_journal_entry_id
         FROM treasury_transactions WHERE id IN (5, 6, 9) ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC),
    'unassigned_cash_documents' => $db->query(
        'SELECT "customer_receipt" AS document_type, receipt_no AS reference,
                branch_id, currency, received_amount AS amount
         FROM customer_receipts
         WHERE status <> "void" AND treasury_account_id IS NULL
           AND payment_method IN ("cash", "bank_transfer")
         UNION ALL
         SELECT "supplier_payment" AS document_type, payment_no AS reference,
                branch_id, currency, paid_amount AS amount
         FROM supplier_payments
         WHERE status <> "void" AND treasury_account_id IS NULL
           AND payment_method IN ("cash", "bank_transfer")
         ORDER BY amount DESC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC),
    'accounts' => [],
];

foreach (['journal_entries', 'customer_receipts', 'supplier_payments', 'treasury_transactions'] as $table) {
    $output['source_high_water_marks'][$table] = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn();
}

$journalSummary = $db->prepare(
    'SELECT COUNT(DISTINCT je.id) AS journal_count,
            ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS money_in,
            ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS money_out,
            ROUND(COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0), 2) AS net
     FROM journal_entry_lines jel
     INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = :account_id AND je.currency = :currency'
);
$sourceBreakdown = $db->prepare(
    'SELECT je.source_type, COUNT(DISTINCT je.id) AS journal_count,
            ROUND(SUM(jel.debit_amount), 2) AS money_in,
            ROUND(SUM(jel.credit_amount), 2) AS money_out
     FROM journal_entry_lines jel
     INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = :account_id AND je.currency = :currency
     GROUP BY je.source_type ORDER BY ABS(SUM(jel.debit_amount - jel.credit_amount)) DESC'
);
$monthly = $db->prepare(
    'SELECT DATE_FORMAT(je.entry_date, "%Y-%m") AS month,
            ROUND(SUM(jel.debit_amount), 2) AS money_in,
            ROUND(SUM(jel.credit_amount), 2) AS money_out,
            ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net
     FROM journal_entry_lines jel
     INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = :account_id AND je.currency = :currency
     GROUP BY DATE_FORMAT(je.entry_date, "%Y-%m") ORDER BY month DESC'
);
$recent = $db->prepare(
    'SELECT je.id AS journal_id, je.entry_date, je.created_at, je.branch_id,
            je.source_type, je.source_reference,
            ROUND(jel.debit_amount, 2) AS money_in,
            ROUND(jel.credit_amount, 2) AS money_out
     FROM journal_entry_lines jel
     INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = :account_id AND je.currency = :currency
     ORDER BY je.id DESC, jel.id DESC LIMIT 25'
);
$largestRecentOutflows = $db->prepare(
    'SELECT je.id AS journal_id, je.entry_date, je.created_at,
            je.source_type, je.source_reference,
            ROUND(jel.credit_amount, 2) AS money_out
     FROM journal_entry_lines jel
     INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = :account_id AND je.currency = :currency
       AND je.entry_date >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
       AND je.source_type <> "supplier_payment_treasury_funded"
       AND jel.credit_amount > 0
     ORDER BY jel.credit_amount DESC, je.id DESC LIMIT 25'
);
$supplierPayments = $db->prepare(
    'SELECT sp.payment_no, sp.status, sp.paid_amount,
            ROUND(COALESCE(SUM(CASE WHEN je.currency = ta.currency
                THEN jel.credit_amount - jel.debit_amount ELSE 0 END), 0), 2) AS journal_cash_out
     FROM supplier_payments sp
     INNER JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
     LEFT JOIN journal_entry_lines jel ON jel.supplier_payment_id = sp.id
         AND jel.account_id = ta.linked_account_id
     LEFT JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE sp.treasury_account_id = :treasury_account_id
       AND sp.payment_method IN ("cash", "bank_transfer")
     GROUP BY sp.id'
);
$customerReceipts = $db->prepare(
    'SELECT cr.receipt_no, cr.status, cr.received_amount,
            ROUND(COALESCE(SUM(CASE WHEN je.currency = ta.currency
                THEN jel.debit_amount - jel.credit_amount ELSE 0 END), 0), 2) AS journal_cash_in
     FROM customer_receipts cr
     INNER JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
     LEFT JOIN journal_entry_lines jel ON jel.customer_receipt_id = cr.id
         AND jel.account_id = ta.linked_account_id
     LEFT JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE cr.treasury_account_id = :treasury_account_id
       AND cr.payment_method IN ("cash", "bank_transfer")
     GROUP BY cr.id'
);
$transfers = $db->prepare(
    'SELECT id FROM treasury_transactions
     WHERE status = "posted" AND (
         from_treasury_account_id = :source_id OR to_treasury_account_id = :destination_id
     ) AND transaction_type IN (
         "cash_deposit_to_bank", "bank_withdrawal_to_cash",
         "bank_to_bank_transfer", "cash_to_cash_transfer"
     ) ORDER BY id'
);

foreach ($accounts as $account) {
    $treasuryId = (int) $account['id'];
    $chartId = (int) $account['chart_account_id'];
    if ($chartId <= 0 || $chartId !== (int) $account['linked_account_id']) {
        throw new RuntimeException('Cash account chart link is missing or inconsistent: ' . $account['account_name']);
    }

    $journalParams = ['account_id' => $chartId, 'currency' => $account['currency']];
    $journalSummary->execute($journalParams);
    $journal = $journalSummary->fetch(PDO::FETCH_ASSOC);
    $sourceBreakdown->execute($journalParams);
    $monthly->execute($journalParams);
    $recent->execute($journalParams);
    $largestRecentOutflows->execute($journalParams);

    $ledgerIn = 0.0;
    $ledgerOut = 0.0;
    $ledgerCount = 0;
    foreach ($ledgerRows as $row) {
        if ((int) ($row['treasury_account_id'] ?? 0) !== $treasuryId) {
            continue;
        }
        $ledgerIn += (float) $row['debit_amount'];
        $ledgerOut += (float) $row['credit_amount'];
        $ledgerCount++;
    }

    $supplierPayments->execute(['treasury_account_id' => $treasuryId]);
    $supplierMismatches = [];
    $supplierCount = 0;
    foreach ($supplierPayments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $supplierCount++;
        $expected = $payment['status'] === 'void' ? 0.0 : (float) $payment['paid_amount'];
        if (abs((float) $payment['journal_cash_out'] - $expected) > 0.01) {
            $supplierMismatches[] = [
                'payment_no' => $payment['payment_no'],
                'expected_cash_out' => round($expected, 2),
                'journal_cash_out' => (float) $payment['journal_cash_out'],
            ];
        }
    }

    $customerReceipts->execute(['treasury_account_id' => $treasuryId]);
    $receiptMismatches = [];
    $receiptCount = 0;
    foreach ($customerReceipts->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
        $receiptCount++;
        // Refund cash movements have separate journal sources; compare the receipt posting itself.
        $expected = $receipt['status'] === 'void' ? 0.0 : (float) $receipt['received_amount'];
        if (abs((float) $receipt['journal_cash_in'] - $expected) > 0.01) {
            $receiptMismatches[] = [
                'receipt_no' => $receipt['receipt_no'],
                'expected_cash_in' => round($expected, 2),
                'journal_cash_in' => (float) $receipt['journal_cash_in'],
            ];
        }
    }

    $transfers->execute(['source_id' => $treasuryId, 'destination_id' => $treasuryId]);
    $transferIds = array_map('intval', $transfers->fetchAll(PDO::FETCH_COLUMN));
    $transferFailures = [];
    foreach ($transferIds as $transferId) {
        try {
            $treasury->assertPostedTransferJournalIntegrity($transferId);
        } catch (Throwable $exception) {
            $transferFailures[] = ['transfer_id' => $transferId, 'problem' => $exception->getMessage()];
        }
    }

    $journalBalance = round((float) $account['opening_balance'] + (float) $journal['net'], 2);
    $liveBalance = $treasury->currentBalanceForAccountId($treasuryId);
    $ledgerBalance = round((float) $account['opening_balance'] + $ledgerIn - $ledgerOut, 2);
    $output['accounts'][] = [
        'id' => $treasuryId,
        'name' => $account['account_name'],
        'currency' => $account['currency'],
        'opening_balance' => (float) $account['opening_balance'],
        'live_balance' => $liveBalance,
        'journal_balance' => $journalBalance,
        'cash_bank_ledger_balance' => $ledgerBalance,
        'live_minus_journal' => round($liveBalance - $journalBalance, 2),
        'ledger_minus_journal' => round($ledgerBalance - $journalBalance, 2),
        'journal_totals' => $journal,
        'cash_bank_ledger_rows' => $ledgerCount,
        'supplier_payments_checked' => $supplierCount,
        'supplier_payment_cash_mismatches' => $supplierMismatches,
        'customer_receipts_checked' => $receiptCount,
        'customer_receipt_cash_mismatches' => $receiptMismatches,
        'transfers_checked' => count($transferIds),
        'transfer_journal_failures' => $transferFailures,
        'source_breakdown' => $sourceBreakdown->fetchAll(PDO::FETCH_ASSOC),
        'monthly_journal_movement' => $monthly->fetchAll(PDO::FETCH_ASSOC),
        'latest_cash_journal_lines' => $recent->fetchAll(PDO::FETCH_ASSOC),
        'largest_recent_cash_outflows' => $largestRecentOutflows->fetchAll(PDO::FETCH_ASSOC),
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
