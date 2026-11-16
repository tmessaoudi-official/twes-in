# W4 register: the parameters the delegated marks accepted

Built by reading (2026-11-16), as ruled at the W4 gate (stable-release Decisions Log 2026-11-16 20:23). Source: the 100
picks marked accept-parameter or changed in var/claude/assumed-stable/triage-2026-11-16.json, each read in the code.
Every parameter keeps today's behaviour as its default; « Today » names the line that holds it (a dated snapshot:
line numbers are the tree's of 2026-11-16). A pick that touches an invariant or a ruling is on the decision desk, not
in a batch.

Counts: 65 to build (new 62, an existing setting extended 2, partly built 1); already built 19; merged into another pick's key 5; planned on their own row 2; on the desk 5; no reader yet 1; dropped at triage 3. The estimate of 70 is replaced by 65.

## B1. Operator parameters (services.yaml, bounded at start) (9, 23 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| A101 | `app.notification.stop_keys (key, expiry)` (operator) | HmacMailStopTokens signs under kernel.secret, no expiry; `api/src/Inbox/Infrastructure/Mail/HmacMailStopTokens.php:24` | a secret: a dedicated key is one more secret to provision; default = today: the app secret, no expiry | M |
| C16 | `demo.books_exports` (operator) | DemoAccountGuard refuses the books exports, Factur-X and TEJ for a demo account; `api/src/Demo/Infrastructure/Http/DemoAccountGuard.php:35` | the demo ruling: nothing machine-readable leaves a demo; default refuse | S |
| A93 | `app.password.min_length, app.password.change_keeps_current_session` (operator) | NewPasswordPolicy MIN_LENGTH 12, MAX_LENGTH 4096 constants; a change revokes every session; `api/src/Identity/Application/Password/NewPasswordPolicy.php:21` | security: the floor stays 12 (only raised); default = today: every session ends | M |
| C112 | `app.list_limits.<list>` (operator) | document.lines bounded; 25 written lists say « count: not bounded yet »; `api/config/services.yaml:116` | - | M |
| B4 | `app.support.windows_hours, app.support.default_window_hours` (operator) | the windows [1, 24, 72] and 24 are code; `api/src/Tenancy/Application/Support/ManageSupportAccess.php:73` | values ruled 2026-10-12 08:53 kept as defaults | M |
| C100 | `app.health.public_build (full / version only)` (operator) | HealthController answers mode, deployment and « -dirty » to anyone; `api/src/Shared/Infrastructure/Health/HealthController.php:40` | default = today: full, as ruled 2026-10-07 14:31 | M |
| C149 | `platform.approval.resuspend_updates_reason` (operator) | a second « Suspendre » changes nothing and keeps the first reason; `api/src/Tenancy/Application/Approval/DecideCompanyApproval.php:37` | - | M |
| A79 | `app.files.removed_kept_for` (operator) | a removed photo is restorable with no time limit; its files count in storage; `api/src/Module/Products/Domain/Model/ProductPhoto.php:34` | default = today: kept without limit (the triage set it back); a shorter value lets the files go and stops counting them | M |
| B14 | `account.deletion_when_sole_owner` (platform) | the nightly run keeps an account now sole owner and retries; `api/src/Identity/Application/Account/FinalizeAccountDeletions.php:34` | the last owner is never removed | S |

## B2. Import options (8, 23 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| C162 | `imports.notation (date order, decimal mark)` (company + per-run import option) | ImportCells reads a comma or a point and one date order in code; `api/src/ImportExport/Application/ImportCells.php:20` | - | M |
| C108 | `excess_decimals (refuse / round with a gross_rounded note)` (products import option) | the products import refuses too_many_decimals except float noise; `api/src/ImportExport/Application/ImportCells.php:62` | default refuse = today (triage set it back); round to the currency scale once | M |
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
| C96 | `presentation.takings-refunds (own column / netted)` (person) | takings shows « Remboursé » as its own column; `api/src/Module/Invoices/Domain/ValueObject/CreditEntryKind.php:22` | never silent: a refund stays visible | M |
| C39 | `presentation.receive.serial_entry` (person) | the receive dialog takes serials as a text column; `web/src/app/purchases/receipt-forms.ts:19` | - | M |
| C150 | `presentation.watch.below_zero_grouping` (person) | StockWatch::belowZero groups by product, place and lot; `api/src/Module/Inventory/Infrastructure/Watch/StockWatch.php:128` | - | M |
| B49 | `reports.merged_products (under the kept / on their own)` (person (report option)) | issued-line reports count a merged-away product under the kept one; `api/src/Module/Invoices/Infrastructure/Reports/SalesByProductReport.php:38` | - | M |

