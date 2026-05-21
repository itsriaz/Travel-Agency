# Final Production Test Checklist

## Purpose
- Use this checklist after implementation is complete and before the live launch.
- Test on a copy of production-style data first.
- Use controlled test bookings only.
- Record every failed step with screenshot, user role, branch, booking reference, and exact time.

## Test Users Needed
- `super_admin`
- `branch_admin` for Swat
- `branch_admin` for Dubai
- `employee` for Swat
- `employee` for Dubai

## Stop Rules
Stop testing and fix before continuing if any of these happen:
- Login fails for valid users.
- Workspace cannot open.
- Any receipt, supplier payment, void, cancel, refund, or reissue creates wrong balances.
- Any journal entry becomes unbalanced.
- A normal user sees a PHP error, stack trace, or database message.
- An employee can perform super-admin or branch-admin financial actions.

---

## 1. Preflight And Server Readiness

1. Run:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\preflight_production.php"
```

2. Confirm it ends with `Preflight passed`.
3. Run:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\audit_environment_config.php"
```

4. Confirm production config does not use the bundled/default `APP_KEY`, placeholder database credentials, insecure cookies, debug mode, or a public `.env`.
5. Run:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\database\migrate.php" status
```

6. Confirm pending migrations are `0`.
7. Open `/health?token=<HEALTH_CHECK_TOKEN>`.
8. Confirm `status` is `ok`.
9. Open the site in a browser.
10. Confirm the login page loads without warnings.

Expected result:
- No failed preflight checks.
- No failed environment configuration checks.
- No pending migrations.
- Health check is green.

---

## 2. Backup And Recovery Readiness

1. Run:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php" --label=final-test
```

2. Confirm a `.sql` file is created under `storage/backups/database`.
3. Confirm a matching `.json` manifest is created.
4. Confirm the manifest contains `bytes` and `sha256`.
5. Confirm backup files are not inside `public`.
6. Confirm backup files are not committed to GitHub.

Expected result:
- Backup completes before any migration or launch action.
- Backup can be located by the operator.

---

## 2A. Clean Test Data Reset

Use this only before final testing on local/staging data. It refuses to run when `APP_ENV=production`.

1. Take a backup first.
2. Dry-run the reset:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\reset_non_production_data.php" --confirm-non-production-reset
```

3. Review the row counts.
4. Apply the reset only when you are sure this is local/staging:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\reset_non_production_data.php" --confirm-non-production-reset --apply
```

