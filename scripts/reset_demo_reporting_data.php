<?php

declare(strict_types=1);

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\AccountingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Services\BookingWorkspaceService;
use App\Services\CustomerReceiptWorkspaceService;
use App\Services\ServiceWorkspaceService;
use App\Services\SupplierSettlementWorkspaceService;
use App\Services\TravelerWorkspaceService;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

if (! in_array('--confirm-demo-reset', $argv, true)) {
    fwrite(STDOUT, "Demo reset not executed.\n");
    fwrite(STDOUT, "This command is destructive and dev-only.\n");
    fwrite(STDOUT, "Run: php scripts/reset_demo_reporting_data.php --confirm-demo-reset\n");
    exit(0);
}

$app = App::bootstrap(BASE_PATH);
$environment = (string) config('app.env', 'production');

if (in_array(strtolower($environment), ['production', 'prod'], true)) {
    fwrite(STDERR, "Refusing to run demo reset in production environment.\n");
    exit(1);
}

$dataset = require BASE_PATH . '/scripts/demo_reporting_dataset.php';

/** @var PDO $db */
$db = $app->get('db');
$originalEmulatePrepares = $db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

try {
    $actorUserId = resolveActorUserId($db);
    $branchMap = loadBranchMap($db);
    $accessibleBranchIds = array_values(array_map(static fn (array $branch): int => (int) $branch['id'], $branchMap));

    $travelerService = new TravelerWorkspaceService($app);
    $bookingService = new BookingWorkspaceService($app);
    $serviceWorkspace = new ServiceWorkspaceService($app);
    $receiptService = new CustomerReceiptWorkspaceService($app);
    $supplierSettlementService = new SupplierSettlementWorkspaceService($app);
    $bookingRepository = new BookingRepository($app);
    $bookingServiceRepository = new BookingServiceRepository($app);
    $customerPaymentRepository = new CustomerPaymentRepository($app);
    $supplierRepository = new SupplierRepository($app);

    $runtime = [
        'reporting_period' => $dataset['reporting_period'],
        'exchange_rates' => $dataset['exchange_rates'],
        'customers' => [],
        'suppliers' => [],
        'invoices' => [],
        'services' => [],
        'receipts' => [],
        'receipt_allocations' => [],
        'supplier_payables' => [],
        'supplier_payments' => [],
        'supplier_advances' => [],
        'supplier_advance_applications' => [],
        'customer_previous_balances' => [],
    ];

    $db->beginTransaction();

    cleanupTransactionalData($db);
    resetNumberingSequences($db);
    seedExchangeRates($db, $dataset['exchange_rates']);

    $runtime['customers'] = seedCustomers(
        $dataset['customers'],
        $travelerService,
        $actorUserId,
        $accessibleBranchIds,
        $branchMap
    );

    $runtime['suppliers'] = ensureSuppliers(
        $dataset['suppliers'],
        $supplierRepository,
        $db,
        $actorUserId,
        $accessibleBranchIds,
        $branchMap
    );

    foreach ($dataset['scenarios'] as $scenario) {
        $previousBalance = calculateCustomerPreviousBalance(
            $runtime['invoices'],
            (string) $scenario['customer'],
            (string) $scenario['currency'],
            (string) $scenario['booking_date']
        );

        if ($previousBalance > 0) {
            $runtime['customer_previous_balances'][(string) $scenario['customer']] = round($previousBalance, 2);
        } elseif (! array_key_exists((string) $scenario['customer'], $runtime['customer_previous_balances'])) {
            $runtime['customer_previous_balances'][(string) $scenario['customer']] = 0.0;
        }

        $result = seedScenario(
            $app,
            $scenario,
            $runtime['customers'],
            $runtime['suppliers'],
            $branchMap,
            $bookingService,
            $serviceWorkspace,
            $receiptService,
            $supplierSettlementService,
            $bookingRepository,
            $bookingServiceRepository,
            $customerPaymentRepository,
            $supplierRepository,
            $db,
            $actorUserId,
            $accessibleBranchIds
        );

        $runtime['invoices'][] = $result['invoice'];
        $runtime['services'] = array_merge($runtime['services'], $result['services']);
        $runtime['receipts'] = array_merge($runtime['receipts'], $result['receipts']);
        $runtime['receipt_allocations'] = array_merge($runtime['receipt_allocations'], $result['receipt_allocations']);
        $runtime['supplier_payables'] = array_merge($runtime['supplier_payables'], $result['supplier_payables']);
        $runtime['supplier_payments'] = array_merge($runtime['supplier_payments'], $result['supplier_payments']);
        $runtime['supplier_advances'] = array_merge($runtime['supplier_advances'], $result['supplier_advances']);
        $runtime['supplier_advance_applications'] = array_merge($runtime['supplier_advance_applications'], $result['supplier_advance_applications']);
    }

    $worksheets = buildWorksheets($runtime);

    $db->commit();

    $csvDirectory = exportWorksheets($worksheets);
    $guidePath = writeTestGuide($runtime, $csvDirectory);
    printSummary($runtime, $csvDirectory, $guidePath);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Demo reset failed: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'At: ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
    if ((bool) config('app.debug', false)) {
        fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    }
    exit(1);
} finally {
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, $originalEmulatePrepares);
}

function resolveActorUserId(PDO $db): int
{
    $statement = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    $userId = $statement !== false ? (int) $statement->fetchColumn() : 0;
    if ($userId <= 0) {
        throw new RuntimeException('No user record exists. Seed foundation users first.');
    }

    return $userId;
}

function loadBranchMap(PDO $db): array
{
    $statement = $db->query('SELECT id, code, name, base_currency FROM branches ORDER BY id ASC');
    $rows = $statement !== false ? ($statement->fetchAll() ?: []) : [];
    $map = [];

    foreach ($rows as $row) {
        $map[(string) $row['code']] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'currency_code' => (string) $row['base_currency'],
        ];
    }

    foreach (['swat', 'dubai'] as $requiredCode) {
        if (! isset($map[$requiredCode])) {
            throw new RuntimeException('Required branch seed missing: ' . $requiredCode);
        }
    }

    return $map;
}

function cleanupTransactionalData(PDO $db): void
{
    $statements = [
        "DELETE FROM booking_reminders",
        "DELETE FROM booking_documents",
        "DELETE FROM customer_receipt_allocations",
        "DELETE FROM customer_receipts",
        "DELETE FROM customer_receivable_items",
        "DELETE FROM supplier_payment_allocations",
        "DELETE FROM supplier_payments",
        "DELETE FROM supplier_advance_applications",
        "DELETE FROM supplier_advances",
        "DELETE FROM supplier_obligations",
        "DELETE FROM journal_entry_lines",
        "DELETE FROM journal_entries",
        "DELETE FROM expense_attachments",
        "DELETE FROM business_expenses",
        "DELETE FROM booking_travelers",
        "DELETE FROM bookings",
        "DELETE FROM travelers",
        "DELETE FROM audit_logs",
        "DELETE FROM suppliers WHERE code LIKE 'DEMO-SUP-%'",
        "DELETE FROM exchange_rates",
    ];

    foreach ($statements as $sql) {
        $db->exec($sql);
    }
}

function resetNumberingSequences(PDO $db): void
{
    $sequenceKeys = [
        'booking.reference.sequence',
        'customer.receipt.sequence',
        'supplier.payment.sequence',
    ];

    $delete = $db->prepare('DELETE FROM app_settings WHERE setting_key = :setting_key');
    foreach ($sequenceKeys as $key) {
        $delete->execute(['setting_key' => $key]);
    }
}

function seedExchangeRates(PDO $db, array $exchangeRates): void
{
    $statement = $db->prepare(
        'INSERT INTO exchange_rates (from_currency, to_currency, rate_value, effective_date, rate_source, notes, is_active)
         VALUES (:from_currency, :to_currency, :rate_value, :effective_date, :rate_source, :notes, 1)'
    );

    foreach ($exchangeRates as $rate) {
        $statement->execute([
            'from_currency' => $rate['from_currency'],
            'to_currency' => $rate['to_currency'],
            'rate_value' => $rate['rate_value'],
            'effective_date' => $rate['effective_date'],
            'rate_source' => 'demo_seed',
            'notes' => 'Demo reporting reset seed',
        ]);
    }
}

function seedCustomers(
    array $customers,
    TravelerWorkspaceService $travelerService,
    int $actorUserId,
    array $accessibleBranchIds,
    array $branchMap
): array {
    $created = [];

    foreach ($customers as $customer) {
        [$firstName, $lastName] = splitName((string) $customer['name']);
        $branchId = (int) $branchMap[(string) $customer['branch_code']]['id'];

        $result = $travelerService->saveTraveler([
            'booking_id' => 0,
            'traveler_branch_id' => $branchId,
            'traveler_role' => 'lead',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => (string) $customer['name'],
            'gender' => 'unspecified',
            'mobile' => (string) $customer['mobile'],
            'passport_number' => (string) $customer['passport'],
            'current_residence' => $customer['current_residence'],
            'permanent_residence' => $customer['permanent_residence'],
            'village' => $customer['village'],
            'district' => $customer['district'],
            'family_id' => $customer['family_id'],
            'color_tag' => $customer['color_tag'] ?? 'none',
            'notes' => 'Demo reporting customer',
        ], $actorUserId, $accessibleBranchIds);

        $traveler = $result['traveler'];
        if (! is_array($traveler) || (int) ($traveler['id'] ?? 0) <= 0) {
            throw new RuntimeException('Traveler save did not return a valid traveler row for ' . $customer['name']);
        }

        $created[(string) $customer['name']] = [
            'id' => (int) $traveler['id'],
            'name' => (string) ($traveler['full_name'] ?? $customer['name']),
            'branch_code' => (string) $customer['branch_code'],
            'branch_name' => (string) $branchMap[(string) $customer['branch_code']]['name'],
            'mobile' => (string) ($traveler['mobile'] ?? $customer['mobile']),
            'passport' => (string) ($traveler['passport_number'] ?? $customer['passport']),
            'family_id' => $traveler['family_id'] ?? $customer['family_id'],
            'color_tag' => $traveler['color_tag'] ?? ($customer['color_tag'] ?? 'none'),
            'current_residence' => $traveler['current_residence'] ?? $customer['current_residence'],
            'permanent_residence' => $traveler['permanent_residence'] ?? $customer['permanent_residence'],
            'district' => $traveler['district'] ?? $customer['district'],
            'village' => $traveler['village'] ?? $customer['village'],
        ];
    }

    return $created;
}

