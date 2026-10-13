# Booking Edit Diagnostic History

Booking Save, invoice autosave, service Save/autosave, Quick Financial Edit, customer receipt Save Payment, cancellation, refund, Edit Refund, and refund reversal/restore write paired `started` and outcome records to `storage/logs/booking-edit-trace-YYYYMMDD.jsonl`. This is diagnostic history, not a journal or payment record. It begins when the feature is deployed; it cannot reconstruct missing historical requests.

For intermittent production Save Invoice failures, the Quick Financial Edit POST writes private lifecycle records to `storage/logs/invoice-save-http-YYYYMMDD.jsonl`. They begin before app bootstrap and record bootstrap, security-gate, and router checkpoints plus the final HTTP status, duration, peak memory, and fatal location. The log contains only request/booking/service IDs and technical metadata, never the submitted form or credentials. If this file cannot be written, the same records fall back to the PHP error log. A 503 with no `started` record points to the hosting web server/proxy or PHP startup before this file could execute; inspect hosting access/error logs. An unpaired `started` record means the worker may have stopped without a PHP shutdown callback. A record ending before `app_bootstrapped` points to bootstrap; `before_launcher_gate` with HTTP 503 should have a matching `security.launcher_gate.*` entry in `app-runtime.log`; `before_router_dispatch` without a booking-edit `started` record stopped in route middleware or CSRF checking. If PHP logs a successful status but the browser receives 503, inspect the proxy response after PHP. Do not infer whether a correction committed from HTTP status alone: compare the booking-edit outcome and saved booking values.

After a live failure, inspect one booking or request without changing data:

```sh
php scripts/inspect_invoice_save_http.php --booking=BK-000795
php scripts/inspect_invoice_save_http.php --request-id=REQUEST_ID
```

The inspection command prints selected lifecycle events and matched server-error and booking-trace statuses, but not full exception messages or financial snapshots. It is safe to run while the site is live. Public error pages remain generic; keep this output and all log files restricted to administrators.

Each trace contains the request and page-session IDs, actor, permitted booking snapshot, selected submitted financial fields, named service checkpoints, the post-request snapshot, changed record groups, and any error. The snapshots include service currencies and amounts, cancellation/refund events and payout metadata, linked customer receipts and allocations, linked supplier payments, obligations and advance applications, and journal entries/lines. A failed request with changed records is marked `partial_commit`, not `rejected`.

Browser service-load, currency-change, Edit Booking open, Invoice Edit change/submit, and refund/Edit Refund open/change/submit observations use the same page-session ID and status `observed_unsaved`. They show what the editor displayed or attempted, **not** what was posted. Compare them with the matching server `committed`, `rejected`, or `partial_commit` outcome before drawing a financial conclusion. Only selected money/currency fields are stored; bank identifiers, customer names, CSRF tokens, and free-text notes are excluded. The browser event endpoint requires authentication, CSRF, and booking-branch access.

Inspect a booking without writing data:

```sh
php scripts/inspect_booking_edit_trace.php --booking=BK-001044
php scripts/inspect_booking_edit_trace.php --booking=BK-001044 --full
```

Use `--group=ID` to narrow the output to one browser editing session. `--full` includes journal lines; the default summarizes journal and correction IDs. Compare the `started` record's `submitted` and `before` values with the outcome's `steps`, `after`, and `changes`. An unpaired `started` record means the request ended before its outcome could be logged; inspect the runtime/PHP error log before drawing conclusions. A browser-only trace means no corresponding server write was observed; it is not proof that a refund was paid.

For a manual test, open a saved booking, open Edit Booking or Edit Refund, change one currency or amount, and save only if the transaction is genuinely intended. Then inspect that booking with the command above. The browser observation should show the edited field, the server `started` entry should show the submitted financial values, and the outcome should show the final event, refund, receivable, supplier, and journal changes. Do not infer a posted payment from a browser observation alone.

For a branch change, look for `BookingWorkspaceService::branchChange` checkpoints and compare the submitted branch with the saved booking, service, receipt, treasury-account branch, and journal branch IDs. `booking.branch_change.*` entries in `storage/logs/app-runtime.log` identify safety checks and staged cash corrections. A rejected outcome means the old branch remains saved. Browser debug events `branch-change:requested`, `branch-change:saved`, and `branch-change:save-failed` distinguish a cancelled currency transition from a server-side rejection.

An ordinary cash receipt moves only when the destination branch has a unique active cash account in the receipt's currency and the receipt has no allocations to other bookings. The original cash journal stays in the old branch, a reversal clears it there, and a new cash journal is posted in the destination branch, all inside the booking transaction. Bank receipts, shared supplier advances, linked-party offsets, and other ambiguous settlements remain guarded; do not relabel them without identifying the real destination account and settlement ownership.

The trace deliberately excludes CSRF tokens, card details, passports, and customer names from submitted fields. It lives outside `public`; the project-root rewrite rule also forbids direct web access to `storage`. Restrict server access to the log directory and include these files in the normal secure log-retention policy. Diagnostic logging is best-effort and does not block financial transactions if storage is unavailable; check `booking_edit_trace.write_failed` in `app-runtime.log` if a trace is missing.
