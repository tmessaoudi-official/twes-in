# Limitations round, 2026-10-04

The five read-only reviews of 2026-10-04 (stock, sales, fiscal, organisation, days), 72 findings, kept here because
their first copy lived in the gitignored `var/claude/flaws/`. The ten ruled on 2026-10-04 09:55 are recorded in
`docs/SPEC.md` § 7; the rest are put to the developer one by one (audit 2026-10-06, P-4), each becoming a row, a
ruling or a recorded rejection. Static reads, nothing run: a finding here is a lead, not a verified defect.


## Stock / product / buying: modelling limits (read-only audit, 2026-10-04)

Method: `docs/SPEC.md` (§1-§3, §7 greps, §8 rows 74-87, 101, 110, 116), then `api/src/Module/{Inventory,Products,Vendors,Expenses}` read with Read and git grep. There is no `Purchasing` module directory and no `Projects` module (rows 80, 81, 84 are `todo`). Nothing was run. "Ruled" means a SPEC § 7 entry exists; "built" means code exists.

Already ruled and NOT repeated as findings: one home per establishment (relaxed 2026-10-04 09:04), lots/serials/expiry/FEFO (built, 2026-09-22 11:10), weighted-average cost on receive (2026-10-03 08:09, built), moves inside an establishment (built, row 74), write-offs with reason (built, row 74b), reorder point per product and establishment (built, row 110), pack barcodes (built, `BarcodeRole::Pack`), customer return through a credit note (built, `StockMovement::saleReturn`).

Ranking = how soon a real quincaillerie or tourneur hits it.

---

### 1. Sales and deliveries leave from the establishment's default location only
- **Scenario.** A shop keeps 40 hinges on the shop-floor rack and 200 in the stockroom, which is exactly what the 2026-10-04 multi-place ruling allows. A delivery note or invoice for 10 hinges is validated.
- **Evidence.** `Inventory/Application/MoveStockForDeliveryNotes.php:132` always uses `$this->locations->defaultOf($establishment)`. Neither the delivery-note line nor the invoice line carries a location (no `location` in `Module/DeliveryNotes`). Untracked goods then go below zero at the default location while the racks hold stock. `onHand` is read per location (`DoctrineStockMovementRepository::onHand`), but the sale path never reads it for untracked goods. Not ruled out: SPEC 2026-09-21 on lots says "an untracked product still goes below zero silently, as before".
- **Severity.** Blocks a first customer once multi-place storage lands. Today every receipt may sit anywhere but every sale drains one bin.
- **Fix.** Pick the sale location from the product's ordered homes (first with enough stock, then spill over, then default), and record the location on each movement. Let a line override it.
- **Question.** How should a sale choose where the goods leave from? (a) Automatic: the main home first, then the next homes in order, never negative at a place that holds stock. (b) The person picks a location per line when validating, with (a) as the proposal. (c) Keep the default location and require a move first (the current behaviour, which cannot be what the multi-place ruling meant).

### 2. A unit is only a code and a number of decimals: no packaging, no conversion
- **Scenario.** The shop buys screws by the box of 500, stores them by the box, sells them by the piece and by the box. It buys cable by the 100 m roll and sells by the metre. The workshop buys steel bar by the 6 m length, prices by the metre and consumes it by the kilo.
- **Evidence.** `Fiscal/Domain/Unit.php`: the columns are `code`, `name`, `decimals`, nothing else. `Product` has one `unit`. A delivery line in another unit than the product's moves no stock and the person is only told (`MoveStockForDeliveryNotes.php:101`). A product's unit cannot change once it has movements (`Products/Application/ManageProducts.php:292-300`), so a wrong first choice is permanent. The only conversion is a pack barcode that enters N pieces at a scan (`BarcodeLine.php:27`, integer 1..max). Ruled in (2026-09-16 composite products "starts with unit conversion"; 2026-09-20 01:02 "unit conversion sits on the product") but row 76 is `todo`.
- **Severity.** Blocks a first customer. Both trades are named by the ruling, and a delivery in another unit silently moves no stock.
- **Fix.** Build row 76's conversion first: per product a purchase unit, a stock unit and a sales unit, each with a factor to the stock unit. Convert at the edge (receipt, delivery line, line pick) and keep the movements in the stock unit.
- **Question.** What does the first working version need from units? (a) Only product-level factors (1 box = 500 pieces), no dimensions. (b) Factors plus a dimension (length, mass, volume) so bar by metre can become kilos with a density per product. (c) Factors now, dimension and density later with the workshop's material jobs.

### 3. Buying has no documents: a receipt names no vendor, order or invoice
- **Scenario.** A delivery arrives from a supplier against an order phoned in last week, 60 of 100 ordered. The owner wants to see what is still due, who supplied the goods and what that vendor charged last time.
- **Evidence.** `grep -ri vendor` over `api/src/Module/Inventory` returns nothing: `StockMovement` has `sourceType`, `sourceId` (null for a receipt) and no vendor. `Module/Vendors` holds a vendor and a number; `Product` links to a vendor only through a supplier barcode (`ProductBarcode::supplier`). The 2026-10-03 08:09 ruling says "vendor prices are a view derived from receipts first", but a receipt cannot say whose it is, so the view cannot be built as ruled. Order, receipt and bill are ruled (2026-09-20 00:52) and rows 81 and 84 are `todo`. The 2026-10-03 21:17 ruling defers several lots in one delivery to row 81.
- **Severity.** Blocks a first customer: "restocking from suppliers" is half of the shop's ruled first version.
- **Fix.** Land row 81 before more receipt features. If it slips, give a receipt an optional vendor, reference and date now, so the data starts accumulating and the derived price view is possible.
- **Question.** Should a vendor and a supplier delivery reference be added to the existing receipt form now, ahead of row 81? (a) Yes, optional fields on the movement now, migrated into the goods receipt later. (b) No, wait for row 81 and accept receipts without a vendor until then. (c) Yes, and also an optional vendor per product with last price.

### 4. The workshop's core flow does not exist: raw material to part, with scrap and cost
- **Scenario.** The tourneur takes 3 m of bar, turns 40 pins and scraps 2. Stock must lose the bar, gain 40 pins at the bar's cost plus a share of hours, and the scrap is a cost of the job.
- **Evidence.** No transformation operation in `Module/Inventory` (the only movement sources are receipt, count, delivery note, invoice, credit note, move, loss: `StockMovement.php:39-45`). `StockWatch` and `KeepStock::tracked` are goods-only (`KeepStock.php:70`). Ruled: generic transform (2026-09-19 23:26), the job (2026-09-20 01:18, "scrap is consumed by the job"), replaced by Projects (2026-09-27 17:20, row 80 `todo`, after row 162). Row 80 now says "material, output and scrap when Stock is on", with the transformation built "inside the composite-products goal", which has no row of its own and depends on row 76.
- **Severity.** Blocks the workshop as a first customer. Without it the tourneur can only receive bar and write off the rest by hand.
- **Fix.** Give the transformation its own row ahead of row 80: consume lines, produce lines, scrap lines, one transaction, output valued at consumed cost (+ optional extra cost). It needs row 2's conversion for bar-to-kilo.
- **Question.** What order should the workshop's stock side be built in? (a) Transformation as its own small slice now, Projects later. (b) Inside row 80 as ruled, workshop waits. (c) A manual "production entry" (a receipt with a typed cost, plus a write-off with reason "internal use") as a stopgap.

### 5. Negative stock is silent for plain goods and has no setting
- **Scenario.** A clerk invoices the last 5 screws twice; the stock reads -5 and nobody is told. An owner who sells goods before the supplier delivery wants the opposite: allow it on purpose.
- **Evidence.** `takeOut` writes the movement without comparing on-hand for untracked products (`MoveStockForDeliveryNotes.php:140-146`); only lot-tracked goods are picked against what is there. Moves and write-offs do refuse (`KeepStock::move`). `StockLevelResource.php:95` documents "negative stock says more left than was ever received". SPEC 2026-09-21: "an untracked product still goes below zero silently, as before". No setting found for warn / forbid / allow.
- **Severity.** Annoying. A negative level is an honest signal here, but the sale path gives the clerk none.
- **Fix.** A per-company (override per category) setting `stock.negative`: `allow` (today), `warn` (the line says "only N in stock", sale proceeds), `refuse`. The stock keepers' alert already exists.
- **Question.** What should a sale do when stock is short? (a) Warn the clerk, sell anyway, alert the stock keepers. (b) Per company setting with allow / warn / refuse. (c) Leave silent and rely on « À surveiller ».

### 6. A quarantine location changes nothing: its stock is sellable, counted and valued
- **Scenario.** Returned or doubtful goods are moved to a quarantine location "awaiting a decision". The counter still shows the product as in stock, the reorder alert does not fire, and valuation includes it.
- **Evidence.** `StockLocationKind::Quarantine` exists; `git grep Quarantine api/src` finds it only in the enum and the resource's schema enum. No query filters on it: `onHandInEstablishment` (reorder), `totalsOf`/`valuation` (levels, value) and `ReadAvailability::among` (customer screen "in stock") sum every location. Ruled as a location kind (2026-09-19 23:25); its meaning was not.
- **Severity.** Annoying, and silently wrong for any shop that uses it.
- **Fix.** Make "available" a derived figure excluding quarantine (and expired unreleased lots) and use it in alerts, availability and the customer screen; keep valuation including quarantine but show it apart.
- **Question.** Is quarantined stock part of what a shop can sell? (a) No: excluded from available, alerts and the customer band, included in value. (b) No, and excluded from value too until released. (c) Keep as is (a label only).

### 7. A return to the vendor does not exist, and a customer return always goes back to its sale's bin
- **Scenario.** 20 faulty drills go back to the supplier for credit. A customer returns a cracked saw: it should go to quarantine, not to the shelf.
- **Evidence.** No return-to-vendor movement source or flow (list at `StockMovement.php:39-45`); the nearest is a write-off whose reasons are lost, broken, expired, stolen, internal use, sample (`StockLossReason`). `saleReturn` restocks "to the lot and the location the sale took it from" (`StockMovement.php:266-270`), so it re-enters sellable stock at the default location. Ruled and scheduled (2026-09-19 23:48: "each to a chosen location or quarantine"; "a return to a vendor takes them out") but not built.
- **Severity.** Annoying for the shop; the credit from the supplier cannot be traced at all.
- **Fix.** Two additions: the customer return takes a destination (default: where it left; options include quarantine); a vendor return is a movement linked to the vendor, later to the supplier credit note.
- **Question.** Where does a returned customer item go by default? (a) Back where it left, the person can choose quarantine on the credit note. (b) Always quarantine until someone releases it. (c) A per-product or per-reason rule.

### 8. Variants are plain products with a free-text "substitution group"
- **Scenario.** One bolt in 6 diameters × 5 lengths × 2 finishes is 60 products. The owner wants them side by side, one price rule per family and a sales view by family.
- **Evidence.** `Product.php:58` has `substitutionGroup` (a label for products that replace each other) and no family or axis fields. Ruled: "a variant is a product, gathered in a family" (2026-09-20 01:02), row 76 `todo`. Stock, barcode and lines stay on the single key, so nothing is blocked, only unmanageable at scale.
- **Severity.** Annoying. The first customers can run on separate products, and the import engine can load 60 rows.
- **Fix.** Row 76's family: a name, up to three axes, per-product axis values, a matrix screen to create the missing ones. No second identity.
- **Question.** Should the family wait for row 76 as a whole? (a) Yes. (b) Ship a minimal family (grouping and axes, no matrix screen) first. (c) Reuse `substitutionGroup` as the family key for now.