function ensureSuppliers(
    array $suppliers,
    SupplierRepository $supplierRepository,
    PDO $db,
    int $actorUserId,
    array $accessibleBranchIds,
    array $branchMap
): array {
    $resolved = [];

    foreach ($suppliers as $supplier) {
        $existing = $supplierRepository->findAccessibleSupplierByName((string) $supplier['name'], $accessibleBranchIds);
        if ($existing === null) {
            $branchId = $supplier['branch_code'] !== null ? (int) $branchMap[(string) $supplier['branch_code']]['id'] : null;
            $supplierRepository->registerSupplier([
                'branch_id' => $branchId,
                'code' => (string) $supplier['code'],
                'name' => (string) $supplier['name'],
                'supplier_mode' => (string) $supplier['supplier_mode'],
                'default_currency' => (string) $supplier['default_currency'],
                'notes' => 'Demo reporting supplier created by reset_demo_reporting_data.php',
                'actor_user_id' => $actorUserId,
            ]);

            $existing = $supplierRepository->findAccessibleSupplierByName((string) $supplier['name'], $accessibleBranchIds);
        }

        if ($existing === null) {
            throw new RuntimeException('Supplier resolution failed for ' . $supplier['name']);
        }

        $resolved[(string) $supplier['name']] = [
            'id' => (int) $existing['id'],
            'code' => (string) $existing['code'],
            'name' => (string) $existing['name'],
            'branch_name' => resolveBranchNameById($db, $branchMap, isset($existing['branch_id']) ? (int) $existing['branch_id'] : null),
            'supplier_mode' => (string) $existing['supplier_mode'],
            'default_currency' => (string) $existing['default_currency'],
        ];
    }

    return $resolved;
}

