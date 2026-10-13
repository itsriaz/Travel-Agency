<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\ReportRepository;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$reports = new ReportRepository($app);
$expected = [
    'RCPT-000873' => ['booking' => 'BK-000819', 'amount' => 40000],
    'RCPT-000926' => ['booking' => 'BK-000819', 'amount' => 45000],
    'RCPT-000938' => ['booking' => 'BK-000732', 'amount' => 86000],
];

try {
    $receiptQuery = $db->prepare(
        'SELECT cr.id, cr.receipt_no, cr.booking_reference, cr.branch_id AS booking_branch_id,
                cr.receipt_date, cr.currency, cr.received_amount, cr.allocated_amount,
                cr.treasury_account_id, cr.status, ta.branch_id AS account_branch_id,
                ta.linked_account_id, ta.account_name
         FROM customer_receipts cr
         INNER JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
         WHERE cr.receipt_no = :receipt_no'
    );
    $journalQuery = $db->prepare(
        'SELECT ROUND(COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0), 2)
         FROM journal_entry_lines jel
         INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
         WHERE jel.customer_receipt_id = :receipt_id
           AND jel.account_id = :account_id
           AND je.currency = "PKR"'
    );
    $allocationQuery = $db->prepare(
        'SELECT cra.id, cra.payment_currency, cra.receivable_currency,
                cra.payment_amount_consumed, cra.receivable_amount_allocated,
                cri.booking_reference, cri.currency, cri.due_amount,
                cri.allocated_amount, cri.outstanding_amount, cri.status
         FROM customer_receipt_allocations cra
         INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
         WHERE cra.customer_receipt_id = :receipt_id'
    );
    $journalDetailQuery = $db->prepare(
        'SELECT je.id, je.branch_id, je.source_type, je.currency, coa.code,
                jel.debit_amount, jel.credit_amount
         FROM journal_entry_lines jel
         INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE jel.customer_receipt_id = :receipt_id
         ORDER BY je.id, jel.id'
    );
    $auditQuery = $db->prepare(
        'SELECT id FROM audit_logs
         WHERE event_name = "customer.receipt.recorded"
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.receipt_no")) = :receipt_no
           AND JSON_EXTRACT(payload_json, "$.treasury_account_id") = 33'
    );
    $eventQuery = $db->prepare(
        'SELECT COUNT(*) FROM booking_service_events
         WHERE booking_reference = :booking_reference AND event_type = "cancel"'
    );
    $results = [];
    foreach ($expected as $receiptNo => $truth) {
        $receiptQuery->execute(['receipt_no' => $receiptNo]);
        $receipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);
        if (!$receipt
            || $receipt['booking_reference'] !== $truth['booking']
            || (int) $receipt['booking_branch_id'] !== 2
            || (int) $receipt['account_branch_id'] !== 1
            || (int) $receipt['treasury_account_id'] !== 33
            || $receipt['currency'] !== 'PKR'
            || $receipt['status'] === 'void'
            || abs((float) $receipt['received_amount'] - $truth['amount']) > 0.005
            || abs((float) $receipt['allocated_amount'] - $truth['amount']) > 0.005
        ) {
            throw new RuntimeException($receiptNo . ' source receipt, allocation, or selected treasury account differs from the reviewed facts.');
        }

        $journalQuery->execute([
            'receipt_id' => $receipt['id'],
            'account_id' => $receipt['linked_account_id'],
        ]);
        $cashNet = (float) $journalQuery->fetchColumn();
        $allocationQuery->execute(['receipt_id' => $receipt['id']]);
        $allocations = $allocationQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $journalDetailQuery->execute(['receipt_id' => $receipt['id']]);
        $journalLines = $journalDetailQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $auditQuery->execute(['receipt_no' => $receiptNo]);
        $auditCount = count($auditQuery->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $eventQuery->execute(['booking_reference' => $truth['booking']]);
        $cancellationCount = (int) $eventQuery->fetchColumn();
        $journalGroups = [];
        foreach ($journalLines as $line) {
            $journalId = (int) $line['id'];
            $journalGroups[$journalId]['branch_id'] = (int) $line['branch_id'];
            $journalGroups[$journalId]['currency'] = (string) $line['currency'];
            $journalGroups[$journalId]['source_type'] = (string) $line['source_type'];
            $journalGroups[$journalId]['debit'] = ($journalGroups[$journalId]['debit'] ?? 0)
                + (float) $line['debit_amount'];
            $journalGroups[$journalId]['credit'] = ($journalGroups[$journalId]['credit'] ?? 0)
                + (float) $line['credit_amount'];
        }
        $journalTypes = array_column(array_values($journalGroups), 'source_type');
        $date = (string) $receipt['receipt_date'];
        $swatRows = array_values(array_filter(
            $reports->cashBankLedger([1], $date, $date, 'PKR', 'customer', null, 33),
            static fn (array $row): bool => ($row['reference'] ?? '') === $receiptNo
        ));
        $nobleRows = array_values(array_filter(
            $reports->cashBankLedger([2], $date, $date, 'PKR', 'customer'),
            static fn (array $row): bool => ($row['reference'] ?? '') === $receiptNo
        ));
        if (count($allocations) !== 1
            || $allocations[0]['booking_reference'] !== $truth['booking']
            || $allocations[0]['payment_currency'] !== 'PKR'
            || $allocations[0]['receivable_currency'] !== 'PKR'
            || abs((float) $allocations[0]['payment_amount_consumed'] - $truth['amount']) > 0.005
            || abs((float) $allocations[0]['receivable_amount_allocated'] - $truth['amount']) > 0.005
            || $allocations[0]['status'] !== 'paid'
            || abs((float) $allocations[0]['outstanding_amount']) > 0.005
            || $auditCount !== 1 || $cancellationCount !== 0
            || count($journalGroups) !== 2
            || $journalTypes !== ['customer_receipt_recorded', 'customer_receipt_allocated']
            || count($journalLines) !== 4
            || array_filter($journalGroups, static fn (array $group): bool =>
                $group['branch_id'] !== 2 || $group['currency'] !== 'PKR'
                || abs($group['debit'] - $group['credit']) > 0.005) !== []
            || abs($cashNet - $truth['amount']) > 0.005
            || count($swatRows) !== 1
            || count($nobleRows) !== 0
            || (int) $swatRows[0]['branch_id'] !== 1
            || abs((float) $swatRows[0]['debit_amount'] - $truth['amount']) > 0.005
            || abs((float) $swatRows[0]['credit_amount']) > 0.005
            || !str_contains((string) $swatRows[0]['description'], 'Booking branch:')
        ) {
            throw new RuntimeException($receiptNo . ' receipt, allocation, journal, audit, or Swat cash ledger does not reconcile.');
        }
        $results[] = [
            'receipt_no' => $receiptNo,
            'booking_reference' => $truth['booking'],
            'booking_branch_id' => 2,
            'cash_account' => (string) $receipt['account_name'],
            'cash_branch_id' => 1,
            'currency' => 'PKR',
            'cash_in' => $truth['amount'],
            'swat_ledger_rows' => 1,
            'noble_ledger_rows' => 0,
        ];
    }
    echo json_encode([
        'inspection_only' => true,
        'status' => 'PASS',
        'receipts' => $results,
        'total_cash_in_pkr' => array_sum(array_column($results, 'cash_in')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
