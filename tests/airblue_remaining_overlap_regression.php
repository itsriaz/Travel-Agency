<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
require_once BASE_PATH . '/tests/support/SupplierPositionReconciliationAudit.php';

try {
    $app = \App\Core\App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    $baseline = json_decode((string) file_get_contents(
        BASE_PATH . '/docs/verification/airblue_remaining_overlap_before_20261026.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    };
    $scalar = static function (string $sql) use ($db): float {
        return (float) $db->query($sql)->fetchColumn();
    };
    foreach ($baseline['duplicate_cover'] as $item) {
        $obligation = $db->query('SELECT gross_amount, advance_applied_amount, net_payable_amount, status
            FROM supplier_obligations WHERE id = ' . (int) $item['obligation_id'])->fetch(PDO::FETCH_ASSOC);
        $assert(is_array($obligation)
            && abs((float) $obligation['gross_amount'] - (float) $item['gross']) < 0.005
            && abs((float) $obligation['advance_applied_amount']) < 0.005
            && abs((float) $obligation['net_payable_amount']) < 0.005
            && $obligation['status'] === 'paid',
            $item['booking'] . ' should remain fully paid by its direct payment.');
        $paid = $scalar('SELECT COALESCE(SUM(spa.allocated_amount),0)
            FROM supplier_payment_allocations spa JOIN supplier_payments sp
            ON sp.id = spa.supplier_payment_id AND sp.status <> "void"
            WHERE spa.supplier_obligation_id = ' . (int) $item['obligation_id']);
        $assert(abs($paid - (float) $item['gross']) < 0.005,
            $item['booking'] . ' direct payment changed.');
        $assert($scalar('SELECT COUNT(*) FROM supplier_advance_applications WHERE id = '
            . (int) $item['duplicate_advance_application_id']) === 0.0,
            $item['booking'] . ' still has the duplicate advance application.');
    }
    echo "PASS: Both bookings remain paid once; duplicate applications are released.\n";

    foreach ([36 => [400000, 203232, 196768], 52 => [24885, 0, 24885]] as $id => [$deposit, $used, $available]) {
        $row = $db->query('SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = ' . $id)
            ->fetch(PDO::FETCH_ASSOC);
        $assert(is_array($row) && abs((float) $row['deposit_amount'] - $deposit) < 0.005
            && abs((float) $row['available_amount'] - $available) < 0.005,
            'Advance #' . $id . ' deposit or available credit differs.');
        $applications = $scalar('SELECT COALESCE(SUM(applied_amount),0) FROM supplier_advance_applications
            WHERE supplier_advance_id = ' . $id);
        $assert(abs($applications - $used) < 0.005 && $deposit === $used + $available,
            'Advance #' . $id . ' does not conserve its deposit.');
    }
    echo "PASS: PKR 136,093 is available again in the original advances.\n";

    $audit = SupplierPositionReconciliationAudit::inspect($db, 349, $app);
    $group = null;
    foreach ($audit['groups'] as $candidate) {
        if ((int) $candidate['supplier_id'] === 349 && $candidate['currency'] === 'PKR') {
            $group = $candidate;
            break;
        }
    }
    $assert(is_array($group)
        && abs((float) $group['expected_net_position'] - 117863) < 0.005
        && abs((float) $group['actual_net_position'] - 117863) < 0.005
        && abs((float) $group['difference']) < 0.005,
        'AirBlue account and operational credit still disagree.');
    echo "PASS: AirBlue ledger and operational credit independently agree at PKR 117,863 advance.\n";

    $assert($scalar('SELECT COUNT(*) FROM journal_entries
        WHERE source_reference IN ("SADV-OVERLAP-REPAIR-649", "SADV-OVERLAP-REPAIR-888")') === 2.0,
        'Exactly two overlap adjustment journals are required.');
    $assert($scalar('SELECT COUNT(*) FROM (
        SELECT je.id FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.source_reference IN ("SADV-OVERLAP-REPAIR-649", "SADV-OVERLAP-REPAIR-888")
        GROUP BY je.id HAVING ABS(SUM(jel.debit_amount - jel.credit_amount)) > 0.005
        ) AS unbalanced') === 0.0,
        'An overlap adjustment journal is unbalanced.');
    $assert($scalar('SELECT COUNT(*) FROM audit_logs
        WHERE event_name = "supplier.advance.paid_overlap_repaired"
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.supplier_id")) = "349"
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.released_amount")) = "136093"') === 1.0,
        'Required overlap repair audit is missing or duplicated.');
    echo "PASS: Two balanced journals and one required repair audit record.\n";

    require BASE_PATH . '/tests/airblue_spay267_atomic_correction_regression.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
