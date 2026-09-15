# Account Ledger transferred-credit running balance

## Change

Incoming transferred credit now reduces the receiving invoice's running balance.
Outgoing transferred credit consumes the sending booking's available credit.
The displayed Debit/Credit amounts and all receipt/summary classifications remain
unchanged. No automatic allocation is introduced.

Files changed:
- app/Services/AccountLedgerService.php: one balance-delta expression plus comments.
- tests/account_ledger_credit_transfer_balance_regression.php: new read-only tests.
- This verification note.

SQL changes: none. No data repair or migration required. No production action taken.
No repository, payment, refund, supplier ledger, treasury or workspace.js edits.

## Verification

- Local BK-000277: invoice AED 844, transferred credit AED 844, final running
  balance now zero rather than AED 1,688; database outstanding remains zero.
- 18 new checks passed. The balance checks failed before the fix and passed after.
- Full local Account Ledger comparison: 1,970 rows retain exactly identical
  non-balance fields, and every summary is unchanged. 24 row balances changed.
- Eight targeted regression scripts passed:
  account_ledger_credit_transfer_balance_regression,
  account_ledger_receipt_balance_regression,
  account_ledger_cancellation_currency_regression,
  counterparty_offset_regression, counterparty_auto_offset_regression,
  linked_settlement_history_report_regression,
  report_counterparty_amount_filters_regression, supplier_ledger_direction_regression.
- Logs are in storage/logs/credit-balance-*.log.
- No browser click-through performed; local repository/service outputs and
  existing full report-template integration tests were executed.

Tests include incoming/outgoing credit, partial payments, two allocations,
advance-to-invoice credit, PKR/AED/USD and separately denominated cross-branch
inputs. This verifies presentation, NOT an end-to-end FX allocation workflow;
currency conversion and repository amount selection were intentionally unchanged.

The pre-existing account_ledger_summary_regression.php expectation that summary
Debit includes AED 844 transferred credit remains unresolved. This change does
not alter summary semantics merely to make that older assertion pass.

## Manual Checklist

1. Open Account Ledger and filter BK-000277.
2. Confirm invoice AED 844 and incoming credit AED 844 remain separate rows.
3. Confirm the last running balance is zero.
4. Check a partial-credit example: invoice 1,450 minus credit 844 leaves 606.
5. Confirm original receipt totals and supplier balances have not changed.

## Security and Done Criteria

This is read-only report arithmetic with no new inputs, routes or privileges.
Existing branch scope and report authorization are untouched. Tests use
in-memory scenarios; shared financial regression fixtures roll back.

The requested running-balance display fix is complete locally. Production
instructions remain deferred until all local tasks and fresh-data review finish.
