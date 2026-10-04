<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);
$customerRepository = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$eventRepository = new \App\Repositories\BookingServiceEventRepository($app);

$actorUserId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
     ORDER BY (r.code = "super_admin") DESC, u.id
     LIMIT 1'
)->fetchColumn();
$treasuryAccount = $db->query(
    'SELECT ta.id, ta.branch_id
     FROM treasury_accounts ta
     INNER JOIN branches b ON b.id = ta.branch_id AND b.is_active = 1
     WHERE ta.is_active = 1 AND ta.currency = "AED" AND ta.account_type IN ("cash", "bank")
     ORDER BY ta.is_default DESC, ta.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$branchId = (int) ($treasuryAccount['branch_id'] ?? 0);
$aedTreasuryAccountId = (int) ($treasuryAccount['id'] ?? 0);
$customerIdStatement = $db->prepare(
    'SELECT id FROM travelers WHERE branch_id = :branch_id ORDER BY id LIMIT 1'
);
$customerIdStatement->execute(['branch_id' => $branchId]);
$customerId = (int) $customerIdStatement->fetchColumn();
if ($actorUserId <= 0 || $branchId <= 0 || $aedTreasuryAccountId <= 0 || $customerId <= 0) {
    throw new RuntimeException('An active branch, AED cash/bank account, customer and financial administrator are required.');
}

$accessibleBranchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$today = date('Y-m-d');
$failures = [];
$check = static function (string $label, bool $passed, array $details = []) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label;
    if ($details !== []) {
        echo ' - ' . json_encode($details, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Cancellation partial-payment settlement regression' . PHP_EOL;
echo 'Business example: invoice AED 620, supplier refund AED 300, customer penalty AED 100, final charge AED 420.' . PHP_EOL;

$db->beginTransaction();
try {
    $token = date('His') . random_int(100, 999);
    $treasuryStatement = $db->prepare(
        'INSERT INTO treasury_accounts (
            branch_id, account_type, account_name, account_code, currency,
            opening_balance, opening_balance_date, is_default, is_active, created_by_user_id
         ) VALUES (
            :branch_id, "cash", :account_name, :account_code, "PKR",
            0, :opening_date, 0, 1, :user_id
         )'
    );
    $treasuryStatement->execute([
        'branch_id' => $branchId,
        'account_name' => 'QA Partial Cancellation PKR Cash ' . $token,
        'account_code' => 'QA-PC-PKR-' . $token,
        'opening_date' => $today,
        'user_id' => $actorUserId,
    ]);
    $pkrTreasuryAccountId = (int) $db->lastInsertId();

    $runCase = static function (
        string $case,
        float $receivedAmount,
        bool $mixedSupplierCurrency = false,
        string $receiptCurrency = 'AED',
        bool $editSupplierRefund = false
    ) use (
        $bookingWorkspace,
        $serviceWorkspace,
        $receiptWorkspace,
        $customerRepository,
        $supplierRepository,
        $eventRepository,
        $actorUserId,
        $accessibleBranchIds,
        $branchId,
        $customerId,
        $aedTreasuryAccountId,
        $pkrTreasuryAccountId,
        $today,
        $token,
        $db,
        $check
    ): array {
        $bookingResult = $bookingWorkspace->saveBooking([
            'branch_id' => $branchId,
            'booking_status' => 'draft',
            'booking_date' => $today,
            'selected_customer_id' => $customerId,
            'party_label' => 'Lead Traveler / Booking Party',
            'lead_traveler_name' => 'Partial Cancellation ' . $case . ' ' . $token,
            'contact_mobile' => '03001230000',
            'remarks' => 'Rollback-only partial cancellation ' . $case,
        ], $actorUserId, $accessibleBranchIds);
        $booking = $bookingResult['booking'] ?? [];
        $bookingId = (int) ($booking['id'] ?? 0);
        $bookingReference = (string) ($booking['booking_reference'] ?? '');

        $serviceResult = $serviceWorkspace->saveService([
            'booking_id' => $bookingId,
            'service_type' => 'air ticket',
            'service_status' => 'Open',
            'currency' => 'AED',
            'cost_currency' => $mixedSupplierCurrency ? 'PKR' : 'AED',
            'pricing_exchange_rate' => $mixedSupplierCurrency ? '0.01250000' : '1.00000000',
            'pricing_rate_effective_date' => $today,
            'service_charge_currency' => 'AED',
            'service_charge_exchange_rate' => '1.00000000',
            'service_charge_rate_effective_date' => $today,
            'supplier_name' => 'Partial Cancellation Supplier ' . $token,
            'service_passenger_name' => 'Partial Cancellation Passenger',
            'sale_price' => $mixedSupplierCurrency ? '46320' : '579',
            'purchase_cost' => $mixedSupplierCurrency ? '46320' : '579',
            'taxes' => '0',
            'other_fare' => '0',
            'soto_fare' => '0',
            'spyi_amount' => '0',
            'aq_yr_pk_amount' => '0',
            'yq_amount' => '0',
            'oth_amount' => '0',
            'vat_input' => '0',
            'vat' => '0',
            'commission' => '0',
            'service_charge' => '41',
            'discount_amount' => '0',
            'remarks' => 'Rollback-only partial cancellation service',
            'ticket_pnr' => 'PC' . strtoupper(substr($case, 0, 3)),
            'ticket_number' => 'PC-' . strtoupper($case) . '-' . $token,
            'ticket_airline' => 'PIA',
            'ticket_sector_from' => 'ISB',
            'ticket_sector_to' => 'DXB',
            'ticket_departure_date' => $today,
            'ticket_class' => 'Economy',
            'ticket_fare' => '579',
            'ticket_tax' => '0',
            'ticket_vat' => '0',
            'ticket_commission' => '0',
            'ticket_remarks' => 'Rollback-only partial cancellation service',
        ], $actorUserId, $accessibleBranchIds);
        $service = $serviceResult['service'] ?? [];
        $serviceId = (int) ($service['id'] ?? 0);
        $lineReference = (string) ($service['line_reference'] ?? '');
        $initialReceivable = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];

        if ($receivedAmount > 0) {
            $receiptPayload = [
                'booking_id' => $bookingId,
                'receipt_date' => $today,
                'receipt_currency' => $receiptCurrency,
                'received_amount' => (string) $receivedAmount,
                'payment_method' => 'cash',
                'charges_amount' => '0',
                'receipt_status' => 'received',
                'treasury_account_id' => $receiptCurrency === 'PKR' ? $pkrTreasuryAccountId : $aedTreasuryAccountId,
                'receipt_remarks' => 'Rollback-only partial cancellation receipt',
            ];
            if ($receiptCurrency === 'PKR') {
                $receiptPayload = array_merge($receiptPayload, [
                    'settlement_mode' => 'exchange',
                    'settlement_target_receivable_id' => (int) ($initialReceivable['id'] ?? 0),
                    'settlement_target_currency' => 'AED',
                    'settlement_target_receivable_amount' => '100',
                    'settlement_target_payment_amount' => (string) $receivedAmount,
                    'settlement_rate_from_currency' => 'AED',
                    'settlement_rate_to_currency' => 'PKR',
                    'settlement_exchange_rate' => '80.00000000',
                ]);
            }
            $receiptWorkspace->saveReceipt($receiptPayload, $actorUserId, $accessibleBranchIds);
        }

        $before = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];
        $serviceWorkspace->cancelService([
            'booking_id' => $bookingId,
            'service_id' => $serviceId,
            'cancel_reason' => 'Rollback-only cancellation',
            'cancel_event_date' => $today,
            'cancel_notes' => 'Partial-payment cancellation reproduction',
        ], $actorUserId, $accessibleBranchIds);
        $settlementPayload = [
            'booking_id' => $bookingId,
            'service_id' => $serviceId,
            'settlement_reason' => 'Rollback-only cancellation settlement',
            'settlement_event_date' => $today,
            'settlement_notes' => 'Final charge should remain AED 420 regardless of prior payment amount',
        ];
        if ($mixedSupplierCurrency) {
            $settlementPayload = array_merge($settlementPayload, [
                'settlement_currency' => $receiptCurrency,
                'customer_penalty_amount' => $receiptCurrency === 'PKR' ? '8000' : '100',
                'customer_penalty_currency' => $receiptCurrency,
                'customer_penalty_exchange_rate' => $receiptCurrency === 'PKR' ? '0.01250000' : '1.00000000',
                'expected_supplier_refund_amount' => '24000',
                'expected_supplier_refund_currency' => 'PKR',
                'expected_supplier_refund_exchange_rate' => '0.01250000',
            ]);
        } else {
            $settlementPayload = array_merge($settlementPayload, [
                'customer_penalty_amount' => '100',
                'expected_supplier_refund_amount' => '300',
            ]);
        }
        if ($mixedSupplierCurrency && $receiptCurrency === 'AED') {
            $supplierBefore = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference) ?? [];
            $journalCountBefore = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
            $statement = $db->prepare('UPDATE supplier_obligations SET currency = "AED" WHERE id = ?');
            $statement->execute([(int) ($supplierBefore['id'] ?? 0)]);
            $repositoryRejected = false;
            try {
                $supplierRepository->syncObligation([
                    'supplier_id' => (int) ($supplierBefore['supplier_id'] ?? 0),
                    'branch_id' => $branchId, 'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference, 'obligation_group' => 'service_cost',
                    'currency' => 'PKR', 'gross_amount' => 22320,
                ]);
            } catch (RuntimeException) {
                $repositoryRejected = true;
            }
            $settlementRejected = false;
            try {
                $serviceWorkspace->settleCancellationFinancials($settlementPayload, $actorUserId, $accessibleBranchIds);
            } catch (RuntimeException) {
                $settlementRejected = true;
            }
            $check('Mismatched supplier payable currency blocks both direct sync and cancellation without journals',
                $repositoryRejected && $settlementRejected
                && $journalCountBefore === (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
            $statement = $db->prepare('UPDATE supplier_obligations SET currency = "PKR" WHERE id = ?');
            $statement->execute([(int) ($supplierBefore['id'] ?? 0)]);
            $customerBefore = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];
            $statement = $db->prepare('UPDATE customer_receivable_items SET currency = "PKR" WHERE id = ?');
            $statement->execute([(int) ($customerBefore['id'] ?? 0)]);
            $repositoryRejected = false;
            try {
                $customerRepository->syncReceivableItem([
                    'branch_id' => $branchId, 'booking_reference' => $bookingReference,
                    'service_line_reference' => $lineReference, 'due_group' => 'service_sale',
                    'currency' => 'AED', 'due_amount' => 620,
                ]);
            } catch (RuntimeException) {
                $repositoryRejected = true;
            }
            $settlementRejected = false;
            try {
                $serviceWorkspace->settleCancellationFinancials($settlementPayload, $actorUserId, $accessibleBranchIds);
            } catch (RuntimeException) {
                $settlementRejected = true;
            }
            $check('Mismatched customer receivable currency blocks sync and cancellation without journals',
                $repositoryRejected && $settlementRejected
                && $journalCountBefore === (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
            $statement = $db->prepare('UPDATE customer_receivable_items SET currency = "AED" WHERE id = ?');
            $statement->execute([(int) ($customerBefore['id'] ?? 0)]);
        }
        $serviceWorkspace->settleCancellationFinancials($settlementPayload, $actorUserId, $accessibleBranchIds);

        if ($editSupplierRefund) {
            $legacyCancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel') ?? [];
            $legacyPayload = json_decode((string) ($legacyCancelEvent['payload_json'] ?? ''), true);
            $legacyPayload['expected_supplier_refund_amount'] = 24000;
            unset($legacyPayload['expected_supplier_refund_booking_amount']);
            $statement = $db->prepare('UPDATE booking_service_events SET payload_json = ? WHERE id = ?');
            $statement->execute([json_encode($legacyPayload, JSON_THROW_ON_ERROR), (int) $legacyCancelEvent['id']]);
            $correctionInput = [
                'booking_id' => $bookingId,
                'service_id' => $serviceId,
                'correction_reason' => 'Rollback-only PKR supplier refund correction',
                'correction_event_date' => $today,
                'customer_penalty_amount' => '100',
                'customer_penalty_currency' => 'AED',
                'agency_fee_refund_amount' => '0',
                'expected_supplier_refund_amount' => '24000',
                'expected_supplier_refund_currency' => 'PKR',
                'expected_supplier_refund_exchange_rate' => '0.01250000',
                'expected_supplier_refund_rate_date' => $today,
                'supplier_penalty_amount' => '22320',
                'supplier_penalty_currency' => 'PKR',
                'customer_refund_amount' => '0',
                'supplier_refund_amount' => '0',
            ];
            $journalCountBeforeNoop = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
            $serviceWorkspace->correctPenaltyRefundWorkflow($correctionInput, $actorUserId, $accessibleBranchIds);
            $check('Unchanged legacy PKR source amount does not rebuild cancellation journals',
                $journalCountBeforeNoop === (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
            $correctionInput['expected_supplier_refund_amount'] = '23000';
            $correctionInput['supplier_penalty_amount'] = '23320';
            $legacyChangeRejected = false;
            try {
                $serviceWorkspace->correctPenaltyRefundWorkflow($correctionInput, $actorUserId, $accessibleBranchIds);
            } catch (RuntimeException $exception) {
                $legacyChangeRejected = str_contains($exception->getMessage(), 'no verified invoice-currency equivalent');
            }
            $check('Legacy PKR amount edit without verified AED equivalent cannot repost',
                $legacyChangeRejected
                && $journalCountBeforeNoop === (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
            $statement->execute([(string) $legacyCancelEvent['payload_json'], (int) $legacyCancelEvent['id']]);
            $serviceWorkspace->correctPenaltyRefundWorkflow($correctionInput, $actorUserId, $accessibleBranchIds);
        }

        $after = $customerRepository->findReceivableByServiceLine($bookingReference, $lineReference) ?? [];
        $supplierAfter = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference) ?? [];
        $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel') ?? [];
        $payload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
        $controlStatement = $db->prepare('SELECT je.currency, coa.code,
            ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS balance
            FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE je.booking_reference = ? AND coa.code = "AP_CONTROL"
            GROUP BY je.currency, coa.code');
        $controlStatement->execute([$bookingReference]);
        $apBalances = [];
        foreach ($controlStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $apBalances[(string) $row['currency']] = round((float) $row['balance'], 2);
        }

        return [
            'booking_reference' => $bookingReference,
            'before_due' => round((float) ($before['due_amount'] ?? -1), 2),
            'before_allocated' => round((float) ($before['allocated_amount'] ?? -1), 2),
            'before_outstanding' => round((float) ($before['outstanding_amount'] ?? -1), 2),
            'after_due' => round((float) ($after['due_amount'] ?? -1), 2),
            'after_allocated' => round((float) ($after['allocated_amount'] ?? -1), 2),
            'after_outstanding' => round((float) ($after['outstanding_amount'] ?? -1), 2),
            'customer_final_charge' => round((float) (($payload['customer_final_charge_amount'] ?? -1)), 2),
            'customer_received' => round((float) (($payload['customer_received_amount'] ?? -1)), 2),
            'refund_available' => round((float) (($payload['available_customer_refund_credit'] ?? -1)), 2),
            'refund_target' => round((float) (($payload['target_customer_refund_credit'] ?? -1)), 2),
            'customer_penalty_currency' => (string) ($payload['customer_penalty_currency'] ?? ''),
            'customer_penalty_source' => round((float) ($payload['customer_penalty_source_amount'] ?? -1), 2),
            'supplier_refund_currency' => (string) ($payload['expected_supplier_refund_currency'] ?? ''),
            'supplier_refund_source' => round((float) ($payload['expected_supplier_refund_source_amount'] ?? -1), 2),
            'supplier_currency' => (string) ($supplierAfter['currency'] ?? ''),
            'supplier_penalty_due' => round((float) ($supplierAfter['gross_amount'] ?? -1), 2),
            'supplier_ap_balances' => $apBalances,
        ];
    };

    $unpaid = $runCase('unpaid', 0);
    $partial = $runCase('partial', 100);
    $full = $runCase('full', 620);
    $mixedAedPartial = $runCase('mixed-aed-partial', 100, true);
    $mixedPkrPartial = $runCase('mixed-pkr-partial', 8000, true, 'PKR');
    $mixedEdited = $runCase('mixed-edited', 100, true, 'AED', true);

    $check('All three fixtures begin with the same AED 620 invoice',
        $unpaid['before_due'] === 620.0 && $partial['before_due'] === 620.0 && $full['before_due'] === 620.0,
        ['unpaid' => $unpaid, 'partial' => $partial, 'full' => $full]);
    $check('Unpaid cancellation retains AED 420 due and exposes no refund',
        $unpaid['after_due'] === 420.0
        && $unpaid['after_allocated'] === 0.0
        && $unpaid['after_outstanding'] === 420.0
        && $unpaid['refund_available'] === 0.0,
        $unpaid);
    $check('Partially paid cancellation retains AED 420 charge, AED 100 paid and AED 320 outstanding',
        $partial['after_due'] === 420.0
        && $partial['after_allocated'] === 100.0
        && $partial['after_outstanding'] === 320.0
        && $partial['refund_available'] === 0.0,
        $partial);
    $check('Fully paid cancellation retains AED 420 and releases only AED 200 refund credit',
        $full['after_due'] === 420.0
        && $full['after_allocated'] === 420.0
        && $full['after_outstanding'] === 0.0
        && $full['refund_available'] === 200.0
        && $full['refund_target'] === 200.0,
        $full);
    $check('PKR supplier refund with AED customer payment preserves both currency truths',
        $mixedAedPartial['after_due'] === 420.0
        && $mixedAedPartial['after_allocated'] === 100.0
        && $mixedAedPartial['after_outstanding'] === 320.0
        && $mixedAedPartial['refund_available'] === 0.0
        && $mixedAedPartial['customer_penalty_currency'] === 'AED'
        && $mixedAedPartial['customer_penalty_source'] === 100.0
        && $mixedAedPartial['supplier_refund_currency'] === 'PKR'
        && $mixedAedPartial['supplier_refund_source'] === 24000.0
        && $mixedAedPartial['supplier_currency'] === 'PKR'
        && $mixedAedPartial['supplier_penalty_due'] === 22320.0,
        $mixedAedPartial);
    $check('PKR customer payment and penalty are internally converted without inventing a refund',
        $mixedPkrPartial['after_due'] === 420.0
        && $mixedPkrPartial['after_allocated'] === 100.0
        && $mixedPkrPartial['after_outstanding'] === 320.0
        && $mixedPkrPartial['refund_available'] === 0.0
        && $mixedPkrPartial['customer_penalty_currency'] === 'PKR'
        && $mixedPkrPartial['customer_penalty_source'] === 8000.0
        && $mixedPkrPartial['supplier_refund_currency'] === 'PKR'
        && $mixedPkrPartial['supplier_refund_source'] === 24000.0
        && $mixedPkrPartial['supplier_currency'] === 'PKR'
        && $mixedPkrPartial['supplier_penalty_due'] === 22320.0,
        $mixedPkrPartial);
    $check('Editing PKR supplier refund preserves PKR payable denomination',
        $mixedEdited['supplier_currency'] === 'PKR'
        && $mixedEdited['supplier_refund_currency'] === 'PKR'
        && $mixedEdited['supplier_refund_source'] === 23000.0
        && $mixedEdited['supplier_penalty_due'] === 23320.0
        && ($mixedEdited['supplier_ap_balances']['PKR'] ?? null) === -23320.0
        && abs($mixedEdited['supplier_ap_balances']['AED'] ?? 0) < .005,
        $mixedEdited);
} catch (Throwable $throwable) {
    $failures[] = 'Probe completed without exception';
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    fwrite(STDERR, $throwable->getTraceAsString() . PHP_EOL);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILURES:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Cancellation partial-payment settlement regression passed. Transaction rolled back.' . PHP_EOL;
