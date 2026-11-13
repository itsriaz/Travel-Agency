<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Authorization;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Repositories\MasterDataRepository;
use App\Repositories\ReportRepository;
use App\Repositories\TreasuryRepository;
use App\Services\DirectMoneyInOutSummary;
use RuntimeException;

final class TreasuryController extends BaseController
{
    public function accounts(): string
    {
        $repository = new TreasuryRepository($this->app);
        $accessibleBranchIds = array_values(array_map('intval', Authorization::accessibleBranchIds()));
        $returnTo = $this->sanitizeReturnTo((string) ($_GET['return_to'] ?? ''));

        $editId = (int) ($_GET['id'] ?? 0);
        $editAccount = $editId > 0
            ? $repository->findAccount($editId, $accessibleBranchIds)
            : null;
        $editTransactionId = (int) ($_GET['edit_transaction_id'] ?? 0);
        $editTransaction = $editTransactionId > 0
            ? $repository->findTransactionForCorrection($editTransactionId, $accessibleBranchIds)
            : null;

        $historyBranchId = (int) ($_GET['history_branch_id'] ?? 0);
        if ($historyBranchId > 0 && ! in_array($historyBranchId, $accessibleBranchIds, true)) {
            $historyBranchId = 0;
        }
        $historyScope = $historyBranchId > 0 ? [$historyBranchId] : $accessibleBranchIds;
        $historyDate = static function (mixed $value): string {
            $date = trim((string) $value);
            if ($date === '') {
                return '';
            }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : '';
        };
        $historyCurrency = strtoupper(trim((string) ($_GET['history_currency'] ?? '')));
        if (! in_array($historyCurrency, ['', 'PKR', 'AED', 'USD'], true)) {
            $historyCurrency = '';
        }
        $historyDirection = (string) ($_GET['history_direction'] ?? 'all');
        if (! in_array($historyDirection, ['all', 'in', 'out'], true)) {
            $historyDirection = 'all';
        }
        $historyFilters = [
            'branch_id' => $historyBranchId,
            'date_from' => $historyDate($_GET['history_date_from'] ?? ''),
            'date_to' => $historyDate($_GET['history_date_to'] ?? ''),
            'currency' => $historyCurrency,
            'direction' => $historyDirection,
            'account_id' => max(0, (int) ($_GET['history_account_id'] ?? 0)),
            'holder_id' => max(-1, (int) ($_GET['history_holder_id'] ?? 0)),
        ];
        $historyRows = (new ReportRepository($this->app))->directMoneyInOutLedger(
            $historyScope,
            $historyFilters['date_from'] ?: null,
            $historyFilters['date_to'] ?: null,
            $historyFilters['account_id'] ?: null,
            $historyDirection,
            'all',
            $historyFilters['holder_id'] ?: null,
            $historyCurrency
        );

        return $this->view('treasury/accounts', [
            'title' => 'Treasury Accounts',
            'accounts' => $repository->accounts($accessibleBranchIds),
            'transferAccounts' => $repository->transferAccounts($accessibleBranchIds),
            'recentDirectEntries' => $repository->recentTransfers($historyScope, 20, 'direct', $historyFilters),
            'directHistoryFilters' => $historyFilters,
            'directHistoryTotals' => DirectMoneyInOutSummary::byCurrency($historyRows),
            'recentInternalTransfers' => $repository->recentTransfers($accessibleBranchIds, 20, 'transfer'),
            'branches' => $repository->branches($accessibleBranchIds),
            'currencies' => $repository->currencies(),
            'ledgerAccounts' => $repository->assetLedgerAccounts(),
            'businessSourceOptions' => (new MasterDataRepository($this->app))->activeRows('business_sources'),
            'historyBusinessSourceOptions' => (new MasterDataRepository($this->app))->rows('business_sources'),
            'editAccount' => $editAccount,
            'editTransaction' => $editTransaction,
            'returnTo' => $returnTo,
            'accountTypes' => [
                'cash' => 'Cash Counter',
                'bank' => 'Bank Account',
                'wallet' => 'Wallet / Mobile Account',
                'bank_clearing' => 'Bank Clearing',
                'card_clearing' => 'Card Clearing',
            ],
        ]);
    }

    public function saveAccount(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->sanitizeReturnTo((string) ($_POST['return_to'] ?? ''));

        try {
            $repository = new TreasuryRepository($this->app);
            $repository->saveAccount(
                array_merge($_POST, [
                    'created_by_user_id' => Auth::id(),
                ]),
                Authorization::accessibleBranchIds()
            );

            Flash::success('Treasury account saved successfully.');
            if ($returnTo !== '') {
                $this->closeAndReturn($returnTo);
            }
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $query = $returnTo !== '' ? '?return_to=' . rawurlencode($returnTo) : '';
            $this->redirect('/treasury/accounts' . $query);
        }

        $this->redirect($returnTo !== '' ? $returnTo : '/treasury/accounts');
    }

