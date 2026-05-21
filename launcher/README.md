# Travel Agency Windows Launcher

This launcher keeps the production system centralized while giving staff a desktop-style app window, emergency read-only cache, and safe offline draft queue.

## What It Allows Offline

- View the most recent emergency snapshot cached on this computer.
- Queue new traveler drafts.
- Queue new booking drafts.

## What It Blocks Offline

- Customer receipts
- Receipt allocations
- Supplier payments
- Supplier advances
- Supplier allocations
- Voids and reversals
- Exchange-rate settlements
- Final financial posting

The central server remains the only financial truth.

## Local Test

1. Copy `launcher-config.example.json` to `launcher-config.json`.
2. Set `serverUrl` to `http://localhost/Travel-Agency`.
3. From `launcher`, run `npm install`.
4. Run `npm start`.
5. Login through the launcher.
6. Stop Apache in XAMPP to simulate outage.
7. Queue traveler and booking drafts in the offline screen.
8. Start Apache again.
9. Click `Sync Drafts`.

## Build Windows EXE

After installing dependencies:

```powershell
npm run package:win
```

The output is created under `launcher/dist`.
