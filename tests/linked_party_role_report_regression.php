<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$actorId = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$links = (new \App\Repositories\CounterpartyLinkRepository($app))->listLinks();
$link = null;
foreach ($links as $candidate) {
    if (strcasecmp((string) $candidate['business_source_name'], 'AJWA SWAT SHJ') === 0
        && strcasecmp((string) $candidate['supplier_name'], 'AJWA SWAT TOURISM') === 0) {
        $link = $candidate;
        break;
    }
}
if ($link === null) {
    echo '[SKIP] AJWA linked account/supplier fixture is not present.' . PHP_EOL;
    exit(0);
}

$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};
$cardAmount = static function (array $cards, string $label): ?float {
    foreach ($cards as $card) {
        if ((string) ($card['label'] ?? '') === $label) {
            $value = preg_replace('/[^0-9.\-]/', '', (string) ($card['value'] ?? ''));
            return $value !== '' && is_numeric($value) ? (float) $value : null;
        }
    }
    return null;
};
$render = static function (array $state, string $view): string {
    ob_start();
    (static function (array $viewState, string $viewPath): void {
        extract($viewState);
        require BASE_PATH . '/app/Views/reports/' . $viewPath;
    })($state, $view);
    return (string) ob_get_clean();
};

$positions = (new \App\Services\LinkedPartyPositionService($app))->positions($branchIds);
$position = null;
foreach ($positions as $candidate) {
    if ((int) $candidate['link_id'] === (int) $link['id'] && (string) $candidate['currency'] === 'AED') {
        $position = $candidate;
        break;
    }
}
if ($position === null) {
    throw new RuntimeException('AJWA AED linked position is missing.');
}

$service = new \App\Services\ReportService($app);
$account = $service->reportState([
    'report' => 'customer_ledger',
    'business_source_id' => (int) $link['business_source_id'],
    'currency' => 'AED',
], $branchIds, $actorId, 'screen');
$supplier = $service->reportState([
    'report' => 'supplier_ledger',
    'supplier_id' => (int) $link['supplier_id'],
    'currency' => 'AED',
], $branchIds, $actorId, 'screen');
$accountName = (string) $link['business_source_name'];
$supplierName = (string) $link['supplier_name'];
$expectedAccountBalance = round((float) $position['receivable'] - (float) ($position['account_paid_direct'] ?? 0), 2);
$accountLabel = ($expectedAccountBalance < -0.005 ? 'Payable to ' : 'Receivable from ') . $accountName . ' / AED';
$supplierLabel = 'Payable to ' . $supplierName . ' / AED';
$expectedPayable = (float) $position['payable'];
$net = $position['net_balance'] === null ? null : (float) $position['net_balance'];
$netLabel = \App\Services\LinkedPartyLedgerService::netBalanceLabel(
    $net,
    'AED',
    $accountName,
    $supplierName,
    $expectedAccountBalance,
    $expectedPayable + max(0.0, (float) ($position['on_account_credit'] ?? 0) - (float) ($position['account_paid_direct'] ?? 0))
);
$accountInvoiceOutstanding = array_sum(array_map(
    static fn (array $row): float => (string) ($row['currency'] ?? '') === 'AED'
        ? (float) ($row['raw_outstanding_amount'] ?? 0) : 0.0,
    (new \App\Services\AccountLedgerService($app))->report(
        ['businessSourceId' => (int) $link['business_source_id']], $branchIds
    )['summaryRows']
));

$check('Both role reports detect the link', $account['isLinkedPartyLedger'] === true && $supplier['isLinkedPartyLedger'] === true);
$check('Both role reports subtract direct account payments from the account balance',
    abs(($cardAmount($account['summaryCards'], $accountLabel) ?? -1) - abs($expectedAccountBalance)) < 0.005
    && abs(($cardAmount($supplier['summaryCards'], $accountLabel) ?? -1) - abs($expectedAccountBalance)) < 0.005);
