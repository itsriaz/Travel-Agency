# AirBlue Imdad Int PKR advance reconciliation — 25 October 2026

Read-only local investigation. No payment, allocation, journal, or report code was changed for this investigation.

## Confirmed system amounts

- Current local supplier-ledger card: PKR 152,989 advance. The production report was stated as PKR 40,697 after the two bookings; **PKR 116,219 is the client's manual closing balance, not a production report result**. The local-versus-manual gap is PKR 36,770; the production-versus-manual gap is PKR 75,522.
- Current ledger as of 22 October: PKR 420,222 advance; 23 October: PKR 394,222; 24 October: PKR 152,989.
- The production CSV downloaded 23 October shows PKR 307,930 at the end of 22 October. The old report displayed SPAY-000235 as PKR 387,708; its saved payment and balanced cash journal #7078 both show PKR 500,000. The historical display omitted PKR 112,292 of that one payment. Thus the current and old 22 October displays differ by exactly PKR 112,292.
- After 22 October the ledger has BK-001177 supplier cost PKR 26,000 and BK-001182 supplier cost PKR 241,233. No new AirBlue PKR payment, supplier advance creation, or booking refund/credit event was found for 23–24 October. These two charges sum to PKR 267,233.
- BK-001182's saved obligation and journal #8108 are PKR 241,233. Journal #8109 and current application use PKR 165,891 of advance; allocation #1177/journal #8123 use PKR 75,342 from SPAY-000235. These funding pieces sum to its full cost, with no new cash receipt.
- All 31 active AirBlue PKR supplier payment headers match their `supplier_payment_recorded` journal amounts. Voided SPAY-000263 (PKR 250,000) is excluded. The source-to-treasury audit did not flag an AirBlue payment.

## Arithmetic requiring independent evidence

The old PKR 307,930 balance minus the two confirmed charges closes at **PKR 40,697**, not PKR 116,219. The manual closing requires either a PKR 383,452 opening balance immediately before BK-001177 or another net PKR 75,522 credit/correction. The current display adds the PKR 112,292 payment-display correction to the old PKR 40,697, producing PKR 152,989. Thus the apparent PKR 36,770 excess is the net of PKR 112,292 and PKR 75,522; it is not itself an identified payment or bill.

Separately, active payment headers total PKR 13,955,799 and current supplier-obligation gross amounts total PKR 13,655,879, yielding PKR 299,920. The current ledger shows PKR 13,802,810 bills/adjustments and PKR 152,989 advance; the PKR 146,931 ledger-versus-source difference comes from historical reversed adjustments on BK-000570 and BK-000897 documented in the production reconciliation register. Source net minus the client-reported manual closing is PKR 183,701. Do not post a PKR 36,770 adjustment to make the display match while this source discrepancy remains.

The two later booking charges are confirmed from saved source records. Do not keep asking the client for those entries. Continue tracing older corrections and application history in the database before requesting external evidence.

## Deeper source and report audit

The current local database has **four distinct PKR positions** for the same supplier: usable supplier-advance rows plus unallocated payment PKR 2,990; client-reported manual balance PKR 116,219; displayed supplier ledger PKR 152,989; active payment headers minus current gross obligations PKR 299,920. None can be substituted for another without reconciling their source events.

The PKR 296,930 source-versus-usable-credit gap is traced to three old advance applications on obligations already fully settled by a supplier payment: #94/BK-000297 PKR 160,837 (SPAY-000114), #93/BK-000643 PKR 111,208 (SPAY-000126), and #131/BK-000886 PKR 24,885 (SPAY-000201). Their payment allocations predate the duplicate advance applications; each booking's payment alone covers its full gross payable. The current refreshed database still has all three applications and their application journals, with no matching overcoverage reversal. The read-only exact-evidence repair check accepts these three obligations and PKR 296,930. A similar repair was run on an earlier local copy, but it is absent from this refreshed copy. The local paid-payable and advance-coverage guard regressions pass; production code version remains unverified.

The supplier-ledger report includes original payable-adjustment journals #5464 (BK-000570, PKR 102,487) and #6205 (BK-000897, PKR 44,444) but excludes their posted reversal journals #7267 and #6732. That causes PKR 146,931 of extra displayed bills and makes the ledger advance PKR 146,931 lower than the current source net. A booking-by-booking comparison found those **exactly two** report-versus-obligation mismatches; the other 137 obligation bookings matched. All 31 displayed AirBlue PKR supplier payment amounts matched their saved headers and recording journals. Correcting this report bug by itself would raise the displayed advance to PKR 299,920, further from the client's manual PKR 116,219. It cannot be used as a balancing adjustment.

The historical application overcoverage and the report reversal bug are independently proven system defects. Neither one accounts for the client-versus-source difference of PKR 183,701 or proves an isolated erroneous PKR 36,770 posting. No data or report code was changed during this investigation.

## Further database-only checks