### 9. No landed cost, no purchase currency, and one company-wide weighted average
- **Scenario.** A pallet from Italy: invoice in EUR, plus freight, customs duty and a forwarder's fee in TND. The cost of each item should include its share; the margin on the home screen should be real.
- **Evidence.** A receipt carries one `unitCost` (decimal(15,4), `StockMovement.php:103`) and nothing else; no freight or customs field anywhere (`git grep -i "freight\|customs\|douane" docs/SPEC.md` finds nothing). Foreign-currency documents are out of the POC list (SPEC 2026-09-09) and later ruled as a design, not built. Average is computed over every costed movement of the product across all locations and establishments (`valuedTotalsOf`), and the weighted average is flagged UNCERTIFIED legal (SPEC 2026-10-02 05:24). A cost per lot was "not asked" (2026-10-03 21:17). No ruling about landed cost either way.
- **Severity.** Annoying: the shop can type a loaded unit cost by hand, which loses the audit trail. Rises to blocking when the margin card is trusted.
- **Fix.** On a goods receipt, an additional-costs block (freight, customs, other) allocated to lines by value, weight or quantity, written into each line's `unitCost`, with the amounts kept for the trail. Purchase currency belongs with the foreign-currency work.
- **Question.** Does the first version need landed cost? (a) Yes, extra costs on the receipt, spread by value. (b) Typed unit cost only for now, note the limit in the UI. (c) Landed cost only with the purchase order and bill (rows 81 and 84).

### 10. Kits and bundles are not representable (a box that sells as one line and consumes several)
- **Scenario.** A "coffret outillage" is sold as one line, but the 12 bits and the case inside must leave stock; or a made-up bundle (door set: hinges + screws + handle).
- **Evidence.** Recorded as wanted, "built when it earns its place" (SPEC 2026-09-21 18:50, which also refuses loosening the goods-only guard at `KeepStock.php:70`). Today a kit is either a counted good (components do not move) or a service (nothing moves). Needs the composition from row 76's area (no row of its own).
- **Severity.** Later for a first customer, annoying when promotions or sets begin.
- **Fix.** A `composition` (component, quantity) on a product, expanded at delivery into component movements; kit availability derived from components.
- **Question.** When should kits arrive? (a) With the transformation slice (finding 4), same engine. (b) Separately after variants. (c) Not until a customer asks.

### 11. Reorder is one number: no target, order quantity, vendor or lead time
- **Scenario.** The owner wants "bring screws to 2000 in boxes of 500, ordered from X who takes 10 days", and a list of what to order today per vendor.
- **Evidence.** `ProductReorderPoint` stores a single `quantity` per product and establishment (`ProductReorderPoint.php:52`); the alert compares on-hand at the establishment after a movement (`RaiseStockAlerts.php:71-77`). It ignores goods already ordered or in transit (nothing to read: no orders), and quarantine (finding 6). `Product` has no preferred vendor, price list, lead time or minimum order (grep in `Product.php`, `ProductDetails.php`: none). The 2026-10-03 ruling defers an editable vendor price list to purchase orders.
- **Severity.** Annoying. It works as a low-stock bell, not a replenishment tool.
- **Fix.** With row 81: a per-product-per-vendor record (vendor code, price, minimum quantity, lead days, preferred flag) and a "to order" screen computing target minus (on hand + on order) by vendor, creating a draft purchase order.
- **Question.** What should reorder produce? (a) A "to order" list grouped by vendor, then a draft purchase order. (b) Keep the bell, add only a target quantity per point. (c) Nothing more until orders exist.

### 12. Availability is yes/no company-wide: nothing is reserved, nothing is backordered
- **Scenario.** Two clerks quote the last 5 items to two customers. A workshop accepts a quote for 200 pieces of material; the stock still shows free. A customer orders 100 when 60 are in stock and 40 are due.
- **Evidence.** `ReadAvailability::among` returns only `inStock` = company-wide total > 0 (`ReadAvailability.php:40-55`). No reserved or committed field on any stock row. Portal ruling: "nothing is reserved" (2026-09-20, line ~999); stock reservations are "kept with a trigger, not scheduled" (2026-09-19 23:48). Partial delivery exists on delivery notes (row 129), so a short line can be delivered in parts, but there is no backorder list or promise date. Table-booking reservations (2026-09-20 19:40) are a different thing.
- **Severity.** Later for a one-site shop, annoying with quotes (row 78).
- **Fix.** A derived `committed` quantity from accepted quotes and drafted delivery notes (no new table), and `available = on hand - quarantine - committed`; a backorder is a quote line's delivered/remaining quantity, already planned.
- **Question.** Should an accepted quote hold stock? (a) No, show committed as information only. (b) Yes, soft hold derived from open quotes. (c) Not before the portal ships.

### 13. No transfers between establishments, no in-transit state, no vehicle stock
- **Scenario.** A shop with a second branch sends 50 bags of cement across town; the van carries stock for site deliveries. Goods are on the road for a day.
- **Evidence.** `StockMovement.php:217-218`: "A move stays inside one establishment." A location always belongs to an establishment (`StockLocation.php:46-48`), so a van is a site of whichever establishment owns it. Ruled "later, after research on the transport document" (2026-09-19 23:25). The count of establishments the first customers have is UNVERIFIED (SPEC says "any business with a place").
- **Severity.** Later, unless a first customer has two sites.
- **Fix.** A transfer document: out of site A (a shipped state, goods in an "in transit" location kind per company), received into site B, with the transport document number.
- **Question.** Do the first two customers have more than one site? (a) Yes, build transfers soon. (b) No, leave it. (c) One of them uses a van: build only a vehicle location kind.

### 14. Goods that are not ours: no owner on stock (customer-supplied material, consignment)
- **Scenario.** A customer brings his own steel to be machined: it sits in the shop, must be counted and returned, and must never be valued or sold. Or a supplier leaves goods on consignment, paid when sold.
- **Evidence.** No `owner` or ownership notion on `StockMovement`, `StockLocation` or `Product`; `git grep -i "consign\|customer-supplied\|matière fournie" docs/SPEC.md` finds nothing, so not ruled out either. Valuation sums every movement with a cost (`DoctrineStockMovementRepository::valuation`).
- **Severity.** Later; probably real for a subcontracting tourneur, but UNVERIFIED that the first workshop does this.
- **Fix.** A location flag "not ours" (excluded from value, availability and sale) as the minimal model; consignment would also need a settle-on-sale flow.
- **Question.** Does the workshop machine material the customer supplies? (a) Yes: add the "not ours" flag with the transformation slice. (b) No. (c) Ask the developer's first customer before deciding.

---

Count: 14 findings. Not verified by execution: this is a static read; no test or container was run. UNVERIFIED items are marked inline (number of establishments for finding 13, whether the workshop takes customer material for finding 14). The stock count screen, the opening-stock import and the web screens under `web/src/app/{products,inventory}` were listed but not read for limits.

## Sales-side modelling limits, twes-in (read-only audit, 2026-10-04, HEAD 8f24e520)

Method: SPEC §1-§3, §7 greps, §8 status block, then `git grep` / Read over `api/src/Module/{Invoices,DeliveryNotes,Customers,PriceLists}`, `api/src/Settings`, `web/src/app/{invoices,delivery-notes,customers}`. No Docker or make run. Nothing was executed, so every "cannot" is a static-code claim (Verified = read in code; UNVERIFIED = not confirmed).
Already built and NOT flagged: partial invoicing of delivery-note lines and invoicing several notes at once (`InvoiceDeliveryNotes.php`), customer credit balance with deposit/apply/refund-on-credit-note (`ManageCustomerCredit.php`, `CreditEntryKind`), credit note on a paid invoice, statement of account, price lists with quantity breaks, per-line tax/discount %, customer default discount, withholding frozen at issue, lots/serials, duplicate invoice, credit-note restock (`returned`).
Ranking = how soon a hardware shop or machining workshop hits it.

---

### 1. No quote, no order: the chain starts at the delivery note, and two rulings disagree on what the order is
- Scenario: a workshop quotes a batch of 200 parts, the customer accepts, parts ship in three lots, one invoice at the end. The shop wants "what is still owed to the customer".
- Evidence: no `Quotes` module exists (`ls api/src/Module` = Customers, DeliveryNotes, Expenses, Inventory, Invoices, PriceLists, Products, Vendors). A delivery note carries no source document (`DeliveryNote.php`: only `invoicedByInvoiceId`, line 101). Rows 78 (quote, L), 122 (signature boxes) are todo/doing. Row 78 says "acting as the order... no separate sales-order type" (SPEC §7 2026-09-20 00:45), but §7 2026-09-21 14:55 AGREED three documents: devis, a separate commande client (partial conversion, more than once, own numbering). §8 has no row for the commande client. Ruled IN, not built; the contradiction is the finding.
- Severity: blocks a first customer (the workshop, "quotes acting as the order" is its stated thin path, §2).
- Fix: supersede row 78's wording with the 09-21 decision and add a row for the commande before building quotes, so the quote model is built once.
- Question: which model stands? (a) quote is the order, one record tracks delivered/remaining; (b) devis plus separate commande client, as 2026-09-21 14:55; (c) build quote first as a priced offer only, decide the commande when the first workshop job needs partial delivery.

### 2. A deposit is an unnumbered credit entry with no VAT treatment, no document, and no cash refund
- Scenario: a customer pays 30 % in advance on a 12 000 TND job, then cancels, or the job is delivered. The shop needs a receipt it can print and a VAT-correct final invoice.
- Evidence: `ManageCustomerCredit::deposit` (line 82) writes a `CustomerCreditEntry` (kind `deposit`); `apply` (113) turns it into an ordinary `Payment` on the invoice. No number, no print, no tax split. The only `Refunded` entry is written from a credit note (`InvoiceWorkflow.php:145`, `CustomerCreditEntry::refunded`), so a deposit cannot be handed back without issuing an invoice and a credit note. Row 79 (deposit invoice, deducted by the engine) is todo; the 2026-09-21 14:55 ruling says the preset picks receipt vs deposit invoice and is UNCERTIFIED - LEGAL.
- Severity: blocks (workshop: deposits are in its thin path); the missing refund is annoying.
- Fix: keep the credit entry as the money record, add the receipt document and the preset switch from the 09-21 ruling; add a "refund credit" action writing a `refunded` entry with no invoice.
- Question: ship the interim receipt first? (a) printed numbered « reçu d'acompte » on the credit entry now, deposit invoice later; (b) wait for the legal check and build the deposit invoice only; (c) interim receipt plus refund action, both without VAT lines.

