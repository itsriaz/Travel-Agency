# Refreshed Production Copy: Refund and Treasury Replay

Updated: 2026-10-03. Scope: local rehearsal and the completed production replay. The local starting database was a fresh production export; it was not expected to match the older local test snapshot. Historical hold points and commands below document the investigation; do not rerun completed `--apply` repairs.

## Production Execution (2026-10-03)

The operator reported successful guarded production applies for BK-000517, BK-000897, BK-000221, BK-000087, BK-000795, BK-000038 supplier source, and AL SAUDIA AED treasury funding. The final `php scripts/verify_refund_replay_20261002.php` passed every required assertion, including BK-000087/BK-000795 receipt reuse and BK-000221's AED 1,300 customer credit. `php scripts/verify_bk000038_supplier_truth.php` separately passed after the PKR supplier-credit repair.

For **AL SAUDIA AED only**, supplier payment IDs 175-196 total PKR 19,457,566. The production repair posted balanced journals 6796-6839 to place their existing cash outflow in Swat PKR treasury account 33, reducing its recorded balance from PKR 21,970,699.01 to PKR 2,513,133.01. The guarded apply reported supplier payments, allocations, obligations, advances, treasury transactions, bookings, and services unchanged. It did not create new payments or confirm the separate historical transfers.

Two verifier `[REVIEW]` notices remain, not failures: BK-000038's PKR 50,242 supplier return is retained credit rather than cash, and three unjournaled historical transfers total PKR 23,100,000 of destination entries. Do not backfill those transfers without real-world evidence. BK-000897's customer-payment attribution, the three cross-branch PKR customer receipts in the historical hold below, and BK-000038 profit presentation still require separate review. Do not infer that passing this focused replay is a full financial audit or approval of those unresolved records.

**BK-000795 workspace display follow-up, pending production upload:** After the complete refund undo, the cross-currency service has no active cancellation event. `ServiceWorkspaceService::serviceState()` dereferenced that missing event and the workspace controller silently substituted an empty service list. This can display zero service/invoice figures beside the correctly allocated AED 1,081 receipt. The local code now handles a missing cancellation event and redirects with an explicit error instead of rendering false zeroes if service loading fails again. `tests/complete_refund_undo_regression.php` passes with a rollback-only cross-currency visibility assertion. Upload the changed service and controller files, inspect production booking/service/receivable/obligation rows read-only, then hard-refresh BK-000795; do not rerun financial repairs or create another receipt.

**2026-10-03 correction:** The initial version of `scripts/reconcile_bk000038_supplier_truth.php` incorrectly used an AED 653 customer-side equivalent as a supplier exchange basis. Do not run that uploaded version. Its PKR 41,163.05 consumption was reversed locally. The replacement script is PKR-only on the supplier side: PKR 90,942 paid, PKR 40,700 retained, and PKR 50,242 supplier credit. AED 653 is customer-side reference only; AED 53 is the customer penalty and AED 600 was returned to the customer. Re-upload the replacement script and verifier, rehearse on a production copy, and use the guarded production procedure only after the deployment gate is met.

The local unapproved FX bridge was subsequently reversed with two balanced reversing journals and a required audit entry; supplier advance #75 now has PKR 50,242 available. The separate historical AED advance use remains flagged for source review rather than being attributed to BK-000038. This local reversal does not change customer refund or cash.

## Historical Deployment Hold (2026-10-02)

At this earlier gate, the operator applied the expected migration on production (72 applied, 0 pending), uploaded the application and repair scripts, and reran preflight. The root `.gitignore` was initially omitted but was then present; release-hygiene checks passed. This historical hold preceded the guarded production applies recorded above.

The workspace/payment audit reports three non-void PKR customer receipts for Noble Route bookings pointing to Swat PKR cash account 33: `RCPT-000873` and `RCPT-000926` for BK-000819 (PKR 40,000 and 45,000), and `RCPT-000938` for BK-000732 (PKR 86,000). The refreshed local production copy has the same rows, so the migration did not create them. Their customer-receipt journal headers use branch 2 while their cash-account line references Swat branch 1. Whether the physical cash was received in Swat is unconfirmed; do not relabel the receipts, move cash, or waive the branch check. Obtain source evidence and decide whether cross-branch bridge entries are required.

