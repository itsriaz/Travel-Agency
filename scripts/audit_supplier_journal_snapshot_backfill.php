<?php

declare(strict_types=1);

// Read-only inventory. This script deliberately does not update historical journals.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$db = \App\Core\App::bootstrap(BASE_PATH)->get('db');
$sources = ['obligation' => [], 'payment' => []];
foreach (['obligation' => 'supplier_obligations', 'payment' => 'supplier_payments'] as $kind => $table) {
    foreach ($db->query('SELECT id, supplier_id, created_at, ' . ($kind === 'obligation'
        ? 'booking_reference, service_line_reference' : 'payment_no') . ' FROM ' . $table) as $row) {
        $sources[$kind][(int) $row['id']] = $row;
    }
}

$history = ['obligation' => [], 'payment' => []];
$discarded = ['before_current_record' => 0, 'different_record_identity' => 0];
$audit = $db->query('SELECT id, event_name, payload_json, created_at FROM audit_logs
    WHERE event_name IN (
        "supplier.obligation.created", "supplier.obligation.updated",
        "supplier.payment.created", "supplier.payment.supplier_corrected", "supplier.payment.corrected"
    ) ORDER BY id');
foreach ($audit as $event) {
    $payload = json_decode((string) $event['payload_json'], true);
    if (! is_array($payload)) {
        continue;
    }
    $name = (string) $event['event_name'];
    $kind = str_starts_with($name, 'supplier.obligation.') ? 'obligation' : 'payment';
    $ids = $kind === 'obligation' ? [(int) ($payload['supplier_obligation_id'] ?? 0)]
        : ($name === 'supplier.payment.corrected'
            ? [(int) ($payload['original_supplier_payment_id'] ?? 0),
                (int) ($payload['replacement_supplier_payment_id'] ?? 0)]
            : [(int) ($payload['supplier_payment_id'] ?? 0)]);
    foreach ($ids as $id) {
        $source = $sources[$kind][$id] ?? null;
        if ($source === null) {
            continue;
        }
        if ((string) $event['created_at'] < (string) $source['created_at']) {
            $discarded['before_current_record']++;
            continue;
        }
        if (($kind === 'obligation'
                && ((string) ($payload['booking_reference'] ?? '') !== (string) $source['booking_reference']
                    || (string) ($payload['service_line_reference'] ?? '') !== (string) $source['service_line_reference']))
            || ($kind === 'payment' && isset($payload['payment_no'])
                && (string) $payload['payment_no'] !== (string) $source['payment_no'])) {
            $discarded['different_record_identity']++;
            continue;
        }
        $supplierIds = match ($name) {
            'supplier.payment.supplier_corrected' => [(int) ($payload['old_supplier_id'] ?? 0),
                (int) ($payload['new_supplier_id'] ?? 0)],
            'supplier.payment.corrected' => [(int) ($payload['before']['supplier_id'] ?? 0),
                (int) ($payload['after']['supplier_id'] ?? 0)],
            default => [(int) ($payload['supplier_id'] ?? 0)],
        };
        foreach ($supplierIds as $supplierId) {
            if ($supplierId > 0) {
                $history[$kind][$id][$supplierId] = true;
            }
        }
    }
}

$counts = [];
$exceptions = [];
$candidates = [];
$lines = $db->query('SELECT jel.id, jel.supplier_obligation_id, jel.supplier_payment_id,
        jel.supplier_id_snapshot, so.supplier_id AS obligation_supplier_id,
        sp.supplier_id AS payment_supplier_id, je.source_type, je.source_reference
    FROM journal_entry_lines jel
    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
    LEFT JOIN supplier_obligations so ON so.id = jel.supplier_obligation_id
    LEFT JOIN supplier_payments sp ON sp.id = jel.supplier_payment_id
    WHERE jel.supplier_obligation_id IS NOT NULL OR jel.supplier_payment_id IS NOT NULL
    ORDER BY jel.id');
foreach ($lines as $line) {
    $kind = (int) $line['supplier_obligation_id'] > 0 ? 'obligation' : 'payment';
    $sourceId = (int) ($kind === 'obligation' ? $line['supplier_obligation_id'] : $line['supplier_payment_id']);
    $supplierId = (int) ($kind === 'obligation'
        ? $line['obligation_supplier_id'] : $line['payment_supplier_id']);
    $seen = array_keys($history[$kind][$sourceId] ?? []);
    $conflictingPayment = $kind === 'obligation' && (int) $line['supplier_payment_id'] > 0
        && (int) $line['payment_supplier_id'] !== $supplierId;
    if ((int) $line['supplier_id_snapshot'] > 0) {
        $classification = 'already_snapshotted';
    } elseif ($supplierId <= 0 || $seen === []) {
        $classification = 'missing_source_or_audit';
    } elseif (count($seen) !== 1 || (int) $seen[0] !== $supplierId || $conflictingPayment) {
        $classification = 'supplier_history_or_link_conflict';
    } else {
        $classification = 'candidate_requires_ledger_rehearsal';
    }
    $counts[$classification] = ($counts[$classification] ?? 0) + 1;
    if ($classification === 'candidate_requires_ledger_rehearsal') {
        $candidates[] = [
            'journal_line_id' => (int) $line['id'],
            'supplier_id' => $supplierId,
            'source_kind' => $kind,
            'source_id' => $sourceId,
        ];
    }
    if ($classification !== 'candidate_requires_ledger_rehearsal'
        && $classification !== 'already_snapshotted') {
        $exceptions[] = [
            'journal_line_id' => (int) $line['id'],
            'classification' => $classification,
            'source_type' => $line['source_type'],
            'source_reference' => $line['source_reference'],
            'obligation_id' => $line['supplier_obligation_id'],
            'payment_id' => $line['supplier_payment_id'],
            'current_obligation_supplier_id' => $line['obligation_supplier_id'],
            'current_payment_supplier_id' => $line['payment_supplier_id'],
            'recorded_supplier_ids' => $seen,
        ];
    }
}
ksort($counts);
echo json_encode([
    'mode' => 'read_only',
    'discarded_audit_events' => $discarded,
    'classifications' => $counts,
    'candidates' => $candidates,
    'exceptions' => $exceptions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
