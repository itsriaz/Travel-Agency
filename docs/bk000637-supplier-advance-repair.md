# BK-000637 supplier advance over-application

## What happened

The original AED 545 supplier cost was fully covered by an allocation from supplier payment
`SPAY-000152`. Payment `SPAY-000154` later produced AED 529 of supplier advance. An invoice
edit raised the supplier cost from AED 545 to AED 549, leaving only AED 4 to cover. The old
auto-application calculation ignored the existing AED 545 payment and consumed the entire
AED 529 advance. The booking therefore had AED 1,074 of settlement against AED 549 of cost.

The repair keeps the AED 545 payment, reduces the booking's advance application to AED 4,
returns AED 525 to the reusable supplier advance, and posts an AED 525 balancing supplier
journal. It does not create or reverse cash/bank movements or alter supplier payment records.
The original entries remain visible for audit. No schema migration is required.

## Verify production first (read-only)

Upload only `scripts/reconcile_bk000637_supplier_overapplication.php`. From the production
`public_html` directory run:

```sh
cd ~/domains/imdadint.ae/public_html
php -l scripts/reconcile_bk000637_supplier_overapplication.php
DB_HOST=127.0.0.1 php scripts/reconcile_bk000637_supplier_overapplication.php
```

The output must say `"mode": "preview"` and `"inspection_only": true`. Compare these figures
with the booking, supplier payment, and supplier advance screens:

- Original supplier cost AED 545; `SPAY-000152` allocated AED 545 to this booking.
- Edited supplier cost AED 549, leaving AED 4 to cover.
- `SPAY-000154` created AED 529 of supplier advance; all AED 529 was applied to this booking.
- Excess AED 525; current supplier payable appears zero but the supplier control journal is
  AED 525 over-applied.

The preview also prints the production source IDs and fingerprint. If it fails or any figure
differs, **stop and inspect production history**. Do not reuse the local fingerprint or change
database rows manually.

## Repair production after the preview agrees

The repair requires the corrected `app/Repositories/SupplierRepository.php`, in particular
the version whose `autoApplyAvailableAdvanceToObligation` subtracts existing supplier payment
allocations when calculating advance use. Upload that file if production does not yet have it.
The script will roll back if the repository does not release exactly AED 525.

Back up the production database and confirm the files exist:

```sh
DB_HOST=127.0.0.1 php scripts/backup_database.php --label=before-bk000637-advance --output-dir="$HOME/travel-agency-db-backups"
ls -lh "$HOME/travel-agency-db-backups"/*before-bk000637-advance*
```

Use the **fingerprint from the production preview** below. Actor `10` must be an active,
authorized production user; substitute the correct ID if necessary.

```sh
DB_HOST=127.0.0.1 php scripts/reconcile_bk000637_supplier_overapplication.php --rehearse --actor=10 --fingerprint=PRODUCTION_FINGERPRINT
DB_HOST=127.0.0.1 php scripts/reconcile_bk000637_supplier_overapplication.php
DB_HOST=127.0.0.1 php scripts/reconcile_bk000637_supplier_overapplication.php --apply --actor=10 --fingerprint=PRODUCTION_FINGERPRINT
DB_HOST=127.0.0.1 php scripts/reconcile_bk000637_supplier_overapplication.php
```

Require `rehearsed-and-rolled-back` after the first command, the **same fingerprint** in the
second preview, `applied` after the third command, and `ALREADY APPLIED` after the last.
If any step fails, stop and share its output. Never force a failed fingerprint/preflight.

## Manual checks after application

- BK-000637 supplier cost is AED 549; payment allocation AED 545; advance applied AED 4;
  supplier payable AED 0.
- The supplier advance originating from `SPAY-000154` has AED 525 available for future use.
- Supplier journal AP control for BK-000637 is AED 0; SERVICE_COST is AED 549.
- Cash/bank reports and `SPAY-000152`/`SPAY-000154` payment records are unchanged.
- The audit log contains `supplier.bk000637_advance_overapplication_reconciled`.