The original `tests/workspace_payment_readiness.php` printed the failure but returned `1` at top level, which PHP CLI treated as exit code 0; therefore the parent preflight incorrectly printed `Preflight passed`. The local test now uses `exit(1)`/`exit(0)` and preflight correctly fails. Upload this updated test file separately before repeating preflight. This correction changes no financial data. A verified current production SQL backup is still required before any further write.

## Local Result

The pre-write local SQL backup is `C:\xampp\mysql\recovery-backups\fresh-production-copy-before-replay-20261002\travel_agency_op.sql` (25,323,434 bytes; SHA-256 `CCE062F25D6A5EFBB789797C219291529EB3EC61A858235F2B38BC6B45694DDE`). It stays local and must **not** be uploaded over production.

Migration `20260923_000077_counterparty_offset_cross_dimension.php` is applied locally (72 applied, none pending). Guarded repairs were run against the refreshed copy:

| Case | Local result |
| --- | --- |
| BK-000517 | Market Fare AED 1,351, VAT Input 0; original AED 1,450 invoice and AED 99 margin preserved. Section A ordinary-edit lock is in the application code. |
| BK-000897 | Cancellation receivable/payable resynced. No customer receipt was invented or reassigned; the payment attribution remains for client review. |
| BK-000221 | Reviewed cancellation and confirmed AED 1,660 cash receipt restored. AED 360 applied to the final charge, AED 1,300 remains customer credit; supplier return PKR 101,500 is expected, not received. |
| BK-000087 / BK-000795 | AED 100 service-charge refund released, giving BK-000087 AED 1,280 historical entitlement. BK-000795's mistaken cancellation was undone and AED 1,081 of the existing receipt applied to its invoice. AED 199 remains on that receipt; **do not pay AED 1,280 again**. |
| BK-000038 | The AED 608 invoice restored by a later cost-currency correction was removed from customer receivables by reversing journal 4908. Local due/allocated/outstanding are AED 580/580/0; receipt AED 1,180 and refund AED 600 were not moved. The repair did not book a supplier cash receipt. |
| AL SAUDIA AED | 22 already-recorded supplier payments, PKR 19,457,566 total, now have balanced cross-branch source-cash journals. The selected Swat PKR cash is debited once; no supplier payment was recreated. |

Read-only `php scripts/verify_refund_replay_20261002.php` passes all guarded assertions. The targeted PHP and JavaScript regression suites and `php scripts/preflight_production.php` passed. These are automated checks, not authenticated browser acceptance or a full independent accounting audit.

**Still open:** BK-000038 has a posted supplier credit, not a PKR cash receipt. The local supplier-side source correction below establishes PKR 50,242 credit and PKR 40,700 retained cost, but does not independently certify AED 45 consolidated profit; review FX/period attribution before P&L sign-off. BK-000570 has a separate preexisting resync: its posted cancellation says PKR 342 final charge, while the current receivable is PKR 109,000. Do not infer customer payment or repair it from the amount comparison alone. Three older AED-to-PKR transfers (IDs 5, 6, 9; destination PKR 23,100,000) are unjournaled and were expressly not confirmed. Do not backfill them. The difference between treasury and journal-only cash reporting reflects those transfers. Review period attribution of backdated BK-000221 cash and later correction/reversal journals before monthly P&L sign-off.

## BK-000038 and Future Cancellation Protection

Before the local BK-000038 repair, a fresh SQL backup was saved at `storage/backups/travel_agency_op_before_bk000038_receivable_repair_20261002.sql` (25,579,297 bytes; SHA-256 `1F5FA0AF534C65D3AF5D3440057C804C7882AC4E17233558BEA25D8F6688417C`). The CLI repair was previewed, rehearsed with rollback, and applied locally; its reversal journal is 7424. A second invocation reported `ALREADY APPLIED`. This backup and journal ID are **local only**; production will have its own IDs and fingerprint.