function seedScenario(
    App $app,
    array $scenario,
    array $customerMap,
    array $supplierMap,
    array $branchMap,
    BookingWorkspaceService $bookingService,
    ServiceWorkspaceService $serviceWorkspace,
    CustomerReceiptWorkspaceService $receiptService,
    SupplierSettlementWorkspaceService $supplierSettlementService,
    BookingRepository $bookingRepository,
    BookingServiceRepository $bookingServiceRepository,
    CustomerPaymentRepository $customerPaymentRepository,
    SupplierRepository $supplierRepository,
    PDO $db,
    int $actorUserId,
    array $accessibleBranchIds
): array {
    $customer = $customerMap[(string) $scenario['customer']] ?? null;
    if ($customer === null) {
        throw new RuntimeException('Scenario customer not found: ' . $scenario['customer']);
    }

    $branch = $branchMap[(string) $scenario['branch_code']] ?? null;
    if ($branch === null) {
        throw new RuntimeException('Scenario branch not found: ' . $scenario['branch_code']);
    }

    $bookingResult = $bookingService->saveBooking([
        'branch_id' => (int) $branch['id'],
        'booking_status' => 'open',
        'booking_date' => (string) $scenario['booking_date'],
        'due_date' => (string) ($scenario['due_date'] ?? ''),
        'departure_date' => (string) ($scenario['departure_date'] ?? ''),
        'return_date' => (string) ($scenario['return_date'] ?? ''),
        'party_label' => (string) $customer['name'],
        'lead_traveler_name' => (string) $customer['name'],
        'contact_mobile' => (string) $customer['mobile'],
        'passport_number' => (string) $customer['passport'],
        'party_notes' => (string) $scenario['notes'],
        'selected_customer_id' => (int) $customer['id'],
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? null;
    if (! is_array($booking) || (int) ($booking['id'] ?? 0) <= 0) {
        throw new RuntimeException('Booking save failed for scenario ' . $scenario['code']);
    }

    $serviceRows = [];
    $serviceLookup = [];
    foreach ($scenario['services'] as $serviceDefinition) {
        $input = [
            'booking_id' => (int) $booking['id'],
            'service_type' => (string) $serviceDefinition['type'],
            'supplier_name' => (string) ($serviceDefinition['supplier'] ?? ''),
            'service_traveler_id' => (int) $customer['id'],
            'service_passenger_name' => (string) $serviceDefinition['passenger'],
            'currency' => (string) $serviceDefinition['currency'],
            'sale_price' => $serviceDefinition['sale_price'] ?? 0,
            'purchase_cost' => $serviceDefinition['purchase_cost'] ?? 0,
            'taxes' => $serviceDefinition['taxes'] ?? 0,
            'service_charge' => $serviceDefinition['service_charge'] ?? 0,
            'discount_amount' => $serviceDefinition['discount_amount'] ?? 0,
            'final_sale_price' => $serviceDefinition['final_sale_price'] ?? null,
            'commission' => $serviceDefinition['commission'] ?? 0,
            'due_date' => (string) ($serviceDefinition['due_date'] ?? ''),
            'service_status' => (string) ($serviceDefinition['status'] ?? 'Open'),
            'remarks' => (string) (($serviceDefinition['ticket']['remarks'] ?? $serviceDefinition['subtype']['remarks'] ?? $scenario['notes']) ?? ''),
            'loss_reason' => (string) ($serviceDefinition['loss_reason'] ?? ''),
        ];

        if ((string) $serviceDefinition['type'] === 'air ticket') {
            $ticket = $serviceDefinition['ticket'] ?? [];
            $input = array_merge($input, [
                'ticket_pnr' => $ticket['pnr'] ?? null,
                'ticket_number' => $ticket['ticket_number'] ?? null,
                'ticket_airline' => $ticket['airline'] ?? null,
                'ticket_sector_from' => $ticket['sector_from'] ?? null,
                'ticket_sector_to' => $ticket['sector_to'] ?? null,
                'ticket_departure_date' => $ticket['departure_date'] ?? null,
                'ticket_return_date' => $ticket['return_date'] ?? null,
                'ticket_class' => $ticket['class'] ?? null,
                'ticket_fare' => $ticket['fare'] ?? ($serviceDefinition['sale_price'] ?? 0),
                'ticket_tax' => $ticket['tax'] ?? ($serviceDefinition['taxes'] ?? 0),
                'ticket_vat' => $ticket['vat'] ?? 0,
                'ticket_commission' => $ticket['commission'] ?? ($serviceDefinition['commission'] ?? 0),
                'ticket_remarks' => $ticket['remarks'] ?? null,
                'other_fare' => $serviceDefinition['other_fare'] ?? 0,
                'soto_fare' => $serviceDefinition['soto_fare'] ?? 0,
                'spyi_amount' => $serviceDefinition['spyi_amount'] ?? 0,
                'aq_yr_pk_amount' => $serviceDefinition['aq_yr_pk_amount'] ?? 0,
                'yq_amount' => $serviceDefinition['yq_amount'] ?? 0,
                'oth_amount' => $serviceDefinition['oth_amount'] ?? 0,
                'vat_input' => $serviceDefinition['vat_input'] ?? 0,
            ]);
        }

        $serviceResult = $serviceWorkspace->saveService($input, $actorUserId, $accessibleBranchIds);
        $savedService = $serviceResult['service'] ?? null;
        if (! is_array($savedService) || (int) ($savedService['id'] ?? 0) <= 0) {
            throw new RuntimeException('Service save failed for scenario ' . $scenario['code'] . ' / ' . $serviceDefinition['label']);
        }

        if ((string) $serviceDefinition['type'] !== 'air ticket' && isset($serviceDefinition['subtype']) && is_array($serviceDefinition['subtype'])) {
            updateSubtypeDetails($db, (string) $serviceDefinition['type'], (int) $savedService['id'], $serviceDefinition['subtype']);
            $savedService = $bookingServiceRepository->findServiceById((int) $savedService['id']) ?? $savedService;
        }

        $serviceRow = [
            'scenario_code' => (string) $scenario['code'],
            'invoice_no' => (string) $booking['booking_reference'],
            'booking_id' => (int) $booking['id'],
            'service_id' => (int) $savedService['id'],
            'service_ref' => (string) ($savedService['line_reference'] ?? ''),
            'service_type' => normalizeServiceTypeLabel((string) $serviceDefinition['type']),
            'supplier' => (string) ($savedService['supplier_name_snapshot'] ?? $serviceDefinition['supplier'] ?? ''),
            'passenger' => (string) ($savedService['passenger_name_snapshot'] ?? $serviceDefinition['passenger']),
            'currency' => (string) ($savedService['currency'] ?? $serviceDefinition['currency']),
            'sale_price' => round((float) ($savedService['sale_price'] ?? $serviceDefinition['sale_price'] ?? 0), 2),
            'service_charge' => round((float) ($savedService['service_charge'] ?? $serviceDefinition['service_charge'] ?? 0), 2),
            'discount' => round((float) ($savedService['discount_amount'] ?? $serviceDefinition['discount_amount'] ?? 0), 2),
            'final_receivable' => round((float) ($savedService['final_sale_price'] ?? $serviceDefinition['final_sale_price'] ?? 0), 2),
            'purchase_cost' => round((float) ($savedService['purchase_cost'] ?? $serviceDefinition['purchase_cost'] ?? 0), 2),
            'taxes' => round((float) ($savedService['taxes'] ?? $serviceDefinition['taxes'] ?? 0), 2),
            'commission' => round((float) ($savedService['commission'] ?? $serviceDefinition['commission'] ?? 0), 2),
            'payable' => round((float) ($savedService['purchase_cost'] ?? $serviceDefinition['purchase_cost'] ?? 0), 2),
            'profit_loss' => round((float) ($savedService['net_profit_loss'] ?? 0), 2),
            'due_date' => (string) (($savedService['due_date'] ?? $serviceDefinition['due_date'] ?? '') ?: ''),
            'scenario_notes' => (string) $scenario['notes'],
            'status' => (string) ($savedService['service_status'] ?? $serviceDefinition['status'] ?? 'Open'),
        ];

        $serviceRows[] = $serviceRow;
        $serviceLookup[(string) $serviceDefinition['label']] = $serviceRow;

    }

    $receiptRows = [];
    $receiptAllocationRows = [];
    $cumulativeReceived = 0.0;
    $invoiceTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['final_receivable'], $serviceRows)), 2);
    $remainingReceivableByService = [];

    foreach ($serviceRows as $serviceRow) {
        $remainingReceivableByService[$serviceRow['service_ref']] = round((float) $serviceRow['final_receivable'], 2);
    }

    foreach (($scenario['receipts'] ?? []) as $receiptDefinition) {
        $receiptResult = $receiptService->saveReceipt([
            'booking_id' => (int) $booking['id'],
            'receipt_date' => (string) $receiptDefinition['date'],
            'receipt_currency' => (string) $receiptDefinition['currency'],
            'received_amount' => $receiptDefinition['amount'],
            'payment_method' => (string) $receiptDefinition['method'],
            'reference_number' => (string) ($receiptDefinition['reference'] ?? ''),
            'charges_amount' => 0,
            'receipt_status' => 'received',
            'receipt_remarks' => (string) ($receiptDefinition['reference'] ?? 'Demo receipt'),
        ], $actorUserId, $accessibleBranchIds);

        $receipt = $receiptResult['receipt'] ?? null;
        if (! is_array($receipt) || (int) ($receipt['id'] ?? 0) <= 0) {
            throw new RuntimeException('Receipt save failed for scenario ' . $scenario['code']);
        }

        $allocationReceivableIds = [];
        $allocationAmounts = [];
        $allocationNotes = [];
        $allocationRates = [];
        $allocationCurrencies = [];

        foreach (($receiptDefinition['allocations'] ?? []) as $allocationDefinition) {
            $service = $serviceLookup[(string) $allocationDefinition['service_label']] ?? null;
            if ($service === null) {
                throw new RuntimeException('Receipt allocation references unknown service label: ' . $allocationDefinition['service_label']);
            }

            $receivable = $customerPaymentRepository->findReceivableByServiceLine(
                (string) $booking['booking_reference'],
                (string) $service['service_ref']
            );
            if ($receivable === null) {
                throw new RuntimeException('Receivable not found for service label: ' . $allocationDefinition['service_label']);
            }
            if ((float) ($receivable['outstanding_amount'] ?? 0) <= 0) {
                throw new RuntimeException(sprintf(
                    'Receivable already settled before allocation. Scenario %s / invoice %s / service %s / ref %s / due %.2f / allocated %.2f / outstanding %.2f',
                    (string) $scenario['code'],
                    (string) $booking['booking_reference'],
                    (string) $allocationDefinition['service_label'],
                    (string) ($receivable['service_line_reference'] ?? ''),
                    (float) ($receivable['due_amount'] ?? 0),
                    (float) ($receivable['allocated_amount'] ?? 0),
                    (float) ($receivable['outstanding_amount'] ?? 0)
                ));
            }

            $allocationReceivableIds[] = (int) $receivable['id'];
            $allocationAmounts[] = (float) $allocationDefinition['amount'];
            $allocationNotes[] = (string) ($allocationDefinition['note'] ?? '');
            $allocationRates[] = '';
            $allocationCurrencies[] = (string) ($receivable['currency'] ?? $service['currency']);
        }

        if (count($allocationReceivableIds) !== count(array_unique($allocationReceivableIds))) {
            throw new RuntimeException(sprintf(
                'Duplicate receivable mapping detected in scenario %s / invoice %s for receipt %s. IDs: %s',
                (string) $scenario['code'],
                (string) $booking['booking_reference'],
                (string) ($receiptDefinition['reference'] ?? $receiptDefinition['date']),
                implode(', ', array_map('strval', $allocationReceivableIds))
            ));
        }

        if ($allocationReceivableIds !== []) {
            foreach ($allocationReceivableIds as $index => $receivableId) {
                $currentReceivable = $customerPaymentRepository->findReceivableById((int) $receivableId);
                $requestedAmount = (float) $allocationAmounts[$index];
                if ($currentReceivable === null) {
                    throw new RuntimeException('Allocation receivable disappeared before posting for scenario ' . $scenario['code']);
                }
                if ((float) ($currentReceivable['outstanding_amount'] ?? 0) < $requestedAmount) {
                    throw new RuntimeException(sprintf(
                        'Allocation exceeds live outstanding. Scenario %s / invoice %s / receivable %d / due %.2f / allocated %.2f / outstanding %.2f / requested %.2f',
                        (string) $scenario['code'],
                        (string) $booking['booking_reference'],
                        (int) $receivableId,
                        (float) ($currentReceivable['due_amount'] ?? 0),
                        (float) ($currentReceivable['allocated_amount'] ?? 0),
                        (float) ($currentReceivable['outstanding_amount'] ?? 0),
                        $requestedAmount
                    ));
                }
                allocateCustomerReceiptForSeed(
                    $app,
                    $db,
                    (int) $branch['id'],
                    (string) $booking['booking_reference'],
                    (int) $receipt['id'],
                    (string) ($receipt['receipt_no'] ?? ''),
                    (string) $receipt['receipt_date'],
                    (string) $receipt['currency'],
                    (int) $receivableId,
                    $requestedAmount,
                    $allocationNotes[$index] ?? '',
                    null,
                    $actorUserId
                );
            }
        }

        $cumulativeReceived = round($cumulativeReceived + (float) $receiptDefinition['amount'], 2);
        $expectedBalance = round(max($invoiceTotal - $cumulativeReceived, 0), 2);

        $receiptRows[] = [
            'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
            'invoice_no' => (string) $booking['booking_reference'],
            'branch' => (string) $branch['name'],
            'customer' => (string) $customer['name'],
            'receipt_date' => (string) $receipt['receipt_date'],
            'currency' => (string) $receipt['currency'],
            'amount_received' => round((float) $receipt['received_amount'], 2),
            'payment_method' => formatPaymentMethod((string) $receipt['payment_method']),
            'cumulative_received_after_receipt' => $cumulativeReceived,
            'expected_balance_after_receipt' => $expectedBalance,
        ];

        foreach (($receiptDefinition['allocations'] ?? []) as $allocationDefinition) {
            $service = $serviceLookup[(string) $allocationDefinition['service_label']];
            $serviceRef = (string) $service['service_ref'];
            $remainingReceivableByService[$serviceRef] = round(
                max(($remainingReceivableByService[$serviceRef] ?? 0) - (float) $allocationDefinition['amount'], 0),
                2
            );

            $receiptAllocationRows[] = [
                'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
                'invoice_no' => (string) $booking['booking_reference'],
                'service_ref_or_due_group' => $serviceRef,
                'allocated_amount' => round((float) $allocationDefinition['amount'], 2),
                'expected_remaining_receivable' => $remainingReceivableByService[$serviceRef],
            ];
        }
    }

    $supplierPaymentRows = [];
    $supplierAdvanceRows = [];
    $supplierAdvanceApplicationRows = [];
    $paidByService = [];
    $advanceAppliedByService = [];
    $supplierBalanceByNameCurrency = [];

    foreach (($scenario['supplier_payments'] ?? []) as $paymentDefinition) {
        $paymentResult = $supplierSettlementService->recordSupplierPayment([
            'booking_id' => (int) $booking['id'],
            'supplier_name' => (string) $paymentDefinition['supplier'],
            'supplier_payment_date' => (string) $paymentDefinition['date'],
            'supplier_payment_currency' => (string) $paymentDefinition['currency'],
            'supplier_paid_amount' => $paymentDefinition['amount'],
            'supplier_payment_method' => (string) $paymentDefinition['method'],
            'supplier_reference_number' => (string) ($paymentDefinition['reference'] ?? ''),
            'supplier_payment_status' => 'paid',
            'supplier_payment_remarks' => (string) ($paymentDefinition['reference'] ?? 'Demo supplier payment'),
        ], $actorUserId, $accessibleBranchIds);

        $payment = $paymentResult['payment'] ?? null;
        if (! is_array($payment) || (int) ($payment['id'] ?? 0) <= 0) {
            throw new RuntimeException('Supplier payment save failed for scenario ' . $scenario['code']);
        }

        $allocationObligationIds = [];
        $allocationAmounts = [];
        $allocationNotes = [];
        $allocationRates = [];

        foreach (($paymentDefinition['allocations'] ?? []) as $allocationDefinition) {
            $service = $serviceLookup[(string) $allocationDefinition['service_label']] ?? null;
            if ($service === null) {
                throw new RuntimeException('Supplier allocation references unknown service label: ' . $allocationDefinition['service_label']);
            }

            $obligation = $supplierRepository->findObligationByServiceLine(
                (string) $booking['booking_reference'],
                (string) $service['service_ref']
            );
            if ($obligation === null) {
                throw new RuntimeException('Supplier allocation references unknown service label: ' . $allocationDefinition['service_label']);
            }

            $allocationObligationIds[] = (int) $obligation['id'];
            $allocationAmounts[] = (float) $allocationDefinition['amount'];
            $allocationNotes[] = (string) ($allocationDefinition['note'] ?? '');
            $allocationRates[] = '';

            $serviceRef = (string) ($obligation['service_line_reference'] ?? '');
            $paidByService[$serviceRef] = round(($paidByService[$serviceRef] ?? 0) + (float) $allocationDefinition['amount'], 2);
        }

        if ($allocationObligationIds !== []) {
            $supplierSettlementService->allocateSupplierPayment([
                'booking_id' => (int) $booking['id'],
                'supplier_payment_id' => (int) $payment['id'],
                'supplier_allocation_obligation_id' => $allocationObligationIds,
                'supplier_allocation_amount' => $allocationAmounts,
                'supplier_allocation_note' => $allocationNotes,
                'supplier_allocation_exchange_rate' => $allocationRates,
            ], $actorUserId, $accessibleBranchIds);
        }

        $supplierKey = makeKey([
            (string) $paymentDefinition['supplier'],
            (string) $scenario['branch_code'],
            (string) $paymentDefinition['currency'],
        ]);
        $supplierBalanceByNameCurrency[$supplierKey] = round(
            ($supplierBalanceByNameCurrency[$supplierKey] ?? 0) + (float) $paymentDefinition['amount'],
            2
        );

        $supplierPaymentRows[] = [
            'payment_no' => (string) ($payment['payment_no'] ?? ''),
            'supplier' => (string) $paymentDefinition['supplier'],
            'branch' => (string) $branch['name'],
            'payment_date' => (string) $payment['payment_date'],
            'currency' => (string) $payment['currency'],
            'amount_paid' => round((float) $payment['paid_amount'], 2),
            'allocated_to_invoice' => (string) $booking['booking_reference'],
            'expected_supplier_balance_after_payment' => null,
        ];
    }

    foreach (($scenario['supplier_advances'] ?? []) as $advanceDefinition) {
        $advanceResult = $supplierSettlementService->recordSupplierAdvance([
            'booking_id' => (int) $booking['id'],
            'supplier_name' => (string) $advanceDefinition['supplier'],
            'advance_currency' => (string) $advanceDefinition['currency'],
            'advance_amount' => $advanceDefinition['amount'],
            'advance_date' => (string) $advanceDefinition['date'],
            'advance_reference_number' => (string) $advanceDefinition['reference'],
            'advance_remarks' => 'Demo supplier advance',
        ], $actorUserId, $accessibleBranchIds);

        $advance = $advanceResult['advance'] ?? null;
        if (! is_array($advance) || (int) ($advance['id'] ?? 0) <= 0) {
            throw new RuntimeException('Supplier advance save failed for scenario ' . $scenario['code']);
        }

        $remainingAdvance = round((float) ($advance['available_amount'] ?? $advanceDefinition['amount']), 2);

        foreach (($advanceDefinition['apply'] ?? []) as $applicationDefinition) {
            $service = $serviceLookup[(string) $applicationDefinition['service_label']] ?? null;
            if ($service === null) {
                throw new RuntimeException('Supplier advance application references unknown service label: ' . $applicationDefinition['service_label']);
            }

            $obligation = $supplierRepository->findObligationByServiceLine(
                (string) $booking['booking_reference'],
                (string) $service['service_ref']
            );
            if ($obligation === null) {
                throw new RuntimeException('Supplier advance application references unknown service label: ' . $applicationDefinition['service_label']);
            }

            $supplierSettlementService->applySupplierAdvance([
                'booking_id' => (int) $booking['id'],
                'supplier_advance_id' => (int) $advance['id'],
                'supplier_obligation_id' => (int) $obligation['id'],
                'advance_apply_amount' => (float) $applicationDefinition['amount'],
            ], $actorUserId, $accessibleBranchIds);

            $serviceRef = (string) ($obligation['service_line_reference'] ?? '');
            $advanceAppliedByService[$serviceRef] = round(
                ($advanceAppliedByService[$serviceRef] ?? 0) + (float) $applicationDefinition['amount'],
                2
            );
            $remainingAdvance = round($remainingAdvance - (float) $applicationDefinition['amount'], 2);

            $supplierAdvanceApplicationRows[] = [
                'scenario_code' => (string) $scenario['code'],
                'supplier' => (string) $advanceDefinition['supplier'],
                'invoice_no' => (string) $booking['booking_reference'],
                'service_ref' => $serviceRef,
                'applied_amount' => round((float) $applicationDefinition['amount'], 2),
                'remaining_advance' => max($remainingAdvance, 0),
            ];
        }

        $supplierAdvanceRows[] = [
            'supplier' => (string) $advanceDefinition['supplier'],
            'invoice_no' => (string) $booking['booking_reference'],
            'branch' => (string) $branch['name'],
            'advance_date' => (string) $advance['received_at'],
            'currency' => (string) $advance['currency'],
            'deposit_amount' => round((float) $advance['deposit_amount'], 2),
            'remaining_advance' => max($remainingAdvance, 0),
            'reference_no' => (string) ($advance['reference_no'] ?? ''),
        ];
    }

    $supplierPayableRows = [];
    $invoiceSupplierPayable = 0.0;
    $invoiceSupplierPaid = 0.0;
    $invoiceAdvanceApplied = 0.0;
    $invoiceProfit = 0.0;

    foreach ($serviceRows as $serviceRow) {
        $obligation = $supplierRepository->findObligationByServiceLine(
            (string) $booking['booking_reference'],
            (string) $serviceRow['service_ref']
        );

        $grossAmount = $obligation !== null ? round((float) ($obligation['gross_amount'] ?? 0), 2) : 0.0;
        $advanceApplied = $obligation !== null ? round((float) ($obligation['advance_applied_amount'] ?? 0), 2) : round((float) ($advanceAppliedByService[$serviceRow['service_ref']] ?? 0), 2);
        $paidAmount = round((float) ($paidByService[$serviceRow['service_ref']] ?? 0), 2);
        $outstanding = $obligation !== null
            ? round((float) ($obligation['net_payable_amount'] ?? 0), 2)
            : round(max($grossAmount - $advanceApplied - $paidAmount, 0), 2);

        $supplierPayableRows[] = [
            'invoice_no' => (string) $booking['booking_reference'],
            'branch' => (string) $branch['name'],
            'service_ref' => (string) $serviceRow['service_ref'],
            'supplier' => (string) $serviceRow['supplier'],
            'payable_date' => (string) $serviceRow['due_date'],
            'currency' => (string) $serviceRow['currency'],
            'payable_amount' => $grossAmount,
            'paid_amount' => $paidAmount,
            'advance_applied' => $advanceApplied,
            'outstanding_amount' => $outstanding,
        ];

        $invoiceSupplierPayable = round($invoiceSupplierPayable + $grossAmount, 2);
        $invoiceSupplierPaid = round($invoiceSupplierPaid + $paidAmount, 2);
        $invoiceAdvanceApplied = round($invoiceAdvanceApplied + $advanceApplied, 2);
        $invoiceProfit = round($invoiceProfit + (float) $serviceRow['profit_loss'], 2);
    }

    $invoiceReceived = round(array_sum(array_map(static fn (array $row): float => (float) $row['amount_received'], $receiptRows)), 2);
    $invoiceBalance = round(max($invoiceTotal - $invoiceReceived, 0), 2);
    $invoiceSupplierOutstanding = round(max($invoiceSupplierPayable - $invoiceSupplierPaid - $invoiceAdvanceApplied, 0), 2);

    $invoiceRow = [
        'scenario_code' => (string) $scenario['code'],
        'invoice_no' => (string) $booking['booking_reference'],
        'branch' => (string) $branch['name'],
        'branch_code' => (string) $branch['code'],
        'customer' => (string) $customer['name'],
        'customer_id' => (int) $customer['id'],
        'invoice_date' => (string) $booking['booking_date'],
        'due_date' => (string) ($booking['due_date'] ?? ''),
        'currency' => (string) $scenario['currency'],
        'invoice_total' => $invoiceTotal,
        'total_received' => $invoiceReceived,
        'balance_due' => $invoiceBalance,
        'supplier_payable' => $invoiceSupplierPayable,
        'supplier_paid' => $invoiceSupplierPaid,
        'supplier_outstanding' => $invoiceSupplierOutstanding,
        'profit_loss' => $invoiceProfit,
        'status' => $invoiceBalance <= 0 ? 'Paid' : ($invoiceReceived > 0 ? 'Partially Paid' : 'Unpaid'),
        'scenario_notes' => (string) $scenario['notes'],
    ];

    return [
        'invoice' => $invoiceRow,
        'services' => $serviceRows,
        'receipts' => $receiptRows,
        'receipt_allocations' => $receiptAllocationRows,
        'supplier_payables' => $supplierPayableRows,
        'supplier_payments' => finalizeSupplierPaymentBalances($supplierPaymentRows, $supplierPayableRows),
        'supplier_advances' => $supplierAdvanceRows,
        'supplier_advance_applications' => $supplierAdvanceApplicationRows,
    ];
}

