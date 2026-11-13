<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$actor = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$link = (new \App\Repositories\CounterpartyLinkRepository($app))->findByBusinessSourceId(12);
if ($link === null || (int) $link['supplier_id'] !== 412 || $branches === [] || $actor <= 0) {
    throw new RuntimeException('AJWA linked account test fixture is unavailable.');
}

$report = new \App\Services\ReportService($app);
$aging = $report->reportState(['report' => 'receivable_aging', 'business_source_id' => 12], $branches, $actor);
$ledger = $report->reportState(['report' => 'supplier_ledger', 'supplier_id' => 412], $branches, $actor);
$linkedCards = static function (array $state): array {
    $result = [];
    foreach ($state['summaryCards'] as $card) {
        if (($card['group'] ?? '') === 'linked_roles' && ($card['linked_currency'] ?? '') === 'AED') {
            $result[(string) $card['label']] = (string) $card['value'];
        }
    }
    ksort($result);
    return $result;
};

if (($aging['linkedAgingNetShown'] ?? false) !== true || $linkedCards($aging) !== $linkedCards($ledger)) {
    throw new RuntimeException('Linked Aging cards do not match the supplier ledger: ' . json_encode([
        'aging_shown' => $aging['linkedAgingNetShown'] ?? null,
        'aging' => $linkedCards($aging),
        'supplier' => $linkedCards($ledger),
    ]));
}
$cards = $linkedCards($aging);
if (! in_array('AED 106,805', $cards, true)
    || ! in_array('AED 61,422', $cards, true)
    || ! in_array('AED 45,383', $cards, true)) {
    throw new RuntimeException('AJWA AED invoice, supplier and net positions are incorrect: ' . json_encode($cards));
}
$invoiceTotal = 0.0;
foreach ($aging['rows'] as $row) {
    if (($row['currency'] ?? '') === 'AED') {
        $invoiceTotal += (float) str_replace(',', '', (string) ($row['total_outstanding'] ?? '0'));
    }
}
if (abs($invoiceTotal - 106805.0) > 0.005) {
    throw new RuntimeException('Invoice Aging detail was silently netted against supplier payable.');
}

$all = $report->reportState(['report' => 'receivable_aging'], $branches, $actor);
$allCards = [];
foreach ($all['summaryCards'] as $card) {
    $allCards[(string) $card['label']] = (string) $card['value'];
}
if (($all['linkedAgingNetShown'] ?? false) !== true
    || ($allCards['Net Receivable / AED'] ?? '') !== 'AED 102,546'
    || ($allCards['Net Receivable / PKR'] ?? '') !== 'PKR 2,107,570'
    || ($allCards['Linked Supplier Payable / AED'] ?? '') !== 'AED 61,422') {
    throw new RuntimeException('All-account Aging did not net AJWA once: ' . json_encode($allCards));
}
foreach ($all['summaryCards'] as $card) {
    if (str_contains((string) ($card['label'] ?? ''), 'Consolidated PKR')) {
        throw new RuntimeException('Receivable Aging still includes a consolidated PKR summary card.');
    }
}

$allAed = $report->reportState(['report' => 'receivable_aging', 'currency' => 'AED'], $branches, $actor);
if (count(array_filter($allAed['rows'], static fn (array $row): bool => ($row['currency'] ?? '') !== 'AED')) > 0
    || count(array_filter($allAed['summaryCards'], static fn (array $card): bool => ($card['label'] ?? '') === 'Net Receivable / PKR')) > 0) {
    throw new RuntimeException('AED filter retained PKR Aging rows or native-currency cards.');
}

$branchId = (int) $link['supplier_branch_id'];
$branchAll = $report->reportState(['report' => 'receivable_aging', 'branch_id' => $branchId], $branches, $actor);
$branchInvoiceTotal = 0.0;
$branchAjwaInvoiceTotal = 0.0;
foreach ((new \App\Repositories\ReportRepository($app))->receivableAging([$branchId], date('Y-m-d')) as $row) {
    if (($row['currency'] ?? '') !== 'AED') {
        continue;
    }
    $branchInvoiceTotal += (float) $row['outstanding_amount'];
    if ((int) $row['business_source_id'] === 12) {
        $branchAjwaInvoiceTotal += (float) $row['outstanding_amount'];
    }
}
$branchNet = null;
foreach ((new \App\Services\LinkedPartyPositionService($app))->positions([$branchId], true) as $position) {
    if ((int) $position['link_id'] === (int) $link['id'] && $position['currency'] === 'AED') {
        $branchNet = (float) $position['net_balance'];
        break;
    }
}
$branchNetCard = null;
foreach ($branchAll['summaryCards'] as $card) {
    if (($card['label'] ?? '') === 'Net Receivable / AED') {
        $branchNetCard = (float) str_replace(['AED ', ','], '', (string) $card['value']);
    }
}
if ($branchNet === null || $branchNetCard === null
    || abs($branchNetCard - round($branchInvoiceTotal - $branchAjwaInvoiceTotal + max(0, $branchNet))) > 1) {
    throw new RuntimeException('All-account branch filter used a linked net outside its branch scope.');
}