All 139 current AirBlue PKR supplier obligations were compared with their booking-service purchase costs (125 open services) or latest posted cancellation penalties (14 cancelled services). There were **zero discrepancies**. This checks the current bill source independently of the supplier-ledger report; it does not validate historical cash paid outside the application.

Audit events #33333, #35056, and #55203 trace one payment-correction chain: SPAY-000113 PKR 116,548 to SPAY-000115 PKR 1,165,448; then to SPAY-000131 PKR 1,089,899; then back to active SPAY-000267 PKR 1,165,448. The last two corrections changed the payment by PKR 75,549 in opposite directions. Active SPAY-000267 has PKR 1,089,899 allocated and PKR 75,549 moved to advance #69, which now has PKR 0 available. Its current saved header and cash journal are consistent. The PKR 75,549 is near the PKR 75,522 gap implied by the old display but differs by PKR 27; the old production report also included SPAY-000267 at its full amount. Thus this is a correction to inspect, **not a proven cause or authorization to subtract PKR 75,549**.

## 23–24 October booking trail

BK-001182 was created on 24 October at PKR 241,233 (obligation #1170, payable journal #8108, audit #57757–57759). No audit event records an earlier PKR 165,711 cost or a later cost increase. Four advance applications #198–201 used PKR 165,891 in journal #8109 at creation. At 22:45, `SPAY-000235` allocation #1177 / journal #8123 applied the remaining PKR 75,342 (audit #57849–57850). The existing payment was not cash paid on 24 October. BK-001177 was created on 23 October at PKR 26,000, paid from advance #80. Both bookings are fully covered in current source records.

Correction after the user's clarification: PKR 165,711 was a **code mistake**, and BK-001182 is correct. Do not present PKR 165,711 as the amount the client deducted for this booking, ask about a separate cash payment, or use the arithmetic match with PKR 116,219 as the discrepancy's cause. This local database has no supplier-payment header, journal cash movement, or audit payload for PKR 165,711, and no AirBlue payment created on 23–24 October. Continue the root-cause search in older AirBlue transactions and report logic.

The exact-value search also found no PKR 165,711 entry in `treasury_transactions` money amounts. This says only that the local snapshot does not record that specific amount in the checked payment/treasury sources; it does not prove whether cash changed hands outside the application.

There is a confirmed application failure at booking creation. The four `supplier_advances` entries applied PKR 165,891 to BK-001182 across branches. The remaining PKR 75,342 was left open even though global payment SPAY-000235 had PKR 78,332 unallocated in branch 1. The booking is in branch 2. The old `SupplierRepository::autoApplyUnallocatedSupplierPaymentCreditToObligation` query required `payment.branch_id = obligation.branch_id`, so it excluded that global payment credit. Audit #57849 and journal #8123 show the PKR 75,342 was eventually applied at 22:45, about 56 minutes after booking creation, leaving PKR 2,990. Thus the available credit pool was split between advance rows and an unallocated payment, and the second pool was blocked by branch at creation. The existing local repository removes that restriction **for global payments only**; its rollback-only cross-branch regression passes. Verify production's SupplierRepository version before claiming the fix is deployed.

The above explains why the booking appeared to require payment despite enough same-currency AirBlue credit across the branches. It does not by itself reconcile the client's PKR 116,219 with the supplier-ledger display, because applying an already-paid credit to a booking is not a new cash payment or a new supplier-account movement.

## Independent posted-journal bridge

Read-only journal aggregation on the current local database attributed PKR AirBlue `AP_CONTROL` and `SUPPLIER_ADVANCES` lines using obligation/payment/advance links. The journal lines have **no `supplier_id_snapshot` column** in this copy, so historical supplier attribution after a supplier change remains a limitation. The attributable net is PKR 299,920 debit: AP_CONTROL PKR 296,930 plus SUPPLIER_ADVANCES PKR 2,990. This agrees exactly with active saved payments PKR 13,955,799 minus current obligation gross PKR 13,655,879. It does not independently verify real-world cash.

The system reports reconcile arithmetically to those source/journal totals: PKR 299,920 source/journal net minus PKR 146,931 from the two report-omitted reversal effects equals the current local supplier-ledger PKR 152,989 advance; subtract another PKR 112,292 omitted from SPAY-000235 by the older production display rule and the production-style report is PKR 40,697 advance. These are exact, separately traced **report calculation defects**, present before the two 23–24 October tickets. The same defects at the 22 October cutoff reduce the source net PKR 567,153 to the old report PKR 307,930, explaining why that old display could coincide with the manual figure despite disagreed rows.

The client's manual PKR 116,219 is PKR 183,701 below the current source/journal net and PKR 75,522 above the older production-style report. No AirBlue PKR journal line equals PKR 36,770, PKR 75,522, or PKR 183,701. An exact matching line is not required for a multi-entry difference, but there is no evidence to post one of these amounts as a balancing correction. The database proves the report bridges; it cannot by itself validate the external manual closing figure when all internal payment, obligation, and attributable journal totals agree.
