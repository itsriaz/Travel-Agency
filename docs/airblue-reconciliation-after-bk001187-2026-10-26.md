# AirBlue PKR reconciliation after BK-001187 (local, 26 October 2026)

Production was not changed. The client reports PKR 116,219 advance. This note does not treat a report figure as proof of that balance. On 26 October, the client confirmed an additional PKR 75,277 historical AirBlue charge and a noncash local adjustment was posted as described below.

## 26 October follow-up: historical PKR 75,277 and May payment

The user reports that PKR 75,277 relates to older AirBlue transactions and that the actual cash paid to AirBlue on 1 May was **PKR 1,165,448**. The active saved payment is `SPAY-000267`, PKR 1,165,448, dated 1 May, supplier AirBlue Imdad Int, branch 1, source treasury account #33 `Cash-SWT-PKR`. `SPAY-000115` is an earlier void record for the same amount; its cash journal was reversed. The active payment's cash journal #7735 credits `Cash-SWT-PKR` by PKR 1,165,448. Do not edit the active cash payment down to PKR 1,090,171: that would falsely increase recorded cash by PKR 75,277.

The local supplier ledger starts 1 May with `BK-000256` as a PKR 51,638 payable, then shows `SPAY-000267` paid PKR 1,165,448 and running PKR 1,113,810 advance, matching the user's screenshot. If a documented PKR 75,277 liability from before the recorded period is added *before* that payment, opening payable would be PKR 126,915 and the running advance immediately after the payment would be PKR 1,038,533. The payment row itself must still say PKR 1,165,448 and its cash journal must remain unchanged.

`SPAY-000267` has PKR 1,089,899 in 11 direct allocations and PKR 75,549 converted to advance #69. That advance has six applications totaling PKR 75,549 and zero available. No existing AirBlue PKR obligation or payment of exactly PKR 75,277 was found in the local database. The client confirmed that PKR 75,277 belongs to older AirBlue transactions and that the full PKR 1,165,448 was actually paid in cash on 1 May. A separate, traceable **local** prior-period adjustment was posted on 30 April: journal #8153, source `AIRBLUE-PRIOR-20260430-75277`, debit prior-period equity and credit supplier advances PKR 75,277. The local AirBlue supplier-account advance changed from PKR 193,140 to **PKR 117,863**; the payment remains PKR 1,165,448, Cash-SWT-PKR journal totals are unchanged, and no bank account changed. The remaining PKR 1,644 difference from the client manual total is accepted by the user for now. The local source correction is not on production; it has not been uploaded or run there.

## Official AirBlue SalesHistory, received 26 October

The two official CSV exports overlap on 31 July by one identical `VCVGQG` sale for PKR 102,485; deduplicate it before summing. Their `Deposit` rows have blank amount columns, so these exports can verify ticket sales/refunds/fees but **cannot independently certify cash payments or a closing supplier balance**.

For the Maqbool-linked references, the official export contains:

| Date | Reference | AirBlue transaction | Charge to Account (PKR) |
| --- | --- | --- | ---: |
| 23 May | PSKYKE | Ticket Sale | +200,554 |
| 23 May | CZSJLP | Ticket Sale | +95,376 |
| 31 May | PSKYKE | Ticket Refund | -100,277 |
| 31 May | PSKYKE | Cancellation Fee | +25,000 |

The net for these two references is **PKR 220,653**: 200,554 + 95,376 - 100,277 + 25,000. The `PSKYKE` refund and fee give a net PKR 75,277 credit. The local system has BK-000302 at PKR 200,554 with a PKR 75,277 cancellation reduction, plus BK-000301 at PKR 95,376, also net PKR 220,653. The manual ledger's corresponding rows #13, #14 and #94 net to PKR 295,930 because #94 adds PKR 75,277 in both `Received` and `Paid`. **The official export contains no separate PKR 75,277 ticket sale under `PSKYKE`, nor any PKR 75,277 charge-to-account sale in the supplied date range.** This is transaction-level evidence that #94's `Received` amount should not be treated as another supplier charge. Removing that debit while retaining the refund makes the manual advance PKR 191,496, leaving PKR 1,644 against the system's PKR 193,140.

AirBlue dates the `PSKYKE` refund and fee 31 May. Manual row #94 is dated 26 August, and the app cancellation event uses 26 August while its release journal is dated 31 May. This is a separate period/date discrepancy; it does not change the lifetime balance.