The application now rejects ordinary financial edits and Quick Financial Edit cost-currency changes after a cancellation settlement is posted; direct commercial sync also skips such services. `tests/cancellation_save_protection_regression.php` covers the cost-currency attempt and confirms that receivable, supplier, refund, journal and cash snapshots remain unchanged. Run `php scripts/audit_settled_cancellation_receivables.php` on each database copy to identify older mismatches without writing anything. The local audit examined 22 settled cancellations with a final-charge snapshot, flagged only BK-000570, and identified BK-000038 as an older settlement without that snapshot. A flagged or unverifiable booking requires review, not an automatic adjustment.

For a **separately approved** production BK-000038 repair, upload `scripts/reconcile_bk000038_restored_receivable.php`, take a fresh production SQL backup, and run its no-flag preview. Confirm every guarded ID, amount, currency, allocation and audit record. Rehearse with the production preview's fingerprint, then re-preview immediately before applying with that same current fingerprint and a verified actor. A failed guard is a stop, never a reason to loosen it. Do not use the local fingerprint or reversal ID in production. Do not run this write while the broader production deployment hold above is unresolved.

## BK-000038 Supplier Credit Denomination

The user confirmed that the supplier kept the PKR 50,242 return as credit and that the AED 535 application to BK-000517 is valid. Full cancellation/refund undo is not appropriate: legacy cancellation #1 lacks the verified original-restoration basis, and supplier-only reversal is blocked because the credit has already been used. The rollback-only diagnostics changed no records.

On the local copy, a pre-write SQL backup is `storage/backups/travel_agency_op_before_bk000038_supplier_source_20261002.sql` (25,596,969 bytes; SHA-256 `67E73CDEFCEAF7CCBBE65FA835E5F5B845DE2088E7F3E1B2E182A7E3C0B9F5C5`). `scripts/correct_bk000038_supplier_refund_source.php` was previewed, rehearsed with rollback, and applied locally. It changed only posted event #119's supplier source metadata and appended a required audit entry: source AED 653 became PKR 50,242, with AED 653 booking equivalent and an implied PKR-per-AED rate. It did **not** reverse event #119, its AED credit, application #124 to BK-000517, customer refund #47, treasury, or journals. A second invocation reports `ALREADY APPLIED`.

The supplier posting form accepts the saved expected supplier currency directly; for BK-000038 this is PKR, not the AED customer equivalent. New cross-currency retained credit must be backed by a specifically released payment in the supplier's source currency. It enters the supplier advance pool without applying to an already-open booking. A cash/bank supplier refund may be posted in its actual source currency only when its treasury account matches both the source currency and booking branch, and released supplier credit backs the receipt; the AED booking equivalent is not a second cash movement. The rollback-only tests `tests/supplier_refund_source_currency_posting_regression.php` and `tests/supplier_refund_advance_pool_regression.php` cover these paths.

Later explicit cross-currency application of supplier advance posts paired noncash supplier-pool FX bridge journals in source and payable currencies, followed by one payable-currency application journal. The source booking is not attached to the later payable. `tests/supplier_advance_fx_journal_regression.php` covers PKR-to-AED use across branches and verifies that no cash account moves.

**2026-10-03 local supplier correction:** A full SQL snapshot was saved at `storage/bk38_supplier_truth_before_20261003.sql`. The first local repair released PKR 50,242 from original payment `SPAY-000168`, reduced the supplier obligation to PKR 40,700, created PKR supplier credit, voided the false AED supplier payment, and reversed its cash journal. That run also incorrectly consumed PKR 41,163.05 through an inferred supplier conversion; a separate guarded local reversal restored the full PKR 50,242 advance and reversed both noncash bridge journals with audit. The revised production script never posts such a supplier conversion. The separate historical AED advance use is marked unverified for later source review, not attributed to BK-000038. The AED 45 profit-summary issue remains deferred.

The revised `scripts/verify_bk000038_supplier_truth.php` checks PKR 40,700 supplier settlement, PKR 50,242 available credit, reversal of the false AED cash posting, AED 600 customer refund, and a PKR-only BK-000038 supplier ledger. Do not use the old uploaded script or verifier; re-upload both reviewed replacements together.

The replacement was previewed, rehearsed with rollback, applied, and verified against a separate local database restored from the pre-repair snapshot. Its second preview failed closed as already applied. The copy contains one PKR supplier-release journal and one reversal of the erroneous AED cash journal, with no supplier-pool FX journals. The current local database independently passes the same verifier after the earlier bridge was reversed. These checks do not mean the replacement has been run on production.