function updateSubtypeDetails(PDO $db, string $serviceType, int $serviceId, array $subtype): void
{
    $config = match ($serviceType) {
        'visa' => ['table' => 'service_visa', 'fields' => ['visa_country', 'visa_type', 'remarks']],
        'umrah' => ['table' => 'service_umrah', 'fields' => ['package_name', 'remarks']],
        'hotel' => ['table' => 'service_hotel', 'fields' => ['hotel_name', 'city', 'remarks']],
        'transport' => ['table' => 'service_transport', 'fields' => ['transport_mode', 'route_notes', 'remarks']],
        'tourism' => ['table' => 'service_tour', 'fields' => ['tour_name', 'destination', 'remarks']],
        default => ['table' => 'service_other', 'fields' => ['label', 'remarks']],
    };

    $assignments = [];
    $params = ['booking_service_id' => $serviceId];
    foreach ($config['fields'] as $field) {
        $assignments[] = $field . ' = :' . $field;
        $params[$field] = $subtype[$field] ?? null;
    }

    $statement = $db->prepare(sprintf(
        'UPDATE %s SET %s WHERE booking_service_id = :booking_service_id',
        $config['table'],
        implode(', ', $assignments)
    ));
    $statement->execute($params);
}

function allocateCustomerReceiptForSeed(
    App $app,
    PDO $db,
    int $branchId,
    string $bookingReference,
    int $receiptId,
    string $receiptNo,
    string $receiptDate,
    string $receiptCurrency,
    int $receivableId,
    float $amount,
    ?string $allocationNote,
    ?float $exchangeRateUsed,
    int $actorUserId
): void {
    $receiptStatement = $db->prepare(
        'SELECT id, booking_reference, currency, received_amount, allocated_amount, unallocated_amount
         FROM customer_receipts
         WHERE id = :receipt_id
         FOR UPDATE'
    );
    $receiptStatement->execute(['receipt_id' => $receiptId]);
    $receipt = $receiptStatement->fetch();

    $receivableStatement = $db->prepare(
        'SELECT id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount
         FROM customer_receivable_items
         WHERE id = :receivable_id
         FOR UPDATE'
    );
    $receivableStatement->execute(['receivable_id' => $receivableId]);
    $receivable = $receivableStatement->fetch();

    if ($receipt === false || $receivable === false) {
        throw new RuntimeException('Receipt or receivable item not found for demo allocation.');
    }

    if ((string) $receipt['booking_reference'] !== $bookingReference || (string) $receivable['booking_reference'] !== $bookingReference) {
        throw new RuntimeException('Demo allocation booking mismatch.');
    }

    $outstandingAmount = round((float) $receivable['outstanding_amount'], 2);
    $requestedAllocationAmount = min(round($amount, 2), $outstandingAmount);
    if ($requestedAllocationAmount <= 0) {
        throw new RuntimeException('No allocatable amount remains.');
    }

    $receivableCurrency = (string) $receivable['currency'];
    $effectiveRate = $exchangeRateUsed ?? null;
    if ($receiptCurrency === $receivableCurrency) {
        $effectiveRate = 1.0;
    }
    if ($effectiveRate === null || $effectiveRate <= 0) {
        throw new RuntimeException('A valid exchange rate is required for cross-currency allocation.');
    }

    $unallocatedAmount = round((float) $receipt['unallocated_amount'], 2);
    $maxAllocatableByReceipt = $receiptCurrency === $receivableCurrency
        ? $unallocatedAmount
        : round($unallocatedAmount * $effectiveRate, 2);
    $applicableAmount = round(min($requestedAllocationAmount, $maxAllocatableByReceipt, $outstandingAmount), 2);
    if ($applicableAmount <= 0) {
        throw new RuntimeException('No allocatable amount remains.');
    }

    $receiptConsumedAmount = $receiptCurrency === $receivableCurrency
        ? $applicableAmount
        : round($applicableAmount / $effectiveRate, 2);

    $rateFromCurrency = $receivableCurrency;
    $rateToCurrency = $receiptCurrency;
    $businessReadableRate = $receiptCurrency === $receivableCurrency
        ? 1.0
        : round(1 / $effectiveRate, 8);

    $insertAllocation = $db->prepare(
        'INSERT INTO customer_receipt_allocations (
            customer_receipt_id, customer_receivable_item_id, allocated_amount, receivable_currency,
            receivable_amount_allocated, payment_currency, payment_amount_consumed, allocation_note,
            exchange_rate_used, rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date,
            created_by_user_id
         ) VALUES (
            :customer_receipt_id, :customer_receivable_item_id, :allocated_amount, :receivable_currency,
            :receivable_amount_allocated, :payment_currency, :payment_amount_consumed, :allocation_note,
            :exchange_rate_used, :rate_from_currency, :rate_to_currency, :exchange_rate, :exchange_rate_effective_date,
            :created_by_user_id
         )'
    );
    $insertAllocation->execute([
        'customer_receipt_id' => $receiptId,
        'customer_receivable_item_id' => $receivableId,
        'allocated_amount' => $applicableAmount,
        'receivable_currency' => $receivableCurrency,
        'receivable_amount_allocated' => $applicableAmount,
        'payment_currency' => $receiptCurrency,
        'payment_amount_consumed' => $receiptConsumedAmount,
        'allocation_note' => $allocationNote,
        'exchange_rate_used' => $effectiveRate,
        'rate_from_currency' => $rateFromCurrency,
        'rate_to_currency' => $rateToCurrency,
        'exchange_rate' => $businessReadableRate,
        'exchange_rate_effective_date' => $receiptDate,
        'created_by_user_id' => $actorUserId,
    ]);
    $allocationId = (int) $db->lastInsertId();

    $newReceiptAllocated = round((float) $receipt['allocated_amount'] + $receiptConsumedAmount, 2);
    $newReceiptUnallocated = round(max((float) $receipt['received_amount'] - $newReceiptAllocated, 0), 2);
    $receiptStatus = $newReceiptUnallocated <= 0 ? 'fully_allocated' : 'partially_allocated';

    $updateReceipt = $db->prepare(
        'UPDATE customer_receipts
         SET allocated_amount = :allocated_amount,
             unallocated_amount = :unallocated_amount,
             status = :status
         WHERE id = :receipt_id'
    );
    $updateReceipt->execute([
        'allocated_amount' => $newReceiptAllocated,
        'unallocated_amount' => $newReceiptUnallocated,
        'status' => $receiptStatus,
        'receipt_id' => $receiptId,
    ]);

    $newReceivableAllocated = round((float) $receivable['allocated_amount'] + $applicableAmount, 2);
    $newReceivableOutstanding = round(max((float) $receivable['due_amount'] - $newReceivableAllocated, 0), 2);
    $receivableStatus = $newReceivableOutstanding <= 0 ? 'paid' : 'partially_paid';

    $updateReceivable = $db->prepare(
        'UPDATE customer_receivable_items
         SET allocated_amount = :allocated_amount,
             outstanding_amount = :outstanding_amount,
             status = :status
         WHERE id = :receivable_id'
    );
    $updateReceivable->execute([
        'allocated_amount' => $newReceivableAllocated,
        'outstanding_amount' => $newReceivableOutstanding,
        'status' => $receivableStatus,
        'receivable_id' => $receivableId,
    ]);

    AuditLog::record($app, 'customer.receipt.allocated', [
        'user_id' => $actorUserId,
        'customer_receipt_id' => $receiptId,
        'customer_receivable_item_id' => $receivableId,
        'allocated_amount' => $applicableAmount,
        'receipt_consumed_amount' => $receiptConsumedAmount,
        'exchange_rate_used' => $effectiveRate,
        'allocation_note' => $allocationNote,
    ]);

    (new AccountingRepository($app))->postCustomerReceiptAllocation([
        'branch_id' => $branchId,
        'booking_reference' => $bookingReference,
        'source_reference' => $receiptNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => (string) ($receivable['service_line_reference'] ?? '') !== '' ? (string) $receivable['service_line_reference'] : null,
        'customer_receivable_item_id' => $receivableId,
        'customer_receipt_id' => $receiptId,
        'allocated_amount' => $applicableAmount,
        'entry_date' => $receiptDate,
        'currency' => $receivableCurrency !== '' ? $receivableCurrency : $receiptCurrency,
        'actor_user_id' => $actorUserId,
    ]);
}

