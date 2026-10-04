# Complete Refund Reversal and Recovery

## Implemented Locally

- Keep Edit Refund for partial corrections. Reverse Entire Refund restores the selected service/invoice, not every booking belonging to the customer.
- The operator explicitly confirms whether all recorded customer refund money was actually paid and all recorded supplier refund money was actually received. Entry-only movements are reversed; genuine movements keep their original journals and receipt/payment consumption. Restored invoice balances reflect money still recoverable/payable through balanced control-account journals.
- Nothing is transferred to another customer/booking automatically. If actual money belongs to another booking/person, it needs a separately confirmed attribution; complete reversal does not guess the recipient.
- Refund History / Restore lists actor/date/reason, reversal/restoration audit references, money decisions and before/after invoice figures. Restore Reversed Refund recovers a new reversal's protected snapshot with compensating journals, not deletion of journal history.
- Before/after snapshots preserve all affected receipt/payment/advance allocation records, including allocation IDs replaced by existing accounting primitives. Subsequent restoration does not erase the original audit or its restoration history.
- Required audit failure, database failure or validation failure rolls back the whole operation, including nested savepoints. Original journal entries are never deleted or edited. Multiple partial refunds, repeat submissions, repeat recovery cycles and stale-service submissions are guarded.
- New customer refunds persist exact receipt consumption amounts in event JSON. Their reversal restores those receipts, not an unrelated newer receipt with returned money.
- Financial ledger/register and cash-flow queries include specifically preserved actual-money refund events even though the operational refund is voided. Entry-only reversed refunds remain excluded. Operational entitlement calculations still ignore voided refunds, preventing a later cancellation from counting an old cash movement as a new refund entitlement.
- Reinstatement is allowed only while saved financial dependencies still match. Later changes require review instead of silently overwriting them. Shared supplier payment/advance dependencies are conservatively guarded, even if a later change belongs to another booking.

## Deliberate Safety Boundaries

- A valid audit record is not proof of physical cash movement: the operator must make the actual-money choices correctly.
- When only some payments on one side genuinely occurred, use the existing event-specific correction workflow to reconcile that side first. The compact complete-reversal choices apply to all posted payments on each selected side.
- Do not invent missing original invoice amounts/status, refund receipt mappings, supplier credit sources, journal lines or historical FX. Older contradictory records stop with an explanation and no changes.
- Actual-money legacy combined events currently require review. Separate modern customer/supplier events cover all four money-status combinations. Verified recorded-only combined component reversals retain their existing accounting path.
- Actual refund journal and source-credit currencies must agree. Historical mismatches need FX reconciliation; the operation does not silently convert or relabel an existing journal.
- Released linked-party offsets without complete source matching and retained supplier credits already used on other documents require review. No unrelated credit is pulled in to make reversal succeed.
- BK-000038 is not automatically repaired: its imported cancellation lacks original basis and source snapshots, and its synthetic refund consumption needs reconciliation. Customer AED600 genuinely paid must not disappear from cash.
- Old complete-undo operations without recovery snapshots are auditable but not automatically restorable by the new action.

## Deployment Files

Upload the reviewed release consistently, including earlier locking dependencies:

- `app/Helpers/AuditLog.php`
- `app/Repositories/AccountingRepository.php`
- `app/Repositories/BaseRepository.php`
- `app/Repositories/AccountLedgerRepository.php`
- `app/Repositories/ReportRepository.php`
- `app/Repositories/CustomerPaymentRepository.php`
- `app/Repositories/RefundRecoveryRepository.php` (new)
- `app/Repositories/BookingServiceRepository.php`
- `app/Repositories/BookingServiceEventRepository.php`
- `app/Services/ServiceWorkspaceService.php`
- `app/Services/ReportService.php`
- `app/Controllers/WorkspaceController.php`
- `app/Views/workspace/index.php`
- `app/Views/workspace/partials/station.php`
- `public/index.php`
- `public/assets/js/workspace.js`
- `public/assets/css/app.css`

SQL/schema changes: none. Existing audit/event JSON holds recovery metadata. Uploading files does not reverse/repair any production booking. Keep production environment files, credentials and storage unchanged. A code rollback does not reverse financial operations already performed.

## Acceptance Checklist

- [ ] Financial admin: cancel a disposable invoice, reverse with both sides recorded-only, and verify original invoice, status, allocations, ledger and cash.
- [ ] Test all four actual customer/supplier money combinations. Genuine money must remain in cash; restored invoice outstanding must equal the appropriate recoverable/payable amount. Do not test by pretending a real payment never happened.
- [ ] Compare journal/control balances, Account Ledger, Customer Detail Ledger, Supplier Ledger and Cash Flow. Actual-money refund rows must remain visible after reversal; restoring the reversal must show the original refund once, not twice.
- [ ] Cancel confirmation and try stale/foreign IDs and unauthorized branches: nothing changes. Repeat a successful submit: no duplicate operation.
- [ ] Open Refund History / Restore, inspect actor/date/reason and before/after figures. Restore a mistaken reversal: cancelled/refunded state and balances return, linked audit/journal history remains. Repeat restoration: no duplicates.
- [ ] Make a later allocation/edit first: restoration must refuse without overwriting it. Verify a new invoice/service cannot submit the previous service's recovery IDs.
- [ ] Verify multiple receipts, unrelated returned receipts, supplier advances/credits, currencies and treasury accounts. Used credits, unsupported legacy histories and missing journals must fail safely.
- [ ] Rehearse reviewed historical cases on a disposable current production copy. Authenticated browser visual/E2E acceptance and production deployment remain pending.

Security: both POST routes enforce CSRF, financial-admin authorization and booking/service/audit/treasury-branch ownership. Recovery SQL is prepared in repositories; write snapshots lock dependencies, including booking identity, service subtypes, refund details and treasury account metadata. Browser audit text uses textContent, not HTML. Financial success requires an audit inside the same transaction.

Automated verification: rollback-only complete refund undo/recovery regression (all four money choices, exact receipts, ledger/control and report reconciliation, cash-flow visibility, audit failure on reversal and restoration, stale/foreign IDs, used credit, subsequent edits, duplicate and second cycles, operational-only and unpaid cases); UI confirmation/history tests; existing refund-correction and cancellation protection regressions. No reviewed local booking was committed or production database modified.

Existing regression discrepancies, not repaired by this task:

- `tests/supplier_ledger_direction_regression.php` expects a historical BK38 AED653 supplier cash refund already received and five zero-balance rows. The reviewed current copy has four AED rows and no confirmed received supplier refund. Its repository results are identical to HEAD before the reporting changes; do not fabricate a payment to satisfy this assertion.
- `tests/report_truth_regression.php` reports mismatched treasury versus Cash and Bank Position balances (PKR23,100,000 and AED300,000 differences). Cash and Bank Position results were verified identical to the HEAD repository. TreasuryRepository and that query were not changed in this task; investigate opening-balance/treasury reconciliation separately before production financial sign-off.
- `tests/account_ledger_summary_regression.php` fails an older cross-booking credit fixture assertion after its cancellation scenarios pass. Both its test and the AccountLedgerService it directly exercises are unchanged from HEAD. The dedicated receipt and credit-transfer balance regressions pass; do not alter unrelated linked/ordinary ledger logic as part of reversal work.

Browser access was attempted, but the available isolated browser session redirects to Sign In. No login bypass, production deployment or real refund/reversal was performed.

Done criteria: guarded local reversal/restoration and regression verification are complete. Universal historical repair, mixed actual/entry-only payments within one side, authenticated browser acceptance and production sign-off are not claimed.
