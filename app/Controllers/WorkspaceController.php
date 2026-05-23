<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Authorization;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Session;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TravelerRepository;
use App\Services\AccountingFoundationService;
use App\Services\BookingWorkspaceService;
use App\Services\CustomerPaymentFoundationService;
use App\Services\CustomerReceiptWorkspaceService;
use App\Services\DocumentWorkspaceService;
use App\Services\OperationalOutputService;
use App\Services\ReminderWorkspaceService;
use App\Services\ServiceWorkspaceService;
use App\Services\SupplierSettlementWorkspaceService;
use App\Services\SupplierFoundationService;
use App\Services\TravelerWorkspaceService;
use PDO;
use RuntimeException;
use Throwable;

final class WorkspaceController extends BaseController
{
    public function index(): string
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $serviceSaveDebug = Session::get('_service_save_debug');
        Session::forget('_service_save_debug');
        $bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : null;
        $bookingReference = trim((string) ($_GET['booking_reference'] ?? ''));
        $customerId = isset($_GET['customer_id'])
            ? (int) $_GET['customer_id']
            : (isset($_GET['traveler_id']) ? (int) $_GET['traveler_id'] : null);
        $searchTerm = trim((string) ($_GET['q'] ?? ''));
        $travelerSearchTerm = trim((string) ($_GET['traveler_q'] ?? ''));
        $editingReminderId = isset($_GET['reminder_edit']) ? (int) $_GET['reminder_edit'] : null;
        $newMode = isset($_GET['new']) && $_GET['new'] === '1';

