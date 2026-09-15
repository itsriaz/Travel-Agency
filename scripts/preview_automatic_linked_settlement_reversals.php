<?php

declare(strict_types=1);

// Deliberately read-only: this preview is not a repair or a bulk void command.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (count($argv) > 1) {
    fwrite(STDERR, "This command accepts no arguments and cannot apply changes.\n");
    exit(1);
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

try {
    $app = \App\Core\App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $read = static function (string $sql) use ($db): array {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    };
    $offsets = $read('SELECT o.*, bs.name AS account_holder, s.name AS supplier
        FROM counterparty_offsets o
        JOIN business_sources bs ON bs.id = o.business_source_id
        JOIN suppliers s ON s.id = o.supplier_id
        WHERE o.status = "posted" AND o.offset_no LIKE "AUTO-OFFSET-%" ORDER BY o.id');
    $totals = [];
    $issues = [];
    foreach ($offsets as &$offset) {
        $id = (int) $offset['id'];
        $offset['account_allocations'] = $read('SELECT a.*, r.booking_reference, r.service_line_reference,
            r.branch_id, r.currency, r.due_amount, r.allocated_amount AS customer_paid,
            r.outstanding_amount AS passenger_due, b.business_source_id
            FROM counterparty_offset_account_allocations a
            JOIN customer_receivable_items r ON r.id = a.customer_receivable_item_id
            JOIN bookings b ON b.booking_reference = r.booking_reference
            WHERE a.counterparty_offset_id = ' . $id . ' ORDER BY a.id');
        $offset['legacy_allocations'] = $read('SELECT * FROM counterparty_offset_receivable_allocations
            WHERE counterparty_offset_id = ' . $id);
        $offset['supplier_allocations'] = $read('SELECT a.*, s.booking_reference, s.service_line_reference,
            s.branch_id, s.currency, s.supplier_id
            FROM counterparty_offset_payable_allocations a
            JOIN supplier_obligations s ON s.id = a.supplier_obligation_id
            WHERE a.counterparty_offset_id = ' . $id . ' ORDER BY a.id');
        $offset['original_journal'] = $read('SELECT j.id, j.branch_id, j.currency, j.source_type, j.source_reference,
            c.code, l.debit_amount, l.credit_amount
            FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
            JOIN chart_of_accounts c ON c.id = l.account_id
            WHERE j.id = ' . (int) $offset['journal_entry_id'] . ' ORDER BY l.id');
        $amount = (float) $offset['amount'];
        $accountTotal = array_sum(array_column($offset['account_allocations'], 'allocated_amount'));
        $supplierTotal = array_sum(array_column($offset['supplier_allocations'], 'allocated_amount'));
        $journalDebit = array_sum(array_column($offset['original_journal'], 'debit_amount'));
        $journalCredit = array_sum(array_column($offset['original_journal'], 'credit_amount'));
        $offset['original_journal_minus_current_allocation'] = round($journalDebit - $amount, 2);
        if ($offset['legacy_allocations'] !== [] || abs($accountTotal - $amount) > 0.005 || abs($supplierTotal - $amount) > 0.005) {
            $issues[] = ['offset_id' => $id, 'issue' => 'Allocation totals or legacy allocations require review.'];
        }
        if (abs($journalDebit - $amount) > 0.005 || abs($journalCredit - $amount) > 0.005) {
            $issues[] = ['offset_id' => $id, 'issue' => 'Original journal does not match current allocations; trace releases before reversal.'];
        }
        foreach (['account_allocations', 'supplier_allocations'] as $side) {
            foreach ($offset[$side] as $row) {
                $partyKey = $side === 'account_allocations' ? 'business_source_id' : 'supplier_id';
                if ((int) $row['branch_id'] !== (int) $offset['branch_id'] || $row['currency'] !== $offset['currency']
                    || (int) $row[$partyKey] !== (int) $offset[$partyKey]) {
                    $issues[] = ['offset_id' => $id, 'issue' => 'Allocation party, branch, or currency differs from settlement.'];
                }
            }
        }
        $key = $offset['branch_id'] . '/' . $offset['currency'];
        $totals[$key] = round(($totals[$key] ?? 0) + $amount, 2);
    }
    unset($offset);

    $supplierPreview = $read('SELECT s.id, s.booking_reference, s.service_line_reference, s.branch_id, s.currency,
        s.gross_amount, s.advance_applied_amount, s.net_payable_amount AS current_payable,
        COALESCE((SELECT SUM(p.allocated_amount) FROM supplier_payment_allocations p WHERE p.supplier_obligation_id = s.id), 0) AS payment_allocations,
        COALESCE((SELECT SUM(a.allocated_amount) FROM counterparty_offset_payable_allocations a
            JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id AND o.status = "posted"
            WHERE a.supplier_obligation_id = s.id), 0) AS all_active_offsets,
        COALESCE((SELECT SUM(a.allocated_amount) FROM counterparty_offset_payable_allocations a
            JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id AND o.status = "posted"
            WHERE a.supplier_obligation_id = s.id AND o.offset_no LIKE "AUTO-OFFSET-%"), 0) AS automatic_offsets
        FROM supplier_obligations s WHERE EXISTS (SELECT 1 FROM counterparty_offset_payable_allocations a
            JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id
            WHERE a.supplier_obligation_id = s.id AND o.status = "posted" AND o.offset_no LIKE "AUTO-OFFSET-%") ORDER BY s.id');
    foreach ($supplierPreview as &$row) {
        $withoutOffsets = (float) $row['gross_amount'] - (float) $row['advance_applied_amount'] - (float) $row['payment_allocations'];
        $row['current_reconciliation_difference'] = round($withoutOffsets - (float) $row['all_active_offsets'] - (float) $row['current_payable'], 2);
        $row['existing_void_routine_would_produce'] = round((float) $row['current_payable'] + (float) $row['automatic_offsets'], 2);
        $row['candidate_payable_preserving_payments_advances_and_manual_offsets'] = round($withoutOffsets - ((float) $row['all_active_offsets'] - (float) $row['automatic_offsets']), 2);
        if (abs($row['current_reconciliation_difference']) > 0.005) {
            $issues[] = ['supplier_obligation_id' => (int) $row['id'], 'booking' => $row['booking_reference'], 'issue' => 'Current payable does not reconcile with gross less payment, advance and offset allocations.'];
        }
    }
    unset($row);
    $accountPreview = $read('SELECT r.id, r.booking_reference, r.service_line_reference, r.currency, r.branch_id,
        r.due_amount AS account_sale_basis, r.allocated_amount AS customer_paid, r.outstanding_amount AS passenger_due,
        SUM(a.allocated_amount) AS all_active_offsets,
        SUM(CASE WHEN o.offset_no LIKE "AUTO-OFFSET-%" THEN a.allocated_amount ELSE 0 END) AS automatic_offsets
        FROM customer_receivable_items r JOIN counterparty_offset_account_allocations a ON a.customer_receivable_item_id = r.id
        JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id AND o.status = "posted"
        GROUP BY r.id HAVING automatic_offsets > 0 ORDER BY r.id');
    foreach ($accountPreview as &$row) {
        $row['available_account_basis_before'] = round(max((float) $row['account_sale_basis'] - (float) $row['all_active_offsets'], 0), 2);
        $row['available_account_basis_after_removing_automatic_offsets'] = round(max((float) $row['account_sale_basis'] - ((float) $row['all_active_offsets'] - (float) $row['automatic_offsets']), 0), 2);
        if ((float) $row['all_active_offsets'] - (float) $row['account_sale_basis'] > 0.005) {
            $issues[] = ['receivable_id' => (int) $row['id'], 'issue' => 'Active account offsets exceed account sale basis.'];
        }
    }
    unset($row);
    $releases = $read('SELECT j.id, j.branch_id, j.currency, j.booking_reference, j.source_reference,
        j.entry_date, c.code, l.debit_amount, l.credit_amount
        FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
        JOIN chart_of_accounts c ON c.id = l.account_id
        WHERE j.source_type = "linked_party_offset_release" ORDER BY j.id, l.id');
    $manual = $read('SELECT id, offset_no, branch_id, currency, amount FROM counterparty_offsets
        WHERE status = "posted" AND offset_no NOT LIKE "AUTO-OFFSET-%" ORDER BY id');
    $db->rollBack();
    echo json_encode([
        'mode' => 'read-only-preview', 'generated_at' => date(DATE_ATOM),
        'automatic_count' => count($offsets), 'automatic_totals_by_branch_currency' => $totals,
        'review_required' => $issues, 'automatic_offsets' => $offsets,
        'supplier_invoice_preview' => $supplierPreview, 'account_invoice_preview' => $accountPreview,
        'release_journal_evidence' => $releases, 'manual_offsets_to_preserve' => $manual,
        'note' => 'Candidate balances are for review, not approved repair values. Passenger paid/due and real receipts/payments are not changed. Release journals require individual attribution.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