**Production snapshot differs:** The 2026-10-03 production read-only inspection found posted cancellation event #1 and AED 600 customer-refund event #47, but no supplier-refund event #119. Supplier payment #8 is still an active AED 1,188 record with AED 653 marked converted to advance; no BK38 PKR supplier-repair journal exists. Both earlier guarded scripts stopped without committing. Do not run their `--apply` paths on this production state. Inspect the PKR allocation, obligation, source advance usage, and historical cash journal with `php scripts/inspect_bk000038_supplier_refund.php --supplier-state` before designing a separate guarded repair for this earlier state.

The production-specific replacement is `scripts/reconcile_bk000038_supplier_precredit.php`. It was tested on an isolated copy of this earlier state after adding the already-confirmed cancellation facts: preview, rollback rehearsal, apply, verifier, supplier-ledger output, and duplicate-run guard all passed. It releases PKR 50,242 from the existing PKR payment, records one PKR supplier-credit source detail, neutralizes the incorrect AED payment/advance and reverses only its false AED cash journal, preserves the AED 600 customer refund, posts no supplier FX conversion, and creates no new cash movement. It does not touch any other booking.

Production commands for this exact inspected state, after a fresh backup and maintenance pause:

```bash
php scripts/reconcile_bk000038_supplier_precredit.php
php scripts/reconcile_bk000038_supplier_precredit.php --rehearse --actor=<VERIFIED_SUPER_ADMIN_ID> --fingerprint=<PREVIEW_SHA256>
php scripts/reconcile_bk000038_supplier_precredit.php --apply --actor=<VERIFIED_SUPER_ADMIN_ID> --fingerprint=<PREVIEW_SHA256>
php scripts/verify_bk000038_supplier_truth.php
```

The preview fingerprint must come from the same production database immediately before rehearsal. If any guard fails, stop; do not edit the script or reuse a local fingerprint. The expected final supplier-ledger result is PKR 50,242 Advance, with no AED supplier row for BK-000038.

**Production is separate.** The receivable-repair script accepts either the earlier two posted events or this exact reviewed third supplier-credit event; unrelated cash receipts still fail its guard. If event #119 exists there with the same reviewed AED credit/application, upload the source-correction script and run preview, rollback rehearsal, then a fresh preview before `--apply` with that production fingerprint and verified actor. If the event or IDs differ, stop; do not try to recreate the local event or apply the local fingerprint. The wider production hold and fresh backup requirements still apply.

## Production Deployment Gate

1. Freeze relevant financial writes and take independent production SQL **and** code backups. Record database name, host, table counts, migration status, key balances, and backup hash. Never restore the locally modified rehearsal DB over production.
2. Review/deploy the complete application changes and new classes from this working tree as one tested release, not isolated files. At minimum, include changed `app/`, `public/`, `database/`, `routes/`, `config/`, the migration and the reviewed CLI scripts. Preserve production `.env`, secrets, uploaded files and runtime `storage/` **even if those paths are present in a release archive**; do not copy test data or the local SQL dump. This working tree is uncommitted, so create a reviewed release artifact or commit before upload and record its hash.
3. From the production application root, run `php database/migrate.php status` first, then `php database/migrate.php up` only after reviewing the pending migration list. The expected pending migration on the rehearsal copy was `20260923_000077_counterparty_offset_cross_dimension.php`; stop if production differs. Re-run status and then `php scripts/preflight_production.php`. A preflight run *before* migration may fail only because the reviewed migration is pending; do not treat that as permission to apply unexpected migrations.
4. On a **new copy of the actual production database**, run each command below first without `--apply`. Compare its guarded preconditions, IDs, currencies, balances and audit trail with the local plan. A failed guard means stop and investigate, never weaken the guard to force a match. Only then run the corresponding production `--apply`, one case at a time, with a fresh backup/review checkpoint. Existing receipts and cash movements must never be duplicated.

