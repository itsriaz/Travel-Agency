<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = (new \App\Repositories\SupplierAccountBalanceRepository($app))->allBranchIds();
$service = new \App\Services\SupplierAccountBalanceService($app);
$reports = new \App\Services\ReportService($app);
$supplier = $db->query("SELECT id FROM suppliers WHERE name = 'AirBlue imdad int' LIMIT 1")->fetchColumn();
if (!$supplier) {
    throw new RuntimeException('AirBlue fixture is missing.');
}
$supplierId = (int) $supplier;
$ledgerSummary = $service->summaries($branches)[$supplierId]['PKR'] ?? null;
if ($ledgerSummary === null) {
    throw new RuntimeException('AirBlue supplier ledger has no PKR position.');
}
$ledgerBalance = $ledgerSummary['net'];
$actor = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
foreach (['prepaid_supplier_ledger', 'supplier_all_payments'] as $report) {
    $state = $reports->reportState([
        'report' => $report,
        'supplier_id' => $supplierId,
        'currency' => 'PKR',
    ], $branches, $actor, 'screen');
    $cards = array_column($state['summaryCards'], 'value', 'label');
    $label = $report === 'prepaid_supplier_ledger'
        ? 'Supplier Account Advance / PKR' : 'Supplier Account Balance / PKR';
    $expectedValue = 'PKR ' . number_format($report === 'prepaid_supplier_ledger'
        ? max(0.0, $ledgerBalance) : abs($ledgerBalance), 0)
        . ($report === 'supplier_all_payments' ? ($ledgerBalance >= 0 ? ' Advance' : ' Payable') : '');
    if (($cards[$label] ?? '') !== $expectedValue) {
        throw new RuntimeException($report . ' balance differs from the supplier ledger: ' . json_encode($cards));
    }
    if (($cards['Supplier Ledger Payments / PKR'] ?? '') !== 'PKR ' . number_format($ledgerSummary['payments'], 0)
        || ($cards['Supplier Ledger Bills / Adjustments / PKR'] ?? '') !== 'PKR ' . number_format($ledgerSummary['bills'], 0)) {
        throw new RuntimeException($report . ' components differ from the supplier ledger: ' . json_encode($cards));
    }
    if ($report === 'prepaid_supplier_ledger') {
        $footer = $state['footerRows'][0] ?? [];
        if (($footer['ledger_payments'] ?? '') !== number_format($ledgerSummary['payments'], 0)
            || ($footer['ledger_bills'] ?? '') !== number_format($ledgerSummary['bills'], 0)
            || ($footer['account_advance'] ?? '') !== number_format(max(0.0, $ledgerBalance), 0)) {
            throw new RuntimeException('Supplier advance footer still differs from ledger summary: ' . json_encode($footer));
        }
        echo '[PASS] Supplier advance bottom totals match its ledger summary, not source-row sums' . PHP_EOL;
    } else {
        $footer = $state['footerRows'][0] ?? [];
        if (($footer['currency'] ?? '') !== 'PKR'
            || ($footer['ledger_payments'] ?? '') !== number_format($ledgerSummary['payments'], 0)
            || ($footer['ledger_bills'] ?? '') !== number_format($ledgerSummary['bills'], 0)
            || ($footer['account_balance'] ?? '') !== number_format(abs($ledgerBalance), 0)
                . ($ledgerBalance >= 0 ? ' Advance' : ' Payable')) {
            throw new RuntimeException('Supplier Payments footer differs from ledger cards: ' . json_encode($footer));
        }
        echo '[PASS] Supplier Payments bottom totals match its ledger cards, not payment-source row sums' . PHP_EOL;
    }
    echo '[PASS] ' . $report . ' matches AirBlue supplier ledger PKR ' . number_format($ledgerBalance, 0) . PHP_EOL;
}
$positions = $service->positions($branches);
$summaries = $service->summaries($branches);
foreach ($summaries as $id => $currencies) {
    foreach ($currencies as $code => $account) {
        if (abs($account['payments'] - $account['bills'] - $account['net']) > 0.005) {
            throw new RuntimeException('Ledger components do not explain supplier ' . $id . ' / ' . $code);
        }
    }
}
echo '[PASS] Every supplier/currency ledger balance equals its payments less bills' . PHP_EOL;
if (abs(($positions[$supplierId]['PKR'] ?? 0) - $ledgerBalance) > 0.005) {
    throw new RuntimeException('Booking lookup source differs from supplier ledger.');
}
echo '[PASS] Booking lookup uses the same AirBlue account position' . PHP_EOL;

