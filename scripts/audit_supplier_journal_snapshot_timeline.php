<?php

declare(strict_types=1);

// Resolve exceptional legacy lines from posting-time evidence; never writes data.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$db = \App\Core\App::bootstrap(BASE_PATH)->get('db');
$initial = json_decode((string) file_get_contents(
    BASE_PATH . '/storage/supplier-journal-snapshot-audit-20261025.json'
), true, 512, JSON_THROW_ON_ERROR);
if (($initial['mode'] ?? '') !== 'read_only') {
    throw new RuntimeException('The candidate inventory is not a read-only audit.');
}

$obligations = [];
foreach ($db->query('SELECT id, booking_reference, service_line_reference, created_at
    FROM supplier_obligations') as $row) {
    $obligations[(int) $row['id']] = $row;
}
$payments = [];
foreach ($db->query('SELECT id, payment_no, created_at FROM supplier_payments') as $row) {
    $payments[(int) $row['id']] = $row;
}

$obligationEvents = [];
$paymentCreated = [];
$corrections = [];
$events = $db->query('SELECT id, event_name, payload_json, created_at FROM audit_logs
    WHERE event_name IN ("supplier.obligation.created", "supplier.obligation.updated",
        "supplier.payment.created", "service.supplier_corrected") ORDER BY id');
foreach ($events as $event) {
    $payload = json_decode((string) $event['payload_json'], true);
    if (! is_array($payload)) {
        continue;
    }
    $name = (string) $event['event_name'];
    if (str_starts_with($name, 'supplier.obligation.')) {
        $id = (int) ($payload['supplier_obligation_id'] ?? 0);
        $source = $obligations[$id] ?? null;
        if ($source === null || (string) $event['created_at'] < (string) $source['created_at']
            || (string) ($payload['booking_reference'] ?? '') !== (string) $source['booking_reference']
            || (string) ($payload['service_line_reference'] ?? '') !== (string) $source['service_line_reference']) {
            continue;
        }
        $sid = (int) ($payload['supplier_id'] ?? 0);
        if ($sid > 0) {
            $obligationEvents[$id][] = [
                'at' => (string) $event['created_at'], 'supplier_id' => $sid,
                'audit_id' => (int) $event['id'], 'event' => $name,
            ];
        }
    } elseif ($name === 'supplier.payment.created') {
        $id = (int) ($payload['supplier_payment_id'] ?? 0);
        $source = $payments[$id] ?? null;
        if ($source !== null && (string) $event['created_at'] === (string) $source['created_at']
            && (string) ($payload['payment_no'] ?? '') === (string) $source['payment_no']
            && (int) ($payload['supplier_id'] ?? 0) > 0) {
            $paymentCreated[$id][] = [
                'supplier_id' => (int) $payload['supplier_id'],
                'audit_id' => (int) $event['id'],
            ];
        }
    } elseif ($name === 'service.supplier_corrected') {
        $key = (string) ($payload['booking_reference'] ?? '') . '|'
            . (string) ($payload['service_line_reference'] ?? '') . '|'
            . (string) $event['created_at'];
        $corrections[$key][] = [
            'prior_supplier_id' => (int) ($payload['prior_supplier_id'] ?? 0),
            'new_supplier_id' => (int) ($payload['new_supplier_id'] ?? 0),
            'released_amount' => (float) ($payload['released_settlement_amount'] ?? 0),
            'audit_id' => (int) $event['id'],
        ];
    }
}

$journal = $db->prepare('SELECT je.created_at, je.source_type, je.booking_reference,
        jel.supplier_obligation_id, jel.supplier_payment_id, jel.debit_amount, jel.credit_amount
    FROM journal_entry_lines jel
    INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
    WHERE jel.id = :line_id');
$resolved = [];
$unresolved = [];
$reasons = [];
foreach ($initial['exceptions'] as $exception) {
    $lineId = (int) $exception['journal_line_id'];
    $journal->execute(['line_id' => $lineId]);
    $line = $journal->fetch(PDO::FETCH_ASSOC);
    if (! is_array($line)) {
        throw new RuntimeException('Journal line disappeared during the audit: ' . $lineId);
    }
    $obligationId = (int) ($line['supplier_obligation_id'] ?? 0);
    $paymentId = (int) ($line['supplier_payment_id'] ?? 0);
    $supplierId = 0;
    $reason = '';
    $evidenceIds = [];
    if ($obligationId > 0) {
        $before = [];
        $at = [];
        foreach ($obligationEvents[$obligationId] ?? [] as $event) {
            if ($event['at'] < $line['created_at']) {
                $before[] = $event;
            } elseif ($event['at'] === $line['created_at']) {
                $at[] = $event;
            }
        }
        $latest = $before === [] ? null : end($before);
        $atIds = array_values(array_unique(array_column($at, 'supplier_id')));
        if ($latest === null && $line['source_type'] === 'supplier_payable_created'
            && count($atIds) === 1) {
            $supplierId = (int) $atIds[0];
            $reason = 'matching_creation_event';
            $evidenceIds = array_column($at, 'audit_id');
        } elseif ($latest !== null && ($atIds === [] || $atIds === [(int) $latest['supplier_id']])) {
            $supplierId = (int) $latest['supplier_id'];
            $reason = 'supplier_history_before_posting';
            $evidenceIds = [(int) $latest['audit_id']];
        } elseif ($latest !== null && $line['source_type'] === 'supplier_settlement_released') {
            $source = $obligations[$obligationId];
            $key = (string) $source['booking_reference'] . '|'
                . (string) $source['service_line_reference'] . '|'
                . (string) $line['created_at'];
            $amount = max((float) $line['debit_amount'], (float) $line['credit_amount']);
            $matching = array_values(array_filter($corrections[$key] ?? [], static fn (array $item): bool =>
                $item['prior_supplier_id'] === (int) $latest['supplier_id']
                && $item['new_supplier_id'] !== $item['prior_supplier_id']
                && abs($item['released_amount'] - $amount) < 0.005));
            if (count($matching) === 1) {
                $supplierId = $matching[0]['prior_supplier_id'];
                $reason = 'supplier_change_released_prior_settlement';
                $evidenceIds = [(int) $latest['audit_id'], (int) $matching[0]['audit_id']];
            }
        }
    } elseif ($paymentId > 0 && $line['source_type'] === 'supplier_payment_recorded'
        && (string) $line['created_at'] === (string) ($payments[$paymentId]['created_at'] ?? '')
        && count($paymentCreated[$paymentId] ?? []) === 1) {
        $supplierId = (int) $paymentCreated[$paymentId][0]['supplier_id'];
        $reason = 'matching_payment_creation_event';
        $evidenceIds = [(int) $paymentCreated[$paymentId][0]['audit_id']];
    }
    if ($supplierId > 0) {
        $resolved[] = [
            'journal_line_id' => $lineId, 'supplier_id' => $supplierId,
            'reason' => $reason, 'audit_ids' => $evidenceIds,
        ];
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    } else {
        $unresolved[] = $exception;
    }
}
ksort($reasons);
echo json_encode([
    'mode' => 'read_only',
    'resolved_count' => count($resolved),
    'unresolved_count' => count($unresolved),
    'resolution_reasons' => $reasons,
    'resolved' => $resolved,
    'unresolved' => $unresolved,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
