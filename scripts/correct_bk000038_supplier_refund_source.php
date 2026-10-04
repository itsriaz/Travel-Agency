<?php

declare(strict_types=1);

// Correct the denomination of an existing supplier credit without undoing its valid application.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$usage = "Usage: php scripts/correct_bk000038_supplier_refund_source.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}
$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
$rehearse = array_key_exists('rehearse', $options);
$apply = array_key_exists('apply', $options);
if ($rehearse && $apply) {
    fwrite(STDERR, $usage);
    exit(1);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$read = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message . ' No changes committed; stop for review.');
    }
};
$near = static fn (mixed $value, float $expected): bool => abs((float) $value - $expected) < .005;

$db->beginTransaction();
try {
    $event = $read('SELECT * FROM booking_service_events WHERE id = ? FOR UPDATE', [119]);
    $cancel = $read('SELECT id, event_status, payload_json FROM booking_service_events WHERE id = ? FOR UPDATE', [1]);
    $advance = $read('SELECT id, supplier_id, branch_id, currency, deposit_amount, available_amount,
            source_supplier_payment_id, reference_no FROM supplier_advances WHERE id = ? FOR UPDATE', [48]);
    $application = $read('SELECT id, supplier_advance_id, supplier_obligation_id, applied_amount
        FROM supplier_advance_applications WHERE supplier_advance_id = ? FOR UPDATE', [48]);
    $obligation = $read('SELECT id, booking_reference, currency, gross_amount, advance_applied_amount,
            net_payable_amount FROM supplier_obligations WHERE id = ? FOR UPDATE', [514]);
    $payment = $read('SELECT id, booking_reference, currency, paid_amount, allocated_amount,
            returned_amount, converted_advance_amount FROM supplier_payments WHERE id = ? FOR UPDATE', [8]);
    $customerRefund = $read('SELECT id, event_status, customer_refund_amount, supplier_refund_amount
        FROM booking_service_events WHERE id = ? FOR UPDATE', [47]);

    $assert(count($event) === 1 && $event[0]['booking_reference'] === 'BK-000038'
        && (int) $event[0]['booking_service_id'] === 38 && $event[0]['event_type'] === 'refund'
        && $event[0]['event_status'] === 'posted' && $event[0]['currency'] === 'AED'
        && $near($event[0]['customer_refund_amount'], 0) && $near($event[0]['supplier_refund_amount'], 653)
        && $event[0]['journal_entry_id'] === null,
        'Supplier credit event #119 differs from the reviewed posting');
    $payload = json_decode((string) $event[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $cancelPayload = count($cancel) === 1
        ? json_decode((string) $cancel[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
    $assert($cancel !== [] && $cancel[0]['event_status'] === 'posted'
        && ($cancelPayload['expected_supplier_refund_currency'] ?? '') === 'PKR'
        && $near($cancelPayload['expected_supplier_refund_source_amount'] ?? -1, 50242)
        && $near($cancelPayload['expected_supplier_refund_equivalent_amount'] ?? -1, 653),
        'The saved PKR expectation or AED equivalent changed');
    $assert(($payload['refund_scope'] ?? '') === 'supplier'
        && ($payload['supplier_refund_payment_method'] ?? '') === 'supplier_credit'
        && $near($payload['retained_credit_conversion']['confirmed_amount'] ?? -1, 653)
        && $near($payload['retained_credit_conversion']['converted_payment_credit_amount'] ?? -1, 535)
        && ($payload['retained_credit_conversion']['supplier_advance_ids'] ?? []) === [48]
        && ($payload['retained_credit_conversion']['source_supplier_payment_ids'] ?? []) === [8],
        'The retained supplier-credit conversion changed');
    $assert(count($advance) === 1 && $advance[0]['currency'] === 'AED'
        && (int) $advance[0]['source_supplier_payment_id'] === 8
        && $near($advance[0]['deposit_amount'], 535) && $near($advance[0]['available_amount'], 0)
        && count($application) === 1 && (int) $application[0]['id'] === 124
        && (int) $application[0]['supplier_obligation_id'] === 514
        && $near($application[0]['applied_amount'], 535)
        && count($obligation) === 1 && $obligation[0]['booking_reference'] === 'BK-000517'
        && $obligation[0]['currency'] === 'AED'
        && $near($obligation[0]['advance_applied_amount'], 535)
        && count($payment) === 1 && $payment[0]['booking_reference'] === 'BK-000038'
        && $payment[0]['currency'] === 'AED'
        && $near($payment[0]['converted_advance_amount'], 1188)
        && count($customerRefund) === 1 && $customerRefund[0]['event_status'] === 'posted'
        && $near($customerRefund[0]['customer_refund_amount'], 600),
        'The valid AED credit application or customer refund changed');

    if (($payload['supplier_source_correction']['source_currency'] ?? '') === 'PKR'
        && $near($payload['supplier_refund_detail']['refund_source_amount'] ?? -1, 50242)
        && ($payload['supplier_refund_detail']['refund_currency'] ?? '') === 'PKR') {
        $db->rollBack();
        echo "ALREADY APPLIED: event #119 records PKR 50,242 source and AED 653 equivalent; valid credit unchanged.\n";
        exit(0);
    }
    foreach (['supplier_refund_detail', 'refund_detail'] as $detailKey) {
        $detail = $payload[$detailKey] ?? [];
        $assert(is_array($detail) && ($detail['refund_currency'] ?? '') === 'AED'
            && $near($detail['refund_source_amount'] ?? -1, 653)
            && $near($detail['refund_exchange_rate_to_booking'] ?? -1, 1)
            && $near($detail['refund_booking_equivalent'] ?? -1, 653),
            'The existing AED source detail changed');
    }

    $snapshot = [$event, $cancel, $advance, $application, $obligation, $payment, $customerRefund];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $plan = [
        'booking_reference' => 'BK-000038', 'supplier_credit_event_id' => 119,
        'fingerprint' => $fingerprint,
        'source_before' => 'AED 653', 'source_after' => 'PKR 50,242',
        'booking_equivalent_unchanged' => 'AED 653',
        'bk000517_credit_application_unchanged' => 'AED 535',
        'cash_movements' => 0, 'journal_movements' => 0,
    ];
    if (! $rehearse && ! $apply) {
        $db->rollBack();
        echo json_encode(['mode' => 'preview'] + $plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $actor = (int) ($options['actor'] ?? 0);
    $givenFingerprint = strtolower((string) ($options['fingerprint'] ?? ''));
    $assert($actor > 0 && $read('SELECT id FROM users WHERE id = ?', [$actor]) !== []
        && hash_equals($fingerprint, $givenFingerprint),
        'Use a verified actor and the exact fingerprint from a fresh preview');

    $rate = round(50242 / 653, 8);
    foreach (['supplier_refund_detail', 'refund_detail'] as $detailKey) {
        $payload[$detailKey]['refund_currency'] = 'PKR';
        $payload[$detailKey]['refund_source_amount'] = 50242;
        $payload[$detailKey]['refund_exchange_rate_to_booking'] = $rate;
        $payload[$detailKey]['refund_exchange_rate_direction'] = 'booking_to_refund';
        $payload[$detailKey]['refund_rate_date'] = (string) $event[0]['event_date'];
    }
    $payload['supplier_source_correction'] = [
        'source_currency' => 'PKR', 'source_amount' => 50242,
        'booking_currency' => 'AED', 'booking_equivalent' => 653,
        'implied_booking_to_source_rate' => $rate,
        'basis' => 'Correct source denomination to the posted cancellation expectation; preserve the valid AED credit and BK-000517 application.',
        'prior_source_currency' => 'AED', 'prior_source_amount' => 653,
        'corrected_by_user_id' => $actor, 'corrected_at' => date('c'),
    ];
    $updated = $db->prepare('UPDATE booking_service_events SET payload_json = :next_payload
        WHERE id = 119 AND event_status = "posted" AND payload_json = :prior_payload');
    $updated->execute([
        'next_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'prior_payload' => $event[0]['payload_json'],
    ]);
    $assert($updated->rowCount() === 1, 'Concurrent change to event #119');
    \App\Helpers\AuditLog::recordRequired($app, 'service.bk000038.supplier_refund_source.corrected', [
        'user_id' => $actor, 'booking_id' => 38, 'service_id' => 38,
        'booking_reference' => 'BK-000038', 'refund_event_id' => 119,
        'before' => ['source_currency' => 'AED', 'source_amount' => 653],
        'after' => ['source_currency' => 'PKR', 'source_amount' => 50242,
            'booking_currency' => 'AED', 'booking_equivalent' => 653, 'implied_rate' => $rate],
        'preserved_supplier_advance_id' => 48,
        'preserved_bk000517_application_id' => 124,
        'preserved_customer_refund_event_id' => 47,
        'cash_and_journals_unchanged' => true,
    ]);
    $assert($advance === $read('SELECT id, supplier_id, branch_id, currency, deposit_amount, available_amount,
            source_supplier_payment_id, reference_no FROM supplier_advances WHERE id = ?', [48])
        && $application === $read('SELECT id, supplier_advance_id, supplier_obligation_id, applied_amount
            FROM supplier_advance_applications WHERE supplier_advance_id = ?', [48])
        && $obligation === $read('SELECT id, booking_reference, currency, gross_amount, advance_applied_amount,
            net_payable_amount FROM supplier_obligations WHERE id = ?', [514])
        && $payment === $read('SELECT id, booking_reference, currency, paid_amount, allocated_amount,
            returned_amount, converted_advance_amount FROM supplier_payments WHERE id = ?', [8])
        && $customerRefund === $read('SELECT id, event_status, customer_refund_amount, supplier_refund_amount
            FROM booking_service_events WHERE id = ?', [47]),
        'Financial rows changed during source correction');

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back'] + $plan,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'No changes committed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