    public function saveDirectEntry(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->sanitizeReturnTo((string) ($_POST['return_to'] ?? ''));
        $correctionTransactionId = (int) ($_POST['correction_transaction_id'] ?? 0);

        try {
            $repository = new TreasuryRepository($this->app);
            $payload = array_merge($_POST, [
                'created_by_user_id' => Auth::id(),
            ]);
            if ($correctionTransactionId > 0) {
                $repository->correctDirectEntry(
                    $payload,
                    Auth::id() ?? 0,
                    Authorization::accessibleBranchIds()
                );
                Flash::success('Treasury direct entry corrected successfully.');
            } else {
                $repository->saveDirectEntry(
                    $payload,
                    Authorization::accessibleBranchIds()
                );

                Flash::success('Direct treasury entry posted successfully.');
            }
            if ($returnTo !== '') {
                $this->redirect($returnTo);
            }
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            if ($correctionTransactionId > 0) {
                $query = http_build_query(array_filter([
                    'edit_transaction_id' => $correctionTransactionId,
                    'return_to' => $returnTo,
                ], static fn (mixed $value): bool => (string) $value !== ''));
                $this->redirect('/treasury/accounts' . ($query !== '' ? '?' . $query : ''));
            }
            $this->redirect($returnTo !== '' ? $returnTo : '/treasury/accounts');
        }

        $this->redirect($returnTo !== '' ? $returnTo : '/treasury/accounts');
    }

    public function saveTransfer(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);
        $returnTo = $this->sanitizeReturnTo((string) ($_POST['return_to'] ?? ''));
        $correctionTransactionId = (int) ($_POST['correction_transaction_id'] ?? 0);

        try {
            $repository = new TreasuryRepository($this->app);
            $payload = array_merge($_POST, [
                'created_by_user_id' => Auth::id(),
            ]);
            if ($correctionTransactionId > 0) {
                $repository->correctTransfer(
                    $payload,
                    Auth::id() ?? 0,
                    Authorization::accessibleBranchIds()
                );
                Flash::success('Treasury transfer corrected successfully.');
            } else {
                $repository->saveTransfer(
                    $payload,
                    Authorization::accessibleBranchIds()
                );

                Flash::success('Treasury transfer posted successfully.');
            }
            if ($returnTo !== '') {
                $this->redirect($returnTo);
            }
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            if ($correctionTransactionId > 0) {
                $query = http_build_query(array_filter([
                    'edit_transaction_id' => $correctionTransactionId,
                    'return_to' => $returnTo,
                ], static fn (mixed $value): bool => (string) $value !== ''));
                $this->redirect('/treasury/accounts' . ($query !== '' ? '?' . $query : ''));
            }
            $this->redirect($returnTo !== '' ? $returnTo : '/treasury/accounts');
        }

        $this->redirect($returnTo !== '' ? $returnTo : '/treasury/accounts');
    }

    public function voidTransfer(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $voidReason = trim((string) ($_POST['void_reason'] ?? ''));
            if ($voidReason === '') {
                throw new RuntimeException('Please enter a void reason for this treasury transfer.');
            }

            $repository = new TreasuryRepository($this->app);
            $repository->voidTransfer(
                (int) ($_POST['treasury_transaction_id'] ?? 0),
                $voidReason,
                Auth::id() ?? 0,
                Authorization::accessibleBranchIds()
            );

            Flash::success('Treasury transfer voided successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect('/treasury/accounts');
    }

    private function sanitizeReturnTo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return app_path($value);
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || ! str_starts_with($path, '/')) {
            return '';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return app_path($path) . $query . $fragment;
    }

    private function closeAndReturn(string $returnTo): never
    {
        $destination = url($returnTo);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Returning...</title></head><body>';
        echo '<script>';
        echo 'try {';
        echo 'if (window.opener && !window.opener.closed) {';
        echo 'window.opener.location.href = ' . json_encode($destination, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
        echo 'window.close();';
        echo '} else {';
        echo 'window.location.href = ' . json_encode($destination, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
        echo '}';
        echo '} catch (error) { window.location.href = ' . json_encode($destination, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '; }';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . e($destination) . '"></noscript>';
        echo '</body></html>';
        exit;
    }
}
