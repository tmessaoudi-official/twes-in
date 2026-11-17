# W4 register: the parameters the delegated marks accepted

Built 2026-11-16, as ruled at the W4 gate (stable-release Decisions Log 2026-11-16 20:23). Source: the 100 picks marked
accept-parameter or changed in var/claude/assumed-stable/triage-2026-11-16.json, plus the shop setting the desk answer
q-start-step-rerun asked for. « Today » is the picks review's reading of the code (about 2026-11-01) with the first
line a search for its symbol found, a dated snapshot: each batch starts by reading that behaviour again and naming, or
writing, the test that holds it as the default. Every parameter keeps today's behaviour as its default. The 13:19
test of SPEC § 7 (does it change what a fiscal document prints or computes, or loosen a ruled refusal or a security
floor) was applied to every pick: one a ruling already answers gets no parameter, one only the developer can answer
goes to the decision desk, and the rest carry their bound here. That reading looked at each pick and its default;
each batch therefore starts by listing every parameter's other options and applying the same test to them, since an
option, not the default, is what can loosen a ruling (B14 against the erasure rulings, A75 against the 2026-10-28
00:13 ruling that lots keep their refusal).

Counts: 61 to build (new 57, an existing setting extended 3, partly built 1); already built 19; merged into another pick's key 5; planned on their own row 2 (C94 row 40, B79 row 132; S0.10i's « 7 already planned » was a key search that named only these two, and a search of the plan by id and by key finds no other); on the desk 6, and A104 both on the desk and in B4, so 7 desk cards (q-w4-*: A41, A2, A54, B13, B35, A104, A49); no reader yet 1; dropped 7 (A48, A73, B80 at triage; C16, C161, C114, A52 because a recorded ruling decides, and A93 keeps only its raise-only half). The estimate of 70 is replaced by 61.

## B1. Operator parameters (services.yaml, bounded at start) (8, 20 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| A101 | `app.notification.stop_keys (key, expiry)` (operator) | HmacMailStopTokens signs under kernel.secret, no expiry; `api/src/Inbox/Infrastructure/Mail/HmacMailStopTokens.php:24` | a secret: a dedicated key is one more secret to provision; default = today: the app secret, no expiry | M |
| A93 | `app.password.min_length (raise only)` (operator) | NewPasswordPolicy MIN_LENGTH 12, MAX_LENGTH 4096 constants; a change revokes every session; `api/src/Identity/Application/Password/NewPasswordPolicy.php:21` | security floor 12 kept, the parameter only raises it; keeping the current session on a password change is not built: it would loosen a security behaviour | S |
| C112 | `app.list_limits.<list>` (operator) | document.lines bounded; 25 written lists say « count: not bounded yet »; `api/config/services.yaml:116` | - | M |
| B4 | `app.support.windows_hours, app.support.default_window_hours` (operator) | the windows [1, 24, 72] and 24 are code; `api/src/Tenancy/Domain/Model/SupportGrant.php:31` | support stays time-boxed: the ruled values (2026-10-12 08:53) are the ceiling, a parameter only shortens | M |
| C100 | `app.health.public_build (full / version only)` (operator) | HealthController answers mode, deployment and « -dirty » to anyone; `api/src/Shared/Infrastructure/Health/HealthController.php:40` | default = today: full, as ruled 2026-10-07 14:31 | M |
| C149 | `approval.resuspend_updates_reason` (operator) | a second « Suspendre » changes nothing and keeps the first reason; `api/src/Tenancy/Application/Approval/DecideCompanyApproval.php:37` | - | M |
| A79 | `app.products.removed_photos_kept_for` (operator) | a removed photo is restorable with no time limit; its files count in storage; `api/src/Module/Products/Application/Photo/ProductPhotos.php:204` | default = today: kept without limit (the triage set it back); a shorter value lets the files go and stops counting them | M |
| B14 | `account.deletion_withdrawn_when_sole_owner` (platform) | the nightly run keeps an account now sole owner and retries; `api/src/Identity/Application/Account/FinalizeAccountDeletions.php:34` | the last owner is never removed | S |

