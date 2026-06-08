<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Authorization;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Repositories\AccountingSetupRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\MasterDataRepository;
use App\Services\AccountingFoundationService;
use App\Services\AccountingSetupService;
use App\Services\CustomerPaymentFoundationService;
use App\Services\ExpenseAdminService;
use App\Services\MasterDataAdminService;
use App\Services\SupplierFoundationService;
use RuntimeException;

final class ControlController extends BaseController
{
    public function masterData(): string
    {
        $repository = new MasterDataRepository($this->app);
        $editRegister = $this->requestedRegister([
            'branches',
            'currencies',
            'service_types',
            'payment_methods',
            'supplier_modes',
            'document_types',
        ]);
        $editId = (int) ($_GET['id'] ?? 0);

        $branches = $this->decorateMasterRows($repository->rows('branches'));
        $currencies = $this->decorateMasterRows($repository->rows('currencies'));
        $serviceTypes = $this->decorateMasterRows($repository->rows('service_types'));
        $paymentMethods = $this->decorateMasterRows($repository->rows('payment_methods'));
        $supplierModes = $this->decorateMasterRows($repository->rows('supplier_modes'));
        $documentTypes = $this->decorateMasterRows($repository->rows('document_types'));

        $currencyOptions = array_map(
            static fn (array $row): array => [
                'value' => $row['code'],
                'label' => $row['code'] . ' - ' . $row['name'],
            ],
            $currencies !== [] ? $currencies : [
                ['code' => 'PKR', 'name' => 'Pakistani Rupee'],
                ['code' => 'AED', 'name' => 'UAE Dirham'],
                ['code' => 'USD', 'name' => 'US Dollar'],
            ]
        );

        return $this->view('control/master_data', [
            'title' => 'Master Data',
            'accessibleBranchIds' => Authorization::accessibleBranchIds(),
            'panels' => [
                $this->masterPanel(
                    'branches',
                    'Branches',
                    'Branch ownership, security scope, and base operating currency.',
                    $branches,
                    $editRegister === 'branches' ? $repository->find('branches', $editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'city', 'label' => 'City'],
                        ['key' => 'country_code', 'label' => 'Country'],
                        ['key' => 'base_currency', 'label' => 'Base Curr.'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 50, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 190, 'required' => true],
                        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'maxlength' => 120],
                        ['name' => 'country_code', 'label' => 'Country', 'type' => 'text', 'maxlength' => 2, 'required' => true],
                        ['name' => 'base_currency', 'label' => 'Base Curr.', 'type' => 'select', 'required' => true, 'options' => $currencyOptions],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'currencies',
                    'Currencies',
                    'Transaction currencies and PKR consolidation roles.',
                    $currencies,
                    $editRegister === 'currencies' ? $repository->find('currencies', $editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'symbol', 'label' => 'Symbol'],
                        ['key' => 'reporting_role', 'label' => 'Reporting Role'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 3, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'symbol', 'label' => 'Symbol', 'type' => 'text', 'maxlength' => 10],
                        ['name' => 'reporting_role', 'label' => 'Role', 'type' => 'text', 'maxlength' => 190, 'required' => true],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'service_types',
                    'Service Types',
                    'Operational service-line vocabularies and posting behavior basis.',
                    $serviceTypes,
                    $editRegister === 'service_types' ? $repository->find('service_types', $editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'posting_mode', 'label' => 'Posting Mode'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 20, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'posting_mode', 'label' => 'Posting', 'type' => 'text', 'maxlength' => 190, 'required' => true],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'payment_methods',
                    'Payment Methods',
                    'Booking-level collection methods and accounting targets.',
                    $paymentMethods,
                    $editRegister === 'payment_methods' ? $repository->find('payment_methods', $editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'ledger_target', 'label' => 'Ledger Target'],
                        ['key' => 'charges_target', 'label' => 'Charges Target'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 50, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'ledger_target', 'label' => 'Ledger', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'charges_target', 'label' => 'Charges', 'type' => 'text', 'maxlength' => 120],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'supplier_modes',
                    'Supplier Modes',
                    'Running-balance versus direct-payable supplier behavior.',
                    $supplierModes,
                    $editRegister === 'supplier_modes' ? $repository->find('supplier_modes', $editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'behavior', 'label' => 'Behavior'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 50, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'behavior', 'label' => 'Behavior', 'type' => 'select', 'required' => true, 'options' => $this->choiceOptions([
                            'Creates direct supplier payable' => 'Creates direct supplier payable',
                            'Consumes supplier advance first' => 'Consumes supplier advance first',
                        ])],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'document_types',
                    'Document Types',
                    'Booking-linked document classifications for retrieval and reminders.',
                    $documentTypes,
                    $editRegister === 'document_types' ? $repository->find('document_types', $editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'linked_area', 'label' => 'Linked Area'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 50, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'linked_area', 'label' => 'Linked Area', 'type' => 'select', 'required' => true, 'options' => $this->choiceOptions([
                            'Booking' => 'Booking File',
                            'Traveler' => 'Traveler',
                            'Service Line' => 'Service Line',
                            'Service Line / Print' => 'Service Line / Print',
                            'Booking / Accounts' => 'Booking / Accounts',
                            'Customer Receipt' => 'Customer Receipt',
                            'Supplier Payment' => 'Supplier Payment',
                            'Supplier Obligation' => 'Supplier Obligation',
                        ])],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
            ],
        ]);
    }

