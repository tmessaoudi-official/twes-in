# add-to Plan

« Ajouter à… » (docs/SPEC.md § 8 row 259, ruled § 7 2026-10-11 01:24): goods onto a new draft or one of the open drafts of an
invoice, a quote or a delivery note, never an issued document, filtered to the customer when one is known; from the product
page and list, the scan card, the stock map's selection and between documents; one picker, generalising the delivery note's
« Facturer » target; « Ajouter à un tarif ».

## Decisions Log
- [2026-10-16 15:17] ASSUMED (review): The picker hands the products to the document's own editor through the address (?add=<id>…) rather than a new API route appending lines: one product-to-line path (applyProduct, reprice: price lists, the customer's regime, default taxes), and nothing saved until the person saves — advisor agreed. Alternatives: an append endpoint per document type building lines on the server.
- [2026-10-16 15:17] ASSUMED (review): A product already on the draft is placed as a scan is (scanIntoLines: same product and unit adds to its line, else a new line), so a scan and « Ajouter à… » behave alike — advisor agreed. Alternatives: always a new line.
- [2026-10-16 15:17] ASSUMED (review): The editor forgets add from the address once taken in (replaceUrl), so a reload or Back adds nothing; « Ajouter à… » navigates in the same tab, a flow continuing — advisor agreed. Alternatives: a new tab as labels do.
- [2026-10-16 15:17] ASSUMED (review): From the products list no customer is known, so the picker lists every open draft of the chosen type with its customer, total and lines, and a filter; from a page that knows the customer, that customer's drafts — advisor agreed. Alternatives: ask for the customer first.
- [2026-10-16 15:47] ASSUMED (review): The picker finds a draft by its customer (a pick field, hidden when the screen already knows the customer), not by typed words; supersedes the 15:17 filter-field pick — because the invoices, quotes and delivery-notes list search reads customerSnapshot, which a draft never has (72 drafts, 0 snapshots on 2026-10-16), so words found no draft; advisor agreed. Alternatives: fix the list search first (its own defect, recorded under Known issues), search by draft number (a draft has none).

## Formal Plan

1. Shared picker `AddToDialog` (`web/src/app/shared/documents/`): the types the person may write (module on and its
   `*.write`), then « Nouveau brouillon » or an open draft of that type (`status[]=draft`, the customer's when known), each
   row naming its customer, total and line count, with a filter field. The products list's selection bar and the product
   page offer « Ajouter à… ». Hand-off through the editor: `/<type>/new?add=<id>…` or `/<type>/<draftId>?add=<id>…`; the
   invoice, quote and delivery note editors take the products in through `applyProduct` and `reprice`, the same placement
   rule as a scan (`scanIntoLines`), then forget `add` from the address (`replaceUrl`), so a reload adds nothing. Nothing is
   saved until the person saves.
2. Fold `InvoiceTargetDialog` (delivery notes « Facturer », single and bulk) into the picker.
3. The scan card and the stock map's selection offer « Ajouter à… ».
4. « Ajouter à un tarif » from the product page and list.
5. Between documents: a quote's lines onto an open invoice (an API route, as delivery notes onto an invoice).

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Picker + products list/page + three editors' intake | L | done | - | web/src/app/shared/documents/** web/src/app/products/** web/src/app/invoices/** web/src/app/quotes/** web/src/app/delivery-notes/** |
| 2 | Break the picker's import cycle (a token each document feature provides), fold InvoiceTargetDialog into the picker | M | doing | - | web/src/app/delivery-notes/** web/src/app/shared/documents/** |
| 3 | Scan card and stock map selection (moved into Stage 2, § 7 2026-10-16 17:25) | M | deferred | - | web/src/app/products/** web/src/app/inventory/** |
| 4 | « Ajouter à un tarif » (moved into Stage 2) | M | deferred | - | web/src/app/products/** web/src/app/price-lists/** |
| 5 | A quote's lines onto an open invoice (moved into Stage 2) | M | deferred | - | api/src/Module/** web/src/app/quotes/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- A draft's customer is never found by the invoices, quotes or delivery-notes list search: `MATCHES_WORDS` (Doctrine{Invoice,Quote,DeliveryNote}Repository) reads `customerSnapshot`, which a draft has none of (72 drafts, 0 snapshots on 2026-10-16). Its own defect, with its own index design (`SearchIndexesTest` pairs each expression with its trigram index); the picker sidesteps it by filtering on the customer.
