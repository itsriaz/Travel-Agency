# Travel Agency System Rules of Engagement

This document is the architectural source of truth for AI agents working on the Travel Agency Operations and Accounting System.

It is written for a successor coding agent that must operate with senior-level judgment inside this specific codebase, not as a generic web-app assistant.

The system is a booking-workspace-first travel agency platform with accounting underneath. Operational truth lives in the booking workspace. Financial truth lives in persisted receivable, payable, allocation, and journal structures.

---

## 1. Project Blueprint & Logical Hubs

### 1.1 Primary application layers

- `app/Controllers`
  - HTTP entry points only
  - validate request shape, delegate to services, return views/JSON
- `app/Services`
  - business orchestration layer
  - all booking lifecycle, payment, refund, report, output, security, and setup flows belong here
- `app/Repositories`
  - PDO data access only
  - all SQL should stay here
- `app/Views`
  - render-only layer
  - never place business rules or SQL here
- `app/Helpers`, `app/Core`, `app/Middleware`, `app/Policies`, `app/DTOs`
  - infrastructure, cross-cutting concerns, and reusable system utilities

### 1.2 Ticket issuance and lifecycle management hub

The booking/service workflow is centered in these modules:

- `app/Controllers/WorkspaceController.php`
- `app/Services/BookingWorkspaceService.php`
- `app/Services/ServiceWorkspaceService.php`
- `app/Services/TravelerWorkspaceService.php`
- `app/Repositories/BookingRepository.php`
- `app/Repositories/BookingServiceRepository.php`
- `app/Repositories/BookingServiceEventRepository.php`
- `app/Views/workspace/*`

Key responsibilities:

- create and update booking header data
- manage lead traveler / party / additional travelers
- manage service lines
- run service events:
  - cancel
  - cancellation financial settlement
  - refund
  - reissue
- keep workspace UI in sync with persisted service state

### 1.3 Financial accounting and ledger update hub

The accounting core is centered in:

- `app/Services/CustomerPaymentFoundationService.php`
- `app/Services/CustomerReceiptWorkspaceService.php`
- `app/Services/SupplierFoundationService.php`
- `app/Services/SupplierSettlementWorkspaceService.php`
- `app/Services/CommercialObligationSyncService.php`
- `app/Services/AccountingFoundationService.php`
- `app/Services/AccountingSetupService.php`
- `app/Repositories/CustomerPaymentRepository.php`
- `app/Repositories/SupplierRepository.php`
- `app/Repositories/AccountingRepository.php`
- `app/Repositories/AccountingSetupRepository.php`

Key responsibilities:

- create receivable and payable truth from booking operations
- record customer receipts separately from allocation
- record supplier payments separately from payable settlement / advance application
- preserve unallocated customer money as customer credit
- preserve unallocated supplier money as supplier advance
- write balanced journal entries for financial events
- reverse entries through formal void / release / settlement flows, never by silent overwrite

### 1.4 Reporting and output hub

These modules define the business-facing truth presentation layer:

- `app/Controllers/ReportsController.php`
- `app/Services/ReportService.php`
- `app/Repositories/ReportRepository.php`
- `app/Services/OperationalOutputService.php`
- `app/Services/DocumentWorkspaceService.php`
- `app/Views/reports/*`
- `app/Views/workspace/output.php`

Key responsibilities:

- management summary
- branch P/L and consolidated reporting
- ledger/account statement printing
- receipt / voucher / refund output
- financial audit and summary views

### 1.5 Security, audit, and operational resilience hub

- `app/Services/AuthService.php`
- `app/Services/PasswordSecurityService.php`
- `app/Services/SecuritySettingsService.php`
- `app/Services/TrustedDeviceService.php`
- `app/Services/TwoFactorService.php`
- `app/Repositories/AuditLogRepository.php`
- `app/Repositories/LoginAttemptRepository.php`
- `app/Repositories/SecurityThrottleRepository.php`
- `app/Repositories/TrustedDeviceRepository.php`
- `app/Repositories/TwoFactorRecoveryCodeRepository.php`
- `app/Services/OfflineWorkspaceService.php`
- `app/Repositories/OfflineDraftSyncRepository.php`
- `app/Services/HealthCheckService.php`

Key responsibilities:

- authentication and session hardening
- role and branch-aware access control
- audit logging for important actions
- offline emergency cache / draft sync support
- health checks and operational diagnostics

### 1.6 Module interaction rules

These interactions are non-negotiable:

1. Any persisted service creation or service financial change must synchronously update commercial obligations.
   - This usually means `ServiceWorkspaceService` and `CommercialObligationSyncService` must stay aligned.

2. Any customer receipt change must update:
   - customer receipt records
   - allocation state
   - customer credit state
   - accounting journal state

3. Any supplier payment change must update:
   - supplier payment / advance application state
   - supplier payable state
   - accounting journal state

4. Any ticket/service status change with financial impact must preserve auditability.
   - status is not enough
   - event rows and ledger consequences must remain reconstructable

5. Reports must derive from persisted financial truth, not transient UI state.

6. Printing modules must never invent financial numbers independently of the services/repositories that produced them.

---

## 2. Operational Safeguards (The "Never-Do-This" Rules)

### 2.1 Never bypass service orchestration

Never write directly to booking, receipt, supplier, receivable, payable, or journal tables from controllers or views.

Always go through the appropriate service:

- workspace/booking changes -> `BookingWorkspaceService`, `ServiceWorkspaceService`, `TravelerWorkspaceService`
- customer receipt flows -> `CustomerReceiptWorkspaceService`, `CustomerPaymentFoundationService`
- supplier payment flows -> `SupplierSettlementWorkspaceService`, `SupplierFoundationService`
- accounting setup -> `AccountingSetupService`

### 2.2 Never mutate financial truth with UI-only assumptions

Do not trust the current screen as financial truth.

Examples of historic failure patterns:

- deriving refundable credit from visible unallocated receipt figures instead of actual booking-level customer credit
- using current form fields as if they were posted values
- netting management summary figures from UI assumptions instead of persisted event data

### 2.3 Never collapse receipt/payment and allocation into one concept

This system explicitly separates:

- receipt/payment capture
- allocation/application

Never implement a shortcut like:

- `paid_amount = invoice_amount`
- `receipt save = invoice settled`

One receipt can settle many due items. One due item can be settled by many receipts.

### 2.4 Never bypass the audit trail on service events

Never change a service state like `Cancelled`, `Reissued`, `Open`, `Closed`, or void-related states without preserving:

- service event row
- acting user
- reason / note
- financial side effects
- downstream report visibility

If a change would be impossible to explain later from persisted rows, the implementation is wrong.

### 2.5 Never “fix” accounting by editing rows in place

Do not silently overwrite posted financial rows to force a new total.

Use formal patterns:

- cancellation settlement
- refund posting
- allocation release
- void / reversal
- supplier advance release / re-application

### 2.6 Never let report queries drift from financial semantics

Several bugs historically came from report-layer rewrites that changed business meaning.

Examples:

- showing supplier cost as “supplier payable” even when it had already been covered by prepaid supplier balances
- counting refunded customer money as still “received”
- showing duplicate “outstanding” concepts in printed statements

Report wording must match financial meaning exactly.

### 2.7 Never break the workspace payment panel casually

The booking workspace JS and payment strip are fragile high-traffic areas.

Historically risky areas:

- current invoice amount / paid / balance preview
- payment method Enter-key flow
- exchange settlement modal behavior
- customer selection returning into a dirty invoice

Do not combine visual/UI experiments with accounting changes in the same patch.

### 2.8 Never remove branch scoping

Every operational and reporting query must remain branch-aware.

This system supports:

- Imdad International Travel Agency
- Noble Route

All reporting, access control, and financial aggregation must preserve branch scope.

---

## 3. Accounting Integrity Standards

### 3.1 Non-negotiable financial patterns

The following patterns must always remain true:

1. Booking operation creates financial exposure.
   - service creation creates receivable/payable positions

2. Receipt/payment capture is distinct from settlement.
   - customer receipt != invoice allocation
   - supplier payment != payable application

3. Unallocated balances remain visible and meaningful.
   - customer unallocated money = customer credit
   - supplier unallocated money = supplier advance

4. Reversals must be explainable.
   - refunds, voids, releases, and reissues must leave a traceable chain

5. Ledger entries must remain balanced.
   - every financial posting must debit and credit correctly