### 3. Post-dated cheques and traites: ruled, but no status row, and today they cannot be recorded at all
- Scenario: a trade customer hands over a cheque dated in 45 days and a traite at 90 days; the shop invoices today and wants the portfolio and due dates.
- Evidence: `Invoice.php:504` refuses a payment dated in the future (by design, SPEC §7 2026-09-21 18:40 AGREED: instruments are their own record, en portefeuille -> remise -> encaissé / impayé). `PaymentMethod` has `check` but no due date, bank or state. §7 2026-09-24 11:40 item (4) says "its own row"; `sed` over the §8 status block finds no row for instruments (rows 60-135 searched for cheque, traite, instrument, IN-B-14): the row was never created, so nothing schedules it. Today the shop must either leave the invoice unpaid (receivables overstated) or backdate-record it (says paid when holding paper that may bounce).
- Severity: blocks (hardware shop trade clients; "ordinary in Tunisian B2B" per the ruling).
- Fix: add the row now (instrument entity: amount, due date, bank, state; becomes a Payment on clearing; dishonour reopens the invoice).
- Question: scope of the first slice? (a) cheques and traites with full state machine; (b) record-only portfolio (amount, due date, bank, a "cleared" button creating the payment) without the bank-remittance state; (c) defer, tell shops to leave invoices open.

### 4. One payment settles exactly one invoice: no multi-invoice receipt
- Scenario: a customer wires one transfer covering invoices 112, 118 and 121. The cashier needs one receipt and one bank reference reconciled.
- Evidence: `Payment.php:30` `invoice` is a non-nullable ManyToOne; `recordPayment` is per invoice and capped at what is due (`Invoice.php:497`). The only multi-invoice route is deposit-then-apply (`ManageCustomerCredit::apply`), which splits the transfer into a deposit plus N applied payments with the reference lost on the way. SPEC §7 2026-09-24 11:40 names "IN-A-01's receipt aggregate" for a company-wide payments list, but no row builds it (UNVERIFIED that IN-A-01 is planned elsewhere; grep finds only that mention).
- Severity: annoying (workaround exists, daily cost for shops with account customers).
- Fix: a "receipt" parent record owning N payment lines (one date, method, reference, total); payments stay per invoice for the ledger.
- Question: model? (a) receipt aggregate over per-invoice payments (matches the 09-24 note); (b) a "pay these invoices" dialog that just creates N payments sharing a reference; (c) leave deposit-then-apply as the answer.

### 5. A single due date: no instalment schedule
- Scenario: the workshop sells a 9 000 TND machine part payable in three monthly instalments; the home and statement should show three due amounts and dates.
- Evidence: `Invoice.php:442` `dueDate = issueDate + paymentTermsDays`, one column (`Invoice.php:112`); the open-due index and overdue logic read that one date. `git grep -i instalment` hits only an unrelated VAT sentence (SPEC line 2732). No ruling either way.
- Severity: annoying (workaround: three invoices or notes text, which breaks the one-sale-one-invoice rule).
- Fix: optional schedule rows (date, amount) on the invoice, frozen at issue; overdue = first unpaid row past due; ties naturally to the instruments in finding 3.
- Question: (a) schedule of due dates per invoice, frozen at issue; (b) payment-terms presets only ("30/60/90 split" as a preset) with no per-invoice editing; (c) not needed, rule it out for now.

### 6. A delivered delivery note cannot be undone, and goods coming back before invoicing have no path
- Scenario: 40 hinges delivered Monday, 15 returned Tuesday before the month-end invoice. Stock must go up, the customer must not be invoiced for them.
- Evidence: `DeliveryNote::cancel` (`DeliveryNote.php:286-287`) accepts only draft or validated; `Delivered` and `Invoiced` are terminal. No return note exists (`git grep` retour/return note in SPEC finds none). The restock path exists only on credit notes (`returned`, `Invoice.php:454`), so the shop must invoice all 40, issue a credit note for 15 (two fiscal numbers for a non-sale) or edit the draft invoice quantity and leave 15 delivered forever in stock terms. Partial invoice quantities do work (`InvoiceDeliveryNotes`), but they do not restock.
- Severity: annoying (hardware shop returns weekly).
- Fix: a "return" document or a partial cancel on a delivered note, moving stock back (the `MoveStockOnDeliveryNotes` listener already handles the cancel event shape).
- Question: (a) partial return on a delivered note (quantities, restocks); (b) a separate return note type; (c) keep as is and document "invoice then credit note with Returned".

### 7. Price lists are applied only by the browser; the API ignores them
- Scenario: a recurring invoice, an import, a quote converted to an invoice or the future till creates lines without a typed price; the customer's list price should apply.
- Evidence: the resolver is `ResolveUnitPrice` in `PriceLists/Application`, called only by `ProductPriceProvider` (`git grep ResolveUnitPrice` outside `PriceLists/` returns nothing). The web invoice and delivery-note lines call `GET .../products/{id}/price` (`invoice-lines.ts:254`, `delivery-note-lines.ts:218`). Server side, `ManageInvoices.php:275` does `$line->unitPriceNet ?? $product->unitPriceNet`: a line sent without a price gets the product's base price, never the list price. Rows 86 (recurring), 82 (register), 78 (quote) will all create lines server-side.
- Severity: annoying today (the screen works), becomes wrong-price-silently for every non-browser path.
- Fix: resolve through `ResolveUnitPrice` in `ManageInvoices`/`ManageDeliveryNotes` when no price is given; keep the typed price winning.
- Question: where is the price decided? (a) server resolves when absent, browser only previews; (b) server always re-resolves and flags a differing typed price; (c) keep browser-only and require every new creator to call the endpoint.

### 8. The credit limit only warns, after the fact, and ignores goods delivered but not invoiced
- Scenario: a customer at 95 % of a 5 000 TND limit asks for another delivery; the counter staff should be stopped or must get a manager override.
- Evidence: SPEC §7 2026-10-02 01:49 (ASSUMED (review), not AGREED): "it warns, it never refuses"; goods delivered and not yet invoiced are not on the account. Code: `DeliveryNoteCredit.php` returns `over`, `TellCreditWatchers` only notifies after issue (`AlertOnCreditLimit` is an `InvoiceIssued` listener), `credit.limit` is a plain setting (`BusinessDefaultSettings.php:44`). No hold, no overdue-based block, no check on invoice issue or at order time. Because the account is invoiced-only, a shop that delivers first and invoices monthly (the DN flow this product pushes) sees a balance of zero while owed thousands.
- Severity: annoying; becomes blocking for account customers once row 134 (running account) lands unless the base is fixed.
- Fix: count validated/delivered uninvoiced notes in the owed figure; add an optional `credit.limit_action` (warn | require permission | refuse).
- Question: (a) keep warn-only but include uninvoiced deliveries; (b) add a "hold" setting with a permission to override; (c) ratify warn-only as AGREED and move on.

### 9. No bad-debt write-off at all; the only way to close a dead invoice is a credit note
- Scenario: a customer is 14 months overdue and the shop wants it off the receivables without pretending the sale never happened.
- Evidence: row 128 (write-off of a short payment under a tolerance, "doing") is not in code (`git grep -i 'tolerance\|write.off'` in `api/src/Module/Invoices` and `Settings` is empty); SPEC says it is blocked on its legal basis (UNCERTIFIED - LEGAL, 2026-10-02 03:40 "Not in this slice"). A credit note reverses the VAT, which for an unpaid Tunisian invoice may be the wrong fiscal treatment (legal question, UNVERIFIED here). No status for "written off" or "contentieux".
- Severity: later (first-customer invoices are not yet old), but short-pay rounding differences appear within weeks.
- Fix: do the tolerance case first (small amounts closed by a credit note with a reason, as ruled); keep large bad debts outside until the legal source is in `docs/fiscal/TN.md`.
- Question: (a) ship the small-balance tolerance now, leave bad debts to credit notes; (b) wait for the legal source and ship both together; (c) add a status flag "disputed / in collection" with no accounting effect first.

### 10. Nothing on a document line carries the customer's own references (PO line, part number, drawing revision)
- Scenario: a machining customer's order says "line 30, part 4711-B rev C, drawing D-88"; their accounts payable will reject an invoice that lacks it, and traceability needs it.
- Evidence: `InvoiceLineDetails` fields (line 36-44 region): description, quantity, unit, price, discountRate, taxes, source line, lot. Document-level `customerReference` only (`InvoiceHeader.php:25`). Custom fields exist for `Customer` and `Product` only (`CustomFieldEntity.php:15-16`), none for documents or lines. Free text in `description` is the workaround (not searchable, not copied through quote-to-invoice by field). No ruling excludes it (grep drawing/customer part/revision in SPEC is empty).
- Severity: annoying for the workshop, near-blocking for B2B customers with strict AP.
- Fix: a customer-reference column (and optional free key/value) on lines of quote, delivery note and invoice, copied through the chain; or extend custom fields to document lines.
- Question: (a) one `customerLineReference` text column per line; (b) custom fields on documents and lines (reuses the engine); (c) rely on description text.

### 11. Discounts: line percentage and a document FIXED amount only
- Scenario: "10 % off the whole order" or "50 TND off this line". Today: type each line's % or compute the document amount by hand.
- Evidence: `InvoiceHeader.php:29,38` document discount is `discountAmount` only; `InvoiceLineDetails.php:40` line discount is `discountRate` only. `InvoiceTotals.php:64` caps the document amount at the lines' net. No discount reason or approval; customer `defaultDiscountRate` only prefills lines (`invoice-forms.spec.ts:294`) and is not stored as the reason. No ruling found excluding percentage or amount variants.
- Severity: annoying, daily for a shop.
- Fix: accept a document discount rate (stored as the rate, amount derived) and a line amount; optional free-text reason.
- Question: (a) add document % and line amount, store both forms; (b) add only document %; (c) leave as is.

### 12. Foreign customers: one currency per company, no rate on documents or payments
- Scenario: the workshop invoices a Libyan or French customer in EUR and gets paid in EUR into a TND books.
- Evidence: `Company.php:46` one `currency`; `Payment.amount` has no currency or rate; `Invoice` has no currency column. Ruled and deferred: §2 "foreign-currency documents" after the first working version (§7 2026-09-17: reference currency, per-document currency and rate, exchange gain/loss); row 98 (counter foreign note) todo; 2026-09-20 03:20 AGREED per-customer default currency.
- Severity: later (Tunisia-domestic first customers), but a workshop with one export customer will ask.
- Fix: as ruled; interim, document it so a foreign sale is invoiced in TND at a typed rate with the foreign amount in notes.
- Question: (a) keep deferred as ruled; (b) pull the per-document currency forward for the workshop; (c) ship the interim TND-with-note convention.

### 13. Parties on a document: no contact, no bill-to override, no payer/parent customer
- Scenario: a contractor's invoice should be "à l'attention de" the site accountant, billed to head office while goods go to a branch; one head office pays for five branch customers.
- Evidence: invoices and delivery notes name only a customer (`Invoice.php:81`, `customer` non-null). `git grep -i contact` in `api/src/Module/Invoices` and `web/src/app/invoices` is empty: a Contact (`ContactDetails`: name, email, phone, role) cannot be put on a document. The customer has one billing and one shipping address (`CustomerProfile.php:51-52`); only a delivery note has its own `deliveryAddress` (`DeliveryNoteHeader.php:31`); the invoice snapshots the customer's billing address. No parent/payer link (`git grep` payer/bill-to/parent customer in `Customers` is empty). Statement and credit limit are per customer, so a group's exposure is invisible.
- Severity: annoying (later for payer groups).
- Fix: optional contact reference and bill-to customer on the invoice header; parent customer later for statements and credit.
- Question: (a) contact on document only; (b) contact plus a bill-to customer different from the ship-to; (c) parent/payer hierarchy now so statements and limits roll up.