## B2. Import options (8, 23 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| C162 | `imports.notation (date order, decimal mark)` (company + per-run import option) | ImportCells reads a comma or a point and one date order in code; `api/src/ImportExport/Application/ImportCells.php:20` | - | M |
| C108 | `excess_decimals (refuse / round with a gross_rounded note)` (products import option) | the products import refuses too_many_decimals except float noise; `api/src/ImportExport/Application/ImportCells.php:62` | import set-aside never loosens the domain: the rounded value still passes the price value object, the row carries its gross_rounded note, and the default stays refuse (too_many_decimals) | M |
| B61 | `import.undo_stock_conflict (keep and say / refuse the undo)` (company) | UndoImportedStock keeps a counted or below-zero place and says which; `api/src/Module/Inventory/Infrastructure/Import/UndoImportedStock.php:30` | - | M |
| A111 | `import.stock_cost_fallback` (company) | row cost, else the product's, else none, hard-coded; `api/src/Module/Inventory/Infrastructure/Import/OpeningStockImport.php:45` | money: a receipt with no cost is valued at the average | M |
| C49 | `import.product_vendors.mode (add only / replace)` (products-vendors import option) | a file adds links and codes, never removes; `api/src/Module/Products/Application/ManageProducts.php:336` | a replace removes links: through the import undo | L |
| B66 | `below_cost (note / refuse)` (products import option) | fixed in code: written and noted below_cost; `api/src/Module/Products/Infrastructure/Import/ProductImport.php:956` | - | S |
| A107 | `import.repeat_file (warn / refuse)` (company) | the same bytes are warned « déjà importé le … »; `api/src/ImportExport/Application/ImportReport.php:36` | - | S |
| B65 | `import.products.unpriced_inactive_default` (company) | the per-run switch starts off; `api/src/Module/Products/Infrastructure/Import/ProductImport.php:104` | - | S |

## B3. A person's presentation choices (7, 21 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| C26 | `report column net_before_discount` (person (report column)) | sales reports sum each document's net; no net before discount column; `api/src/Module/Invoices/Infrastructure/Reports/SalesByCustomerReport.php:35` | - | M |
| B77 | `presentation.margin-basis (margin / markup)` (person) | Margin::percentOf is the share of the price; the calculator offers both bases; `api/src/Module/Products/Domain/ValueObject/Margin.php:37` | cost figures still only to product.cost.read | M |
| B22 | `presentation.expense-bill-on-line-change (keep nets / keep totals)` (person) | refill() recomputes each TTC from its kept net; entry mode TTC new / HT saved, hard-coded; `web/src/app/expenses/expense-bill.ts:155` | - | M |
| C96 | `presentation.takings-refunds (own column / netted)` (person) | takings shows « Remboursé » as its own column; `api/src/Module/Invoices/Infrastructure/Reports/TakingsReport.php:34` | never silent: a refund stays visible | M |
| C39 | `presentation.receive.serial_entry` (person) | the receive dialog takes serials as a text column; `web/src/app/purchases/receipt-forms.ts:19` | - | M |
| C150 | `presentation.watch.below_zero_grouping` (person) | StockWatch::belowZero groups by product, place and lot; `api/src/Module/Inventory/Infrastructure/Watch/StockWatch.php:128` | - | M |
| B49 | `reports.merged_products (under the kept / on their own)` (person (report option)) | issued-line reports count a merged-away product under the kept one; `api/src/Module/Invoices/Infrastructure/Reports/SalesByProductReport.php:38` | - | M |

