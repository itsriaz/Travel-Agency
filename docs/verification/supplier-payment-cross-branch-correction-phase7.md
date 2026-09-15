# Supplier Payment Cross-Branch Correction - Phase 7

## Scope

This phase fixes supplier payment correction and void accounting when one global supplier payment funds obligations in more than one branch.

The reviewed historical case is `SPAY-000113`:

- payment branch: `1`
- payment currency and original amount: `PKR 116548`
- original allocations: `PKR 20006` in branch `1` and `PKR 96542` in branch `2`
- affected booking: `BK-000053`
- affected supplier obligation: `53`

## Confirmed Defects

1. A financial payment correction released operational allocations and posted release journals before the payment void reversed the original allocation journals. This reversed the same accounting twice.
2. The global void reversal grouped only by currency and posted every line in the payment branch, including lines belonging to obligations in another branch.
3. Corrected global payments reloaded open obligations only from the payment branch, so an original cross-branch obligation could be omitted from replacement allocation.
4. Supplier-only correction journals used the payment branch instead of each released or replacement obligation branch.
5. A financial edit could release a used converted supplier advance before the normal void guard inspected it.
6. A user with access only to the payment branch could reach obligations allocated in another branch during correction.
7. Supplier finder search parameters were bound in the wrong order, hiding supplier advance rows when a search term was entered.
8. A void-only supplier ledger could omit the zero-valued Supplier Credit card.

## Runtime Fix

- Supplier payment void reversals are grouped by original branch and currency.
- Corrections and voids require access to every affected obligation branch.
- Financial edits reject used converted advances before releasing any allocations.
- Financial replacement does not post separate release journals because the void already reverses original allocation accounting.
- Global corrections reload obligations from every accessible branch.
- Replacement allocation and supplier-only release/reallocation journals use the obligation branch.
- Booking-scoped supplier payment behavior remains branch-scoped.
- Supplier finder parameters now follow SQL placeholder order.
- Voided supplier payments retain a zero Supplier Credit summary card.

No schema or migration change is required.

## Local Historical Repair

Backup created before repair:

`storage/backups/travel_agency_op_before_spay000113_repair_20260915_213705.sql`

Repair command:

`php scripts/repair_spay000113_cross_branch_correction_accounting.php --apply`

The script is dry-run by default, validates the exact reviewed payment and journal fingerprints, runs in one transaction, and is idempotent.

It appended four journals:

- `6650`: neutralized duplicate release journal `4109`
- `6651`: neutralized duplicate release journal `4110`
- `6652`: removed obligation `53` void-reversal accounting from branch `1`
- `6653`: restored the same void-reversal accounting in branch `2`

It did not change supplier payments, allocations, obligations, or treasury cash. The complete old `SPAY-000113` chain now nets to zero for every affected account inside each branch.

## Verification

- `tests/supplier_payment_cross_branch_correction_regression.php` passed with rollback-only two-branch financial and supplier-only correction fixtures.
- `tests/supplier_payment_unified_edit_regression.php` passed.
- `tests/supplier_payment_supplier_correction_regression.php` passed.
- `tests/global_supplier_payment_correction_regression.php` passed.
- `tests/supplier_payment_ledger_correction_dedup_regression.php` passed.
- `tests/cross_branch_supplier_allocation_audit_regression.php` passed.
- `tests/unified_supplier_payment_regression.php` passed.
- `tests/supplier_void_payment_summary_regression.php` passed.
- `tests/financial_invariants_audit.php` passed after the local repair.

## Production Gate

Do not run the local backup against production. Before production repair:

1. Ask users to stop financial entry.
2. Download a fresh production database snapshot.
3. Run the repair script without `--apply` and review its fingerprinted dry-run output.
4. Back up production immediately before applying.
5. Apply once, rerun dry-run to confirm `already_applied: true`, and run `tests/financial_invariants_audit.php`.

If the fresh production audit finds any later correction chain beyond `SPAY-000113`, stop and prepare a separately fingerprinted repair rather than broadening this script blindly.

## Residual Unrelated Test Issues

- `tests/supplier_advance_fx_application_regression.php` currently reports existing supplier-advance FX failures in code not changed by this phase.
- `tests/supplier_advance_existing_payables_reconciliation_regression.php` prints an application exception after its passing assertions while returning exit code `0`; its harness should be reviewed separately.
