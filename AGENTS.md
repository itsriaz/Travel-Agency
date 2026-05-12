# AGENTS.md

## Project Purpose
- Build a web-based Travel Agency Operations and Accounting System for:
- Imdad International Travel Agency, Swat, Pakistan
- Noble Route, Dubai, UAE
- This is not just accounting software.
- This is a booking-workspace-first travel agency platform with accounting underneath.

## Architecture Rules
- Use a modular monolith, not microservices.
- Preferred structure:
- `/app/Controllers`
- `/app/Models`
- `/app/Services`
- `/app/Repositories`
- `/app/Views`
- `/app/Core`
- `/app/Helpers`
- `/app/Middleware`
- `/app/Policies`
- `/app/DTOs`
- `/config`
- `/database/migrations`
- `/database/seeds`
- `/public`
- `/storage`
- `/routes`
- `/tests`
- Use PDO repositories only.
- Do not scatter raw SQL in controllers or views.
- Use transaction-safe write methods for major financial actions.

## UI Rules
- Keep the UI compact, dense, desktop-first, and keyboard-friendly.
- Aim for modern-but-operational, not spacious marketing layouts.
- Avoid oversized inputs, oversized cards, and wasted whitespace.
- Employee default landing page: booking workspace.
- Super admin default landing page: dashboard with fast access to booking workspace.

## Core Product Structure
- Booking file / case file is the operational master record.
- A booking contains lead traveler / booking party, additional travelers, service lines, suppliers, payments, allocations, documents, reminders, notes, and accounting impact.
- Accounting is event-driven from booking operations.
- Ledger is financial truth.
- Booking is operational truth.

## Data-Model Rules
- Use a common `booking_services` table plus subtype tables such as:
- `service_air_ticket`
- `service_visa`
- `service_umrah`
- `service_hotel`
- `service_transport`
- `service_tour`
- `service_other`
- Do not cram all service fields into one giant table.
- Define receivable/payable items separately from receipts/payments.
- Use payment headers separate from allocation lines.
- Unallocated customer money is customer credit.
- Unallocated supplier payment is supplier advance.

## Business Rules
- Two branches only in the initial design, but branch-aware design is mandatory everywhere.
- Supported currencies: `PKR`, `AED`, `USD`.
- Consolidated reporting currency: `PKR`.
- Air tickets are recorded from external systems, not issued inside this software.
- Creating booking services must immediately create receivable/payable positions.
- Support partial and full payments.
- Full allocation history is required.
- Support both supplier advances and normal supplier payables.
- BSP/IATA-style reporting and airline commission reporting are required in version 1.

## Security Rules
- Use prepared statements everywhere.
- Enforce CSRF protection.
- Implement strong authentication and authorization.
- Use secure session handling.
- Use secure file upload validation and storage.
- Keep audit logs for all important actions.
- Apply strong financial controls.
- Use double-entry accounting from day one.

## Development Rules
- Implement one focused task at a time.
- Preserve backward compatibility.
- Do not rewrite unrelated files.
- Stop after each task and wait for review.
- Every major task must end with:
- files changed
- SQL changes
- manual test checklist
- security checks
- done criteria

## Deferred-Payment Rules
- A receipt/payment is one thing.
- Allocation is a separate thing.
- Never merge them into one simple paid field.
- One payment can allocate to many due items.
- One due item can be settled by many payments.
- Allocation history must never be lost.