### 14. No salesperson on a sale, no commission
- Scenario: a hardware shop with two counter salesmen pays a commission on sales and wants "sales by seller".
- Evidence: `git grep -i 'salesperson|sales_rep|commission'` across `api/src` finds only an unrelated Tunisian withholding code text; SPEC mentions only a café waiter (line 769). The invoice stores `issuedBy` (`Invoice.php`, user who issued) which is the clerk who clicked, not the seller, and is unavailable for a delivery note or quote. No ruling covers or excludes it.
- Severity: later.
- Fix: an optional seller (membership) on quote, delivery note and invoice, defaulted to the current user; the report engine groups by it; commission stays out of the product.
- Question: (a) seller field plus a "sales by seller" report, no commission maths; (b) seller field and a per-seller rate setting producing a commission report; (c) rule it out, an accountant does it from the export.

---

Not certified by execution: every claim above is a static read (Read/git grep); no test, container or UI was run. Claims that depend on law (finding 2 VAT on deposits, 9 bad-debt VAT) are UNVERIFIED. Finding 4's "IN-A-01 not planned elsewhere" is UNVERIFIED.
Ruled-out check: none of the 14 is already ruled OUT; findings 1, 2, 3, 9, 12 are ruled IN and unbuilt, 8 is an ASSUMED (review) not an AGREED ruling.

## twes-in: money, tax and fiscal limits (read-only audit, 2026-10-04)

Scope read: docs/SPEC.md (§1-§3, §7 by grep, §8 rows), docs/fiscal/TN.md and FR.md, docs/research/tax-data-tunisia.md (e-invoicing section), api/config/fiscal/{TN,FR}.yaml, api/src/Fiscal, api/src/Module/{Invoices,Expenses}. No docker, no make, nothing executed: every claim below was read with Read/Grep/git grep. Nothing here is legal advice; every legal statement is as the repo's own fiscal docs state it (all marked `unvalidated` there) or is flagged UNVERIFIED and needs a chartered accountant.

Checked and found sound (not findings): gapless numbering allocated inside the issuing transaction with yearly/monthly reset and a refusal of a month before the last number (`api/src/Tenancy/Domain/NumberingSeries.php:134`); the issue date is always the company's today, so no back-dating (`InvoiceWorkflow.php`, `$allocated->issueDate`); an issued invoice is never cancelled, only a draft (`Invoice.php:466`); credit notes capped at what the invoice still carries, with the withholding completing exactly (`Invoice.php:760`, `InvoiceTotals.php:150-186`); rate snapshots on credit notes (`retakeTaxes`, `Invoice.php:775`); discount allocation and per-rate-group rounding per EN 16931 BR-CO-17 (`DocumentCalculator.php`).

Ranking: 1 to 3 are legal exposure on a first Tunisian or French customer; 4 to 9 block or hurt a first customer or its accountant; 10 to 14 are later or annoying.

---

### 1. Tunisian e-invoicing is already mandatory for services; nothing can be sent or even exported as TEIF

- **Scenario.** The machining workshop (a service provider declaring services as main or secondary activity) must issue through TTN/El Fatoora since 2026-01-01 if it has joined the network. twes-in issues a PDF only; a paper invoice can cost 100 to 500 TND per document (cap 50,000), and an invoice without the mandatory mentions or QR is exposed too.
- **Evidence.** docs/fiscal/TN.md §12 and docs/research/tax-data-tunisia.md §1.1 (LF 2026 art. 53, NC 02/2026, transitional rule: only providers that completed the TTN membership are bound). Code: Factur-X exists (`Module/Invoices/Application/FacturX/*`, preset TN refused unless listed in `DescribeFacturX::PRESETS`), no TEIF writer, no QR on the PDF, no sending port found. Ruled deferred: SPEC §7 2026-09-20 00:35 and 09:30 ("build every field, schedule no connector"), row 93 todo, row 102 blocked (unestimated), row 145 doing (TEIF 1.8.8 signed).
- **Severity.** Legal risk, and blocks the workshop if it is a TTN member.
- **Fix.** Pull row 145's TEIF file generation ahead of the connector so a company can at least upload a signed file by hand, and put the TTN verification QR on the PDF (row 93).
- **Question.** Is the workshop already a TTN member (it files paper invoices only until it completes the request)? Options: (a) yes, date TEIF + QR before its go-live; (b) no, keep the connector unscheduled and tell the customer in writing; (c) ask the customer's accountant before deciding.

### 2. A legal exemption or 0 % reason cannot be printed per line; the tax component's `exemptionMention` is stored and never used

- **Scenario.** A French invoice mixing 20 % goods and an exempt training line (or a Tunisian invoice with an exempt basic product beside 19 % lines) must name the exemption article on the document. Here the only mention source is the customer's regime, applied to the whole document.
- **Evidence.** `InvoiceMentions::keys()` (`Module/Invoices/Application/InvoiceMentions.php:28`) reads the customer regime, the company regime and the preset only. `TaxComponent::$exemptionMention` is edited and exposed (`Fiscal/Infrastructure/ApiPlatform/TaxComponentResource.php:112`) but `git grep exemptionMention` finds no reader in Invoices or templates. TN.yaml carries no exempt or 0 % component (only TVA19/13/7); FR.yaml likewise (no 0 %, no DOM rates 8.5 / 2.1 overseas, no domestic reverse charge for subcontracting). A line without VAT under a `standard` customer makes Factur-X refuse with `incomplete_document` (`DescribeFacturX.php`, `lineVat`). FR.md §6 admits `exempt` cannot be written as Factur-X. No ruling puts this out; TN.md §7 says the regime mention wording is unsourced, and TN.md §12 names a 250 to 10,000 TND fine for missing mentions.
- **Severity.** Legal risk (mandatory mention) and blocks mixed-VAT documents.
- **Fix.** Print each distinct `exemptionMention` of the components or lines used, beside the regime mentions; add `exempt` (0 %) components with their article to both presets, and a per-line VATEX for Factur-X.
- **Question.** How should a no-VAT line carry its reason? Options: (a) a per-component mention printed once per document (smallest); (b) a free per-line reason field; (c) keep per-customer only and refuse mixed documents.

### 3. No closed period: payments, deletions and issuing can change a month after it was declared

- **Scenario.** A shop files its monthly VAT/stamp declaration on the 28th, then on the 5th someone deletes or back-dates a payment of the declared month: the home figures and any later export differ from the filed return, with only an audit row left behind.
- **Evidence.** `Invoice::recordPayment` (`Invoice.php:486`) only requires a date from the issue day to today; `ManagePayments::delete` (`ManagePayments.php:70`) removes a payment whenever no credit note gave money back; no lock or period concept anywhere under Invoices, Expenses or Tenancy (`git grep -i 'closed\|lock'` finds none relevant). Ruled but not built: SPEC §7 2026-09-20 01:33 ("closing a period ... an invariant, not a feature"), row 92 todo. Expenses are also freely paid with back-dates (`Expense::pay`, `Expense.php:192`), and a draft expense is deletable.
- **Severity.** Legal risk (declared figures must not move) and the accountant's first complaint.
- **Fix.** Build the closed-period invariant first inside row 92 (refuse payments, expense payments and deletions dated before the close date); payments dated inside a closed period go through a dated reversal, not a delete.
- **Question.** Who closes a period and with what date granularity? Options: (a) the owner or accountant sets one "closed through" date per company; (b) automatic close on the 28th after the month, overridable; (c) per-month close with a reopen log.

### 4. When VAT becomes due is modelled as the issue date only: advances, services paid before or after invoicing, FR débits option

