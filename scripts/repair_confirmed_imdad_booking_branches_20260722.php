<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', ['apply', 'actor-user-id::']);
$apply = array_key_exists('apply', $options);
$actorUserId = max(1, (int) ($options['actor-user-id'] ?? 1));
$targetBranchId = 1;
$targetBranchName = 'Imdad International Travel Agency';
$bookingReferences = [
    'BK-000298', 'BK-000307', 'BK-000316', 'BK-000322',
    'BK-000342', 'BK-000349', 'BK-000350', 'BK-000354',
    'BK-000362', 'BK-000378', 'BK-000381', 'BK-000382',
    'BK-000395', 'BK-000403', 'BK-000441', 'BK-000450',
];

$placeholders = implode(', ', array_fill(0, count($bookingReferences), '?'));
$fetchAll = static function (PDO $db, string $sql, array $parameters = []): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$count = static function (PDO $db, string $sql, array $parameters = []): int {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
};

echo 'Confirmed Imdad booking branch repair (2026-07-22)' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Target: ' . $targetBranchName . ' / branch ' . $targetBranchId . PHP_EOL;

$branchStatement = $db->prepare('SELECT name FROM branches WHERE id = :id AND is_active = 1');
$branchStatement->execute(['id' => $targetBranchId]);
if ((string) $branchStatement->fetchColumn() !== $targetBranchName) {
    fwrite(STDERR, '[ABORT] Branch 1 is not the expected active Imdad branch.' . PHP_EOL);
    exit(1);
}

$bookings = $fetchAll(
    $db,
    "SELECT b.id, b.booking_reference, b.branch_id,
            COUNT(bs.id) AS service_count,
            SUM(CASE WHEN bs.currency = 'PKR' AND bs.cost_currency = 'PKR' THEN 0 ELSE 1 END) AS non_pkr_services,
            SUM(CASE WHEN bs.branch_id <> ? THEN 1 ELSE 0 END) AS split_services
     FROM bookings b
     INNER JOIN booking_services bs ON bs.booking_id = b.id
     WHERE b.booking_reference IN ({$placeholders})
     GROUP BY b.id, b.booking_reference, b.branch_id
     ORDER BY b.id",
    array_merge([$targetBranchId], $bookingReferences)
);

if (count($bookings) !== count($bookingReferences)) {
    fwrite(STDERR, '[ABORT] Expected 16 confirmed bookings but found ' . count($bookings) . '.' . PHP_EOL);
    exit(1);
}

foreach ($bookings as $booking) {
    if ((int) $booking['branch_id'] !== $targetBranchId) {
        fwrite(STDERR, '[ABORT] ' . $booking['booking_reference'] . ' booking header is not assigned to Imdad.' . PHP_EOL);
        exit(1);
    }
    if ((int) $booking['non_pkr_services'] !== 0) {
        fwrite(STDERR, '[ABORT] ' . $booking['booking_reference'] . ' does not have the confirmed PKR service currency.' . PHP_EOL);
        exit(1);
    }
}

