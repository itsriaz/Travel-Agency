# Phase 6: cross-branch supplier allocation audit

Local review date: 2026-09-15. Production data was not modified.

## Classification

The financial invariant incorrectly treated every supplier payment and payable
from different branches as a defect. That rule conflicts with the supported
global supplier-account payment workflow.

The reviewed local data contains 77 cross-branch allocations under 25 active
global payments. All 77 preserve the same supplier and currency, use rate 1,
target an obligation in its booking branch, and have the correct AP debit and
Supplier Advances credit journal in that obligation branch. No repair or reversal
is required for these payments.

Example: branch 1 can fund a PKR global payment to a shared supplier, while that
payment settles a branch 2 booking payable. Cash/payment recording belongs to the
funding side; the payable allocation journal belongs to branch 2. This is not a
cash transfer between the bookings and must not be rejected merely because the
two branch IDs differ.

## Audit correction

The broad same-branch rule remains strict for customer receipts and ordinary
booking-scoped supplier payments. A cross-branch supplier allocation is accepted
only when all of the following are true:

- payment scope and reference are both global;
- payment and obligation belong to the same supplier;
- payment and obligation use the same native currency and rate 1;
- the obligation belongs to its booking branch;
- the payment-recording journal exists in the payment branch;
- AP reduction and Supplier Advances consumption are journaled in the obligation
  branch for the exact allocation amount; and
- a different treasury branch or currency has its separate funding journal.

Malformed global labels do not bypass the rule. Missing or wrong-branch journals
remain audit failures.

## Verification and safety

`tests/cross_branch_supplier_allocation_audit_regression.php` checks all current
cross-branch allocations and then performs two rollback-only negative probes. It
proves that changing a valid payment to booking scope or moving its allocation
journal to the payment branch is detected, and that both probes leave no data.

Local verification results:

- all 77 current cross-branch allocations pass the strict evidence rule;
- both rollback-only negative probes are detected;
- unified supplier-account payment behavior passes;
- global supplier payment void/recreate correction passes;
- supplier ledger correction de-duplication passes; and
- the broad financial audit now retains only the separate historical journal
  4110 branch-attribution finding.

`tests/supplier_global_settlement_readiness.php` still has seven older source-text
expectations that describe implementation shapes removed before this phase. Its
transaction-based companion tests pass, and no supplier runtime file was changed
here. Those stale readiness assertions were not changed or hidden as part of this
focused audit correction.

No controller, service, repository, UI, migration, financial row, or journal is
changed in this phase. The only behavior change is that the audit now distinguishes
supported global cross-branch settlement from genuine branch leakage.