$check('Customer invoice outstanding remains separate from the linked net',
    abs(($cardAmount($account['summaryCards'], 'Invoice Outstanding / AED') ?? -1) - $accountInvoiceOutstanding) < 0.005
    && $cardAmount($account['summaryCards'], 'Current Net Receivable / AED') === null);
$check('Supplier report uses its own AED payable', abs(($cardAmount($supplier['summaryCards'], $supplierLabel) ?? -1) - $expectedPayable) < 0.005);
$check('Both reports show the same separate linked net', $net === null
    ? $cardAmount($account['summaryCards'], $netLabel) === null && $cardAmount($supplier['summaryCards'], $netLabel) === null
    : abs(($cardAmount($account['summaryCards'], $netLabel) ?? -1) - abs($net)) < 0.005
        && abs(($cardAmount($supplier['summaryCards'], $netLabel) ?? -1) - abs($net)) < 0.005);
$linkedCards = array_values(array_filter(
    array_merge($account['summaryCards'], $supplier['summaryCards']),
    static fn (array $card): bool => (string) ($card['group'] ?? '') === 'linked_roles'
));
$check('Linked balance cards show titles and amounts without description text',
    $linkedCards !== []
    && count(array_filter($linkedCards, static fn (array $card): bool =>
        trim((string) ($card['label'] ?? '')) === ''
        || trim((string) ($card['value'] ?? '')) === ''
        || trim((string) ($card['note'] ?? '')) !== '')) === 0);
$accountDebit = array_sum(array_map(static fn (array $row): float => (float) ($row['raw_debit_amount'] ?? 0), $account['rows']));
$accountCredit = array_sum(array_map(static fn (array $row): float => (float) ($row['raw_credit_amount'] ?? 0), $account['rows']));
$supplierDebit = array_sum(array_map(static fn (array $row): float => (float) ($row['raw_debit_amount'] ?? 0), $supplier['rows']));
$supplierCredit = array_sum(array_map(static fn (array $row): float => (float) ($row['raw_credit_amount'] ?? 0), $supplier['rows']));
$check('Both linked ledgers retain debit and credit movements and mirror the same net',
    $accountDebit > 0.005 && $accountCredit > 0.005
    && $supplierDebit > 0.005 && $supplierCredit > 0.005
    && abs($accountDebit - $supplierCredit) < 0.005
    && abs($accountCredit - $supplierDebit) < 0.005
    && abs($accountDebit - $accountCredit - $net) < 0.005);
$check('Both linked ledgers show account invoices and supplier invoices',
    count(array_filter($account['rows'], static fn (array $row): bool =>
        (string) ($row['ledger_entry'] ?? '') === 'Supplier invoice recorded')) > 0
    && count(array_filter($supplier['rows'], static fn (array $row): bool =>
        (string) ($row['entry_type'] ?? '') === 'Air Ticket invoice')) > 0);
$check('Neither report duplicates the linked net as a ledger footer', $account['footerRows'] === [] && $supplier['footerRows'] === []);
$supplierStatementBalance = $cardAmount($supplier['summaryCards'], $netLabel);
$check('Linked supplier summary shows the two role balances and one combined balance',
    count($supplier['summaryCards']) === 3
    && count(array_filter($supplier['summaryCards'], static fn (array $card): bool =>
        (string) ($card['group'] ?? '') === 'linked_roles')) === 3
    && $supplierStatementBalance !== null && abs($supplierStatementBalance - abs($net)) < 0.005);

$linkedLedger = new \App\Services\LinkedPartyLedgerService($app);
$testLinkedBalance = static function (float $debit, float $credit) use ($linkedLedger): array {
    $report = $linkedLedger->buildSupplierReport([[
        'entry_date_raw' => '2026-10-12',
        'currency' => 'AED',
        'ledger_entry' => 'Test balance',
        'is_financial_position_only' => true,
        'raw_debit_amount' => $debit,
        'raw_credit_amount' => $credit,
    ]], [], null, null, 'AED', 'Test supplier');
    return $report['summaryCards'][2] ?? [];
};
$check('Linked supplier balance direction is correct for receivable, payable, and settled',
    (string) ($testLinkedBalance(25, 0)['label'] ?? '') === 'Receivable exceeds payable / AED'
    && (string) ($testLinkedBalance(0, 10)['label'] ?? '') === 'Payable exceeds receivable / AED'
    && (string) ($testLinkedBalance(10, 10)['label'] ?? '') === 'Net balance zero / AED');
