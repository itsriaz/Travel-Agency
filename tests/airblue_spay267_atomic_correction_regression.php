<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Services\SupplierLedgerService;

try {
    $app = App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $baseline = json_decode((string) file_get_contents(
        BASE_PATH . '/docs/verification/airblue_spay267_correction_before_20261026.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    };
    $scalar = static function (string $sql) use ($db): float {
        return (float) $db->query($sql)->fetchColumn();
    };
    $equal = static function (float $actual, float $expected, string $name) use ($assert): void {
        $assert(abs($actual - $expected) < 0.005,
            $name . ': expected ' . $expected . ', got ' . $actual);
    };

    $payment = $db->query('SELECT paid_amount, allocated_amount, converted_advance_amount, status
        FROM supplier_payments WHERE id = 279')->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($payment) && $payment['status'] === 'fully_allocated', 'Corrected payment is missing or void.');
    $equal((float) $payment['paid_amount'], 1090171, 'SPAY-000267 paid');
    $equal((float) $payment['allocated_amount'], 1089899, 'Direct ticket allocations');
    $equal((float) $payment['converted_advance_amount'], 272, 'Converted advance');
    $equal((float) $payment['paid_amount'],
        (float) $payment['allocated_amount'] + (float) $payment['converted_advance_amount'],
        'Payment conservation');

    $overlapRepairCount = (int) $scalar('SELECT COUNT(*) FROM journal_entries WHERE
        source_reference IN ("SADV-OVERLAP-REPAIR-649", "SADV-OVERLAP-REPAIR-888")');
    $assert(in_array($overlapRepairCount, [0, 2], true), 'The later overlap repair is partially posted.');
    $advance36 = $overlapRepairCount === 2 ? [400000, 203232, 196768] : [400000, 314440, 85560];
    foreach ([36 => $advance36, 69 => [272, 272, 0]] as $id => [$deposit, $used, $available]) {
        $row = $db->query('SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = ' . $id)
            ->fetch(PDO::FETCH_ASSOC);
        $assert(is_array($row), 'Advance #' . $id . ' is missing.');
        $equal((float) $row['deposit_amount'], $deposit, 'Advance #' . $id . ' deposit');
        $equal((float) $row['available_amount'], $available, 'Advance #' . $id . ' available');
        $equal($scalar('SELECT COALESCE(SUM(applied_amount),0) FROM supplier_advance_applications
            WHERE supplier_advance_id = ' . $id), $used, 'Advance #' . $id . ' applications');
        $equal($deposit, $used + $available, 'Advance #' . $id . ' conservation');
    }
    $equal($scalar('SELECT COUNT(*) FROM supplier_advance_applications WHERE id = 94'), 0,
        'Duplicate BK-000297 application');
    foreach ([293, 791, 865, 1070, 1076, 1109, 1137] as $id) {
        $equal($scalar('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $id), 0,
            'Affected obligation #' . $id . ' payable');
    }
    $equal($scalar('SELECT advance_applied_amount FROM supplier_obligations WHERE id = 293'), 0,
        'BK-000297 duplicate advance coverage');
    $equal($scalar('SELECT applied_amount FROM supplier_advance_applications WHERE id = 91'), 71004,
        'BK-000862 merged application');
    $equal($scalar('SELECT applied_amount FROM supplier_advance_applications WHERE id = 162'), 272,
        'BK-000787 retained application');

    $service = new SupplierLedgerService($app);
    foreach (['branch_1' => [1], 'branch_2' => [2], 'all_branches' => [1, 2]] as $key => $branches) {
        $rows = $service->report(['supplierId' => 349, 'currency' => 'PKR'], $branches)['rows'];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($rows as $row) {
            $debit += (float) ($row['raw_debit_amount'] ?? 0);
            $credit += (float) ($row['raw_credit_amount'] ?? 0);
        }
        $expected = $baseline['supplier_ledger'][$key];
        $equal($debit, (float) $expected['debit_total'], $key . ' supplier debit');
        $equal($credit, (float) $expected['credit_total'], $key . ' supplier credit');
        $equal($credit - $debit, (float) $expected['net_advance'], $key . ' supplier ending balance');
        $assert(count($rows) === (int) $expected['row_count'] + ($key === 'branch_2' ? 0 : 1),
            $key . ' supplier row count differs beyond the one reversal row.');
    }
    echo "PASS: AirBlue supplier ledger movements and ending balances match the before snapshot in both branches.\n";

    $customer = $db->query('SELECT branch_id, currency, COUNT(*) AS row_count,
        SUM(due_amount) AS due_amount, SUM(allocated_amount) AS allocated_amount,
        SUM(outstanding_amount) AS outstanding_amount
        FROM customer_receivable_items GROUP BY branch_id, currency ORDER BY branch_id, currency')
        ->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($customer) === count($baseline['customer_receivables']),
        'Customer receivable branch/currency groups changed.');
    foreach ($customer as $index => $row) {
        $expected = $baseline['customer_receivables'][$index];
        $assert((int) $row['branch_id'] === (int) $expected['branch_id']
            && $row['currency'] === $expected['currency']
            && (int) $row['row_count'] === (int) $expected['row_count'],
            'Customer receivable scope or row count changed.');
        foreach (['due_amount', 'allocated_amount', 'outstanding_amount'] as $field) {
            $equal((float) $row[$field], (float) $expected[$field],
                'Customer branch ' . $row['branch_id'] . ' ' . $row['currency'] . ' ' . $field);
        }
    }
    echo "PASS: Customer receivable rows and amounts are unchanged in every branch and currency.\n";

    $cash = $db->query('SELECT COUNT(*) AS row_count, SUM(jel.debit_amount) AS debit,
        SUM(jel.credit_amount) AS credit
        FROM journal_entry_lines jel JOIN journal_entries je ON je.id = jel.journal_entry_id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE coa.code = "CASH_CASH_SWT_PKR_PKR" AND je.branch_id = 1 AND je.currency = "PKR"')
        ->fetch(PDO::FETCH_ASSOC);
    $equal((float) $cash['row_count'], (float) $baseline['cash_swt_pkr']['row_count'] + 1,
        'Cash journal row count');
    $equal((float) $cash['debit'], (float) $baseline['cash_swt_pkr']['debit'] + 75277,
        'Cash debit movement');
    $equal((float) $cash['credit'], (float) $baseline['cash_swt_pkr']['credit'],
        'Cash credit movement');
    echo "PASS: Cash-SWT-PKR rises by exactly PKR 75,277; no extra cash outflow.\n";

    $equal($scalar('SELECT COUNT(*) FROM journal_entries WHERE source_reference LIKE
        "AIRBLUE-SPAY267-75277-ATOMIC-%"'), 15, 'Correction journal count');
    $equal($scalar('SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.source_reference LIKE "AIRBLUE-SPAY267-75277-ATOMIC-%"
        GROUP BY je.id HAVING ABS(SUM(jel.debit_amount - jel.credit_amount)) > 0.005) AS broken'),
        0, 'Correction journal balance');
    $equal($scalar('SELECT COUNT(*) FROM audit_logs WHERE event_name =
        "supplier.payment.atomic_historical_correction" AND
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.reference")) = "AIRBLUE-SPAY267-75277-ATOMIC"'),
        1, 'Required correction audit');
    echo "PASS: Fifteen balanced correction journals and one required audit record.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
