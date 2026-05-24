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

        $editId = (int) ($_GET['id'] ?? 0);
        $editAccount = $editId > 0
            ? $repository->findAccount($editId, $accessibleBranchIds)
            : null;

        return $this->view('treasury/accounts', [
            'title' => 'Treasury Accounts',
            'accounts' => $repository->accounts($accessibleBranchIds),
            'branches' => $repository->branches($accessibleBranchIds),
            'currencies' => $repository->currencies(),
            'ledgerAccounts' => $repository->assetLedgerAccounts(),
            'editAccount' => $editAccount,
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

        try {
            $repository = new TreasuryRepository($this->app);
            $repository->saveAccount(
                array_merge($_POST, [
                    'created_by_user_id' => Auth::id(),
                ]),
                Authorization::accessibleBranchIds()
            );

            Flash::success('Treasury account saved successfully.');
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
        }

        $this->redirect('/treasury/accounts');
    }
}