function buildWorksheets(array $runtime): array
{
    $asOfDate = (string) $runtime['reporting_period']['as_of_date'];

    $customerOutstandingRows = buildCustomerOutstandingRows($runtime['invoices'], $asOfDate);
    $supplierOutstandingRows = buildSupplierOutstandingRows($runtime['supplier_payables']);
    $branchPnlRows = buildPnlByBranchRows($runtime['invoices']);
    $serviceTypePnlRows = buildPnlByServiceTypeRows($runtime['services']);
    $cashFlowRows = buildCashFlowRows($runtime['receipts'], $runtime['supplier_payments'], $runtime['supplier_advances']);
    $monthlySummaryRows = buildMonthlySummaryRows(
        $runtime['invoices'],
        $runtime['receipts'],
        $runtime['supplier_payments'],
        $runtime['supplier_advances'],
        $runtime['supplier_payables']
    );

    $customerRows = [];
    foreach ($runtime['customers'] as $customer) {
        $customerRows[] = [
            'customer_id_or_code' => 'TRV-' . str_pad((string) $customer['id'], 5, '0', STR_PAD_LEFT),
            'name' => $customer['name'],
            'branch' => $customer['branch_name'],
            'mobile' => $customer['mobile'],
            'passport' => $customer['passport'],
            'family_id' => $customer['family_id'] ?? '',
            'color_tag' => $customer['color_tag'] ?? '',
            'previous_balance_expected' => round((float) ($runtime['customer_previous_balances'][$customer['name']] ?? 0), 2),
        ];
    }

    $supplierRows = [];
    foreach ($runtime['suppliers'] as $supplier) {
        $supplierRows[] = [
            'supplier_id_or_code' => $supplier['code'],
            'supplier_name' => $supplier['name'],
            'branch' => $supplier['branch_name'] ?? 'Shared',
            'supplier_type' => $supplier['supplier_mode'],
        ];
    }

    $invoiceRows = array_map(static fn (array $invoice): array => [
        'invoice_no' => $invoice['invoice_no'],
        'branch' => $invoice['branch'],
        'customer' => $invoice['customer'],
        'invoice_date' => $invoice['invoice_date'],
        'due_date' => $invoice['due_date'],
        'currency' => $invoice['currency'],
        'invoice_total' => $invoice['invoice_total'],
        'total_received' => $invoice['total_received'],
        'balance_due' => $invoice['balance_due'],
        'supplier_payable' => $invoice['supplier_payable'],
        'supplier_paid' => $invoice['supplier_paid'],
        'supplier_outstanding' => $invoice['supplier_outstanding'],
        'profit_loss' => $invoice['profit_loss'],
        'status' => $invoice['status'],
        'scenario_notes' => $invoice['scenario_notes'],
    ], $runtime['invoices']);

    $serviceRows = array_map(static fn (array $service): array => [
        'invoice_no' => $service['invoice_no'],
        'service_ref' => $service['service_ref'],
        'service_type' => $service['service_type'],
        'supplier' => $service['supplier'],
        'passenger' => $service['passenger'],
        'currency' => $service['currency'],
        'sale_price' => $service['sale_price'],
        'service_charge' => $service['service_charge'],
        'discount' => $service['discount'],
        'final_receivable' => $service['final_receivable'],
        'purchase_cost' => $service['purchase_cost'],
        'taxes' => $service['taxes'],
        'commission' => $service['commission'],
        'payable' => $service['payable'],
        'profit_loss' => $service['profit_loss'],
        'due_date' => $service['due_date'],
        'scenario_notes' => $service['scenario_notes'],
    ], $runtime['services']);

    $readmeRows = [
        ['topic' => 'Purpose', 'details' => 'Dev-only deterministic reporting dataset for 2026-01-01 to 2026-03-31.'],
        ['topic' => 'Warning', 'details' => 'Do not run against production. This reset wipes travelers and transactional business data only.'],
        ['topic' => 'Currencies', 'details' => 'PKR, AED, USD'],
        ['topic' => 'FX Rates', 'details' => 'PKR->PKR 1.00, AED->PKR 76.00, USD->PKR 280.00 effective 2026-01-01'],
        ['topic' => 'Invoice Formula', 'details' => 'Invoice total = sum(final_receivable) per invoice. Balance due = invoice total - total received.'],
        ['topic' => 'Supplier Outstanding Formula', 'details' => 'Outstanding = payable - paid - advance applied.'],
        ['topic' => 'Receipt Balance Formula', 'details' => 'Balance due = max(total invoice amount - cumulative invoice receipts, 0).'],
        ['topic' => 'Commission Note', 'details' => 'Air-ticket commission is stored separately. Current service profit remains final_receivable - payable under existing system logic.'],
        ['topic' => 'Non-Air Limitation', 'details' => 'Non-air subtype detail tables are populated by a tiny demo-only direct SQL helper because current repository persistence is air-ticket-first.'],
    ];

    return [
        'README' => $readmeRows,
        'Customers' => $customerRows,
        'Suppliers' => $supplierRows,
        'Invoices' => $invoiceRows,
        'Services' => $serviceRows,
        'Receipts' => $runtime['receipts'],
        'Receipt_Allocations' => $runtime['receipt_allocations'],
        'Supplier_Payables' => $runtime['supplier_payables'],
        'Supplier_Payments' => $runtime['supplier_payments'],
        'Expected_Customer_Outstanding' => $customerOutstandingRows,
        'Expected_Supplier_Outstanding' => $supplierOutstandingRows,
        'Expected_PnL_By_Branch' => $branchPnlRows,
        'Expected_PnL_By_Service_Type' => $serviceTypePnlRows,
        'Expected_Cash_Flow' => $cashFlowRows,
        'Expected_Monthly_Summary' => $monthlySummaryRows,
        'Report_Test_Checklist' => buildReportChecklistRows($branchPnlRows, $customerOutstandingRows, $supplierOutstandingRows, $serviceTypePnlRows, $monthlySummaryRows),
    ];
}