        try {
            $workspaceState = (new BookingWorkspaceService($this->app))->workspaceState(
                $accessibleBranchIds,
                $bookingId,
                $bookingReference !== '' ? $bookingReference : null,
                $searchTerm,
                $newMode,
                (int) Auth::id()
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace');
        }
        $effectiveBookingId = $bookingId;
        if (($effectiveBookingId === null || $effectiveBookingId <= 0) && (int) ($workspaceState['currentBooking']['id'] ?? 0) > 0) {
            $effectiveBookingId = (int) $workspaceState['currentBooking']['id'];
        }

        try {
            $travelerWorkspaceService = new TravelerWorkspaceService($this->app);
            $travelerState = $travelerWorkspaceService->travelerState(
                $effectiveBookingId,
                $accessibleBranchIds,
                $travelerSearchTerm
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace');
        }

        $selectedTravelerProfile = null;
        if ($customerId !== null && $customerId > 0) {
            $selectedTravelerProfile = $travelerWorkspaceService->travelerProfile($customerId, $accessibleBranchIds);
        }
        if ($selectedTravelerProfile === null) {
            $currentLeadTravelerId = (int) ($workspaceState['currentBooking']['lead_traveler_id'] ?? 0);
            if ($currentLeadTravelerId > 0) {
                $selectedTravelerProfile = $travelerWorkspaceService->travelerProfile($currentLeadTravelerId, $accessibleBranchIds);
            }
        }

        try {
            $serviceState = (new ServiceWorkspaceService($this->app))->serviceState(
                $effectiveBookingId,
                $accessibleBranchIds
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace');
        }

        $bookingReference = (string) ($workspaceState['currentBooking']['booking_reference'] ?? 'Draft');
        $currentBookingContext = [
            'booking_id' => (int) ($workspaceState['currentBooking']['id'] ?? 0),
            'booking_reference' => $bookingReference,
            'booking_date' => (string) ($workspaceState['currentBooking']['booking_date'] ?? ''),
        ];
        $responsibleCustomerId = (int) ($workspaceState['currentBooking']['lead_traveler_id'] ?? ($selectedTravelerProfile['id'] ?? 0));
        $customerPaymentService = new CustomerPaymentFoundationService($this->app);
        $customerPreviousBalanceDirectory = $customerPaymentService->previousBalanceDirectory(
            array_column($travelerState['travelerSearchResults'], 'id'),
            $accessibleBranchIds,
            $bookingReference,
            $currentBookingContext
        );
        $travelerSearchResults = array_map(
            static function (array $travelerRow) use ($customerPreviousBalanceDirectory): array {
                $travelerId = (int) ($travelerRow['id'] ?? 0);
                $travelerRow['previous_balance_totals'] = $customerPreviousBalanceDirectory[$travelerId] ?? [];

                return $travelerRow;
            },
            $travelerState['travelerSearchResults']
        );
        $supplierFoundation = (new SupplierFoundationService($this->app))->buildWorkspacePreview($bookingReference);
        $customerPaymentFoundation = $customerPaymentService->buildWorkspacePreview(
            $bookingReference,
            $responsibleCustomerId > 0 ? $responsibleCustomerId : null,
            $accessibleBranchIds,
            $currentBookingContext
        );
        $recreateReceiptId = isset($_GET['recreate_receipt_id']) ? (int) $_GET['recreate_receipt_id'] : 0;
        $recreateSupplierPaymentId = isset($_GET['recreate_supplier_payment_id']) ? (int) $_GET['recreate_supplier_payment_id'] : 0;
        $receiptRecreateDraft = null;
        if ($recreateReceiptId > 0) {
            foreach (($customerPaymentFoundation['receipts'] ?? []) as $receiptRow) {
                if ((int) ($receiptRow['id'] ?? 0) !== $recreateReceiptId) {
                    continue;
                }

                if ((string) ($receiptRow['statusRaw'] ?? '') === 'void') {
                    $receiptRecreateDraft = $receiptRow;
                }

                break;
            }
        }
        $supplierPaymentRecreateDraft = null;
        if ($recreateSupplierPaymentId > 0) {
            foreach (($supplierFoundation['payments'] ?? []) as $paymentRow) {
                if ((int) ($paymentRow['id'] ?? 0) !== $recreateSupplierPaymentId) {
                    continue;
                }

                if ((string) ($paymentRow['statusRaw'] ?? '') === 'void') {
                    $supplierPaymentRecreateDraft = $paymentRow;
                }

                break;
            }
        }
        $accountingFoundation = (new AccountingFoundationService($this->app))->buildWorkspacePreview(
            $bookingReference !== '' ? $bookingReference : null,
            $this->serviceLinePreview($serviceState['services']),
            $supplierFoundation,
            $customerPaymentFoundation
        );
        $documentState = (new DocumentWorkspaceService($this->app))->documentState(
            $effectiveBookingId,
            $accessibleBranchIds,
            $travelerState['travelers'],
            $serviceState['services'],
            $customerPaymentFoundation,
            $supplierFoundation
        );
        $reminderState = (new ReminderWorkspaceService($this->app))->reminderState(
            $effectiveBookingId,
            $accessibleBranchIds,
            $workspaceState['currentBooking'],
            $travelerState['travelers'],
            $serviceState['services'],
            $customerPaymentFoundation,
            $supplierFoundation,
            $documentState['documents'],
            $editingReminderId,
            (int) Auth::id()
        );

        return $this->view('workspace/index', [
            'title' => 'Booking Workspace',
            'user' => Auth::user(),
            'accessibleBranchIds' => $accessibleBranchIds,
            'pageScript' => 'assets/js/workspace.js',
            'supplierFoundation' => $supplierFoundation,
            'customerPaymentFoundation' => $customerPaymentFoundation,
            'receiptRecreateDraft' => $receiptRecreateDraft,
            'supplierPaymentRecreateDraft' => $supplierPaymentRecreateDraft,
            'accountingFoundation' => $accountingFoundation,
            'branchOptions' => $workspaceState['branchOptions'],
            'bookingSearchResults' => $workspaceState['searchResults'],
            'currentBookingRecord' => $workspaceState['currentBooking'],
            'currentSearchTerm' => $workspaceState['searchTerm'],
            'workspaceIsNew' => $newMode || $workspaceState['currentBooking'] === null,
            'bookingTravelers' => $travelerState['travelers'],
            'selectedTravelerProfile' => $selectedTravelerProfile,
            'travelerSearchResults' => $travelerSearchResults,
            'travelerSearchTerm' => $travelerState['travelerSearchTerm'],
            'bookingServices' => $serviceState['services'],
            'serviceSupplierOptions' => $serviceState['supplierOptions'],
            'documents' => $documentState['documents'],
            'documentTypeOptions' => $documentState['documentTypeOptions'],
            'documentLinkTargets' => $documentState['documentLinkTargets'],
            'replaceableDocuments' => $documentState['replaceableDocuments'],
            'reminders' => $reminderState['reminders'],
            'receivableAlerts' => $reminderState['receivableAlerts'],
            'reminderTypeOptions' => $reminderState['reminderTypeOptions'],
            'reminderChannels' => $reminderState['reminderChannels'],
            'reminderLinkTargets' => $reminderState['reminderLinkTargets'],
            'editingReminder' => $reminderState['editingReminder'],
            'serviceSaveDebug' => is_array($serviceSaveDebug) ? $serviceSaveDebug : null,
        ]);
    }

    public function customerDuesFinder(): never
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $query = trim((string) ($_GET['q'] ?? ''));
        $travelerId = (int) ($_GET['traveler_id'] ?? 0);
        $currencyFilter = strtoupper(trim((string) ($_GET['currency'] ?? '')));

        $travelerRepository = new TravelerRepository($this->app);
        $paymentRepository = new \App\Repositories\CustomerPaymentRepository($this->app);
        $paymentFoundation = new CustomerPaymentFoundationService($this->app);

        $searchResults = $travelerRepository->searchTravelers($query, $accessibleBranchIds, 50);
        $travelerIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $searchResults
        ), static fn (int $id): bool => $id > 0)));
        if ($travelerId > 0 && ! in_array($travelerId, $travelerIds, true)) {
            $travelerIds[] = $travelerId;
        }

        $balanceDirectory = $paymentFoundation->previousBalanceDirectory($travelerIds, $accessibleBranchIds);
        $customers = [];
        foreach ($searchResults as $travelerRow) {
            $id = (int) ($travelerRow['id'] ?? 0);
            $openBalanceTotals = array_filter(
                $balanceDirectory[$id] ?? [],
                static fn (float $amount): bool => abs($amount) > 0.005
            );
            if ($openBalanceTotals === []) {
                continue;
            }

            $customers[] = [
                'id' => $id,
                'full_name' => (string) ($travelerRow['full_name'] ?? ''),
                'passport_number' => (string) ($travelerRow['passport_number'] ?? ''),
                'mobile' => (string) ($travelerRow['mobile'] ?? ''),
                'family_id' => (string) ($travelerRow['family_id'] ?? ''),
                'branch_name' => (string) ($travelerRow['branch_name'] ?? ''),
                'open_balance_totals' => $openBalanceTotals,
            ];
        }

        $selectedCustomer = null;
        $selectedTravelerId = 0;
        $selectionMessage = '';
        if ($travelerId > 0) {
            $travelerMatchesSearch = $query === '' || in_array($travelerId, array_column($customers, 'id'), true);
            if ($travelerMatchesSearch) {
                $selectedTravelerId = $travelerId;
            } else {
                $selectionMessage = 'Selected customer no longer matches the current search. Please choose View Dues again.';
            }
        }
        if ($selectedTravelerId > 0) {
            $traveler = $travelerRepository->travelerExistsInBranches($selectedTravelerId, $accessibleBranchIds)
                ? $travelerRepository->findTravelerById($selectedTravelerId)
                : null;
            if ($traveler !== null) {
                $selectedCustomerSearchRow = null;
                foreach ($customers as $customerRow) {
                    if ((int) ($customerRow['id'] ?? 0) === $selectedTravelerId) {
                        $selectedCustomerSearchRow = $customerRow;
                        break;
                    }
                }
                $selectedCustomer = [
                    'id' => (int) ($traveler['id'] ?? 0),
                    'full_name' => (string) ($traveler['full_name'] ?? ''),
                    'passport_number' => (string) ($traveler['passport_number'] ?? ''),
                    'mobile' => (string) ($traveler['mobile'] ?? ''),
                    'family_id' => (string) ($traveler['family_id'] ?? ''),
                    'branch_name' => (string) ($selectedCustomerSearchRow['branch_name'] ?? ''),
                    'open_balance_totals' => array_filter(
                        $balanceDirectory[$selectedTravelerId] ?? [],
                        static fn (float $amount): bool => abs($amount) > 0.005
                    ),
                ];
            }
        }

        $openInvoices = [];
        if ($selectedTravelerId > 0) {
            $rows = $paymentRepository->openReceivablesDetailedForLeadTraveler(
                $selectedTravelerId,
                $accessibleBranchIds,
                in_array($currencyFilter, ['PKR', 'AED', 'USD'], true) ? $currencyFilter : null
            );
            $openInvoices = array_map(
                static function (array $row): array {
                    return [
                        'receivable_id' => (int) ($row['id'] ?? 0),
                        'booking_id' => (int) ($row['booking_id'] ?? 0),
                        'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                        'booking_date' => (string) ($row['booking_date'] ?? ''),
                        'branch_name' => (string) ($row['branch_name'] ?? ''),
                        'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
                        'service_type' => ucwords((string) ($row['service_type'] ?? 'service')),
                        'passenger_name' => (string) ($row['passenger_name'] ?? ''),
                        'currency' => (string) ($row['currency'] ?? 'PKR'),
                        'due_amount' => round((float) ($row['due_amount'] ?? 0), 2),
                        'allocated_amount' => round((float) ($row['allocated_amount'] ?? 0), 2),
                        'outstanding_amount' => round((float) ($row['outstanding_amount'] ?? 0), 2),
                        'due_date' => (string) ($row['due_date'] ?? ''),
                        'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                        'open_url' => url('/workspace?booking_id=' . (int) ($row['booking_id'] ?? 0) . '&dues_receivable_id=' . (int) ($row['id'] ?? 0) . '#dock-panel-payments'),
                    ];
                },
                $rows
            );
        }

        $this->jsonResponse([
            'ok' => true,
            'query' => $query,
            'selected_traveler_id' => $selectedTravelerId,
            'currency_filter' => $currencyFilter,
            'customers' => $customers,
            'selected_customer' => $selectedCustomer,
            'open_invoices' => $openInvoices,
            'message' => $selectionMessage,
        ]);
    }

    public function supplierHistoryFinder(): never
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $query = trim((string) ($_GET['q'] ?? ''));

        $rows = (new \App\Repositories\SupplierRepository($this->app))
            ->supplierHistoryFinderResults($query, $accessibleBranchIds, 50);

        $results = array_map(
            static function (array $row): array {
                $bookingId = (int) ($row['booking_id'] ?? 0);
                $grossAmount = round((float) ($row['total_gross_amount'] ?? 0), 2);
                $paidAmount = round((float) ($row['total_paid_amount'] ?? 0), 2);
                $balanceAmount = round((float) ($row['total_balance_amount'] ?? 0), 2);
                $status = 'Recorded';

                if ($balanceAmount <= 0.005 && $paidAmount > 0.005) {
                    $status = 'Settled';
                } elseif ($balanceAmount > 0.005 && $paidAmount > 0.005) {
                    $status = 'Partially Paid';
                } elseif ($balanceAmount > 0.005) {
                    $status = 'Open';
                }

                return [
                    'booking_id' => $bookingId,
                    'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                    'booking_date' => (string) ($row['booking_date'] ?? ''),
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'supplier_id' => (int) ($row['supplier_id'] ?? 0),
                    'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                    'supplier_code' => (string) ($row['supplier_code'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'total_gross_amount' => $grossAmount,
                    'total_paid_amount' => $paidAmount,
                    'total_balance_amount' => $balanceAmount,
                    'due_date' => (string) ($row['due_date'] ?? ''),
                    'status' => $status,
                    'open_url' => $bookingId > 0
                        ? url('/workspace?booking_id=' . $bookingId . '#dock-panel-suppliers')
                        : '#',
                ];
            },
            $rows
        );

        $message = '';
        if ($query !== '') {
            $message = $results === []
                ? 'No supplier payment history matched this search.'
                : 'Select Open History to jump into the booking supplier payment workspace.';
        }

        $this->jsonResponse([
            'ok' => true,
            'query' => $query,
            'results' => $results,
            'message' => $message,
        ]);
    }

    public function registerSupplier(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $accessibleBranchIds = array_map('intval', Authorization::accessibleBranchIds());
            $name = trim((string) ($_POST['supplier_name'] ?? ''));
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $currency = strtoupper(trim((string) ($_POST['default_currency'] ?? 'PKR')));
            $mode = trim((string) ($_POST['supplier_mode'] ?? 'normal_payable'));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Enter supplier name.');
            }

            if (strlen($name) > 190) {
                throw new RuntimeException('Supplier name is too long.');
            }

            if ($branchId <= 0 || ! in_array($branchId, $accessibleBranchIds, true)) {
                throw new RuntimeException('Please select an accessible branch for this supplier.');
            }

            if (! in_array($currency, ['PKR', 'AED', 'USD'], true)) {
                throw new RuntimeException('Please select a valid supplier currency.');
            }

            if (! in_array($mode, ['normal_payable', 'running_balance'], true)) {
                throw new RuntimeException('Please select a valid supplier type.');
            }

            if (strlen($notes) > 4000) {
                throw new RuntimeException('Supplier notes are too long.');
            }

            $repository = new SupplierRepository($this->app);
            $supplier = $repository->findAccessibleSupplierByName($name, $accessibleBranchIds);
            $created = false;

            if ($supplier === null) {
                $supplierId = $repository->registerSupplier([
                    'branch_id' => $branchId,
                    'code' => $repository->nextSupplierCode(),
                    'name' => $name,
                    'supplier_mode' => $mode,
                    'default_currency' => $currency,
                    'notes' => $notes !== '' ? $notes : 'Created from booking workspace.',
                    'actor_user_id' => (int) Auth::id(),
                ]);
                $supplier = $repository->findSupplierById($supplierId);
                $created = true;
            }

            if ($supplier === null) {
                throw new RuntimeException('Supplier could not be loaded after saving.');
            }

            $this->jsonResponse([
                'ok' => true,
                'created' => $created,
                'supplier' => [
                    'id' => (int) ($supplier['id'] ?? 0),
                    'branch_id' => (int) ($supplier['branch_id'] ?? 0),
                    'code' => (string) ($supplier['code'] ?? ''),
                    'name' => (string) ($supplier['name'] ?? $name),
                    'supplier_mode' => (string) ($supplier['supplier_mode'] ?? $mode),
                    'default_currency' => (string) ($supplier['default_currency'] ?? $currency),
                ],
                'message' => $created ? 'Supplier added.' : 'Supplier already exists.',
            ]);
        } catch (RuntimeException $exception) {
            $this->jsonResponse([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function supplierAvailableAdvance(): never
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);
        $supplierName = trim((string) ($_GET['supplier_name'] ?? ''));
        $bookingId = (int) ($_GET['booking_id'] ?? 0);
        $branchId = (int) ($_GET['branch_id'] ?? 0);

        if (! in_array($currency, ['PKR', 'AED', 'USD'], true)) {
            $this->jsonResponse([
                'success' => true,
                'available' => false,
                'message' => 'Currency is required for supplier advance lookup.',
            ]);
        }

        $bookingRepository = new BookingRepository($this->app);
        if ($bookingId > 0) {
            if (! $bookingRepository->bookingExistsInBranches($bookingId, $accessibleBranchIds)) {
                $this->jsonResponse([
                    'success' => true,
                    'available' => false,
                    'message' => 'Booking is outside your accessible branches.',
                ], 403);
            }

            $booking = $bookingRepository->findBookingById($bookingId);
            $branchId = (int) ($booking['branch_id'] ?? 0);
        }

        if ($branchId <= 0 || ! in_array($branchId, $accessibleBranchIds, true)) {
            $this->jsonResponse([
                'success' => true,
                'available' => false,
                'message' => 'Branch is outside your accessible scope.',
            ], 403);
        }

        $supplierRepository = new \App\Repositories\SupplierRepository($this->app);
        $supplier = null;

        if ($supplierId > 0) {
            $supplier = $supplierRepository->findSupplierById($supplierId);
            if ($supplier !== null) {
                $supplierBranchId = isset($supplier['branch_id']) ? (int) $supplier['branch_id'] : 0;
                $supplierAccessible = $supplierBranchId === 0 || in_array($supplierBranchId, $accessibleBranchIds, true);
                if (! $supplierAccessible) {
                    $supplier = null;
                }
            }
        }

        if ($supplier === null && $supplierName !== '') {
            $supplier = $supplierRepository->findAccessibleSupplierByName($supplierName, $accessibleBranchIds);
        }

        if ($supplier === null) {
            $this->jsonResponse([
                'success' => true,
                'available' => false,
                'supplier_id' => 0,
                'supplier_name' => $supplierName,
                'branch_id' => $branchId,
                'currency' => $currency,
                'available_amount' => 0,
                'formatted_available_amount' => $currency . ' 0.00',
                'message' => 'Supplier not found or no available prepaid balance.',
            ]);
        }

        $supplierBranchId = isset($supplier['branch_id']) ? (int) $supplier['branch_id'] : 0;
        if ($supplierBranchId > 0 && $supplierBranchId !== $branchId) {
            $this->jsonResponse([
                'success' => true,
                'available' => false,
                'supplier_id' => (int) ($supplier['id'] ?? 0),
                'supplier_name' => (string) ($supplier['name'] ?? $supplierName),
                'branch_id' => $branchId,
                'currency' => $currency,
                'available_amount' => 0,
                'formatted_available_amount' => $currency . ' 0.00',
                'message' => 'No same-branch prepaid balance found.',
            ]);
        }

        $availableAmount = $supplierRepository->availableAdvanceBalanceForSupplier(
            (int) ($supplier['id'] ?? 0),
            $branchId,
            $currency
        );

        $this->jsonResponse([
            'success' => true,
            'available' => $availableAmount > 0.005,
            'supplier_id' => (int) ($supplier['id'] ?? 0),
            'supplier_name' => (string) ($supplier['name'] ?? $supplierName),
            'branch_id' => $branchId,
            'currency' => $currency,
            'available_amount' => $availableAmount,
            'formatted_available_amount' => $currency . ' ' . number_format($availableAmount, 2),
            'message' => $availableAmount > 0.005
                ? 'Available prepaid supplier balance found.'
                : 'No available prepaid supplier balance found.',
        ]);
    }

    public function save(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new BookingWorkspaceService($this->app))->saveBooking(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Booking ' . $result['action'] . ' successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking']['id']);
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());

            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $this->redirect('/workspace?booking_id=' . $bookingId);
            }

            $this->redirect('/workspace?new=1');
        }
    }

    public function autosaveInvoice(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $accessibleBranchIds = Authorization::accessibleBranchIds();
            $result = $this->saveAutosaveBooking(
                $_POST,
                (int) Auth::id(),
                $accessibleBranchIds
            );
            $booking = $result['booking'];
            $snapshot = $this->workspaceAutosaveSnapshot((int) ($booking['id'] ?? 0), $accessibleBranchIds);

            $this->jsonResponse([
                'ok' => true,
                'booking_id' => (int) ($booking['id'] ?? 0),
                'invoice_no' => (string) ($booking['booking_reference'] ?? ''),
                'lead_traveler_id' => (int) ($booking['lead_traveler_id'] ?? 0),
                'customer' => $this->workspaceAutosaveCustomer(
                    (int) ($booking['lead_traveler_id'] ?? 0),
                    $accessibleBranchIds,
                    is_array($snapshot['totals']['previous_balance_map'] ?? null) ? $snapshot['totals']['previous_balance_map'] : []
                ),
                'totals' => $snapshot['totals'],
                'customer_open_receivables' => $snapshot['customerOpenReceivables'],
                'daily_settlement_rates' => $snapshot['dailySettlementRates'],
                'message' => 'Invoice autosaved',
            ]);
        } catch (RuntimeException $exception) {
            $this->jsonResponse([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function autosaveService(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $traceId = $this->startSupplierAdvanceTrace('autosaveService', $_POST);

        try {
            $accessibleBranchIds = Authorization::accessibleBranchIds();
            if ((int) ($_POST['booking_id'] ?? 0) <= 0) {
                $bookingResult = $this->saveAutosaveBooking(
                    $this->bookingPayloadFromServiceRequest($_POST),
                    (int) Auth::id(),
                    $accessibleBranchIds
                );
                $_POST['booking_id'] = (string) (int) ($bookingResult['booking']['id'] ?? 0);
            }

            $result = (new ServiceWorkspaceService($this->app))->saveService(
                $_POST,
                (int) Auth::id(),
                $accessibleBranchIds
            );
            $savedServiceId = (int) (($result['service']['id'] ?? 0));
            $savedService = $savedServiceId > 0
                ? (new BookingServiceRepository($this->app))->findServiceById($savedServiceId)
                : null;
            $this->supplierAdvanceTraceLog('WorkspaceController::autosaveService', 'booking_service_save_result', [
                'trace_id' => $traceId,
                'booking_id' => (int) ($result['booking_id'] ?? ($_POST['booking_id'] ?? 0)),
                'booking_service_id' => $savedServiceId,
                'supplier_name' => (string) ($savedService['supplier_name_snapshot'] ?? $savedService['supplier_name'] ?? ($_POST['supplier_name'] ?? '')),
                'supplier_id' => isset($savedService['supplier_id']) ? (int) $savedService['supplier_id'] : null,
                'branch_id' => isset($savedService['branch_id']) ? (int) $savedService['branch_id'] : (int) ($_POST['branch_id'] ?? 0),
                'currency' => (string) ($savedService['currency'] ?? ($_POST['currency'] ?? '')),
                'purchase_cost' => isset($savedService['purchase_cost']) ? round((float) $savedService['purchase_cost'], 2) : round((float) ($_POST['purchase_cost'] ?? 0), 2),
                'sale_price' => isset($savedService['sale_price']) ? round((float) $savedService['sale_price'], 2) : round((float) ($_POST['sale_price'] ?? 0), 2),
                'action' => (string) ($result['action'] ?? ''),
                'reason' => (string) ($result['action'] ?? '') === 'created' ? 'new_service' : 'updated_service',
            ]);
            $snapshot = $this->workspaceAutosaveSnapshot((int) $result['booking_id'], $accessibleBranchIds);
            $savedService = $snapshot['serviceDirectory'][(int) (($result['service']['id'] ?? 0))] ?? null;
            $paymentEligible = $savedService !== null
                && (int) ($savedService['serviceId'] ?? 0) > 0
                && (
                    (float) ($savedService['finalSalePrice'] ?? 0) > 0
                    || (float) ($snapshot['totals']['total_receivable'] ?? 0) > 0
                );

            $this->jsonResponse([
                'ok' => true,
                'booking_id' => (int) $result['booking_id'],
                'invoice_no' => $snapshot['invoiceNo'],
                'service_id' => (int) (($savedService['serviceId'] ?? 0)),
                'service_line' => $savedService,
                'payment_eligible' => $paymentEligible,
                'customer' => $this->workspaceAutosaveCustomer(
                    (int) ($snapshot['booking']['lead_traveler_id'] ?? 0),
                    $accessibleBranchIds,
                    is_array($snapshot['totals']['previous_balance_map'] ?? null) ? $snapshot['totals']['previous_balance_map'] : []
                ),
                'totals' => $snapshot['totals'],
                'customer_open_receivables' => $snapshot['customerOpenReceivables'],
                'daily_settlement_rates' => $snapshot['dailySettlementRates'],
                'message' => 'Service autosaved',
            ]);
        } catch (RuntimeException $exception) {
            $this->jsonResponse([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function saveTraveler(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        try {
            $result = (new TravelerWorkspaceService($this->app))->saveTraveler(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            if ($isAjax) {
                $this->jsonResponse([
                    'ok' => true,
                    'traveler' => $result['traveler'] ?? null,
                    'action' => (string) ($result['action'] ?? 'saved'),
                    'booking_id' => (int) ($result['booking_id'] ?? 0),
                    'message' => 'Customer ' . (string) ($result['action'] ?? 'saved') . '.',
                ]);
            }

            Flash::success('Traveler ' . $result['action'] . ' successfully.');
            if ((int) $result['booking_id'] > 0) {
                $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-travelers');
            }

            $travelerId = (int) ($result['traveler']['id'] ?? 0);
            $suffix = $travelerId > 0 ? '&customer_id=' . $travelerId : '';
            $this->redirect('/workspace?new=1' . $suffix);
        } catch (RuntimeException $exception) {
            if ($isAjax) {
                $this->jsonResponse([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            Flash::error($exception->getMessage());
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-travelers');
            }

            $travelerId = (int) ($_POST['traveler_id'] ?? 0);
            $suffix = $travelerId > 0 ? '&customer_id=' . $travelerId : '';
            $this->redirect('/workspace?new=1' . $suffix);
        }
    }

    public function attachTraveler(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new TravelerWorkspaceService($this->app))->attachExistingTraveler(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Traveler attached successfully.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '&traveler_q=' . rawurlencode((string) ($_POST['traveler_search_term'] ?? '')) . '#dock-panel-travelers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '&traveler_q=' . rawurlencode((string) ($_POST['traveler_search_term'] ?? '')) . '#dock-panel-travelers');
        }
    }

    public function removeTraveler(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new TravelerWorkspaceService($this->app))->removeTraveler(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Traveler removed from booking.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-travelers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-travelers');
        }
    }

    public function saveService(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $traceId = $this->startSupplierAdvanceTrace('saveService', $_POST);

        try {
            $accessibleBranchIds = Authorization::accessibleBranchIds();
            $serviceDebug = [
                'posted' => $this->serviceSavePostedDebugFields($_POST),
            ];
            if ((int) ($_POST['booking_id'] ?? 0) <= 0) {
                $bookingPayload = $this->bookingPayloadFromServiceRequest($_POST);
                $serviceDebug['bookingPayloadFromServiceRequest'] = $bookingPayload;
                $bookingResult = (new BookingWorkspaceService($this->app))->saveBooking(
                    $bookingPayload,
                    (int) Auth::id(),
                    $accessibleBranchIds
                );
                $_POST['booking_id'] = (string) (int) ($bookingResult['booking']['id'] ?? 0);
            }

            $serviceWorkspaceService = new ServiceWorkspaceService($this->app);
            $result = $serviceWorkspaceService->saveService(
                $_POST,
                (int) Auth::id(),
                $accessibleBranchIds
            );
            $savedServiceId = (int) (($result['service']['id'] ?? 0));
            $savedService = $savedServiceId > 0
                ? (new BookingServiceRepository($this->app))->findServiceById($savedServiceId)
                : null;
            $this->supplierAdvanceTraceLog('WorkspaceController::saveService', 'booking_service_save_result', [
                'trace_id' => $traceId,
                'booking_id' => (int) ($result['booking_id'] ?? ($_POST['booking_id'] ?? 0)),
                'booking_service_id' => $savedServiceId,
                'supplier_name' => (string) ($savedService['supplier_name_snapshot'] ?? $savedService['supplier_name'] ?? ($_POST['supplier_name'] ?? '')),
                'supplier_id' => isset($savedService['supplier_id']) ? (int) $savedService['supplier_id'] : null,
                'branch_id' => isset($savedService['branch_id']) ? (int) $savedService['branch_id'] : (int) ($_POST['branch_id'] ?? 0),
                'currency' => (string) ($savedService['currency'] ?? ($_POST['currency'] ?? '')),
                'purchase_cost' => isset($savedService['purchase_cost']) ? round((float) $savedService['purchase_cost'], 2) : round((float) ($_POST['purchase_cost'] ?? 0), 2),
                'sale_price' => isset($savedService['sale_price']) ? round((float) $savedService['sale_price'], 2) : round((float) ($_POST['sale_price'] ?? 0), 2),
                'action' => (string) ($result['action'] ?? ''),
                'reason' => (string) ($result['action'] ?? '') === 'created' ? 'new_service' : 'updated_service',
            ]);
            $serviceDebug['serviceResult'] = $result['debug'] ?? [];

            $bookingRepository = new BookingRepository($this->app);
            $savedBooking = $bookingRepository->findBookingById((int) $result['booking_id']);
            if (is_array($savedBooking)) {
                $serviceDebug['savedBooking'] = [
                    'id' => (int) ($savedBooking['id'] ?? 0),
                    'booking_reference' => (string) ($savedBooking['booking_reference'] ?? ''),
                    'lead_traveler_id' => (int) ($savedBooking['lead_traveler_id'] ?? 0),
                    'lead_traveler_name' => (string) ($savedBooking['lead_traveler_name'] ?? ''),
                    'party_label' => (string) ($savedBooking['party_label'] ?? ''),
                ];

                $customerPaymentPreview = (new CustomerPaymentFoundationService($this->app))->buildWorkspacePreview(
                    (string) ($savedBooking['booking_reference'] ?? ''),
                    (int) ($savedBooking['lead_traveler_id'] ?? 0) > 0 ? (int) $savedBooking['lead_traveler_id'] : null,
                    $accessibleBranchIds,
                    [
                        'booking_id' => (int) ($savedBooking['id'] ?? 0),
                        'booking_reference' => (string) ($savedBooking['booking_reference'] ?? ''),
                        'booking_date' => (string) ($savedBooking['booking_date'] ?? ''),
                    ]
                );
                $serviceDebug['customerPaymentPreview'] = [
                    'summary' => $customerPaymentPreview['summary'] ?? [],
                    'serviceReceivables' => $customerPaymentPreview['serviceReceivables'] ?? [],
                    'openReceivables' => $customerPaymentPreview['openReceivables'] ?? [],
                ];
            }

            Session::put('_service_save_debug', $serviceDebug);
            Flash::success(((string) $result['action'] === 'created' ? 'Service line saved.' : 'Service line updated.'));
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-services');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-services');
        }
    }

    public function deactivateService(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new ServiceWorkspaceService($this->app))->deactivateService(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Service line deactivated.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-services');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-services');
        }
    }

    public function cancelService(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new ServiceWorkspaceService($this->app))->cancelService(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Service cancellation recorded. Financial refund or penalty posting still needs the refund workflow.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-services');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-services');
        }
    }

    public function refundService(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new ServiceWorkspaceService($this->app))->refundService(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Service refund posted successfully.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-services');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-services');
        }
    }

    public function settleCancellationFinancials(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new ServiceWorkspaceService($this->app))->settleCancellationFinancials(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Cancellation financial adjustment posted successfully.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-services');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-services');
        }
    }

    public function reissueService(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $bookingId = (new ServiceWorkspaceService($this->app))->reissueService(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Service reissue recorded successfully.');
            $this->redirect('/workspace?booking_id=' . $bookingId . '#dock-panel-services');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-services');
        }
    }

    public function saveReceipt(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->saveReceipt(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            $receiptId = (int) ($result['receipt']['id'] ?? 0);
            if ($receiptId <= 0) {
                throw new RuntimeException('The receipt was saved but could not be reopened for printing.');
            }

             if ($isAjax) {
                $accessibleBranchIds = Authorization::accessibleBranchIds();
                $snapshot = $this->workspaceAutosaveSnapshot((int) $result['booking_id'], $accessibleBranchIds);
                $booking = $snapshot['booking'];
                $this->jsonResponse([
                    'ok' => true,
                    'booking_id' => (int) $result['booking_id'],
                    'receipt_id' => $receiptId,
                    'invoice_no' => $snapshot['invoiceNo'],
                    'customer' => $this->workspaceAutosaveCustomer(
                        (int) ($booking['lead_traveler_id'] ?? 0),
                        $accessibleBranchIds,
                        is_array($snapshot['totals']['previous_balance_map'] ?? null) ? $snapshot['totals']['previous_balance_map'] : []
                    ),
                    'totals' => $snapshot['totals'],
                    'customer_open_receivables' => $snapshot['customerOpenReceivables'],
                    'receipts' => $snapshot['receipts'],
                    'allocations' => $snapshot['allocations'],
                    'daily_settlement_rates' => $snapshot['dailySettlementRates'],
                    'message' => 'Customer receipt recorded successfully.',
                ]);
            }

            if ((string) ($_POST['receipt_action'] ?? 'save') === 'print') {
                $this->redirect('/workspace/output?booking_id=' . (int) $result['booking_id'] . '&doc=customer_receipt&receipt_id=' . $receiptId);
            }

            Flash::success('Customer receipt recorded successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-payments');
        } catch (RuntimeException $exception) {
            if ($isAjax) {
                $this->jsonResponse([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-payments');
        }
    }

    public function allocateReceipt(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->allocateReceipt(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success((int) $result['allocation_count'] . ' allocation(s) posted successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-payments');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-payments');
        }
    }

    public function voidReceipt(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->voidReceipt(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Customer receipt voided successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-payments');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-payments');
        }
    }

    public function updateReceiptMetadata(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->updateReceiptMetadata(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Customer receipt notes updated successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-payments');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-payments');
        }
    }

    public function saveSupplierPayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->recordSupplierPayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Supplier payment recorded successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function saveSimplePostpaidSupplierPayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->recordSimplePostpaidSupplierPayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            $paymentLabel = (int) ($result['payment_count'] ?? 1) > 1 ? 'Supplier payments' : 'Supplier payment';
            Flash::success(
                $paymentLabel . ' saved and allocated to selected payable(s). Allocated '
                . (string) ($result['currency'] ?? 'PKR')
                . ' '
                . number_format((float) ($result['allocated_amount'] ?? 0), 2)
                . ' to '
                . (int) ($result['allocation_count'] ?? 0)
                . ' payable item(s).'
            );
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function updateSupplierPaymentMetadata(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->updateSupplierPaymentMetadata(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Supplier payment notes updated successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function allocateSupplierPayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->allocateSupplierPayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success((int) $result['allocation_count'] . ' supplier allocation(s) posted successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function voidSupplierPayment(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->voidSupplierPayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Supplier payment voided successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function saveSupplierAdvance(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->recordSupplierAdvance(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Supplier advance recorded successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function saveGlobalSupplierAdvance(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->recordGlobalSupplierAdvance(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );

            $message = 'Prepaid supplier payment recorded successfully. Recorded '
                . (string) ($result['currency'] ?? 'PKR')
                . ' '
                . number_format((float) ($result['amount'] ?? 0), 2)
                . ' advance for '
                . (string) ($result['supplier_name'] ?? 'Supplier')
                . '.';

            if ($isAjax) {
                $this->jsonResponse([
                    'ok' => true,
                    'message' => $message,
                    'supplier_name' => (string) ($result['supplier_name'] ?? ''),
                    'currency' => (string) ($result['currency'] ?? 'PKR'),
                    'amount' => round((float) ($result['amount'] ?? 0), 2),
                ]);
            }

            Flash::success($message);
            $this->redirect('/workspace');
        } catch (\RuntimeException $exception) {
            if ($isAjax) {
                $this->jsonResponse([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            Flash::error($exception->getMessage());
            $this->redirect('/workspace');
        }
    }
    public function applySupplierAdvance(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->applySupplierAdvance(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Supplier advance applied successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-suppliers');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-suppliers');
        }
    }

    public function uploadDocument(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new DocumentWorkspaceService($this->app))->uploadDocument(
                $_POST,
                $_FILES,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Document uploaded successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-documents');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-documents');
        }
    }

    public function revokeDocument(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new DocumentWorkspaceService($this->app))->revokeDocument(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Document revoked successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-documents');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-documents');
        }
    }

    public function downloadDocument(): never
    {
        try {
            $download = (new DocumentWorkspaceService($this->app))->documentDownload(
                (int) ($_GET['document_id'] ?? 0),
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $download['mime_type']);
        header('Content-Disposition: attachment; filename="' . rawurlencode($download['download_name']) . '"');
        header('Content-Length: ' . (string) $download['size']);
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        readfile($download['absolute_path']);
        exit;
    }

    public function saveReminder(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new ReminderWorkspaceService($this->app))->saveReminder(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Reminder ' . $result['action'] . ' successfully.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-reminders');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $reminderId = (int) ($_POST['reminder_id'] ?? 0);
            $suffix = $reminderId > 0 ? '&reminder_edit=' . $reminderId : '';
            $this->redirect('/workspace?booking_id=' . $bookingId . $suffix . '#dock-panel-reminders');
        }
    }

    public function completeReminder(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new ReminderWorkspaceService($this->app))->completeReminder(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Reminder completed.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-reminders');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-reminders');
        }
    }

    public function dismissReminder(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new ReminderWorkspaceService($this->app))->dismissReminder(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success('Reminder dismissed.');
            $this->redirect('/workspace?booking_id=' . (int) $result['booking_id'] . '#dock-panel-reminders');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/workspace?booking_id=' . (int) ($_POST['booking_id'] ?? 0) . '#dock-panel-reminders');
        }
    }

    public function output(): string
    {
        $bookingId = (int) ($_GET['booking_id'] ?? 0);
        $outputType = (string) ($_GET['doc'] ?? 'invoice');
        $receiptId = isset($_GET['receipt_id']) ? (int) $_GET['receipt_id'] : null;
        $supplierPaymentId = isset($_GET['supplier_payment_id']) ? (int) $_GET['supplier_payment_id'] : null;
        $refundEventId = isset($_GET['refund_event_id']) ? (int) $_GET['refund_event_id'] : null;

        try {
            $document = (new OperationalOutputService($this->app))->buildOutputDocument(
                $bookingId,
                $outputType,
                Authorization::accessibleBranchIds(),
                $receiptId,
                $supplierPaymentId,
                $refundEventId,
                (int) Auth::id()
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $suffix = $bookingId > 0 ? '?booking_id=' . $bookingId . '#dock-panel-print' : '';
            $this->redirect('/workspace' . $suffix);
        }

        return $this->view('workspace/output', $document, 'layouts/print');
    }

    private function serviceLinePreview(array $services): array
    {
        if ($services === []) {
            return [];
        }

        return array_map(static function (array $service): array {
            return [
                'lineNumber' => (string) ($service['line_reference'] ?? 'SV-DRAFT'),
                'currency' => (string) ($service['currency'] ?? 'PKR'),
                'salePrice' => (float) ($service['sale_price'] ?? 0),
                'purchaseCost' => (float) ($service['purchase_cost'] ?? 0),
                'taxes' => (float) ($service['taxes'] ?? 0),
                'vat' => (float) ($service['vat'] ?? 0),
                'commission' => (float) ($service['commission'] ?? 0),
                'serviceCharge' => (float) ($service['service_charge'] ?? 0),
            ];
        }, $services);
    }

    private function bookingPayloadFromServiceRequest(array $input): array
    {
        $selectedCustomerId = (int) ($input['auto_selected_customer_id'] ?? 0);
        $partyLabel = trim((string) ($input['auto_party_label'] ?? 'Customer'));
        $leadTravelerName = trim((string) ($input['auto_lead_traveler_name'] ?? ''));
        $contactMobile = trim((string) ($input['auto_contact_mobile'] ?? ''));
        $passportNumber = trim((string) ($input['auto_passport_number'] ?? ''));
        if ($leadTravelerName === '' && $selectedCustomerId > 0) {
            $selectedTraveler = (new TravelerWorkspaceService($this->app))->travelerProfile(
                $selectedCustomerId,
                Authorization::accessibleBranchIds()
            );
            if ($selectedTraveler !== null) {
                $leadTravelerName = trim((string) ($selectedTraveler['full_name'] ?? ''));
                $contactMobile = trim((string) (($selectedTraveler['mobile'] ?? '') !== '' ? $selectedTraveler['mobile'] : $contactMobile));
                $passportNumber = trim((string) (($selectedTraveler['passport_number'] ?? '') !== '' ? $selectedTraveler['passport_number'] : $passportNumber));
            }
        } elseif ($selectedCustomerId > 0) {
            $selectedTraveler = (new TravelerWorkspaceService($this->app))->travelerProfile(
                $selectedCustomerId,
                Authorization::accessibleBranchIds()
            );
            if ($selectedTraveler !== null) {
                $leadTravelerName = trim((string) (($selectedTraveler['full_name'] ?? '') !== '' ? $selectedTraveler['full_name'] : $leadTravelerName));
                $contactMobile = trim((string) (($selectedTraveler['mobile'] ?? '') !== '' ? $selectedTraveler['mobile'] : $contactMobile));
                $passportNumber = trim((string) (($selectedTraveler['passport_number'] ?? '') !== '' ? $selectedTraveler['passport_number'] : $passportNumber));
            }
        }
        if ($leadTravelerName === '' && $partyLabel !== '') {
            $leadTravelerName = $partyLabel;
        }

        return [
            'booking_id' => 0,
            'branch_id' => (int) ($input['auto_branch_id'] ?? 0),
            'selected_customer_id' => $selectedCustomerId,
            'booking_date' => (string) ($input['auto_booking_date'] ?? date('Y-m-d')),
            'due_date' => (string) ($input['auto_due_date'] ?? ''),
            'booking_status' => (string) ($input['auto_booking_status'] ?? 'draft'),
            'party_label' => $partyLabel,
            'lead_traveler_name' => $leadTravelerName,
            'contact_mobile' => $contactMobile,
            'passport_number' => $passportNumber,
            'departure_date' => (string) ($input['auto_departure_date'] ?? ''),
            'return_date' => (string) ($input['auto_return_date'] ?? ''),
            'party_notes' => (string) ($input['auto_party_notes'] ?? ''),
            'remarks' => (string) ($input['auto_booking_remarks'] ?? ''),
        ];
    }

    private function saveAutosaveBooking(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = false;

        if (! $db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $preparedInput = $this->prepareAutosaveBookingInput($input, $actorUserId, $accessibleBranchIds);
            $result = (new BookingWorkspaceService($this->app))->saveBooking(
                $preparedInput,
                $actorUserId,
                $accessibleBranchIds
            );

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Invoice autosave failed unexpectedly.', 0, $exception);
        }
    }

    private function prepareAutosaveBookingInput(array $input, int $actorUserId, array $accessibleBranchIds): array
    {
        $normalized = $input;
        if (! array_key_exists('selected_customer_id', $normalized) && array_key_exists('selected_traveler_id', $normalized)) {
            $normalized['selected_customer_id'] = $normalized['selected_traveler_id'];
        }

        $selectedTravelerId = (int) ($normalized['selected_customer_id'] ?? 0);
        $leadTravelerName = trim((string) ($normalized['lead_traveler_name'] ?? ''));
        if ($selectedTravelerId > 0 || $leadTravelerName === '') {
            return $normalized;
        }

        $traveler = (new TravelerWorkspaceService($this->app))->resolveAutosaveCustomer(
            $normalized,
            $actorUserId,
            $accessibleBranchIds
        );

        $normalized['selected_customer_id'] = (string) (int) ($traveler['id'] ?? 0);
        $normalized['lead_traveler_name'] = (string) ($traveler['full_name'] ?? $leadTravelerName);
        if (trim((string) ($normalized['contact_mobile'] ?? '')) === '' && trim((string) ($traveler['mobile'] ?? '')) !== '') {
            $normalized['contact_mobile'] = (string) $traveler['mobile'];
        }
        if (trim((string) ($normalized['passport_number'] ?? '')) === '' && trim((string) ($traveler['passport_number'] ?? '')) !== '') {
            $normalized['passport_number'] = (string) $traveler['passport_number'];
        }

        return $normalized;
    }

    private function serviceSavePostedDebugFields(array $input): array
    {
        $fields = [
            'booking_id',
            'service_id',
            'service_type',
            'currency',
            'supplier_name',
            'service_passenger_name',
            'service_traveler_id',
            'sale_price',
            'purchase_cost',
            'taxes',
            'other_fare',
            'soto_fare',
            'spyi_amount',
            'aq_yr_pk_amount',
            'yq_amount',
            'oth_amount',
            'vat_input',
            'vat',
            'commission',
            'service_charge',
            'discount_amount',
            'final_sale_price',
            'due_date',
            'service_status',
            'remarks',
            'loss_reason',
            'auto_selected_customer_id',
            'auto_lead_traveler_name',
            'auto_party_label',
            'auto_contact_mobile',
            'auto_passport_number',
        ];

        $debug = [];
        foreach ($fields as $field) {
            $debug[$field] = $input[$field] ?? null;
        }

        return $debug;
    }

    private function workspaceAutosaveSnapshot(int $bookingId, array $accessibleBranchIds): array
    {
        $bookingRepository = new BookingRepository($this->app);
        $booking = $bookingRepository->findBookingById($bookingId);
        if ($booking === null) {
            throw new RuntimeException('The saved booking could not be reloaded.');
        }

        $services = (new BookingServiceRepository($this->app))->servicesForBooking($bookingId);
        $customerPaymentFoundation = (new CustomerPaymentFoundationService($this->app))->buildWorkspacePreview(
            (string) ($booking['booking_reference'] ?? ''),
            (int) ($booking['lead_traveler_id'] ?? 0) > 0 ? (int) $booking['lead_traveler_id'] : null,
            $accessibleBranchIds,
            [
                'booking_id' => (int) ($booking['id'] ?? 0),
                'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
                'booking_date' => (string) ($booking['booking_date'] ?? ''),
            ]
        );
        $supplierFoundation = (new SupplierFoundationService($this->app))->buildWorkspacePreview(
            (string) ($booking['booking_reference'] ?? '')
        );

        $totals = $this->workspaceAutosaveTotals($services, $customerPaymentFoundation, $supplierFoundation);
        $serviceDirectory = [];
        foreach ($services as $serviceRow) {
            $normalized = $this->normalizeServiceLineForAutosave($serviceRow);
            $serviceDirectory[(int) $normalized['serviceId']] = $normalized;
        }

        return [
            'booking' => $booking,
            'invoiceNo' => (string) ($booking['booking_reference'] ?? ''),
            'serviceDirectory' => $serviceDirectory,
            'totals' => $totals,
            'receipts' => is_array($customerPaymentFoundation['receipts'] ?? null)
                ? $customerPaymentFoundation['receipts']
                : [],
            'allocations' => is_array($customerPaymentFoundation['allocations'] ?? null)
                ? $customerPaymentFoundation['allocations']
                : [],
            'customerOpenReceivables' => is_array($customerPaymentFoundation['customerOpenReceivables'] ?? null)
                ? $customerPaymentFoundation['customerOpenReceivables']
                : [],
            'dailySettlementRates' => is_array($customerPaymentFoundation['dailySettlementRates'] ?? null)
                ? $customerPaymentFoundation['dailySettlementRates']
                : [],
        ];
    }

    private function workspaceAutosaveTotals(
        array $services,
        array $customerPaymentFoundation,
        array $supplierFoundation
    ): array {
        $activeServices = array_values(array_filter(
            $services,
            static fn (array $row): bool => (int) ($row['is_active'] ?? 1) === 1
        ));

        $totalFare = 0.0;
        $totalTaxes = 0.0;
        $totalOther = 0.0;
        $totalReceivable = 0.0;
        $totalPayable = 0.0;
        $profitLoss = 0.0;

        foreach ($activeServices as $serviceRow) {
            $serviceTaxTotal = (float) ($serviceRow['spyi_amount'] ?? 0)
                + (float) ($serviceRow['aq_yr_pk_amount'] ?? 0)
                + (float) ($serviceRow['yq_amount'] ?? 0)
                + (float) ($serviceRow['oth_amount'] ?? 0)
                + (float) ($serviceRow['vat_input'] ?? 0)
                + (float) ($serviceRow['taxes'] ?? 0);
            $finalSalePrice = array_key_exists('final_sale_price', $serviceRow) && $serviceRow['final_sale_price'] !== null
                ? (float) $serviceRow['final_sale_price']
                : round(
                    ((string) ($serviceRow['service_type'] ?? 'air ticket') === 'air ticket'
                        ? (float) ($serviceRow['purchase_cost'] ?? 0)
                        : (float) ($serviceRow['sale_price'] ?? 0))
                    + (float) ($serviceRow['service_charge'] ?? 0)
                    + (float) ($serviceRow['vat'] ?? 0)
                    - (float) ($serviceRow['discount_amount'] ?? 0),
                    2
                );

            $payableAmount = (float) ($serviceRow['purchase_cost'] ?? 0);
            $receivableAmount = $finalSalePrice;

            $totalFare += (float) (($serviceRow['fare'] ?? 0) !== null && (float) ($serviceRow['fare'] ?? 0) > 0
                ? $serviceRow['fare']
                : ($serviceRow['sale_price'] ?? 0));
            $totalTaxes += $serviceTaxTotal;
            $totalOther += (float) ($serviceRow['service_charge'] ?? 0);
            $totalReceivable += $receivableAmount;
            $totalPayable += $payableAmount;
            $profitLoss += $receivableAmount - $payableAmount;
        }

        $summary = is_array($customerPaymentFoundation['summary'] ?? null)
            ? $customerPaymentFoundation['summary']
            : [];
        $invoiceOutstandingMap = is_array($summary['invoiceOutstanding'] ?? null)
            ? $summary['invoiceOutstanding']
            : [];
        $fullCustomerOutstandingMap = is_array($summary['fullCustomerOutstanding'] ?? null)
            ? $summary['fullCustomerOutstanding']
            : [];
        $previousBalanceMap = is_array($summary['previousBalance'] ?? null)
            ? $summary['previousBalance']
            : [];
        $invoiceReceivedMap = is_array($summary['invoiceReceived'] ?? null)
            ? $summary['invoiceReceived']
            : [];
        $invoiceCurrency = '';
        foreach ($activeServices as $serviceRow) {
            $candidateCurrency = trim((string) ($serviceRow['currency'] ?? ''));
            if ($candidateCurrency !== '') {
                $invoiceCurrency = $candidateCurrency;
                break;
            }
        }
        if ($invoiceCurrency === '' && $invoiceOutstandingMap !== []) {
            $invoiceCurrency = (string) array_key_first($invoiceOutstandingMap);
        }
        if ($invoiceCurrency === '' && is_array($summary['invoiceReceivable'] ?? null) && $summary['invoiceReceivable'] !== []) {
            $invoiceCurrency = (string) array_key_first($summary['invoiceReceivable']);
        }
        if ($invoiceCurrency === '' && $invoiceReceivedMap !== []) {
            $invoiceCurrency = (string) array_key_first($invoiceReceivedMap);
        }
        if ($invoiceCurrency === '') {
            $invoiceCurrency = 'PKR';
        }

        $invoiceOutstanding = (float) ($invoiceOutstandingMap[$invoiceCurrency] ?? 0);
        $previousBalance = (float) ($previousBalanceMap[$invoiceCurrency] ?? 0);
        $invoiceReceived = (float) ($invoiceReceivedMap[$invoiceCurrency] ?? 0);
        $invoiceOutstandingPkrRate = is_numeric($summary['invoiceOutstandingPkrRate'] ?? null)
            ? (float) $summary['invoiceOutstandingPkrRate']
            : null;
        $invoiceOutstandingPkrEquivalent = is_numeric($summary['invoiceOutstandingPkrEquivalent'] ?? null)
            ? (float) $summary['invoiceOutstandingPkrEquivalent']
            : null;
        $otherCurrencyPreviousBalanceMap = array_filter(
            $previousBalanceMap,
            static fn (float $amount, string $currency): bool => $currency !== $invoiceCurrency && abs($amount) > 0.005,
            ARRAY_FILTER_USE_BOTH
        );
        $supplierOutstanding = array_reduce(
            $supplierFoundation['obligations'] ?? [],
            static fn (float $carry, array $row): float => $carry + (float) ($row['netPayableAmount'] ?? 0),
            0.0
        );

        return [
            'airline_payable' => round($totalPayable, 2),
            'receivable_client' => round($totalReceivable, 2),
            'other_payable' => 0.0,
            'profit_loss' => round($profitLoss, 2),
            'total_fare' => round($totalFare, 2),
            'total_taxes' => round($totalTaxes, 2),
            'total_other' => round($totalOther, 2),
            'total_receivable' => round($totalReceivable, 2),
            'total_payable' => round($totalPayable, 2),
            'current_invoice_currency' => $invoiceCurrency,
            'full_customer_outstanding_map' => $fullCustomerOutstandingMap,
            'previous_balance_map' => $previousBalanceMap,
            'other_currency_previous_balance_map' => $otherCurrencyPreviousBalanceMap,
            'same_currency_previous_balance' => round($previousBalance, 2),
            'current_invoice_balance' => round($invoiceOutstanding, 2),
            'total_outstanding' => round($previousBalance + $invoiceOutstanding, 2),
            'total_received' => round($invoiceReceived, 2),
            'current_invoice_balance_pkr_rate' => $invoiceOutstandingPkrRate,
            'current_invoice_balance_pkr_equivalent' => $invoiceOutstandingPkrEquivalent,
            'supplier_outstanding' => round($supplierOutstanding, 2),
            'has_saved_service' => $activeServices !== [],
        ];
    }

    private function normalizeServiceLineForAutosave(array $serviceRow): array
    {
        $isAirTicket = (string) ($serviceRow['service_type'] ?? 'air ticket') === 'air ticket';
        $derivedFinalSalePrice = $isAirTicket
            ? (float) ($serviceRow['purchase_cost'] ?? 0) + (float) ($serviceRow['service_charge'] ?? 0) + (float) ($serviceRow['vat'] ?? 0) - (float) ($serviceRow['discount_amount'] ?? 0)
            : (float) ($serviceRow['sale_price'] ?? 0) + (float) ($serviceRow['service_charge'] ?? 0) + (float) ($serviceRow['vat'] ?? 0) - (float) ($serviceRow['discount_amount'] ?? 0);
        $finalSalePrice = array_key_exists('final_sale_price', $serviceRow) && $serviceRow['final_sale_price'] !== null
            ? (float) $serviceRow['final_sale_price']
            : $derivedFinalSalePrice;
        $profit = array_key_exists('net_profit_loss', $serviceRow)
            ? (float) ($serviceRow['net_profit_loss'] ?? 0)
            : round($finalSalePrice - (float) ($serviceRow['purchase_cost'] ?? 0), 2);

        return [
            'serviceId' => (int) ($serviceRow['id'] ?? 0),
            'lineNumber' => (string) ($serviceRow['line_reference'] ?? 'SV-DRAFT'),
            'type' => (string) ($serviceRow['service_type'] ?? 'air ticket'),
            'supplierId' => (int) ($serviceRow['supplier_id'] ?? 0),
            'supplier' => (string) ($serviceRow['supplier_name'] ?? $serviceRow['supplier_name_snapshot'] ?? 'Supplier not selected'),
            'travelerId' => (int) ($serviceRow['traveler_id'] ?? 0),
            'passengerName' => (string) ($serviceRow['passenger_name'] ?? $serviceRow['passenger_name_snapshot'] ?? ''),
            'currency' => (string) ($serviceRow['currency'] ?? 'PKR'),
            'salePrice' => (float) ($serviceRow['sale_price'] ?? 0),
            'purchaseCost' => (float) ($serviceRow['purchase_cost'] ?? 0),
            'taxes' => (float) ($serviceRow['taxes'] ?? 0),
            'otherFare' => (float) ($serviceRow['other_fare'] ?? 0),
            'sotoFare' => (float) ($serviceRow['soto_fare'] ?? 0),
            'spyiAmount' => (float) ($serviceRow['spyi_amount'] ?? 0),
            'aqYrPkAmount' => (float) ($serviceRow['aq_yr_pk_amount'] ?? 0),
            'yqAmount' => (float) ($serviceRow['yq_amount'] ?? 0),
            'othAmount' => (float) ($serviceRow['oth_amount'] ?? 0),
            'vatInput' => (float) ($serviceRow['vat_input'] ?? 0),
            'vat' => (float) ($serviceRow['vat'] ?? 0),
            'commission' => (float) ($serviceRow['commission'] ?? 0),
            'serviceCharge' => (float) ($serviceRow['service_charge'] ?? 0),
            'discountAmount' => (float) ($serviceRow['discount_amount'] ?? 0),
            'finalSalePrice' => $finalSalePrice,
            'netProfitLoss' => $profit,
            'dueDate' => (string) ($serviceRow['due_date'] ?? ''),
            'status' => (string) ($serviceRow['service_status'] ?? 'Open'),
            'displayStatus' => (string) (($serviceRow['is_active'] ?? 1) ? ($serviceRow['service_status'] ?? 'Open') : 'Inactive'),
            'remarks' => (string) ($serviceRow['remarks'] ?? ''),
            'lossReason' => (string) ($serviceRow['loss_reason'] ?? ''),
            'pnr' => (string) ($serviceRow['pnr'] ?? ''),
            'ticketNumber' => (string) ($serviceRow['ticket_number'] ?? ''),
            'airline' => (string) ($serviceRow['airline'] ?? ''),
            'sectorFrom' => (string) ($serviceRow['sector_from'] ?? ''),
            'sectorTo' => (string) ($serviceRow['sector_to'] ?? ''),
            'departureDate' => (string) ($serviceRow['departure_date'] ?? ''),
            'returnDate' => (string) ($serviceRow['return_date'] ?? ''),
            'class' => (string) ($serviceRow['travel_class'] ?? ''),
            'fare' => (float) ($serviceRow['fare'] ?? 0),
            'ticketTax' => (float) ($serviceRow['ticket_tax'] ?? 0),
            'ticketVat' => (float) ($serviceRow['ticket_vat'] ?? 0),
            'ticketCommission' => (float) ($serviceRow['ticket_commission'] ?? 0),
            'supplierCost' => (float) ($serviceRow['supplier_cost'] ?? 0),
            'saleAmount' => (float) ($serviceRow['sale_amount'] ?? 0),
            'ticketRemarks' => (string) ($serviceRow['ticket_remarks'] ?? ''),
            'visaCountry' => (string) ($serviceRow['visa_country'] ?? ''),
            'visaType' => (string) ($serviceRow['visa_type'] ?? ''),
            'visaApplicationReference' => (string) ($serviceRow['visa_application_reference'] ?? ''),
            'visaPassportNumber' => (string) ($serviceRow['visa_passport_number'] ?? ''),
            'visaSubmissionDate' => (string) ($serviceRow['visa_submission_date'] ?? ''),
            'visaIssueDate' => (string) ($serviceRow['visa_issue_date'] ?? ''),
            'visaExpiryDate' => (string) ($serviceRow['visa_expiry_date'] ?? ''),
            'visaStatus' => (string) ($serviceRow['visa_status'] ?? ''),
            'visaRemarks' => (string) ($serviceRow['visa_remarks'] ?? ''),
            'umrahPackageName' => (string) ($serviceRow['umrah_package_name'] ?? ''),
            'umrahMofaReference' => (string) ($serviceRow['umrah_mofa_reference'] ?? ''),
            'umrahDepartureDate' => (string) ($serviceRow['umrah_departure_date'] ?? ''),
            'umrahReturnDate' => (string) ($serviceRow['umrah_return_date'] ?? ''),
            'umrahHotelName' => (string) ($serviceRow['umrah_hotel_name'] ?? ''),
            'umrahTransportNotes' => (string) ($serviceRow['umrah_transport_notes'] ?? ''),
            'umrahRemarks' => (string) ($serviceRow['umrah_remarks'] ?? ''),
            'hotelName' => (string) ($serviceRow['hotel_name'] ?? ''),
            'hotelCity' => (string) ($serviceRow['hotel_city'] ?? ''),
            'hotelConfirmationNumber' => (string) ($serviceRow['hotel_confirmation_number'] ?? ''),
            'hotelCheckInDate' => (string) ($serviceRow['hotel_check_in_date'] ?? ''),
            'hotelCheckOutDate' => (string) ($serviceRow['hotel_check_out_date'] ?? ''),
            'hotelRoomType' => (string) ($serviceRow['hotel_room_type'] ?? ''),
            'hotelGuestCount' => (int) ($serviceRow['hotel_guest_count'] ?? 0),
            'hotelRemarks' => (string) ($serviceRow['hotel_remarks'] ?? ''),
            'transportMode' => (string) ($serviceRow['transport_mode'] ?? ''),
            'transportVehicleType' => (string) ($serviceRow['transport_vehicle_type'] ?? ''),
            'transportPickupDate' => (string) ($serviceRow['transport_pickup_date'] ?? ''),
            'transportPickupLocation' => (string) ($serviceRow['transport_pickup_location'] ?? ''),
            'transportDropoffLocation' => (string) ($serviceRow['transport_dropoff_location'] ?? ''),
            'transportDriverDetail' => (string) ($serviceRow['transport_driver_detail'] ?? ''),
            'transportRouteNotes' => (string) ($serviceRow['transport_route_notes'] ?? ''),
            'transportRemarks' => (string) ($serviceRow['transport_remarks'] ?? ''),
            'tourName' => (string) ($serviceRow['tour_name'] ?? ''),
            'tourDestination' => (string) ($serviceRow['tour_destination'] ?? ''),
            'tourConfirmationNumber' => (string) ($serviceRow['tour_confirmation_number'] ?? ''),
            'tourStartDate' => (string) ($serviceRow['tour_start_date'] ?? ''),
            'tourEndDate' => (string) ($serviceRow['tour_end_date'] ?? ''),
            'tourInclusions' => (string) ($serviceRow['tour_inclusions'] ?? ''),
            'tourRemarks' => (string) ($serviceRow['tour_remarks'] ?? ''),
            'otherLabel' => (string) ($serviceRow['other_label'] ?? ''),
            'otherReferenceNumber' => (string) ($serviceRow['other_reference_number'] ?? ''),
            'otherServiceDate' => (string) ($serviceRow['other_service_date'] ?? ''),
            'otherProviderName' => (string) ($serviceRow['other_provider_name'] ?? ''),
            'otherRemarks' => (string) ($serviceRow['other_remarks'] ?? ''),
            'isActive' => (int) ($serviceRow['is_active'] ?? 1),
            'profit' => $profit,
            'rowSpTotal' => $finalSalePrice,
            'rowReceivable' => $finalSalePrice,
            'rowPayable' => (float) ($serviceRow['purchase_cost'] ?? 0),
            'rowProfit' => $profit,
            'paymentEligible' => (int) ($serviceRow['id'] ?? 0) > 0 && $finalSalePrice > 0.005,
        ];
    }

    private function workspaceAutosaveCustomer(int $travelerId, array $accessibleBranchIds, array $previousBalanceTotals = []): ?array
    {
        if ($travelerId <= 0) {
            return null;
        }

        $repository = new TravelerRepository($this->app);
        if (! $repository->travelerExistsInBranches($travelerId, $accessibleBranchIds)) {
            return null;
        }

        $traveler = $repository->findTravelerById($travelerId);
        if ($traveler === null) {
            return null;
        }

        return [
            'id' => (int) ($traveler['id'] ?? 0),
            'branch_id' => (int) ($traveler['branch_id'] ?? 0),
            'full_name' => (string) ($traveler['full_name'] ?? ''),
            'passport_number' => (string) ($traveler['passport_number'] ?? ''),
            'mobile' => (string) ($traveler['mobile'] ?? ''),
            'family_id' => (string) ($traveler['family_id'] ?? ''),
            'color_tag' => (string) ($traveler['color_tag'] ?? ''),
            'gender' => (string) ($traveler['gender'] ?? 'unspecified'),
            'date_of_birth' => (string) ($traveler['date_of_birth'] ?? ''),
            'passport_expiry' => (string) ($traveler['passport_expiry'] ?? ''),
            'nationality' => (string) ($traveler['nationality'] ?? ''),
            'current_residence' => (string) ($traveler['current_residence'] ?? ''),
            'address' => (string) ($traveler['address'] ?? ''),
            'notes' => (string) ($traveler['notes'] ?? ''),
            'previous_balance_totals' => $previousBalanceTotals,
        ];
    }

    private function startSupplierAdvanceTrace(string $method, array $payload): string
    {
        $traceId = 'svc-' . date('YmdHis') . '-' . str_replace('.', '', (string) microtime(true)) . '-' . mt_rand(1000, 9999);
        $_SERVER['SUPPLIER_ADVANCE_TRACE_V2_ID'] = $traceId;

        $this->supplierAdvanceTraceLog('WorkspaceController::' . $method, 'request_entry', [
            'trace_id' => $traceId,
            'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'booking_id' => (int) ($payload['booking_id'] ?? 0),
            'booking_service_id' => (int) ($payload['service_id'] ?? 0),
            'supplier_name' => (string) ($payload['supplier_name'] ?? ''),
            'supplier_id' => null,
            'branch_id' => (int) ($payload['branch_id'] ?? 0),
            'currency' => (string) ($payload['currency'] ?? ''),
            'purchase_cost' => round((float) ($payload['purchase_cost'] ?? 0), 2),
            'mkt_fare' => round((float) ($payload['sale_price'] ?? 0), 2),
            'action' => 'request_reached_controller',
        ]);

        return $traceId;
    }

    private function supplierAdvanceTraceLog(string $method, string $step, array $context): void
    {
        if (! app_debug_tools_enabled()) {
            return;
        }

        try {
            $parts = [
                'timestamp=' . date('Y-m-d H:i:s'),
                'trace_id=' . ($context['trace_id'] ?? ($_SERVER['SUPPLIER_ADVANCE_TRACE_V2_ID'] ?? '')),
                'method=' . $method,
                'step=' . $step,
            ];

            foreach ($context as $key => $value) {
                if ($key === 'trace_id') {
                    continue;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }

                $parts[] = $key . '=' . str_replace(["\r", "\n"], [' ', ' '], (string) $value);
            }

            $line = '[SUPPLIER_ADVANCE_TRACE_V2] ' . implode('; ', $parts);
            $this->writeSupplierAdvanceTraceLine($line);
            error_log($line);
        } catch (Throwable $exception) {
            error_log('[SUPPLIER_ADVANCE_TRACE_V2] trace_error; method=' . $method . '; step=' . $step . '; message=' . str_replace(["\r", "\n"], [' ', ' '], $exception->getMessage()));
        }
    }

    private function writeSupplierAdvanceTraceLine(string $line): void
    {
        if (! app_debug_tools_enabled()) {
            return;
        }

        $logDirectory = dirname(__DIR__, 2) . '/storage/logs';
        $logFile = $logDirectory . '/supplier_advance_trace_v2.log';

        try {
            if (! is_dir($logDirectory) && ! @mkdir($logDirectory, 0777, true) && ! is_dir($logDirectory)) {
                throw new RuntimeException('Unable to create trace log directory.');
            }

            if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to append trace log line.');
            }
        } catch (Throwable $exception) {
            error_log('[SUPPLIER_ADVANCE_TRACE_V2] file_log_error; method=WorkspaceController::writeSupplierAdvanceTraceLine; message=' . str_replace(["\r", "\n"], [' ', ' '], $exception->getMessage()));
        }
    }
}
