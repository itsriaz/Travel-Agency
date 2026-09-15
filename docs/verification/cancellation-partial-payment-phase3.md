# Phase 3: Partial-Payment Cancellation Verification

## Scope

This phase fixes one cancellation-settlement defect only. It does not change refund payment posting, supplier settlement, treasury, linked-party settlement, or browser JavaScript.

## Reproduced Defect

Before the fix, this rollback-only example produced a false customer refund:

- invoice: AED 620
- paid by customer: AED 100
- expected supplier refund: AED 300
- customer penalty: AED 100
- correct final customer charge: AED 420

The old condition subtracted the entire AED 520 unpaid invoice balance from the final charge. It reduced the final charge to zero, removed the AED 100 allocation, and exposed AED 100 as refundable.

## Correct Rule

The final customer charge is calculated independently of how much the customer has paid:

`final charge = current invoice due - expected supplier refund + customer penalty - agency fee refund`

Refund credit is then limited to money already settled above that final charge:

`refund credit = max(customer settled - final charge, 0)`

For the example above, the result is:

- final charge: AED 420
- customer allocation retained: AED 100
- customer outstanding: AED 320
- refund available: AED 0

An explicitly approved loss sale remains a separate business event. An ordinary unpaid invoice balance is not automatically treated as a loss or refund.

## Currency Matrix

The rollback-only regression verifies:

- AED invoice, AED supplier, no customer payment
- AED invoice, AED supplier, AED 100 partial payment
- AED invoice, AED supplier, AED 620 full payment
- AED invoice/customer with PKR supplier truth at 1 AED = 80 PKR
- AED invoice with PKR 8,000 customer payment and penalty, plus PKR supplier truth

In the PKR supplier cases, PKR 46,320 cost, PKR 24,000 expected refund, and PKR 22,320 supplier penalty remain in PKR. Internal conversion produces the AED 420 customer charge without changing the supplier ledger currency.

## Files Changed

- `app/Services/ServiceWorkspaceService.php`
- `tests/cancellation_partial_payment_settlement_regression.php`
- `tests/customer_ledger_cancellation_regression.php`
- `tests/service_lifecycle_regression.php`
- `docs/refund-workflow-standard.md`

## SQL Changes

None. No migration or data-repair script is required for this code fix.

## Verification

- The new integration regression uses normal booking, service, receipt, FX settlement, cancellation, and settlement services.
- All fixtures run inside one outer transaction and are rolled back.
- Existing unpaid-cancellation, customer-ledger, service-lifecycle, correction, refund-guard, currency-ledger, and loss-report regressions remain green.
- `tests/financial_invariants_audit.php` still reports the three previously known local-data/audit-policy findings: BK-000274 allocation mismatch, supported cross-branch supplier allocations, and journal 4110 branch mismatch.

## Manual Test Checklist

1. Create an AED 620 invoice and receive AED 100.
2. Cancel the service and settle with AED 300 expected supplier refund and AED 100 customer penalty.
3. Confirm customer refund available is zero, AED 100 remains allocated, and AED 320 remains outstanding.
4. Repeat with PKR supplier cost/refund and confirm the supplier obligation remains PKR.
5. Repeat with PKR customer payment/penalty and confirm conversion affects internal calculation only.

## Security And Done Criteria

- Existing branch-access, currency, treasury-account, and financial-admin validation paths are unchanged.
- Prepared repository/service write paths remain unchanged.
- No production data was read or changed.
- Phase 3 is done when the focused and wider regressions pass and the change is committed as its own recovery point.
