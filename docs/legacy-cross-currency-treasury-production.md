# Historical AED-to-PKR treasury journal repair

## Why this exists

Transfers #5, #6, and #9 are genuine posted transfers from Noble `Cash-UAE-AED` (account 34) to Swat `Cash-SWT-PKR` (account 33). They total AED 300,000 out and PKR 23,100,000 in at 77 PKR per AED. The old save path recorded these transfer headers but did not create journal entries when branch/currency differed. Live cash balances counted the headers; the detailed cash ledger and journal-based cash position did not. The current save path creates both journal legs, and now verifies them before commit.

The one-time repair creates and links **only the missing journals** to the three original transfer rows. It creates no receipt, payment, or new cash transfer. Linking each journal removes that transfer from the legacy balance fallback, so the live AED and PKR cash balances must remain exactly unchanged.

## Local checks

```powershell
C:\xampp\php\php.exe -l app/Repositories/AccountingRepository.php
C:\xampp\php\php.exe -l app/Repositories/TreasuryRepository.php
C:\xampp\php\php.exe -l scripts/reconcile_legacy_cross_currency_treasury_transfers.php
C:\xampp\php\php.exe -l scripts/audit_treasury_transfer_journals.php
C:\xampp\php\php.exe scripts/reconcile_legacy_cross_currency_treasury_transfers.php
C:\xampp\php\php.exe scripts/audit_treasury_transfer_journals.php
C:\xampp\php\php.exe tests/treasury_cross_dimension_journal_regression.php
C:\xampp\php\php.exe tests/treasury_transfer_regression.php
```

Local repair was backed up, rehearsed with rollback, applied, and verified on 10 October 2026. The repaired transfer journals are #13421–#13426 in this local database only; production will assign different IDs. The live balances remained AED 108,631 in Noble cash and PKR 2,629,353.01 in Swat cash.

## Production sequence

1. Before upload, retain a copy of the currently deployed application files and create a database backup from `~/domains/imdadint.ae/public_html`:

```bash
DB_HOST=127.0.0.1 php scripts/backup_database.php --label=before-legacy-treasury-journals --output-dir="$HOME/travel-agency-db-backups"
```

2. Upload the tested `app/Repositories/AccountingRepository.php`, `app/Repositories/TreasuryRepository.php`, `app/Repositories/ReportRepository.php`, `scripts/reconcile_legacy_cross_currency_treasury_transfers.php`, and `scripts/audit_treasury_transfer_journals.php` together. The report repository must include the prior cross-branch cash-ledger fix. No new migration is required.
3. Syntax-check, then run the read-only audit and preview:

```bash
php -l app/Repositories/AccountingRepository.php
php -l app/Repositories/TreasuryRepository.php
php -l app/Repositories/ReportRepository.php
php -l scripts/reconcile_legacy_cross_currency_treasury_transfers.php
php -l scripts/audit_treasury_transfer_journals.php
DB_HOST=127.0.0.1 php scripts/audit_treasury_transfer_journals.php
DB_HOST=127.0.0.1 php scripts/reconcile_legacy_cross_currency_treasury_transfers.php
```

4. Before repair, the audit may report **only** transfers #5, #6, and #9 as missing journals. The preview must show AED 300,000 missing out, PKR 23,100,000 missing in, and unchanged live balances. If it fails, reports additional IDs, or shows different facts, stop. Do not guess a fingerprint or run `--apply`.
5. Use the **fingerprint from that production preview**, not the local fingerprint. Replace `FINGERPRINT_FROM_PRODUCTION_PREVIEW` below with its 64-character value. Actor 10 must be an active production **Super Admin**; otherwise use another active Super Admin user ID. Rehearse first:

```bash
DB_HOST=127.0.0.1 php scripts/reconcile_legacy_cross_currency_treasury_transfers.php --rehearse --actor=10 --fingerprint=FINGERPRINT_FROM_PRODUCTION_PREVIEW
```

6. Confirm `"mode": "rehearsed-and-rolled-back"` and both live balances unchanged. Re-run the preview; it must return the same fingerprint. Only then apply:

```bash
DB_HOST=127.0.0.1 php scripts/reconcile_legacy_cross_currency_treasury_transfers.php --apply --actor=10 --fingerprint=FINGERPRINT_FROM_PRODUCTION_PREVIEW
```

7. Verify repeat-run safety and all posted transfers:

```bash
DB_HOST=127.0.0.1 php scripts/reconcile_legacy_cross_currency_treasury_transfers.php
DB_HOST=127.0.0.1 php scripts/audit_treasury_transfer_journals.php
```

Expect `ALREADY APPLIED` and audit `PASS`. In the GUI, inspect the Noble AED cash detailed ledger and Swat PKR cash detailed ledger for the three original dates. Each transfer must appear once on each side; compare the account balances with the pre-apply preview. If verification fails, stop using transfer edit/void for these rows and investigate the backup and journal IDs before further action. Do not restore the entire production database without a separate recovery decision.

## Controls and done criteria

- Files changed for this task: `AccountingRepository.php`, `TreasuryRepository.php`, the two scripts, the cross-dimension regression, and this guide. `ReportRepository.php` is a deployment dependency from the prior cash-ledger fix.
- SQL/schema changes: none. The apply script links six new balanced journal entries to the existing three transfers; no transfer amounts or cash accounts are changed.
- Security: CLI-only repair, active Super Admin actor ID, exact current fingerprint, prepared statements, transaction rollback on any failed assertion, and audit log entries. The audit script is read-only.
- Done: source/destination live cash balances unchanged; journal-based positions equal those balances; detailed cash ledgers each show three original-date movements; audit passes; repeat run performs no writes; GUI transfer edit/void can now use verified journal pairs.
