<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branches = array_map('intval', $db->query(
    'SELECT id FROM branches WHERE is_active = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_COLUMN));
$actorId = (int) $db->query(
    'SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1'
)->fetchColumn();
$link = null;
foreach ((new \App\Repositories\CounterpartyLinkRepository($app))->listLinks() as $candidate) {
    if (strcasecmp((string) $candidate['business_source_name'], 'AJWA SWAT SHJ') === 0
        && strcasecmp((string) $candidate['supplier_name'], 'AJWA SWAT TOURISM') === 0) {
        $link = $candidate;
        break;
    }
}
if ($link === null || $actorId <= 0 || count($branches) < 2) {
    throw new RuntimeException('AJWA link, an active actor, and two active branches are required.');
}

$cardsFor = static function (array $state): array {
    $cards = [];
    foreach ($state['summaryCards'] as $card) {
        if (($card['group'] ?? '') !== 'linked_roles') {
            continue;
        }
        $value = (string) ($card['value'] ?? '');
        if (! preg_match('/^([A-Z]{3}) ([0-9][0-9,]*(?:\.[0-9]{2})?)$/', $value, $matches)) {
            throw new RuntimeException('Unexpected linked balance card: ' . $value);
        }
        $cards[(string) $card['label']] = [$matches[1], (float) str_replace(',', '', $matches[2])];
    }
    ksort($cards);
    return $cards;
};

$reportService = new \App\Services\ReportService($app);
$scopes = [
    'all' => 0,
    'supplier_home' => (int) $link['supplier_branch_id'],
];
$results = [];
foreach ($scopes as $scope => $selectedBranchId) {
    foreach ([
        'account' => ['report' => 'customer_ledger', 'business_source_id' => (int) $link['business_source_id']],
        'supplier' => ['report' => 'supplier_ledger', 'supplier_id' => (int) $link['supplier_id']],
    ] as $side => $query) {
        if ($selectedBranchId > 0) {
            $query['branch_id'] = $selectedBranchId;
        }
        $state = $reportService->reportState($query, $branches, $actorId, 'screen');
        $results[$scope][$side] = $cardsFor($state);
        if ($scope === 'supplier_home') {
            foreach ($state['rows'] as $row) {
                if ((int) ($row['branch_id'] ?? 0) !== $selectedBranchId) {
                    throw new RuntimeException('A detail row escaped the selected branch scope.');
                }
            }
        }
    }
}

if (count($results['all']['account']) < 3
    || count($results['all']['account']) % 3 !== 0
    || $results['all']['account'] !== $results['all']['supplier']
    || $results['all']['account'] !== $results['supplier_home']['account']
    || $results['all']['account'] !== $results['supplier_home']['supplier']) {
    throw new RuntimeException('Linked balance cards changed with branch selection or disagree across ledgers.');
}

$limitedState = $reportService->reportState(
    ['report' => 'customer_ledger', 'business_source_id' => (int) $link['business_source_id']],
    [(int) $link['supplier_branch_id']],
    $actorId,
    'screen'
);
$limitedExpected = (new \App\Services\LinkedPartyPositionService($app))->positions(
    [(int) $link['supplier_branch_id']],
    true
);
$limitedExpectedCards = [];
foreach ($limitedExpected as $position) {
    if ((int) $position['link_id'] === (int) $link['id']) {
        $limitedExpectedCards = $position;
        break;
    }
}
if ($limitedExpectedCards === [] || $cardsFor($limitedState) === []) {
    throw new RuntimeException('Branch-limited linked position is unavailable.');
}
$netCard = null;
foreach ($cardsFor($limitedState) as $label => $card) {
    if (str_starts_with($label, 'Net ')) {
        $netCard = $card;
        break;
    }
}
if ($netCard === null || abs($netCard[1] - abs((float) $limitedExpectedCards['net_balance'])) > 0.005) {
    throw new RuntimeException('Branch-limited viewer received a balance outside their accessible scope.');
}

echo 'Linked-party AED cards match across account/supplier ledgers and branch selections.' . PHP_EOL;
echo 'Detail rows remain branch-filtered; branch-limited access stays restricted. No data changed.' . PHP_EOL;
