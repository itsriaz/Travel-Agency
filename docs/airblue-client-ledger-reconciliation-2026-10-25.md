# AirBlue client ledger reconciliation, 25 October 2026

**26 October official AirBlue update:** BK-001187 now records the previously missing PKR 106,780 Aamir Nawaz charge. The official AirBlue SalesHistory exports show `PSKYKE` sold for PKR 200,554 and later refunded PKR 100,277 with a PKR 25,000 cancellation fee, producing one net PKR 75,277 supplier credit. They show `CZSJLP` as a separate PKR 95,376 sale but no separate PKR 75,277 sale in the supplied period. This resolves the earlier uncertainty about the manual Mujeeb row #94: its PKR 75,277 `Received` amount is unsupported as an additional supplier charge, while its `Paid` amount represents the real net credit. See `docs/airblue-reconciliation-after-bk001187-2026-10-26.md` for current figures and source detail. The historical observations below describe the database before BK-001187 was entered and contain earlier hypotheses superseded by the official export.

Source: the 18-page scanned client ledger titled `air blu new noble route`, dated 25 October 2026. Pages 1–17 contain 185 numbered rows; page 18 contains no ledger rows. Every numeric row was read from the rendered PDF and the independently added columns reproduce the printed totals exactly. This investigation is read-only. The local database is a production-derived copy; production records were not queried or changed here.

## Column meaning and closing position

The client's `Paid` column includes both cash payments to AirBlue and supplier refunds/credits. Its 44 entries split into 32 cash payment entries and 12 refund entries. The cash payments sum to PKR 13,955,799, exactly matching the 31 active AirBlue PKR supplier-payment headers in the local database. The amount multiset differs only because the client's two PKR 500,000 entries on 30 July are recorded as one PKR 1,000,000 payment (`SPAY-000124`) in the system. The voided duplicate `SPAY-000263` is not needed to reach the cash total. Two payment dates differ: the client's PKR 458,933 entry is dated 1 May with a note `20 may`, while `SPAY-000114` is dated 20 May; the client's PKR 500,000 entry on 20 August is dated 23 August on `SPAY-000130`. These date differences affect an as-of report, not the overall cash total.

| Measure | Client ledger PKR | Local source PKR | Client minus system PKR |
| --- | ---: | ---: | ---: |
| Original ticket/charge entries (`Received` versus active service original costs) | 14,844,424 | 14,662,393 | +182,031 |
| Supplier refunds/reductions (`Paid` refund entries versus original-to-current obligation reduction) | 1,004,844 | 1,006,514 | -1,670 |
| Net supplier charges | 13,839,580 | 13,655,879 | +183,701 |
| Cash paid to supplier | 13,955,799 | 13,955,799 | 0 |
| Advance / amount due from AirBlue | 116,219 | 299,920 source position | -183,701 |

The client's closing `-116,219 Due` means AirBlue owes the agency PKR 116,219. The final two client entries are PKR 26,000 and PKR 241,233 charges on 23 and 24 October. Therefore this **same PDF** yields PKR 383,452 due from AirBlue at the end of 22 October (116,219 + 26,000 + 241,233). It does not yield the older report's PKR 307,930 at that cutoff; the difference is PKR 75,522. The two recent bookings match the PDF and are not the discrepancy.

## Original-charge differences, fully bridged

Exact amount matching accounts for 136 system service costs. The remaining rows explain the entire PKR 182,031 original-charge difference:

| Client row | Client entry | Local record | Effect on client minus system original charges |
| --- | --- | --- | ---: |
| 7 | Sardar Muhammad, PKR 77,628 | `BK-000085`, PKR 77,682 | -54 |
| 47–48 | Waqar Ahmad, PKR 55,977 ticket + PKR 4,000 baggage | `BK-000468`, one PKR 59,977 service | 0 |
| 94 | Mujeeb, PKR 75,277 in **both** `Received` and `Paid`; user confirms this belongs to `BK-000302` | No separate PKR 75,277 service; `BK-000302` has PKR 75,277 cancellation credit against a PKR 200,554 Maqbool two-passenger ticket | +75,277 gross; 0 net in client row |
| 166 | Akhtar Hussain, PKR 83,651 | `BK-001101`, PKR 83,623 | +28 |
| 171 | Aamir Nawaz, PKR 106,780 on 19 October | No matching AirBlue service, same-price service, passenger snapshot, or audit payload in this local database | +106,780 |

Arithmetic: -54 + 75,277 + 28 + 106,780 = **182,031**.

The user confirms that row 94 belongs to `BK-000302`, that AirBlue kept PKR 125,277 after returning PKR 75,277 from the original PKR 200,554 payment, and that the client's ledger is correct. The user further clarified that AirBlue did **not** return cash: PKR 75,277 was retained in the agency's supplier advance, while the agency separately paid Maqbool cash. The system records the same types of movements: cancellation event #44 reduces the supplier obligation to PKR 125,277 and releases PKR 75,277 of supplier credit; customer refund event #45 pays PKR 75,000 from Cash-SWT-PKR, reflecting the PKR 277 customer penalty. Audit #42195 shows the supplier credit applied to `BK-000846`, and later PKR 35,000 plus PKR 40,277 advances from the same payment are fully used. The earlier suggestion to look for an AirBlue cash receipt or reverse the supplier advance was wrong. The PDF's row 94 names Mujeeb and shows PKR 75,277 in both `Received` and `Paid`; its charge-side meaning and the client's statement that this credit was not used on another booking remain to be reconciled. Do not add a Mujeeb charge, another refund, or delete the client row based only on its label.

