<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};
$signedCard = static function (array $cards, string $prefix, string $currency): ?float {
    foreach ($cards as $card) {
        $label = (string) ($card['label'] ?? '');
        if (! str_starts_with($label, $prefix) || ! str_ends_with($label, ' / ' . $currency)) {
            continue;
        }
        $value = (string) ($card['value'] ?? '');
        if (! preg_match('/([0-9][0-9,]*\.[0-9]{2})/', $value, $matches)) {
            return null;
        }
        $amount = (float) str_replace(',', '', $matches[1]);
        return stripos($label, 'payable') !== false ? -$amount : $amount;
    }
    return null;
};

$balanceService = new \App\Services\AccountBalanceSummaryService();
$fixtureRows = [
    ['business_source_id' => 1, 'currency' => 'AED', 'raw_outstanding_amount' => 70],
    ['business_source_id' => 1, 'currency' => 'AED', 'raw_outstanding_amount' => 30],
    ['business_source_id' => 1, 'currency' => 'PKR', 'raw_outstanding_amount' => 1000],
    ['business_source_id' => 2, 'currency' => 'AED', 'raw_outstanding_amount' => 50],
    ['business_source_id' => 3, 'currency' => 'AED', 'raw_outstanding_amount' => 20],
    ['business_source_id' => 4, 'currency' => 'AED', 'raw_outstanding_amount' => 0],
];
$fixtureLinks = [
    ['id' => 101, 'business_source_id' => 1],
    ['id' => 303, 'business_source_id' => 3],
    ['id' => 404, 'business_source_id' => 4],
];
$fixtureLinked = [
    ['link_id' => 101, 'currency' => 'AED', 'net_balance' => 40],
    ['link_id' => 101, 'currency' => 'PKR', 'net_balance' => -200],
    ['link_id' => 303, 'currency' => 'AED', 'net_balance' => -10],
];
$positions = $balanceService->positions($fixtureRows, $fixtureLinks, $fixtureLinked);
$allCards = $balanceService->cards($positions, 0);
$oneCards = $balanceService->cards($positions, 1);
$threeCards = $balanceService->cards($positions, 3);
$check('Each account is valued once before all-account aggregation',
    $signedCard($allCards, 'Total Current Net ', 'AED') === 80.0
    && $signedCard($oneCards, 'Current Net ', 'AED') === 40.0
    && $signedCard($threeCards, 'Current Net ', 'AED') === -10.0);
$check('Currencies and negative linked balances remain separate',
    $signedCard($allCards, 'Total Current Net ', 'PKR') === -200.0
    && $signedCard($balanceService->cards($positions, 1, 'PKR'), 'Current Net ', 'PKR') === -200.0
    && count($balanceService->cards($positions, 1, 'AED')) === 1);
$check('A zero helper row never invents a missing linked currency',
    count($balanceService->cards($positions, 4)) === 0);
$missing = $balanceService->positions(
    [['business_source_id' => 4, 'currency' => 'AED', 'raw_outstanding_amount' => 5]],
    $fixtureLinks,
    []
);
$check('Missing linked evidence is unavailable, never replaced by gross invoices',
    ($balanceService->cards($missing, 4)[0]['value'] ?? '') === 'Unavailable'
    && ($balanceService->cards($missing, 0)[0]['value'] ?? '') === 'Unavailable');

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$actorId = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$links = (new \App\Repositories\CounterpartyLinkRepository($app))->listLinks();
$ajwa = null;
foreach ($links as $link) {
    if (strcasecmp((string) $link['business_source_name'], 'AJWA SWAT SHJ') === 0
        && strcasecmp((string) $link['supplier_name'], 'AJWA SWAT TOURISM') === 0) {
        $ajwa = $link;
        break;
    }
}