$linkedBooking = (string) $db->query(
    'SELECT cri.booking_reference FROM customer_receivable_items cri
     INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
     WHERE b.business_source_id = 12 AND cri.outstanding_amount > 0
     ORDER BY cri.id LIMIT 1'
)->fetchColumn();
if ($linkedBooking !== '') {
    $filteredBooking = $report->reportState([
        'report' => 'receivable_aging',
        'booking_reference' => $linkedBooking,
    ], $branches, $actor);
    if (($filteredBooking['linkedAgingNetShown'] ?? false) !== false) {
        throw new RuntimeException('Whole-account net was shown for one booking filter.');
    }
}

$selectedBranch = $report->reportState([
    'report' => 'receivable_aging',
    'business_source_id' => 12,
    'branch_id' => (int) $link['supplier_branch_id'],
], $branches, $actor);
if ($linkedCards($selectedBranch) !== $cards) {
    throw new RuntimeException('Branch selection split the accessible linked net position.');
}
$limitedBranch = $report->reportState([
    'report' => 'receivable_aging',
    'business_source_id' => 12,
], [(int) $link['supplier_branch_id']], $actor);
$limitedPosition = null;
foreach ((new \App\Services\LinkedPartyPositionService($app))->positions([(int) $link['supplier_branch_id']]) as $position) {
    if ((int) $position['link_id'] === (int) $link['id'] && $position['currency'] === 'AED') {
        $limitedPosition = $position;
        break;
    }
}
if ($limitedPosition === null || ! in_array('AED ' . number_format(abs((float) $limitedPosition['net_balance']), 0), $linkedCards($limitedBranch), true)) {
    throw new RuntimeException('Branch-limited access exposed a net balance outside its scope.');
}

$unlinkedId = (int) $db->query(
    'SELECT bs.id FROM business_sources bs
     LEFT JOIN business_source_supplier_links l ON l.business_source_id = bs.id
     WHERE l.id IS NULL AND bs.is_active = 1 ORDER BY bs.id LIMIT 1'
)->fetchColumn();
if ($unlinkedId > 0) {
    $ordinary = $report->reportState([
        'report' => 'receivable_aging',
        'business_source_id' => $unlinkedId,
    ], $branches, $actor);
    if (($ordinary['linkedAgingNetShown'] ?? false) !== false || $linkedCards($ordinary) !== []) {
        throw new RuntimeException('An ordinary account was incorrectly shown as a linked party.');
    }
    foreach ($ordinary['summaryCards'] as $card) {
        if (str_contains((string) ($card['label'] ?? ''), 'Consolidated PKR')) {
            throw new RuntimeException('Ordinary account Aging still includes a consolidated PKR summary card.');
        }
    }
}

$historical = $report->reportState([
    'report' => 'receivable_aging',
    'business_source_id' => 12,
    'as_of_date' => '2026-10-01',
], $branches, $actor);
if (($historical['linkedAgingNetShown'] ?? false) !== false || $linkedCards($historical) !== []) {
    throw new RuntimeException('A current linked balance was mislabeled as historical.');
}

echo '[PASS] AJWA Aging shows the same AED 106,805 / 61,422 / 45,383 linked position as the supplier ledger.' . PHP_EOL;
echo '[PASS] All accounts count AJWA net once: AED 102,546 displayed total and AED 61,422 linked supplier payable.' . PHP_EOL;
echo '[PASS] No consolidated PKR summary card; AED filter excludes PKR invoice rows.' . PHP_EOL;
echo '[PASS] All-account branch filter uses its own scoped net; booking filter stays invoice-only.' . PHP_EOL;
echo '[PASS] Invoice detail remains AED 106,805 and is labeled separately from the net position.' . PHP_EOL;
echo '[PASS] Linked net pools accessible branches and respects branch-limited access.' . PHP_EOL;
echo '[PASS] Ordinary account Aging does not acquire linked balance cards.' . PHP_EOL;
echo '[PASS] Historical Aging does not present the current linked net as an as-of balance.' . PHP_EOL;