## B4. Company settings: records, people, access (16, 54 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| B33 | `document.names_as_issued` (company) | existing setting; extension: purchase orders read it too (they print live names today); `api/src/Module/Invoices/Application/Print/PrintInvoice.php:212` | - | S |
| B46 | `vendors.merged_names_on_screens` (company) | screens read standingFor()/mergedInto; journals read the recorded vendor; `api/src/Module/Customers/Domain/Model/Customer.php:422` | books keep the recorded vendor whatever the setting | M |
| C122 | `documents.inactive_party_at_issue` (company) | a draft keeps a deactivated customer; only a merged-away one is refused at issue; `api/src/Module/Customers/Domain/Model/Customer.php:402` | - | M |
| C161 | `customer.identifier_may_follow (one key with C90)` (company) | only the import may leave the identifier to follow; the form refuses a new customer without it; `api/src/Module/Customers/Application/CustomerInput.php:22` | the law stays at issue (PartyIdentity) | S |
| C165 | `document.bulk_invoicing_mixed_customers` (company) | the first selected note fixes customer and establishment; `api/src/Module/DeliveryNotes/Application/InvoiceDeliveryNotes.php:186` | - | M |
| B56 | `records.undoable_for over app.records.undoable_for (with B43, B59)` (company (over the operator value)) | app.records.undoable_for exists (operator, PT24H); no company override; `api/config/services.yaml:210` | ruling: every delete undoable 24 h; the company value bounded by the operator's | M |
| B2 | `support_grant.operator_id (optional)` (per grant) | any operator with a second factor reads through a live grant; `api/src/Tenancy/Application/Support/SupportAccess.php:48` | support stays owner-granted, read-only; naming an operator only narrows it | L |
| C30 | `export.headers (keys / words)` (company) | report exports are headed by column keys; `api/src/Module/Reports/Infrastructure/Http/ReportExportController.php:53` | - | M |
| A104 | `products.photos.strip_metadata, app.products.photo_cache_max_age` (company + operator) | the original is kept as uploaded and served to product.read; cache max-age 31536000 fixed; `api/src/Module/Products/Infrastructure/Http/ProductPhotosController.php:129` | default = today: the original kept with its EXIF (the triage said true; W4 keeps today); a privacy default to raise on the desk | M |
| B29 | `purchase_order.print_for_non_cost_readers (refused / without prices)` (company) | PurchaseOrderPdfController answers 404 without product.cost.read; `api/src/Module/Purchases/Infrastructure/Http/PurchaseOrderPdfController.php:33` | product.cost.read stays the only way to a cost: the other option prints no price | M |
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
| A35 | `document.line_discount_amount_entry (per line / per unit)` (company) | the calculator takes discountAmount as the whole line's; `api/src/Fiscal/Domain/Calculation/DocumentCalculator.php:43` | money arithmetic: pricing vectors and the CHECK rate XOR amount stay | L |
| C120 | `article.after_price_restore (close review / reopen)` (company) | restoreFollowedPrice clears priceReviewCost: the review stays closed; `api/src/Module/Products/Domain/Model/Product.php:533` | - | M |
| A39 | `price_list.same_kind_tie (lowest / highest)` (company) | ResolveUnitPrice: between two lists of one tier the lower net wins; `api/src/Module/PriceLists/Application/ResolveUnitPrice.php:26` | - | M |
| A11 | `article.reference_reserved_on_open` (company) | ProductReferences::preview shows the next without taking it; `api/src/Module/Products/Application/ProductReferences.php:29` | - | M |
| A68 | `invoice.credit_note_cost (at the credit note's issue / as credited)` (company) | each line freezes unit_cost at its own issue; `api/src/Module/Invoices/Domain/Model/InvoiceLine.php:163` | default = today: the cost at the credit note's issue; stock valuation is before the accountant (Q25) | M |
| A71 | `credit_note.returned_by_default` (company) | the returned flag starts false in the form; `web/src/app/invoices/invoice-forms.ts:710` | - | S |
| A63 | `stock.line_in_other_unit (say / refuse validation)` (company) | a line in another unit moves nothing and is said; `api/src/Module/Inventory/Application/MoveStockForDeliveryNotes.php:53` | - | M |
| A75 | `stock.named_lot_unavailable (say / refuse validation)` (company) | the line moves nothing and the reason is said; `api/src/Module/Inventory/Application/MoveStockForDeliveryNotes.php:399` | another lot is never taken in its place unless chosen | M |
| A77 | `stock.default_reorder_point` (company) | a product with no product_reorder_point row raises no alert; `api/src/Module/Inventory/Application/KeepReorderPoints.php:42` | - | M |
| A65 | `stock.exit_order, stock.shortage_place` (company) | MoveStockForDeliveryNotes: homes in order, then the default place; a shortage on the main place; `api/src/Module/Inventory/Application/MoveStockForDeliveryNotes.php:53` | stock never stored, only summed | M |
| A74 | `stock.quarantine_on_hand` (company) | on-hand, alerts and « À surveiller » leave quarantine places out; `api/src/Module/Inventory/Application/KeepStock.php:443` | - | M |
| B24 | `scan.shared_location_code` (company) | a shared code resolves in the establishment in hand, else « scannez l'étiquette »; `web/src/app/inventory/count-sheet.ts:112` | - | M |
| C33 | `purchase.over_receipt_tolerance_percent` (company) | a receipt above the outstanding quantity is refused (above_expected); `api/src/Module/Purchases/Domain/Model/PurchaseOrder.php:279` | - | M |

