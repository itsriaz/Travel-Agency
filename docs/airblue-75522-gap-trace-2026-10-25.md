# AirBlue Imdad Int — PKR 75,522 gap trace (local, read-only)

The client reports PKR 116,219 advance. Neither the older displayed PKR 40,697 nor the local displayed PKR 152,989 is accepted as the correct balance. This note traces the **difference**, not a proposed adjustment. No financial data or report code was changed.

## Scope and established facts

- The client confirms PKR 116,219 in their manual record; the older production report displayed PKR 40,697. The difference is PKR 75,522, but this is **not yet an identified transaction**.
- BK-001182 is correct. The PKR 165,711 calculation was a code mistake and is excluded from the root-cause search. Do not ask about it again or infer a separate cash payment from it.
- No exact PKR 75,522 source or journal amount was found in this local database. A multi-entry discrepancy remains possible.
- A read-only supplier-position audit flags older PKR 296,930 advance overcoverage and three advance-conservation exceptions. Those are separate issues and cannot be used as an unexplained balancing amount for PKR 75,522.

## Decision before any repair

Trace older AirBlue payment corrections, refund credits, duplicate/partially applied advances, and journal reversals against the supplier-ledger movement trail. Leave BK-001182 and the mistaken PKR 165,711 code calculation out of the root-cause search. Do not create a PKR 75,522 balancing entry, change SPAY-000235, or treat a report total as source evidence.

## Older near-amount candidates checked

- BK-000302's PKR 75,277 cancellation credit (event #44) did not create a `CANCEL-CREDIT-44` advance because it released PKR 75,277 of already allocated SPAY-000114 credit. Audit #42195 shows that exact amount reapplied to BK-000846. Later releases and uses lead to advances #56 (PKR 35,000) and #75 (PKR 40,277); together they equal PKR 75,277 and both are fully used. It is not an unrecorded PKR 75,277 supplier refund.
- SPAY-000267's PKR 75,549 converted advance #69 has six applications totaling PKR 75,549 and zero available. The payment correction chain remains a real-world cash-verification question, but this advance is not silently unallocated in the database.
- The six posted AirBlue PKR cancellation-credit events in this copy record PKR 75,277, 102,829, 44,444, 94,426, 86,638, and 81,296. Their event payloads split each released supplier credit between payment-credit and advance-credit release amounts exactly. That checks the release classification, not whether the original cash amounts agreed with the supplier's external account.
- The local supplier-position audit still flags three advance conservation differences of PKR 28, 33,532, and 25,503; the dry-run metadata reconciliation identifies their application-detail mismatches. These amount to PKR 59,063, not an evidenced PKR 75,522 posting.

The current source records do not identify one missing PKR 75,522 payment or refund. The older report's PKR 112,292 payment-display omission and PKR 146,931 reversal-display difference are separately demonstrated; their effects must not be treated as confirmation of the client's closing balance. Continue at transaction level and require independent cash/supplier evidence before changing a source amount.

The focused local source/report consistency regression still reports a mismatch between posted source position and supplier-ledger presentation; it does not establish the client's manual closing as wrong or identify a PKR 75,522 source transaction. No AirBlue or AL SAUDIA AED financial records were changed during this review.