function buildCustomerOutstandingRows(array $invoices, string $asOfDate): array
{
    $grouped = [];

    foreach ($invoices as $invoice) {
        $key = makeKey([$invoice['customer'], $invoice['branch'], $invoice['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'customer' => $invoice['customer'],
                'branch' => $invoice['branch'],
                'currency' => $invoice['currency'],
                'total_invoice_amount' => 0.0,
                'total_received' => 0.0,
                'expected_outstanding' => 0.0,
                'oldest_due_date' => null,
            ];
        }

        $grouped[$key]['total_invoice_amount'] = round($grouped[$key]['total_invoice_amount'] + (float) $invoice['invoice_total'], 2);
        $grouped[$key]['total_received'] = round($grouped[$key]['total_received'] + (float) $invoice['total_received'], 2);
        $grouped[$key]['expected_outstanding'] = round($grouped[$key]['expected_outstanding'] + (float) $invoice['balance_due'], 2);

        if ((float) $invoice['balance_due'] > 0) {
            $dueDate = (string) $invoice['due_date'];
            if ($grouped[$key]['oldest_due_date'] === null || ($dueDate !== '' && $dueDate < $grouped[$key]['oldest_due_date'])) {
                $grouped[$key]['oldest_due_date'] = $dueDate;
            }
        }
    }

    $rows = [];
    foreach ($grouped as $row) {
        $rows[] = [
            'customer' => $row['customer'],
            'branch' => $row['branch'],
            'currency' => $row['currency'],
            'total_invoice_amount' => $row['total_invoice_amount'],
            'total_received' => $row['total_received'],
            'expected_outstanding' => $row['expected_outstanding'],
            'aging_bucket' => agingBucket($row['oldest_due_date'], $asOfDate, $row['expected_outstanding']),
        ];
    }

    usort($rows, static fn (array $a, array $b): int => [$a['branch'], $a['customer'], $a['currency']] <=> [$b['branch'], $b['customer'], $b['currency']]);

    return $rows;
}

function buildSupplierOutstandingRows(array $supplierPayables): array
{
    $grouped = [];

    foreach ($supplierPayables as $payable) {
        $key = makeKey([$payable['supplier'], $payable['branch'], $payable['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'supplier' => $payable['supplier'],
                'branch' => $payable['branch'],
                'currency' => $payable['currency'],
                'total_payable' => 0.0,
                'total_paid' => 0.0,
                'advance_applied' => 0.0,
                'expected_outstanding' => 0.0,
            ];
        }

        $grouped[$key]['total_payable'] = round($grouped[$key]['total_payable'] + (float) $payable['payable_amount'], 2);
        $grouped[$key]['total_paid'] = round($grouped[$key]['total_paid'] + (float) $payable['paid_amount'], 2);
        $grouped[$key]['advance_applied'] = round($grouped[$key]['advance_applied'] + (float) $payable['advance_applied'], 2);
        $grouped[$key]['expected_outstanding'] = round($grouped[$key]['expected_outstanding'] + (float) $payable['outstanding_amount'], 2);
    }

    $rows = array_values($grouped);
    usort($rows, static fn (array $a, array $b): int => [$a['branch'], $a['supplier'], $a['currency']] <=> [$b['branch'], $b['supplier'], $b['currency']]);

    return $rows;
}

function buildPnlByBranchRows(array $invoices): array
{
    $grouped = [];

    foreach ($invoices as $invoice) {
        $key = makeKey([$invoice['branch'], $invoice['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'branch' => $invoice['branch'],
                'currency' => $invoice['currency'],
                'total_receivable' => 0.0,
                'total_payable' => 0.0,
                'expected_profit_loss' => 0.0,
            ];
        }

        $grouped[$key]['total_receivable'] = round($grouped[$key]['total_receivable'] + (float) $invoice['invoice_total'], 2);
        $grouped[$key]['total_payable'] = round($grouped[$key]['total_payable'] + (float) $invoice['supplier_payable'], 2);
        $grouped[$key]['expected_profit_loss'] = round($grouped[$key]['expected_profit_loss'] + (float) $invoice['profit_loss'], 2);
    }

    $rows = array_values($grouped);
    usort($rows, static fn (array $a, array $b): int => [$a['branch'], $a['currency']] <=> [$b['branch'], $b['currency']]);

    return $rows;
}

function buildPnlByServiceTypeRows(array $services): array
{
    $grouped = [];

    foreach ($services as $service) {
        $key = makeKey([$service['service_type'], $service['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'service_type' => $service['service_type'],
                'currency' => $service['currency'],
                'total_receivable' => 0.0,
                'total_payable' => 0.0,
                'expected_profit_loss' => 0.0,
            ];
        }

        $grouped[$key]['total_receivable'] = round($grouped[$key]['total_receivable'] + (float) $service['final_receivable'], 2);
        $grouped[$key]['total_payable'] = round($grouped[$key]['total_payable'] + (float) $service['payable'], 2);
        $grouped[$key]['expected_profit_loss'] = round($grouped[$key]['expected_profit_loss'] + (float) $service['profit_loss'], 2);
    }

    $rows = array_values($grouped);
    usort($rows, static fn (array $a, array $b): int => [$a['service_type'], $a['currency']] <=> [$b['service_type'], $b['currency']]);

    return $rows;
}

function buildCashFlowRows(array $receipts, array $supplierPayments, array $supplierAdvances): array
{
    $grouped = [];

    foreach ($receipts as $receipt) {
        $key = makeKey([$receipt['receipt_date'], $receipt['branch'], $receipt['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'date' => $receipt['receipt_date'],
                'branch' => $receipt['branch'],
                'currency' => $receipt['currency'],
                'cash_in_customer_receipts' => 0.0,
                'cash_out_supplier_payments' => 0.0,
                'net_cash_flow' => 0.0,
            ];
        }

        $grouped[$key]['cash_in_customer_receipts'] = round($grouped[$key]['cash_in_customer_receipts'] + (float) $receipt['amount_received'], 2);
    }

    foreach ($supplierPayments as $payment) {
        $key = makeKey([$payment['payment_date'], $payment['branch'], $payment['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'date' => $payment['payment_date'],
                'branch' => $payment['branch'],
                'currency' => $payment['currency'],
                'cash_in_customer_receipts' => 0.0,
                'cash_out_supplier_payments' => 0.0,
                'net_cash_flow' => 0.0,
            ];
        }

        $grouped[$key]['cash_out_supplier_payments'] = round($grouped[$key]['cash_out_supplier_payments'] + (float) $payment['amount_paid'], 2);
    }

    foreach ($supplierAdvances as $advance) {
        $key = makeKey([$advance['advance_date'], $advance['branch'], $advance['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'date' => $advance['advance_date'],
                'branch' => $advance['branch'],
                'currency' => $advance['currency'],
                'cash_in_customer_receipts' => 0.0,
                'cash_out_supplier_payments' => 0.0,
                'net_cash_flow' => 0.0,
            ];
        }

        $grouped[$key]['cash_out_supplier_payments'] = round($grouped[$key]['cash_out_supplier_payments'] + (float) $advance['deposit_amount'], 2);
    }

    foreach ($grouped as &$row) {
        $row['net_cash_flow'] = round((float) $row['cash_in_customer_receipts'] - (float) $row['cash_out_supplier_payments'], 2);
    }
    unset($row);

    $rows = array_values($grouped);
    usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['branch'], $a['currency']] <=> [$b['date'], $b['branch'], $b['currency']]);

    return $rows;
}

function buildMonthlySummaryRows(
    array $invoices,
    array $receipts,
    array $supplierPayments,
    array $supplierAdvances,
    array $supplierPayables
): array {
    $grouped = [];
    $allMonths = [];

    foreach ($invoices as $invoice) {
        $month = substr((string) $invoice['invoice_date'], 0, 7);
        $allMonths[$month] = true;
        $key = makeKey([$month, $invoice['branch'], $invoice['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'month' => $month,
                'branch' => $invoice['branch'],
                'currency' => $invoice['currency'],
                'invoice_total' => 0.0,
                'receipts_total' => 0.0,
                'supplier_payments_total' => 0.0,
                'expected_profit_loss' => 0.0,
                'ending_customer_outstanding' => 0.0,
                'ending_supplier_outstanding' => 0.0,
            ];
        }

        $grouped[$key]['invoice_total'] = round($grouped[$key]['invoice_total'] + (float) $invoice['invoice_total'], 2);
        $grouped[$key]['expected_profit_loss'] = round($grouped[$key]['expected_profit_loss'] + (float) $invoice['profit_loss'], 2);
    }

    foreach ($receipts as $receipt) {
        $month = substr((string) $receipt['receipt_date'], 0, 7);
        $allMonths[$month] = true;
        $key = makeKey([$month, $receipt['branch'], $receipt['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'month' => $month,
                'branch' => $receipt['branch'],
                'currency' => $receipt['currency'],
                'invoice_total' => 0.0,
                'receipts_total' => 0.0,
                'supplier_payments_total' => 0.0,
                'expected_profit_loss' => 0.0,
                'ending_customer_outstanding' => 0.0,
                'ending_supplier_outstanding' => 0.0,
            ];
        }

        $grouped[$key]['receipts_total'] = round($grouped[$key]['receipts_total'] + (float) $receipt['amount_received'], 2);
    }

    foreach ($supplierPayments as $payment) {
        $month = substr((string) $payment['payment_date'], 0, 7);
        $allMonths[$month] = true;
        $key = makeKey([$month, $payment['branch'], $payment['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'month' => $month,
                'branch' => $payment['branch'],
                'currency' => $payment['currency'],
                'invoice_total' => 0.0,
                'receipts_total' => 0.0,
                'supplier_payments_total' => 0.0,
                'expected_profit_loss' => 0.0,
                'ending_customer_outstanding' => 0.0,
                'ending_supplier_outstanding' => 0.0,
            ];
        }

        $grouped[$key]['supplier_payments_total'] = round($grouped[$key]['supplier_payments_total'] + (float) $payment['amount_paid'], 2);
    }

    foreach ($supplierAdvances as $advance) {
        $month = substr((string) $advance['advance_date'], 0, 7);
        $allMonths[$month] = true;
        $key = makeKey([$month, $advance['branch'], $advance['currency']]);
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'month' => $month,
                'branch' => $advance['branch'],
                'currency' => $advance['currency'],
                'invoice_total' => 0.0,
                'receipts_total' => 0.0,
                'supplier_payments_total' => 0.0,
                'expected_profit_loss' => 0.0,
                'ending_customer_outstanding' => 0.0,
                'ending_supplier_outstanding' => 0.0,
            ];
        }

        $grouped[$key]['supplier_payments_total'] = round($grouped[$key]['supplier_payments_total'] + (float) $advance['deposit_amount'], 2);
    }

    foreach ($grouped as &$row) {
        $monthEnd = $row['month'] . '-31';
        $row['ending_customer_outstanding'] = invoiceOutstandingForMonth($invoices, $row['branch'], $row['currency'], $monthEnd);
        $row['ending_supplier_outstanding'] = supplierOutstandingForMonth($supplierPayables, $row['branch'], $row['currency'], $monthEnd);
    }
    unset($row);

    $rows = array_values($grouped);
    usort($rows, static fn (array $a, array $b): int => [$a['month'], $a['branch'], $a['currency']] <=> [$b['month'], $b['branch'], $b['currency']]);

    return $rows;
}

function buildReportChecklistRows(
    array $branchPnlRows,
    array $customerOutstandingRows,
    array $supplierOutstandingRows,
    array $serviceTypePnlRows,
    array $monthlySummaryRows
): array {
    $rows = [];

    foreach ($customerOutstandingRows as $row) {
        $rows[] = [
            'report_name' => 'Customer Outstanding',
            'system_filter_to_use' => sprintf('%s | %s | as of 2026-03-31', $row['branch'], $row['currency']),
            'expected_value_to_compare' => $row['customer'],
            'expected_result' => sprintf('Outstanding %.2f %s', $row['expected_outstanding'], $row['currency']),
            'pass_fail_manual' => '',
        ];
    }

    foreach ($supplierOutstandingRows as $row) {
        $rows[] = [
            'report_name' => 'Supplier Outstanding',
            'system_filter_to_use' => sprintf('%s | %s | as of 2026-03-31', $row['branch'], $row['currency']),
            'expected_value_to_compare' => $row['supplier'],
            'expected_result' => sprintf('Outstanding %.2f %s', $row['expected_outstanding'], $row['currency']),
            'pass_fail_manual' => '',
        ];
    }

    foreach ($branchPnlRows as $row) {
        $rows[] = [
            'report_name' => 'Branch Performance / Profit & Loss',
            'system_filter_to_use' => sprintf('%s | 2026-01-01 to 2026-03-31', $row['branch']),
            'expected_value_to_compare' => $row['currency'],
            'expected_result' => sprintf('Receivable %.2f, Payable %.2f, Profit %.2f', $row['total_receivable'], $row['total_payable'], $row['expected_profit_loss']),
            'pass_fail_manual' => '',
        ];
    }

    foreach ($serviceTypePnlRows as $row) {
        $rows[] = [
            'report_name' => 'Service Profit',
            'system_filter_to_use' => '2026-01-01 to 2026-03-31',
            'expected_value_to_compare' => $row['service_type'] . ' / ' . $row['currency'],
            'expected_result' => sprintf('Receivable %.2f, Payable %.2f, Profit %.2f', $row['total_receivable'], $row['total_payable'], $row['expected_profit_loss']),
            'pass_fail_manual' => '',
        ];
    }

    foreach ($monthlySummaryRows as $row) {
        $rows[] = [
            'report_name' => 'Monthly Summary',
            'system_filter_to_use' => sprintf('%s | %s', $row['month'], $row['branch']),
            'expected_value_to_compare' => $row['currency'],
            'expected_result' => sprintf('Invoice %.2f, Receipts %.2f, Supplier Cash Out %.2f, Profit %.2f', $row['invoice_total'], $row['receipts_total'], $row['supplier_payments_total'], $row['expected_profit_loss']),
            'pass_fail_manual' => '',
        ];
    }

    return $rows;
}

function exportWorksheets(array $worksheets): string
{
    $baseDirectory = BASE_PATH . '/storage/demo_reporting_truth';
    $directory = prepareCsvExportDirectory($baseDirectory);

    foreach ($worksheets as $sheetName => $rows) {
        writeCsvFile($directory . '/' . $sheetName . '.csv', $rows);
    }

    return $directory;
}

function prepareCsvExportDirectory(string $baseDirectory): string
{
    if (! is_dir($baseDirectory) && ! mkdir($baseDirectory, 0777, true) && ! is_dir($baseDirectory)) {
        throw new RuntimeException('Unable to create CSV export directory: ' . $baseDirectory);
    }

    $existingFiles = glob($baseDirectory . '/*.csv') ?: [];
    $locked = false;
    foreach ($existingFiles as $file) {
        if (! safeUnlink($file) && file_exists($file)) {
            $locked = true;
            break;
        }
    }

    if (! $locked) {
        return $baseDirectory;
    }

    $fallbackDirectory = $baseDirectory . '/run_' . date('Ymd_His');
    if (! is_dir($fallbackDirectory) && ! mkdir($fallbackDirectory, 0777, true) && ! is_dir($fallbackDirectory)) {
        throw new RuntimeException('Unable to create fallback CSV export directory: ' . $fallbackDirectory);
    }

    return $fallbackDirectory;
}

function safeUnlink(string $path): bool
{
    $previousHandler = set_error_handler(static fn (): bool => true);

    try {
        return unlink($path);
    } catch (Throwable) {
        return false;
    } finally {
        restore_error_handler();
        if ($previousHandler !== null) {
            set_error_handler($previousHandler);
        }
    }
}

function writeCsvFile(string $path, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write CSV file: ' . $path);
    }

    try {
        if ($rows === []) {
            fputcsv($handle, ['empty']);
            return;
        }

        $headers = array_keys($rows[0]);
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $ordered = [];
            foreach ($headers as $header) {
                $ordered[] = $row[$header] ?? '';
            }
            fputcsv($handle, $ordered);
        }
    } finally {
        fclose($handle);
    }
}

function writeTestGuide(array $runtime, string $csvDirectory): string
{
    $docsDirectory = BASE_PATH . '/docs';
    if (! is_dir($docsDirectory) && ! mkdir($docsDirectory, 0777, true) && ! is_dir($docsDirectory)) {
        throw new RuntimeException('Unable to create docs directory: ' . $docsDirectory);
    }

    $guidePath = $docsDirectory . '/demo-reporting-test-guide.md';
    $branchSummaries = buildPnlByBranchRows($runtime['invoices']);
    $serviceSummaries = buildPnlByServiceTypeRows($runtime['services']);
    $customerOutstanding = buildCustomerOutstandingRows($runtime['invoices'], (string) $runtime['reporting_period']['as_of_date']);
    $supplierOutstanding = buildSupplierOutstandingRows($runtime['supplier_payables']);

    $markdown = [];
    $markdown[] = '# Demo Reporting Test Guide';
    $markdown[] = '';
    $markdown[] = 'This guide is generated by `scripts/reset_demo_reporting_data.php`.';
    $markdown[] = '';
    $markdown[] = '## 1. Run the demo reset';
    $markdown[] = '';
    $markdown[] = '```bash';
    $markdown[] = 'php scripts/reset_demo_reporting_data.php --confirm-demo-reset';
    $markdown[] = '```';
    $markdown[] = '';
    $markdown[] = 'The command refuses to run when `config(\'app.env\')` is `production` and does nothing without the confirmation flag.';
    $markdown[] = '';
    $markdown[] = '## 2. Reporting period and FX';
    $markdown[] = '';
    $markdown[] = '- Period: 2026-01-01 to 2026-03-31';
    $markdown[] = '- Aging as of: 2026-03-31';
    $markdown[] = '- PKR base: 1.00';
    $markdown[] = '- AED to PKR: 76.00';
    $markdown[] = '- USD to PKR: 280.00';
    $markdown[] = '';
    $markdown[] = '## 3. Truth files';
    $markdown[] = '';
    $markdown[] = '- CSV truth pack: `' . str_replace('\\', '/', $csvDirectory) . '`';
    $markdown[] = '- Open the CSV files in Excel or LibreOffice for side-by-side checks.';
    $markdown[] = '';
    $markdown[] = '## 4. Report filters to use';
    $markdown[] = '';
    $markdown[] = '- Date range reports: `2026-01-01` to `2026-03-31`';
    $markdown[] = '- Aging / outstanding reports: `As of 2026-03-31`';
    $markdown[] = '- Branch filters: `Imdad International Travel Agency` and `Noble Route`';
    $markdown[] = '- Currency filter: the current reports UI does not expose a dedicated currency filter, so compare currency-specific rows inside the CSV truth files.';
    $markdown[] = '';
    $markdown[] = '## 5. High-value report checks';
    $markdown[] = '';
    $markdown[] = '### Branch profit and loss';
    $markdown[] = '';
    foreach ($branchSummaries as $row) {
        $markdown[] = sprintf(
            '- %s / %s: receivable %.2f, payable %.2f, profit %.2f',
            $row['branch'],
            $row['currency'],
            $row['total_receivable'],
            $row['total_payable'],
            $row['expected_profit_loss']
        );
    }
    $markdown[] = '';
    $markdown[] = '### Customer outstanding';
    $markdown[] = '';
    foreach ($customerOutstanding as $row) {
        $markdown[] = sprintf(
            '- %s / %s / %s: invoice %.2f, received %.2f, outstanding %.2f, aging %s',
            $row['customer'],
            $row['branch'],
            $row['currency'],
            $row['total_invoice_amount'],
            $row['total_received'],
            $row['expected_outstanding'],
            $row['aging_bucket']
        );
    }
    $markdown[] = '';
    $markdown[] = '### Customer credit / overpayment check';
    $markdown[] = '';
    $markdown[] = '- Scenario 18 adds a PKR invoice of 500.00 with a PKR receipt of 700.00. The expected result is allocated 500.00, outstanding 0.00, and customer credit/unallocated receipt 200.00.';
    $markdown[] = '- Validate this using `Receipts.csv`, `Receipt_Allocations.csv`, the workspace payment summary, and the customer ledger/statement output.';
    $markdown[] = '';
    $markdown[] = '### Supplier outstanding';
    $markdown[] = '';
    foreach ($supplierOutstanding as $row) {
        $markdown[] = sprintf(
            '- %s / %s / %s: payable %.2f, paid %.2f, advance applied %.2f, outstanding %.2f',
            $row['supplier'],
            $row['branch'],
            $row['currency'],
            $row['total_payable'],
            $row['total_paid'],
            $row['advance_applied'],
            $row['expected_outstanding']
        );
    }
    $markdown[] = '';
    $markdown[] = '### Service type profit';
    $markdown[] = '';
    foreach ($serviceSummaries as $row) {
        $markdown[] = sprintf(
            '- %s / %s: receivable %.2f, payable %.2f, profit %.2f',
            $row['service_type'],
            $row['currency'],
            $row['total_receivable'],
            $row['total_payable'],
            $row['expected_profit_loss']
        );
    }
    $markdown[] = '';
    $markdown[] = '## 6. Manual comparison flow';
    $markdown[] = '';
    $markdown[] = '1. Run the reset command.';
    $markdown[] = '2. Open the target system report with the filters above.';
    $markdown[] = '3. Open the matching CSV truth sheet.';
    $markdown[] = '4. Compare totals first, then drill into invoice/service level rows.';
    $markdown[] = '5. For receipts, verify cumulative and remaining balances against `Receipts.csv` and `Receipt_Allocations.csv`.';
    $markdown[] = '6. For supplier settlement, compare `Supplier_Payables.csv`, `Supplier_Payments.csv`, and `Expected_Supplier_Outstanding.csv`.';
    $markdown[] = '';
    $markdown[] = '## 7. Known limitations';
    $markdown[] = '';
    $markdown[] = '- XLSX generation is not used because no spreadsheet writer library is installed in `composer.json`; the truth pack is exported as CSV sheets.';
    $markdown[] = '- Non-air subtype tables are populated by a tiny direct SQL helper after the normal service save because the current repository only persists detailed air-ticket subtype fields.';
    $markdown[] = '- Existing reports currently do not expose a dedicated currency filter, so currency comparisons must be read within the report rows and the CSV truth files.';
    $markdown[] = '- Air-ticket commission is stored separately, but current service profit still follows `final_receivable - payable` under the existing service logic.';
    $markdown[] = '';

    file_put_contents($guidePath, implode(PHP_EOL, $markdown) . PHP_EOL);

    return $guidePath;
}

function printSummary(array $runtime, string $csvDirectory, string $guidePath): void
{
    $receivableTotals = aggregateMoneyByCurrency($runtime['services'], 'final_receivable');
    $receivedTotals = aggregateMoneyByCurrency($runtime['receipts'], 'amount_received');
    $customerOutstandingTotals = aggregateInvoiceOutstandingByCurrency($runtime['invoices']);
    $supplierPayableTotals = aggregateMoneyByCurrency($runtime['supplier_payables'], 'payable_amount');
    $supplierPaidTotals = aggregateSupplierCashOutByCurrency($runtime['supplier_payments'], $runtime['supplier_advances']);
    $supplierOutstandingTotals = aggregateMoneyByCurrency($runtime['supplier_payables'], 'outstanding_amount');
    $profitTotals = aggregateMoneyByCurrency($runtime['services'], 'profit_loss');

    fwrite(STDOUT, "Demo reporting dataset seeded successfully.\n");
    fwrite(STDOUT, 'Customers inserted: ' . count($runtime['customers']) . PHP_EOL);
    fwrite(STDOUT, 'Invoices inserted: ' . count($runtime['invoices']) . PHP_EOL);
    fwrite(STDOUT, 'Services inserted: ' . count($runtime['services']) . PHP_EOL);
    fwrite(STDOUT, 'Receipts inserted: ' . count($runtime['receipts']) . PHP_EOL);
    fwrite(STDOUT, 'Supplier obligations inserted: ' . count($runtime['supplier_payables']) . PHP_EOL);
    fwrite(STDOUT, 'Supplier payments inserted: ' . (count($runtime['supplier_payments']) + count($runtime['supplier_advances'])) . PHP_EOL);
    fwrite(STDOUT, 'Total receivable by currency: ' . formatCurrencyMap($receivableTotals) . PHP_EOL);
    fwrite(STDOUT, 'Total received by currency: ' . formatCurrencyMap($receivedTotals) . PHP_EOL);
    fwrite(STDOUT, 'Total customer outstanding by currency: ' . formatCurrencyMap($customerOutstandingTotals) . PHP_EOL);
    fwrite(STDOUT, 'Total supplier payable by currency: ' . formatCurrencyMap($supplierPayableTotals) . PHP_EOL);
    fwrite(STDOUT, 'Total supplier paid by currency: ' . formatCurrencyMap($supplierPaidTotals) . PHP_EOL);
    fwrite(STDOUT, 'Total supplier outstanding by currency: ' . formatCurrencyMap($supplierOutstandingTotals) . PHP_EOL);
    fwrite(STDOUT, 'Total profit/loss by currency: ' . formatCurrencyMap($profitTotals) . PHP_EOL);
    fwrite(STDOUT, 'CSV truth pack: ' . $csvDirectory . PHP_EOL);
    fwrite(STDOUT, 'Test guide: ' . $guidePath . PHP_EOL);
}

function aggregateMoneyByCurrency(array $rows, string $field): array
{
    $totals = [];
    foreach ($rows as $row) {
        $currency = (string) ($row['currency'] ?? '');
        if ($currency === '') {
            continue;
        }
        $totals[$currency] = round(($totals[$currency] ?? 0) + (float) ($row[$field] ?? 0), 2);
    }
    ksort($totals);

    return $totals;
}

function aggregateInvoiceOutstandingByCurrency(array $invoices): array
{
    $totals = [];
    foreach ($invoices as $invoice) {
        $currency = (string) $invoice['currency'];
        $totals[$currency] = round(($totals[$currency] ?? 0) + (float) $invoice['balance_due'], 2);
    }
    ksort($totals);

    return $totals;
}

function aggregateSupplierCashOutByCurrency(array $supplierPayments, array $supplierAdvances): array
{
    $totals = aggregateMoneyByCurrency($supplierPayments, 'amount_paid');
    foreach ($supplierAdvances as $advance) {
        $currency = (string) $advance['currency'];
        $totals[$currency] = round(($totals[$currency] ?? 0) + (float) $advance['deposit_amount'], 2);
    }
    ksort($totals);

    return $totals;
}

function formatCurrencyMap(array $totals): string
{
    if ($totals === []) {
        return 'None';
    }

    $parts = [];
    foreach ($totals as $currency => $amount) {
        $parts[] = $currency . ' ' . number_format((float) $amount, 2, '.', '');
    }

    return implode(', ', $parts);
}

function splitName(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    if ($parts === []) {
        return ['Customer', ''];
    }

    $firstName = array_shift($parts);
    $lastName = implode(' ', $parts);

    return [$firstName, $lastName];
}

function normalizeServiceTypeLabel(string $serviceType): string
{
    return match ($serviceType) {
        'air ticket' => 'Air Ticket',
        'visa' => 'Visa',
        'umrah' => 'Umrah',
        'hotel' => 'Hotel',
        'transport' => 'Transport',
        'tourism' => 'Tour',
        default => 'Other',
    };
}

function formatPaymentMethod(string $method): string
{
    return match ($method) {
        'bank_transfer' => 'Bank Transfer',
        'debit_card' => 'Debit Card',
        'credit_card' => 'Credit Card',
        default => 'Cash',
    };
}

function agingBucket(?string $dueDate, string $asOfDate, float $outstanding): string
{
    if ($outstanding <= 0) {
        return 'Current';
    }

    if ($dueDate === null || trim($dueDate) === '') {
        return 'Current';
    }

    $days = (int) floor((strtotime($asOfDate) - strtotime($dueDate)) / 86400);
    if ($days <= 0) {
        return 'Current';
    }
    if ($days <= 30) {
        return '1-30';
    }
    if ($days <= 60) {
        return '31-60';
    }
    if ($days <= 90) {
        return '61-90';
    }

    return '90+';
}

function calculateCustomerPreviousBalance(array $invoices, string $customerName, string $currency, string $bookingDate): float
{
    $total = 0.0;
    foreach ($invoices as $invoice) {
        if ((string) $invoice['customer'] !== $customerName) {
            continue;
        }
        if ((string) $invoice['currency'] !== $currency) {
            continue;
        }
        if ((string) $invoice['invoice_date'] >= $bookingDate) {
            continue;
        }
        $total = round($total + (float) $invoice['balance_due'], 2);
    }

    return $total;
}

function finalizeSupplierPaymentBalances(array $supplierPayments, array $supplierPayables): array
{
    $outstandingBySupplierCurrency = [];
    foreach ($supplierPayables as $payable) {
        $key = makeKey([$payable['supplier'], $payable['branch'], $payable['currency']]);
        $outstandingBySupplierCurrency[$key] = round(($outstandingBySupplierCurrency[$key] ?? 0) + (float) $payable['outstanding_amount'], 2);
    }

    $rows = [];
    foreach ($supplierPayments as $payment) {
        $key = makeKey([$payment['supplier'], $payment['branch'], $payment['currency']]);
        $rows[] = array_merge($payment, [
            'expected_supplier_balance_after_payment' => round((float) ($outstandingBySupplierCurrency[$key] ?? 0), 2),
        ]);
    }

    return $rows;
}

function invoiceOutstandingForMonth(array $invoices, string $branchName, string $currency, string $monthEnd): float
{
    $total = 0.0;
    foreach ($invoices as $invoice) {
        if ((string) $invoice['branch'] !== $branchName || (string) $invoice['currency'] !== $currency) {
            continue;
        }
        if ((string) $invoice['invoice_date'] > $monthEnd) {
            continue;
        }
        $total = round($total + (float) $invoice['balance_due'], 2);
    }

    return $total;
}

function supplierOutstandingForMonth(array $supplierPayables, string $branchName, string $currency, string $monthEnd): float
{
    $total = 0.0;
    foreach ($supplierPayables as $payable) {
        if ((string) $payable['branch'] !== $branchName) {
            continue;
        }
        if ((string) $payable['currency'] !== $currency) {
            continue;
        }
        if ((string) $payable['payable_date'] > $monthEnd) {
            continue;
        }
        $total = round($total + (float) $payable['outstanding_amount'], 2);
    }

    return $total;
}

function resolveBranchNameById(PDO $db, array $branchMap, ?int $branchId): string
{
    if ($branchId === null || $branchId <= 0) {
        return 'Shared';
    }

    foreach ($branchMap as $branch) {
        if ((int) $branch['id'] === $branchId) {
            return (string) $branch['name'];
        }
    }

    $statement = $db->prepare('SELECT name FROM branches WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $branchId]);
    $name = $statement->fetchColumn();

    return is_string($name) && $name !== '' ? $name : 'Shared';
}

function makeKey(array $segments): string
{
    return implode('|', array_map(static fn (mixed $segment): string => (string) $segment, $segments));
}
