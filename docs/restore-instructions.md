# Restore Instructions

## Purpose
- Keep one short, reliable restore guide inside the repo.
- Cover:
  - code restore from Git
  - database restore from SQL dump
  - post-restore checks
  - future restore-point routine

Use this together with:
- [docs/backup-recovery-operations.md](/C:/xampp/htdocs/Travel-Agency/docs/backup-recovery-operations.md)
- [scripts/backup_database.php](/C:/xampp/htdocs/Travel-Agency/scripts/backup_database.php)

---

## Current restore point

Code:
- branch: `task-treasury-p1-cash-bank-position`
- commit: `00aaad4`
- tag: `stable-after-customer-receipt-return-balance`

GitHub:
- [itsriaz/Travel-Agency](https://github.com/itsriaz/Travel-Agency)

Latest known local database dump:
- [travel_agency_ops_20260530_145114_codex-nightly-smoke.sql](/C:/xampp/htdocs/Travel-Agency/storage/backups/database/travel_agency_ops_20260530_145114_codex-nightly-smoke.sql)

---

## Restore code

### Restore exact tagged state on working branch

```bash
cd /c/xampp/htdocs/Travel-Agency
git fetch origin --tags
git checkout task-treasury-p1-cash-bank-position
git reset --hard stable-after-customer-receipt-return-balance
```

### Open tagged state only

```bash
cd /c/xampp/htdocs/Travel-Agency
git fetch origin --tags
git checkout stable-after-customer-receipt-return-balance
```

---

## Restore database

### Option A: phpMyAdmin

1. Open phpMyAdmin
2. Create or select database `travel_agency_ops`
3. Open `Import`
4. Choose:
   - [travel_agency_ops_20260530_145114_codex-nightly-smoke.sql](/C:/xampp/htdocs/Travel-Agency/storage/backups/database/travel_agency_ops_20260530_145114_codex-nightly-smoke.sql)
5. Click `Go`

### Option B: command line

```bash
/c/xampp/mysql/bin/mysql -u root -p travel_agency_ops < /c/xampp/htdocs/Travel-Agency/storage/backups/database/travel_agency_ops_20260530_145114_codex-nightly-smoke.sql
```

---

## Post-restore checks

After restoring code and database, verify:

1. `.env` is correct for that machine or server
2. required storage folders exist and are writable
3. launcher config matches the current domain and environment
4. database schema includes latest receipt return tracking fields
5. login works
6. booking workspace opens
7. customer receipt prints correctly

---

## Required receipt return tracking SQL

Run this if the restored database does not yet include the newer customer receipt fields:

```sql
ALTER TABLE customer_receipts
    ADD COLUMN tendered_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER currency,
    ADD COLUMN returned_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER unallocated_amount;

UPDATE customer_receipts
SET tendered_amount = received_amount
WHERE tendered_amount = 0.00;

UPDATE customer_receipts
SET returned_amount = 0.00
WHERE returned_amount IS NULL;
```

Expected rule after this:
- `tendered_amount` = amount customer handed over
- `received_amount` = amount business kept
- `returned_amount` = amount returned to customer
- `unallocated_amount` must not be used as fake customer advance for these receipt cases

---

## Create a new restore point later

When the system reaches another stable state:

```bash
cd /c/xampp/htdocs/Travel-Agency
git add .
git commit -m "Describe stable point"
git tag -a stable-after-meaningful-name -m "Stable after meaningful name"
git push origin task-treasury-p1-cash-bank-position
git push origin stable-after-meaningful-name
```

Then create a database dump:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php --label=stable-after-meaningful-name
```

---

## Important rule

Always treat restore as two parts:

1. code restore from Git tag
2. database restore from SQL dump

Only one of them is not enough for a real rollback.