$otherSuppliers = ['AIR SIAL IMD INT' => 'PKR', 'AED PKR CHANGE EXCHANGE' => 'AED'];
foreach ($otherSuppliers as $name => $currency) {
    $idStmt = $db->prepare('SELECT id FROM suppliers WHERE name = ?');
    $idStmt->execute([$name]);
    $id = (int) $idStmt->fetchColumn();
    $amount = $summaries[$id][$currency]['net'] ?? 0.0;
    if (abs(($positions[$id][$currency] ?? 0) - $amount) > 0.005) {
        throw new RuntimeException($name . ' cross-supplier position changed unexpectedly.');
    }
    foreach (['prepaid_supplier_ledger', 'supplier_all_payments'] as $report) {
        $state = $reports->reportState([
            'report' => $report, 'supplier_id' => $id, 'currency' => $currency,
        ], $branches, $actor, 'screen');
        $cards = array_column($state['summaryCards'], 'value', 'label');
        $label = ($report === 'prepaid_supplier_ledger'
            ? 'Supplier Account Advance / ' : 'Supplier Account Balance / ') . $currency;
        $expectedValue = $currency . ' ' . number_format($report === 'prepaid_supplier_ledger'
            ? max(0.0, $amount) : abs($amount), 0)
            . ($report === 'supplier_all_payments' ? ($amount >= 0 ? ' Advance' : ' Payable') : '');
        if (($cards[$label] ?? '') !== $expectedValue) {
            throw new RuntimeException($name . ' / ' . $report . ' differs from supplier ledger.');
        }
    }
}
echo '[PASS] Other affected suppliers use their own ledger positions' . PHP_EOL;

$allState = $reports->reportState([
    'report' => 'prepaid_supplier_ledger', 'currency' => 'PKR',
], $branches, $actor, 'screen');
$allFooter = $allState['footerRows'][0] ?? [];
$allAdvance = 0.0;
$drilldownKey = '';
foreach ($allState['supplierAdvanceSummaryRows'] as $row) {
    $allAdvance += (float) str_replace(',', '', (string) ($row['remaining_advance_balance'] ?? '0'));
    if (($row['supplier_name'] ?? '') === 'AirBlue imdad int') {
        $drilldownKey = (string) ($row['summary_drilldown_key'] ?? '');
    }
}
if (abs((float) str_replace(',', '', (string) ($allFooter['account_advance'] ?? '0')) - $allAdvance) > 0.005) {
    throw new RuntimeException('All-supplier footer does not match ledger-derived summary rows.');
}
if ($drilldownKey === '') {
    throw new RuntimeException('AirBlue drilldown key is missing.');
}
$drillState = $reports->reportState([
    'report' => 'prepaid_supplier_ledger', 'currency' => 'PKR',
    'supplier_advance_drilldown' => $drilldownKey,
], $branches, $actor, 'screen');
$drillFooter = $drillState['footerRows'][0] ?? [];
$drillCards = array_column($drillState['summaryCards'], 'value', 'label');
if (($drillFooter['account_advance'] ?? '') !== number_format(max(0.0, $ledgerBalance), 0)
    || ($drillCards['Supplier Account Advance / PKR'] ?? '') !== 'PKR ' . number_format(max(0.0, $ledgerBalance), 0)
    || count($drillState['supplierAdvanceSummaryRows']) !== 1) {
    throw new RuntimeException('Drilldown footer does not match selected supplier account.');
}
echo '[PASS] All-supplier and selected-supplier footers follow the same ledger summaries' . PHP_EOL;

foreach (['only_available', 'fully_used'] as $balanceView) {
    $filtered = $reports->reportState([
        'report' => 'prepaid_supplier_ledger', 'currency' => 'PKR',
        'advance_balance_view' => $balanceView,
    ], $branches, $actor, 'screen');
    $expectedAdvance = 0.0;
    foreach ($filtered['supplierAdvanceSummaryRows'] as $row) {
        $expectedAdvance += (float) str_replace(',', '', (string) ($row['remaining_advance_balance'] ?? '0'));
    }
    $actualAdvance = (float) str_replace(',', '', (string) ($filtered['footerRows'][0]['account_advance'] ?? '0'));
    if (abs($expectedAdvance - $actualAdvance) > 0.005) {
        throw new RuntimeException($balanceView . ' footer does not match its visible account summaries.');
    }
}
echo '[PASS] Available and fully-used filters retain matching account footers' . PHP_EOL;
