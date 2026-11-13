# Supplier journal snapshot backfill review — local database, 25 October 2026

This review is **local only**. No historical `supplier_id_snapshot` values were saved. Do not run a historical backfill on production from these results. The user explicitly excluded **AL SAUDIA AED** from any change.

## What was verified

- Migration `20261018_000081` is applied locally. It adds a nullable supplier snapshot to `journal_entry_lines`; it does not fill old rows.
- All 15,484 existing journal lines still have a null snapshot. Only 7,128 lines directly reference a supplier obligation or supplier payment; the other lines must not be assigned a supplier merely because the column exists.
- The read-only inventory found 6,612 stable-source candidates, 512 exceptional lines and four custom BK-000038 lines without payment-creation audit evidence. All 1,063 obligation sources and 279 payment sources in the stable candidate set have an exact-time creation audit event matching their current supplier. None of the 7,128 source references is orphaned.
- The exceptional timeline audit traced 512 lines to posting-time evidence: 180 matching obligation creation events, 324 preceding supplier-history events, four supplier-change settlement releases matched to the prior supplier and released amount, and four payment-creation events before a supplier change. Four BK-000038 payment/reversal lines remain unverified.
- Audit logs contain 172 events for reused obligation IDs that predate the **current** obligation row. The verifier discards these events by source creation time and checks booking/service identity. An ID alone is not proof of attribution.
- A rollback-only rehearsal of the 6,612 stable candidates changed no supplier-ledger totals or row counts by branch or across branches.
- A rollback-only rehearsal of all 7,124 attributed candidates changed 29 supplier/currency/branch summaries. Across all branches, AirBlue Imdad Int PKR would change from 152,989 advance to 339,719 advance. AL SAUDIA AED PKR would shift substantially to AL SAUDIA PKR. These are **unsafe report effects**, not approved corrections or evidence that the resulting balances are true.
- The service-supplier correction regression currently fails its supplier-ledger assertion with snapshots enabled: the corrected payable belongs to the new supplier in source/payable aging, while the ledger still shows its original payable under the old supplier. The test rolls back all fixtures. This is a release blocker for any broad historical backfill and needs an accounting/reporting design fix, not a changed expected value alone.
- The local financial baseline hash before and after the rehearsals and regressions is `93B687E42A0070FFA1AE3D0BE9E6CED344EA3F207E0C67DD0CD6870BCE02E351`. Persisted historical snapshots remain zero.

## Safe next steps

1. Keep AL SAUDIA AED out of all historical backfill batches. Do not alter its saved payments, obligations, journals, or reports as part of this task.
2. Separate *posting-time attribution evidence* from the *current supplier-account balance*. A historic supplier change needs verified release/transfer evidence before the ledger may move the old debit or credit to another supplier. A snapshot alone must not silently change a current balance.
3. Fix and rerun the service-supplier correction regression with before/after supplier, customer, treasury, and journal baselines. Verify both the old supplier credit and the new supplier payable after a correction in both directions.
4. Only then prepare a guarded local backfill for individually evidenced rows, excluding AL SAUDIA AED and the four unresolved BK-000038 lines. Rehearse each batch in a transaction and require explained ledger differences. Do not fill a line solely from its current foreign key.
5. Repeat the read-only audit against the exact production database and deployed code before any production backfill. Local line IDs and audit counts are not production approvals.

Read-only evidence: `storage/supplier-journal-snapshot-audit-20261025.json`, `storage/supplier-journal-snapshot-timeline-20261025.json`. Rollback-only reports: `storage/supplier-journal-snapshot-rehearsal-20261025.json`, `storage/supplier-journal-snapshot-rehearsal-full-20261025.json`.