## B6. Fiscal presets and identifier data (each other option sourced in docs/fiscal) (7, 22 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| A49 | `declarations.withholding_file; vendor.resident, cnpc, p_charge` (preset + vendor field) | DeclareTejWithholdings: TN and TND as constants; Resident=1, CNPC=0, P_Charge=0 always; `api/src/Module/Expenses/Infrastructure/Tej/TejDeclarationXml.php:72` | fiscal: a non-resident TEJ line needs a source (RS codes to non-residents); refusal outside TN is the preset's | L |
| A50 | `e_invoicing.formats (with C124)` (preset) | PRESETS = [FR] in DescribeFacturX and FACTUR_X_COUNTRIES = [FR] in the web: one list twice in code; `api/src/Module/Invoices/Application/FacturX/DescribeFacturX.php:44` | data only, no new fiscal behaviour | S |
| A44 | `parties.required_fields` (preset) | PartyIdentity::assertNamed holds the lists in code; `api/src/Module/Invoices/Application/Print/PartyIdentity.php:32` | law varies by country: preset is its place; default today's list | M |
| A52 | `invoice.free_line_operations` (company) | a free line with no category refuses the issue; `api/config/fiscal/FR.yaml:139` | FR operation category: the company sets the default, the app never picks one | M |
| B16 | `retention_counted_from (preset), company.retention_extra_years (platform)` (preset + platform) | retainUntil = 31 Dec of closing year + preset years; start is code; `api/config/fiscal/FR.yaml:10` | never shorter than the law: extra years only lengthen; another start needs a source (none names it) | M |
| C105 | `identifiers[siret].check_alternatives` (identifier data) | La Poste SIREN 356000000 a code constant; `api/src/Fiscal/Domain/ValueObject/IdentifierCheck.php:25` | sourced: INSEE notices, FR.md § 5 | S |
| C114 | `identifiers[].on_part_mismatch (refuse / keep and mark)` (fiscal preset) | the matricule is read part by part from the TN preset and its letter sets refused; `api/config/fiscal/TN.yaml:37` | AGREED 2026-10-20 22:38 (#115): refuse is the default; keep and mark follows the phones canon; an invoice still refuses at issue | M |

## B7. Installation values on a shop's machine (last, rehearsed) (3, 9 points)

| Pick | Key (level) | Today | Invariant or source | Size |
|---|---|---|---|---|
| C5 | `BACKUP_KEEP_DAYS; TWES_ZONE written into the values` (installation) | pgbackrest repo1 retention 14 days fixed; TWES_ZONE read, not written; `infra/postgres/pgbackrest.conf:11` | the off-site Object Lock stays 14 days whatever the local value | M |
| A85 | `PHP_MEMORY_LIMIT` (installation) | conf.d/10-app.ini memory_limit = 256M for every mode; `infra/api/conf.d/10-app.ini:22` | - | M |
| C14 | `TICK_INTERVAL_MINUTES` (installation) | OnCalendar=*:0/15 written by twes schedule; `scripts/onprem/twes:541` | - | M |

## Not built in W4

| Pick | Status | Why |
|---|---|---|
| A47 | no-reader | no declaration or calendar is computed (row 91); a period with no reader builds nothing until the declaration exists |
| A48 | dropped | dropped at triage: its note builds nothing (the code is already a person's choice) |
| C124 | merged | same key as A50 |
| A41 | desk | on the decision desk: invariant: numbering gapless and continuous; a separate deposit series needs a fiscal source (TN, FR) |
| B19 | exists | already built: both presets state given_back_once; no other option is built (a further option goes to the desk) |
| C94 | planned | row 40, blocked until a purchase document names FODEC or a stamp |
| B79 | planned | row 132 todo: warn, never refuse |
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
| B10 | exists | already built: built in parameter batch 3 |
| B35 | desk | on the decision desk: r18 (the Installation card) was moved after the release by an earlier ruling; W4 (later) says before: which wins |

Total: 205 points by the 1/3/8 sizes. Cost baseline: the three parameter batches of 2026-11-02 built 15 values in about five hours; W4 is mostly behaviours, measured again after B1.
