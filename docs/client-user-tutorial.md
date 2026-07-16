# Client User Tutorial

## Purpose
- Help staff use the Travel Agency Operations system in daily work.
- Focus on normal office tasks.
- Avoid technical language as much as possible.

This guide is for:
- booking staff
- sales staff
- accounts staff
- branch admin users

---

## 1. What this system does

This system is built around the **booking workspace**.

Think of one booking as one working file for a customer.

Inside one booking, staff can manage:
- customer details
- service lines
- invoice amount
- customer payments
- receipts
- outstanding balances
- reminders
- documents
- cancellation, refund, and reissue actions

---

## 2. First login

1. Open the launcher.
2. Wait for the system to load.
3. Enter your username and password.
4. If 2FA is enabled, enter the code from your authenticator app.
5. After login:
   - employee users normally open in the booking workspace
   - super admin may open on the dashboard first

Important:
- Do not share your login with another user.
- Do not save your password in public or shared devices.

---

## 3. Main working idea

The safest habit is:

1. open a fresh invoice
2. select or create the customer
3. add service details
4. confirm invoice amount
5. receive payment if customer pays now
6. print receipt if needed

This avoids mixing one customer's work with another customer's booking.

---

## 4. Start a new booking

1. Click `New Invoice`.
2. Select branch.
3. Set booking date.
4. Choose one of these:
   - `Find Customer` for existing customer
   - `New Customer` for new customer
5. Confirm the customer details load correctly.

Tip:
- Always start with `New Invoice` before switching to another customer.

---

## 5. Find an existing customer

1. Click `Find Customer`.
2. Search by:
   - customer name
   - mobile number
   - passport number
3. Select the correct customer.
4. Confirm:
   - name
   - mobile
   - old outstanding balance if any

If the wrong customer loads:
1. click `New Invoice`
2. search again

---

## 6. Add a new customer

1. Click `New Customer`.
2. Fill the customer details.
3. Save the customer.
4. Confirm the customer is loaded into the workspace.

Minimum good practice:
- full customer name
- mobile number
- passport number if available

---

## 7. Add service details

After the customer is loaded:

1. Select service type
   - Air Ticket
   - Visa
   - Hotel
   - Transport
   - Tour
   - Other
2. Fill the service fields.
3. Enter financial values carefully.

For air ticket:
- `Mkt.Fare`
- taxes if any
- `Serv.Amount`
- due date if payment is not complete

The system will calculate:
- final sale amount
- receivable
- payable
- profit/loss

Check before moving on:
- service amount is correct
- final sale amount is correct
- passenger name is correct

---

## 8. Understand the payment side

The payment panel shows:
- current invoice amount
- paid on this invoice
- outstanding balance

### If customer pays less
The receipt should show:
- `Outstanding Balance`

### If customer pays more
The system should:
1. clear current invoice first
2. clear previous outstanding for the same customer if any
3. show `Return to Customer` for the remaining extra amount

No extra customer money should be kept as advance unless management changes that rule.

---

## 9. Receive a payment

1. Check invoice amount first.
2. In `Amount Receiving`, enter the amount customer is paying now.
3. Select payment method.
4. Select cash/bank account if required.
5. Click `Save Payment`.

After save:
- receipt is created
- paid amount updates
- outstanding balance updates
- amount receiving should reset for the next payment

---

## 10. Print a receipt

1. Save the payment first.
2. Click `Print Receipt`.

Check the receipt before giving it to customer:
- booking / invoice no.
- customer name
- current invoice amount
- current invoice payment
- total paid
- outstanding balance, if any
- return to customer, if any

---

## 11. When customer pays partially

If customer does not pay the full amount:
- save the payment normally
- make sure due date is set
- check the receipt shows `Outstanding Balance`

This helps staff follow up later.

---

## 12. View customer ledger

Use `View Customer Ledger` when you want to check:
- all receipts
- invoice history
- outstanding balances
- previous dues

Use this before collecting more money from returning customers.

---

## 13. Payment history

Use `Payment History` to review:
- receipt number
- date
- payment amount
- allocated amount
- payment status

Use this before:
- printing again
- checking disputes
- confirming earlier payment

---

## 14. Add reminders

Use reminders for:
- payment follow-up
- passport expiry
- missing documents
- custom follow-up tasks

Basic flow:
1. open booking
2. create reminder
3. set due date and time
4. save

When completed:
- mark as completed

When no longer needed:
- dismiss it

---

## 15. Upload documents

Use the documents section for:
- passport copies
- visa copies
- tickets
- customer supporting documents

Basic flow:
1. choose document type
2. select linked record if needed
3. choose file
4. click upload

If upload fails:
- check the file type
- check file size
- try a clean PDF or image format

---

## 16. Cancellation, refund, reissue

These options are for saved existing bookings, not fresh unsaved bookings.

### Cancel Service
Use when the service is cancelled operationally.

### Settle Cancel
Use when cancellation financial impact is being finalized.

### Refund
Use when money is being paid back to customer.

### Reissue
Use for ticket reissue where extra charges or adjustments apply.

Important:
- always read the amounts carefully before saving
- always print the correct receipt after financial action

---

## 17. Reminder hub and reports

Use reports and reminder hub for:
- active reminders
- completed reminders
- dismissed reminders
- outstanding balances
- branch performance

Staff should check:
- due reminders
- overdue reminders
- missing due dates

---

## 18. Good daily habits

Follow these habits:

1. Start with `New Invoice`
2. Check customer name before entering money
3. Check invoice amount before saving payment
4. Print only after confirming the numbers
5. Do not enter the same payment twice
6. Use payment history before correcting old work
7. Use ledger before collecting from a returning customer

---

## 19. Common mistakes to avoid

Do not:
- switch customer without opening a fresh invoice
- save payment before checking invoice amount
- ignore outstanding balance on partial payment
- treat returned change as customer advance
- print receipt before verifying customer name and amount

---

## 20. If something looks wrong

Stop and check before continuing if:
- receipt amount looks wrong
- old customer balance appears in a new booking
- return to customer is missing
- outstanding balance is wrong
- refund or reissue looks confusing

In that case:
1. do not continue posting more actions
2. note booking number
3. take screenshot
4. report exact step to admin or support

---

## 21. Suggested training order for staff

Train users in this order:

1. login
2. new invoice
3. find customer
4. new customer
5. add service
6. save payment
7. print receipt
8. partial payment
9. ledger and payment history
10. reminders and documents
11. cancellation / refund / reissue

---

## 22. Quick daily workflow

For most normal bookings:

1. `New Invoice`
2. `Find Customer` or `New Customer`
3. add service details
4. verify total
5. receive payment
6. print receipt
7. check outstanding balance if not fully paid

That is the core workflow.
