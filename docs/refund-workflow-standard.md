# Refund Workflow Standard

## Purpose

This document defines the standard refund workflow for cancelled services in the Travel Agency Operations system.

The goal is to keep:

- operational flow simple for users
- accounting flow correct
- supplier state and customer state separate
- audit trail complete

This workflow applies to:

- air ticket refunds
- cancelled services with customer refund
- cancelled services with supplier refund or supplier penalty

## Core Principle

Cancellation is **not** one event.

It is handled in separate stages:

1. cancel the service
2. settle the financial outcome of cancellation
3. return money to the customer if needed
4. receive money back from the supplier if needed

These steps may happen on different dates.

## Key Terms

### Customer Penalty

Amount kept from the customer after cancellation.

Formula:

`customer paid - customer refund = customer penalty`

### Expected Supplier Refund

Amount expected back from supplier after cancellation.

This is used to calculate supplier penalty.

Formula:

`supplier paid or payable original amount - expected supplier refund = supplier penalty`

### Supplier Penalty

Amount retained by supplier after cancellation.

### Customer Refund

Actual cash returned to customer.

This is a real money-out event and must only be recorded when money is actually paid back.

### Supplier Refund Received

Actual cash received back from supplier.

This is a real money-in event and must only be recorded when supplier has actually returned money.

## Standard Workflow

## Step 1: Cancel Service

User clicks `Cancel Service`.

Effect:

- service becomes cancelled operationally
- no cash movement happens yet
- no settlement happens yet

This step only marks the service as cancelled.

## Step 2: Settle Cancel

User enters:

- date
- customer penalty
- expected supplier refund
- supplier penalty
- reason

### System logic

The system should support this formula:

- if supplier refund is known:
  - `supplier penalty = original supplier amount - expected supplier refund`
- if supplier penalty is entered directly:
  - expected supplier refund is whatever remains after penalty

### Settlement result

This step should release financial follow-up amounts.

#### Customer side

If customer paid already:

- refundable amount to customer becomes available

Formula:

`customer refund credit = customer paid - customer penalty`

#### Supplier side

There are two cases.

### Case A: Supplier already paid

If supplier was already paid before cancellation:

- expected supplier refund becomes supplier receivable
- supplier penalty is the part supplier keeps

Formula:

`supplier receivable = expected supplier refund`

This receivable remains pending until supplier actually returns money.

### Case B: Supplier not yet paid

If supplier was not yet paid before cancellation:

- supplier penalty remains payable to supplier
- no supplier refund receivable exists yet

Formula:

`remaining supplier payable after cancellation = supplier penalty`

This is because the agency has not paid money out yet, so nothing is being recovered back.

## Step 3: Post Refund

This step records actual cash movements.

User can record:

- customer refund
- supplier refund received

These are independent.

They may happen:

- on the same day
- on different days
- in separate follow-up sessions

### Customer refund rules

Customer refund can be posted when refund credit is available.

This reduces:

- treasury cash/bank
- customer refund credit

### Supplier refund received rules

Supplier refund received can be posted when supplier receivable exists.

This increases:

- treasury cash/bank
- reduces supplier receivable

## Visibility Rules

### Settlement row

Show settlement row only until cancellation is financially settled.

Hide it after settlement is successfully posted.

### Post Refund row

Do not hide post-refund row immediately after settlement.

Keep it visible while any follow-up remains pending:

- customer refund still pending, or
- supplier refund receivable still pending

Hide it only when both are complete or not applicable.

## Business Scenarios

## Scenario 1: Supplier already paid, customer refunded first

Example:

- supplier paid = 1188
- customer paid = 1180
- customer penalty = 53
- expected supplier refund = 653
- supplier penalty = 535
- customer refund paid now = 600
- supplier refund received later = 653

Result:

- customer refund credit released = 1127
- user may choose to return only 600 now
- remaining customer refund credit stays pending if not fully returned
- supplier receivable = 653
- when supplier returns 653 later, user records it in `Supplier refund received`

## Scenario 2: Supplier not yet paid

Example:

- original supplier cost = 1188
- expected supplier refund = 653
- supplier penalty = 535
- supplier has not yet been paid

Result:

- supplier payable becomes 535
- no supplier receivable should be created

Because nothing was previously paid to supplier.

## Scenario 3: Customer pays nothing yet

Example:

- service cancelled before customer payment

Result:

- customer refund credit should remain zero
- customer penalty may still be recorded for settlement logic if needed
- no customer cash refund should be posted unless money was actually received before

## Scenario 4: Customer advance exists

If customer had advance money already in system:

- refund logic should still respect actual customer paid / available credit
- refund should only consume valid refundable customer credit

## Profit / Loss Handling

Difference between customer paid and supplier cost may create profit or loss.

Example:

- customer paid = 1180
- supplier paid = 1188
- initial loss = 8
- customer penalty = 53

Result:

- first 8 recovers prior loss
- remaining 45 becomes net gain from cancellation penalty

This should be reflected in accounting, not guessed manually.

## Correction Rules

Users may enter wrong values.

The system should support correction of:

- customer penalty
- expected supplier refund
- supplier penalty
- customer refund
- supplier refund received

But corrections must never silently overwrite history.

Standard correction method:

- reverse prior incorrect financial event
- post corrected replacement event
- preserve full audit trail with actor, date, old value, new value, and reason

## UI Standard

The refund module should remain simple:

### Row 1

`Cancel Service`

### Row 2

`Settle Cancel`

Fields:

- Date
- Customer Penalty
- Expected Supplier Refund
- Supplier Penalty
- Reason

### Row 3

`Post Refund`

Fields:

- Date
- Customer Refund
- Supplier Refund Received
- Method
- Source Account
- Reason

No extra remarks field is necessary if reason is already available and clear.

## Operational Warnings

Users should understand:

- `Expected Supplier Refund` is not cash in hand yet
- `Supplier Refund Received` is actual money received
- `Customer Refund` is actual money paid back
- settlement and refund are different actions

## Done Standard

The refund module is considered standard-compliant when:

1. cancellation, settlement, and refund are separate
2. already-paid supplier and unpaid supplier behave differently
3. customer refund and supplier refund can be posted on different dates
4. post-refund row remains visible while any follow-up is pending
5. no fake supplier receivable is created for unpaid supplier cases
6. no refund is blocked when legitimate supplier receivable exists
7. every correction leaves a full audit trail
8. UI does not mislead users after settlement is already complete