$check('Named net card follows the role responsible as balances change',
    \App\Services\LinkedPartyLedgerService::netBalanceLabel(39_325, 'AED', $accountName, $supplierName, 95_355, 56_030)
        === 'Net receivable from ' . $accountName . ' / AED'
    && \App\Services\LinkedPartyLedgerService::netBalanceLabel(-10, 'AED', $accountName, $supplierName, 0, 10)
        === 'Net payable to ' . $supplierName . ' / AED'
    && \App\Services\LinkedPartyLedgerService::netBalanceLabel(-10, 'AED', $accountName, $supplierName, -10, 0)
        === 'Net payable to ' . $accountName . ' / AED'
    && \App\Services\LinkedPartyLedgerService::netBalanceLabel(0, 'AED', $accountName, $supplierName, 10, 10)
        === 'Net balance zero / AED');

$accountScreen = $render($account, 'index.php');
$supplierScreen = $render($supplier, 'index.php');
$layoutState = $account;
$layoutCard = static fn (string $label, string $currency, bool $credit = false): array => [
    'group' => 'linked_roles',
    'group_title' => 'Account: test account | Supplier: test supplier',
    'linked_currency' => $currency,
    'linked_credit' => $credit,
    'label' => $label,
    'value' => $currency . ' 1.00',
];
$layoutState['summaryCards'] = [
    $layoutCard('PKR PAYABLE TEST', 'PKR'),
    $layoutCard('AED NET TEST', 'AED'),
    $layoutCard('PKR NET TEST', 'PKR'),
    $layoutCard('AED RECEIVABLE TEST', 'AED'),
    $layoutCard('PKR RECEIVABLE TEST', 'PKR'),
    $layoutCard('AED PAYABLE TEST', 'AED'),
];
$layoutScreen = $render($layoutState, 'index.php');
$aedRow = strpos($layoutScreen, 'data-linked-currency="AED"');
$pkrRow = strpos($layoutScreen, 'data-linked-currency="PKR"');
$creditRow = strpos($layoutScreen, 'class="stat-grid stat-grid--group report-stat-grid linked-party-credit-row"');
$aedCards = $aedRow !== false && $pkrRow !== false ? substr($layoutScreen, $aedRow, $pkrRow - $aedRow) : '';
$pkrEnd = $pkrRow !== false ? strpos($layoutScreen, '</section>', $pkrRow) : false;
$pkrCards = $pkrRow !== false && $pkrEnd !== false ? substr($layoutScreen, $pkrRow, $pkrEnd - $pkrRow) : '';
$check('Linked cards render AED first, PKR second, and direct receipts only in the net',
    $aedRow !== false && $pkrRow !== false && $creditRow === false
    && $aedRow < $pkrRow
    && substr_count($aedCards, '<article class="stat-card ') === 3
    && substr_count($pkrCards, '<article class="stat-card ') === 3
    && str_contains($aedCards, 'AED RECEIVABLE TEST')
    && str_contains($pkrCards, 'PKR PAYABLE TEST'));
$supplierLayoutState = $supplier;
$supplierLayoutState['summaryCards'] = $layoutState['summaryCards'];
$supplierLayoutScreen = $render($supplierLayoutState, 'index.php');
$supplierAedRow = strpos($supplierLayoutScreen, 'data-linked-currency="AED"');
$supplierPkrRow = strpos($supplierLayoutScreen, 'data-linked-currency="PKR"');
$supplierAedEnd = $supplierPkrRow;
$supplierPkrEnd = $supplierPkrRow !== false ? strpos($supplierLayoutScreen, '</section>', $supplierPkrRow) : false;
$supplierAedCards = $supplierAedRow !== false && $supplierAedEnd !== false
    ? substr($supplierLayoutScreen, $supplierAedRow, $supplierAedEnd - $supplierAedRow) : '';
