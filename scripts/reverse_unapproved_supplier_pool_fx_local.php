<?php

declare(strict_types=1);

// Local-only correction of an inferred supplier-pool FX bridge; never deploy to production.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply-local') {
    fwrite(STDERR, "Usage: php scripts/reverse_unapproved_supplier_pool_fx_local.php --apply-local\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$query = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message . ' No changes committed.');
    }
};
$db->beginTransaction();
try {
    $advance = $query('SELECT id, currency, deposit_amount, available_amount, reference_no
        FROM supplier_advances WHERE id = 75 FOR UPDATE');
    $application = $query('SELECT id FROM supplier_advance_applications WHERE supplier_advance_id = 75 FOR UPDATE');
    $journals = $query('SELECT id, source_reference, currency, booking_reference
        FROM journal_entries WHERE id IN (7992, 7993) ORDER BY id FOR UPDATE');
    $prior = $query('SELECT id FROM journal_entries
        WHERE source_reference IN ("VOID-UNAPPROVED-SUPPLIER-POOL-FX-PKR", "VOID-UNAPPROVED-SUPPLIER-POOL-FX-AED")');
    $require(count($advance) === 1 && $advance[0]['currency'] === 'PKR'
        && abs((float) $advance[0]['deposit_amount'] - 50242) < .005
        && abs((float) $advance[0]['available_amount'] - 9078.95) < .005
        && $application === [], 'PKR supplier advance changed');
    $require(count($journals) === 2 && $journals[0]['id'] == 7992
        && $journals[0]['source_reference'] === 'SUPPLIER-POOL-FX-HISTORICAL-413-PKR'
        && $journals[0]['currency'] === 'PKR' && $journals[0]['booking_reference'] === null
        && $journals[1]['id'] == 7993
        && $journals[1]['source_reference'] === 'SUPPLIER-POOL-FX-HISTORICAL-413-AED'
        && $journals[1]['currency'] === 'AED' && $journals[1]['booking_reference'] === null
        && $prior === [], 'Unapproved FX journal state changed');
    $detail = $query('SELECT id, refund_currency, refund_source_amount
        FROM booking_service_refund_details WHERE service_event_id = 119');
    $require(count($detail) === 1 && $detail[0]['refund_currency'] === 'PKR'
        && abs((float) $detail[0]['refund_source_amount'] - 50242) < .005,
        'Supplier refund source changed');

    $accounting = new \App\Repositories\AccountingRepository($app);
    $sourceReversal = $accounting->reverseJournalEntry(7992, [
        'source_type' => 'data_integrity_reversal',
        'source_reference' => 'VOID-UNAPPROVED-SUPPLIER-POOL-FX-PKR',
        'entry_date' => date('Y-m-d'), 'actor_user_id' => 10,
        'narration' => 'Reverse unapproved PKR supplier-pool conversion based on customer equivalent',
    ]);
    $destinationReversal = $accounting->reverseJournalEntry(7993, [
        'source_type' => 'data_integrity_reversal',
        'source_reference' => 'VOID-UNAPPROVED-SUPPLIER-POOL-FX-AED',
        'entry_date' => date('Y-m-d'), 'actor_user_id' => 10,
        'narration' => 'Reverse unapproved AED supplier-pool conversion based on customer equivalent',
    ]);
    $require($sourceReversal !== null && $destinationReversal !== null,
        'FX bridge reversal failed');
    $restore = $db->prepare('UPDATE supplier_advances SET available_amount = 50242.00,
        remarks = ?, updated_by_user_id = 10
        WHERE id = 75 AND available_amount = 9078.95 AND deposit_amount = 50242.00');
    $restore->execute(['PKR supplier credit restored in full; no AED customer equivalent used to value supplier credit']);
    $require($restore->rowCount() === 1, 'PKR advance could not be restored');
    $separate = $db->prepare('UPDATE supplier_advances SET reference_no = ?, remarks = ?, updated_by_user_id = 10
        WHERE id = 48 AND currency = "AED" AND deposit_amount = 535.00 AND available_amount = 0');
    $separate->execute([
        'UNVERIFIED-HISTORICAL-AED-ADV-48',
        'Historical supplier advance use retained for separate source review; not funded by BK-000038 customer equivalent',
    ]);
    $require($separate->rowCount() === 1, 'Separate historical advance changed');
    \App\Helpers\AuditLog::recordRequired($app, 'supplier.pool_fx.unapproved_bridge_reversed', [
        'user_id' => 10, 'source_journal_id' => 7992, 'destination_journal_id' => 7993,
        'source_reversal_journal_id' => $sourceReversal,
        'destination_reversal_journal_id' => $destinationReversal,
        'restored_pkr_advance_id' => 75, 'restored_pkr_amount' => 41163.05,
        'separate_historical_advance_id' => 48,
        'customer_refund_unchanged' => true, 'cash_unchanged' => true,
    ]);
    $db->commit();
    echo "Restored full PKR 50,242 supplier credit locally; unapproved noncash FX bridge reversed and audited.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
