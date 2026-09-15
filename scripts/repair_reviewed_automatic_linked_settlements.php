<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['plan:', 'actor:', 'backup:', 'rehearse', 'apply']);
if (!isset($options['plan']) || (isset($options['apply']) && isset($options['rehearse']))) {
    fwrite(STDERR, "Usage: php scripts/repair_reviewed_automatic_linked_settlements.php --plan=preview.json --actor=ID [--rehearse | --apply --backup=database.sql]\n");
    exit(1);
}
$apply = isset($options['apply']);
$rehearse = isset($options['rehearse']);
try {
    $approved = json_decode((string) file_get_contents($options['plan']), true, 512, JSON_THROW_ON_ERROR);
    // Reuse the read-only preview, including its transaction and dimensional checks.
    $argv = [$argv[0]];
    ob_start();
    require __DIR__ . '/preview_automatic_linked_settlement_reversals.php';
    $current = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    $fingerprint = static function (array $value): string {
        unset($value['generated_at']);
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    };
    if ($fingerprint($approved) !== $fingerprint($current)) {
        throw new RuntimeException('Database differs from the reviewed preview. Generate and review a fresh preview.');
    }
    // This repair is restricted to the reviewed AJWA history, not arbitrary future offsets.
    $offsets = $current['automatic_offsets'];
    if (array_map('intval', array_column($offsets, 'id')) !== range(6, 32)
        || $current['automatic_totals_by_branch_currency'] !== ['2/AED' => 37703]) {
        throw new RuntimeException('Automatic settlement scope differs from the reviewed 27 AED adjustments.');
    }
    $actor = (int) ($options['actor'] ?? 0);
    $actorQuery = $db->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.code="super_admin"');
    $actorQuery->execute([$actor]);
    if (!$actorQuery->fetchColumn()) { throw new RuntimeException('Specify an active super administrator with --actor.'); }
    $release = $current['release_journal_evidence'];
    if (count($release) !== 2) { throw new RuntimeException('Release journal scope changed.'); }
    foreach ($release as $line) {
        if ((int) $line['id'] !== 3453 || $line['source_reference'] !== 'REL-OFFSET-AR-273'
            || $line['booking_reference'] !== 'BK-000274' || (int) $line['branch_id'] !== 2 || $line['currency'] !== 'AED'
            || !in_array($line['code'], ['AP_CONTROL', 'AR_CONTROL'], true)
            || (float) $line['debit_amount'] !== ($line['code'] === 'AP_CONTROL' ? 250.0 : 0.0)
            || (float) $line['credit_amount'] !== ($line['code'] === 'AR_CONTROL' ? 250.0 : 0.0)) {
            throw new RuntimeException('Release journal does not match the reviewed wrong-direction AED 250 posting.');
        }
    }
    foreach ($offsets as $offset) {
        $id = (int) $offset['id'];
        if ($offset['legacy_allocations'] !== [] || count($offset['original_journal']) !== 2
            || abs(array_sum(array_column($offset['account_allocations'], 'allocated_amount')) - (float) $offset['amount']) > .005
            || abs(array_sum(array_column($offset['supplier_allocations'], 'allocated_amount')) - (float) $offset['amount']) > .005) {
            throw new RuntimeException('Allocation/journal structure changed for offset ' . $id);
        }
        foreach ($offset['original_journal'] as $line) {
            $originalAmount = (float) $offset['amount'] + ($id === 6 ? 250 : 0);
            if ($line['source_type'] !== 'linked_party_offset' || $line['source_reference'] !== $offset['offset_no']
                || (int) $line['branch_id'] !== 2 || $line['currency'] !== 'AED'
                || !in_array($line['code'], ['AP_CONTROL', 'AR_CONTROL'], true)
                || (float) $line['debit_amount'] !== ($line['code'] === 'AP_CONTROL' ? $originalAmount : 0.0)
                || (float) $line['credit_amount'] !== ($line['code'] === 'AR_CONTROL' ? $originalAmount : 0.0)) {
                throw new RuntimeException('Unexpected journal for offset ' . $id);
            }
        }
    }
    $supplierPlan = $current['supplier_invoice_preview'];
    if (count($supplierPlan) !== 33 || abs(array_sum(array_column($supplierPlan, 'candidate_payable_preserving_payments_advances_and_manual_offsets')) - 29362) > .005) {
        throw new RuntimeException('Supplier balance plan differs from the reviewed AED 29,362.');
    }
    if (!$apply && !$rehearse) {
        echo "Validated preview: 27 automatic offsets, 33 supplier balances, AED 29,362 payable. No writes.\n";
        exit;
    }
    if ($apply && (!isset($options['backup']) || !is_file($options['backup']) || filesize($options['backup']) < 4096)) {
        throw new RuntimeException('A database SQL backup is required before applying.');
    }
    $db->beginTransaction();
    $ids = implode(',', range(6, 32));
    $locked = $db->query('SELECT * FROM counterparty_offsets WHERE id IN (' . $ids . ') ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($locked as $i => $row) {
        foreach ($row as $key => $value) {
            if ($value !== $offsets[$i][$key]) { throw new RuntimeException('Settlement changed while acquiring locks.'); }
        }
    }
    $supplierIds = implode(',', array_map('intval', array_column($supplierPlan, 'id')));
    $db->query('SELECT id FROM supplier_obligations WHERE id IN (' . $supplierIds . ') ORDER BY id FOR UPDATE')->fetchAll();
    $currentSupplier = $db->prepare('SELECT gross_amount,advance_applied_amount,net_payable_amount,
        COALESCE((SELECT SUM(allocated_amount) FROM supplier_payment_allocations WHERE supplier_obligation_id=s.id),0) AS payments,
        COALESCE((SELECT SUM(a.allocated_amount) FROM counterparty_offset_payable_allocations a
            JOIN counterparty_offsets o ON o.id=a.counterparty_offset_id AND o.status="posted"
            WHERE a.supplier_obligation_id=s.id),0) AS offsets
        FROM supplier_obligations s WHERE s.id=?');
    foreach ($supplierPlan as $row) {
        $currentSupplier->execute([(int) $row['id']]);
        $live = $currentSupplier->fetch(PDO::FETCH_ASSOC);
        foreach (['gross_amount'=>'gross_amount','advance_applied_amount'=>'advance_applied_amount',
            'net_payable_amount'=>'current_payable','payments'=>'payment_allocations','offsets'=>'all_active_offsets'] as $liveKey=>$planKey) {
            if (!$live || abs((float) $live[$liveKey] - (float) $row[$planKey]) > .005) {
                throw new RuntimeException('Supplier allocations changed while acquiring locks.');
            }
        }
    }
    // Capture protected financial records and retain the exact allocation rows.
    $protectedTables = ['customer_receipts','customer_receipt_allocations','customer_receivable_items','supplier_payments',
        'supplier_payment_allocations','supplier_advances','supplier_advance_applications','treasury_accounts','treasury_transactions',
        'counterparty_offset_account_allocations','counterparty_offset_receivable_allocations','counterparty_offset_payable_allocations'];
    $snapshots = [];
    foreach ($protectedTables as $table) {
        $snapshots[$table] = hash('sha256', json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
    }
    $accounting = new \App\Repositories\AccountingRepository($app);
    $reversalIds = [];
    $updateOffset = $db->prepare('UPDATE counterparty_offsets SET status="void", reversal_journal_entry_id=?, voided_by_user_id=?, voided_at=NOW(), void_reason=? WHERE id=? AND status="posted"');
    $reason = 'Reviewed manual-only conversion: reverse automatic history; rebuild supplier payable from genuine allocations. Repair AUTO-LINK-20260909.';
    foreach ($offsets as $offset) {
        $reversalId = $accounting->reverseJournalEntry((int) $offset['journal_entry_id'], [
            'source_type' => 'linked_party_offset_void', 'source_reference' => 'VOID-' . $offset['offset_no'],
            'entry_date' => date('Y-m-d'), 'narration' => $reason, 'actor_user_id' => $actor,
        ]);
        if (!$reversalId) { throw new RuntimeException('Could not reverse original journal.'); }
        $updateOffset->execute([$reversalId, $actor, $reason, (int) $offset['id']]);
        if ($updateOffset->rowCount() !== 1) { throw new RuntimeException('Settlement already changed.'); }
        $reversalIds[] = $reversalId;
    }
    $releaseReversal = $accounting->reverseJournalEntry(3453, [
        'source_type' => 'linked_party_offset_release_void', 'source_reference' => 'VOID-REL-OFFSET-AR-273-3453',
        'entry_date' => date('Y-m-d'), 'narration' => 'Reverse wrong-direction AED 250 release together with original automatic history. AUTO-LINK-20260909.',
        'actor_user_id' => $actor,
    ]);
    if (!$releaseReversal) { throw new RuntimeException('Release reversal failed.'); }
    $reversalIds[] = $releaseReversal;
    $updateSupplier = $db->prepare('UPDATE supplier_obligations SET net_payable_amount=?, status=? WHERE id=? AND gross_amount=? AND net_payable_amount=? AND advance_applied_amount=?');
    foreach ($supplierPlan as $row) {
        $amount = (float) $row['candidate_payable_preserving_payments_advances_and_manual_offsets'];
        if ($amount < 0) { throw new RuntimeException('Negative target payable.'); }
        $status = $amount <= .005 ? 'paid' : ($amount + .005 < (float) $row['gross_amount'] ? 'partially_covered' : 'open');
        $updateSupplier->execute([$amount, $status, (int) $row['id'], $row['gross_amount'], $row['current_payable'], $row['advance_applied_amount']]);
        if ($updateSupplier->rowCount() !== 1) { throw new RuntimeException('Supplier balance changed or was not updated.'); }
    }
    foreach ($protectedTables as $table) {
        $after = hash('sha256', json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
        if ($after !== $snapshots[$table]) { throw new RuntimeException('Protected data changed: ' . $table); }
    }
    foreach ($current['manual_offsets_to_preserve'] as $manual) {
        $row = $db->query('SELECT id,offset_no,branch_id,currency,amount FROM counterparty_offsets WHERE status="posted" AND id=' . (int) $manual['id'])->fetch(PDO::FETCH_ASSOC);
        if ($row !== $manual) { throw new RuntimeException('Manual settlement changed.'); }
    }
    $originalIds = implode(',', array_merge(array_map('intval', array_column($offsets, 'journal_entry_id')), [3453]));
    $net = $db->query('SELECT l.account_id,SUM(l.debit_amount-l.credit_amount) AS net FROM journal_entry_lines l WHERE l.journal_entry_id IN (' . $originalIds . ',' . implode(',', $reversalIds) . ') GROUP BY l.account_id HAVING ABS(net) > 0.005')->fetchAll();
    if ($net !== []) { throw new RuntimeException('Historical journals and reversals do not cancel exactly.'); }
    $report = ['mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back', 'automatic_offsets_reversed' => 27,
        'supplier_payable_AED' => 29362, 'supplier_invoices' => 33, 'journal_reversals' => $reversalIds,
        'manual_offsets_preserved' => $current['manual_offsets_to_preserve'], 'protected_data_checks' => array_keys($snapshots),
        'review_fingerprint' => $fingerprint($approved), 'before' => $supplierPlan];
    $audit = $db->prepare('INSERT INTO audit_logs (event_name,actor_user_id,payload_json) VALUES ("counterparty.automatic_history.repaired",?,?)');
    $audit->execute([$actor, json_encode($report, JSON_THROW_ON_ERROR)]);
    if ($apply) { $db->commit(); } else { $db->rollBack(); }
    unset($report['before']);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