    public function saveMasterData(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $register = (string) ($_POST['register'] ?? '');
        $editId = (int) ($_POST['id'] ?? 0);

        try {
            $result = (new MasterDataAdminService($this->app))->save($register, $_POST, (int) Auth::id());
            Flash::success(ucfirst(str_replace('_', ' ', $register)) . ' ' . $result['action'] . ' successfully.');
            $this->redirectToRegister('/master-data', $register);
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirectToRegister('/master-data', $register, $editId > 0 ? $editId : null);
        }
    }

    public function deleteMasterData(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $register = (string) ($_POST['register'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $result = (new MasterDataAdminService($this->app))->delete($register, $id, (int) Auth::id());
            Flash::success($result['label'] . ' deleted successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirectToRegister('/master-data', $register);
    }

    public function accountingEngine(): string
    {
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $repository = new AccountingSetupRepository($this->app);
        $editRegister = $this->requestedRegister(['control_accounts', 'posting_rules']);
        $editId = (int) ($_GET['id'] ?? 0);
        $previewSearchTerm = trim((string) ($_GET['preview_q'] ?? ''));
        $previewBookingId = (int) ($_GET['preview_booking_id'] ?? 0);

        $controlAccounts = $this->decorateMasterRows($repository->listControlAccounts());
        $postingRules = array_map(function (array $row): array {
            $row['status_label'] = ((int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive');
            $row['system_label'] = ((int) ($row['is_system'] ?? 0) === 1 ? 'System' : 'Custom');
            $row['debit_account'] = trim(($row['debit_account_code'] ?? '') . ' - ' . ($row['debit_account_name'] ?? ''));
            $row['credit_account'] = trim(($row['credit_account_code'] ?? '') . ' - ' . ($row['credit_account_name'] ?? ''));

            return $row;
        }, $repository->listPostingRules());

        $accountOptions = array_map(
            static fn (array $account): array => [
                'value' => (string) $account['id'],
                'label' => $account['code'] . ' - ' . $account['name'],
            ],
            $repository->accountOptions()
        );

        $bookingRepository = new BookingRepository($this->app);
        $previewBookingOptions = $bookingRepository->searchBookings($previewSearchTerm, $accessibleBranchIds, 20);
        $selectedPreviewBooking = null;
        if ($previewBookingId > 0 && $bookingRepository->bookingExistsInBranches($previewBookingId, $accessibleBranchIds)) {
            $selectedPreviewBooking = $bookingRepository->findBookingById($previewBookingId);
        }
        if ($selectedPreviewBooking === null && $previewBookingOptions !== []) {
            $fallbackBookingId = (int) ($previewBookingOptions[0]['id'] ?? 0);
            if ($fallbackBookingId > 0) {
                $selectedPreviewBooking = $bookingRepository->findBookingById($fallbackBookingId);
                $previewBookingId = $fallbackBookingId;
            }
        }

        $bookingReference = trim((string) ($selectedPreviewBooking['booking_reference'] ?? ''));
        $serviceLines = $bookingReference !== ''
            ? (new BookingServiceRepository($this->app))->servicesByBookingReference($bookingReference)
            : [];
        $supplierFoundation = (new SupplierFoundationService($this->app))->buildWorkspacePreview($bookingReference);
        $customerPaymentFoundation = (new CustomerPaymentFoundationService($this->app))->buildWorkspacePreview($bookingReference);
        $accountingFoundation = (new AccountingFoundationService($this->app))->buildWorkspacePreview(
            $bookingReference !== '' ? $bookingReference : null,
            $serviceLines,
            $supplierFoundation,
            $customerPaymentFoundation
        );

        return $this->view('control/accounting_engine_admin', [
            'title' => 'Accounting Engine',
            'accessibleBranchIds' => $accessibleBranchIds,
            'panels' => [
                $this->masterPanel(
                    'control_accounts',
                    'Control Accounts',
                    'Chart-of-accounts rows used by the booking-driven posting engine.',
                    $controlAccounts,
                    $editRegister === 'control_accounts' ? $repository->findControlAccount($editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Account'],
                        ['key' => 'account_type', 'label' => 'Type'],
                        ['key' => 'normal_balance', 'label' => 'Balance'],
                        ['key' => 'purpose', 'label' => 'Purpose'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 50, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 190, 'required' => true],
                        ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'maxlength' => 255],
                        ['name' => 'account_type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => $this->choiceOptions([
                            'asset' => 'Asset',
                            'liability' => 'Liability',
                            'equity' => 'Equity',
                            'revenue' => 'Revenue',
                            'expense' => 'Expense',
                        ])],
                        ['name' => 'normal_balance', 'label' => 'Balance', 'type' => 'select', 'required' => true, 'options' => $this->choiceOptions([
                            'debit' => 'Debit',
                            'credit' => 'Credit',
                        ])],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'posting_rules',
                    'Posting Rules',
                    'Event-driven accounting rules mapping travel operations into debit and credit controls.',
                    $postingRules,
                    $editRegister === 'posting_rules' ? $repository->findPostingRule($editId) : null,
                    [
                        ['key' => 'event_key', 'label' => 'Event Key'],
                        ['key' => 'event_name', 'label' => 'Event'],
                        ['key' => 'source_area', 'label' => 'Source'],
                        ['key' => 'debit_account', 'label' => 'Debit'],
                        ['key' => 'credit_account', 'label' => 'Credit'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'event_key', 'label' => 'Event Key', 'type' => 'text', 'maxlength' => 100, 'required' => true],
                        ['name' => 'event_name', 'label' => 'Event', 'type' => 'text', 'maxlength' => 190, 'required' => true],
                        ['name' => 'source_area', 'label' => 'Source', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'financial_effect', 'label' => 'Effect', 'type' => 'text', 'maxlength' => 255, 'required' => true],
                        ['name' => 'debit_account_id', 'label' => 'Debit', 'type' => 'select', 'required' => true, 'options' => $accountOptions],
                        ['name' => 'credit_account_id', 'label' => 'Credit', 'type' => 'select', 'required' => true, 'options' => $accountOptions],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                        ['name' => 'rule_note', 'label' => 'Rule Note', 'type' => 'textarea', 'maxlength' => 2000, 'stack' => true],
                    ]
                ),
            ],
            'accountingFoundation' => $accountingFoundation,
            'supplierFoundation' => $supplierFoundation,
            'customerPaymentFoundation' => $customerPaymentFoundation,
            'previewSearchTerm' => $previewSearchTerm,
            'previewBookingId' => $previewBookingId,
            'previewBookingOptions' => $previewBookingOptions,
            'selectedPreviewBooking' => $selectedPreviewBooking,
        ]);
    }

    public function saveAccountingEngine(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $register = (string) ($_POST['register'] ?? '');
        $editId = (int) ($_POST['id'] ?? 0);

        try {
            $result = (new AccountingSetupService($this->app))->save($register, $_POST, (int) Auth::id());
            Flash::success(ucfirst(str_replace('_', ' ', $register)) . ' ' . $result['action'] . ' successfully.');
            $this->redirectToRegister('/accounting-engine', $register);
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirectToRegister('/accounting-engine', $register, $editId > 0 ? $editId : null);
        }
    }

    public function deleteAccountingEngine(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $register = (string) ($_POST['register'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $result = (new AccountingSetupService($this->app))->delete($register, $id, (int) Auth::id());
            Flash::success($result['label'] . ' deleted successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirectToRegister('/accounting-engine', $register);
    }

    public function expenses(): string
    {
        $repository = new ExpenseRepository($this->app);
        $masterData = new MasterDataRepository($this->app);
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $editRegister = $this->requestedRegister(['expense_categories', 'business_expenses']);
        $editId = (int) ($_GET['id'] ?? 0);
        $expenseFilters = [
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
            'branch_id' => (int) ($_GET['branch_id'] ?? 0),
            'expense_category_id' => (int) ($_GET['expense_category_id'] ?? 0),
            'currency' => mb_strtoupper(trim((string) ($_GET['currency'] ?? ''))),
        ];

        $categories = $this->decorateMasterRows($repository->categoryRows());
        $expenses = array_map(static function (array $row): array {
            $row['category'] = trim((string) ($row['category_name'] ?? ''));
            $row['branch'] = trim((string) ($row['branch_name'] ?? ''));
            $row['amount_display'] = (string) ($row['currency'] ?? 'PKR') . ' ' . number_format((float) ($row['amount'] ?? 0), 2);
            $row['status_label'] = ucwords(str_replace('_', ' ', (string) ($row['expense_status'] ?? 'posted')));
            $row['payment_method_display'] = ucwords(str_replace('_', ' ', (string) ($row['payment_method_label'] ?? $row['payment_method'] ?? '')));
            $row['entered_by'] = (string) ($row['entered_by_name'] ?? 'User');
            $row['system_label'] = 'Entry';
            $row['is_system'] = 0;

            return $row;
        }, $repository->expenseRows($accessibleBranchIds, $expenseFilters));

        $branchRows = array_values(array_filter(
            $masterData->rows('branches'),
            static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $accessibleBranchIds, true)
        ));
        $branchOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) $row['id'],
                'label' => trim((string) $row['name'] . (! empty($row['city']) ? ' - ' . $row['city'] : '')),
            ],
            $branchRows
        );
        $categoryOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) $row['id'],
                'label' => (string) $row['name'],
            ],
            array_values(array_filter($categories, static fn (array $row): bool => ((int) ($row['is_active'] ?? 0)) === 1))
        );
        $currencyOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) $row['code'],
                'label' => (string) $row['code'] . ' - ' . (string) $row['name'],
            ],
            $masterData->rows('currencies')
        );
        $paymentMethodOptions = array_map(
            static fn (array $row): array => [
                'value' => (string) $row['code'],
                'label' => (string) $row['name'],
            ],
            $masterData->rows('payment_methods')
        );
        $expenseEditRecord = $editRegister === 'business_expenses' ? $repository->findExpense($editId, $accessibleBranchIds) : null;

        return $this->view('control/expenses', [
            'title' => 'Business Expenses',
            'user' => Auth::user(),
            'accessibleBranchIds' => $accessibleBranchIds,
            'expenseRows' => $expenses,
            'expenseFilters' => $expenseFilters,
            'expenseCategoryOptions' => $categoryOptions,
            'expenseBranchOptions' => $branchOptions,
            'expenseCurrencyOptions' => $currencyOptions,
            'expensePaymentMethodOptions' => $paymentMethodOptions,
            'expenseEditRecord' => $expenseEditRecord,
            'panels' => [
                $this->masterPanel(
                    'expense_categories',
                    'Expense Categories',
                    'Maintain reusable admin expense categories without mixing them into booking/service costing.',
                    $categories,
                    $editRegister === 'expense_categories' ? $repository->findCategory($editId) : null,
                    [
                        ['key' => 'code', 'label' => 'Code'],
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'sort_order', 'label' => 'Sort'],
                        ['key' => 'status_label', 'label' => 'Status'],
                    ],
                    [
                        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 50, 'required' => true],
                        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 120, 'required' => true],
                        ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'min' => 0],
                        ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
                    ]
                ),
                $this->masterPanel(
                    'business_expenses',
                    'Business Expenses',
                    'Record branch/admin expenses outside booking workspace so net profit can be measured separately from gross profit.',
                    $expenses,
                    $expenseEditRecord,
                    [
                        ['key' => 'expense_date', 'label' => 'Date'],
                        ['key' => 'branch', 'label' => 'Branch'],
                        ['key' => 'category', 'label' => 'Category'],
                        ['key' => 'title', 'label' => 'Title'],
                        ['key' => 'amount_display', 'label' => 'Amount'],
                        ['key' => 'payment_method_display', 'label' => 'Payment'],
                        ['key' => 'paid_to_name', 'label' => 'Paid To'],
                        ['key' => 'status_label', 'label' => 'Status'],
                        ['key' => 'entered_by', 'label' => 'Entered By'],
                    ],
                    [
                        ['name' => 'expense_date', 'label' => 'Expense Date', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d')],
                        ['name' => 'branch_id', 'label' => 'Branch', 'type' => 'select', 'required' => true, 'options' => $branchOptions],
                        ['name' => 'expense_category_id', 'label' => 'Expense Category', 'type' => 'select', 'required' => true, 'options' => $categoryOptions],
                        ['name' => 'title', 'label' => 'Expense Title', 'type' => 'text', 'maxlength' => 190, 'required' => true],
                        ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'min' => 0.01],
                        ['name' => 'currency', 'label' => 'Currency', 'type' => 'select', 'required' => true, 'options' => $currencyOptions],
                        ['name' => 'payment_method', 'label' => 'Payment Method', 'type' => 'select', 'required' => true, 'options' => $paymentMethodOptions],
                        ['name' => 'paid_to_name', 'label' => 'Paid To', 'type' => 'text', 'maxlength' => 190],
                        ['name' => 'reference_number', 'label' => 'Reference No.', 'type' => 'text', 'maxlength' => 120],
                        ['name' => 'expense_status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => $this->choiceOptions([
                            'posted' => 'Posted',
                            'active' => 'Active',
                        ])],
                        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'maxlength' => 4000, 'stack' => true],
                    ]
                ),
            ],
        ]);
    }

    public function saveExpenses(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $register = (string) ($_POST['register'] ?? '');
        $editId = (int) ($_POST['id'] ?? 0);

        try {
            $result = (new ExpenseAdminService($this->app))->save(
                $register,
                $_POST,
                $_FILES,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success(ucfirst(str_replace('_', ' ', $register)) . ' ' . $result['action'] . ' successfully.');
            $this->redirectToRegister('/expenses', $register);
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirectToRegister('/expenses', $register, $editId > 0 ? $editId : null);
        }
    }

    public function deleteExpenses(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        $register = (string) ($_POST['register'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $result = (new ExpenseAdminService($this->app))->delete(
                $register,
                $id,
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
            Flash::success($result['label'] . ' deleted successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirectToRegister('/expenses', $register);
    }

    public function downloadExpenseAttachment(): never
    {
        try {
            $download = (new ExpenseAdminService($this->app))->downloadAttachment(
                (int) ($_GET['attachment_id'] ?? 0),
                (int) Auth::id(),
                Authorization::accessibleBranchIds()
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/expenses');
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

    private function masterPanel(
        string $register,
        string $title,
        string $description,
        array $rows,
        ?array $editRecord,
        array $columns,
        array $fields
    ): array {
        return [
            'register' => $register,
            'title' => $title,
            'description' => $description,
            'rows' => $rows,
            'editRecord' => $editRecord,
            'columns' => $columns,
            'fields' => $fields,
        ];
    }

    private function decorateMasterRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $row['status_label'] = ((int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive');
            $row['system_label'] = ((int) ($row['is_system'] ?? 0) === 1 ? 'System' : 'Custom');

            return $row;
        }, $rows);
    }

    private function requestedRegister(array $allowed): ?string
    {
        $register = (string) ($_GET['edit'] ?? '');

        return in_array($register, $allowed, true) ? $register : null;
    }

    private function redirectToRegister(string $path, string $register, ?int $editId = null): never
    {
        $location = url($path);

        if ($editId !== null && $editId > 0) {
            $location .= '?edit=' . rawurlencode($register) . '&id=' . $editId;
        }

        $location .= '#register-' . rawurlencode($register);

        header('Location: ' . $location);
        exit;
    }

    private function statusOptions(): array
    {
        return $this->choiceOptions([
            '1' => 'Active',
            '0' => 'Inactive',
        ]);
    }

    private function choiceOptions(array $choices): array
    {
        $options = [];

        foreach ($choices as $value => $label) {
            $options[] = [
                'value' => (string) $value,
                'label' => $label,
            ];
        }

        return $options;
    }

}
