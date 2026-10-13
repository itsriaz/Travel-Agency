# Supplier cost currency reconciliation

## Scope

The refreshed local database confirms five whole-unit wrong-currency supplier cost/payable journal cases: BK-000002, BK-000529, BK-000606, BK-000636, and BK-000670. BK-001044 in the earlier local test was a different PKR 1,000 advance-backed booking; the refreshed database's BK-001044 has a PKR 81,585 open supplier obligation and is not in this repair. BK-000527 also had a historical AED 725.01 cancellation journal that must be reversed exactly to reach zero. These repairs do not change supplier payments, advance applications, cash, bank, service prices, or obligations.

The original source journals remain as audit history. The repair posts balanced corrective journals and records an audit event. Future repeated cost-currency edits now post incremental old/new currency changes without re-reversing prior edits. A preflight blocks a currency change when saved cost journals already disagree with the supplier obligation. The supplier-advance reconciler also now respects existing payment allocations, so a paid obligation does not consume advance a second time.

## Production sequence

Upload the changed `app/Repositories/AccountingRepository.php`, `app/Repositories/ReportRepository.php`, `app/Repositories/SupplierRepository.php`, `app/Services/CommercialObligationSyncService.php`, `app/Services/ServiceWorkspaceService.php`, `app/Services/SupplierLedgerService.php`, `scripts/reconcile_supplier_cost_currency_journals.php`, and `scripts/reconcile_bk000527_supplier_currency_journals.php`. These are not database migrations. Verify production code versions before proceeding. Use the production CLI from `~/domains/imdadint.ae/public_html`.

1. Back up the database: `DB_HOST=127.0.0.1 php scripts/backup_database.php --label=before-supplier-cost-journals --output-dir="$HOME/travel-agency-db-backups"`. Confirm the SQL file and manifest exist.
2. Preview the five-booking script: `DB_HOST=127.0.0.1 php scripts/reconcile_supplier_cost_currency_journals.php`. Check all five identities and amounts. If the script refuses due to different production data, stop; do not override its guards.
3. Rehearse using the **production** fingerprint printed by step 2: `DB_HOST=127.0.0.1 php scripts/reconcile_supplier_cost_currency_journals.php --rehearse --actor=10 --fingerprint=PRODUCTION_FINGERPRINT`.
4. Apply only if rehearsal passes: repeat step 3 with `--apply` instead of `--rehearse`. Re-run the preview command; it must say `ALREADY APPLIED`.
5. Preview BK-000527 separately: `DB_HOST=127.0.0.1 php scripts/reconcile_bk000527_supplier_currency_journals.php`. Confirm the AED 725.01 exact reversal and PKR 35,700 final supplier cost.
6. Rehearse BK-000527 with `--rehearse --actor=10 --fingerprint=ITS_PRODUCTION_FINGERPRINT`, then apply with `--apply` and the same fingerprint. Re-run preview and require `ALREADY APPLIED`.
7. In the GUI, inspect each repaired booking's supplier ledger. Paid bookings should close at zero. BK-000529 should show PKR 377,483 payable; BK-000670 should show PKR 210,317 payable. BK-000527 should have zero AED supplier balance and zero PKR payable after its PKR 35,700 payment. BK-001044 is outside this repair and must not be presented as an advance-backed case.

Never reuse a local fingerprint on production. Neither script is safe to force through a failed preflight. If production differs, collect the preview error and re-audit that booking before changing it.

## Remaining separate review

The broad local scan also flagged BK-000696 for a PKR 2,432 `SERVICE_COST` journal despite an AED supplier cost, and BK-000637 for a supplier-control discrepancy. They were not part of this repair and should not be changed by these scripts. Their operational history requires separate review.
