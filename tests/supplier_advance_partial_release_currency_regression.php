<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branchId = (int) $db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
$actorId = (int) $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($branchId <= 0 || $actorId <= 0) {
    throw new RuntimeException('A branch and user are required for supplier release regression.');
}

$db->beginTransaction();
try {
    $code = 'QA-ADV-REL-' . random_int(100000, 999999);
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
                  VALUES (?, ?, ?, "normal_payable", "PKR", 1)')
        ->execute([$branchId, $code, $code]);
    $supplierId = (int) $db->lastInsertId();
    $repository = new \App\Repositories\SupplierRepository($app);

    foreach ([
        ['label' => 'same', 'advance_currency' => 'PKR', 'deposit' => 1000, 'available' => 400,
            'applied' => 600, 'consumed' => 650, 'source_release' => 200, 'source_remaining' => 400],
        ['label' => 'cross', 'advance_currency' => 'AED', 'deposit' => 100, 'available' => 40,
            'applied' => 600, 'consumed' => 60, 'source_release' => 20, 'source_remaining' => 40],
    ] as $case) {
        $booking = $code . '-' . $case['label'];
        $db->prepare('INSERT INTO supplier_obligations
            (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount,
             advance_applied_amount, net_payable_amount, status, created_by_user_id)
            VALUES (?, ?, ?, "SV-001", "PKR", 1000, 600, 400, "partially_covered", ?)')
            ->execute([$supplierId, $branchId, $booking, $actorId]);
        $obligationId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO supplier_advances
            (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, received_at,
             created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)')
            ->execute([$supplierId, $branchId, $case['advance_currency'], $case['deposit'], $case['available'], $booking, $actorId]);
        $advanceId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO supplier_advance_applications
            (supplier_advance_id, supplier_obligation_id, applied_amount, application_type,
             advance_currency, advance_amount_consumed, obligation_currency, obligation_amount_applied,
             exchange_rate, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, "PKR", 600, ?, ?)')
            ->execute([$advanceId, $obligationId, $case['applied'],
                $case['label'] === 'same' ? 'same_currency_auto' : 'cross_currency_explicit',
                $case['advance_currency'], $case['consumed'],
                $case['label'] === 'same' ? 1 : 10, $actorId]);

        $repository->releaseSettledCreditForObligation($obligationId, 400, $actorId, 'QA partial advance release');
        $state = $db->query('SELECT a.available_amount, x.applied_amount, x.advance_amount_consumed,
                            x.obligation_amount_applied, o.advance_applied_amount, o.net_payable_amount
                            FROM supplier_advances a
                            JOIN supplier_advance_applications x ON x.supplier_advance_id = a.id
                            JOIN supplier_obligations o ON o.id = x.supplier_obligation_id
                            WHERE a.id = ' . $advanceId)->fetch(PDO::FETCH_ASSOC);
        if (! is_array($state)
            || abs((float) $state['available_amount'] - ($case['available'] + $case['source_release'])) > 0.005
            || abs((float) $state['applied_amount'] - 400) > 0.005
            || abs((float) $state['advance_amount_consumed'] - $case['source_remaining']) > 0.005
            || abs((float) $state['obligation_amount_applied'] - 400) > 0.005
            || abs((float) $state['advance_applied_amount'] - 400) > 0.005
            || abs((float) $state['net_payable_amount'] - 600) > 0.005) {
            throw new RuntimeException('Partial ' . $case['label'] . '-currency release did not preserve both native amounts.');
        }

        $repository->releaseSettledCreditForObligation($obligationId, 0, $actorId, 'QA full advance release');
        $remainingApplications = (int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications WHERE supplier_advance_id = ' . $advanceId)->fetchColumn();
        $available = (float) $db->query('SELECT available_amount FROM supplier_advances WHERE id = ' . $advanceId)->fetchColumn();
        $open = (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn();
        if ($remainingApplications !== 0 || abs($available - $case['deposit']) > 0.005 || abs($open - 1000) > 0.005) {
            throw new RuntimeException('Full ' . $case['label'] . '-currency release did not restore credit and payable.');
        }
        echo '[PASS] ' . ucfirst($case['label']) . '-currency partial and full release preserve advance and payable amounts.' . PHP_EOL;
    }
} finally {
    $db->rollBack();
}
