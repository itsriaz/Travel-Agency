# Production Deployment Runbook

## Purpose
- Provide a controlled deployment, rollback, and recovery procedure for the Travel Agency Operations and Accounting System.
- Keep production changes repeatable and low-risk.
- This runbook is written for the current codebase state after:
  - payment/void/reversal hardening
  - production config support
  - repo/runtime cleanup
  - finance reporting additions

## Scope
- Code deployment
- Environment setup
- Database migration
- Backup and rollback
- Post-deploy smoke testing

## Explicit exclusion
- 2FA is enforced by default when `APP_ENV=production`. Only use `TWO_FACTOR_TEMPORARILY_DISABLED=true` during a controlled emergency window.

---

## 1. Pre-deployment prerequisites

### Server requirements
- PHP `8.2+`
- MySQL or MariaDB compatible with current migration set
- Apache or equivalent web server
- PDO MySQL enabled
- `mbstring` enabled
- `fileinfo` enabled
- sessions enabled
- `openssl` / `random_bytes` support enabled

### Required writable directories
- `storage/logs`
- `storage/documents`

### Required web-server rule
- Document root must point to:
  - `C:\xampp\htdocs\Travel-Agency\public`
- Do not expose the repository root directly as the public web root.

### Required production environment values
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=<real production base URL>`
- `APP_KEY=<real production app key>`
- `APP_TIMEZONE=Asia/Karachi` or the approved production timezone
- `DB_HOST=<production database host>`
- `DB_PORT=<production database port>`
- `DB_DATABASE=<production database name>`
- `DB_USERNAME=<production database user>`
- `DB_PASSWORD=<production database password>`
- `DB_CHARSET=utf8mb4`
- `HEALTH_CHECK_TOKEN=<long random operator-only token>`

### Password reset delivery note
- Current supported production-safe default:
- `PASSWORD_RESET_DELIVERY_MODE=disabled`
- `TWO_FACTOR_TEMPORARILY_DISABLED=false`
- Do not enable plaintext reset-link logging in production unless there is a temporary controlled incident process.

### Environment template
- Use `.env.production.example` as the checklist for production values.
- Preferred: set the same values in the hosting control panel or Apache/PHP environment.
- Supported fallback: create a private `.env` file in the project root beside `app`, `config`, and `public`, then copy the values from `.env.production.example` and replace placeholders.
- Never place `.env` inside `public`.
- Never commit the real `.env` file to GitHub.
- Keep `APP_KEY` private forever. If it is lost, existing encrypted/signed values may become unreadable or invalid. If it leaks, rotate it during a controlled maintenance window.
- Generate a real app key with:

```powershell
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$bytes = New-Object byte[] 32
$rng.GetBytes($bytes)
"base64:" + [Convert]::ToBase64String($bytes)
$rng.Dispose()
```

### Windows launcher production config
- Copy `launcher/launcher-config.production.example.json` to `launcher/launcher-config.json`.
- Replace `serverUrl` with the real HTTPS production URL.
- Rebuild the launcher from `launcher` with:

```powershell
npm.cmd run package:win
```

---

## 2. Pre-deployment checklist

Before any production release:

1. Confirm branch/tag to deploy.
2. Confirm working tree is clean in the deployment artifact or release branch.
3. Confirm latest database backup exists.
4. Confirm current production backup location outside the repo.
5. Confirm production environment variables are prepared.
6. Confirm `APP_DEBUG=false`.
7. Confirm `APP_ENV=production`.
8. Confirm writable permissions for `storage/logs` and `storage/documents`.
9. Confirm the deployment operator has rollback access.
10. Confirm release window and responsible operator.

### Automated preflight
Run the read-only preflight command before building or uploading the release:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\preflight_production.php"
```

Continue only if the command ends with `Preflight passed`.

---

## 3. Database backup before deployment

Always back up the production database before running migrations.

### Minimum rule
- Never deploy without a fresh pre-release database backup.

### Recommended command
From the project root, run:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php" --label=before-release
```

For Hostinger or another server, use that server's PHP path and project path. If `mysqldump` is not found automatically, set `MYSQLDUMP_PATH` in the private production environment or pass it directly:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php" --label=before-release --mysqldump="C:\xampp\mysql\bin\mysqldump.exe"
```

The script reads the same database settings as the app, writes the SQL file to `storage/backups/database`, and creates a JSON manifest with file size and SHA-256 hash.

### Backup naming recommendation
- `travel_agency_ops_prod_YYYYMMDD_HHMMSS_before_<release-tag>.sql`

### Repo note
- The repository currently contains a committed recovery SQL backup for controlled recovery work:
  - `storage/backups/database/travel_agency_ops_20260518_170457_stable-after-payment-layout-loss-display.sql`
- Future production backups should be stored outside the public code repository.

---

## 4. Code deployment procedure

### Build a clean upload package
From the project root:

```powershell
.\scripts\build_release_package.ps1
```

The script creates:
- `storage\release-packages\<release-name>`
- `storage\release-packages\<release-name>.zip`

The package excludes local secrets, logs, customer documents, database backups, browser profiles, launcher `node_modules`, and built EXE files.

