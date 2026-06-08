# Backup and Recovery Operations

## Purpose
- Turn backup and recovery into an operational routine, not a one-time developer trick.
- Cover:
  - manual safe backup before risky changes
  - nightly automated backup
  - offline/offsite copy
  - retention policy
  - restore verification
  - emergency recovery steps

Use this together with:
- [C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php](C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php)
- [C:\xampp\htdocs\Travel-Agency\scripts\run_backup_cycle.php](C:\xampp\htdocs\Travel-Agency\scripts\run_backup_cycle.php)
- [C:\xampp\htdocs\Travel-Agency\scripts\backup_restore_drill.php](C:\xampp\htdocs\Travel-Agency\scripts\backup_restore_drill.php)
- [C:\xampp\htdocs\Travel-Agency\docs\production-deployment-runbook.md](C:\xampp\htdocs\Travel-Agency\docs\production-deployment-runbook.md)

---

## 1. What must be backed up

Every production-safe backup plan must include:

1. **Database**
- bookings
- services
- receipts
- refunds
- treasury
- audit logs
- journals

2. **Customer / operational documents**
- `storage/documents`

3. **Private production configuration**
- real `.env`
- server-level config not stored in Git

4. **Release reference**
- release tag
- release zip or deployment artifact

Database backup alone is not enough if document history matters.

---

## 2. Backup scripts

### A. Database-only backup

Run:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Travel-Agency\scripts\backup_database.php --label=before-release
```

This creates:
- SQL dump
- JSON manifest with:
  - created time
  - file size
  - SHA-256 hash

Default output:
- `storage/backups/database`

### B. Full backup cycle

Run:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Travel-Agency\scripts\run_backup_cycle.php --label=nightly --offsite-dir=E:\TravelAgency-Offsite --prune --keep-daily=14 --keep-weekly=8 --keep-monthly=12
```

This does all of the following:
- runs the DB backup script
- copies the DB backup into a timestamped cycle folder
- snapshots `storage/documents`
- writes a cycle manifest
- mirrors the cycle to an offline/offsite location if provided
- prunes older cycle folders according to retention rules

Default cycle location:
- `storage/backups/cycles/<timestamp>_<label>`

---

## 3. Recommended schedule

## Nightly production backup

Run every night, for example **1:00 AM**:

```powershell
C:\xampp\php\php.exe C:\path\to\project\scripts\run_backup_cycle.php --label=nightly --offsite-dir=E:\TravelAgency-Offsite --prune --keep-daily=14 --keep-weekly=8 --keep-monthly=12
```

## Weekly restore verification

Run every Sunday, for example **2:00 AM**:

```powershell
C:\xampp\php\php.exe C:\path\to\project\scripts\backup_restore_drill.php --label=weekly-drill
```

## Before every deployment / migration

Run manually:

```powershell
C:\xampp\php\php.exe C:\path\to\project\scripts\backup_database.php --label=before-release
```

---

## 4. Offline / offsite copy strategy

Best practice is **3-2-1**:

- 3 copies of data
- 2 different storage locations/media
- 1 offsite or offline copy

Recommended practical setup:

1. live production database and live documents
2. local nightly backup cycle on server disk
3. offsite copy to one of:
   - external drive
   - NAS / second machine
   - secure cloud storage path

### Good offsite targets
- `E:\TravelAgency-Offsite`
- `\\OfficeNAS\travel-agency-backups`
- synced private cloud folder

### Important rule
- do not keep the only backup on the same disk as production

---

## 5. Retention policy

Recommended defaults:
- daily: `14`
- weekly: `8`
- monthly: `12`

The backup cycle script keeps:
- one newest backup for each recent day
- one newest backup for each older week
- one newest backup for each older month

This gives practical recovery points without unlimited growth.

---

## 6. Windows Task Scheduler setup

Create **two** scheduled tasks.

### Task 1: Nightly Backup Cycle

**Program/script**
```text
C:\xampp\php\php.exe
```

**Add arguments**
```text
C:\path\to\project\scripts\run_backup_cycle.php --label=nightly --offsite-dir=E:\TravelAgency-Offsite --prune --keep-daily=14 --keep-weekly=8 --keep-monthly=12
```

**Start in**
```text
C:\path\to\project
```

**Trigger**
- Daily
- 1:00 AM

### Task 2: Weekly Restore Drill

**Program/script**
```text
C:\xampp\php\php.exe
```

**Add arguments**
```text
C:\path\to\project\scripts\backup_restore_drill.php --label=weekly-drill
```

**Start in**
```text
C:\path\to\project
```

**Trigger**
- Weekly
- Sunday
- 2:00 AM

### Recommended Task Scheduler options
- Run whether user is logged on or not
- Run with highest privileges if server policy requires it
- Stop the task if it runs unusually long
- Enable history

---

## 7. Manual recovery workflow when something goes wrong

## Case A: Code broke, data is still fine

1. stop new deployment changes
2. redeploy previous approved release
3. smoke test login, workspace, receipts, reports

No DB restore needed if data is fine.

## Case B: Data corruption or bad migration

1. take an **emergency backup of current broken state**
2. identify the last good backup
3. create maintenance window
4. restore backup into a **temporary restore database first**
5. verify:
   - bookings count
   - receipts count
   - journal count
   - users/login availability
6. only then decide whether to replace live DB

## Rule
- do not restore blindly over live production first

---

## 8. Restore verification

The restore drill script already verifies:
- backup file is created
- restore DB can be created
- dump imports successfully
- critical table counts match
- migration history matches
- restore DB is queryable

Run:

```powershell
C:\xampp\php\php.exe C:\path\to\project\scripts\backup_restore_drill.php --label=manual-drill
```

To keep the temporary restore DB for inspection:

```powershell
C:\xampp\php\php.exe C:\path\to\project\scripts\backup_restore_drill.php --label=manual-drill --keep-restore-db
```

---

## 9. What to check every month

At least once a month:

1. confirm nightly backup task still runs
2. confirm offsite target is receiving files
3. confirm weekly restore drill still passes
4. confirm backup disk is not full
5. confirm documents snapshot is present
6. confirm someone can actually find the latest good backup quickly

---

## 10. Operational recommendations

- keep production backups outside public web root
- protect offsite location with limited operator access
- never commit real production `.env`
- keep at least one copy off the live server
- document who is allowed to restore production
- rehearse recovery before an emergency happens

---

## 11. Suggested robust baseline

For this system, a strong baseline is:

1. nightly `run_backup_cycle.php`
2. weekly `backup_restore_drill.php`
3. manual DB backup before every deployment
4. offsite copy on another disk or network share
5. monthly operator review

That is the difference between “we take backups sometimes” and a real recovery posture.
