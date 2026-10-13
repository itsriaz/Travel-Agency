<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\BusinessSourceRepository;
use App\Services\SalaryAccountMatcher;

$app = App::bootstrap(BASE_PATH);
$db = $app->get('db');
$tableExists = static function (string $table) use ($db): bool {
    $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    return (int) $statement->fetchColumn() > 0;
};

$accounts = (new BusinessSourceRepository($app))->activeOptions();
$groupKeys = SalaryAccountMatcher::groupKeys($accounts);
$groups = [];
foreach ($accounts as $account) {
    $id = (int) ($account['id'] ?? 0);
    $key = $groupKeys[$id] ?? 'account:' . $id;
    $groups[$key][] = [
        'id' => $id,
        'name' => (string) ($account['name'] ?? ''),
    ];
}

$result = [
    'inspection_only' => true,
    'active_account_count' => count($accounts),
    'paired_snapshot_accounts' => array_values(array_filter($groups, static fn (array $rows): bool => count($rows) > 1)),
    'legacy_link_table_exists' => $tableExists('employee_business_source_links'),
    'legacy_link_count' => null,
    'salary_record_count' => null,
    'salary_payment_count' => null,
    'salary_totals_by_currency' => [],
    'orphan_salary_account_count' => null,
];

if ($result['legacy_link_table_exists']) {
    $result['legacy_link_count'] = (int) $db->query('SELECT COUNT(*) FROM employee_business_source_links')->fetchColumn();
}
if ($tableExists('employee_salary_records')) {
    $result['salary_record_count'] = (int) $db->query('SELECT COUNT(*) FROM employee_salary_records')->fetchColumn();
    $result['orphan_salary_account_count'] = (int) $db->query(
        'SELECT COUNT(*) FROM employee_salary_records r LEFT JOIN business_sources bs ON bs.id = r.business_source_id WHERE bs.id IS NULL'
    )->fetchColumn();
    $result['salary_totals_by_currency'] = $db->query(
        'SELECT currency, COUNT(*) AS record_count,
                SUM(gross_debit_amount) AS gross_debit,
                SUM(paid_credit_amount) AS paid_credit,
                SUM(deduction_credit_amount) AS deduction_credit,
                SUM(outstanding_amount) AS outstanding
         FROM employee_salary_records
         GROUP BY currency ORDER BY currency'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($tableExists('employee_salary_payments')) {
    $result['salary_payment_count'] = (int) $db->query('SELECT COUNT(*) FROM employee_salary_payments')->fetchColumn();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
