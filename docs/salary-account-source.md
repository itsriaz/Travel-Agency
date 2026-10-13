# Salary Account Source

Salary now selects `business_sources.id`, the same Account master used by the booking workspace. Existing `employee_salary_records.business_source_id` and salary payments are not rewritten. Basic Salary and Bonus remain staff-entered amounts. The Link Employee screen and user-to-account table are retired by migration `20261009_000078_salary_account_source.php`.

The Employee Sales Snapshot remains read-only. It groups active PKR/AED/USD account names only when removing one standalone currency word leaves the same case-insensitive name, and no currency appears twice in that group. Ambiguous or currency-less names stay separate. For example, `ABUBAKAR PKR SALES` and `abubakar AED sales` pair; `ABUBAKAR AED SALES EXTRA` does not. The snapshot never calculates or posts Bonus.

Salary create/edit/pay/void operations write a required `audit_logs` record in the same database transaction. Browser salary actions also write paired `started` and `committed`/`rejected` diagnostic entries to `storage/logs/salary-operations-YYYYMMDD.jsonl`. The diagnostic file is not accounting truth and excludes free-text notes, reasons, bank details, and credentials. If it cannot be written, `app-runtime.log` records `salary_operation_trace.write_failed`; the database audit remains mandatory.

Read an incident without changing data:

```sh
php scripts/inspect_salary_operation_trace.php --record=123
php scripts/inspect_salary_operation_trace.php --payment=456
php scripts/inspect_salary_operation_trace.php --account=11 --limit=20
```

## Production Deployment

1. Upload the application files and the new migration. During deployment, avoid concurrent salary entry so old code cannot write while the table is being retired.
2. Run `php scripts/inspect_salary_account_source.php` and retain its account pairs, record/payment counts, currency totals, and orphan count. Stop if orphan count is nonzero or the expected account pairs are missing.
3. Make and verify a database backup outside the web root. The migration drops the old link table; its contents cannot be reconstructed from the new salary rows. Do not proceed if the backup fails.
4. Run `php database/migrate.php status`, then `php database/migrate.php up` only after confirming the expected pending migration set. The migration does not change salary records or payments.
5. Run the read-only inspection again. Record/payment counts and currency totals must match step 2; `legacy_link_table_exists` must be false.
6. Open Salary locally in the production UI, select an account, confirm the PKR/AED snapshot cards, enter a small genuine salary only if operationally appropriate, and check Salary Report and the corresponding audit/trace entries. Do not use the rollback-based regression test on production.

Local regression: `php tests/salary_account_source_regression.php`. It creates salary rows and audit entries in an outer transaction and rolls them back. It also checks paired diagnostic entries for a rejected request.
