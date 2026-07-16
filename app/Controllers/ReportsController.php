<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Authorization;
use App\Helpers\AuditLog;
use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\ReportRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TreasuryRepository;
use App\Services\CustomerReceiptWorkspaceService;
use App\Services\ReportService;
use App\Services\SupplierSettlementWorkspaceService;
use RuntimeException;

final class ReportsController extends BaseController
{
    public function index(): string
    {
        try {
            if ((string) ($_GET['report'] ?? '') === 'accounting_integrity' && ! Auth::isFinancialAdmin()) {
                throw new RuntimeException('Only super admin or branch admin can open accounting integrity checks.');
            }

            $state = (new ReportService($this->app))->reportState(
                $_GET,
                Authorization::accessibleBranchIds(),
                (int) Auth::id(),
                'screen'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/');
        }

        return $this->view('reports/index', array_merge($state, [
            'user' => Auth::user(),
            'pageScript' => 'assets/js/reports.js',
        ]));
    }

    public function exportCsv(): never
    {
        try {
            if ((string) ($_GET['report'] ?? '') === 'accounting_integrity' && ! Auth::isFinancialAdmin()) {
                throw new RuntimeException('Only super admin or branch admin can export accounting integrity checks.');
            }

            $state = (new ReportService($this->app))->reportState(
                $_GET,
                Authorization::accessibleBranchIds(),
                (int) Auth::id(),
                'csv'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/reports');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $state['csvFilename']) . '"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            exit;
        }

        fputcsv($stream, array_map(static fn (array $column): string => (string) $column['label'], $state['columns']));
        foreach ($state['rows'] as $row) {
            fputcsv($stream, array_map(
                static fn (array $column): string => (string) ($row[$column['key']] ?? ''),
                $state['columns']
            ));
        }

        fclose($stream);
        exit;
    }

    public function accountLedgerPrint(): string
    {
        try {
            $query = $_GET;
            $query['report'] = 'customer_ledger';

            $state = (new ReportService($this->app))->reportState(
                $query,
                Authorization::accessibleBranchIds(),
                (int) Auth::id(),
                'print'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/reports?report=customer_ledger');
        }

        return $this->view('reports/account_ledger_print', array_merge($state, [
            'user' => Auth::user(),
            'pageTitle' => 'Account Ledger Print',
        ]), 'layouts/print');
    }

    public function printReport(): string
    {
        try {
            if ((string) ($_GET['report'] ?? '') === 'accounting_integrity' && ! Auth::isFinancialAdmin()) {
                throw new RuntimeException('Only super admin or branch admin can print accounting integrity checks.');
            }

            $state = (new ReportService($this->app))->reportState(
                $_GET,
                Authorization::accessibleBranchIds(),
                (int) Auth::id(),
                'print'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/reports');
        }

        return $this->view('reports/report_print', array_merge($state, [
            'user' => Auth::user(),
            'pageTitle' => 'Report Print',
        ]), 'layouts/print');
    }

    public function supplierPrepaidReceipt(): string
    {
        $advanceId = (int) ($_GET['supplier_advance_id'] ?? 0);
        if ($advanceId <= 0) {
            Flash::error('Please select a valid prepaid supplier payment receipt.');
            $this->redirect('/reports?report=supplier_prepaid_payments');
        }

        $repository = new ReportRepository($this->app);
        $receipt = $repository->supplierPrepaidPaymentReceipt($advanceId, Authorization::accessibleBranchIds());
        if ($receipt === null) {
            Flash::error('The selected prepaid supplier payment receipt could not be found.');
            $this->redirect('/reports?report=supplier_prepaid_payments');
        }

        return $this->view('reports/supplier_prepaid_receipt', [
            'user' => Auth::user(),
            'receipt' => $receipt,
            'pageTitle' => 'Prepaid Supplier Payment Receipt',
        ]);
    }

    public function globalSupplierSettlement(): string
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $bookingRepository = new BookingRepository($this->app);
        $supplierRepository = new SupplierRepository($this->app);
        $treasuryRepository = new TreasuryRepository($this->app);
        $masterData = new MasterDataRepository($this->app);

        $branchOptions = $bookingRepository->branchOptions($accessibleBranchIds);
        $selectedBranchId = (int) ($_GET['branch_id'] ?? ($branchOptions[0]['id'] ?? 0));
        if ($selectedBranchId <= 0 || ! in_array($selectedBranchId, array_map('intval', $accessibleBranchIds), true)) {
            $selectedBranchId = (int) ($branchOptions[0]['id'] ?? 0);
        }

        $supplierOptions = $selectedBranchId > 0
            ? $supplierRepository->globalSettlementSupplierOptions($selectedBranchId)
            : [];
        $selectedSupplierId = (int) ($_GET['supplier_id'] ?? 0);
        $supplierOptionIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $supplierOptions);
        if ($selectedSupplierId <= 0 || ! in_array($selectedSupplierId, $supplierOptionIds, true)) {
            $selectedSupplierId = (int) ($supplierOptions[0]['id'] ?? 0);
        }

        $currencyOptions = $selectedSupplierId > 0
            ? $supplierRepository->globalSettlementCurrencies($selectedBranchId, $selectedSupplierId)
            : [];
        $availableCurrencies = array_map(static fn (array $row): string => (string) ($row['currency'] ?? ''), $currencyOptions);
        $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
        if (! in_array($currency, $availableCurrencies, true)) {
            $currency = (string) ($availableCurrencies[0] ?? 'PKR');
        }

        $openObligations = $selectedSupplierId > 0 && $selectedBranchId > 0
            ? $supplierRepository->globalOpenObligations($selectedBranchId, $selectedSupplierId, $currency)
            : [];

        $sourceAccounts = [];
        foreach (['cash', 'bank_transfer'] as $method) {
            foreach ($treasuryRepository->eligiblePaymentTreasuryAccounts($selectedBranchId, $currency, $method) as $account) {
                $accountId = (int) ($account['id'] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }

                if (! isset($sourceAccounts[$accountId])) {
                    $sourceAccounts[$accountId] = $account;
                    $sourceAccounts[$accountId]['payment_methods'] = [];
                    $sourceAccounts[$accountId]['current_balance'] = $treasuryRepository->currentBalanceForAccountId($accountId);
                }
                $sourceAccounts[$accountId]['payment_methods'][] = $method;
            }
        }

        $paymentMethods = $masterData->activeCodeLabelMap('payment_methods');
        $paymentMethods = array_intersect_key($paymentMethods !== [] ? $paymentMethods : [
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'debit_card' => 'Debit Card',
            'credit_card' => 'Credit Card',
        ], array_flip(['cash', 'bank_transfer', 'debit_card', 'credit_card']));

        return $this->view('reports/global_supplier_settlement', [
            'user' => Auth::user(),
            'title' => 'Global Supplier Settlement',
            'branchOptions' => $branchOptions,
            'supplierOptions' => $supplierOptions,
            'selectedBranchId' => $selectedBranchId,
            'selectedSupplierId' => $selectedSupplierId,
            'selectedCurrency' => $currency,
            'currencyOptions' => $currencyOptions,
            'openObligations' => $openObligations,
            'sourceAccounts' => array_values($sourceAccounts),
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function saveGlobalSupplierSettlement(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new SupplierSettlementWorkspaceService($this->app))->recordGlobalPostpaidSupplierPayment(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            $supplierCreditMessage = '';
            if ((float) ($result['unallocated_amount'] ?? 0) > 0.005) {
                $supplierCreditMessage = ' Supplier credit kept: '
                    . (string) ($result['currency'] ?? 'PKR')
                    . ' '
                    . number_format((float) ($result['unallocated_amount'] ?? 0), 2)
                    . '.';
            }

            Flash::success(
                'Global supplier payment '
                . (string) ($result['payment_no'] ?? '')
                . ' posted for '
                . (string) ($result['supplier_name'] ?? 'Supplier')
                . '. Allocated '
                . (string) ($result['currency'] ?? 'PKR')
                . ' '
                . number_format((float) ($result['allocated_amount'] ?? 0), 2)
                . ' across '
                . (int) ($result['allocation_count'] ?? 0)
                . ' payable item(s).'
                . $supplierCreditMessage
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $query = http_build_query([
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'supplier_id' => (int) ($_POST['supplier_id'] ?? 0),
            'currency' => (string) ($_POST['supplier_payment_currency'] ?? 'PKR'),
        ]);
        $this->redirect('/suppliers/settlements/global?' . $query);
    }

    public function globalCustomerSettlement(): string
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $bookingRepository = new BookingRepository($this->app);
        $customerRepository = new CustomerPaymentRepository($this->app);
        $treasuryRepository = new TreasuryRepository($this->app);
        $masterData = new MasterDataRepository($this->app);

        $branchOptions = $bookingRepository->branchOptions($accessibleBranchIds);
        $selectedBranchId = (int) ($_GET['branch_id'] ?? ($branchOptions[0]['id'] ?? 0));
        if ($selectedBranchId <= 0 || ! in_array($selectedBranchId, array_map('intval', $accessibleBranchIds), true)) {
            $selectedBranchId = (int) ($branchOptions[0]['id'] ?? 0);
        }

        $customerOptions = $selectedBranchId > 0
            ? $customerRepository->globalSettlementCustomerOptions($selectedBranchId)
            : [];
        $selectedTravelerId = (int) ($_GET['traveler_id'] ?? 0);
        $customerOptionIds = array_map(static fn (array $row): int => (int) ($row['traveler_id'] ?? 0), $customerOptions);
        if ($selectedTravelerId <= 0 || ! in_array($selectedTravelerId, $customerOptionIds, true)) {
            $selectedTravelerId = (int) ($customerOptions[0]['traveler_id'] ?? 0);
        }

        $currencyOptions = $selectedTravelerId > 0
            ? $customerRepository->globalSettlementCurrencies($selectedBranchId, $selectedTravelerId)
            : [];
        $availableCurrencies = array_map(static fn (array $row): string => (string) ($row['currency'] ?? ''), $currencyOptions);
        $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
        if (! in_array($currency, $availableCurrencies, true)) {
            $currency = (string) ($availableCurrencies[0] ?? 'PKR');
        }

        $openReceivables = $selectedTravelerId > 0 && $selectedBranchId > 0
            ? $customerRepository->globalOpenReceivables($selectedBranchId, $selectedTravelerId, $currency)
            : [];
        $availableAdvances = $selectedTravelerId > 0 && $selectedBranchId > 0
            ? $customerRepository->availableCustomerAdvances($selectedBranchId, $selectedTravelerId, $currency)
            : [];

        $treasuryAccounts = [];
        foreach (['cash', 'bank_transfer'] as $method) {
            foreach ($treasuryRepository->eligiblePaymentTreasuryAccounts($selectedBranchId, $currency, $method) as $account) {
                $accountId = (int) ($account['id'] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }

                if (! isset($treasuryAccounts[$accountId])) {
                    $treasuryAccounts[$accountId] = $account;
                    $treasuryAccounts[$accountId]['payment_methods'] = [];
                    $treasuryAccounts[$accountId]['current_balance'] = $treasuryRepository->currentBalanceForAccountId($accountId);
                }
                $treasuryAccounts[$accountId]['payment_methods'][] = $method;
            }
        }

        $paymentMethods = $masterData->activeCodeLabelMap('payment_methods');
        $paymentMethods = array_intersect_key($paymentMethods !== [] ? $paymentMethods : [
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'debit_card' => 'Debit Card',
            'credit_card' => 'Credit Card',
        ], array_flip(['cash', 'bank_transfer', 'debit_card', 'credit_card']));

        return $this->view('reports/global_customer_settlement', [
            'user' => Auth::user(),
            'title' => 'Global Customer Payment',
            'branchOptions' => $branchOptions,
            'customerOptions' => $customerOptions,
            'selectedBranchId' => $selectedBranchId,
            'selectedTravelerId' => $selectedTravelerId,
            'selectedCurrency' => $currency,
            'currencyOptions' => $currencyOptions,
            'openReceivables' => $openReceivables,
            'availableAdvances' => $availableAdvances,
            'treasuryAccounts' => array_values($treasuryAccounts),
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function saveGlobalCustomerSettlement(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $action = (string) ($_POST['settlement_action_button'] ?? $_POST['settlement_action'] ?? 'payment');
            $service = new CustomerReceiptWorkspaceService($this->app);
            if ($action === 'apply_advance') {
                $result = $service->applyCustomerAdvance(
                    $_POST,
                    (int) Auth::id(),
                    Authorization::accessibleBranchIds()
                );
                Flash::success(
                    'Customer advance '
                    . (string) ($result['receipt_no'] ?? '')
                    . ' applied for '
                    . (string) ($result['customer_name'] ?? 'Customer')
                    . '. Allocated '
                    . (string) ($result['currency'] ?? 'PKR')
                    . ' '
                    . number_format((float) ($result['allocated_amount'] ?? 0), 2)
                    . ' across '
                    . (int) ($result['allocation_count'] ?? 0)
                    . ' receivable item(s).'
                );
            } else {
                $result = $service->recordGlobalCustomerPayment(
                    $_POST,
                    (int) Auth::id(),
                    Authorization::accessibleBranchIds()
                );
                Flash::success(
                    'Global customer payment '
                    . (string) ($result['receipt_no'] ?? '')
                    . ' posted for '
                    . (string) ($result['customer_name'] ?? 'Customer')
                    . '. Allocated '
                    . (string) ($result['currency'] ?? 'PKR')
                    . ' '
                    . number_format((float) ($result['allocated_amount'] ?? 0), 2)
                    . ' across '
                    . (int) ($result['allocation_count'] ?? 0)
                    . ' receivable item(s).'
                    . ((float) ($result['unallocated_amount'] ?? 0) > 0
                        ? ' Remaining customer credit: ' . (string) ($result['currency'] ?? 'PKR') . ' ' . number_format((float) ($result['unallocated_amount'] ?? 0), 2) . '.'
                        : '')
                );
            }
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $query = http_build_query([
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
            'currency' => (string) ($_POST['receipt_currency'] ?? 'PKR'),
        ]);
        $this->redirect('/customers/settlements/global?' . $query);
    }

    public function saveCustomerAdvance(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->safeLocalReturnPath((string) ($_POST['return_to'] ?? ''), '/workspace');

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->recordCustomerAdvance(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            AuditLog::record($this->app, 'customer.advance.recorded', [
                'customer_receipt_id' => (int) ($result['receipt_id'] ?? 0),
                'receipt_no' => (string) ($result['receipt_no'] ?? ''),
                'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                'currency' => (string) ($result['currency'] ?? 'PKR'),
                'received_amount' => (float) ($result['received_amount'] ?? 0),
            ]);
            Flash::success(
                'Customer advance '
                . (string) ($result['receipt_no'] ?? '')
                . ' recorded for '
                . (string) ($result['customer_name'] ?? 'Customer')
                . ': '
                . (string) ($result['currency'] ?? 'PKR')
                . ' '
                . number_format((float) ($result['received_amount'] ?? 0), 2)
                . '.'
            );
            if ((string) ($_POST['print_after_save'] ?? '0') === '1' && (int) ($result['receipt_id'] ?? 0) > 0) {
                try {
                    echo $this->view('reports/customer_advance_receipt', $this->customerAdvanceReceiptViewData((int) $result['receipt_id']), 'layouts/print');
                    exit;
                } catch (RuntimeException $receiptException) {
                    AuditLog::record($this->app, 'customer.advance.receipt.render_failed_after_save', [
                        'customer_receipt_id' => (int) ($result['receipt_id'] ?? 0),
                        'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                        'message' => $receiptException->getMessage(),
                    ]);
                    Flash::error('Customer advance was saved, but the receipt could not be opened.');
                }
            }
        } catch (RuntimeException $exception) {
            AuditLog::record($this->app, 'customer.advance.failed', [
                'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                'branch_id' => (int) ($_POST['branch_id'] ?? 0),
                'currency' => (string) ($_POST['receipt_currency'] ?? 'PKR'),
                'message' => $exception->getMessage(),
            ]);
            Flash::error($exception->getMessage());
        }

        $this->redirect($returnTo);
    }

    public function customerAdvanceReceipt(): string
    {
        $receiptId = (int) ($_GET['receipt_id'] ?? 0);
        try {
            $viewData = $this->customerAdvanceReceiptViewData($receiptId);
        } catch (RuntimeException $exception) {
            AuditLog::record($this->app, 'customer.advance.receipt.open_failed', [
                'customer_receipt_id' => $receiptId,
                'message' => $exception->getMessage(),
            ]);
            Flash::error('Customer advance receipt could not be opened.');
            $this->redirect('/workspace');
        }

        AuditLog::record($this->app, 'customer.advance.receipt.opened', [
            'customer_receipt_id' => $receiptId,
            'traveler_id' => (int) (($viewData['receipt']['traveler_id'] ?? 0)),
        ]);

        return $this->view('reports/customer_advance_receipt', $viewData, 'layouts/print');
    }

    private function customerAdvanceReceiptViewData(int $receiptId): array
    {
        $repository = new CustomerPaymentRepository($this->app);
        $receipt = $repository->findReceiptById($receiptId);
        if ($receipt === null) {
            throw new RuntimeException('Receipt record was not found.');
        }

        $accessibleBranchIds = array_values(array_unique(array_map('intval', Authorization::accessibleBranchIds())));
        $receiptBranchId = (int) ($receipt['branch_id'] ?? 0);
        $receiptPurpose = (string) ($receipt['receipt_purpose'] ?? '');
        $bookingReference = strtoupper(trim((string) ($receipt['booking_reference'] ?? '')));
        $isCustomerAdvanceReceipt = $receiptPurpose === 'customer_advance'
            || ($receiptPurpose === 'booking_payment' && $bookingReference === 'ADVANCE');

        if (! in_array($receiptBranchId, $accessibleBranchIds, true)) {
            throw new RuntimeException('Receipt branch is not accessible.');
        }

        if (! $isCustomerAdvanceReceipt) {
            throw new RuntimeException('Receipt is not a customer advance receipt.');
        }

        $branchRow = [];
        foreach ((new MasterDataRepository($this->app))->rows('branches') as $candidateBranchRow) {
            if ((int) ($candidateBranchRow['id'] ?? 0) === $receiptBranchId) {
                $branchRow = $candidateBranchRow;
                break;
            }
        }

        $branchCode = mb_strtolower(trim((string) ($branchRow['code'] ?? '')));
        $receiptProfiles = config('branches.receipt_contacts', []);
        $branchContact = is_array($receiptProfiles[$branchCode] ?? null) ? $receiptProfiles[$branchCode] : [];
        $branchDirectory = [];

        foreach ($receiptProfiles as $profileCode => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $location = trim((string) ($profile['location_label'] ?? ''));
            $contactBits = [];
            $landline = trim((string) ($profile['landline'] ?? ''));
            $email = trim((string) ($profile['email'] ?? ''));

            if ($landline !== '') {
                $contactBits[] = $landline;
            }
            if ($email !== '') {
                $contactBits[] = $email;
            }

            $branchDirectory[] = [
                'branch' => trim((string) ($profile['display_name'] ?? ucfirst((string) $profileCode))),
                'location' => $location,
                'contact' => implode(' | ', $contactBits),
            ];
        }

        return [
            'title' => 'Customer Advance Receipt',
            'receipt' => $receipt,
            'customerName' => $repository->customerNameByTraveler((int) ($receipt['traveler_id'] ?? 0)),
            'branchName' => (string) ($branchRow['name'] ?? ''),
            'branchBranding' => [
                'code' => $branchCode,
                'name' => (string) ($branchRow['name'] ?? ''),
                'receipt_name' => trim((string) ($branchContact['display_name'] ?? ((string) ($branchRow['name'] ?? '')))),
                'contact' => $branchContact,
            ],
            'branchDirectory' => $branchDirectory,
            'generatedAt' => date('Y-m-d H:i'),
        ];
    }

    public function availableCustomerAdvances(): never
    {
        $branchId = (int) ($_GET['branch_id'] ?? 0);
        $travelerId = (int) ($_GET['traveler_id'] ?? 0);
        $currency = strtoupper(trim((string) ($_GET['currency'] ?? 'PKR')));
        $accessibleBranchIds = array_values(array_unique(array_map('intval', Authorization::accessibleBranchIds())));

        if ($branchId <= 0 || $travelerId <= 0 || ! in_array($branchId, $accessibleBranchIds, true)) {
            $this->jsonResponse(['ok' => true, 'advances' => []]);
        }

        $advances = (new CustomerPaymentRepository($this->app))->availableCustomerAdvances(
            $branchId,
            $travelerId,
            $currency
        );

        $this->jsonResponse([
            'ok' => true,
            'advances' => array_map(static function (array $advance): array {
                return [
                    'id' => (int) ($advance['id'] ?? 0),
                    'receipt_no' => (string) ($advance['receipt_no'] ?? ''),
                    'receipt_date' => (string) ($advance['receipt_date'] ?? ''),
                    'currency' => (string) ($advance['currency'] ?? ''),
                    'unallocated_amount' => (float) ($advance['unallocated_amount'] ?? 0),
                ];
            }, $advances),
        ]);
    }

    public function refundCustomerAdvance(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->safeLocalReturnPath((string) ($_POST['return_to'] ?? ''), '/workspace');

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->refundCustomerAdvance(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success(
                'Customer advance refunded for '
                . (string) ($result['customer_name'] ?? 'Customer')
                . ': '
                . (string) ($result['currency'] ?? 'PKR')
                . ' '
                . number_format((float) ($result['amount'] ?? 0), 2)
                . '.'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect($returnTo);
    }

    public function correctCustomerAdvance(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->safeLocalReturnPath((string) ($_POST['return_to'] ?? ''), '/reports?report=customer_advance_ledger');

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->correctCustomerAdvance(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            AuditLog::record($this->app, 'customer.advance.corrected', [
                'customer_receipt_id' => (int) ($_POST['customer_receipt_id'] ?? 0),
                'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                'currency' => (string) ($result['currency'] ?? 'PKR'),
                'amount' => (float) ($result['amount'] ?? 0),
            ]);
            Flash::success(
                'Customer advance '
                . (string) ($result['receipt_no'] ?? '')
                . ' corrected for '
                . (string) ($result['customer_name'] ?? 'Customer')
                . '.'
            );
        } catch (RuntimeException $exception) {
            AuditLog::record($this->app, 'customer.advance.correction_failed', [
                'customer_receipt_id' => (int) ($_POST['customer_receipt_id'] ?? 0),
                'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                'message' => $exception->getMessage(),
            ]);
            Flash::error($exception->getMessage());
        }

        $this->redirect($returnTo);
    }

    public function correctCustomerAdvanceRefund(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->safeLocalReturnPath((string) ($_POST['return_to'] ?? ''), '/reports?report=customer_advance_ledger');

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->correctCustomerAdvanceRefund(
                $_POST,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            AuditLog::record($this->app, 'customer.advance_refund.corrected', [
                'customer_advance_refund_id' => (int) ($_POST['customer_advance_refund_id'] ?? 0),
                'customer_receipt_id' => (int) ($_POST['customer_receipt_id'] ?? 0),
                'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                'currency' => (string) ($result['currency'] ?? 'PKR'),
                'amount' => (float) ($result['amount'] ?? 0),
            ]);
            Flash::success(
                'Returned customer advance '
                . (string) ($result['receipt_no'] ?? '')
                . ' corrected for '
                . (string) ($result['customer_name'] ?? 'Customer')
                . '.'
            );
        } catch (RuntimeException $exception) {
            AuditLog::record($this->app, 'customer.advance_refund.correction_failed', [
                'customer_advance_refund_id' => (int) ($_POST['customer_advance_refund_id'] ?? 0),
                'customer_receipt_id' => (int) ($_POST['customer_receipt_id'] ?? 0),
                'traveler_id' => (int) ($_POST['traveler_id'] ?? 0),
                'message' => $exception->getMessage(),
            ]);
            Flash::error($exception->getMessage());
        }

        $this->redirect($returnTo);
    }

    private function safeLocalReturnPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            return $fallback;
        }

        if (! str_starts_with($path, '/')) {
            return $fallback;
        }

        $basePath = request_base_path();
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
            $path = $path === '' ? '/' : $path;
        }

        return str_starts_with($path, '/') ? $path : $fallback;
    }
}