5. Add `--include-suppliers` if supplier master data is also random test data.
6. Add `--include-exchange-rates` if exchange-rate rows should also be cleared.
7. Verify the reset result:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\verify_clean_test_database.php"
```

Expected result:
- Random operational data is cleared.
- Users, roles, branches, currencies, service types, payment methods, document types, posting rules, and chart of accounts remain.

---

## 3. Authentication And Role Access

1. Login as `employee`.
2. Confirm the booking workspace opens by default.
3. Confirm admin pages such as master data, expenses, and accounting engine are not accessible.
4. Confirm employee cannot see or use void, cancel, refund, or reissue controls.
5. Login as `branch_admin`.
6. Confirm branch admin can access normal branch work.
7. Confirm branch admin can perform controlled financial actions for their own branch only.
8. Confirm branch admin cannot access another branch booking.
9. Login as `super_admin`.
10. Confirm dashboard opens and workspace remains reachable.
11. Confirm super admin can access admin/security/config areas.
12. Logout and confirm protected pages redirect to login.

Expected result:
- Employee is blocked from high-impact financial actions.
- Branch admin is branch-limited.
- Super admin can manage the system.

---

## 4. Booking Workspace Basics

1. Create or open a safe test booking.
2. Confirm booking reference, branch, lead traveler, and customer details display correctly.
3. Add an additional traveler.
4. Remove or detach an additional traveler where allowed.
5. Add an air ticket service line.
6. Add another service type if available, such as visa, hotel, transport, tour, or other.
7. Save the booking.
8. Reload the booking.
9. Confirm services, travelers, prices, suppliers, and notes remain correct.

Expected result:
- Booking remains the operational master record.
- Service lines create/update receivable and payable positions correctly.

---

## 5. Customer Receipt And Allocation

1. Open a booking with an open customer receivable.
2. Save a partial customer receipt.
3. Confirm invoice balance reduces by the allocated amount.
4. Confirm unallocated amount becomes customer credit if receipt is larger than allocation.
5. Open `Payment History`.
6. Confirm receipt appears with correct currency, method, amount, allocation, and status.
7. Open receipt print/output.
8. Save metadata only, such as reference number, bank/card detail, or remarks.
9. Confirm financial fields of posted receipt cannot be edited directly.
10. Create a second allocation against the same due item.

Expected result:
- Receipt and allocation remain separate.
- One receipt can allocate to multiple dues.
- One due can receive multiple allocations.

---

## 6. Customer Receipt Void

1. Login as `employee`.
2. Confirm employee cannot void a receipt.
3. Login as `branch_admin` or `super_admin`.
4. Void a safe test receipt with a clear reason.
5. Confirm original receipt status becomes `void`.
6. Confirm allocations are reversed.
7. Confirm customer outstanding is restored.
8. Confirm voided receipt remains visible in history.
9. Confirm recreate-from-void opens a fresh draft.
10. Confirm recreated receipt saves as a new receipt, not an edit of the voided receipt.
11. Confirm void appears in reports and audit trail.

Expected result:
- Voids are controlled, reversible in accounting impact, and auditable.

---

## 7. Supplier Payments And Advances

1. Open supplier settlement on a booking with supplier payable.
2. Save a normal supplier payment.
3. Confirm payable reduces correctly.
4. Save supplier payment metadata only.
5. Confirm financial fields of posted supplier payment cannot be edited directly.
6. Create a supplier advance if supported for the selected supplier.
7. Apply supplier advance to a payable.
8. Confirm supplier advance and payable balances update correctly.
9. Open supplier payment history.
10. Open supplier voucher/output.

Expected result:
- Supplier payment headers and allocations remain separate.
- Supplier advances are tracked separately from normal payables.

---

## 8. Supplier Payment Void

1. Login as `employee`.
2. Confirm employee cannot void supplier payment.
3. Login as `branch_admin` or `super_admin`.
4. Void a safe supplier payment with a clear reason.
5. Confirm original payment status becomes `void`.
6. Confirm supplier allocation is reversed.
7. Confirm supplier obligation/payable is restored.
8. Confirm voided payment remains visible in history.
9. Confirm recreate-from-void opens a fresh supplier payment draft.
10. Confirm void appears in reports and audit trail.

Expected result:
- Supplier voids are restricted, branch-aware, and auditable.

---

## 9. Cancel, Cancellation Financials, Refund, Reissue

### Cancel
1. Open an active service line.
2. Login as employee and confirm cancel is blocked.
3. Login as branch admin or super admin.
4. Cancel the service with reason and event date.
5. Confirm service status changes to cancelled.
6. Confirm duplicate cancellation is blocked.

### Cancellation Financials
1. On a cancelled service, settle customer penalty if applicable.
2. Settle supplier penalty if applicable.
3. Confirm receivable/payable positions reflect the cancellation settlement.
4. Confirm audit event is recorded.

### Refund
1. Use a booking with customer credit or supplier credit/advance.
2. Post a customer refund amount or supplier refund amount.
3. Confirm refund cannot exceed available credit/advance.
4. Confirm journal entry is balanced.
5. Confirm refund event appears in service event history/audit trail.

### Reissue
1. Use an air ticket service line.
2. Reissue with reason, event date, fare difference, tax difference, service fee, or supplier cost difference as appropriate.
3. Confirm non-air-ticket service reissue is blocked.
4. Confirm new receivable/payable impact is correct.
5. Confirm journal entry is balanced.

Expected result:
- Only super admin or branch admin can post these actions.
- Service event history preserves cancel/refund/reissue trail.
- Ledger remains balanced.

---

## 10. Documents And Expense Attachments

1. Upload allowed files: PDF, JPG, PNG, WEBP.
2. Confirm files download correctly.
3. Try blocked files: PHP, JS, HTML, SVG, EXE.
4. Confirm blocked files are rejected.
5. Confirm files are stored under `storage/documents`, not `public`.
6. Upload an expense proof attachment.
7. Download the expense proof.
8. Confirm tampered or missing files do not expose server paths.
9. Run the storage audit:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\audit_storage_documents.php"
```