$supplierPkrCards = $supplierPkrRow !== false && $supplierPkrEnd !== false
    ? substr($supplierLayoutScreen, $supplierPkrRow, $supplierPkrEnd - $supplierPkrRow) : '';
$check('Linked supplier summary places only role balances in separate AED and PKR rows',
    $supplierAedRow !== false && $supplierPkrRow !== false && $supplierAedEnd !== false && $supplierPkrEnd !== false
    && $supplierAedRow < $supplierAedEnd && $supplierAedEnd === $supplierPkrRow
    && substr_count($supplierAedCards, '<article class="stat-card ') === 3
    && substr_count($supplierPkrCards, '<article class="stat-card ') === 3
    && ! str_contains($supplierAedCards, 'PKR')
    && ! str_contains($supplierPkrCards, 'AED'));
$accountPrint = $service->reportState([
    'report' => 'customer_ledger',
    'business_source_id' => (int) $link['business_source_id'],
    'currency' => 'AED',
], $branchIds, $actorId, 'print');
$accountPrint['title'] = 'Account Ledger';
$supplierPrint = $service->reportState([
    'report' => 'supplier_ledger',
    'supplier_id' => (int) $link['supplier_id'],
    'currency' => 'AED',
], $branchIds, $actorId, 'print');
$check('Screen views identify each role by name', str_contains($accountScreen, $accountLabel)
    && str_contains($supplierScreen, $supplierLabel));
$check('Linked account ledger uses simple Debit and Credit headings',
    str_contains($accountScreen, 'Voucher /<br>Receipt')
    && in_array('Debit', array_column($account['columns'], 'label'), true)
    && in_array('Credit', array_column($account['columns'], 'label'), true)
    && ! in_array('Debit / Money In', array_column($account['columns'], 'label'), true));
$check('Supplier ledger uses content-width columns in the screen view',
    str_contains($supplierScreen, 'supplier-ledger-trace-table')
    && str_contains($supplierScreen, 'width: max-content;')
    && str_contains($supplierScreen, '[data-column-key="supplier_name"]')
    && str_contains($supplierScreen, '[data-column-key="trace"]'));
$check('Linked account summary headers remain horizontal',
    str_contains($accountScreen, 'Account Summary')
    && str_contains($accountScreen, 'account-ledger-summary-wrap linked-account-summary-wrap')
    && str_contains($accountScreen, 'account-ledger-summary-table linked-account-summary-table')
    && str_contains($accountScreen, '.account-ledger-summary-table.linked-account-summary-table {')
    && str_contains($accountScreen, 'data-column-key="outstanding_amount"')
    && ! str_contains($accountScreen, 'account-ledger-vertical-heading')
    && ! str_contains($accountScreen, 'writing-mode: vertical-rl'));
$accountPrintHtml = $render($accountPrint, 'account_ledger_print.php');
$supplierPrintHtml = $render($supplierPrint, 'report_print.php');
$check('Print views identify each role by name', str_contains($accountPrintHtml, $accountLabel)
    && str_contains($supplierPrintHtml, $supplierLabel));
$check('Printed tables size every account and supplier column to the page',
    substr_count($accountPrintHtml, '<col style="width: ') === count($accountPrint['columns'])
    && substr_count($supplierPrintHtml, '<col style="width: ') === count($supplierPrint['columns'])
    && str_contains($accountPrintHtml, 'size: A3 landscape;')
    && str_contains($supplierPrintHtml, 'size: A4 landscape;'));
$check('Supplier print includes the trace column shown on screen',
    in_array('trace', array_column($supplierPrint['columns'], 'key'), true)
    && str_contains($supplierPrintHtml, '<th>Trace</th>')
    && str_contains($supplierPrintHtml, 'supplier-ledger-trace-table'));

if ($failures !== []) {
    fwrite(STDERR, 'Linked-party role report regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'Linked-party role report regression passed; no records were changed.' . PHP_EOL;
