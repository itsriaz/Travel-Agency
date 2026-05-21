# Production Release Checklist

## Purpose
- Provide a single operator checklist for the final production release decision.
- Keep release sign-off separate from development discussions.
- Use this together with:
  - [C:\xampp\htdocs\Travel-Agency\docs\production-deployment-runbook.md](C:\xampp\htdocs\Travel-Agency\docs\production-deployment-runbook.md)
  - [C:\xampp\htdocs\Travel-Agency\docs\final-production-test-checklist.md](C:\xampp\htdocs\Travel-Agency\docs\final-production-test-checklist.md)

## Explicit exclusion
- 2FA production configuration is intentionally excluded here and must be handled as the final separate stage.

---

## A. Release gate

All items below must be true before deployment starts.

1. Approved release branch/tag is identified.
2. Working tree is clean for the release artifact.
3. Recovery tag/rollback target is identified.
4. Latest production database backup plan is ready.
5. Deployment operator is assigned.
6. Rollback decision owner is assigned.
7. Release window is approved.
8. `scripts/preflight_production.php` has passed.
9. `scripts/audit_environment_config.php` has passed against production settings.
10. Final production test checklist has passed on controlled test data.

---

## B. Production configuration gate

1. `APP_ENV=production`
2. `APP_DEBUG=false`
3. `APP_URL` points to the real production URL
4. `APP_KEY` is a private `base64:` 32-byte key and is not the local fallback value
5. `DB_HOST` is the real production database host
6. `DB_DATABASE` is the real production database name
7. `DB_USERNAME` is the real production database user
8. `DB_PASSWORD` is set
9. `DB_CHARSET=utf8mb4`
10. `PASSWORD_RESET_DELIVERY_MODE=disabled` unless a controlled alternative is approved
11. `scripts/audit_environment_config.php` passes with no failures

---

## C. Filesystem and server gate

1. Web root points to `public`
2. Repository root is not publicly exposed
3. `storage/logs` is writable
4. `storage/documents` is writable
5. PHP version is `8.2+`
6. PDO MySQL is enabled
7. `mbstring` is enabled
8. `fileinfo` is enabled
9. sessions are working
10. `random_bytes` / OpenSSL support is available

---

## D. Database gate

1. Fresh pre-release production backup completed
2. Backup file location is confirmed
3. Migration status checked before deploy
4. Pending migrations are understood
5. Migration operator is assigned
6. Restore procedure is available before `up` is run
7. Production preflight has no critical accounting integrity failures
8. Any random local/staging test data has been reset before final validation

---

## E. Security gate

1. Login requires authentication as expected
2. Reports require authentication
3. Payment routes require authentication
4. Void routes are POST-only
5. Metadata edit routes are POST-only
6. Finder endpoints are protected
7. No stack traces are shown to users in production mode
8. Password reset links are not being plaintext-logged in production by default
9. Audit log table is writable
10. 2FA production stage is still pending and intentionally separate

---

## F. Customer finance gate

1. Workspace opens correctly for a valid booking
2. Customer `Payment History` opens
3. New customer receipt can be saved on a safe test booking
4. Receipt output opens
5. Receipt allocation updates outstanding correctly
6. Voided receipt is excluded from active totals
7. Voided receipt remains visible in history
8. Metadata-only receipt edit still works
9. Recreate-from-void workflow still works

---

## G. Supplier finance gate

1. Supplier modal opens correctly
2. Postpaid supplier settlement opens
3. Supplier payment can be saved on a safe test booking
4. Supplier voucher output opens
5. Supplier payment allocation updates payable correctly
6. Voided supplier payment is excluded from active totals
7. Voided supplier payment remains visible in history
8. Metadata-only supplier payment edit still works
9. Recreate-from-void supplier workflow still works
10. Supplier finder can reopen history for fully settled bookings

---

## H. Reporting gate

1. Main reports page loads
2. Supplier postpaid payment report loads
3. Supplier prepaid payment report loads
4. Supplier all-payments report loads
5. `Void / Reversal Register` loads
6. `Finance Audit Trail` loads
7. `Unallocated Money Trace` loads and every open balance links back to the source receipt/payment
8. CSV export works for at least one report
9. Branch filtering works on reports
10. Date filtering works on reports
11. Currency filtering works where expected

---

## I. Error and logging gate

1. `storage/logs/app-runtime.log` can be written
2. `storage/logs/php-error.log` can be written
3. Production runtime errors do not show traces to users
4. Controlled local debug mode still works when intentionally enabled outside production
5. No leftover runtime junk is being tracked again by Git

---

## J. Backup and rollback gate

1. Fresh production DB backup exists
2. Rollback code target is known
3. Rollback operator has access
4. Restore command/process is documented
5. Team agrees on rollback trigger conditions

---

## K. Final sign-off

Release is approved only if:

1. All gates above are passed
2. No blocker remains in customer finance flow
3. No blocker remains in supplier finance flow
4. Reports are operational
5. Production config guardrails pass
6. Migration status is acceptable
7. Backup and rollback are ready

---

## L. Final note

- Execute this checklist during the final controlled release pass.
- Complete 2FA production configuration only after the items above are satisfied.
