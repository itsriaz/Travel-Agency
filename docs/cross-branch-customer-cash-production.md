# Cross-branch customer cash ledger rollout

## What changes

The booking branch remains the operational owner of its customer receipt and allocation. The selected cash/bank account remains the owner of the money. A Noble Route booking paid into `Cash-SWT-PKR` therefore appears as cash in the Swat account, with the Noble booking branch shown in the detailed row. The cash position, account balance, and cash-flow read paths no longer discard a cash line merely because its journal header has a different branch. No existing receipt, allocation, journal, or bank/cash account is rewritten by this rollout.

The reviewed historical examples are `RCPT-000873` (BK-000819, PKR 40,000), `RCPT-000926` (BK-000819, PKR 45,000), and `RCPT-000938` (BK-000732, PKR 86,000). Their selected cash account is Swat `Cash-SWT-PKR`; combined cash in is PKR 171,000.

## Local verification

Run from the project root before uploading:

```powershell
C:\xampp\php\php.exe tests/cross_branch_customer_cash_ledger_regression.php
C:\xampp\php\php.exe scripts/verify_cross_branch_customer_cash_truth.php
C:\xampp\php\php.exe tests/treasury_receipt_balance_dedup_regression.php
C:\xampp\php\php.exe tests/cash_bank_account_history_regression.php
C:\xampp\php\php.exe tests/booking_branch_cash_correction_regression.php
C:\xampp\php\php.exe tests/booking_branch_bank_receipt_correction_regression.php
C:\xampp\php\php.exe tests/supplier_payment_same_currency_cross_branch_funding_regression.php
C:\xampp\php\php.exe tests/supplier_payment_cross_currency_cross_branch_regression.php
```

The regression fixtures are transaction-wrapped and rolled back. The verifier is SELECT-only.

## Production sequence

1. Stage these tested files for upload, preserving paths: `app/Repositories/AccountingRepository.php`, `app/Repositories/ReportRepository.php`, `app/Repositories/TreasuryRepository.php`, `tests/workspace_payment_readiness.php`, `tests/cross_branch_customer_cash_ledger_regression.php`, and `scripts/verify_cross_branch_customer_cash_truth.php`. The repository files contain other current work; deploy the intended tested versions together rather than cherry-picking lines from an older production copy.
2. From `~/domains/imdadint.ae/public_html`, make a database backup and retain the current deployed application files before replacing anything:

```bash
DB_HOST=127.0.0.1 php scripts/backup_database.php --label=before-cross-branch-cash --output-dir="$HOME/travel-agency-db-backups"
```

3. Upload the staged files together. Then syntax-check and run the SELECT-only historical verifier:

```bash
php -l app/Repositories/AccountingRepository.php
php -l app/Repositories/ReportRepository.php
php -l app/Repositories/TreasuryRepository.php
php -l scripts/verify_cross_branch_customer_cash_truth.php
DB_HOST=127.0.0.1 php scripts/verify_cross_branch_customer_cash_truth.php
```

4. Expect `"status": "PASS"`, three Swat ledger rows, zero Noble cash-ledger duplicates, and `"total_cash_in_pkr": 171000`. If it fails, stop and inspect the reported receipt; do not force a data repair. No migration or `--apply` command is part of this fix.
5. In the GUI, filter the detailed cash/bank ledger to Swat `Cash-SWT-PKR`, PKR, and the receipt dates. Confirm all three receipts appear once, with their Noble booking branch indicated. Check BK-000819 and BK-000732 customer payment histories remain unchanged and their existing allocations are intact. A Noble-only cash-account filter must not show those three Swat cash movements.
6. Keep the file-deployment snapshot for rollback. If verification fails after deployment, restore the prior application files as a set and investigate. Do not restore the database just to undo this read-path change; the rollout does not write historical financial data.

## Separate historical reconciliation item

The local `Cash-SWT-PKR` account also has PKR 23,100,000 of older posted treasury transfers with no linked journal entries. `currentBalanceForAccountId` includes those transfers, but the journal-based cash/bank position and detailed journal ledger do not. This is not caused by the three cross-branch receipts and is not repaired by this rollout. Do not interpret a full account-balance-versus-journal-position difference of PKR 23,100,000 as failure of the PKR 171,000 receipt check; investigate the transfer-history reconciliation separately before changing historical entries.

Follow-up: `docs/legacy-cross-currency-treasury-production.md` records the guarded repair. It has been applied and verified in the local database; production still requires its own backup, preview, rehearsal, and apply sequence.

## Controls

- Files changed: three repositories, one readiness check, one rollback-only regression, one read-only verifier, and this document.
- SQL/schema changes: none. Existing receipt and journal rows are not mutated.
- Security: no new public endpoint; the verifier uses prepared statements for receipt lookup and prints only operational IDs and amounts. Run it from the authenticated server shell, not over HTTP.
- Done criteria: all syntax checks and targeted tests pass; production verifier reports all three existing receipts exactly once under Swat cash; customer allocation history is unchanged.