```powershell
php scripts/repair_bk000517_market_fare.php
php scripts/repair_bk000517_market_fare.php --apply

php scripts/repair_bk000897_cancellation_resync.php --rehearse --actor=10
php scripts/repair_bk000897_cancellation_resync.php --apply --actor=10

php scripts/reconcile_bk000221_cancellation.php
php scripts/reconcile_bk000221_cancellation.php --apply
php scripts/reconcile_bk000221_customer_cash.php
php scripts/reconcile_bk000221_customer_cash.php --apply --confirmed-2026-07-04-cash-account-34

php scripts/reconcile_bk000087_service_charge_refund.php --actor=10
php scripts/reconcile_bk000087_service_charge_refund.php --apply --actor=10
php scripts/reconcile_bk000795_refund.php --actor=10
php scripts/reconcile_bk000795_refund.php --apply --actor=10
```

Production user ID 10 was confirmed as the active Development Super Admin for this replay. The BK-000221 cash confirmation flag is based on the user's confirmed real cash/date/account in the copied dataset; verify production account ID 34 and evidence again before applying. If any case is already repaired in production, skip its apply and document why. The order matters, especially BK-000087 before BK-000795.

For AL SAUDIA, run the preview **without flags** and review the 22-payment plan and new fingerprint from that production copy. Never paste the local fingerprint into production. First rehearse and then apply using the *same reviewed production fingerprint* and a verified actor ID:

```powershell
php scripts/repair_alsaudia_cross_branch_treasury_funding_20260924.php
php scripts/repair_alsaudia_cross_branch_treasury_funding_20260924.php --rehearse --actor=10 --fingerprint=<PRODUCTION_PREVIEW_SHA256>
php scripts/repair_alsaudia_cross_branch_treasury_funding_20260924.php --apply --actor=10 --fingerprint=<PRODUCTION_PREVIEW_SHA256>
php scripts/verify_refund_replay_20261002.php
```

The final verifier is intentionally tied to the reviewed production snapshot's booking/receipt/journal IDs and 22-payment plan. A mismatch is a **stop for review**, not an instruction to alter production until it passes. Its two `[REVIEW]` lines for BK-000038 and the transfers are expected unresolved exceptions. Validate all new journals balance, each bank/cash source moves once, supplier payable does not move twice, allocations sum to their receipts, and no refund is duplicated. Keep full command output and before/after SQL evidence with the release record.

## Manual Acceptance

- Hard-refresh and sign in as an authorized user. Check BK-000517 New Invoice: only Market Fare and Cost Currency editable in Section A; existing corrected totals unchanged.
- Open BK-000038 Edit Refund and Receivable Aging: PKR 50,242 expected and now recorded as retained supplier credit, AED 653 booking equivalent, AED 53 customer penalty, AED 600 already refunded, AED 0 still due, and no AED 608 receivable. Confirm no PKR cash/bank receipt was posted and that the supplier credit was not recorded twice.
- Check BK-000897 cancellation, PKR 44,444 expected supplier return and PKR 4,444 customer penalty; absence of customer payment must not create a cash refund.
- Check BK-000221 receipt and customer-credit history, both currencies, payable and ledger; do not equate expected supplier return with collected cash.
- Check BK-000087 AED 1,280 historical entitlement but only AED 199 currently available after transfer; an attempt to pay AED 200 must fail without changing cash or events. Check BK-000795 original invoice/status restored and AED 1,081 allocation.
- Check AL SAUDIA supplier ledger, Swat PKR cash and Cash & Bank Position together; investigate the known PKR 23.1m transfer gap separately, without guessing transfer funding.
- On a disposable booking, exercise a mistaken complete refund and restore, a partially settled refund, same/cross-currency equivalent input, insufficient cash causing a negative account balance, and print/ledger views. Confirm audit entries, allocations, treasury and balanced journals after each action.

## Safety / Done Criteria

The code uses existing authorized/CSRF-protected HTTP paths and prepared PDO repositories. Financial repair scripts are CLI-only, guard specific records, transact or rehearse with rollback, and retain audit history. Run scripts from a restricted administrator terminal, not via the web server. No automatic linked-party offset, guessed FX, or unconfirmed actual cash was added. This is not a security certification.

Local rehearsal is complete for the guarded cases listed above. Production is **not done** until a reviewed release, fresh production-copy rehearsal, case-by-case guarded deployment, balanced-journal verification and authenticated manual acceptance all pass. BK-000038 consolidated P&L, BK-000570's older resync, three historical transfers, and period-reporting reconciliation remain separate review items.