Other direct references also explain most of the PKR 1,644 remainder without relying on raw refund amounts alone: `VCVGQG` sold/refunded PKR 102,485 (net zero); `DSJXVN` sold/refunded PKR 51,782 (net zero); `JGOMST` has PKR 213,177 sale, two PKR 9,000 additional sales, and PKR 231,177 refund (net zero); `OICEAV` sold/refunded PKR 80,302 (net zero). The manual rows for these groups have net charges of PKR 351, 1,000, 999, and -1,006 respectively, while the corresponding local booking groups net to zero. `FNKMDZ` nets to PKR 20,000 in AirBlue's export; the matched local Sharif bookings net to PKR 20,006 and the manual rows net to PKR 20,332. These comparisons are tied to references and ticket groups, not a blanket assumption that the entire remaining PKR 1,644 belongs on one side.

## Direct CSV comparison

The client supplied `air_blu_noble_route_ledger.csv` with 185 numbered transaction rows, followed by totals. I parsed its `Received` and `Paid` columns as integer PKR values and compared them with the local production-derived database by amount, date, and description.

- All **31 active AirBlue PKR payment headers** match the manual `Paid` entries by amount. The one system PKR 1,000,000 payment (`SPAY-000124`) corresponds to two PKR 500,000 manual entries (#64 and #68) on 30 July. No payment header or manual cash-payment amount is left unmatched. The manual file has 32 payment lines because of that split.
- Three matched payment dates differ: `SPAY-000114` is dated 20 May while manual #2 is dated 1 May with note `20-May`; `SPAY-000117` is dated 21 June while manual #27 is dated 24 June; `SPAY-000130` is dated 23 August while manual #88 is dated 20 August. These affect interim as-of balances, not the 25 October closing amount.
- Manual #161 and #176 each say `full refund` in the notes and are PKR 500,000. They match same-day PKR 500,000 supplier payment records (`SPAY-000235` and `SPAY-000274`) that credited supplier advance and debited Cash-SWT-PKR. Separate manual refund entries #162 (Ayesha, PKR 230,177) and #177 (Sami Ullah, PKR 86,638) follow. The notes alone do not establish whether the PKR 500,000 entries were physically paid cash; treasury evidence should be checked before changing their method. Their classification does not explain the PKR 76,921 supplier account gap, because both manual and system currently credit the account by those amounts.
- The 12 other manual `Paid` entries total PKR 1,004,844 and correspond to posted cancellation refunds. Seven amounts match exactly; five differ as listed below. The system has one additional PKR 1,994 reduction on BK-000449 with no separate matching manual refund line.
- The manual has 142 `Received` amount rows; the system has 140 active service costs. One extra manual row is a two-part ticket/baggage amount that equals a single saved service; the other is the Mujeeb PKR 75,277 charge on row #94. Apart from that row, the two original cost differences are PKR -54 and +28 (client minus system). The official AirBlue export now resolves row #94 as a net refund/fee on `PSKYKE`, with no matching new PKR 75,277 ticket sale in the supplied date range.

## Confirmed report defect corrected locally

- Posted payable-adjustment journal #5464 (BK-000570, PKR 102,487) has an exact inverse in journal #7267. Journal #6205 (BK-000897, PKR 44,444) has an exact inverse in journal #6732. The supplier report counted both original adjustments but omitted their reversals, overstating bills by PKR 146,931.
- The report now excludes a payable adjustment only when a later posted reversal names that journal ID and has a matching opposite AP_CONTROL line for the same obligation, branch, currency, and booking. A report ending before the reversal still includes the original adjustment.
- The AirBlue PKR report changed from PKR 46,209 to PKR 193,140 advance. No saved financial record changed. The before/after supplier position snapshot changed only AirBlue PKR, branch 1; 47 other supplier/branch/currency positions were unchanged. Journal aggregates and source counts were identical.

## Remaining difference against the client's ledger

| Measure | PKR |
| --- | ---: |
| Active AirBlue payments, 31 headers | 13,955,799 |
| Current AirBlue obligations, including BK-001187 PKR 106,780 | 13,762,659 |
| Independent system source advance | 193,140 |
| Client ledger advance | 116,219 |
| Difference | 76,921 |

BK-001187 is present and lowers the source balance by exactly PKR 106,780. It is dated 19 October, so it appears below the 24, 23, and 22 October rows in the descending screen.

The client's paper ledger contains PKR 75,277 on both sides of the Mujeeb row. The official `PSKYKE` sale/refund/fee sequence above establishes one net PKR 75,277 credit and no separate PKR 75,277 sale. The app's BK-000302 cancellation event #44 records the same PKR 75,277 credit against the original PKR 200,554 service; BK-000301 separately records PKR 95,376. The passenger count/identity within `PSKYKE` remains undocumented, but it is not needed to establish the net supplier charge for these references. No system booking, payment, refund or journal amount was changed during this comparison.

The remaining PKR 1,644 consists of PKR -26 across two original costs (BK-000085 -54; BK-001101 +28, client minus system) and PKR -1,670 across six refund/reduction comparisons (BK-000330 +1,668; BK-000449 -1,994; BK-000570 -351; BK-000922 -1,000; BK-001030/BK-001032 -999; BK-001058 +1,006). These are client minus system amounts, so the net charge difference is -26 - (-1,670) = +1,644. Exact row evidence is in `docs/airblue-client-ledger-reconciliation-2026-10-25.md`.

The local source evidence for those smaller differences is explicit: BK-000085 and BK-001101 currently carry PKR 77,682 and PKR 83,623 in both their service and obligation records. The cancelled tickets' obligation gross values and posted event payloads agree with the system amounts; for AED customer invoices with PKR supplier costs, the payload stores the PKR amount in `expected_supplier_refund_source_amount`. The scanned paper values differ, but neither the paper nor local database alone proves which original supplier ticket/refund amount is correct. No PKR 1,644 adjustment was posted.

The separate 30 April PKR 75,277 historical adjustment described at the top is now present **only in the local database**. It is a prior-period equity-to-supplier-advance journal, not a booking cost, refund, or cash transaction. The local supplier ledger has one extra opening row; its 1 May payment row remains PKR 1,165,448. The new local account balance is PKR 117,863. The pre-adjustment baseline is in `docs/verification/airblue_prior_period_baseline_20261026.json`, and `tests/airblue_prior_period_adjustment_regression.php` checks the journal, payment, cash totals, supplier balance, and as-of dates. Running `database/patches/2026_10_26_airblue_prior_period_adjustment.php` twice does not add a second journal.

Independent split of the BK-000302 funding: `SPAY-000114` is PKR 458,933. Its current direct allocations total PKR 383,656, including BK-000302 PKR 125,277; its released PKR 75,277 appears as advances #56 (35,000) and #75 (40,277), both fully used. PKR 383,656 + PKR 75,277 = PKR 458,933. The refund was not omitted or added twice in this payment's source records.

The client paper ledger's PKR 13,955,799 payment-sized entries exactly match the 31 active system payment headers by amount (one system PKR 1,000,000 header corresponds to two manual PKR 500,000 rows). The paper net supplier charges are PKR 13,839,580 versus system PKR 13,762,659, a difference of PKR 76,921. The official AirBlue `PSKYKE` record supports removing the PKR 75,277 duplicate debit in manual row #94, leaving a PKR 1,644 manual-versus-system difference. The user confirmed that PKR 116,219 was supported only by the earlier manual CSV total; these official exports do not contain cash deposit amounts to independently prove a final advance. Do not post a system balancing entry for PKR 75,277 or PKR 1,644; review the smaller PNR-linked amount differences before any source correction.

## Separate allocation defect; do not confuse it with the PKR 76,921 gap

The current local database has zero available credit in `supplier_advances` and zero unallocated payment, while BK-001187 still has PKR 103,790 open. The source account nevertheless nets to PKR 193,140 advance. Three AirBlue obligations have both an active payment allocation and an advance application: BK-000297 PKR 160,837, BK-000643 PKR 111,208, and BK-000886 PKR 24,885. Their combined overcoverage is PKR 296,930, exactly the gap between source net PKR 193,140 and operational available less open payable (PKR -103,790). This is flagged by the existing financial audit. Releasing the invalid duplicate allocation requires choosing which recorded funding is valid for each booking; no allocation was changed here.

After the separate PKR 75,277 historical adjustment, the local source and supplier ledger are PKR 117,863 while operational available credit less open payable remains PKR -103,790. The remaining PKR 221,653 difference is still flagged by `SupplierPositionReconciliationAudit`; the new adjustment was included as a verified source in its expected position. No advance application or payable was silently rewritten to make that audit pass. `tests/supplier_report_source_consistency_regression.php` likewise still fails when it compares the raw operational advance summary (zero) with the supplier account balance. The displayed account summary and booking lookup use the shared supplier-ledger balance, but the underlying allocation discrepancy remains a separate issue.

## Verification and deployment boundary

- `tests/supplier_payable_reversal_as_of_regression.php` passes for both dated reversals and source-versus-ledger balance.
- `tests/supplier_shared_account_balance_regression.php` passes for supplier ledger, advance report, payment report, and booking lookup using the same account balance.
- The broad financial audit still fails on pre-existing supplier overallocations, advance conservation, and one cross-branch allocation journal. Its failures were not suppressed.
- Do not deploy the report change as a claimed PKR 116,219 correction or alter production records. The official export resolves the Mujeeb charge-side question, but the remaining PKR 1,644 source differences and the separately flagged allocation defects still require review.
