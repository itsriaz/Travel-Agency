# Linked Party Currency Pooling

The account holder and supplier in one link are two views of one party. The combined statement and supplier-facing statement now maintain one running balance for each currency across accessible branches. Every underlying invoice, journal, receipt, treasury account, and transaction row keeps its actual branch. PKR, AED, and USD are never added together or silently converted.

An account invoice of PKR 1,000 with no other movements means PKR 1,000 due from the party; the supplier-facing linked statement shows PKR 1,000 receivable. If PKR 10,000 is then received directly, the pooled position becomes PKR 9,000 due to the party. The receipt is recorded once against the selected cash/bank account, and its branch is derived from that account. It remains unallocated until an explicit ticket-level allocation is performed. No supplier payment or invoice allocation is fabricated.

The linked balance screen shows matchable open account invoices, open supplier invoices, unallocated account-holder receipts, and the net ledger position separately. Matchable account invoices use their recorded outstanding amount less active linked settlement allocations, not their original due amount. Manual invoice matching still requires an explicit action. A party/currency can be opened for a direct receipt even if there are no invoices.

## Local checks

Run `php tests/linked_party_currency_pool_regression.php`, `php tests/account_holder_unallocated_receipt_regression.php`, `php tests/counterparty_auto_offset_regression.php`, `php tests/counterparty_multi_invoice_offset_regression.php`, and `php tests/linked_party_end_to_end_acceptance.php`. These tests do not retain new financial rows. In the UI, choose a linked pair and currency, verify the pooled net, record a genuine cash receipt only when appropriate, then verify its branch, net balance, unallocated status, ledger entry, and void reversal.

## Production deployment

No schema migration or historical rewrite is required. Deploy the changed application files together, then compare the pre- and post-deployment linked balances by pair and currency. Historical per-branch footers will be replaced by one currency footer; underlying transactions should remain unchanged. Do not run test receipts on production. If a historical balance does not reconcile, inspect the underlying ledger and receipt rows rather than posting an adjustment to force the displayed total.
