<?php

declare(strict_types=1);

// One reviewed production snapshot only. Never use this as a generic offset void command.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = "Usage: php scripts/repair_reviewed_automatic_linked_settlements_20260922.php --plan=preview.json [--rehearse --actor=ID | --apply --actor=ID --backup=database.sql]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:plan=.+|actor=\d+|backup=.+|rehearse|apply)$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}
$options = getopt('', ['plan:', 'actor:', 'backup:', 'rehearse', 'apply']);
$apply = isset($options['apply']);
$rehearse = isset($options['rehearse']);
if (! isset($options['plan']) || ($apply && $rehearse)) {
    fwrite(STDERR, $usage);
    exit(1);
}

try {
    $planJson = file_get_contents((string) $options['plan']);
    if ($planJson === false) {
        throw new RuntimeException('The reviewed preview file cannot be read.');
    }
    $approved = json_decode($planJson, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($approved)) {
        throw new RuntimeException('The reviewed preview must be a JSON object.');
    }

    // The preview owns a read-only transaction and leaves $app and $db available here.
    $argv = [$argv[0]];
    ob_start();
    require __DIR__ . '/preview_automatic_linked_settlement_reversals.php';
    $current = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    $fingerprint = static function (array $value): string {
        unset($value['generated_at']);
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    };
    if ($fingerprint($approved) !== $fingerprint($current)) {
        throw new RuntimeException('Database differs from the reviewed preview. Stop and review a fresh snapshot.');
    }

    $offsets = $current['automatic_offsets'];
    $suppliers = $current['supplier_invoice_preview'];
    $accounts = $current['account_invoice_preview'];
    if (array_map('intval', array_column($offsets, 'id')) !== range(6, 53)
        || (int) $current['automatic_count'] !== 48
        || $current['automatic_totals_by_branch_currency'] !== ['2/AED' => 48920]
        || $current['manual_offsets_to_preserve'] !== []
        || count($suppliers) !== 51
        || count($accounts) !== 27
        || abs(array_sum(array_column($suppliers, 'candidate_payable_preserving_payments_advances_and_manual_offsets')) - 39464) > 0.005) {
        throw new RuntimeException('The reviewed AJWA 48-offset / 51-invoice AED scope changed.');
    }
    $expectedSupplierWarnings = [75, 283, 425, 472, 505, 538, 539, 613, 629, 671, 696, 817];
    $warnings = [];
    foreach ($current['review_required'] as $warning) {
        if (isset($warning['offset_id']) && $warning['issue'] === 'Original journal does not match current allocations; trace releases before reversal.') {
            $warnings[] = 'offset:' . $warning['offset_id'];
        } elseif (isset($warning['supplier_obligation_id']) && $warning['issue'] === 'Current payable does not reconcile with gross less payment, advance and offset allocations.') {
            $warnings[] = 'supplier:' . $warning['supplier_obligation_id'];
        } else {
            throw new RuntimeException('An unreviewed preview warning is present.');
        }
    }
    $expectedWarnings = array_merge(['offset:6', 'offset:16'], array_map(static fn (int $id): string => 'supplier:' . $id, $expectedSupplierWarnings));
    sort($warnings);
    sort($expectedWarnings);
    if ($warnings !== $expectedWarnings) {
        throw new RuntimeException('The partial releases or supplier reconciliation warnings changed.');
    }

    foreach ($offsets as $offset) {
        $id = (int) $offset['id'];
        $original = (float) $offset['amount'] + ($id === 6 ? 255 : ($id === 16 ? 10 : 0));
        if ((int) $offset['link_id'] !== 6 || (int) $offset['business_source_id'] !== 12
            || (int) $offset['supplier_id'] !== 412 || (int) $offset['branch_id'] !== 2
            || $offset['currency'] !== 'AED' || $offset['status'] !== 'posted'
            || ! str_starts_with($offset['offset_no'], 'AUTO-OFFSET-')
            || $offset['reversal_journal_entry_id'] !== null
            || $offset['legacy_allocations'] !== [] || count($offset['original_journal']) !== 2
            || abs(array_sum(array_column($offset['account_allocations'], 'allocated_amount')) - (float) $offset['amount']) > 0.005
            || abs(array_sum(array_column($offset['supplier_allocations'], 'allocated_amount')) - (float) $offset['amount']) > 0.005) {
            throw new RuntimeException('Offset ' . $id . ' is outside the reviewed structure.');
        }
        $seenCodes = [];
        foreach ($offset['original_journal'] as $line) {
            $code = (string) $line['code'];
            if (isset($seenCodes[$code]) || ! in_array($code, ['AR_CONTROL', 'AP_CONTROL'], true)
                || (int) $line['id'] !== (int) $offset['journal_entry_id']
                || (int) $line['branch_id'] !== 2 || $line['currency'] !== 'AED'
                || $line['source_type'] !== 'linked_party_offset' || $line['source_reference'] !== $offset['offset_no']
                || abs((float) $line['debit_amount'] - ($code === 'AP_CONTROL' ? $original : 0)) > 0.005
                || abs((float) $line['credit_amount'] - ($code === 'AR_CONTROL' ? $original : 0)) > 0.005) {
                throw new RuntimeException('The original journal differs for offset ' . $id . '.');
            }
            $seenCodes[$code] = true;
        }
    }

    $releaseScope = [
        3453 => ['reference' => 'REL-OFFSET-AR-273', 'booking' => 'BK-000274', 'ar' => -250, 'ap' => 250],
        4619 => ['reference' => 'REL-OFFSET-AR-441', 'booking' => 'BK-000442', 'ar' => -10, 'ap' => 10],
        4629 => ['reference' => 'REL-OFFSET-75', 'booking' => 'BK-000075', 'ar' => 5, 'ap' => -5],
    ];
    if (count($current['release_journal_evidence']) !== 6) {
        throw new RuntimeException('The linked-release journal count changed.');
    }
    $releaseSeen = [];
    foreach ($current['release_journal_evidence'] as $line) {
        $id = (int) $line['id'];
        $code = (string) $line['code'];
        $scope = $releaseScope[$id] ?? null;
        $net = $code === 'AR_CONTROL' ? ($scope['ar'] ?? null) : ($code === 'AP_CONTROL' ? ($scope['ap'] ?? null) : null);
        if ($scope === null || $net === null || isset($releaseSeen[$id][$code])
            || (int) $line['branch_id'] !== 2 || $line['currency'] !== 'AED'
            || $line['booking_reference'] !== $scope['booking'] || $line['source_reference'] !== $scope['reference']
            || abs((float) $line['debit_amount'] - max($net, 0)) > 0.005
            || abs((float) $line['credit_amount'] - max(-$net, 0)) > 0.005) {
            throw new RuntimeException('A linked-release journal differs from the reviewed three journals.');
        }
        $releaseSeen[$id][$code] = true;
    }
    foreach (array_keys($releaseScope) as $id) {
        if (count($releaseSeen[$id] ?? []) !== 2) {
            throw new RuntimeException('A reviewed linked-release journal is incomplete.');
        }
    }

    $expectedSupplierIds = array_map('intval', array_column($suppliers, 'id'));
    if (count(array_unique($expectedSupplierIds)) !== 51 || count(array_unique(array_map('intval', array_column($accounts, 'id')))) !== 27) {
        throw new RuntimeException('Duplicate supplier or account invoice in the review plan.');
    }
    foreach ($suppliers as $supplier) {
        if (abs((float) $supplier['current_payable']) > 0.005
            || abs((float) $supplier['payment_allocations']) > 0.005
            || abs((float) $supplier['advance_applied_amount']) > 0.005
            || abs((float) $supplier['all_active_offsets'] - (float) $supplier['automatic_offsets']) > 0.005
            || abs((float) $supplier['candidate_payable_preserving_payments_advances_and_manual_offsets'] - (float) $supplier['gross_amount']) > 0.005
            || (float) $supplier['gross_amount'] <= 0) {
            throw new RuntimeException('Supplier invoice ' . $supplier['id'] . ' has unreviewed cash, advance, or payable truth.');
        }
    }
    $ids = implode(',', $expectedSupplierIds);
    $costCheckSql = 'SELECT COUNT(*) FROM supplier_obligations s
        JOIN bookings b ON b.booking_reference = s.booking_reference
        JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = s.service_line_reference
        WHERE s.id IN (' . $ids . ') AND s.supplier_id = 412 AND s.branch_id = 2
          AND s.obligation_group = "service_cost" AND s.currency = "AED"
          AND bs.supplier_id = s.supplier_id AND bs.cost_currency = s.currency
          AND bs.purchase_cost = s.gross_amount AND b.branch_id = s.branch_id
          AND bs.service_status NOT IN ("Cancelled", "Voided")';
    $verifiedCosts = (int) $db->query($costCheckSql)->fetchColumn();
    if ($verifiedCosts !== 51) {
        throw new RuntimeException('Supplier gross balances no longer match all 51 active service costs.');
    }

    $receivables = [273 => ['booking' => 'BK-000274', 'due' => 585, 'service' => 'Cancelled', 'ar_before' => 335],
        441 => ['booking' => 'BK-000442', 'due' => 1540, 'service' => 'Open', 'ar_before' => 1530]];
    $readReceivable = static function (int $id, bool $lock = false) use ($db): array {
        $statement = $db->query('SELECT r.id,r.booking_reference,r.branch_id,r.currency,r.due_amount,r.allocated_amount,r.outstanding_amount,r.status,
            b.business_source_id,bs.service_status,
            (SELECT COALESCE(SUM(a.receivable_amount_allocated),0) FROM customer_receipt_allocations a WHERE a.customer_receivable_item_id=r.id) AS receipt_allocated
            FROM customer_receivable_items r JOIN bookings b ON b.booking_reference=r.booking_reference
            JOIN booking_services bs ON bs.booking_id=b.id AND bs.line_reference=r.service_line_reference
            WHERE r.id=' . $id . ($lock ? ' FOR UPDATE' : ''));
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $arBalance = static function (string $booking) use ($db): float {
        $statement = $db->prepare('SELECT COALESCE(SUM(l.debit_amount-l.credit_amount),0)
            FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id=j.id
            JOIN chart_of_accounts c ON c.id=l.account_id
            WHERE j.booking_reference=? AND c.code="AR_CONTROL"');
        $statement->execute([$booking]);
        return round((float) $statement->fetchColumn(), 2);
    };
    $assertReceivables = static function (bool $after, bool $lock = false) use ($receivables, $readReceivable, $arBalance): void {
        foreach ($receivables as $id => $scope) {
            $row = $readReceivable($id, $lock);
            $targetAllocated = $after ? 0 : $scope['due'];
            $targetOutstanding = $after ? $scope['due'] : 0;
            if ((int) ($row['id'] ?? 0) !== $id || $row['booking_reference'] !== $scope['booking']
                || (int) $row['branch_id'] !== 2 || (int) $row['business_source_id'] !== 12
                || $row['currency'] !== 'AED' || $row['service_status'] !== $scope['service']
                || abs((float) $row['due_amount'] - $scope['due']) > 0.005
                || abs((float) $row['allocated_amount'] - $targetAllocated) > 0.005
                || abs((float) $row['outstanding_amount'] - $targetOutstanding) > 0.005
                || $row['status'] !== ($after ? 'open' : 'paid')
                || abs((float) $row['receipt_allocated']) > 0.005
                || abs($arBalance($scope['booking']) - ($after ? $scope['due'] : $scope['ar_before'])) > 0.005) {
                throw new RuntimeException('Customer receivable ' . $id . ' differs from reviewed receipt and accounting truth.');
            }
        }
    };
    $assertReceivables(false);

    if (! $apply && ! $rehearse) {
        echo json_encode(['mode' => 'validated-no-writes', 'automatic_offsets' => 48, 'offset_amount_AED' => 48920,
            'supplier_invoices' => 51, 'supplier_payable_after_AED' => 39464,
            'false_paid_receivables' => [273, 441], 'release_journals_to_reverse' => array_keys($releaseScope),
            'review_fingerprint' => $fingerprint($approved)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $actor = (int) ($options['actor'] ?? 0);
    $actorStatement = $db->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
        WHERE u.id=? AND u.is_active=1 AND r.code="super_admin"');
    $actorStatement->execute([$actor]);
    if (! $actorStatement->fetchColumn()) {
        throw new RuntimeException('Specify an active super administrator with --actor=ID.');
    }
    if ($apply && (! isset($options['backup']) || ! is_readable($options['backup']) || filesize($options['backup']) < 4096)) {
        throw new RuntimeException('A readable SQL backup file is required before applying.');
    }

    $db->beginTransaction();
    $locked = $db->query('SELECT id,offset_no,status,amount,journal_entry_id,reversal_journal_entry_id,branch_id,currency,link_id,business_source_id,supplier_id
        FROM counterparty_offsets WHERE id BETWEEN 6 AND 53 ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if (count($locked) !== 48) {
        throw new RuntimeException('Offsets changed while acquiring locks.');
    }
    foreach ($locked as $index => $row) {
        foreach ($row as $key => $value) {
            if ($value !== $offsets[$index][$key]) {
                throw new RuntimeException('Offset ' . $row['id'] . ' changed while acquiring locks.');
            }
        }
    }
    $db->query('SELECT id FROM supplier_obligations WHERE id IN (' . $ids . ') ORDER BY id FOR UPDATE')->fetchAll();
    $supplierState = $db->prepare('SELECT s.gross_amount,s.net_payable_amount,s.advance_applied_amount,s.status,
        COALESCE((SELECT SUM(a.allocated_amount) FROM supplier_payment_allocations a WHERE a.supplier_obligation_id=s.id),0) AS payments,
        COALESCE((SELECT SUM(a.allocated_amount) FROM counterparty_offset_payable_allocations a
            JOIN counterparty_offsets o ON o.id=a.counterparty_offset_id AND o.status="posted"
            WHERE a.supplier_obligation_id=s.id),0) AS offsets
        FROM supplier_obligations s WHERE s.id=?');
    foreach ($suppliers as $supplier) {
        $supplierState->execute([(int) $supplier['id']]);
        $state = $supplierState->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($state['status'] !== 'paid'
            || abs((float) $state['gross_amount'] - (float) $supplier['gross_amount']) > 0.005
            || abs((float) $state['net_payable_amount']) > 0.005
            || abs((float) $state['advance_applied_amount']) > 0.005
            || abs((float) $state['payments']) > 0.005
            || abs((float) $state['offsets'] - (float) $supplier['all_active_offsets']) > 0.005) {
            throw new RuntimeException('Supplier invoice ' . $supplier['id'] . ' changed while acquiring locks.');
        }
    }
    if ((int) $db->query($costCheckSql)->fetchColumn() !== 51) {
        throw new RuntimeException('Service costs changed while acquiring supplier locks.');
    }
    $assertReceivables(false, true);

    // Hash every protected table; only offset headers, 51 payables, two receivable headers,
    // append-only journals and the audit log may change.
    $protected = ['customer_receipts', 'customer_receipt_allocations', 'supplier_payments',
        'supplier_payment_allocations', 'supplier_advances', 'supplier_advance_applications',
        'treasury_accounts', 'treasury_transactions', 'bookings', 'booking_services', 'booking_service_events',
        'counterparty_offset_account_allocations', 'counterparty_offset_receivable_allocations',
        'counterparty_offset_payable_allocations'];
    $protected['other_customer_receivables'] = 'customer_receivable_items WHERE id NOT IN (273,441)';
    $protected['other_supplier_obligations'] = 'supplier_obligations WHERE id NOT IN (' . $ids . ')';
    $hashes = [];
    foreach ($protected as $key => $table) {
        $name = is_int($key) ? $table : $key;
        $hashes[$name] = hash('sha256', json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
    }

    $accounting = new \App\Repositories\AccountingRepository($app);
    $reason = 'Reviewed 2026-09-22 AJWA automatic-settlement reversal; keep receivables and supplier payables separate.';
    $updateOffset = $db->prepare('UPDATE counterparty_offsets SET status="void",reversal_journal_entry_id=?,
        voided_by_user_id=?,voided_at=NOW(),void_reason=? WHERE id=? AND status="posted"');
    $reversalIds = [];
    foreach ($offsets as $offset) {
        $reversalId = $accounting->reverseJournalEntry((int) $offset['journal_entry_id'], [
            'source_type' => 'linked_party_offset_void', 'source_reference' => 'VOID-' . $offset['offset_no'],
            'entry_date' => date('Y-m-d'), 'narration' => $reason, 'actor_user_id' => $actor,
        ]);
        if (! $reversalId) {
            throw new RuntimeException('Could not reverse original offset journal ' . $offset['id'] . '.');
        }
        $updateOffset->execute([$reversalId, $actor, $reason, (int) $offset['id']]);
        if ($updateOffset->rowCount() !== 1) {
            throw new RuntimeException('Offset ' . $offset['id'] . ' changed during reversal.');
        }
        $reversalIds[] = $reversalId;
    }
    foreach ($releaseScope as $id => $scope) {
        $reversalId = $accounting->reverseJournalEntry($id, [
            'source_type' => 'linked_party_offset_release_void',
            'source_reference' => 'VOID-' . $scope['reference'] . '-' . $id,
            'entry_date' => date('Y-m-d'), 'narration' => $reason, 'actor_user_id' => $actor,
        ]);
        if (! $reversalId) {
            throw new RuntimeException('Could not reverse linked-release journal ' . $id . '.');
        }
        $reversalIds[] = $reversalId;
    }

    $updateSupplier = $db->prepare('UPDATE supplier_obligations SET net_payable_amount=?,status="open"
        WHERE id=? AND gross_amount=? AND net_payable_amount=0 AND advance_applied_amount=0 AND status="paid"');
    foreach ($suppliers as $supplier) {
        $updateSupplier->execute([$supplier['gross_amount'], (int) $supplier['id'], $supplier['gross_amount']]);
        if ($updateSupplier->rowCount() !== 1) {
            throw new RuntimeException('Supplier invoice ' . $supplier['id'] . ' could not be restored.');
        }
    }
    $updateReceivable = $db->prepare('UPDATE customer_receivable_items
        SET allocated_amount=0,outstanding_amount=?,status="open"
        WHERE id=? AND due_amount=? AND allocated_amount=? AND outstanding_amount=0 AND status="paid"');
    foreach ($receivables as $id => $scope) {
        $updateReceivable->execute([$scope['due'], $id, $scope['due'], $scope['due']]);
        if ($updateReceivable->rowCount() !== 1) {
            throw new RuntimeException('Customer receivable ' . $id . ' could not be restored.');
        }
    }

    $assertReceivables(true);
    $postPayable = $db->prepare('SELECT net_payable_amount,status FROM supplier_obligations WHERE id=?');
    foreach ($suppliers as $supplier) {
        $postPayable->execute([(int) $supplier['id']]);
        $row = $postPayable->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($row['status'] !== 'open' || abs((float) $row['net_payable_amount'] - (float) $supplier['gross_amount']) > 0.005) {
            throw new RuntimeException('Post-repair supplier verification failed.');
        }
    }
    foreach ($protected as $key => $table) {
        $name = is_int($key) ? $table : $key;
        $after = hash('sha256', json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
        if ($after !== $hashes[$name]) {
            throw new RuntimeException('Protected data changed: ' . $name);
        }
    }
    $originalIds = array_merge(array_map('intval', array_column($offsets, 'journal_entry_id')), array_keys($releaseScope));
    $journalIds = implode(',', array_merge($originalIds, $reversalIds));
    $net = $db->query('SELECT l.account_id,SUM(l.debit_amount-l.credit_amount) AS amount
        FROM journal_entry_lines l WHERE l.journal_entry_id IN (' . $journalIds . ')
        GROUP BY l.account_id HAVING ABS(amount)>0.005')->fetchAll();
    if ($net !== []) {
        throw new RuntimeException('Original offset and release journals do not net exactly to zero.');
    }
    $stillPosted = (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets
        WHERE status="posted" AND offset_no LIKE "AUTO-OFFSET-%"')->fetchColumn();
    if ($stillPosted !== 0) {
        throw new RuntimeException('An automatic linked settlement remains posted.');
    }

    $report = ['mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back',
        'automatic_offsets_reversed' => 48, 'original_offset_amount_AED' => 48920,
        'supplier_invoices_restored' => 51, 'supplier_payable_after_AED' => 39464,
        'customer_receivables_restored' => [273 => 585, 441 => 1540],
        'release_journals_reversed' => array_keys($releaseScope),
        'new_reversal_journal_ids' => $reversalIds,
        'protected_data_checks' => array_keys($hashes), 'review_fingerprint' => $fingerprint($approved)];
    $audit = $db->prepare('INSERT INTO audit_logs (event_name,actor_user_id,payload_json)
        VALUES ("counterparty.automatic_history.repaired",?,?)');
    $audit->execute([$actor, json_encode($report, JSON_THROW_ON_ERROR)]);
    if ($audit->rowCount() !== 1) {
        throw new RuntimeException('The repair audit log could not be written.');
    }
    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
