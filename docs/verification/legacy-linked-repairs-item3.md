# Item 3: legacy linked repair script compatibility

## Outcome and Scope

The two historical booking-specific repair scripts now pass their selected
customer_receivable_item_id and supplier_obligation_id to CounterpartyOffsetService.
Previously these arguments were missing after the manual-selection requirement
was introduced. Applying a repair would fail when posting its replacement
settlement; the existing transaction would roll back the earlier void.

No application service, controller, report, payment/refund workflow or JavaScript
was changed for this item. Automatic settlement remains disabled.
Neither historical repair was applied to local or production business records.

## Files Changed

- scripts/repair_bk000001_bk000002_account_supplier_offset.php
- scripts/repair_bk532_bk533_account_supplier_settlement.php
- tests/legacy_linked_repair_script_readiness.php
- This verification note.

## Safeguards

- CLI only; unknown or conflicting mode arguments are rejected before bootstrap.
- Default mode remains read-only. --rehearse runs existing validations and
  financial actions in the transaction but rolls back instead of committing.
- More than one matching invoice or original offset now stops for review
  instead of silently selecting the first row.
- Approved historical PKR/branch scope is enforced before writes.
- Obvious reruns of an already tagged replacement are rejected.
- Existing post-repair amount checks and exception rollback remain intact.
- These are historical repair utilities, not generic settlement commands.
  They still require a reviewed current database and a backup before any --apply.
  Uploading either file does not execute it.

## SQL and Security

No migration or database repair executed. Fixture-based settlement tests roll
back. Bound inputs and the service's financial-admin checks remain in place.
No public route was added. No credentials or permissions were changed.

## Verification

- 14 script readiness checks passed, including real CLI rejection of unknown
  and conflicting arguments before database bootstrap.
- counterparty_offset_regression.php: 47 checks passed.
- counterparty_auto_offset_regression.php passed.
- linked_settlement_history_report_regression.php passed.
- PHP syntax is checked using TOKEN_PARSE for both script sources.
- git diff --check passed for the changed scripts.
- Logs: storage/logs/item3-*.log.

The historical repairs themselves were NOT rehearsed against current business
records. Script-input wiring and guards were checked; shared settlement behavior
was exercised using transaction-rolled-back fixtures. A full historical rehearsal
remains conditional on the fresh production database matching the approved case.

## Manual Checklist and Done Criteria

1. No screen test is required: normal screens and their shared services are unchanged.
2. Optional CLI validation: run php tests/legacy_linked_repair_script_readiness.php.
3. Do not run either historical script with --apply merely because it was uploaded.
4. After the refreshed production database is reviewed, decide whether either
   repair is relevant; only then review its dry run and rollback rehearsal.

Item 3 compatibility work is complete locally. The separate existing AED 844
cross-booking account-summary assertion remains pending investigation.
Production deployment/reversal instructions will be prepared after local tasks
and the fresh production-data review are finished.
