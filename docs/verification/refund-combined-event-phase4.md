# Phase 4: Legacy Combined Refund Correction

## Business Problem

Older data can contain one posted refund event holding both a customer refund and a supplier refund.

Example:

- customer refund paid: PKR 30
- supplier refund received: PKR 30
- both amounts stored in one legacy event and one journal

The correction workflow reversed the customer component first, but then reused the original in-memory event while reversing the supplier component. That stale event still contained the customer PKR 30, so the customer amount could become active again. A correction could then fail with an incorrect customer-credit limit or leave duplicate active refund truth.

## Implemented Boundary

- Current refund posting remains unchanged and continues to create separate customer and supplier refund events.
- Ordinary single-scope refund correction remains unchanged and continues to reverse its complete original journal.
- Only a legacy event containing both refund components, or carrying prior component-reversal metadata, uses the compatibility path.
- The correction workflow re-fetches the latest posted event before reversing each scope.
- Each legacy component receives its own accounting reversal.
- After both components reach zero, the legacy event is voided without reversing its original journal a second time.
- Both component reversal references and journal IDs remain in the event payload and audit history.
- A runtime log entry is written as `service.refund.legacy_combined_reversal_completed` when the legacy event is fully retired.

## Mixed-Currency Compatibility

- Modern settlements use explicit customer refund truth currency metadata.
- Legacy mixed-currency settlements continue to derive refund truth from saved source-currency amounts before raw converted fallbacks.
- Example: expected supplier refund PKR 55,826 less customer penalty PKR 826 produces customer refund truth PKR 55,000, even if an old converted header says 714.28.
- An explicit modern zero refund remains zero and is not replaced by supplier-side arithmetic.
- The stale test suggestion to trust an unlabelled legacy target amount was rejected because it could expose false refund credit on dirty unpaid records such as BK-000274.

## Files Changed

- `app/Services/ServiceWorkspaceService.php`
- `tests/service_refund_combined_event_correction_regression.php`
- `tests/customer_refund_booking_currency_regression.php`
- `docs/verification/refund-combined-event-phase4.md`

## SQL Changes

None.

No migration or data-repair script is required for this code correction. Rollback-only tests left no booking, service, receipt, supplier payment, event, or journal records behind.

## Automated Verification

The dedicated rollback-only integration test proves:

- a legacy PKR 30 customer plus PKR 30 supplier combined event is fully retired
- exactly two component reversal journals are posted
- no whole-journal reversal duplicates either component
- corrected active totals remain customer PKR 30 and supplier PKR 30
- customer receipt credit finishes at PKR 25 unallocated and PKR 30 returned
- supplier payment credit finishes at PKR 60 unallocated and PKR 30 returned
- a second correction of the new separate events to PKR 20 each still uses the existing full-event reversal path
- final separate-event balances become customer PKR 35 unallocated and supplier PKR 70 unallocated

Ten adjacent refund, cancellation, ledger-currency, correction-guard, and lifecycle suites passed.

The broader `supplier_five_ticket_cancellation_credit_scenario.php` still has two pre-existing presentation assertions. It does not invoke the changed correction/reversal path; its supplier payable, treasury payment, balanced-journal, and rollback checks pass.

## Manual Test Checklist

1. Open a cancelled service that has both a posted customer refund and posted supplier refund.
2. Choose Edit Refund and keep the settlement values unchanged.
3. Change the customer and supplier refund amounts, select valid accounts, enter a reason, and save.
4. Confirm the service shows only the corrected active customer and supplier refund amounts.
5. Confirm the customer receipt returned/unallocated amounts changed once, not twice.
6. Confirm the supplier payment returned/unallocated amounts changed once, not twice.
7. Confirm the Account Ledger shows reversal history and the corrected postings without duplicated money movement.
8. For a mixed-currency case, confirm customer refund currency follows customer settlement truth while supplier refund remains in supplier truth currency.

## Security And Audit Checks

- Existing branch-access checks remain active.
- Existing financial-admin authorization remains active.
- All correction writes remain inside the existing database transaction.
- Existing prepared repository statements remain in use.
- Correction reason, actor, component reversal references, and journal IDs are retained.

## Done Criteria

- Legacy combined correction cannot resurrect a reversed component.
- Legacy combined correction cannot reverse the full original journal after a component reversal.
- Current separate refund correction behavior remains unchanged.
- Mixed-currency source amounts remain authoritative where explicit legacy currency metadata is absent.