Expected result:
- Uploads are type-checked and stored outside public web root.
- Active database file records point to real files with matching size and SHA-256 hash.

---

## 11. Reports

1. Open `/reports`.
2. Test supplier postpaid payments.
3. Test supplier prepaid payments.
4. Test supplier all-payments report.
5. Test customer outstanding report if present.
6. Test supplier outstanding report if present.
7. Test `Void / Reversal Register`.
8. Test `Finance Audit Trail`.
9. Test `Accounting Integrity Checks`.
10. Test `Unallocated Money Trace`.
11. Confirm every unallocated customer receipt, supplier payment, and supplier advance has a clickable source receipt/payment link.
12. Confirm any unallocated balance is intentional, explainable, and can be allocated/applied from the linked booking or payment screen.
13. Confirm accounting integrity shows zero critical issues.
14. Filter by Swat branch.
15. Filter by Dubai branch.
16. Filter by date range.
17. Filter by currency where available.
18. Export at least one CSV.
19. Open the CSV and confirm columns and totals look correct.

Expected result:
- Reports load without errors.
- Branch/date/currency filters work.
- Voids and service events are visible for audit.
- No unallocated money is left unexplained before launch.
- Accounting integrity exceptions are visible to financial admins only.

---

## 12. Offline Launcher And Draft Queue

1. Install or open the Windows launcher.
2. Confirm it opens the online URL.
3. Login through the launcher.
4. Confirm offline cache refreshes.
5. Stop the server or disconnect internet on a test machine.
6. Confirm offline screen appears.
7. Search cached bookings, travelers, customer dues, and suppliers.
8. Queue one traveler draft.
9. Queue one booking draft.
10. Confirm receipts, allocations, supplier payments, advances, voids, refunds, and reissues are blocked offline.
11. Restore internet/server.
12. Click `Sync Drafts`.
13. Confirm drafts become synced.
14. Click `Sync Drafts` again.
15. Confirm no duplicate records are created.

Expected result:
- Offline mode is emergency-only.
- Only safe drafts sync.
- Financial posting remains online-only.

---

## 13. Two-Branch Testing

1. As Swat employee, create or open Swat booking.
2. Confirm Dubai bookings are not available unless role permits.
3. As Dubai employee, create or open Dubai booking.
4. Confirm Swat bookings are not available unless role permits.
5. Confirm reports can separate Swat and Dubai.
6. Confirm branch admin cannot financially alter the other branch.
7. Confirm super admin can review both branches.

Expected result:
- Branch separation is enforced in workspace, finance actions, and reports.

---

## 14. Security And Error Handling

1. In production mode, visit a non-existing page.
2. Confirm normal 404 page appears.
3. Trigger a safe validation error.
4. Confirm no stack trace appears.
5. Confirm CSRF-protected forms reject invalid/missing CSRF tokens.
6. Confirm protected endpoints redirect or reject when logged out.
7. Confirm `/health` requires token in production.
8. Confirm response headers include frame, content-type, referrer, permissions, CSP, and HTTPS HSTS where applicable.

Expected result:
- Errors are controlled.
- Sensitive internals are not shown to normal users.

---

## 15. Final Sign-Off

Launch is acceptable only when:

1. Preflight passed.
2. Backup completed and file location is known.
3. Migrations are complete.
4. Login and logout passed.
5. Employee, branch admin, and super admin roles passed.
6. Swat and Dubai branch separation passed.
7. Customer receipt and void passed.
8. Supplier payment and void passed.
9. Cancel, cancellation financials, refund, and reissue passed.
10. Reports and CSV export passed.
11. Upload security passed.
12. Offline launcher and sync passed.
13. Health check passed.
14. No unbalanced journal entries exist.
15. No production stack traces are visible.

## Tester Sign-Off

- Tester name:
- Date:
- Test environment:
- Database backup file:
- Release package:
- Passed:
- Failed items:
- Management approval:
