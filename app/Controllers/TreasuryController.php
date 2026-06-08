<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Authorization;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Repositories\TreasuryRepository;
use RuntimeException;

final class TreasuryController extends BaseController
{
    public function accounts(): string
    {
        $repository = new TreasuryRepository($this->app);
        $accessibleBranchIds = Authorization::accessibleBranchIds();
        $returnTo = $this->sanitizeReturnTo((string) ($_GET['return_to'] ?? ''));

        $editId = (int) ($_GET['id'] ?? 0);
        $editAccount = $editId > 0
            ? $repository->findAccount($editId, $accessibleBranchIds)
            : null;

        return $this->view('treasury/accounts', [
            'title' => 'Treasury Accounts',
            'accounts' => $repository->accounts($accessibleBranchIds),
            'transferAccounts' => $repository->transferAccounts($accessibleBranchIds),
            'recentTransfers' => $repository->recentTransfers($accessibleBranchIds),
            'branches' => $repository->branches($accessibleBranchIds),
            'currencies' => $repository->currencies(),
            'ledgerAccounts' => $repository->assetLedgerAccounts(),
            'editAccount' => $editAccount,
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

    public function saveTransfer(): never
    {
        Csrf::verifyOrFail($_POST['_token'] ?? null);

        try {
            $repository = new TreasuryRepository($this->app);
            $repository->saveTransfer(
                array_merge($_POST, [
                    'created_by_user_id' => Auth::id(),
                ]),
                Authorization::accessibleBranchIds()
            );

            Flash::success('Treasury transfer posted successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/treasury/accounts');
        }

        $this->redirect('/treasury/accounts');
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
