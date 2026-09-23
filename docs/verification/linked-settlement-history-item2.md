# Item 2: reversed linked adjustments in ledgers

> Superseded on 2026-09-22: reversal history is now in the standalone
> "Reversed Linked Adjustments" report under Reports, with screen, CSV and
> print output. It is no longer appended to Account or Supplier Ledgers.
> The notes below document the original 2026-09-10 implementation.

Local implementation and verification: 2026-09-10. Production not modified.

## Scope

Account Ledger and Supplier Ledger now include a separate "Reversed Linked
Adjustments" history section on screen and in print. It shows each original
adjustment and final void with their actual journal amounts, reference,
native currency, date and reason. All money displays use whole numbers.

This is informational history, NOT additional debit/credit movements.
Current ledger rows, summaries, balances, cash movements and CSV layout
are unchanged. No workspace.js, refund, payment or treasury changes in this item.

Partial releases distinguish original amount from the amount remaining at
void. Full releases show zero additional void amount when no further journal
was needed. Earlier releases are summarised, not assigned invented dates.
Missing journals are marked unavailable, not silently presented as zero.
Legacy repair journals that differ from remaining allocations are explicitly
identified for review, not recalculated.

## Files Changed

- app/Repositories/LinkedSettlementHistoryRepository.php (new read-only queries)
- app/Services/LinkedSettlementHistoryService.php (new history presentation)
- app/Services/ReportService.php (screen/print integration only)
- app/Views/reports/partials/linked_settlement_history.php (new shared section)
- app/Views/reports/index.php
- app/Views/reports/account_ledger_print.php
- app/Views/reports/report_print.php
- tests/counterparty_offset_regression.php (additional rollback-only cases)
- tests/linked_settlement_history_report_regression.php (new read-only integration)

Other dirty files in this worktree belong to previous tasks.

## SQL and Security

No migrations or data-repair scripts required. Application changes are SELECT-only.
Regression fixtures roll back; existing business records are not changed.
Branch scope fails closed when empty. Currency/account/supplier and known
booking/customer/airline associations are filtered using bound parameters.
All history cell text is HTML escaped. Existing authorization remains in place.

## Verification

- 47 checks passed in counterparty_offset_regression.php, including 18 history
  checks for partial/full releases, scopes, dates, escaping and unchanged balances.
- Full report integration passed for both screen and print templates.
- The local database exposes 27 reversed adjustments in each ledger's history.
- Main output before/after matched byte-for-byte by SHA-256:
  Account: 1,970 rows, 39cb04ecd12003d0dc08957e92fe1bfd78fa53fc4f6681d6bbf7502833b7eedf
  Supplier: 977 rows, 249ae586f67df9dd56e648ad0b223132084557ac428026ee21cfcc9d5222ab1d
  Hash scope includes rows, summary cards, footers and account summaries.
- PHP syntax checks passed for all nine implementation/test files.
- Broader suite: 12 tests passed; account_ledger_summary_regression.php failed
  its existing cross-booking AED 844 credit assertion at line 408.
  That test invokes AccountLedgerService::buildReport directly. Both that
  service and the failing test are unchanged from Git HEAD; the new history
  code is not on the failing path. No fix or reclassification attempted here.
- Logs: storage/logs/item2-verification-summary.log and item2-*.log.
- Live browser click-through was not performed; full PHP templates were rendered.

## Manual Checklist

1. Open Account Ledger for AJWA SWAT SHJ, with booking/customer/date filters clear.
   Scroll below the existing ledger to Reversed Linked Adjustments.
2. Open Supplier Ledger for AJWA SWAT TOURISM and find the same adjustment
   references and original/void pairs, with native AED amounts.
3. Confirm the main balances are unchanged. Check print preview includes history.
4. Filter by original date or void date: both paired entries remain visible for
   context. An unrelated date, currency or branch must not include those headers.

Old released invoice links may have been removed before this change. Modern
manual postings retain selected invoice IDs in the audit log and remain
searchable after a full release. Where historical evidence is missing, clear
booking/customer filters and search by account, supplier or adjustment number.
Do not assume header totals are the share allocated to a selected invoice.

## Done Criteria

The item-2 history display and its focused checks are complete locally.
No automatic adjustments were re-enabled and no new financial postings occur.
The unrelated summary assertion remains an explicitly recorded follow-up;
this document does not certify the entire system or production deployment.