- **Scenario.** The workshop takes a 30 % deposit on a job. In Tunisia a service's VAT is due on the receipt of an advance, and France treats advances on identified goods and service receipts (encaissements) the same way unless the company opted for the débits. twes-in records the deposit as a customer-credit balance (no document, no VAT) and counts VAT in the month of the final invoice.
- **Evidence.** `SummarizeInvoices.php:29` ("the basis a company declares on is not modelled yet"); `ManageCustomerCredit.php` (deposits are a balance, not a fiscal document); TN.md §2a rows 1-2 and FR.md §2a rows 1-2 state the gap and the 2023 French rule; FR.md "Known gaps" lists the débits option as not recorded. Not ruled out: row 112 only added the "TVA collectée" label (SPEC §7 2026-09-24 11:40); deposits sit in the workshop path "quotes acting as the order, deposits" (SPEC §2, first working version), unbuilt.
- **Severity.** Legal risk for any VAT-registered service business (the declared VAT is wrong in the month of a deposit); UNVERIFIED how Tunisian practice applies art. 5-3 to a deposit, needs an accountant.
- **Fix.** Add a company VAT basis setting (invoices / receipts) and a deposit invoice (facture d'acompte) as a real numbered document that the final invoice deducts; compute the VAT report on the chosen basis.
- **Question.** Which basis does the workshop's accountant declare on? Options: (a) invoices only for v1 and print the limit on the VAT figure; (b) receipts-basis report plus deposit invoices before the workshop goes live; (c) ask the accountant first.

### 5. Withholding suffered (retenue à la source): rate, threshold basis and certificate are not tracked

- **Scenario.** A public-sector or company customer withholds 1 % (or 0.5 / 1.5 % depending on who it pays) on a payment of 1,000 TND or more and later hands over a certificate. The invoice either carries a fixed `RS1` component or nothing; the supplier cannot record "withheld by the customer, certificate received on date X", and cannot tell which withheld amounts are still owed as a tax credit.
- **Evidence.** One document-level `withholding_total` component with one rate and threshold compared with the invoice total, not the payment (`DocumentCalculator.php:104-113`; TN.md §5 says the base and the stamp treatment are "the preset's choice"). Not modelled by ruling: exclusions, reduced rates, TN.md §5 and "Known gaps". The customer must be flagged as payer by hand (`default_tax_component_ids`, SPEC §3). The recapitulation of withholding suffered and certificate follow-up are row 91 (todo); the expense side (withholding the company applies) is built (row 111, TEJ XML). No certificate number, received date or status exists on invoices.
- **Severity.** Blocks a first customer who sells to withholding payers (most B2B in Tunisia); the exact threshold basis (invoice vs each payment) is UNVERIFIED.
- **Fix.** Per invoice (or per customer) rate override; a "withholding certificate" record with number, date and status linked to the invoice, feeding the row 91 recap.
- **Question.** Where should the rate live? Options: (a) one component per rate (RS05, RS1, RS15) chosen per customer; (b) an override on each invoice; (c) leave to the accountant and only track certificates.

### 6. No way to settle a short payment, an early-payment discount, a bill of exchange or a bad debt

- **Scenario.** A customer pays 99 % because it withheld tax the invoice did not show, or takes an early-payment discount, or pays by post-dated cheque that bounces. The invoice stays "partially paid" with a residue nobody can close, or shows "paid" while the cheque is still uncashed.
- **Evidence.** `Invoice::recordPayment` caps a payment at what is due and has no write-off, discount or reversal (`Invoice.php:486-515`); `PaymentMethod` is transfer/cash/check/card/other (`Shared/Domain/PaymentMethod.php`), no cheque lifecycle (received, deposited, cleared, bounced), no bill of exchange (traite), no mobile wallet; `git grep -i 'write.off\|escompte\|bad debt'` finds nothing in Invoices. The only way to close a residue is a credit note, which reverses VAT. Not ruled out.
- **Severity.** Blocks a first customer (shop credit and cheques are everyday) and distorts "collected" and aged balances.
- **Fix.** A payment kind "settlement difference" with a reason (withholding, discount, write-off) that is VAT-neutral by default and flagged for the accountant; a cheque status on payments.
- **Question.** Which tender flows must v1 carry? Options: (a) residual-closure reasons only; (b) plus cheque and traite lifecycle; (c) keep manual and say so in the docs.

### 7. Company VAT regimes: Tunisia has only `standard`; France lacks the other real cases

- **Scenario.** A small Tunisian business under the lump-sum (forfaitaire) regime or one not VAT-registered cannot say so: its invoices still default to 19 % VAT. A French builder under domestic reverse charge (sous-traitance BTP) or a company in an overseas department cannot be served.
- **Evidence.** `company_vat_regimes` in TN.yaml:73 has only `standard`; FR.yaml:76 has `standard` and `franchise`. TN.md §2a: the forfait declaration "not researched". The open question of the first customers' regime was closed as "serve any regime" (SPEC §7 2026-09-20 04:15, 09:30), but the TN preset does not hold the regimes to serve them.
- **Severity.** Blocks a first customer if either is on forfait or exempt; unknown today (SPEC says the developer does not know).
- **Fix.** Add TN `forfaitaire` and `exonere` company regimes (VAT family excluded, own mention) after sourcing them in TN.md; add FR `autoliquidation_domestic` after sourcing in FR.md.
- **Question.** Which regimes must be ready for the two first customers? Options: (a) ask them now and add exactly those; (b) add forfait and exempt for TN now, others later; (c) leave `standard` only and say it on signup.

### 8. Expenses: one VAT rate on the net, computed not entered; no FODEC, stamp, deductibility or entered-VAT match

- **Scenario.** A Tunisian supplier invoice shows HT, FODEC 1 %, VAT 19 % on HT+FODEC, and a stamp. The expense takes one rate on the net and computes the VAT itself, so its VAT differs from the invoice by the FODEC share and a millime, and the accountant's deductible VAT does not reconcile. A French invoice mixing 20 % and 5.5 % cannot be entered at all; non-deductible VAT (fuel share, entertainment, vehicles) has nowhere to go.
- **Evidence.** `Expense::apply` computes `taxAmount = net x rate` (`Expense.php:298`); `ExpenseDetails` has no tax amount input (`ExpenseDetails.php`); a test even asserts "only a rate on the net taxes an expense" (`api/tests/Functional/ExpensesTest.php:49`). `git grep -i deduct` finds no field. SPEC §7 2026-09-27 18:34 calls an expense something that "may carry deductible VAT" but nothing models deductibility. Purchases journal and VAT summary are row 92 (todo).
- **Severity.** Blocks the accountant's workflow (deductible VAT is half of the monthly return).
- **Fix.** Let an expense carry entered tax lines (code, base, amount, deductible share) and keep the computed value only as a suggestion; add deductible/non-deductible split.
- **Question.** What precision do the first customers' accountants need? Options: (a) entered VAT amount plus deductible flag; (b) full multi-line tax like an invoice; (c) keep the single rate and export a note.

### 9. A supplier bill can be paid only once

- **Scenario.** The workshop pays a steel supplier in three post-dated cheques. An expense takes one payment date, one method, one withholding; the second and third cheque cannot be recorded, and the TEJ certificate is one per expense.
- **Evidence.** `Expense::pay` accepts only status `recorded` and ends in `paid` (`Expense.php:192-225`); there is no payment collection on the expense; the TEJ declaration builds one certificate per expense (`DeclareTejWithholdings.php`, docblock). Not ruled out.
- **Severity.** Blocks (trade credit with instalments is normal for both first customers); also wrong TEJ data if the withholding is taken on the last instalment only.
- **Fix.** Give expenses a payments list like invoices, with the withholding taken per payment.
- **Question.** Should instalments exist for expenses in v1? Options: (a) yes, mirror invoice payments; (b) split the bill into several expenses by hand and document it; (c) defer with the Trésorerie module (row 180).

### 10. France e-invoicing reform fields are claimed in the docs but absent from the code

- **Scenario.** From 2026-09-01 French businesses must be able to receive e-invoices and SMEs must issue them from 2027-09-01, carrying operation category (goods, services, both) and the VAT-on-debits flag. The data is not stored, so a Factur-X for a mixed or services invoice cannot declare it.
- **Evidence.** docs/fiscal/FR.md:65 says "The invoice data model already has `operation_category` and `vat_on_debits` (docs/SPEC.md § 4)", and SPEC §4 lists them; `git grep -i 'operation_category|vat_on_debits'` over the whole repo outside docs returns nothing (no entity, migration, config or web code). Docs drift, row 93 (todo) is where they would land.
- **Severity.** Later for the French launch (date 2027-09-01 for SMEs), but the docs overstate what exists.
- **Fix.** Correct FR.md and SPEC §4 now, then add both columns with row 93 and per-line goods/service from the product kind.
- **Question.** Fix the docs only now, or build the columns with row 93? Options: (a) docs now, columns with row 93; (b) both now; (c) leave until a French customer signs.

### 11. No foreign-currency document, payment or expense; no exchange rate or gain/loss

- **Scenario.** The workshop invoices a French client in EUR, or imports steel priced in EUR. There is an `export` customer regime but no way to state the currency, the rate at issue, or the exchange gain or loss at payment.
- **Evidence.** The invoice has no currency column or field in code (`git grep currency -- Module/Invoices` only reads the company's currency, e.g. `InvoiceTotals.php:54`); `Payment` has no currency or rate; `Expense::$currency` is copied from the company (`Expense.php:125`). Ruled later, with the exact design: SPEC §7 2026-09-17 and 2026-09-20 03:20 ("reference currency, per-document currency and rate, payments with exchange gain or loss"), SPEC §2 "After the first working version"; company currency fixed (SPEC §3 Money).
- **Severity.** Later for a domestic first customer; blocks the first export invoice (Tunisian exporters are often paid in EUR and the TND VAT/accounting value needs the BCT-style rate; UNVERIFIED which rate a Tunisian accountant uses).
- **Fix.** Keep the ruling; reserve nullable `currency`, `exchange_rate`, `rate_date` columns on invoice, payment and expense before data accumulates, so the later migration does not rewrite issued documents.
- **Question.** When does the first foreign-currency document appear? Options: (a) not before the workshop's first export, build then; (b) reserve the columns now, build later; (c) pull it into the first version.

### 12. Stamp duty on credit notes and the reach of the regimes over stamp and FODEC are unsourced

- **Scenario.** A 5 TND price-correction credit note refunds the whole 1.000 TND stamp (and a second credit note refunds it again until the cap bites). An export or exempt invoice still carries the stamp and, if chosen, FODEC.
- **Evidence.** A credit note copies the invoice's document taxes (`Invoice.php:334`) and the calculator applies the fixed charge with the credit sign (`DocumentCalculator.php:91-95`), whatever the amount; no pinning vector covers it (`docs/spec/pricing-vectors.json` has no credit-note-with-stamp case). TN.md §4 says nothing on credit notes; §9 says "Whether FODEC applies to exempt, suspended or export sales was not established"; the regimes exclude the `vat` family only (TN.yaml). Not ruled out.
- **Severity.** Annoying and a possible stamp over-refund; legal treatment UNVERIFIED, needs a Tunisian accountant.
- **Fix.** After the accountant answers, either make the stamp not reversible on credit notes or reverse it only on a full credit; add the stamp and FODEC families to a regime's `excluded_families` where the law says so.
- **Question.** What should a credit note do with the stamp? Options: (a) no stamp on credit notes; (b) reverse it only when the credit note is total; (c) keep as is until the accountant rules.

### 13. Accountant outputs: no journals, VAT summary, deductible VAT, balance or FEC yet

- **Scenario.** The Tunisian accountant needs monthly VAT collected and deductible by rate, stamps, FODEC and withholding; the French accountant needs a sales journal that can feed a FEC. Today the list exports of invoices and expenses exist and the home shows invoiced VAT, nothing else.
- **Evidence.** Row 92 (journals, VAT summary, accountant role) todo; row 91 (declarations) todo; the FEC and a ledger are ruled out (SPEC §2 "Out, with no date"; §7 2026-09-20 01:33: "produced by whoever keeps the books"). `InvoiceExport.php` columns are one row per document, not per tax line. There is no deductible-VAT side (finding 8) and no VAT credit carried forward between months. Aged balance exists as home buckets by due date (`SummarizeInvoices.php`), not a per-customer report.
- **Severity.** Blocks a first customer's accountant, later for the FEC (ruled out).
- **Fix.** Build row 92's journals per tax line and make the sales journal's column set FEC-mappable even though no FEC is produced.
- **Question.** What does the first accountant import? Options: (a) CSV per tax line, accountant maps it; (b) an accounting-software format they name; (c) a FEC-shaped sales journal for France only.

### 14. Archive: issued PDF rendered late if the renderer failed, not tamper-evident, retention purge not built

- **Scenario.** If Gotenberg is down at issue, the legal PDF is rendered on first download, possibly after a template change; no hash or signature chain proves it is the original; the later purge of "files with legal retention" is unbuilt and its ten-year value is one number for both countries.
- **Evidence.** `StoreIssuedInvoicePdf.php` (a renderer failure is logged and "its first download stores it"); `Invoice::attachPdf` keeps one PDF; the file row has a sha256 (SPEC §4 `file`) but no chain; the fiscal journal that would chain and sign is "design ruled, nothing built" (row 103, SPEC §7 2026-09-20 11:30); retention is row 58 (todo, "issued PDFs and booked receipts kept ten years") and per-country archive duration is row 75 (todo). Issued documents are immutable in the domain only (UNVERIFIED: no database-level guard was read).
- **Severity.** Later; legal risk only once retention purges or a tax audit arrive. Retention lengths (Tunisia, France) UNVERIFIED, need an accountant.
- **Fix.** Store the PDF inside the issuing flow or retry it from a worker until stored (never regenerate lazily), record its sha256 at issue, and read the retention period from the country pack.
- **Question.** How strict must archiving be at launch? Options: (a) retry-until-stored plus sha256 at issue; (b) wait for the fiscal journal (row 103); (c) accept lazy rendering and document it.

---

Minor, not counted: the rounding point is a preset value only (`FiscalPreset::$vatRoundingPoint`); TN.md §10 says a company may choose `per_line` "once the settings engine carries the choice" and it does not yet, so a customer who re-adds line VAT sees allocated shares, not rate x net, on some lines (annoying; many Tunisian accountants add line VAT). Also unbuilt but ruled: Tunisia's retail stamp brackets (LF 2026 art. 20) and 25 % VAT withholding by public buyers (TN.md §4, §6).

What was and was not certified by execution: nothing was executed; every finding is read from source and docs. Not read: web app, migrations beyond grep, templates beyond grep, `docs/research/tax-data-france.md`, SPEC §7 beyond the keyword hits quoted.

## twes-in organisation layer: limits found (read-only audit, 2026-10-04)

Viewpoint: a company with 2-3 sites and 5-15 staff. Method: Read/git grep only; no docker, no make. Grades: V = verified in code or SPEC text read this session, I = inferred, U = UNVERIFIED. Severity scale: security / blocks a first customer / annoying / later. Ranked most urgent first. 14 findings.

---

### 1. An admin (or any custom-role holder with `company.settings`) can mint any permission for themselves, billing included

- Scenario: the owner keeps billing to themselves (SPEC § 3 Authorization: "owner: billing"). An admin opens Roles, creates "Gestion" with `subscription.pay` and `user.write`, invites their own second address into it, and now pays or declares payments and edits the team. A custom "Chef de site" role that carries `company.settings` can also add permissions to itself.
- Evidence [V]: `ManageRoles::create/revise` (api/src/Tenancy/Application/Role/ManageRoles.php) only calls `assertKnown`; there is no "a role may not hold what its editor does not hold" check. `CreateRoleProcessor`/`ReviseRoleProcessor` ask only `company.settings`. `RoleBounds::assertMayGrant` ranks by built-in role NAME (owner 3, admin 2, member 1, anything else 0), so an admin may grant a custom role whatever it holds (api/src/Tenancy/Application/Company/RoleBounds.php). The admin's built-in set (`SeedPlatform::BUILT_IN_ROLES`) lacks `subscription.read/pay`, but the catalogue contains them. No SPEC § 7 ruling says this is accepted; the role matrix is row 104 (ruled, built).
- Severity: security.
- Fix: in `ManageRoles` refuse a permission the actor's own role does not grant unless the actor is an owner (`*`), on create and revise; keep `company.settings` itself owner-or-held-only.
- Question: who may put a permission into a custom role? (a) only permissions the editor holds, owner exempt (recommended); (b) owners only edit roles; (c) leave as is and document that admin equals owner.

### 2. A membership cannot be limited to a site, and nobody has a "my site"

- Scenario: the Sfax clerk may only sell at Sfax. Today she sees and can issue from Tunis, and the establishment is a required select on every document with no memory of her site, so a wrong pick numbers an invoice in the wrong series.
- Evidence [V]: `Membership` has user, company, role, lastUsedAt, openedAtSignIn only (api/src/Tenancy/Domain/Membership.php). `PermissionVoter` decides on role and company only (api/src/Tenancy/Infrastructure/Security/PermissionVoter.php:59-64). `invoiceForm` offers every establishment (web/src/app/invoices/invoice-forms.ts:229). No `workingEstablishment` in web/api. SPEC § 3 Authorization (amended 2026-09-20) rules the `establishments` + `all_establishments` scope "ruled but unbuilt" and states the accepted limit "waiter at A, manager at B" is not covered.
- Severity: blocks a first customer that has more than one site; otherwise annoying.
- Fix: build the ruled membership scope, add a session "working establishment" that preselects and filters document, stock and till lists, and enforce it in the voter or `CompanyGuard`, not in screens.
- Question: should the scope also be per role (role at site, closing the "waiter A, manager B" limit)? (a) membership to sites only, as ruled; (b) a list of (site, role) pairs now (recommended if sites differ in trust); (c) defer all of it until the first multi-site customer signs.

### 3. Adding a second establishment makes its first invoice fail

- Scenario: the shop opens a second shop. Site 2 copies the default site's numbering. Its first issued invoice gets `FAC-2026-00001`, which exists at site 1, and issuing answers 409.
- Evidence [V]: `ManageEstablishments::seriesToCopy` copies the default series' format verbatim, each new series starts at 1 (api/src/Tenancy/Application/Establishment/ManageEstablishments.php:170-185). The TN preset format has no `{EST}` (api/config/fiscal/TN.yaml:87-89 `FAC-{YYYY}-{SEQ:5}`). `InvoiceWorkflow::issue` throws `InvoiceNumberTaken` telling the person to add `{EST}` (api/src/Module/Invoices/Application/InvoiceWorkflow.php:80-81); delivery notes do the same. The failed allocation rolls back, so no gap, but the error appears at issue time on the first day. SPEC § 7 2026-09-13 rules unique numbers within a company as the allocation's rule.
- Severity: blocks a first customer that opens a second site.
- Fix: when a second establishment is created, either inject `{EST}` into the copied formats or refuse creation with a message pointing to Numbering; add a test that two sites can each issue once from preset formats.
- Question: how should sites number apart? (a) auto-add `{EST}` to copied formats (recommended); (b) one company-wide series shared by all sites (a different allocation design); (c) keep copying and add a warning banner on the Numbering page.

### 4. Role administration works by role name: custom roles cannot manage staff, and a member's role cannot be changed

- Scenario: the owner defines "Responsable magasin" with `user.write` to onboard staff; that person's invites and removals are refused. Promoting a clerk to cashier-manager means removing and re-inviting her.
- Evidence [V]: `RoleBounds::rank` returns 0 for any non-built-in role, and `assertMayGrant`/`assertMayRemove` need owner or admin rank (RoleBounds.php), so `user.write` on a custom role is inert for invite/remove. `MemberResource` exposes only Post (invite) and Delete (api/src/Tenancy/Infrastructure/ApiPlatform/MemberResource.php:35,43); no change-role use case exists (git grep changeRole: none). `InviteToCompany` refuses an existing member (`AlreadyAMember`). No ruling.
- Severity: annoying, becomes blocks when roles are the answer to finding 2.
- Fix: replace rank-by-name with "an actor may grant or remove a role whose permissions are a subset of their own" (also fixes finding 1) and add `PUT .../members/{userId}` to change the role with the last-owner guard.
- Question: how should a member's role change? (a) a role-change endpoint plus the subset rule (recommended); (b) keep remove-and-reinvite and document it; (c) allow only owners to change roles.

### 5. Nobody can read the audit log, and documents do not say who made them

- Scenario: a credit note disappears from a customer's account, or a price is changed. The owner asks "who did this?" and has no screen, no export and no field to answer with.
- Evidence [V]: `AuditLog` rows are written by `DoctrineAuditTrail`; `git grep audit_log` finds no reader other than a FirstSteps check and the writer (api/src). No audit resource or screen in web/src/app/{company,shell,platform}. `Invoice::issuedBy` is stored (api/src/Module/Invoices/Domain/Invoice.php:140) but `git grep issuedBy` finds no API or web exposure; no `createdBy` on documents, customers or products. Audit rows store the actor as a uuid only. SPEC row 58 only schedules purges of "audit rows past their retention".
- Severity: annoying for 5 people, security-relevant (accountability) once staff handle cash.
- Fix: a read-only `GET /companies/{id}/audit` behind a new `audit.read` permission, filtered by entity and actor, with the actor's current name; show "issued by" on the document sheet.
- Question: who may read a company's audit trail? (a) owners and admins only, via a new permission (recommended); (b) owners only; (c) not now, only operators on support access.

### 6. No right-to-erasure path: no account deletion, no person anonymisation, no company closure

- Scenario: a French customer (natural person) or a former employee asks to be erased; a shop closes and wants its data gone after the legal period. The operator has nothing to run except SQL.
- Evidence [V]: `CompanyRepository` has `save` and no remove (api/src/Tenancy/Domain/CompanyRepository.php); git grep for anonymi/erase finds only unrelated hits; no `User` removal use case (api/src/Identity/Application/Account has OwnAccount and ManageAccounts only, deactivate/reactivate by operator, SPEC § 7 2026-09-15). SPEC § 3 says "owner: company deletion" but nothing builds it; row 51 covers legal page texts, not a procedure. FKs are mostly `onDelete: CASCADE` from company (Membership, Establishment, NumberingSeries), so a raw company delete would also take issued documents, which the ten-year retention (row 58) forbids. Invoices freeze a `customerSnapshot` and a `SellerSnapshot` (Invoice.php:117-121), so the design to anonymise a live customer row while keeping the frozen invoice exists but is not used.
- Severity: blocks a first customer in France (RGPD), later for Tunisia.
- Fix: write the procedure before the code: anonymise customer and user rows (keep frozen snapshots for the retention period), company closure = suspend, export, then purge after retention; add a Decisions Log entry on what RGPD asks versus the ten-year invoice duty.
- Question: what happens to a person's data on erasure request? (a) anonymise the live row, keep the frozen invoice snapshot until retention ends (recommended); (b) refuse while any issued document exists, document it in the legal pages; (c) operator-run script only, no UI yet.

### 7. A locked (unpaid) company cannot download its own invoices

- Scenario: a café stops paying; the operator picks "locked". The owner needs a copy of this year's invoices for the accountant or the tax office and is refused everything but the payment page.
- Evidence [V]: `Access::Locked->permits()` allows only `subscription.read` and `subscription.pay` (api/src/Licensing/Domain/Access.php); `ExportController` judges the subject's own permission (`invoice.read`...), so exports are refused when locked (ExportController.php:48-49). SPEC § 3 Subscriptions defines read-only vs locked per company/platform; no ruling exempts exports. Read-only mode keeps `*.read`, so exports work there.
- Severity: security-adjacent (data held hostage, RGPD portability and the legal duty to produce invoices), annoying otherwise.
- Fix: add a permitted export-all (or at least invoice and credit note PDF/CSV) to `ALWAYS` for owners in locked mode, and make "locked" mean no new documents rather than no reads.
- Question: what may a locked company still do? (a) payment page plus export-all of its own data (recommended); (b) payment page only, as built; (c) drop "locked" and use read-only for every lapse.

### 8. No company-level backup, export-all or restore (row 58 and the export package are not built)

- Scenario: a clerk deletes the wrong 300 products on Friday; or a customer leaves for another tool. The platform has no way to give the company its data or to restore one tenant.
- Evidence [V]: SPEC row 58 (nightly backup 14 days, restore tested in CI, file retention) is `todo` (docs/SPEC.md:4233). The 2026-10-04 08:26 ruling schedules the export-all package after per-list import/export and row 56, and the "restore point" only before an import (SPEC § 7 2026-10-04 08:26). Existing exports are per subject only (api/src/Module/*/Infrastructure/Export). A whole-database dump cannot restore one tenant without affecting others [I].
- Severity: blocks a first customer going to production without any backup (row 58), later for the per-tenant parts.
- Fix: bring row 58's backup forward of the first real customer; design the export-all package so its JSON is also the per-company restore format.
- Question: what is the minimum before a real customer? (a) nightly PostgreSQL backup plus a tested restore, export-all later (recommended); (b) export-all first, backups later; (c) both before go-live.

### 9. Settings have no establishment level (hours, printers, receipt text, payment terms per site); sites cannot be closed

- Scenario: Sfax closes at 13:00 on Saturdays and prints on another receipt printer; Tunis has a different footer. Both can only be company-wide. A closed shop stays in every select forever.
- Evidence [V]: `SettingLevel` enum has platform, company, customer_group, customer, document, product_category, product, document_line, role, user (api/src/Settings/Domain/SettingLevel.php); no establishment. `SettingContext` carries no establishment id. `Establishment` has no active or closed flag and the API offers only Get, Post, Put (SPEC § 7 2026-09-13; Establishment.php). Time zone is `company.timezone` only (AllocateNumber.php:50). SPEC § 3 Settings says "a later level is registered, never rewritten", so it is designed to take one.
- Severity: annoying now, blocks when the till and printers (rows 82, 141) arrive.
- Fix: register an `establishment` level in the presentation and business chains and an `isActive` on `Establishment` (retired, never deleted, like expense categories); decide time zone stays per company.
- Question: which settings differ by site first? (a) receipt text, printer and opening hours (till preparation, recommended); (b) only payment terms and default location; (c) wait for a customer to ask.

### 10. Notifications go to every holder of a permission, at every site, in-app only

- Scenario: a low-stock or credit-limit alert at Tunis is shown to the Sfax stock keeper and to everyone else with the permission; nobody can mute a type or get a mail.
- Evidence [V]: `TellStockKeepers::tellAll` and `TellCreditWatchers::afterIssue` loop over `memberships->ofCompany()` and test only `Role::grants` (Module/Inventory/Application/TellStockKeepers.php:98-103; Module/Invoices/Application/TellCreditWatchers.php:49-53). `InboxNotifications` takes only `user:` or `company:` channels, written to the in-app inbox and realtime. SPEC § 2 "After the first working version" lists "per-type notification preferences (in-app, email, off)", so the preferences are ruled as later; routing by site is not mentioned.
- Severity: later; annoying beyond 5 users.
- Fix: when finding 2's scope exists, filter recipients by the notification's establishment; add the per-type preference row already ruled.
- Question: should a site-bound alert reach only that site's members? (a) yes once membership scope exists (recommended); (b) all holders always, owners decide by role; (c) per-user opt-in only.

### 11. No machine credentials: no API tokens, keys or webhooks

- Scenario: the accountant's software, a till, or a Zapier flow wants to pull invoices or push stock counts. Only a person's cookie session with CSRF exists.
- Evidence [V]: auth is session cookie plus CSRF only (SPEC § 3 Auth; api/config/packages/framework.yaml); no token entity under Identity; git grep for "api token", "api key", "webhook" in docs/SPEC.md finds no ruling and no mention. Imports and exports exist but are browser-driven.
- Severity: later.
- Fix: if wanted, per-company scoped tokens with a permission subset (reuse finding 4's subset rule), hashed at rest, shown once, audited, revoked on member removal.
- Question: is a public API for third parties in scope? (a) no, exports and imports are the integration (recommended for the POC); (b) read-only tokens first; (c) full scoped tokens with webhooks.

### 12. Owner succession: the "last owner" guard counts memberships, not usable people

- Scenario: the sole owner loses phone and recovery codes, or an operator deactivates their account for another reason. The company has an "owner" membership but nobody can act as owner, and the platform has no standing access.
- Evidence [V]: `RemoveMember::countOwners` counts membership rows with the owner role regardless of account state (RemoveMember.php); `LastOwner` protects only removal. Operator MFA reset was refused (SPEC § 7 2026-09-10) and operators keep no standing company access (§ 3 Tenancy); support access is owner-granted. `MembersStep` is done as soon as the company has more than one member of any role (MembersStep.php `COUNT(*) > 1`), so it never nudges toward a second owner. Transfer works only as invite-as-owner then remove (owner can grant owner, `RoleBounds`), not as a flow. No ruling on recovery by identity proof.
- Severity: annoying until it happens, then blocks the company.
- Fix: make the first-step check "at least two owners", warn on the members page when there is one, and write the operator-assisted recovery policy (identity check, time delay, notice to the other channels) before launch.
- Question: what is the rescue for a locked-out sole owner? (a) strongly nudge a second owner, no rescue path (recommended with current MFA ruling); (b) a delayed, audited operator recovery after identity proof; (c) owner names a recovery contact at setup.

### 13. Staff leaving: only hard removal, no suspension or leave date

- Scenario: a seasonal cashier leaves in September and returns in June, or a manager serves notice for 30 days.
- Evidence [V]: removal deletes the membership row (`RemoveMember`, `Membership` has no status/ended-at; `uniq_membership_user_company`), losing role and lastUsedAt; rejoining needs a new invitation with a role chosen again. Access ends at once and per company (SPEC § 7 2026-09-15). Their drafts and documents keep an unresolvable `issuedBy` uuid (see finding 5); audit still holds the uuid. User-level presentation settings remain under the user (SettingLevel::User). No ruling on suspension.
- Severity: annoying.
- Fix: an `ended_at` or `suspended` flag on membership honoured by `PermissionVoter` and `CompanyGuard` (same 404 as a stranger), reversible by owner/admin.
- Question: how should a leaver be handled? (a) add a reversible "suspend" beside "remove" (recommended); (b) keep remove only, rehire by invitation; (c) remove with an effective date.

### 14. The importers carry no establishment, so their own advice is impossible; they cannot carry the organisation

- Scenario: a two-site shop imports opening stock where "A-12" exists at both sites; the refusal says "import one establishment at a time" but there is no way to pick one. Members, roles, sites and numbering must all be typed by hand.
- Evidence [V]: `OpeningStockImport::locationOf` refuses an ambiguous code with that advice (api/src/Module/Inventory/Infrastructure/Import/OpeningStockImport.php:153), yet its columns are only reference, location_code and quantity, and `ImportController`/`RunImport`/`ImportRecord` have no establishment parameter. Importers exist for customers, products, vendors and opening stock only (git grep DeclaresImport); no import or export for establishments, numbering series, roles or members; export-all is planned (SPEC § 7 2026-10-04 08:26 ruling 2) and the Invoice Ninja importer is owner-only into an empty company (same ruling, item 4). Opening numbering for a company that already issued invoices elsewhere: `NumberingSeries` exposes a resumable next number (NumberingSeries.php docblock), so that part is possible by hand [I].
- Severity: annoying.
- Fix: add an optional `establishment_code` column (or an establishment picker on the import screen) to opening stock, and list "what the importer cannot carry" on the import guide.
- Question: how do imports pick a site? (a) an `establishment_code` column with the picker defaulting to the working site (recommended); (b) one import per site chosen on screen; (c) leave ambiguous codes refused and require unique codes across sites.

---

### Checked and found adequate (no finding)

- One person in several companies: unique membership per (user, company), per-company MFA switch, switcher, sign-in pin; removal never touches other companies (SPEC § 7 2026-09-15). [V]
- Staff session on removal: the voter reads the membership on every request, so access ends at once. [V, PermissionVoter.php]
- Customers are company-wide by design (no establishment on `Customer`); fine for 2-3 sites, only per-site credit limits or price lists would need a scope. [V]
- Language: `User.locale` and `presentation.language` per person, `document.language` per customer chain; documents are not tied to the viewer's language. [V by grep, behaviour UNVERIFIED]
- Subscription lapse: read-only and locked modes exist and are computed per request; the gap is only finding 7. [V]
- Data retention: purge of old audit and notifications is scheduled under row 58 (todo), no code yet. [V SPEC, U for any purge job]

## Ten days at the shop and the workshop: where a person gets stuck

Read-only audit, 2026-10-04, at HEAD 8f24e520. Method: SPEC §1-2 and §8, `web/src/app/app.routes.ts`, `shell/planned-nav.ts`,
`api/src/Module/*`, and Grep on the code. Nothing was run (no docker or make), so no behaviour below was exercised.
Grades: [Verified: file read] or [Inferred] or UNVERIFIED. "Planned" means a §8 row exists. "Ruled, no row" means §7 says it is wanted but §8 has no row.
Built today: customers, products, price lists, invoices with payments and credit notes (stock comes back), delivery notes (partial
invoicing, several notes into one invoice), vendors, expenses, stock (receive, count, move, write-off, valuation, map), CSV/xlsx import
for customers, products, vendors and opening stock, per-list export, customer statement tab, home summary with "to chase".

Ranked findings (1 = worst).

### 1. Post-dated cheque cannot be recorded (scenario 1)
- Step: the contractor leaves a cheque dated in 30 days against a 40-line invoice.
- Gap: `Invoice::recordPayment` refuses a payment dated after today (`api/src/Module/Invoices/Domain/Invoice.php` ~454-457). Method `check` exists
  (`api/src/Shared/Domain/PaymentMethod.php`) but is only a label. There is no instrument record with a due date, a bank and a state.
  The clerk must either record nothing (the invoice stays "overdue", and the chase list shows a customer who has paid) or lie about the date.
- Planned: ruled 2026-09-21 18:40 (SPEC §7, line ~1574) and listed at ~2522 as IN-B-14, "its own row". Verified: no row in §8 (grep for cheque/traite/instrument in the status block returns nothing). Ruled, no row.
- Severity: blocks for a Tunisian B2B shop. Traites are routine.
- Fix: add a §8 row now and build `instrument` (amount, due date, bank, state: portfolio, deposited, cleared, bounced). Clearing creates the Payment.
- Question: which cheque shape ships first? (a) the full portfolio and deposit slip, (b) a minimal "cheque expected on date X" note on the invoice that suppresses the chase, (c) leave it until the Trésorerie row 180.

### 2. A goods receipt is one product at a time, with no document, vendor or supplier invoice (scenario 2)
- Step: 12 products arrive and some are damaged. The supplier invoice differs from the delivery.
- Gap: `KeepStock::receive(company, product, location, quantity, ..., unitCost)` is a single-product call. Nothing in `api/src/Module/Inventory/` references a vendor.
  Nothing ties a receipt to an expense or a supplier bill. Damaged units are a separate `writeOff` with a reason (`StockLossReason`), so a receipt of 10 where 2 broke is receive 10 then write off 2, with no link to the vendor claim.
  A price difference is hand-fixed on `unitCost`.
- Planned: rows 81 (purchase order and goods receipt) and 84 (supplier bill and three-way match) are both todo.
- Severity: blocks the shop's weekly restock flow from being traceable. Stock still ends up correct.
- Fix: do row 81 before anything else in the "shop" path. Minimum slice: one receipt screen with N lines, a vendor, a delivery reference, and a "received good / damaged" quantity per line.
- Question: how should damaged goods be handled at receipt? (a) the line carries `damaged` and goes straight to a loss movement tied to the vendor, (b) damaged goes to a quarantine location and is decided later, (c) just a note on the receipt.

### 3. Opening a company from another tool cannot bring open invoices or customer balances (scenario 10)
- Step: day 1, the owner loads customers, products, opening stock, and the unpaid invoices people still owe.
- Gap: `imports/:subject` exists for customers, products, vendors and opening-stock only (`web/src/app/app.routes.ts` ~283, `DeclaresImport` implementers). There is no import for invoices, so debts owed on day 1 cannot be collected or chased inside the product.
  The customer statement tab has an opening-balance concept, but a customer has no field to enter one (no match in `CustomerImport` columns).
  Opening stock has no cost (listed under "Needs input" in §8, so valuation starts empty).
- Planned: row 64 (past invoices as a read-only archive) is todo, and the 2026-10-04 ruling (§7 line 4165) goes further: open invoices re-created as live issued invoices, an Invoice Ninja wizard, a restore point. No row yet for the wizard (UNVERIFIED which row will carry it).
- Severity: blocks a clean switch-over. The workaround is typing each open invoice by hand with its old number outside the series.
- Fix: before the wizard, give a customer an opening balance (one dated line in the statement) and add `unit_cost` to opening stock.
- Question: for day 1, what should open invoices be? (a) the ruled live imported invoices with old numbers, (b) one opening-balance line per customer, no documents, (c) both, balance first.

### 4. Stock count commits directly, with no review of differences and no "not found = 0" (scenario 8)
- Step: the physical count finds differences.
- Gap: `web/src/app/inventory/stock-count-page.ts` (header comment): "Recording writes one count per line, which sets what is on hand ... Nothing is recorded of what was not scanned."
  The page has no expected-versus-counted preview, no value of the difference, no approval, and no step that zeroes unscanned products. `KeepStock::count` sets an absolute quantity, so sales made between scan and record are overwritten.
  [Inferred from the page's header comment and the `count` signature; the HTML was not read in full.]
- Planned: row 97 raises an alert on a count difference (done). Review, approval and freeze are not in any §8 row.
- Severity: annoying for a small shop, dangerous for a hardware store with a few thousand references.
- Fix: a count session with a snapshot of on-hand at start, a variance screen before posting, and an explicit "mark unscanned locations as zero" step.
- Question: how strict should counting be? (a) a variance review screen before posting, (b) review plus manager approval above a value threshold, (c) keep it as is.

### 5. Workshop has no quote and no deposit; the order of work is a delivery note (scenario 4)
- Step: a quote request, a quote, two quantity changes, work in 3 batches, one invoice.
- Gap: `quotes` is only a planned nav entry (`web/src/app/shell/planned-nav.ts`: key `quotes` and key `works`, with the "En construction" page at `coming/:key`). There is no quote entity.
  What works today: three delivery notes, then `deliveryNoteIds` into one invoice (`web/src/app/delivery-notes/delivery-notes-api.ts` ~235) and partial invoicing (row 129 done).
  What is missing: the quote itself, versions of it, "what remains to deliver", deposits (row 79), hours on a job.
- Planned: rows 78 (quote), 79 (deposit) and the works module are todo. Not a design block.
- Severity: blocks the tourneur's first step. The workaround is a draft invoice kept open as an informal quote, which then uses the legal numbering at issue.
- Fix: ship row 78 with revision history and acceptance, with partial delivery from the quote.
- Question: what is the tourneur's minimum? (a) quote with revisions, acting as order, (b) a printable quote only, no order tracking, (c) a "job" record first, quotes after.

### 6. Cash refund after a return leaves no cash record (scenario 3)
- Step: the customer returns half of last month's order and is paid back in cash.
- Gap: a partial credit note works and returns the goods to the lot and location they left from (`MoveStockForDeliveryNotes::returned`, `StockMovement::saleReturn`). The refund is a `CustomerCreditEntry::refunded` ledger line (`CreditExcessTo::Refund`, `InvoiceWorkflow.php` ~144).
  There is no cash drawer, no payment-out record with a method, and nothing for the accountant to match to cash. The excess choice (`excessTo`) is only asked when the invoice was already paid.
  If the original invoice was unpaid, the credit note simply reduces what is due, which is correct, but a mixed case (part paid) has to be read carefully.
- Planned: register and shift (rows 82, 88) are todo, and Trésorerie (row 180) is todo, so a refund method is not recorded anywhere.
- Severity: annoying. Stock and the legal document are right, the cash is not traceable.
- Fix: record the method and date on a refund entry now, so the later drawer can read it.
- Question: refund method? (a) add method and date to the refund entry now, (b) wait for row 180, (c) treat a refund as a negative payment on the credit note.

### 7. A wrong invoice the next day takes three manual steps and a customer without a corrected copy (scenario 6)
- Step: the clerk issued a wrong invoice and notices it the next day.
- Gap: `Invoice::cancel` refuses anything but a draft ("an issued invoice is corrected by a credit note", `Invoice.php` ~466). So: create a credit note for the whole invoice, create a new invoice (Dupliquer exists on the page, `invoices.actions.duplicate` in `en.json`), re-enter payments, re-send.
  There is no "correct this invoice" action that does the credit note and a pre-filled replacement in one go. Nothing can be mailed (the `mailing` module is planned, so the PDF is downloaded and sent by hand).
  Whether a full credit note can be created with all lines pre-filled is UNVERIFIED (button `invoices.actions.credit_note` exists, its pre-fill was not read).
- Planned: row 133 (cancel or reverse) is todo, and sending is the `mailing` module (no row number found).
- Severity: annoying. The law is respected, the effort is high on a busy day.
- Fix: one "Correct" action: full credit note plus a draft copy with the payments listed, ready to edit.
- Question: should the correction be one guided action? (a) yes, credit note and replacement draft together, (b) keep two separate steps but add the prefill, (c) leave it.

### 8. Price rise on 200 products has no in-app bulk edit, no effective date and no price history (scenario 7)
- Step: raise 200 prices by 6 percent.
- Gap: no bulk edit or selection in the product list (git grep for bulk or selection in `web/src/app/shared/list` and `web/src/app/products` finds nothing).
  The only route is export, edit in a spreadsheet, re-import with create-and-update (`ProductImport` accepts `unit_price_net`), which works and is previewed.
  There is no scheduled effective date, no price history, and the price calculator (row 185) is per product. Price lists (`price-lists` route) override per customer, not "all products plus 6 percent".
  Whether the import refuses a price update that sits on a draft invoice is UNVERIFIED.
- Planned: not planned. SPEC §8 has no bulk price action. (Not impossible by design.)
- Severity: annoying, once or twice a year per shop.
- Fix: a "Change prices" action on the filtered list: percent or amount, rounding rule, preview with before and after, optional date. Reuse the dry-run engine of the imports.
- Question: how should a price rise be done? (a) a bulk action on the list with preview, (b) only through the import file (document it), (c) price lists carrying a percent over the base.

### 9. The owner is away: no time-boxed rights for a manager (scenario 9)
- Step: the manager needs extra rights for a week.
- Gap: `Membership` has no expiry (no match for expir or until in `api/src/Tenancy/Domain/Membership.php`); `Role` constants are owner, admin and member (`api/src/Tenancy/Domain/Role.php`).
  Custom roles have a management class (`ManageRoles`) and a route (`company/roles`), but row 53 is still todo. So the owner can only promote the manager and must remember to demote them.
  The "owner is away" case also needs the last owner to be reachable: the platform operator has no standing access by design (SPEC §2 invariants), and support access is owner-granted.
  [Whether a second owner can be named is Inferred from "the last owner never demoted"; not tested.]
- Planned: row 53 (custom roles) is todo. Time-boxed grants to staff are not planned; time-boxed support access exists only for the operator.
- Severity: annoying, and a safety risk when the demotion is forgotten.
- Fix: an optional "until" date on a membership role grant, and a daily reminder to the owner.
- Question: temporary rights? (a) an "until" date on the membership, (b) a role granted with an expiry, (c) not wanted, the owner demotes by hand.

### 10. Month end: no VAT summary, no accountant export or role, no closed period (scenario 5)
- Step: what is owed, what to chase, what the accountant needs.
- Gap: what exists is the home summary (`InvoiceSummary`: overdue, oldest overdue days, "toChase" capped at four rows), a status filter "overdue" on the invoice list, a per-customer statement tab, and CSV or xlsx export of every list.
  Missing: aged receivables for all customers (report engine row 89 is todo), the "dû à 30 jours" report (row 114), the VAT and FODEC summary, supplier withholding certificates (row 144 doing), the read-only accountant role, closed periods (row 92 todo), and the printed statement PDF ("Not yet: the printed PDF", SPEC §7 2026-10-02 00:59).
  The accountant gets list exports and has to build the VAT figures himself.
- Planned: rows 89, 91, 92, 114, 144 (all todo or doing).
- Severity: annoying until the first declaration, then blocking for an accountant who needs sums.
- Fix: ship the VAT summary report first (smallest, the accountant asks for it every month), then the accountant role.
- Question: what first at month end? (a) VAT and FODEC summary, (b) aged receivables across customers, (c) the accountant role and exports.

### 11. No reminders or late-payment follow-up; chase is a list the owner works by phone (scenarios 1, 5)
- Gap: the home shows at most four invoices to chase; nothing sends or schedules a reminder. Sending by email is not built (`mailing` is a planned nav entry). Late fees are ruled "off by default, no default amount" (row 135 todo).
- Severity: annoying. The contractor on account is exactly the customer this matters for.
- Planned: row 135, plus the `mailing` and `whatsapp` modules (rows 95 and others).
- Fix: before email, a printable "overdue letter" per customer from the statement.
- Question: which chase channel first? (a) printed statement or letter, (b) email, (c) WhatsApp link (row 95).

### 12. The credit limit warns but the account on credit has no per-customer running account (scenario 1)
- Gap: a customer's credit balance is a ledger (`CustomerCreditEntry`, `CustomerCreditBalanceResource`) and the statement is a read model (`CustomerStatement`, `overCreditLimit`). The "40 items on account" case works for the warning, but the stored running account (row 134) is todo, so overdue, credit limit and statement are three read models that must agree.
  Verified: the statement SQL sums opening balance and lines (SPEC §7 2026-10-02 00:59); a divergence risk exists only if the three drift.
- Severity: later.
- Fix: row 134 when the read models start to disagree.
- Question: is the current statement read model enough until a customer disputes a balance? (a) yes, defer 134, (b) no, do it before the first on-account customer, (c) UNVERIFIED, test with the contractor's data first.

### 13. Receiving against a supplier return: no vendor return or vendor credit (scenario 2)
- Gap: no `Vendors` or `Inventory` code for returning goods to a vendor or recording a vendor credit note (git grep of SPEC for supplier return and vendor credit finds nothing). Damaged goods that the vendor will replace or credit are only a write-off.
- Planned: not planned, and not forbidden by design. Row 84 (supplier bill and match) may absorb it, UNVERIFIED.
- Severity: later, but a hardware shop will hit it within weeks.
- Fix: decide whether row 84 includes a vendor credit note and a return movement.
- Question: vendor returns? (a) part of row 84, (b) a separate small row now, (c) manual write-off with a note.

### 14. A shop customer who buys across an establishment or a till: the counter sale is not built (scenario 1)
- Gap: `register` is a planned nav entry with `meanwhile: '/invoices/new'` (`planned-nav.ts`), so the counter flow today is the full invoice form. A busy shop selling 40 items by scan uses the document lines with the scanner (row 63 doing), which works but has no cash-with-change, no drawer, no receipt printer (row 141 todo).
- Planned: rows 82 and 88.
- Severity: later for these two customers if their sales are mostly account invoices; annoying for walk-in sales.
- Fix: none now; keep `meanwhile` honest on the planned page.
- Question: do the two first customers need a till at all in the first version? (a) no, invoices only, (b) yes, the quincaillerie counter, (c) UNVERIFIED, ask the shop.

### 15. Opening stock has no cost, so valuation reads zero from day one (scenario 10)
- Gap: opening stock is a count and has no cost (`OpeningStockImport`: columns reference, location_code, quantity). The valuation page (`stock/valuation`) has nothing to sum for it. SPEC §8 "Needs input" says so itself.
- Planned: open question, additive column.
- Severity: annoying, and cheaper to decide before the first import.
- Fix: add optional `unit_cost` now.
- Question: add `unit_cost` to opening stock now? (a) yes, optional column, (b) no, set cost on the first receipt, (c) take it from the product's cost price.

### 16. Phone view of long lists: invoices stay a scrolling table (scenario 1)
- Gap: Known issues in §8 (the invoice screens keep the shared list's table at phone width, scrolled sideways). A clerk working from a phone at a delivery, picking 40 lines, will feel it. Row 176 (mobile review) is todo.
- Severity: later.
- Fix: phone cards as a list upgrade for every list.
- Question: is the phone a working device for the first customers? (a) yes, move row 176 up, (b) no, desktop and scanner only, (c) UNVERIFIED.

### Not walked or not verifiable
- Whether `Dupliquer` on an issued invoice carries the payments or only the lines: UNVERIFIED.
- Whether a full-invoice credit note pre-fills all lines: UNVERIFIED.
- Rendered behaviour of any screen: nothing was run.
