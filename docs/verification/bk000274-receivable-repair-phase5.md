# Phase 5: BK-000274 customer receivable repair

Local review date: 2026-09-15. Production was not modified.

## Finding

BK-000274 has one cancelled AED service and a valid AED 585 customer charge after
cancellation. Accounting AR also has a net AED 585 debit. The customer paid
nothing, and no active linked-party adjustment covers the invoice, but customer
receivable 273 incorrectly says AED 585 allocated, AED 0 outstanding and paid.

The stale header came from the historical automatic linked-settlement and
cancellation sequence. Its AED 585 offset allocation is retained only under void
offset 6 as audit history. The original offset journal and its reversal net to
zero, as do the AED 250 release journal and its reversal.

This is historical dirty operational data. Current manual linked settlements do
not change customer paid/outstanding fields, so no shared runtime path is changed
in this phase.

## Repair boundary

`scripts/repair_bk000274_customer_receivable.php` is restricted to booking 274,
service 273, cancellation event 38 and receivable 273. It refuses to write unless:

- the cancellation payload says the customer received AED 0 and owes AED 585;
- there are no active customer receipt allocations;
- there are no active linked-party allocations;
- the reviewed automatic offset is void and its accounting reversal is complete;
- the linked release and reversal net to zero; and
- accounting AR for BK-000274 is exactly AED 585.

The repair changes only the receivable header from AED 585 allocated and AED 0
outstanding to AED 0 allocated and AED 585 outstanding. It writes a mandatory
audit row in the same transaction. It does not create or reverse a journal because
the ledger already carries the correct AED 585 receivable.

## Commands

- Dry run: `php scripts/repair_bk000274_customer_receivable.php`
- Rollback-only rehearsal: `php scripts/repair_bk000274_customer_receivable.php --rehearse --actor=10`
- Apply requires a current SQL backup:
  `php scripts/repair_bk000274_customer_receivable.php --apply --actor=10 --backup=/absolute/path/to/backup.sql`
- Verify: `php tests/financial_invariants_audit.php`

## Local verification result

- Dry run passed against the reviewed dirty state.
- Full write and audit rehearsal passed, then rolled back.
- A 17.6 MB local SQL backup was created before apply.
- Local apply changed only receivable 273 and wrote its audit record.
- A repeated dry run reports `no-change`, proving idempotence.
- The customer-receivable invariant now passes with no drift.
- The PKR/AED/USD manual linked-settlement matrix passes and rolls back.
- Unpaid, partial, full and mixed-currency cancellation cases pass and roll back.
- Customer cancellation ledger and combined-refund correction regressions pass.

The broad invariant audit still reports two separate known findings: supported
cross-branch supplier-payment allocations are rejected by an old audit policy,
and historical journal 4110 has a booking-branch attribution mismatch. The
account-ledger summary test also retains its pre-existing BK-000277 presentation
expectation failure. None is changed or hidden in this phase.

## Manual check

Open BK-000274 after local apply. The customer side must show AED 585 due and AED
0 paid. The cancellation stays posted, customer refund available stays AED 0,
supplier balances and linked-adjustment history remain unchanged, and no treasury
transaction or new journal is created.

## SQL and security

No migration or schema change is required. The command is CLI-only, dry-run by
default, requires an active super administrator for rehearsal/apply, requires a
backup for apply, locks the exact receivable row, uses bound actor/audit values,
checks the reviewed financial state again inside the transaction, and rolls back
on every mismatch.
