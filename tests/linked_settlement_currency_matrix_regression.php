<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Manual linked-settlement currency matrix regression' . PHP_EOL;

$branchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$branchId = $branchIds[0] ?? 0;
$otherBranchId = $branchIds[1] ?? 0;
$actorId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
     ORDER BY (r.code = "super_admin") DESC, u.id
     LIMIT 1'
)->fetchColumn();
if ($branchId <= 0 || $actorId <= 0) {
    throw new RuntimeException('An active branch and financial administrator are required.');
}

$beforeMoneyRows = [
    'customer_receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
    'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
    'treasury_transactions' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
];

$db->beginTransaction();
try {
    $token = date('His') . random_int(100, 999);
    $db->prepare('INSERT INTO business_sources (code, name, is_system, is_active) VALUES (:code, :name, 0, 1)')
        ->execute(['code' => 'QA-MATRIX-' . $token, 'name' => 'QA Matrix Account ' . $token]);
    $businessSourceId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
    )->execute(['branch_id' => $branchId, 'code' => 'QA-MATRIX-S-' . $token, 'name' => 'QA Matrix Supplier ' . $token]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id)
         VALUES (:business_source_id, :supplier_id, :actor_user_id)'
    )->execute([
        'business_source_id' => $businessSourceId,
        'supplier_id' => $supplierId,
        'actor_user_id' => $actorId,
    ]);
    $linkId = (int) $db->lastInsertId();

    $bookingInsert = $db->prepare(
        'INSERT INTO bookings (
            booking_reference, branch_id, business_source_id, booking_status, booking_date,
            created_by_user_id, updated_by_user_id
         ) VALUES (
            :booking_reference, :branch_id, :business_source_id, "open", CURDATE(),
            :created_user_id, :updated_user_id
         )'
    );
    $receivableInsert = $db->prepare(
        'INSERT INTO customer_receivable_items (
            branch_id, booking_reference, service_line_reference, currency,
            due_amount, allocated_amount, outstanding_amount, status, created_by_user_id
         ) VALUES (
            :branch_id, :booking_reference, "SV-001", :currency,
            1000, 0, 1000, "open", :actor_user_id
         )'
    );
    $obligationInsert = $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, currency,
            gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", :currency,
            1000, 0, 1000, "open", :actor_user_id
         )'
    );

    $service = new \App\Services\CounterpartyOffsetService($app);
    $created = [];
    foreach (['PKR', 'AED', 'USD'] as $currency) {
        $saleBooking = 'QA-MX-' . $currency . '-SALE-' . $token;
        $buyBooking = 'QA-MX-' . $currency . '-BUY-' . $token;
        foreach ([$saleBooking, $buyBooking] as $bookingReference) {
            $bookingInsert->execute([
                'booking_reference' => $bookingReference,
                'branch_id' => $branchId,
                'business_source_id' => $businessSourceId,
                'created_user_id' => $actorId,
                'updated_user_id' => $actorId,
            ]);
        }
        $receivableInsert->execute([
            'branch_id' => $branchId,
            'booking_reference' => $saleBooking,
            'currency' => $currency,
            'actor_user_id' => $actorId,
        ]);
        $receivableId = (int) $db->lastInsertId();
        $obligationInsert->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'booking_reference' => $buyBooking,
            'currency' => $currency,
            'actor_user_id' => $actorId,
        ]);
        $obligationId = (int) $db->lastInsertId();

        $baseInput = [
            'link_id' => $linkId,
            'branch_id' => $branchId,
            'currency' => $currency,
            'offset_date' => date('Y-m-d'),
            'customer_receivable_item_id' => $receivableId,
            'supplier_obligation_id' => $obligationId,
        ];
        $partial = $service->settle(array_replace($baseInput, ['amount' => 400]), $actorId, $branchIds);
        $full = $service->settle(array_replace($baseInput, ['amount' => 600]), $actorId, $branchIds);
        $created[$currency] = compact('receivableId', 'obligationId', 'partial', 'full');

        $balances = $db->query(
            'SELECT
                (SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId . ') AS customer_due,
                (SELECT allocated_amount FROM customer_receivable_items WHERE id = ' . $receivableId . ') AS customer_paid,
                (SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId . ') AS supplier_due'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $check($currency . ': partial PKR/AED/USD sequence records exactly 400 then 600',
            (float) ($partial['amount'] ?? 0) === 400.0 && (float) ($full['amount'] ?? 0) === 600.0);
        $check($currency . ': full manual adjustment closes supplier payable without marking passenger paid',
            (float) ($balances['supplier_due'] ?? -1) === 0.0
            && (float) ($balances['customer_due'] ?? -1) === 1000.0
            && (float) ($balances['customer_paid'] ?? -1) === 0.0);

        foreach ([[$partial, 400.0], [$full, 600.0]] as [$offset, $expectedAmount]) {
            $journal = $db->prepare(
                'SELECT j.branch_id, j.currency, j.source_type, j.source_reference,
                        SUM(CASE WHEN c.code = "AP_CONTROL" THEN l.debit_amount ELSE 0 END) AS ap_debit,
                        SUM(CASE WHEN c.code = "AR_CONTROL" THEN l.credit_amount ELSE 0 END) AS ar_credit,
                        SUM(l.debit_amount) AS total_debit, SUM(l.credit_amount) AS total_credit
                 FROM journal_entries j
                 INNER JOIN journal_entry_lines l ON l.journal_entry_id = j.id
                 INNER JOIN chart_of_accounts c ON c.id = l.account_id
                 WHERE j.id = :journal_id
                 GROUP BY j.id, j.branch_id, j.currency, j.source_type, j.source_reference'
            );
            $journal->execute(['journal_id' => (int) ($offset['journal_entry_id'] ?? 0)]);
            $row = $journal->fetch(PDO::FETCH_ASSOC) ?: [];
            $check($currency . ': ' . number_format($expectedAmount, 0) . ' journal is balanced AP debit / AR credit',
                (int) ($row['branch_id'] ?? 0) === $branchId
                && (string) ($row['currency'] ?? '') === $currency
                && (string) ($row['source_type'] ?? '') === 'linked_party_offset'
                && (string) ($row['source_reference'] ?? '') === (string) ($offset['offset_no'] ?? '')
                && (float) ($row['ap_debit'] ?? 0) === $expectedAmount
                && (float) ($row['ar_credit'] ?? 0) === $expectedAmount
                && (float) ($row['total_debit'] ?? 0) === (float) ($row['total_credit'] ?? -1));
        }

        $service->void((int) $full['id'], 'QA matrix full-part reversal', $actorId, $branchIds);
        $check($currency . ': reversing 600 restores only 600 supplier payable',
            (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn() === 600.0);
        $service->void((int) $partial['id'], 'QA matrix partial-part reversal', $actorId, $branchIds);
        $check($currency . ': reversing remaining 400 restores supplier payable to 1,000',
            (float) $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn() === 1000.0);

        $journalIds = implode(',', array_map('intval', [
            $partial['journal_entry_id'] ?? 0,
            $full['journal_entry_id'] ?? 0,
            ($db->query('SELECT reversal_journal_entry_id FROM counterparty_offsets WHERE id = ' . (int) $partial['id'])->fetchColumn() ?: 0),
            ($db->query('SELECT reversal_journal_entry_id FROM counterparty_offsets WHERE id = ' . (int) $full['id'])->fetchColumn() ?: 0),
        ]));
        $net = $db->query(
            'SELECT account_id, SUM(debit_amount - credit_amount) AS net
             FROM journal_entry_lines
             WHERE journal_entry_id IN (' . $journalIds . ')
             GROUP BY account_id
             HAVING ABS(net) > 0.005'
        )->fetchAll(PDO::FETCH_ASSOC);
        $check($currency . ': postings and reversals net to zero by control account', $net === []);
    }

    $pkr = $created['PKR'];
    $wrongCurrencyRejected = false;
    try {
        $service->settle([
            'link_id' => $linkId,
            'branch_id' => $branchId,
            'currency' => 'AED',
            'offset_date' => date('Y-m-d'),
            'amount' => 1,
            'customer_receivable_item_id' => $pkr['receivableId'],
            'supplier_obligation_id' => $pkr['obligationId'],
        ], $actorId, $branchIds);
    } catch (RuntimeException) {
        $wrongCurrencyRejected = true;
    }
    $check('A PKR invoice pair cannot be adjusted through an AED settlement', $wrongCurrencyRejected);

    if ($otherBranchId > 0) {
        $crossBranchBooking = 'QA-MX-BRANCH-' . $token;
        $bookingInsert->execute([
            'booking_reference' => $crossBranchBooking,
            'branch_id' => $otherBranchId,
            'business_source_id' => $businessSourceId,
            'created_user_id' => $actorId,
            'updated_user_id' => $actorId,
        ]);
        $obligationInsert->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $otherBranchId,
            'booking_reference' => $crossBranchBooking,
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
        ]);
        $otherBranchObligationId = (int) $db->lastInsertId();
        $crossBranch = $service->settle([
            'link_id' => $linkId,
            'branch_id' => $branchId,
            'currency' => 'PKR',
            'offset_date' => date('Y-m-d'),
            'amount' => 1,
            'customer_receivable_item_id' => $pkr['receivableId'],
            'supplier_obligation_id' => $otherBranchObligationId,
        ], $actorId, $branchIds);
        $check('A manual invoice pair can cross branches when the administrator can access both',
            (int) ($crossBranch['account_branch_id'] ?? 0) === $branchId
            && (int) ($crossBranch['supplier_branch_id'] ?? 0) === $otherBranchId
            && (int) ($crossBranch['supplier_journal_entry_id'] ?? 0) > 0);
        $service->void((int) $crossBranch['id'], 'QA cross-branch reversal', $actorId, $branchIds);
    }

    $aed = $created['AED'];
    $crossCurrency = $service->settle([
        'link_id' => $linkId,
        'offset_date' => date('Y-m-d'),
        'exchange_rate_effective_date' => date('Y-m-d'),
        'exchange_rate' => 77,
        'account_allocations' => [$aed['receivableId'] => 10],
        'payable_allocations' => [$pkr['obligationId'] => 770],
    ], $actorId, $branchIds);
    $check('A manual settlement preserves AED account value and PKR supplier value at the entered rate',
        (string) ($crossCurrency['account_currency'] ?? '') === 'AED'
        && (float) ($crossCurrency['account_amount'] ?? 0) === 10.0
        && (string) ($crossCurrency['supplier_currency'] ?? '') === 'PKR'
        && (float) ($crossCurrency['supplier_amount'] ?? 0) === 770.0
        && (float) ($crossCurrency['exchange_rate'] ?? 0) === 77.0
        && (int) ($crossCurrency['supplier_journal_entry_id'] ?? 0) > 0);
    $crossCurrencyJournalIds = implode(',', array_map('intval', [
        $crossCurrency['journal_entry_id'] ?? 0,
        $crossCurrency['supplier_journal_entry_id'] ?? 0,
    ]));
    $crossCurrencyJournals = $db->query(
        'SELECT branch_id, currency, SUM(debit_amount) debits, SUM(credit_amount) credits
         FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
         WHERE j.id IN (' . $crossCurrencyJournalIds . ')
         GROUP BY j.id, branch_id, currency ORDER BY j.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $check('Cross-currency settlement creates two separately balanced native-currency journals',
        count($crossCurrencyJournals) === 2
        && array_reduce($crossCurrencyJournals, static fn (bool $ok, array $row): bool =>
            $ok && abs((float) $row['debits'] - (float) $row['credits']) < 0.005, true));
    $service->void((int) $crossCurrency['id'], 'QA cross-currency reversal', $actorId, $branchIds);

    $duringMoneyRows = [
        'customer_receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'treasury_transactions' => (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn(),
    ];
    $check('PKR/AED/USD adjustments create no receipt, supplier payment, or treasury movement',
        $duringMoneyRows === $beforeMoneyRows, json_encode(['before' => $beforeMoneyRows, 'during' => $duringMoneyRows]));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Manual linked-settlement currency matrix failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Manual linked-settlement currency matrix passed. Transaction rolled back.' . PHP_EOL;