$activeTreasuryConflicts = $fetchAll(
    $db,
    "SELECT cr.booking_reference, cr.receipt_no, cr.status, ta.account_name, ta.branch_id AS treasury_branch_id
     FROM customer_receipts cr
     INNER JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
     WHERE cr.booking_reference IN ({$placeholders})
       AND cr.status <> 'void'
       AND ta.branch_id <> ?",
    array_merge($bookingReferences, [$targetBranchId])
);
if ($activeTreasuryConflicts !== []) {
    fwrite(STDERR, '[ABORT] Active customer money belongs to another branch: '
        . json_encode($activeTreasuryConflicts, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$supplierTreasuryConflicts = $fetchAll(
    $db,
    "SELECT DISTINCT so.booking_reference, sp.payment_no, sp.status, sp.branch_id AS payment_branch_id,
            ta.branch_id AS treasury_branch_id, ta.account_name
     FROM supplier_payment_allocations spa
     INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
     INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
     LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
     WHERE so.booking_reference IN ({$placeholders})
       AND sp.status <> 'void'
       AND (sp.branch_id <> ? OR (ta.id IS NOT NULL AND ta.branch_id <> ?))",
    array_merge($bookingReferences, [$targetBranchId, $targetBranchId])
);
if ($supplierTreasuryConflicts !== []) {
    fwrite(STDERR, '[ABORT] Active supplier money belongs to another branch: '
        . json_encode($supplierTreasuryConflicts, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$before = [
    'services' => $count($db, "SELECT COUNT(*) FROM booking_services bs INNER JOIN bookings b ON b.id = bs.booking_id WHERE b.booking_reference IN ({$placeholders}) AND bs.branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
    'receivables' => $count($db, "SELECT COUNT(*) FROM customer_receivable_items WHERE booking_reference IN ({$placeholders}) AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
    'obligations' => $count($db, "SELECT COUNT(*) FROM supplier_obligations WHERE booking_reference IN ({$placeholders}) AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
    'corrections' => $count($db, "SELECT COUNT(*) FROM service_financial_corrections WHERE booking_reference IN ({$placeholders}) AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
    'current_currency_journals' => $count($db, "SELECT COUNT(*) FROM journal_entries WHERE booking_reference IN ({$placeholders}) AND currency = 'PKR' AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
];

foreach ($bookings as $booking) {
    echo sprintf(
        '[READY] %s: %d service(s), %d service branch mismatch(es).',
        $booking['booking_reference'],
        (int) $booking['service_count'],
        (int) $booking['split_services']
    ) . PHP_EOL;
}
echo 'Planned current-record corrections: ' . json_encode($before, JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'Protected history: void AED receipts and their fully reversing AED journals remain under Noble Route.' . PHP_EOL;

if (array_sum($before) === 0) {
    echo '[NO CHANGE] All confirmed current records are already aligned to Imdad.' . PHP_EOL;
    exit(0);
}

if (! $apply) {
    echo 'Dry run complete. No data changed. Take a database backup, then rerun with --apply.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();

try {
    $bookingIds = array_map(static fn (array $row): int => (int) $row['id'], $bookings);
    $bookingIdPlaceholders = implode(', ', array_fill(0, count($bookingIds), '?'));

    $updates = [
        ["UPDATE booking_services SET branch_id = ? WHERE booking_id IN ({$bookingIdPlaceholders})", array_merge([$targetBranchId], $bookingIds)],
        ["UPDATE booking_service_events SET branch_id = ? WHERE booking_id IN ({$bookingIdPlaceholders})", array_merge([$targetBranchId], $bookingIds)],
        ["UPDATE service_financial_corrections SET branch_id = ? WHERE booking_id IN ({$bookingIdPlaceholders})", array_merge([$targetBranchId], $bookingIds)],
        ["UPDATE booking_documents SET branch_id = ? WHERE booking_id IN ({$bookingIdPlaceholders})", array_merge([$targetBranchId], $bookingIds)],
        ["UPDATE booking_reminders SET branch_id = ? WHERE booking_id IN ({$bookingIdPlaceholders})", array_merge([$targetBranchId], $bookingIds)],
        ["UPDATE customer_receivable_items SET branch_id = ? WHERE booking_reference IN ({$placeholders})", array_merge([$targetBranchId], $bookingReferences)],
        ["UPDATE supplier_obligations SET branch_id = ? WHERE booking_reference IN ({$placeholders})", array_merge([$targetBranchId], $bookingReferences)],
        ["UPDATE customer_receipts SET branch_id = ? WHERE booking_reference IN ({$placeholders}) AND status <> 'void'", array_merge([$targetBranchId], $bookingReferences)],
        ["UPDATE supplier_payments SET branch_id = ? WHERE booking_reference IN ({$placeholders}) AND status <> 'void'", array_merge([$targetBranchId], $bookingReferences)],
        ["UPDATE journal_entries SET branch_id = ? WHERE booking_reference IN ({$placeholders}) AND currency = 'PKR'", array_merge([$targetBranchId], $bookingReferences)],
    ];

    foreach ($updates as [$sql, $parameters]) {
        $statement = $db->prepare($sql);
        $statement->execute($parameters);
    }

    $remaining = array_sum([
        $count($db, "SELECT COUNT(*) FROM booking_services bs INNER JOIN bookings b ON b.id = bs.booking_id WHERE b.booking_reference IN ({$placeholders}) AND bs.branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
        $count($db, "SELECT COUNT(*) FROM customer_receivable_items WHERE booking_reference IN ({$placeholders}) AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
        $count($db, "SELECT COUNT(*) FROM supplier_obligations WHERE booking_reference IN ({$placeholders}) AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
        $count($db, "SELECT COUNT(*) FROM service_financial_corrections WHERE booking_reference IN ({$placeholders}) AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
        $count($db, "SELECT COUNT(*) FROM journal_entries WHERE booking_reference IN ({$placeholders}) AND currency = 'PKR' AND branch_id <> ?", array_merge($bookingReferences, [$targetBranchId])),
    ]);
    if ($remaining !== 0) {
        throw new RuntimeException('Post-repair branch verification found ' . $remaining . ' remaining current-record mismatch(es).');
    }

    $journalImbalances = $count(
        $db,
        'SELECT COUNT(*) FROM (
            SELECT je.id
            FROM journal_entries je
            INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            GROUP BY je.id
            HAVING ABS(SUM(jel.debit_amount) - SUM(jel.credit_amount)) > 0.005
         ) imbalanced'
    );
    if ($journalImbalances !== 0) {
        throw new RuntimeException('Journal balance verification failed after branch repair.');
    }

    \App\Helpers\AuditLog::record($app, 'data_repair.confirmed_imdad_booking_branches', [
        'user_id' => $actorUserId,
        'booking_references' => $bookingReferences,
        'target_branch_id' => $targetBranchId,
        'target_branch_name' => $targetBranchName,
        'current_record_corrections' => $before,
        'protected_history' => 'Void AED receipts and reversing AED journals retained under original Noble branch.',
    ]);

    $db->commit();
    echo '[APPLIED] All 16 confirmed bookings now have Imdad-owned current PKR operational and financial records.' . PHP_EOL;
    echo '[PASS] Active treasury evidence remained in its original account and every journal remains balanced.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, '[ROLLED BACK] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