## Refund/reduction differences, fully bridged

The client's twelve refund rows total PKR 1,004,844. The system's fourteen cancelled services reduce original costs by PKR 1,006,514. Most match; these differences account for the complete PKR 1,670 system-over-client reduction:

| Client row / PDF refund | System booking reduction | Client refund minus system reduction |
| --- | --- | ---: |
| 44 / PKR 86,174 (Sharif) | `BK-000330` / PKR 84,506 | +1,668 |
| No separate PDF refund identified for the reissue | `BK-000449` / PKR 1,994 | -1,994 |
| 104 / PKR 102,478 (Noman) | `BK-000570` / PKR 102,829 | -351 |
| 128 / PKR 51,782 (Shakil) | `BK-000922` / PKR 52,782 | -1,000 |
| 162 / PKR 230,177 (Ayesha plus baggage) | `BK-001030` + `BK-001032` / PKR 231,176 | -999 |
| 181 / PKR 82,302 (Akhtar Muhammad) | `BK-001058` / PKR 81,296 | +1,006 |

The six deltas sum to **-1,670**. The other refund amounts reconcile numerically, including rows 94, 107, 131, 135, 147, 177, and 182. Matching an amount does not alone prove the same booking or ticket identity.

## Why report screens showed different balances

The client-versus-local **source** difference of PKR 183,701 decomposes as PKR 106,780 missing Aamir Nawaz charge + PKR 75,277 different BK-000302 row-94 charge treatment + PKR 1,644 net small-ticket/refund differences. The PKR 75,277 supplier refund is confirmed as an advance, so refund-method misclassification is not the cause. The PDF's `Received` amount and the system's later credit applications need to be reconciled without inventing a separate ticket. Existing report behavior separately understates the source advance by PKR 146,931 from the presentation of reversed payable adjustments, yielding the local report's PKR 152,989. Its difference from the client ledger is PKR 36,770. The older report also omitted PKR 112,292 from displaying `SPAY-000235`, yielding PKR 40,697 after 24 October. Its difference from the client ledger is PKR 75,522. These exact bridges explain screen variation; they do not validate a report amount by coincidence.

Updated interpretation: the client's PKR 116,219 closing advance stays unchanged. Recording the confirmed Aamir Nawaz PKR 106,780 ticket once would move the system's source position from PKR 299,920 to PKR 193,140. The remaining PKR 76,921 difference is PKR 75,277 from the unmatched row-94 charge-side treatment plus PKR 1,644 in the other small differences. Do not reverse the confirmed supplier advance or add a Mujeeb ticket merely to force PKR 117,863; trace the charge-side source and later credit applications first.

## Review before any source repair

1. Verify the 19 October Aamir Nawaz PKR 106,780 ticket against its ticket number/PNR and determine why it has no system booking service. Adding it would reduce advance; do not add it blindly because other discrepancies remain.
2. The user confirms that AirBlue retained PKR 125,277 on the original `BK-000302` ticket and left PKR 75,277 as supplier advance; the agency paid Maqbool separately in cash. The local system records both movements, but also documents reuse of the supplier credit on `BK-000846` and later advances. Reconcile that application history with the client's description of the supplier advance and row 94. Do not change the refund method or create a Mujeeb ticket merely to match the report amount.
3. Compare the six small refund/cost deltas to the supplier's ticket/refund documents. In particular, a refund larger than original cost (Sharif), the Noman PKR 351 difference, and the Akhtar PKR 1,006 difference need their source documents checked.

Follow-up system search: the local database contains no `AAMIR NAWAZ` or `AMIR NAWAZ` in traveler names, booking parties, service snapshots, ticket details, document metadata, or audit text, and no PKR 106,780 service cost or audit amount. No `MUJEEB` or `MUJIB` text occurs in those same sources, and no PKR 75,277 separate service exists, including inactive services. `BK-000302` has lead traveler `MAQBOOL MADYAN`, an additional `MAQBOOL * 2` traveler, and no stored ticket number, PNR, or booking document. Its posted cancellation event #44 was created on 26 August at 20:29; it explicitly records PKR 75,277 expected supplier credit against the original PKR 200,554 cost. The client's PDF row 94 is dated 26 August at 19:27 and names Mujeeb with Maqbool as a reference. The user confirms this row belongs to `BK-000302`; AirBlue retained the refund as supplier advance, not cash. A separate Mujeeb ticket charge remains unproven.

No booking, payment, credit, journal, or ledger record was changed. Do not post a PKR 75,522 or PKR 183,701 balancing entry. Keep AL SAUDIA AED out of scope.