## B4. Company settings: records, people, access (15, 53 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| B33 | `document.names_as_issued` (company) | existing setting; extension: purchase orders read it too (they print live names today); `api/src/Module/Invoices/Application/Print/PrintInvoice.php:212` | - | S |
| B46 | `vendors.merged_names_on_screens` (company) | screens read standingFor()/mergedInto; journals read the recorded vendor; `api/src/Module/Vendors/Domain/Model/Vendor.php:106` | books keep the recorded vendor whatever the setting | M |
| C122 | `documents.inactive_party_at_issue` (company) | a draft keeps a deactivated customer; only a merged-away one is refused at issue; `api/src/Module/Customers/Domain/Model/Customer.php:402` | - | M |
| C165 | `document.bulk_invoicing_mixed_customers` (company) | the first selected note fixes customer and establishment; `api/src/Module/DeliveryNotes/Application/InvoiceDeliveryNotes.php:186` | - | M |
| B56 | `records.undoable_for over app.records.undoable_for (with B43, B59)` (company (over the operator value)) | app.records.undoable_for built and bounded PT1H-P30D in S0.10f (6effe010a); the company override is the new part; `api/config/services.yaml:210` | ruling: every delete undoable 24 h; the company value bounded by the operator's | M |
| B2 | `support_grant.operator_id (optional)` (per grant) | any operator with a second factor reads through a live grant; `api/src/Tenancy/Application/Support/SupportAccess.php:48` | support stays owner-granted, read-only; naming an operator only narrows it | L |
| C30 | `export.headers (keys / words)` (company) | report exports are headed by column keys; `api/src/Module/Reports/Infrastructure/Http/ReportExportController.php:53` | - | M |
| A104 | `products.photos.strip_metadata, app.products.photo_cache_max_age` (company + operator) | the original is kept as uploaded and served to product.read; cache max-age 31536000 fixed; `api/src/Module/Products/Infrastructure/Http/ProductPhotosController.php:129` | default = today: the original kept with its EXIF (the triage said true; W4 keeps today); a privacy default to raise on the desk (also on the desk: q-w4-photo-metadata; built in B4 with the default the desk settles) | M |
| B29 | `purchase_order.print_for_non_cost_readers (refused / without prices)` (company) | PurchaseOrderPdfController answers 404 without product.cost.read; `api/src/Module/Purchases/Infrastructure/Http/PurchaseOrderPdfController.php:33` | product.cost.read is the only way to a cost: a purchase order printed for a reader without it carries no price | M |
| A90 | `members.admin_grants_admin` (company) | RoleBounds: an admin grants built-in roles up to admin; `api/src/Tenancy/Application/Member/RoleBounds.php:20` | nobody cuts their own access; the last owner kept | M |
| A99 | `audit.address.read` (permission) | ActivityCollectionProvider shows ip only to user.write; `api/src/Audit/Infrastructure/ApiPlatform/State/Provider/ActivityCollectionProvider.php:31` | granted by migration where user.write is held, as C101 did, so nobody loses a view | M |
| C68 | `customer.merge_main_contact` (company) | the kept customer's main contact stays main; `api/src/Module/Customers/Infrastructure/Records/CustomerMerges.php:50` | undo puts it back either way | M |
| C118 | `records.configuration_links (block / clear)` (company) | RecordUse::Cleared declared, unhandled (LogicException); `api/src/Records/Application/Merge/MergeRecords.php:111` | the records engine answers a cleared reference with a 500 today: that path is built first, undo included | L |
| C95 | `documents.drafts_offer_cancel (always / when delete is refused)` (company) | every draft offers both Annuler and Supprimer; `web/src/app/invoices/invoice-actions.ts:108` | default = today: both offered (the triage set it back) | M |
| C88 | `product.check_reasons (with C89)` (company) | nine automatic reasons, all on, in ProductCheckRules; `api/src/Module/Products/Infrastructure/Doctrine/ProductCheckRules.php:22` | - | M |

