# Phase 2: manual linked-settlement certification

Local certification date: 2026-09-15. Production was not modified.

## Certified behavior

- All compatibility auto-settlement entry points return no settlements.
- A financial administrator must explicitly select one account-holder invoice,
  one supplier invoice, one branch, one currency and a positive amount.
- Manual adjustments are non-cash AP-to-AR journals. They create no customer
  receipt, supplier payment or treasury transaction.
- Passenger invoice paid/outstanding fields remain unchanged. The independent
  account-holder recovery appears only in the Account Ledger.
- Supplier payable reduction, partial/full voids and prior-release reversals
  remain balanced and transaction-safe.
- Reversed linked adjustments are informational history and do not enter current
  ledger rows, summary cards, footer totals or CSV output.

## Currency and dimensional matrix

`tests/linked_settlement_currency_matrix_regression.php` posts and reverses
rollback-only PKR, AED and USD examples. For each currency it verifies:

- PKR/AED/USD 400 partial plus 600 final adjustment against a 1,000 pair.
- supplier payable closes while passenger paid and outstanding values do not move.
- AP_CONTROL debit equals AR_CONTROL credit in the selected native currency.
- reversing 600 then 400 restores the supplier payable to 1,000.
- original and reversal journals net to zero by control account.
- a PKR invoice pair cannot be posted as AED.
- invoices from different branches cannot be combined even when the administrator
  has access to both branches.
- receipt, supplier-payment and treasury row counts remain unchanged.

All 24 checks passed and the outer transaction rolled back. No QA records remain.

## Historical reversal verification

The read-only preview and direct database checks report:

- active automatic linked offsets: 0
- reversed automatic linked offsets: 27
- posted manual linked offsets: 1
- preserved manual offset: ID 37, PKR 55,000
- broken active offset allocation pairs: 0

The separate history integration reports the same 27 reversed adjustments in
both Account Ledger and Supplier Ledger. Screen/print history matches, CSV is
unchanged, and current report summaries remain outside the history component.

## Verification commands

- `php tests/counterparty_auto_offset_regression.php`
- `php tests/counterparty_offset_regression.php`
- `php tests/linked_settlement_currency_matrix_regression.php`
- `php tests/linked_settlement_history_report_regression.php`
- `php tests/counterparty_offset_admin_ui_readiness.php`
- `php tests/account_supplier_link_admin_ui_readiness.php`
- `php tests/legacy_linked_repair_script_readiness.php`
- `php scripts/preview_automatic_linked_settlement_reversals.php`
- `php tests/financial_invariants_audit.php`

The focused suites passed. The final broad invariant audit still fails for three
previously identified matters outside the linked-settlement implementation:

- BK-000274 has a historical AED 585 receivable header/allocation mismatch.
- existing cross-branch supplier allocations are rejected by an audit assertion
  that does not yet model the supported cross-branch payment workflow.
- historical journal 4110 requires review of its branch attribution.

These failures were not changed or hidden. They remain later, separate tasks.

## SQL, security and done criteria

No migration or permanent database write was made. Fixtures run inside a database
transaction and always roll back. Existing financial-admin authorization, CSRF,
branch access, bound SQL inputs and HTML escaping checks pass.

Phase 2 is complete locally when the test and this note are committed on top of
checkpoint `11f76357258b35fad14d20aea8e088b3f3f47b13`.