### Step-by-step
1. Put the application into a controlled release window.
2. Build a clean upload package.
3. Upload the clean release folder or zip, not the full development folder.
3. Confirm the deployed code matches the intended release branch/tag.
4. Confirm `public` remains the web root.
5. Confirm production environment variables are loaded by the web server or PHP runtime.

### Required config posture after deployment
- `APP_ENV=production`
- `APP_DEBUG=false`
- `PASSWORD_RESET_DELIVERY_MODE=disabled` unless a controlled alternative is approved

---

## 5. Migration procedure

### Check migration status first
From the project root:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\database\migrate.php" status
```

### Apply pending migrations

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\database\migrate.php" up
```

### Rules
- Run migrations only after the fresh database backup completes.
- Stop immediately if any migration fails.
- Do not continue partial rollout after a failed migration without a recovery decision.

---

## 6. Post-deploy smoke test checklist

Run these checks immediately after deployment.

### Authentication and access
1. Open login page.
2. Sign in with a valid non-admin user.
3. Confirm workspace opens.
4. Sign in with a super-admin account.
5. Confirm dashboard opens.
6. Confirm logout works.

### Core workspace checks
7. Open an existing booking.
8. Confirm workspace loads without PHP warnings or broken sections.
9. Confirm service save still works on a safe test booking.
10. Confirm traveler add/remove works on a safe test booking.

### Customer finance checks
11. Open `Payment History`.
12. Confirm receipt history loads.
13. Confirm payment save still works on a safe test case.
14. Confirm receipt output opens.
15. Confirm a previously voided receipt still shows correctly in history.

### Supplier finance checks
16. Open `Suppliers`.
17. Confirm postpaid supplier settlement loads.
18. Confirm supplier payment history loads.
19. Confirm supplier voucher opens.
20. Confirm supplier finder works.

### Reports
21. Open `/reports`.
22. Confirm major reports load:
   - supplier postpaid payments
   - supplier prepaid payments
   - void / reversal register
   - finance audit trail
23. Export at least one CSV and confirm it downloads correctly.

### Logging and error posture
24. Confirm no stack trace is shown to normal users.
25. Confirm `storage/logs/app-runtime.log` is writable if an exception is triggered during controlled testing.
26. Confirm `storage/logs/php-error.log` is writable by PHP.

### Health check
27. Confirm the read-only health endpoint returns `status: ok`:

```powershell
Invoke-WebRequest -Uri "https://your-production-domain.example/health?token=<HEALTH_CHECK_TOKEN>" | Select-Object -ExpandProperty Content
```

---

## 7. Financial smoke tests after deployment

Use controlled test bookings only.

1. Save a new customer receipt.
2. Confirm allocation updates the invoice correctly.
3. Void a safe test customer receipt.
4. Confirm outstanding restores correctly.
5. Save a supplier payment.
6. Confirm allocation updates supplier payable correctly.
7. Void a safe test supplier payment.
8. Confirm supplier obligation restores correctly.
9. Confirm both voids appear in:
   - `Void / Reversal Register`
   - `Finance Audit Trail`

---

## 8. Rollback decision rules

Rollback should be considered immediately if any of these occur:
- migrations fail
- login fails for valid users
- workspace cannot open
- receipts/payments cannot save
- void flow corrupts balances
- reports fail broadly
- production stack traces are exposed

---

## 9. Rollback procedure

### Code rollback
1. Stop further production changes.
2. Switch back to the last approved release tag/branch.
3. Redeploy the previous code.

### Database rollback
Only restore the database if:
- the deployment introduced incompatible schema/data state
- or business transactions after deployment are explicitly discarded by management decision

### Database restore rule
- Restore only from the fresh pre-deployment production backup.
- Do not restore from the committed demo/recovery SQL backup for live production.

---

## 10. Recovery references

### Current known recovery reference
- Stable recovery point noted in project work:
  - `stable-db-after-payment-layout-loss-display`

### Migration command

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\database\migrate.php" status
```

### Backup file currently committed for controlled recovery work
- `C:\xampp\htdocs\Travel-Agency\storage\backups\database\travel_agency_ops_20260518_170457_stable-after-payment-layout-loss-display.sql`

---

## 11. Production data protection rules

- Keep production backups outside the main repository.
- Use timestamped backup files.
- Restrict backup access to authorized operators only.
- Never enable plaintext password-reset link logging in production by default.
- Keep `APP_DEBUG=false` in production at all times.

---

## 12. Known current limitations

- Supplier payment void does not automatically post accounting reversal journals because current journal-line linkage does not safely identify original supplier payment journal lines by `supplier_payment_id`.
- 2FA production configuration is intentionally deferred and excluded from this runbook.

---

## 13. Release sign-off checklist

Deployment is considered acceptable only if all are true:

1. Production env values are loaded correctly.
2. Debug is off.
3. Fresh DB backup exists.
4. Migrations completed successfully.
5. Workspace loads successfully.
6. Customer receipt flow passes smoke test.
7. Supplier payment flow passes smoke test.
8. Void / reversal report loads.
9. Finance audit trail loads.
10. No stack traces are visible to users.
11. Logs are writable.
12. Rollback backup and rollback operator are confirmed.
