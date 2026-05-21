# Offline Launcher And Draft Sync

## Goal

Keep the hosted server as the only financial truth while giving Dubai and Swat staff a Windows desktop-style app with limited offline continuity.

## Architecture

- Central PHP/MySQL server remains authoritative.
- Windows launcher opens the hosted system in an app window.
- If the server is unavailable, the launcher shows a local offline screen.
- The offline screen uses the last cached emergency snapshot.
- Offline writes are limited to safe draft events.
- Financial posting stays online-only.

## Server Endpoints

- `GET /offline/snapshot`
  - Requires login, password-change checks, 2FA checks, and branch access.
  - Returns recent bookings, traveler profiles, customer outstanding snapshot, suppliers, CSRF token, and offline policy.

- `POST /offline/drafts/sync`
  - Requires the same auth middleware and CSRF token.
  - Accepts only:
    - `traveler.create`
    - `booking.create`
  - Rejects all other draft types.
  - Uses `offline_draft_syncs` for idempotency so repeated sync attempts do not duplicate records.

## Local Database Change

Run migrations after pulling this feature:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\Travel-Agency\database\migrate.php" up
```

New table:

- `offline_draft_syncs`

## Launcher Setup

1. Open `C:\xampp\htdocs\Travel-Agency\launcher`.
2. Copy `launcher-config.example.json` to `launcher-config.json`.
3. Set `serverUrl`.
   - Local test: `http://localhost/Travel-Agency`
   - Production: your HTTPS domain
4. Run:

```powershell
npm install
npm start
```

To package a Windows portable executable:

```powershell
npm run package:win
```

## Offline Rules

Allowed offline:

- View cached recent bookings.
- View cached travelers.
- View cached customer dues.
- View cached suppliers.
- Queue traveler drafts.
- Queue booking drafts.

Blocked offline:

- Customer receipts
- Receipt allocations
- Supplier payments
- Supplier advances
- Supplier payment allocations
- Voids and reversals
- Exchange-rate settlement
- Final invoice/receipt financial posting

## Local Test Checklist

1. Run migrations.
2. Start Apache and MySQL in XAMPP.
3. Start the launcher with `npm start`.
4. Login through the launcher.
5. Confirm the cache refreshes.
6. Stop Apache in XAMPP.
7. Confirm the offline screen is shown.
8. Search cached bookings/travelers/dues/suppliers.
9. Queue one traveler draft.
10. Queue one booking draft.
11. Start Apache again.
12. Click `Sync Drafts`.
13. Confirm both drafts become `synced`.
14. Confirm new records exist in the normal web app.
15. Click `Sync Drafts` again and confirm no duplicate records are created.

## Production Notes

- Use this as resilience, not as a replacement for reliable internet.
- Give each branch a backup internet connection if possible.
- Keep financial actions online-only unless a full conflict-resolution and ledger-sync engine is built later.
