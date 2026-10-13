# BK-000570 cancellation resync and cross-branch receipt review

## Evidence and intended result

BK-000570's posted cancellation event records PKR 342 as the final customer charge and
PKR 342 as the retained supplier cost. No customer receipt exists. The existing supplier
payment `SPAY-000124` still allocates PKR 342 to the booking. A later ordinary service edit
recreated the original PKR 109,000 customer invoice and PKR 102,829 supplier obligation,
then incorrectly consumed PKR 67,190 from advance `SPAY-000138` and PKR 35,639 from
`SPAY-000130`. The repair restores PKR 342 customer outstanding and the PKR 342 paid
supplier obligation, releases those two advance applications, and posts current-dated
reversals of the three erroneous resync journals. It neither invents customer payment nor
changes any original cash/bank movement or another booking's advance application.

`RCPT-000873` (PKR 40,000), `RCPT-000926` (PKR 45,000), and `RCPT-000938` (PKR 86,000)
are valid Noble booking receipts explicitly recorded to Swat `Cash-SWT-PKR` account 33.
They settle BK-000819 and BK-000732. The booking branch owns the customer invoice; the
selected treasury account owns the cash. Do not relabel, reverse, or transfer these receipts.

## Production preflight (read-only)

Deploy the reviewed script and the matching tested `SupplierRepository.php` and
`AccountingRepository.php` versions. Do not replace other files from a dirty local
working tree without reviewing them. From production `public_html`:

```sh
cd ~/domains/imdadint.ae/public_html
php -l scripts/reconcile_bk000570_cancellation_resync.php
php -l scripts/verify_cross_branch_customer_cash_truth.php
DB_HOST=127.0.0.1 php scripts/reconcile_bk000570_cancellation_resync.php
DB_HOST=127.0.0.1 php scripts/verify_cross_branch_customer_cash_truth.php
```

Require a BK-000570 `preview` with `inspection_only: true`, PKR 109,000 currently open,
PKR 342 after repair, exactly three erroneous resync journals, and PKR 102,829 returned
to the two named supplier pools. The receipt verifier must report `PASS` and PKR 171,000
cash in to Swat account 33. If a production fact differs, stop. Do not use the local
fingerprint or edit rows by hand.

## Guarded production repair

Confirm actor 10 is an active authorized production user, or substitute the correct ID.
Back up the database and retain the backup outside `public_html`:

```sh
DB_HOST=127.0.0.1 php scripts/backup_database.php --label=before-bk000570 --output-dir="$HOME/travel-agency-db-backups"
ls -lh "$HOME/travel-agency-db-backups"/*before-bk000570*
```

Use the **fingerprint printed by the production preview** in each command:

```sh
DB_HOST=127.0.0.1 php scripts/reconcile_bk000570_cancellation_resync.php --rehearse --actor=10 --fingerprint=PRODUCTION_FINGERPRINT
DB_HOST=127.0.0.1 php scripts/reconcile_bk000570_cancellation_resync.php
DB_HOST=127.0.0.1 php scripts/reconcile_bk000570_cancellation_resync.php --apply --actor=10 --fingerprint=PRODUCTION_FINGERPRINT
DB_HOST=127.0.0.1 php scripts/reconcile_bk000570_cancellation_resync.php
DB_HOST=127.0.0.1 php scripts/audit_settled_cancellation_receivables.php
DB_HOST=127.0.0.1 php scripts/verify_cross_branch_customer_cash_truth.php
```

Require `rehearsed-and-rolled-back`, unchanged fingerprint, `applied`, then `ALREADY
APPLIED`. The cancellation audit should no longer flag BK-000570; its separate legacy
BK-000038 notice is not part of this repair. Stop on any failure rather than forcing
the script or changing balances directly.

## Manual acceptance and controls

- BK-000570 shows PKR 342 customer outstanding with no customer receipt.
- Its supplier obligation is PKR 342, fully covered by the existing PKR 342 allocation from `SPAY-000124`.
- `SPAY-000138` has PKR 67,190 available and `SPAY-000130` has PKR 35,639 available, unless later legitimate activity occurred after repair; in every case each pool's available plus applied must equal its deposit.
- The three current-dated reversal journals offset the erroneous resync journals; the original cancellation and supplier cash journals remain visible.
- The audit log contains `historical.bk000570_cancellation_resync_reversed` once.
- BK-000819 and BK-000732 remain paid, and the three named receipts each appear once in Swat PKR cash, not Noble cash.

No schema change is required. The repair is transactional, fingerprint-guarded,
rollback-rehearsed, idempotent, and limited to this booking's evidenced history.
