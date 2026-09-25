# Linked-party automatic reversal: refreshed production snapshot

Local review and repair: 2026-09-22. Production application and verification were reported by the operator on 2026-09-22.

## Scope and Evidence

- Database: `travel_agency_op`, 71 applied migrations, no pending migrations.
- The imported snapshot had 48 posted `AUTO-OFFSET-*` rows, IDs 6-53, all for AJWA SWAT SHJ / AJWA SWAT TOURISM, Noble Route, AED. Their current allocated total was AED 48,920.
- No manual linked-party offset was posted in this snapshot. The earlier local manual PKR offset was not in this newer production import and was not recreated.
- Two original offset journals exceeded current allocations: offset 6 by AED 255 and offset 16 by AED 10. The exact three linked-release journals were 3453 (AED 250, wrong direction), 4619 (AED 10, wrong direction), and 4629 (AED 5, correct direction). All three were included in the reversal, so original plus release plus reversal nets to zero by account.
- The 51 affected supplier invoices had AED 39,464 gross service costs, zero real supplier payments or advance applications, and zero current payable. Their gross amounts matched the current service purchase costs, supplier, currency, and branch. Twelve had stale/duplicated automatic-offset allocation totals; no allocation rows were deleted.
- Customer receivables 273 (`BK-000274`, AED 585) and 441 (`BK-000442`, AED 1,540) were marked paid without any real receipt allocation. Reversing the release journals brought their booking AR_CONTROL balances to AED 585 and AED 1,540 before their headers were reopened.

## Local Action

The new one-off script is `scripts/repair_reviewed_automatic_linked_settlements_20260922.php`. It does not replace the earlier 27-offset script. It requires an exact preview fingerprint and aborts on any changed party, branch, currency, allocation, journal, payable, customer receipt, or accounting state. The `--rehearse` mode executed all changes in a transaction and rolled back. Its `--apply` mode ran after a local SQL backup.

Local artifacts, deliberately excluded from Git:

- `storage/backups/linked_auto_preview_20260922.json` (reviewed input, SHA-256 fingerprint `a37321e2ede20f3b4b4dfa63b8c9f9080b6c379016bb38458fa5fb49cddbf2ce` after ignoring only `generated_at`)
- `storage/backups/travel_agency_op_before_linked_auto_reversal_20260922.sql` (24,046,024 bytes, SHA-256 `5FCBD961E8A51776279B1F798EFFCE477EF0B83F6DCE391A6A162E861E5560F5`)

After local apply: zero posted automatic offsets; all 48 remain as void history; 51 supplier payables total AED 39,464; receivable 273 is open with AED 585 outstanding; receivable 441 is open with AED 1,540 outstanding. The repair audit log was written once. Receipts, allocations, supplier payments/advances, treasury records, service data, all other receivables/obligations, and linked allocation history were protected by before/after hashes. The final script additionally hashes booking headers and repeats the service-cost check after acquiring locks; those two added guards were not part of today's already committed local transaction.

To test the exact final script after those guards were added, the pre-repair SQL backup was imported into a separate local database, `travel_agency_repair_rehearsal_20260922`. Both rollback rehearsal and apply passed there, including booking-header hashes and the second service-cost check. The clone and main local database each ended with zero posted automatic offsets and AED 39,464 on the affected supplier payables. The clone is a local test database, not a production target.

## Production Application

The following evidence came from the operator's SSH output, not direct access to the production host:

- The deployed critical application and repair-script hashes matched the tested local files. Production CLI PHP was 8.3.33; migrations reported 71 applied and zero pending.
- The live read-only preview matched fingerprint `a37321e2ede20f3b4b4dfa63b8c9f9080b6c379016bb38458fa5fb49cddbf2ce` and found the same 48 posted automatic offsets. Validation-only and rollback rehearsal passed before apply.
- The full frozen production SQL export was placed at `storage/backups/linked-reversal-backup-20260922.sql`: 24,930,400 bytes, SHA-256 `90A6D38232D852535A0D11586BC3CC1BE6DCC74A30D675FD948CB25DA6C8269A`. An earlier size check observed the upload while it was still in progress; the completed file matched both size and hash before apply.
- The guarded `--apply` command reported 48 offsets reversed, AED 48,920 original offset amount, 51 supplier invoices restored to AED 39,464 payable, receivables 273 and 441 reopened, three release journals reversed, and all protected-data checks passed. The 51 new journal IDs were 6305-6355.
- Independent read-only post-apply queries reported 48 void and zero posted automatic offsets; 51 open supplier payables totaling AED 39,464; receivables 273 and 441 open for AED 585 and AED 1,540 with zero customer allocations; one repair audit event; and 51 reversal journals with AED 49,450 debit and AED 49,450 credit.

## Verification and Remaining Blockers

- Dedicated linked-party offset, no-auto-offset, PKR/AED/USD manual-settlement, linked-history, and account/supplier-link tests passed after apply.
- The customer receivable allocation invariant failed on 273 and 441 before apply and passed after apply. Linked-party conservation, dimensions, journal balance, and supplier obligation invariants passed.
- The full financial-invariant audit still fails in five unrelated categories already present **before** this repair: three customer receipt/treasury branch mismatches; missing cross-branch supplier treasury-funding journals for several global payments; an incorrect-branch journal for `SPAY-000113`; duplicate supplier correction release journals including `SPAY-000113`/`SPAY-000115`; and an incorrect-branch void for `SPAY-000113`.
- The older `BK-000277` account-ledger credit assertion and the live `BK-000038` supplier-ledger fixture check also fail on this imported snapshot. They were not changed as part of the linked-party repair.

The linked-party production change is verified, but this is not whole-system certification. The five unrelated financial-invariant categories above remain. Do not rerun the one-off repair or the older 27-offset script. Deployment also left duplicate `database/app` and `database/database` directories under the protected database path and an incomplete earlier SQL upload; these are cleanup items, not inputs to this repair. The intended `database/migrate.php` was restored and returned 71 applied, zero pending.

## Files, SQL, Security, Done Criteria

- Files changed for this verification update: this note only. The one-off repair script and runtime code were already prepared and deployed before this update.
- SQL migration: none. Local and production data repairs each used one transaction with append-only journals and a mandatory audit event.
- Security: CLI-only, active super-admin actor required, exact snapshot fingerprint, backup-file gate, locked rows, narrow hard-coded scope, rollback on exception.
- Done for this phase: the scoped linked-party reversal is locally and production-applied, with matching post-apply checks. Unrelated imported-data invariants remain separate follow-up work.
