# AirBlue SPAY-000267 correction impact — read-only review, 26 October 2026

## Local application result

The atomic correction was committed to the local database on 26 October 2026. The original review and projection below are retained for audit context. The verified after snapshot is `airblue_spay267_correction_after_20261026.json`. All projected payment, advance, cash, supplier-ledger, and customer-receivable figures matched. The one extra supplier-ledger row in branch 1 is the visible reversal of the earlier historical adjustment. Fifteen new correction journals balance. A second apply attempt was rejected before posting anything. Production has not been changed by this local run.

No payment, allocation, advance application, journal, treasury balance, or production record was changed for this review. The local before snapshot is `airblue_spay267_correction_before_20261026.json`. The expected-after figures below are arithmetic projections, **not posted results**.

## Source and funding checks

- `SPAY-000267` (#279) is the active global PKR payment dated 1 May 2026: PKR 1,165,448 from treasury account #33 `Cash-SWT-PKR` in branch 1. Its cash journal is #7735. `SPAY-000115` (#127) is an earlier void entry and must remain void.
- Eleven active direct payment allocations total PKR 1,089,899, all on branch-1 supplier obligations.
- Converted advance #69 is PKR 75,549, with zero available. Six active applications total PKR 75,549 to branch-2 obligations. The payment equals its uses: 1,089,899 + 75,549 = 1,165,448.
- Corrected cash payment PKR 1,090,171 would leave PKR 272 after the eleven direct allocations. The difference to reassign is PKR 75,277.
- `BK-000297` obligation #293 has PKR 160,837 gross and two active sources: payment allocation #373 from `SPAY-000114` for PKR 160,837, and advance application #94 from advance #36 for PKR 160,837. The payment alone covers the obligation. Advance #36 comes from active global PKR payment `SPAY-000190` (#202), dated 7 September, and was already funded before the proposed October replacement applications.
- Advance #36 has PKR 400,000 deposit, four applications totaling PKR 400,000, and zero available. Removing only duplicate application #94 would free PKR 160,837. Applying PKR 75,277 to the six later obligations would leave PKR 85,560 available on #36.
- `BK-000862` already has advance #36 application #91 for PKR 70,004. Its replacement PKR 1,000 must increase that existing row to PKR 71,004 because `(supplier_advance_id, supplier_obligation_id)` is unique.
- `BK-001146` application #173 uses PKR 7,980 from #69, but its posting is part of journal #7906 for PKR 356,761 across multiple advances. Only the PKR 7,980 portion may be adjusted; reversing #7906 in full would damage other funding.
- All six target bookings are currently fully covered. No other payment allocation covers their obligations. The replacement must preserve their coverage and their customer invoices.
- The generic supplier-payment edit workflow rejects corrections to a payment with a used converted advance. A focused, transactional correction is needed; changing only `paid_amount` and the cash journal would leave PKR 75,277 of uses without a source.
- Local historical adjustment journal #8153 for PKR 75,277 exists. It must be reversed with a supplier-attributed inverse entry if the payment is reduced. The current generic journal reversal does not carry `supplier_id_snapshot`, so it cannot be used unchanged for this entry.

## Proposed same-transaction funding transfer

Keep all eleven direct allocations and every ticket's total supplier coverage. Release duplicate advance #36 application #94 on `BK-000297`; `SPAY-000114` remains its full PKR 160,837 cover. Keep PKR 272 of advance #69 on the earliest of its six applications and replace the rest using #36:

| Booking | Current #69 | Keep #69 | Use #36 instead | Expected supplier payable |
| --- | ---: | ---: | ---: | ---: |
| BK-000787 | 1,000 | 272 | 728 | 0 |
| BK-000862 | 1,000 | 0 | 1,000 (increase existing #91) | 0 |
| BK-001076 | 43,782 | 0 | 43,782 | 0 |
| BK-001082 | 20,000 | 0 | 20,000 | 0 |
| BK-001117 | 1,787 | 0 | 1,787 | 0 |
| BK-001146 | 7,980 | 0 | 7,980 (partial journal adjustment) | 0 |
| **Total** | **75,549** | **272** | **75,277** | **0** |

Post the payment correction, historical-entry reversal, and allocation-source transfers atomically. Journal movements must stay in each booking's branch and PKR currency. No automatic use of a different supplier or currency is proposed.

## Before and expected after

| Measure | Local before | Expected after |
| --- | ---: | ---: |
| SPAY-000267 cash paid | PKR 1,165,448 | PKR 1,090,171 |
| Direct allocations from SPAY-000267 | PKR 1,089,899 | PKR 1,089,899 |
| Advance #69 deposit / used / available | 75,549 / 75,549 / 0 | 272 / 272 / 0 |
| Advance #36 deposit / used / available | 400,000 / 400,000 / 0 | 400,000 / 314,440 / 85,560 |
| BK-000297 supplier payable | 0 | 0 |
| Six replacement-booking supplier payables | 0 each | 0 each |
| AirBlue branch-1 supplier account | PKR 2,244,503 advance | PKR 2,244,503 advance |
| AirBlue branch-2 supplier account | PKR 2,126,640 payable | PKR 2,126,640 payable |
| AirBlue all-branch supplier account | PKR 117,863 advance | PKR 117,863 advance |
| AirBlue supplier-ledger payments | PKR 13,955,799 | PKR 13,880,522 |
| AirBlue supplier-ledger bills/adjustments | PKR 13,837,936 | PKR 13,762,659 |
| Cash-SWT-PKR journal net | PKR -4,010,120.99 | PKR -3,934,843.99 |
| Other bank accounts | unchanged | unchanged |
| Customer receivable totals, all branches/currencies | see before JSON | unchanged |
| Operational AirBlue available credit less open payable | PKR -103,790 | PKR -18,230 |
| Existing audit gap between source account and operational allocations | PKR 221,653 | PKR 136,093 |

The residual PKR 136,093 audit gap equals the other two known double-covered AirBlue obligations: BK-000643 PKR 111,208 and BK-000886 PKR 24,885. They are outside this focused correction. The earlier broad financial audit had additional unrelated failures; this plan does not claim to repair them.

## Verification required if implemented

Capture the same branch/currency supplier and customer ledger row counts, movements, and ending balances after posting. Compare them to the before JSON and this projection. Confirm `SPAY-000267` + its direct allocations + converted advance conserve PKR 1,090,171; both advances conserve deposits against applications and available amounts; the six obligations and BK-000297 remain fully covered; only `Cash-SWT-PKR` rises PKR 75,277; all new journals balance and carry the correct branch/currency/supplier attribution; the old adjustment has one effective reversal; and a second run cannot post duplicate corrections. Stop on any unexplained difference.