## B5. Company settings: prices, money, stock (15, 53 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| A59 | `accounting_export.account_codes` (company) | four plain-column files, no account numbers; `api/src/Module/AccountingExport/Infrastructure/Export/SalesJournalExport.php:24` | no law: the accountant's mapping | L |
| C81 | `article.price_follow_keeps (margin rate / margin amount)` (company) | PriceReview::suggestedPrice keeps the margin rate only; `api/src/Module/Products/Domain/ValueObject/PriceReview.php:31` | money exact, rounded once | M |
| A35 | `document.line_discount_amount_entry (per line / per unit)` (company) | the calculator takes discountAmount as the whole line's; `api/src/Fiscal/Domain/Calculation/DocumentCalculator.php:43` | how a person types a discount, not a fiscal rule: the stored discount stays the whole line's, at most its total | L |
| C120 | `article.after_price_restore (close review / reopen)` (company) | restoreFollowedPrice clears priceReviewCost: the review stays closed; `api/src/Module/Products/Domain/Model/Product.php:533` | - | M |
| A39 | `price_list.same_kind_tie (lowest / highest)` (company) | ResolveUnitPrice: between two lists of one tier the lower net wins; `api/src/Module/PriceLists/Application/ResolveUnitPrice.php:26` | - | M |
| A11 | `article.reference_reserved_on_open` (company) | ProductReferences::preview shows the next without taking it; `api/src/Module/Products/Application/ProductReferences.php:29` | - | M |
| A68 | `invoice.credit_note_cost (at the credit note's issue / as credited)` (company) | each line freezes unit_cost at its own issue; `api/src/Module/Invoices/Domain/Model/InvoiceLine.php:163` | margin only: the line cost feeds the summary's and the sales reports' margin, never stock valuation (a return moves back at the sale movement's cost, StockMovement.php:394); default today, the cost at the credit note's own issue (InvoiceLine.php:163) | M |
| A71 | `credit_note.returned_by_default` (company) | the returned flag starts false in the form; `web/src/app/invoices/invoice-forms.ts:710` | - | S |
| A63 | `stock.line_in_other_unit (say / refuse validation)` (company) | a line in another unit moves nothing and is said; `api/src/Module/Inventory/Application/MoveStockForDeliveryNotes.php:53` | - | M |
| A75 | `stock.named_lot_unavailable (say / refuse validation)` (company) | the line moves nothing and the reason is said; `api/src/Module/Inventory/Application/MoveStockForDeliveryNotes.php:399` | another lot is never taken in its place unless chosen | M |
| A77 | `stock.default_reorder_point` (company) | a product with no product_reorder_point row raises no alert; `api/src/Module/Inventory/Application/KeepReorderPoints.php:42` | - | M |
| A65 | `stock.exit_order, stock.shortage_place` (company) | MoveStockForDeliveryNotes: homes in order, then the default place; a shortage on the main place; `api/src/Module/Inventory/Application/MoveStockForDeliveryNotes.php:53` | stock never stored, only summed | M |
| A74 | `stock.quarantine_on_hand` (company) | on-hand, alerts and « À surveiller » leave quarantine places out; `api/src/Module/Inventory/Application/KeepStock.php:443` | - | M |
| B24 | `scan.shared_location_code` (company) | a shared code resolves in the establishment in hand, else « scannez l'étiquette »; `web/src/app/inventory/count-sheet.ts:112` | - | M |
| C33 | `purchase.over_receipt_tolerance_percent` (company) | a receipt above the outstanding quantity is refused (above_expected); `api/src/Module/Purchases/Domain/Model/PurchaseOrder.php:279` | - | M |

## B6. Fiscal presets and identifier data (each other option sourced in docs/fiscal) (4, 8 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| A50 | `e_invoicing.formats (with C124)` (preset) | PRESETS = [FR] in DescribeFacturX and FACTUR_X_COUNTRIES = [FR] in the web: one list twice in code; `api/src/Module/Invoices/Application/FacturX/DescribeFacturX.php:44` | a preset value, never a company switch: which e-invoice formats a country issues is the law's | S |
| A44 | `parties.required_fields` (preset) | PartyIdentity::assertNamed holds the lists in code; `api/src/Module/Invoices/Application/Print/PartyIdentity.php:32` | the preset may only add required fields; never fewer than the law asks (a test holds the law's list as the floor) | M |
| B16 | `retention_counted_from (preset), company.retention_extra_years (platform)` (preset + platform) | retainUntil = 31 Dec of closing year + preset years; start is code; `api/config/fiscal/FR.yaml:10` | retention never shorter than the law: the start and the extra years can only lengthen it (a test holds the preset years as the floor) | M |
| C105 | `identifiers[siret].check_alternatives` (identifier data) | La Poste SIREN 356000000 a code constant; `api/src/Fiscal/Domain/ValueObject/IdentifierCheck.php:25` | sourced: INSEE notices, FR.md § 5 | S |

## B7. Installation values on a shop's machine (last, rehearsed) (4, 12 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| C5 | `BACKUP_KEEP_DAYS; TWES_ZONE written into the values` (installation) | pgbackrest repo1 retention 14 days fixed; TWES_ZONE read, not written; `infra/postgres/pgbackrest.conf:11` | backups kept at least the ruled 14 days (Object Lock, 2026-10-13 00:04): the value only lengthens it | M |
| A85 | `PHP_MEMORY_LIMIT` (installation) | conf.d/10-app.ini memory_limit = 256M for every mode; `infra/api/conf.d/10-app.ini:22` | - | M |
| C14 | `TICK_INTERVAL_MINUTES` (installation) | OnCalendar=*:0/15 written by twes schedule; `scripts/onprem/twes:541` | - | M |
| Q-SSR | `START_STEP_RERUN (next restart / night window / after ten minutes)` (installation) | q-start-step-rerun, answered (b) under the delegation: today's behaviour kept, the other moments a shop setting; `scripts/onprem/twes` | a downtime choice: default = today, the next restart of the computer | M |

## Not built in W4

| Pick | Status | Why |
|---|---|---|
| A47 | no-reader | no declaration or calendar is computed (row 91); a period with no reader builds nothing until the declaration exists |
| A48 | dropped | dropped at triage: its note builds nothing (the code is already a person's choice) |
| A49 | desk | on the decision desk: a TEJ file's resident, CNPC and P_Charge fields: each value sourced in docs/fiscal/TN.md and confirmed by the accountant before it is built (q-w4-tej-fields) |
| C124 | merged | same key as A50 |
| A41 | desk | on the decision desk: invariant: numbering gapless and continuous; a separate deposit series needs a fiscal source (TN, FR) |
| B19 | exists | already built: both presets state given_back_once; no other option is built (a further option goes to the desk) |
| C94 | planned | row 40, blocked until a purchase document names FODEC or a stamp |
| B79 | planned | row 132 todo: warn, never refuse |
| A52 | dropped | no parameter, a ruling decides: « guide, never choose a fiscal answer »: the app may not pick a free line's operation category; the issue keeps asking |
| C161 | dropped | no parameter, a ruling decides: ruling 2026-10-09 10:40 (3) names the import alone as the way in without the identifier; the form keeps refusing |
| C90 | merged | same axis as C161: when the identifier is required (create or issue); one key |
| A54 | desk | on the decision desk: fiscal: gating a legal mention by size needs a source in FR.md |
| B32 | exists | already built: the product form ticks every tax marked isDefault, which the company edits |
| B43 | merged | same key as B56 |
| B59 | merged | same key as B56 |
| B69 | exists | already built: built in parameter batch 3 (2026-11-02) |
| A2 | desk | on the decision desk: invariant: gapless continuous numbering; a company-wide series and when it may change need a fiscal source |
| A9 | exists | already built: both presets declare DEV-{YYYY}-{MM}-{SEQ:5}; the company edits its series |
| A73 | dropped | dropped at triage: its note builds nothing |
| B80 | dropped | dropped at triage: no order document is built |
| C140 | exists | already built: built in parameter batch 2 (2026-11-02) |
| C132 | exists | already built: built in parameter batch 2 (2026-11-02) |
| A95 | exists | already built: built in parameter batch 1 (2026-11-02) |
| B5 | exists | already built: built in parameter batch 1 (2026-11-02) |
| B11 | exists | already built: existing operator parameter |
| C16 | dropped | no parameter for now: the refusal stays as built, and whether the books' files (with report files) may leave a demo waits on the desk's h-ie-demo; reading the Factur-X demo ruling as covering them was itself a pick |
| A87 | exists | already built: built in parameter batch 1 |
| A94 | exists | already built: built in parameter batch 1 |
| A98 | exists | already built: built in parameter batch 1 |
| A100 | exists | already built: company setting, 12 to 36 |
| B6 | exists | already built: built in parameter batch 1 |
| B7 | exists | already built: built in parameter batch 3 |
| B13 | desk | on the decision desk: ruling: nobody cuts their own access; the journal and legal texts name operators; allowing it could leave no operator |
| B62 | exists | already built: built in parameter batch 2 |
| C89 | merged | same key as C88 |
| C160 | exists | already built: built in parameter batch 2 |
| C25 | exists | already built: built in parameter batch 2 |
| C114 | dropped | no parameter, a ruling decides: AGREED #115 (2026-10-20 22:38) refuses a matricule whose parts disagree; « keep and mark » would loosen it |
| B10 | exists | already built: built in parameter batch 3 |
| B35 | desk | on the decision desk: r18 (the Installation card) was moved after the release by an earlier ruling; W4 (later) says before: which wins |

Total: 190 points by the 1/3/8 sizes. Cost baseline: the three parameter batches of 2026-11-02 built 15 values in
about five hours; W4 is mostly behaviours, measured again after B1.