### 3.2 Required validations before changing financial data

Before posting or modifying any financial event, the agent must ensure:

- target booking/service exists and belongs to allowed branch scope
- actor is authorized for financial action
- currency is supported and coherent with the operation
- numeric amounts are valid, non-negative, and meaningful for the operation
- target receivable/payable/credit/advance state supports the requested action
- action does not exceed available credit/advance/allocation state
- affected journal or accounting controls exist when required

Additional mandatory checks by flow:

#### Customer receipt save

- booking exists
- amount > 0
- payment currency valid
- due date only required when balance remains
- exchange settlement data valid if cross-currency flow is used

#### Customer refund

- refund must not exceed actual booking-level customer credit in that currency
- do not use raw receipt unallocated figures as the final authority

#### Supplier refund / release

- supplier refund must not exceed actual releasable supplier settlement / advance position

#### Cancellation settlement

- settlement can only follow a cancelled service
- releasing allocated/settled money must happen before shrinking receivable/payable where appropriate

#### Reissue

- reissue is not cancellation
- reissue modifies the existing service economics
- new ticket/PNR and extra charges must map cleanly to receivable/payable deltas

### 3.3 Error handling in accounting flows

Financial flows must fail loudly and transactionally.

Use this pattern:

- validate early in service layer
- use repository transactions for multi-table writes
- throw exceptions on integrity failure
- never partially commit a financial action
- never swallow repository exceptions and continue

When an accounting operation fails:

1. no partial state must remain
2. user-facing error should be business-readable
3. internal detail should remain recoverable from logs/debug context

### 3.4 Reporting standards for accounting truth

Reports must clearly distinguish:

- gross service value
- net customer receipts after refunds where business meaning requires it
- supplier paid versus supplier cost versus supplier due
- branch-local currency truth versus consolidated reporting totals

Do not mix:

- branch-local operational figures
- converted group reporting totals

without explicit labeling.

---

## 4. Stylistic & Coding Norms

### 4.1 Naming conventions

Follow the established naming style already used in the repo:

- Classes: `PascalCase`
  - `ServiceWorkspaceService`
  - `CustomerPaymentRepository`
- Methods: `camelCase`
  - `settleCancellationFinancials`
  - `updateAirTicketReissueDetails`
- Variables: `camelCase`
- Database columns: `snake_case`
- Routes: slash-based resource/action patterns
- View data keys: mostly `snake_case` or intentionally mapped arrays; preserve local convention per file

When touching an existing module, match its local naming style instead of “cleaning it up.”

### 4.2 Preferred error-handling style

Use exceptions for domain failures and persistence failures.

Preferred pattern:

- validate in service
- `throw new RuntimeException('Business-readable message.')` for user-facing domain failures where that pattern already exists
- let repositories throw on SQL/integrity issues inside transactions
- catch only where you need to convert lower-level failure into a clearer business error

Avoid:

- boolean success/failure return codes for multi-step financial logic
- silent fallback writes
- mixed “sometimes return false, sometimes throw” APIs in the same flow

### 4.3 Database interaction rules

Database interactions must follow these rules:

- PDO repositories only
- prepared statements only
- no ad hoc raw SQL in controllers or views
- transaction-safe repository methods for any multi-write operation
- repository is responsible for SQL shape
- service is responsible for business sequencing

If a query grows large, keep it in repository but preserve semantic clarity. Do not move query fragments into views or controllers.

### 4.4 API / AJAX response style

The workspace uses both normal post/redirect flows and AJAX-style JSON responses.

Expected response style for AJAX endpoints:

- predictable object
- include `ok` boolean when endpoint already uses that pattern
- include business-readable `message`
- include payload fields actually needed by the UI

Typical examples in this codebase include returning:

- `booking_id`
- `receipt_id`
- `invoice_no`
- updated customer/payment foundation payloads
- status-oriented message strings

Do not return raw stack traces or internal SQL details to browser clients.

### 4.5 Frontend norms

UI rules in this codebase:

- compact
- dense
- desktop-first
- keyboard-friendly
- operational, not marketing

Preserve:

- button-heavy workflow
- minimal wasted whitespace
- visible labels
- practical data density

When improving UI:

- prefer CSS-only changes when possible
- avoid JS rewrites for purely visual issues
- keep Enter-key workflow and tab order predictable

---

## 5. Common Pitfalls & Known Conflicts

### 5.1 Cancellation, refund, and reissue are different flows

This area caused repeated confusion.

- `Cancel Service`
  - operationally cancels the service
- `Settle Cancel`
  - posts the cancellation financial result
- `Post Refund`
  - returns releasable customer/supplier money after settlement/credit exists
- `Reissue`
  - changes the economics of an ongoing ticket; it is not a cancel flow

Never merge these mentally or in code.

### 5.2 Refund hinting versus real credit

A recurring bug:

- UI showed “available refund” from receipt-level unallocated numbers
- backend correctly enforced booking-level customer credit
- result: user saw available money that was not actually refundable

Rule:

- refund eligibility must always come from booking-level credit truth, not convenience UI totals

### 5.3 Management summary and report SQL placeholder collisions

`app/Repositories/ReportRepository.php` is a high-risk file.

Known failure mode:

- reusing named PDO placeholders across nested report subqueries
- especially when branch/date scopes are repeated for refund netting or aggregated joins
- can cause `SQLSTATE[HY093]: Invalid parameter number`

Rule:

- when duplicating branch/date clauses in one SQL statement, use distinct placeholder names or distinct scoped param sets

### 5.4 Payment preview and workspace JS are fragile

High-risk file:

- `public/assets/js/workspace.js`

Known conflict zones:

- current invoice amount / paid / balance preview
- due date popup rules
- same-currency versus exchange settlement behavior
- payment method Enter-key flow
- quick search Enter behavior
- customer selection reset flow

Rule:

- isolate payment workflow changes
- test keyboard flow after every change
- do not mix financial semantics and UI experiments in one edit

### 5.5 Output/print templates can become too internal

High-risk file:

- `app/Views/workspace/output.php`

Recurring issue:

- internal fields like event IDs or internal accounting terms leaking into customer-facing printouts

Rule:

- customer-facing print documents must be business-readable
- include operational references that matter to staff/customers:
  - booking no
  - ticket/reference no
  - PNR
  - service detail
- avoid internal-only noise unless explicitly needed for admin output

### 5.6 Supplier cost, supplier paid, and supplier due are not the same thing

A recurring reporting mistake:

- showing supplier cost under a “payable” label
- showing prepaid-covered obligations as still unpaid

Rule:

- distinguish:
  - supplier cost / purchase
  - supplier paid
  - prepaid used
  - supplier due

Do not rename financial cards casually without verifying the underlying semantics.

### 5.7 Customer selection and fresh invoice state

Known UX/data integrity issue:

- selecting or creating a customer while stale invoice/service/payment data remained on screen

Rule:

- customer selection into a new sale context should begin from a clean invoice state
- do not leave the old booking payload half-visible underneath the new customer context

### 5.8 Offline/launcher and web app are separate artifacts

The web app and Windows launcher have separate concerns:

- website/server package
- launcher app
- launcher offline page

Rule:

- server deployment changes do not automatically update launcher behavior
- launcher UI or offline behavior requires launcher-side file changes and rebuild

---

## 6. Directive Working Method for the Next AI Agent

When working in this repository, follow this order:

1. Identify the business flow first.
   - booking
   - service lifecycle
   - customer receipt/allocation
   - supplier payment/advance
   - reporting
   - output/print

2. Find the owning service and repository before editing anything.

3. Keep changes within the ownership boundary.

4. If a financial action is touched, inspect both:
   - event/orchestration service
   - accounting/journal side

5. If a report is touched, verify wording and business meaning, not just math.

6. If workspace JS is touched, assume keyboard flow can break and keep the patch minimal.

7. Do not refactor broadly while fixing a production behavior issue.

8. End every major task with:
   - files changed
   - SQL changes
   - manual test checklist
   - security checks
   - done criteria

---

## 7. Final Principle

This system is not a generic CRUD app.

It is a travel-agency operations system where:

- booking workspace is operational truth
- ledger-backed financial state is accounting truth
- allocations and releases matter as much as payments
- every status-changing action must remain explainable later

If a proposed change makes the system easier to code but harder to audit, reconcile, or explain to staff, do not do it.
