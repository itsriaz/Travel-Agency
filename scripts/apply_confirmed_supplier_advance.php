<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$options = getopt('', ['supplier-id:', 'currency:', 'actor-id:', 'expected-open:', 'expected-credit:', 'apply']);
$supplierId = (int) ($options['supplier-id'] ?? 0);
$currency = strtoupper(trim((string) ($options['currency'] ?? '')));
$actorId = (int) ($options['actor-id'] ?? 0);
$expectedCredit = round((float) ($options['expected-credit'] ?? 0), 2);
$apply = array_key_exists('apply', $options);
$expectedOpen = [];
foreach (explode(',', (string) ($options['expected-open'] ?? '')) as $item) {
    if (! preg_match('/^(\d+):(\d+(?:\.\d{1,2})?)$/', trim($item), $matches)) {
        fwrite(STDERR, "Expected open amounts must be obligation-ID:amount pairs.\n");
        exit(2);
    }
    $expectedOpen[(int) $matches[1]] = round((float) $matches[2], 2);
}
if ($supplierId <= 0 || $actorId <= 0 || ! in_array($currency, ['PKR', 'AED', 'USD'], true)
    || $expectedCredit <= 0 || $expectedOpen === []) {
    fwrite(STDERR, "Supplier, currency, actor, expected open amounts, and expected credit are required.\n");
    exit(2);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
if ($apply && (PHP_OS_FAMILY !== 'Windows' || strtolower((string) config('app.env', 'local')) !== 'local')) {
    fwrite(STDERR, "Write mode is local-only. Production requires a separately reviewed action.\n");
    exit(2);
}
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();
try {
    $branchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $openRows = (new \App\Repositories\SupplierRepository($app))
        ->openObligationsForAdvanceReconciliation($branchIds, $supplierId, $currency);
    $actualOpen = [];
    foreach ($openRows as $row) {
        $actualOpen[(int) $row['id']] = round((float) $row['net_payable_amount'], 2);
    }
    ksort($actualOpen);
    ksort($expectedOpen);
    if ($actualOpen !== $expectedOpen) {
        throw new RuntimeException('Open payables changed: expected ' . json_encode($expectedOpen) . ', found ' . json_encode($actualOpen));
    }
    $statement = $db->prepare('SELECT COALESCE(SUM(available_amount), 0)
        FROM supplier_advances WHERE supplier_id = :supplier_id AND currency = :currency');
    $statement->execute(['supplier_id' => $supplierId, 'currency' => $currency]);
    $creditBefore = round((float) $statement->fetchColumn(), 2);
    if (abs($creditBefore - $expectedCredit) > 0.005) {
        throw new RuntimeException('Available supplier credit changed before the approved application.');
    }
    $approvedTotal = round(array_sum($expectedOpen), 2);
    if ($approvedTotal > $creditBefore + 0.005) {
        throw new RuntimeException('Available supplier credit does not cover the approved payables.');
    }
    $receiptsBefore = $db->query('SELECT COUNT(*), COALESCE(SUM(received_amount), 0) FROM customer_receipts')->fetch(PDO::FETCH_NUM);
    $treasuryBefore = $db->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM treasury_transactions')->fetch(PDO::FETCH_NUM);
    $journalBefore = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();

    echo 'Approved supplier #' . $supplierId . ' / ' . $currency . ': apply '
        . number_format($approvedTotal, 2) . ' to ' . json_encode($expectedOpen)
        . ' from available ' . number_format($creditBefore, 2) . PHP_EOL;
    if (! $apply) {
        $db->rollBack();
        echo "Dry run only; no records changed.\n";
        exit(0);
    }

    $result = (new \App\Services\SupplierSettlementWorkspaceService($app))
        ->applyAvailableSupplierAdvancesToOpenPayables($branchIds, $actorId, $supplierId, $currency);
    if (abs((float) ($result['applied_amount'] ?? 0) - $approvedTotal) > 0.005
        || (int) ($result['application_count'] ?? 0) !== count($expectedOpen)) {
        throw new RuntimeException('The advance service did not apply precisely the approved amount and booking count.');
    }
    $actualResults = [];
    foreach ($result['obligations'] ?? [] as $row) {
        $actualResults[(int) $row['supplier_obligation_id']] = round((float) $row['applied_amount_delta'], 2);
        if (abs((float) $row['remaining_payable']) > 0.005) {
            throw new RuntimeException('An approved payable was not fully covered.');
        }
    }
    ksort($actualResults);
    if ($actualResults !== $expectedOpen) {
        throw new RuntimeException('The credit was applied to a different payable or amount.');
    }
    $statement->execute(['supplier_id' => $supplierId, 'currency' => $currency]);
    $creditAfter = round((float) $statement->fetchColumn(), 2);
    if (abs($creditAfter - ($creditBefore - $approvedTotal)) > 0.005) {
        throw new RuntimeException('Remaining supplier credit differs from the approved calculation.');
    }
    $receiptsAfter = $db->query('SELECT COUNT(*), COALESCE(SUM(received_amount), 0) FROM customer_receipts')->fetch(PDO::FETCH_NUM);
    $treasuryAfter = $db->query('SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM treasury_transactions')->fetch(PDO::FETCH_NUM);
    if ($receiptsBefore !== $receiptsAfter || $treasuryBefore !== $treasuryAfter) {
        throw new RuntimeException('Customer receipts or treasury transactions changed unexpectedly.');
    }
    $statement = $db->prepare('SELECT COALESCE(SUM(jel.debit_amount), 0) debit_total,
            COALESCE(SUM(jel.credit_amount), 0) credit_total
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.id > :after_id AND je.source_type = "supplier_advance_applied"');
    $statement->execute(['after_id' => $journalBefore]);
    $journalTotals = $statement->fetch(PDO::FETCH_ASSOC);
    if (abs((float) $journalTotals['debit_total'] - $approvedTotal) > 0.005
        || abs((float) $journalTotals['credit_total'] - $approvedTotal) > 0.005) {
        throw new RuntimeException('The new advance journals do not equal the approved amount on both sides.');
    }
    \App\Helpers\AuditLog::recordRequired($app, 'supplier.advance.confirmed_application', [
        'user_id' => $actorId, 'supplier_id' => $supplierId, 'currency' => $currency,
        'approved_obligations' => $expectedOpen, 'applied_total' => $approvedTotal,
        'available_before' => $creditBefore, 'available_after' => $creditAfter,
    ]);
    $db->commit();
    echo 'Applied ' . number_format($approvedTotal, 2) . '; available credit now '
        . number_format($creditAfter, 2) . '. Customer receipts and treasury unchanged.' . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
