<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Authorization;
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
            'treasuryAccounts' => array_values($treasuryAccounts),
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function saveGlobalCustomerSettlement(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $result = (new CustomerReceiptWorkspaceService($this->app))->recordGlobalCustomerPayment(
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
}
