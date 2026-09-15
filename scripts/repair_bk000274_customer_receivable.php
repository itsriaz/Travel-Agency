<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$options = getopt('', ['apply', 'rehearse', 'actor:', 'backup:']);
$apply = array_key_exists('apply', $options);
$rehearse = array_key_exists('rehearse', $options);

if ($apply && $rehearse) {
    fwrite(STDERR, "Choose either --rehearse or --apply, not both.\n");
    exit(1);
}

if ($apply) {
    $backupPath = trim((string) ($options['backup'] ?? ''));
    if ($backupPath === '' || ! is_file($backupPath) || filesize($backupPath) < 4096) {
        fwrite(STDERR, "A readable SQL backup larger than 4 KB is required with --apply.\n");
        exit(1);
    }
}

$actorUserId = (int) ($options['actor'] ?? 0);
if (($apply || $rehearse) && $actorUserId <= 0) {
    fwrite(STDERR, "Specify an active super administrator with --actor=ID.\n");
    exit(1);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$moneyEquals = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) <= 0.005;

$loadState = static function (bool $forUpdate = false) use ($db): array {
    $recordStatement = $db->prepare(
        'SELECT
            b.id AS booking_id,
            b.booking_reference,
            b.branch_id,
            bs.id AS service_id,
            bs.line_reference,
            bs.service_status,
            bs.currency AS service_currency,
            cri.id AS receivable_id,
            cri.due_group,
            cri.currency AS receivable_currency,
            cri.due_amount,
            cri.allocated_amount,
            cri.outstanding_amount,
            cri.status AS receivable_status,
            cri.remarks
         FROM bookings b
         INNER JOIN booking_services bs
            ON bs.booking_id = b.id
           AND bs.line_reference = "SV-001"
         INNER JOIN customer_receivable_items cri
            ON cri.booking_reference = b.booking_reference
           AND cri.service_line_reference = bs.line_reference
           AND cri.due_group = "service_sale"
         WHERE b.booking_reference = "BK-000274"' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $recordStatement->execute();
    $records = $recordStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $eventStatement = $db->query(
        'SELECT id, event_status, currency, penalty_amount, customer_credit_amount, supplier_credit_amount, payload_json
         FROM booking_service_events
         WHERE booking_reference = "BK-000274"
           AND booking_service_id = 273
           AND event_type = "cancel"
           AND event_status = "posted"
         ORDER BY id DESC'
    );
    $events = $eventStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $receiptAllocationStatement = $db->query(
        'SELECT
            COUNT(*) AS allocation_count,
            COALESCE(SUM(COALESCE(a.receivable_amount_allocated, a.allocated_amount)), 0) AS allocation_amount
         FROM customer_receipt_allocations a
         INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
         WHERE a.customer_receivable_item_id = 273
           AND r.status <> "void"'
    );
    $receiptAllocations = $receiptAllocationStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $postedOffsetStatement = $db->query(
        'SELECT COUNT(*) AS allocation_count, COALESCE(SUM(offset_rows.allocated_amount), 0) AS allocation_amount
         FROM (
            SELECT a.allocated_amount
            FROM counterparty_offset_account_allocations a
            INNER JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id
            WHERE a.customer_receivable_item_id = 273 AND o.status = "posted"
            UNION ALL
            SELECT a.allocated_amount
            FROM counterparty_offset_receivable_allocations a
            INNER JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id
            WHERE a.customer_receivable_item_id = 273 AND o.status = "posted"
         ) offset_rows'
    );
    $postedOffsets = $postedOffsetStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    $historicalOffsetStatement = $db->query(
        'SELECT o.id, o.offset_no, o.status, o.amount, o.journal_entry_id, o.reversal_journal_entry_id,
                a.id AS allocation_id, a.allocated_amount
         FROM counterparty_offsets o
         INNER JOIN counterparty_offset_account_allocations a ON a.counterparty_offset_id = o.id
         WHERE a.customer_receivable_item_id = 273
         ORDER BY o.id'
    );
    $historicalOffsets = $historicalOffsetStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $arStatement = $db->query(
        'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE je.booking_reference = "BK-000274"
           AND coa.code = "AR_CONTROL"'
    );
    $arBalance = round((float) $arStatement->fetchColumn(), 2);

    $offsetJournalStatement = $db->query(
        'SELECT coa.code, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net_amount
         FROM counterparty_offsets o
         INNER JOIN journal_entries je ON je.id IN (o.journal_entry_id, o.reversal_journal_entry_id)
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE o.id = 6
         GROUP BY coa.code
         HAVING ABS(net_amount) > 0.005'
    );
    $offsetJournalDifference = $offsetJournalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $releaseJournalStatement = $db->query(
        'SELECT coa.code, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net_amount
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE (je.source_type = "linked_party_offset_release" AND je.source_reference = "REL-OFFSET-AR-273")
            OR (je.source_type = "linked_party_offset_release_void" AND je.source_reference = "VOID-REL-OFFSET-AR-273-3453")
         GROUP BY coa.code
         HAVING ABS(net_amount) > 0.005'
    );
    $releaseJournalDifference = $releaseJournalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $releaseJournalCount = (int) $db->query(
        'SELECT COUNT(DISTINCT je.id)
         FROM journal_entries je
         WHERE (je.source_type = "linked_party_offset_release" AND je.source_reference = "REL-OFFSET-AR-273")
            OR (je.source_type = "linked_party_offset_release_void" AND je.source_reference = "VOID-REL-OFFSET-AR-273-3453")'
    )->fetchColumn();

    $repairAuditCount = (int) $db->query(
        'SELECT COUNT(*) FROM audit_logs WHERE event_name = "counterparty.automatic_history.repaired"'
    )->fetchColumn();

    return [
        'records' => $records,
        'events' => $events,
        'receipt_allocations' => $receiptAllocations,
        'posted_offsets' => $postedOffsets,
        'historical_offsets' => $historicalOffsets,
        'ar_balance' => $arBalance,
        'offset_journal_difference' => $offsetJournalDifference,
        'release_journal_difference' => $releaseJournalDifference,
        'release_journal_count' => $releaseJournalCount,
        'automatic_history_repair_audit_count' => $repairAuditCount,
    ];
};

$assertReviewedState = static function (array $state) use ($moneyEquals): array {
    if (count($state['records']) !== 1) {
        throw new RuntimeException('Expected exactly one BK-000274 / SV-001 customer receivable; nothing changed.');
    }

    $record = $state['records'][0];
    if ((int) $record['booking_id'] !== 274
        || (int) $record['branch_id'] !== 2
        || (int) $record['service_id'] !== 273
        || (int) $record['receivable_id'] !== 273
        || strcasecmp((string) $record['service_status'], 'Cancelled') !== 0
        || strtoupper((string) $record['service_currency']) !== 'AED'
        || strtoupper((string) $record['receivable_currency']) !== 'AED'
        || ! $moneyEquals($record['due_amount'], 585.0)
        || (string) $record['remarks'] !== 'Cancellation settlement customer penalty [SV-001]') {
        throw new RuntimeException('BK-000274 no longer matches the reviewed booking and receivable identity; nothing changed.');
    }

    if (count($state['events']) !== 1 || (int) $state['events'][0]['id'] !== 38) {
        throw new RuntimeException('The reviewed posted cancellation event 38 is not the sole active cancellation; nothing changed.');
    }

    $payload = json_decode((string) ($state['events'][0]['payload_json'] ?? ''), true);
    if (! is_array($payload)
        || (string) ($payload['mode'] ?? '') !== 'cancellation_financial_adjustment'
        || ! $moneyEquals($payload['customer_received_amount'] ?? null, 0.0)
        || ! $moneyEquals($payload['customer_final_charge_amount'] ?? null, 585.0)
        || ! $moneyEquals($payload['available_customer_refund_credit'] ?? null, 0.0)) {
        throw new RuntimeException('Cancellation event 38 no longer proves an unpaid AED 585 customer balance; nothing changed.');
    }

    if ((int) ($state['receipt_allocations']['allocation_count'] ?? -1) !== 0
        || ! $moneyEquals($state['receipt_allocations']['allocation_amount'] ?? null, 0.0)) {
        throw new RuntimeException('A real customer receipt allocation now exists for receivable 273; manual review is required.');
    }

    if ((int) ($state['posted_offsets']['allocation_count'] ?? -1) !== 0
        || ! $moneyEquals($state['posted_offsets']['allocation_amount'] ?? null, 0.0)) {
        throw new RuntimeException('An active linked-party adjustment now covers receivable 273; manual review is required.');
    }

    $historicalOffsets = $state['historical_offsets'];
    if (count($historicalOffsets) !== 1
        || (int) $historicalOffsets[0]['id'] !== 6
        || (string) $historicalOffsets[0]['offset_no'] !== 'AUTO-OFFSET-20260729200242-2BCA7A'
        || (string) $historicalOffsets[0]['status'] !== 'void'
        || ! $moneyEquals($historicalOffsets[0]['amount'], 18872.0)
        || ! $moneyEquals($historicalOffsets[0]['allocated_amount'], 585.0)
        || (int) $historicalOffsets[0]['journal_entry_id'] !== 2746
        || (int) $historicalOffsets[0]['reversal_journal_entry_id'] <= 0) {
        throw new RuntimeException('The reviewed historical automatic offset is missing or changed; nothing changed.');
    }

    if ((int) $state['automatic_history_repair_audit_count'] < 1
        || $state['offset_journal_difference'] !== []
        || (int) $state['release_journal_count'] !== 2
        || $state['release_journal_difference'] !== []) {
        throw new RuntimeException('The earlier automatic linked-settlement reversal is incomplete; run and verify that phase first.');
    }

    if (! $moneyEquals($state['ar_balance'], 585.0)) {
        throw new RuntimeException('Accounting AR is not exactly AED 585 for BK-000274; no operational-only repair is safe.');
    }

    $isDirty = $moneyEquals($record['allocated_amount'], 585.0)
        && $moneyEquals($record['outstanding_amount'], 0.0)
        && strcasecmp((string) $record['receivable_status'], 'paid') === 0;
    $isRepaired = $moneyEquals($record['allocated_amount'], 0.0)
        && $moneyEquals($record['outstanding_amount'], 585.0)
        && strcasecmp((string) $record['receivable_status'], 'open') === 0;

    if (! $isDirty && ! $isRepaired) {
        throw new RuntimeException('Receivable 273 is neither the reviewed dirty state nor the exact repaired state; nothing changed.');
    }

    return ['record' => $record, 'is_repaired' => $isRepaired];
};

try {
    $initialState = $loadState(false);
    $review = $assertReviewedState($initialState);
    $before = $review['record'];

    $report = [
        'mode' => $apply ? 'apply' : ($rehearse ? 'rehearse' : 'dry-run'),
        'booking_reference' => 'BK-000274',
        'service_id' => 273,
        'cancellation_event_id' => 38,
        'customer_receivable_item_id' => 273,
        'accounting_ar_balance' => 585,
        'real_customer_receipt_allocations' => 0,
        'active_linked_party_allocations' => 0,
        'before' => [
            'due_amount' => (float) $before['due_amount'],
            'allocated_amount' => (float) $before['allocated_amount'],
            'outstanding_amount' => (float) $before['outstanding_amount'],
            'status' => (string) $before['receivable_status'],
        ],
        'after' => [
            'due_amount' => 585,
            'allocated_amount' => 0,
            'outstanding_amount' => 585,
            'status' => 'open',
        ],
        'unchanged' => ['receipts', 'linked-party history', 'supplier balances', 'treasury', 'cancellation events', 'journals'],
    ];

    if ($review['is_repaired']) {
        $report['mode'] = 'no-change';
        $report['message'] = 'BK-000274 already has the exact repaired receivable state.';
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    if (! $apply && ! $rehearse) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $actorStatement = $db->prepare(
        'SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id AND u.is_active = 1 AND r.code = "super_admin"'
    );
    $actorStatement->execute(['id' => $actorUserId]);
    if ($actorStatement->fetchColumn() === false) {
        throw new RuntimeException('The supplied actor is not an active super administrator.');
    }

    $db->beginTransaction();
    $lockedReview = $assertReviewedState($loadState(true));
    if ($lockedReview['is_repaired']) {
        throw new RuntimeException('Receivable 273 changed while acquiring the lock; nothing changed.');
    }

    $updateStatement = $db->prepare(
        'UPDATE customer_receivable_items
         SET allocated_amount = 0,
             outstanding_amount = 585,
             status = "open"
         WHERE id = 273
           AND booking_reference = "BK-000274"
           AND service_line_reference = "SV-001"
           AND currency = "AED"
           AND due_amount = 585
           AND allocated_amount = 585
           AND outstanding_amount = 0
           AND status = "paid"'
    );
    $updateStatement->execute();
    if ($updateStatement->rowCount() !== 1) {
        throw new RuntimeException('The exact receivable row changed before update; transaction rolled back.');
    }

    $auditStatement = $db->prepare(
        'INSERT INTO audit_logs (event_name, actor_user_id, ip_address, user_agent, payload_json, created_at)
         VALUES ("customer.receivable.historical_header_repaired", :actor_user_id, NULL, "CLI repair", :payload_json, NOW())'
    );
    $auditStatement->execute([
        'actor_user_id' => $actorUserId,
        'payload_json' => json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    if ($auditStatement->rowCount() !== 1) {
        throw new RuntimeException('The mandatory repair audit log was not written; transaction rolled back.');
    }

    $verification = $assertReviewedState($loadState(false));
    if (! $verification['is_repaired']) {
        throw new RuntimeException('Post-repair verification failed; transaction rolled back.');
    }

    if ($rehearse) {
        $db->rollBack();
        $report['mode'] = 'rehearsed-and-rolled-back';
    } else {
        $db->commit();
        $report['mode'] = 'applied';
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
