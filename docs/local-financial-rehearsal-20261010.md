# Local financial rehearsal, 10 October 2026

This records work on the refreshed local database only. No production server or database was changed. The user already has a copy of the local database; no additional local backup was retained.

## Local schema and history

- Applied migrations `20261009_000078_salary_account_source.php` and `20261010_000079_customer_receipt_allocation_movements.php`. Migration status: 74 applied, 0 pending.
- The old salary-link table contained three links and no salary records or payments. It was removed as requested; salary now uses the selected account ID.
- The receipt-allocation history migration baselined all 1,159 allocations. One historical BK-000038 allocation points to a missing receipt; the history preserves and labels it rather than silently omitting it. No receipt or balance was fabricated.

## Rehearsed local repairs

Each guarded repair was previewed, rehearsed with rollback, applied locally, and then checked for repeat-run safety unless noted.

- Treasury transfers #5, #6, and #9: restored missing AED 300,000 source and PKR 23.1m destination journals. Live treasury balances did not move. The two clearing accounts were created by the normal posting method inside the apply transaction; preview remained read-only.
- Supplier cost currency: corrected journals for BK-000002, BK-000529, BK-000606, BK-000636, and BK-000670. BK-001044 was excluded because this database's booking is not the earlier PKR 1,000 test case.
- BK-000527: reversed the historical AED 725.01 error and reconciled PKR 35,700 supplier cost, with no payment change.
- BK-000570: resynchronized the cancellation to PKR 342 customer due and PKR 342 supplier cost paid, releasing the wrongly applied supplier advances. No cash moved.
- BK-000637: left AED 545 supplier payment intact, retained AED 4 advance application, and restored AED 525 to reusable supplier advance. No cash moved.
- SPAY-000113: reapplied the existing narrowly guarded repair for two duplicate release journals and one wrong-branch void reversal. Its dry-run now reports `already_applied: true`.
- SPAY-000115: a new dedicated script reversed twelve duplicate supplier correction-release journals totaling PKR 1,165,448. The payment and void cash journals remain unchanged; the complete chain now nets to zero by account. Preview, rollback rehearsal, apply, and repeat-run verification passed.

## Verification

- `tests/financial_invariants_audit.php`: PASS. Cross-branch customer receipts are allowed when the selected treasury account's currency and journal evidence match; allocation branch rules remain enforced.
- `tests/production_readiness.php`: PASS on the local environment.
- `scripts/audit_treasury_transfer_journals.php`: PASS, 36 posted transfers checked.
- `scripts/verify_cross_branch_customer_cash_truth.php`: PASS for RCPT-000873, RCPT-000926, and RCPT-000938. PKR 171,000 appears once in Swat cash and not in Noble cash.
- Focused PHP regressions passed for receipt-history coverage, salary account source, complete refund undo, treasury cross-dimension journals, supplier ledger correction de-duplication, cross-branch supplier payment correction, booking exchange-rate edits, service currency correction, cross-currency customer refunds, linked-party settlement, cancellation resync, and cash/bank report layout and drilldown.
- PHP syntax checks and `git diff --check` passed. Git reported only Windows line-ending notices.

## Local UI checklist

1. Open cash/bank ledger: confirm AED cards are on the first row and PKR cards on the second; inspect transfers #5, #6, and #9 and the three cross-branch receipts.
2. Open supplier ledgers for BK-000529, BK-000670, BK-000527, BK-000637, and BK-000570; compare the balances above. BK-001044 must remain outside the five-booking repair.
3. Open BK-000570 customer balance and confirm PKR 342 remains due, not PKR 109,000.
4. In a new local test booking, change branch and cost/invoice currency, save and reload, then edit invoice/refund; confirm saved amounts keep their entered currencies and review the booking-edit trace.
5. In salary, select an account directly and confirm its sales snapshot displays before saving; the bonus remains staff-entered.

Do not reuse any fingerprint from this local rehearsal on another database. Re-run previews against whatever database is being worked on at that time.
