# Browser Workflow Audit

This audit runs inside the Electron launcher shell so it uses the same Chromium engine and launcher security headers as production users.

## Modes

### Read-only UI audit
- Opens the workspace
- Waits for manual login if needed
- Verifies:
  - `Receive Customer Payment` button opens and searches
  - `Find Supplier Payment` button opens and searches
  - `Reminders` toggle opens/closes
  - `Payment History` opens when a receipt context exists

### Write audit
- Optional
- Creates a dummy invoice through the real workspace UI
- Fills service data
- Saves payment
- Verifies receipt printing opens a child receipt window

Use write mode only when dummy-data posting is acceptable in that environment.

## Setup

1. Copy:
   - `launcher/workflow-audit.config.example.json`
2. Save as:
   - `launcher/workflow-audit.config.json`
3. Adjust:
   - `bookingReference`
   - `customerSearchTerm`
   - `supplierSearchTerm`
   - `writeAudit.enabled`
   - write-audit dummy values if you want end-to-end posting

## Run

From:

```powershell
C:\xampp\htdocs\Travel-Agency\launcher
```

Read-only:

```powershell
npm run audit:workflow
```

Recommended direct command:

```powershell
node_modules\.bin\electron.cmd workflow-audit.js
```

Write audit:

```powershell
node_modules\.bin\electron.cmd workflow-audit.js --write
```

Headless if the launcher session is already authenticated:

```powershell
node_modules\.bin\electron.cmd workflow-audit.js --headless
```

## Notes

- If the login page appears, complete login and 2FA in the audit window.
- The script then continues automatically.
- Console/runtime errors are treated as failures.
- If `writeAudit.enabled` is false, no invoice/payment posting is attempted.
