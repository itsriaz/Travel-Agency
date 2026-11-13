# Local supplier overcoverage reconciliation, 27 October 2026

Scope: local `travel_agency_op` only. No production data or cash payment was changed.

## Source corrections

| Booking | Excess coverage released | Outcome |
| --- | ---: | --- |
| BK-000136 | AED 740 payment allocation | SPAY-000218 retains AED 740 unallocated supplier credit; both cash payment headers remain posted. |
| BK-000252 | AED 985 payment allocation | SPAY-000045 retains AED 985 unallocated supplier credit; both cash payment headers remain posted. |
| BK-000184 | AED 50 advance application | AED 3 payable reopens because its AED 665 payment is less than its AED 668 bill. |
| BK-000450 | PKR 12,980 advance application | Full recorded payment still covers the bill. |
| BK-000472 | AED 641 advance application | Full recorded payment still covers the bill. |
| BK-000478 | AED 1,680 advance application | Full recorded payment still covers the bill. |
| BK-000493 | AED 388 + AED 277 advance applications | Full recorded payment still covers the bill; one aggregate journal reversed. |
| BK-000550 | AED 1,625 advance application | Full recorded payment still covers the bill. |

The corrections used source guards, database transactions, journal reversals and required audit records. Journal IDs 8205–8212 document the releases.

## Before and after

See `supplier_overcoverage_sources_before_20261027.json` and `supplier_overcoverage_sources_after_20261027.json` for obligation, allocation, advance and supplier position records. See `supplier_overcoverage_before_20261027.json` and `supplier_overcoverage_after_20261027.json` for every supplier ledger group by branch/currency and journal totals.

- Supplier ledger row counts, debit movements, credit movements and ending balances: **no changed group**.
- Overcovered obligation audit: **8 before, 0 after**.
- Supplier operational position differences: Princess Tourism AED 4,658→0; AIR SIAL IMD INT PKR 12,980→0; S TT UMRAH TRAVELS AED 740→0; AED PKR CHANGE EXCHANGE AED 985→0. Source and supplier ledger net positions did not change.
- No cash or customer receivable source records were edited by these scripts. Journal checks pass.

## Verification

`tests/financial_invariants_audit.php`: 25 PASS, 0 FAIL, 1 REVIEW. It still prints a non-failing review for supplier #413 BK-000038 cross-currency refund events #62 and #74; their cash-versus-credit classification is outside the same-currency position audit.

Focused regressions passed: supplier obligation coverage, supplier position reconciliation, paid payable blocking advance re-use, voided allocation coverage, AirBlue SPAY-000267 correction, and AirBlue remaining overlap.

The two retained second payments cannot be certified as real-world cash solely from database records. They remain posted as unallocated supplier credit; if either was a mistaken payment entry, the normal audited void workflow should remove that payment after cash evidence is confirmed. No production repair has been applied.