if ($ajwa !== null && $branchIds !== [] && $actorId > 0) {
    $db->beginTransaction();
    try {
        $linkedPositions = (new \App\Services\LinkedPartyPositionService($app))->positions($branchIds, true);
        $sourceRows = (new \App\Services\AccountLedgerService($app))->report([], $branchIds)['summaryRows'];
        $invoiceOutstanding = static function (array $rows, int $accountId = 0): float {
            $total = 0.0;
            foreach ($rows as $row) {
                if ((string) ($row['currency'] ?? '') !== 'AED'
                    || ($accountId > 0 && (int) ($row['business_source_id'] ?? 0) !== $accountId)) {
                    continue;
                }
                $total += (float) ($row['raw_outstanding_amount'] ?? 0);
            }
            return round($total, 2);
        };
        $ajwaInvoice = $invoiceOutstanding($sourceRows, (int) $ajwa['business_source_id']);
        $allInvoices = $invoiceOutstanding($sourceRows);
        $allReport = (new \App\Services\ReportService($app))->reportState(
            ['report' => 'customer_ledger'], $branchIds, $actorId, 'screen'
        );
        $accountReport = (new \App\Services\ReportService($app))->reportState(
            ['report' => 'customer_ledger', 'business_source_id' => (int) $ajwa['business_source_id']],
            $branchIds, $actorId, 'screen'
        );
        $ajwaNet = null;
        foreach ($linkedPositions as $linked) {
            if ((int) $linked['link_id'] === (int) $ajwa['id'] && (string) $linked['currency'] === 'AED') {
                $ajwaNet = $linked['net_balance'];
                break;
            }
        }
        $linkedNetLabel = \App\Services\LinkedPartyLedgerService::netBalanceLabel(
            $ajwaNet,
            'AED',
            (string) $ajwa['business_source_name'],
            (string) $ajwa['supplier_name']
        );
        $check('Selected AJWA customer invoices stay open beside the separate linked net',
            $ajwaNet !== null
            && abs(($signedCard($accountReport['summaryCards'], 'Invoice Outstanding', 'AED') ?? INF) - $ajwaInvoice) < 0.005
            && abs(($signedCard($accountReport['summaryCards'], $linkedNetLabel, 'AED') ?? INF) - (float) $ajwaNet) < 0.005
            && $signedCard($accountReport['summaryCards'], 'Current Net ', 'AED') === null);
        $printReport = (new \App\Services\ReportService($app))->reportState(
            ['report' => 'customer_ledger', 'business_source_id' => (int) $ajwa['business_source_id']],
            $branchIds, $actorId, 'print'
        );
        $check('Printable customer invoice and linked net match the screen',
            $signedCard($printReport['summaryCards'], 'Invoice Outstanding', 'AED')
                === $signedCard($accountReport['summaryCards'], 'Invoice Outstanding', 'AED')
            && $signedCard($printReport['summaryCards'], $linkedNetLabel, 'AED')
                === $signedCard($accountReport['summaryCards'], $linkedNetLabel, 'AED'));
        $check('All-accounts outstanding is the sum of customer invoices, not linked balances',
            abs(($signedCard($allReport['summaryCards'], 'Total Customer Invoice Outstanding', 'AED') ?? INF)
                - $allInvoices) < 0.005
            && $signedCard($allReport['summaryCards'], 'Total Current Net ', 'AED') === null);
        $aedOnly = (new \App\Services\ReportService($app))->reportState(
            ['report' => 'customer_ledger', 'currency' => 'AED'], $branchIds, $actorId, 'screen'
        );
        $check('Currency filtering keeps AED customer outstanding and hides all PKR cards',
            $signedCard($aedOnly['summaryCards'], 'Total Customer Invoice Outstanding', 'AED')
                === $signedCard($allReport['summaryCards'], 'Total Customer Invoice Outstanding', 'AED')
            && count(array_filter($aedOnly['summaryCards'], static fn (array $card): bool =>
                str_ends_with((string) ($card['label'] ?? ''), ' / PKR'))) === 0);
        $filtered = (new \App\Services\ReportService($app))->reportState(
            ['report' => 'customer_ledger', 'business_source_id' => (int) $ajwa['business_source_id'],
                'date_from' => date('Y-m-d')],
            $branchIds, $actorId, 'screen'
        );
        $check('A detail date filter does not turn current customer invoices into a partial balance',
            $signedCard($filtered['summaryCards'], 'Invoice Outstanding', 'AED')
                === $signedCard($accountReport['summaryCards'], 'Invoice Outstanding', 'AED'));
        $filteredBalanceCards = array_values(array_filter($filtered['summaryCards'], static fn (array $card): bool =>
            str_starts_with((string) ($card['label'] ?? ''), 'Invoice Outstanding / ')
                && str_ends_with((string) ($card['label'] ?? ''), ' / AED')));
        $check('Filtered detail explicitly identifies customer invoice balance as current',
            count($filteredBalanceCards) === 1
            && str_contains((string) ($filteredBalanceCards[0]['note'] ?? ''), 'detail filters only narrow the rows'));
        $branchInvoiceTotal = 0.0;
        $branchBalancesMatch = true;
        foreach ($branchIds as $branchId) {
            $branchInvoice = $invoiceOutstanding((new \App\Services\AccountLedgerService($app))->report(
                ['businessSourceId' => (int) $ajwa['business_source_id']], [$branchId]
            )['summaryRows']);
            $branchReport = (new \App\Services\ReportService($app))->reportState(
                ['report' => 'customer_ledger', 'business_source_id' => (int) $ajwa['business_source_id'],
                    'branch_id' => $branchId],
                $branchIds, $actorId, 'screen'
            );
            $branchCard = $signedCard($branchReport['summaryCards'], 'Invoice Outstanding', 'AED');
            $branchBalancesMatch = $branchBalancesMatch && ($branchInvoice > 0.005
                ? $branchCard !== null && abs($branchCard - $branchInvoice) < 0.005
                : $branchCard === null || abs($branchCard) < 0.005);
            $branchInvoiceTotal = round($branchInvoiceTotal + $branchInvoice, 2);
        }
        $check('Branch-specific customer invoices combine without using supplier payables',
            $branchBalancesMatch && abs($branchInvoiceTotal - $ajwaInvoice) < 0.005);
    } finally {
        $db->rollBack();
    }
} else {
    echo '[SKIP] AJWA integration fixture is not present; pure balance tests still ran.' . PHP_EOL;
}

if ($failures !== []) {
    throw new RuntimeException(implode('; ', $failures));
}
echo 'Account balance summary regression passed; integration checks rolled back.' . PHP_EOL;
