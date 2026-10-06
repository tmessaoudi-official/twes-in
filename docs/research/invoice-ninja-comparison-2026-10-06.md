# IN — Invoice Ninja compared with twes-in, on behaviour and public documentation (SPEC row 213)

> Moved here from the 2026-10-06 audit's working files (SPEC row 213). Its twes-in facts are those of HEAD 67dddd64;
> rows built later the same day move some cells: partial invoicing of delivery notes (row 129) and the price calculator
> (row 185) are done, a product kept at several places (row 188) and the locked customer screen (rows 205, 207) exist.
> Read `docs/SPEC.md` § 8 for the current state; the Invoice Ninja side is as its public pages read on 2026-10-06.

Audit 2026-10-06, lens IN. Read-only. twes-in base: HEAD 67dddd64 (master, clean), `docs/SPEC.md` § 1–3 and § 8.
**Licensing boundary held**: no Invoice Ninja source code was read, cloned, opened or searched (no GitHub repository,
no local directory, `/stack/projects/invoiceninja` never touched). Invoice Ninja (IN) facts come only from
invoiceninja.com pages, the public user documentation, the public API reference, pricing pages and third-party
reviews; each cites its URL. Where a behaviour could only be learnt from code, the cell says **unknown**.

Verdict scale (from twes-in's point of view): **better** · **on par** · **worse** · **missing in twes-in** ·
**missing in IN**. Market line: what it means for small Tunisian and French businesses WITH A PLACE (shops,
workshops, cafés).


**Grading legend for IN facts.** `[Verified: <URL>]` = the cited public page states it (fetched 2026-10-06).
`[Inferred: …]` = follows from what a cited page states. `[Unverified: not in IN's public docs as read 2026-10-06]` =
the docs are silent; this is **never** read as "missing in IN". The verdict **missing in IN** is used only where a
page documents the absence (a list that omits it, a forum answer that refuses it). twes-in facts cite a SPEC § 8 row or a
file read at HEAD 67dddd64.

This lens reports no defects (it is a comparison); severities are not used. Short IN URLs used below:
`UG/x` = `https://invoiceninja.github.io/docs/user-guide/x`; `PRICING` = `https://invoiceninja.com/pricing-plans/`;
`FEAT` = `https://invoiceninja.com/features/`.

## Verdict summary (23 areas, primary verdict)
| Verdict | Areas |
|---|---|
| better (8) | 1 invoicing integrity · 5 delivery notes · 6 cheques and traites · 8 products · 9 stock · 12 Tunisian tax · 15 roles · 22 licensing |
| on par (3) | 7 customers (portal missing) · 13 French tax files (neither PA-ready) · 14 multi-company |
| worse (5) | 10 purchasing · 11 reports · 19 document design · 20 languages · 21 native mobile |
| missing in twes-in (3) | 2 quotes · 3 recurring · 18 e-mailing documents, reminders, API tokens, webhooks |
| mixed / both lacking (4) | 4 deposits (both lack the facture d'acompte) · 16 security (better auth, no audit screen) · 17 import/export (better import, no backup) · 23 pricing (better payment fit, no public plans) |

## Per area

### 1. Invoicing — invoices, credit notes, cancellation, numbering
- **IN**: eight invoice states incl. *Cancelled* ("the clean way to write off an invoice that was sent in error"),
  *Deleted* and *Reversed*; "Lock Invoices — prevent edits to invoices after a trigger" is an optional workflow
  setting (on sent or on paid); credits are numbered documents with lines, cloned from an invoice, that sit as a balance
  until applied. Discounts flat or %, quantities to six decimals, four custom surcharges. [Verified: UG/invoices,
  UG/advanced-settings, UG/credits]
- **twes-in**: draft → issued → partially paid / paid, `cancelled` for drafts only (`Invoice::cancel` refuses any non-draft:
  "only a draft is cancelled, and an issued invoice is corrected by a credit note" [Verified: `api/src/Module/Invoices/Domain/Invoice.php:500-505`]); an issued invoice is immutable and
  corrected only by a credit note; numbering gapless, assigned at issue; seller snapshot frozen at issue (row 163), print
  settings frozen (row 174); credit-note withholding mirrors its invoice (rows 21, 25, 29); partial invoicing of delivery
  notes (row 129 doing), add a delivered note to a draft (row 194). [Verified: `api/src/Module/Invoices/Domain/InvoiceStatus.php`,
  `InvoiceType.php`; SPEC § 3 Money]
- **Verdict**: **better** on legal integrity (IN's editable/cancellable sent invoice and optional lock are a compliance
  hole under TN and FR sequential-numbering rules [Inferred: IN pages above vs `docs/fiscal/TN.md` § 11 "numbered from an uninterrupted series (art. 18-II)" and
  `docs/fiscal/FR.md` § 8 "chronological continuous series, with no gaps (242 nonies A)", both `unvalidated`]);
  **on par** on the credit note as a document.
- **Market**: a French or Tunisian controller (and an accountant prospect) asks first "can I change an invoice once
  sent?" — twes-in's "no, by design" is a sales argument, IN's "yes unless you lock it" is a risk.

### 2. Quotes
- **IN**: Draft → Sent → Approved → Converted; client approves in the portal with a timestamped record; e-signature via
  DocuNinja; converts to invoice (linked back) or to a project; "Valid Until"; partial/deposit fields. [Verified: UG/quotes]
- **twes-in**: not built — row 78 `todo` (quote acting as the order, partial deliveries); row 122 `doing` (« Bon pour
  accord » signature box). Planned module `quotes` shown « Bientôt » (row 150).
- **Verdict**: **missing in twes-in**.
- **Market**: the tourneur (workshop) quotes every job; without quotes the workshop profile is not sellable. twes-in's
  planned "quote is the order, delivered in parts" goes beyond IN's convert-whole-quote model.

### 3. Recurring invoices and recurring expenses
- **IN**: recurring invoice templates (weekly → annually, remaining cycles or endless), auto-bill options, "Update
  Prices" / "Increase Prices"; recurring expenses. [Verified: UG/recurring-invoices, UG/expenses]
- **twes-in**: recurring invoices row 86 `todo` (drafts confirmed by an issue, on the row-56 worker, itself `doing`;
  Messenger/Scheduler not installed per lens W); recurring expenses row 179 `todo`.
- **Verdict**: **missing in twes-in**.
- **Market**: lower priority for a shop (sales are one-off), real for rent/maintenance contracts of a workshop and for
  the shop's own fixed costs. twes-in's "generates drafts that a person issues" fits gapless numbering better than IN's
  auto-send.

### 4. Deposits and advance payments
- **IN**: "Partial/Deposit" = a smaller amount due earlier on the SAME invoice or quote, with its own due date
  [Verified: UG/invoices, UG/quotes]; pre-payments not attached to an invoice from the portal [Verified: UG/client-portal].
- **twes-in**: a « dépôt » credit entry kind exists (`CreditEntryKind::Deposit`), which audit finding E-9 says records
  an advance with no VAT and no document; the facture d'acompte with its own number and VAT is row 79 `todo`; row 208
  `todo` renames it « trop-perçu / crédit client ».
- **Verdict**: **both lack the legal object** (a deposit invoice with its own VAT, deducted on the final invoice). IN's
  model is a payment schedule; twes-in's current one is weaker (E-9).
- **Market**: workshops take acomptes on orders; in France VAT on a service is due on receipt of advances, and since 2023
  an advance on identified goods makes VAT due too, while twes-in states "advances are not modelled" [Verified:
  `docs/fiscal/FR.md` § 2a, CGI art. 269-2-a/c, rows `unvalidated`] — the object matters legally. A twes-in that ships row 79 is ahead of IN here.

### 5. Delivery notes
- **IN**: a « Delivery Note » is a PDF view toggle of an invoice ("Open the PDF view with the Delivery Note toggle
  enabled"); it is not a separate document. [Verified: UG/invoices]
- **twes-in**: a document of its own with its own series, PDF, reception block with réserves (row 122 doing), stock moved
  from the product's ordered homes (row 189), partial invoicing (129), added to a draft invoice (194), print language
  and prices frozen (174). [Verified: SPEC § 8 rows 9, 129, 174, 189, 194; `api/templates/pdf/delivery_note.html.twig`]
- **Verdict**: **better** (missing in IN as a document) [Inferred: the only IN mention is a print toggle].
- **Market**: the bon de livraison is daily paperwork for a quincaillerie's trade customers (deliver now, invoice at
  month end); this is one of twes-in's strongest fits.

### 6. Payments — methods, partial, overpayment, credits, cheques and traites
- **IN**: methods from a list ("cash, cheque, credit card, bank transfer, and so on"); partial payments; one payment over
  several invoices; overpayment kept as "unapplied"; refunds; credits combined with cash; online gateways. Post-dated
  cheques are not addressed. [Verified: UG/payments; Unverified: post-dated cheques — not in IN's public docs as read]
- **twes-in**: payments cash / transfer / cheque / card / other (`api/src/Shared/Domain/PaymentMethod.php`); partial;
  overpayment to a credit balance, write-off under tolerance, credit note on a paid invoice (row 128 doing); post-dated
  cheques and traites as instruments with due day and state, becoming a payment only when cashed, with a portfolio and
  an « À surveiller » subject (row 191 done; portfolio unreachable per B-2, no export per B-3, no way back from
  "cashed" per E-7). One payment spread over several invoices: not modelled — a `Payment` belongs to exactly one invoice [Verified: `api/src/Module/Invoices/Domain/Payment.php:30-32`, `ManyToOne Invoice`]; an overpayment reaches the next invoice only through the credit balance (row 128).
- **Verdict**: **better** on cheques/traites (the TN B2B reality), **worse** on a single payment over several invoices
  (a trade customer's one transfer for the month's invoices) and on online payment (ruled out, see § 18).
- **Market**: in Tunisia B2B money arrives as chèques and traites with due dates; a portfolio that tells who pays this
  week is a pitch line IN cannot match.

### 7. Customers and the customer portal
- **IN**: contacts with portal logins, named locations (shipping addresses), per-client currency, language, terms;
  group settings; VAT validation; 4 custom fields; activity; client portal to pay, approve quotes, download, statements
  for any range, pre-pay; branded portal on Enterprise. Credit limit: [Unverified: not in IN's public docs as read].
  [Verified: UG/clients, UG/client-portal, PRICING]
- **twes-in**: customers, contacts, groups (row 7), settings chain platform → company → group → customer → document
  (SPEC § 3 Settings), custom fields (`api/src/CustomFields`), statement of account and credit limit with a delivery
  warning (row 85 done; A-8/A-9/E-10 reopenings), portal planned after the first version (SPEC § 2, no row in progress).
- **Verdict**: **on par** on customer records and statements; **better** on credit limit; **missing in twes-in**:
  the portal.
- **Market**: the portal is "after the first version" by ruling and serves trade clients; a shop prospect will miss it
  less than quotes or email sending.

### 8. Products and catalogue
- **IN**: item, description (Markdown), price, default and max quantity, up to three tax rates, documents, 4 custom
  fields ("SKUs, supplier codes, shelf locations"). Barcodes, variants, units, cost price: [Unverified: not in the
  products page]; product settings mention "cost tracking" [Verified: UG/basic-settings].
- **twes-in**: products and categories, several barcodes with label printing (186), scanner from camera or paired phone
  (63/117), substitution groups (62), lots and serials with GS1 scan (108/116), cost price gated by `product.cost.read`
  (SPEC expertise-core), price calculator with margin (185), price lists (77 doing, API still at shelf price per A-5);
  families/variants and units are row 76 `todo`.
- **Verdict**: **better** (barcodes, lots, cost secrecy, scanning); variants and unit conversion **missing in both**
  as documented.
- **Market**: a quincaillerie has thousands of SKUs with barcodes and buys by the box, sells by the piece — twes-in
  covers barcodes now, units only with row 76.

### 9. Stock and inventory
- **IN**: one stock count per product, decremented "when invoices are issued", low-stock email threshold, purchase
  orders increment stock on receipt. Multiple warehouses, locations: [Unverified: not in IN's docs as read].
  [Verified: UG/products, UG/purchase-orders]
- **twes-in**: establishments → locations from site to bin (row 13), moves (74), counts (118), losses (74b doing),
  reorder point per establishment (110), product homes (101, 188), weighted-average valuation (87 doing; E-4/E-6 defects),
  lots/FEFO (116), a drawn stock map (83 doing, partial per lens W), alerts through the notification centre (97).
- **Verdict**: **better**, by a wide margin.
- **Market**: this is the core of "businesses with a place"; IN is a service-business invoicer with a stock counter.

### 10. Expenses, vendors, purchasing
- **IN**: expenses with categories, receipts, OCR (Mindee) on self-hosted, recurring expenses, re-bill to clients,
  multi-currency, bank-transaction matching; vendors; purchase orders Draft → Sent → Accepted (vendor portal, with
  signature) → Received → converted to an expense, stock incremented. Three-way match: [Unverified: not in docs].
  [Verified: UG/expenses, UG/purchase-orders]
- **twes-in**: vendors (row 11), expenses with attachments (12), expenses without vendors (178), supplier withholding
  on payment with TEJ XML (111, 144); a goods receipt with optional vendor/reference (190); purchase orders, supplier
  bills and three-way match are rows 81 and 84 `todo`; OCR none; re-billing an expense to a customer: not built [Verified:
  `git grep -nE "rebill|re-bill|refactur|billable" -- api/src/Module/Expenses` → no hit].
- **Verdict**: **worse** on purchasing (no PO yet, no vendor portal, no OCR, no recurring); **better** on TN supplier
  withholding and TEJ.
- **Market**: both first customers restock constantly (SPEC § 2, why POs left "out"); row 81 is the gap a shop owner
  will name.

### 11. Reports and dashboard
- **IN**: standard reports (clients, invoices, quotes, payments, credits, expenses, recurring, products, items), P&L
  cash or accrual, tax summary, aged receivables, customer balance/sales, product sales, user sales; CSV export,
  scheduled e-mailed reports; dashboard. [Verified: UG/reports]
- **twes-in**: home figures — margin, invoiced and collected month to date, withholding suffered, expenses, aging and
  chase list as SQL (rows 113 doing, 164 done; « Encaissé » double-counts per E-14, row 201); « À surveiller » live
  screen (115); every list exportable as CSV/xlsx with its filters (60); report engine and the ten reports rows 89 / 114
  `todo`; declarations area row 91 `todo`.
- **Verdict**: **worse** (no report catalogue, no P&L, no tax summary, no scheduling).
- **Market**: the accountant and the owner both want a VAT summary per period and a P&L; TN's monthly declaration and
  FR's CA3 make the tax summary non-optional — twes-in's planned "declarations with a calendar" (91) would overtake IN.

### 12. Taxes and fiscal compliance — Tunisia
- **IN**: line-level and/or invoice-level taxes, up to three rates per product [Verified: UG/taxes, UG/products];
  automatic calculation only for the US, the EU and Australia [Verified: UG/taxes]; withholding by a negative tax rate
  (a forum workaround for Spain's IRPF) [Verified: https://forum.invoiceninja.com/t/problem-with-negative-taxs/1307];
  a fixed charge by one of four custom surcharges, each optionally taxed (`custom_surcharge1-4`,
  `custom_surcharge_tax1-4`) [Inferred: search snippet of https://invoiceninja.github.io/docs/developer-guide/api/invoices]. FODEC entering the VAT
  base, the timbre as a per-document fixed tax, the retenue threshold, TEJ certificates and TEIF / El Fatoora: no IN
  page mentions them, and TN is absent from the e-invoicing country lists [Verified: UG/einvoicing].
  FODEC + TVA on (net + FODEC): IN's three independent rates cannot compound without pre-adjusting prices
  [Inferred: UG/taxes describes independent rates and does not mention compound taxes].
- **twes-in**: a closed set of tax kinds — percentage on line net with `enters_vat_base` (FODEC), fixed per document
  (timbre), withholding on total with threshold — sourced in `docs/fiscal/TN.md` §§ 2–5, preset in
  `api/config/fiscal/TN.yaml`; customer tax regimes (§ 9); supplier withholding and TEJ XML (rows 111, 144); declaration
  basis researched (112). TEIF signed and the TTN connector: rows 145 `doing`, 102 `blocked`, 192 `todo`; till
  accreditation 143 `blocked`. Every rule `unvalidated` until a chartered accountant confirms it.
- **Verdict**: **better** — IN has no Tunisian model at all; approximating it there is configuration the user must get
  right by hand.
- **Market**: this is the launch market's entry ticket: timbre, FODEC and retenue printed correctly, and the TEJ file.
  It is twes-in's clearest wedge against any generic invoicer.

### 13. Taxes and fiscal compliance — France
- **IN**: EU VAT automation incl. OSS [Verified: UG/taxes]; e-invoicing formats PEPPOL, ZUGFeRD/XRechnung, Facturae,
  Verifactu (alpha), FatturaPA and FACT1 (not production-ready), EN 16931, through IN's own PEPPOL access point; France
  listed under "full support launching early May 2026" [Verified: UG/einvoicing] (a date already past as of this read;
  whether it launched is unknown). France's 2026–27 reform routes B2B
  invoices through a plateforme agréée, not PEPPOL alone; a forum request for PA support got only "follow up on GitHub"
  [Verified: https://forum.invoiceninja.com/t/feature-request-france-support-for-mandatory-b2b-e-invoicing-via-pdp-ppf-2026-2027/23042].
  So IN's "France full support" is a PEPPOL claim whose fit with the PA route is [Inferred: unproven from public pages].
  Till rules (NF525-like): none documented.
- **twes-in**: FR preset with franchise en base, customer regimes, mentions (`docs/fiscal/FR.md`); Factur-X (CII in a
  PDF) built in `api/src/Module/Invoices/Application/FacturX/` (row 145 `doing`); UBL not yet; the plateforme agréée is
  "before 2027-09-01" (row 145); French invoice fields `operation_category`, `vat_on_debits` row 44 `todo`; till
  attestation and fiscal journal rows 142, 103 `todo`.
- **Verdict**: **on par** on files (IN wider format set and a live network; twes-in has Factur-X, the French format of
  record); **neither** is ready for the FR PA route; **worse** on network delivery today.
- **Market**: a French shop must RECEIVE e-invoices from 2026-09 and SEND from 2027-09 (SME); both products still owe the
  PA connection. Row 44 is a cheap compliance gap twes-in should close before pitching France.

### 14. Multi-company
- **IN**: "10 company interlinks" on Pro (several companies under one login) [Verified: PRICING].
- **twes-in**: a person belongs to several companies and switches; unread counts per company (rows 2, 157); a company
  has its own fiscal preset and currency. [Verified: SPEC § 1, rows 2, 157]
- **Verdict**: **on par**; twes-in **better** for an accountant serving many companies (no cap stated, per-company
  counts). The exact IN cap on Free: [Unverified: not on the pricing page].
- **Market**: owners with a shop and a workshop, and accountants, both use this; a pitch line for the accountant audience.

### 15. Roles and permissions
- **IN**: users with "View, Create, or Edit across clients, invoices, quotes, tasks, vendors, products"; multiple users
  on Enterprise ($18+/month, priced by user count). [Verified: UG/advanced-settings, PRICING]
- **twes-in**: permission strings checked by a voter on every endpoint; a catalogue declared by each module; a company
  creates and edits its own roles in a matrix (row 104 done; row 53's templates `todo`); `product.cost.read` hides cost
  and margin; establishments and `_own`/`_any` ruled but unbuilt (SPEC § 3 Authorization); the clerk role is B-12's
  defect (row 198). Operators have no standing access (expertise-core § 2).
- **Verdict**: **better** (finer grain, per-company roles, cost secrecy) — and included, not an upsell.
- **Market**: a shop with a counter clerk must hide cost prices and margins from staff and from customers watching the
  screen; twes-in rules this, IN documents no field-level permission.

### 16. Security — MFA, passkeys, audit
- **IN**: two-factor by TOTP [Verified: UG/basic-settings]; SOC 2 Type II for the hosted service, TLS 1.2+, AES-256 at
  rest [Verified: https://invoiceninja.com/soc-2-compliance/]; per-document panels — History ("a chronological record of changes to the invoice total and who made them"),
  Activity ("every action performed against the invoice … alongside the user responsible") and Email History
  ("every time the invoice was emailed, to whom, and when") [Verified: UG/invoices]. Passkeys: [Unverified: not in IN's docs as read].
- **twes-in**: TOTP, recovery codes, passkeys (WebAuthn) as factor, a per-company "second factor required" switch,
  operators always on a second factor (row 3, SPEC § 3 Auth); argon2id, rotated sessions, CSP with nonce, CSRF by
  origin, lockout, breached-password check; single `audit_log` holding field names only, never values; the « Journal
  d'activité » screen is row 161 `todo`. Open defects from lens D (D-1 timing enumeration, D-4/D-6 step-up).
- **Verdict**: **better** on authentication (passkeys, enforced MFA per company); **worse** on a visible audit screen
  (IN shows History/Activity on each document today; twes-in records it but has no screen until row 161); **missing in
  twes-in**: an external certification such as SOC 2 (a one-person hosted SaaS has none).
- **Market**: SMEs rarely ask for SOC 2; an accountant will ask "who changed this?" — row 161 answers it.

### 17. Import and export
- **IN**: CSV import with column mapping per entity; direct imports from FreshBooks, Wave, Zoho, Invoicely; a full
  company JSON backup ("settings and data — as a downloadable zip"); report CSVs. [Verified: UG/basic-settings;
  https://invoiceninja.github.io/docs/advanced-topics/import-and-export via search result]
- **twes-in**: CSV and .xlsx import with a template per type, a server-side preview of new / updated / rejected rows
  with reasons, create-only or create-and-update, one transaction (customers, products, vendors, opening stock: row 59
  done); every list exported as CSV/xlsx with its filters (row 60; CSV formula injection per D-2); no full backup /
  restore point (row 210 `todo`); an Invoice Ninja importer (row 211 `todo`); past invoices as a read-only archive keeping
  their numbers (row 64 `todo`).
- **Verdict**: **better** on import quality (preview with reasons, xlsx, idempotent stock); **worse** on full-company
  backup and on importers from other tools.
- **Market**: a TN shop arrives with an Excel file, not FreshBooks — xlsx with a preview is right for the market; the
  backup matters for trust ("can I leave?").

### 18. Integrations — payment gateways, email, API
- **IN**: gateways Stripe, Square, Checkout.com, Mollie, PayTrace, PayFast, Authorize.net, Braintree, eWAY, CHIP,
  BTCPay; PayPal and GoCardless on the marketing page; fee pass-through; no Tunisian or French-specific processor listed
  [Verified: UG/gateways, FEAT]. Email via Gmail, Microsoft, custom SMTP, bulk e-mail, three reminder stages and late
  fees [Verified: PRICING, UG/advanced-settings]. REST API with tokens, webhooks; Zapier, Make, n8n [Verified:
  https://invoiceninja.github.io/docs/api-reference/invoice-ninja-api-reference, FEAT]. QuickBooks sync, bank sync
  (Yodlee/GoCardless) [Verified: UG index, PRICING].
- **twes-in**: payment gateways **out, with no date** (SPEC § 2); transactional mail only (invitation, signup,
  approval, subscription) — sending a document to its customer by e-mail and reminders are "after the first version"
  (SPEC § 2; no `Send*`/mailer for documents in `api/src` [Verified: `git grep -liE 'SendInvoice|sendDocument|mailInvoice|InvoiceMail' -- api/src` → no hit]);
  WhatsApp link to a document row 95 `todo`; the API is OpenAPI-documented but session-cookie only — no API tokens,
  no webhooks [Verified: `git grep -liE 'ApiToken|AccessToken|webhook' -- api/src` → no hit]; realtime to the browser
  through Centrifugo (row 49).
- **Verdict**: **missing in twes-in**: e-mailing documents, reminders, API tokens and webhooks, bank and accounting
  sync. Gateways: a ruled non-goal, not a gap.
- **Market**: card gateways matter little to a Tunisian B2B shop (cheques, traites, cash, transfer) and the TN processors
  (Konnect, Paymee, Flouci, ClicToPay) are absent from IN too; but **sending the invoice** — by e-mail or WhatsApp — is
  the first thing every prospect does after creating one.

### 19. Document design and templates
- **IN**: 4 free / 11 templates on paid plans, real-time PDF preview, full design customisation (fonts, colours, field
  visibility; custom designs on Pro), invoice design service on Premium. [Verified: FEAT, PRICING, UG/advanced-settings]
- **twes-in**: one Twig template per kind (`api/templates/pdf/{invoice,delivery_note,statement}.html.twig`) rendered by
  Gotenberg, logo (158), amount in words and « Comment payer » (121 doing), printed notes; no layout choice, no colour,
  no preview (lens W item 6). Company HTML refused by ruling.
- **Verdict**: **worse**.
- **Market**: the invoice is what the customer's customer sees; a shop compares it with what it prints today. A
  parameter-based choice of 2–3 layouts with accent and live preview closes most of the gap without IN's free-form editor.

### 20. Languages — Arabic and right-to-left
- **IN**: multiple languages, per-client language for documents [Verified: UG/clients, FEAT]; Arabic appears among the
  translations [Inferred: third-party search snippet listing languages, not an IN page]; RTL in the app and PDFs:
  [Unverified: no IN page; forum threads report Arabic glyphs missing in self-hosted PDFs —
  https://forum.invoiceninja.com/t/arabic-letter-dont-show-in-invoice/10346].
- **twes-in**: French and English (`web/public/i18n/{fr,en}.json`, API `translations/*.{fr,en}.yaml`); nine legal pages
  in fr/en/ar (row 148); Arabic interface, RTL and bilingual documents row 96 `todo` ("bet", SPEC § 2).
- **Verdict**: **worse** today on breadth; for Arabic/RTL both are **unproven** — whoever ships bilingual FR/AR
  documents first owns the Tunisian argument.
- **Market**: Tunisian administration and many customers read Arabic; bilingual invoices are expected by some public
  buyers. Row 96 is a differentiator, not catch-up.

### 21. Mobile
- **IN**: native apps for iOS, Android and desktop (macOS, Windows, Linux) [Verified: FEAT "Mobile & desktop
  applications available"; per a third-party case study they are one Flutter code base —
  https://blog.codemagic.io/invoice-ninja-case-study/].
- **twes-in**: responsive web with bottom bar under 600 px (row 30), the whole app from a phone over the LAN door (183),
  installable (`web/public/manifest.webmanifest` `display: standalone`, linked from `web/src/index.html:16`) but no
  service worker, so nothing offline [Verified: no `ngsw`/`serviceWorker` in `web/src`]; the phone as a paired barcode
  scanner for a computer tab (63, 117) and a customer display window (`/customer-display`). Mobile client: after the
  first version (SPEC § 2).
- **Verdict**: **worse** on native apps and offline; **better** on in-store phone use (scanner, customer display).
- **Market**: a shop owner checks figures from the phone (PWA suffices); the counter's phone-as-scanner is a pitch line.

### 22. Self-hosting and licensing model
- **IN**: source-available under the Elastic License 2.0: self-host for your own business with every feature, but
  "if you plan to resell/create a SaaS which also offers invoicing, you need to become a reseller … and agree to a
  commercial license"; attribution must stay unless a white-label licence is bought ($30/year, client-facing pages and
  PDFs only). Docker, tarball, Cloudron, Softaculous; PHP 8.2 + MySQL/MariaDB (PostgreSQL not listed).
  [Verified: https://invoiceninja.github.io/docs/legal/license, UG self-host installation page
  https://invoiceninja.github.io/docs/self-host/self-host-installation; $30/year: https://forum.invoiceninja.com/t/white-label-licence/12737]
- **twes-in**: AGPL-3.0-or-later plus a commercial licence, copyright wholly owned (SPEC § 1); production image and
  compose (`compose.prod.yaml`, row 166), self-hosted log rotation (`infra/self-hosted/logrotate.conf`); branding fully
  configurable, no licence-key gate (CLAUDE.md invariants 2, 5); brand kit and per-company sign-in rows 36, 38 `todo`.
- **Verdict**: **better** on licence freedom (OSI open source; no attribution tax; a partner may rebrand under the
  commercial licence); **worse** on install paths (one Docker route, no one-click packages yet).
- **Market**: an accountant or integrator in Tunisia could host it for clients; AGPL forces their changes back, the
  commercial licence is the paid alternative — a partner story IN's ELv2 forbids without a reseller deal.

### 23. Pricing
- **IN**: Free (5 clients), Pro $14/month or $140/year (unlimited clients, API, reminders, reports, 10 companies),
  Enterprise from $18/month (users, branded portal, bank sync, e-invoicing with 250 PEPPOL credits), Premium Business
  from $280/year. [Verified: PRICING]
- **twes-in**: a subscription engine per company — trial, period, price, grace, read-only or locked, cash payments
  declared by the company and confirmed by the operator (rows 65, 66 done); plans as data and their content row 159
  `todo`; no public price.
- **Verdict**: **better** on payment fit (declared cash payments suit a market where many pay in cash); **missing in
  twes-in**: published plans and a free tier.
- **Market**: $14–18/month (roughly 45–60 TND [Unverified: exchange rate from recall, not checked]) is the price anchor a Tunisian prospect who googles alternatives will see; the
  pitch must justify any premium by the TN fiscal model and stock.

### 24. Things IN has that no twes-in row plans (for completeness)
- Time tracking with timer, Kanban, project budgets, task → invoice [Verified: FEAT] — twes-in: « Projets » row 80
  `todo` (tasks, status, assignee, due date; hours on a job are in, a general time-tracking product is out, SPEC § 2).
- E-signatures through DocuNinja [Verified: UG index] — twes-in: row 126 `deferred` (research first).
- Payment links / subscriptions checkout, tips/overpay online [Verified: UG index, FEAT] — out with gateways.
- Tags inherited from parent records [Verified: UG/basic-settings] — twes-in: [Unverified: no tag concept found in the
  SPEC rows read].
- Expense OCR [Verified: UG/expenses] — twes-in: none planned in the rows read.

## Where twes-in wins (pitchable differentiators)
1. **The Tunisian invoice done right, out of the box**: FODEC inside the VAT base, the timbre per document, the retenue à
   la source with its threshold printed and carried onto credit notes, customer tax regimes, and the TEJ withholding XML —
   none of which IN models (§ 12). One line: *« Votre facture tunisienne est juste du premier coup, timbre, FODEC et
   retenue compris. »*
2. **Stock for a place, not a counter**: sites to bins, moves, counts, losses, lots/serials with GS1 scan, reorder points
   per establishment, weighted-average valuation and the drawn map (§ 9) — IN keeps one number per product.
3. **The bon de livraison as a real document**, moving stock, invoiced in part or added to a draft invoice (§ 5) — IN
   prints an invoice with prices hidden.
4. **Chèques and traites as a portfolio** with due days, held / deposited / cashed / unpaid, and « À surveiller » telling
   who falls due this week (§ 6) — the Tunisian B2B way of being paid. (Fix B-2, B-3 and E-7 before demoing it.)
5. **Invoices you cannot quietly change**: issued means immutable, gapless numbering at issue, credit notes only, the seller
   and print settings frozen at issue (§ 1) — IN offers cancel, delete and an optional lock.
6. **Phone as a scanner and a customer display** at the counter, barcodes on every label (§ 8, § 21).
7. **Cost and margin hidden from staff and from the customer's eyes** by permission, roles built per company and
   included in the plan (§ 15).
8. **Security a step above**: passkeys, a per-company "second factor required", operators without standing access (§ 16).
9. **Truly open source (AGPL) plus a commercial licence**, no attribution tax, rebrandable by a partner (§ 22).
10. **Live multi-person editing** (another member's save merges into an open editor; lists reload on change, row 49) —
    [Unverified for IN: no public page describes it].

## Where twes-in is behind (ranked by how much a prospect would miss it)
1. **Sending a document** — no e-mail of an invoice, quote or delivery note to the customer, no reminders, no WhatsApp
   link yet (§ 18; SPEC § 2 "after the first version"; row 95). Every prospect does this in the first demo minute.
2. **Quotes** (row 78) — the workshop profile's entry point; IN has quotes with portal approval and e-signature (§ 2).
3. **Document design** — one fixed template, no preview, no colour (§ 19); the printed invoice is what prospects judge.
4. **Reports** — no P&L, no VAT summary per period, no aged-receivables report, no scheduling (§ 11; rows 89, 114, 91).
   The accountant audience misses it most.
5. **Purchase orders and receiving against them** (rows 81, 84) — both first customers restock constantly (§ 10).
6. **Arabic / RTL and bilingual documents** (row 96) — expected in Tunisia; IN's own Arabic is unproven, so this is a race,
   not a deficit, but today twes-in has only fr/en (§ 20).
7. **Deposit invoices** (row 79) and the E-9 acompte cleanup (row 208) (§ 4).
8. **Recurring invoices and expenses** (rows 86, 179) (§ 3).
9. **One payment spread over several invoices** — a trade customer's monthly transfer must be split by hand today
   (§ 6; not in any row read).
10. **Full-company backup / export-all and a visible activity journal** (rows 210, 161) — the "can I leave, who changed
    this" trust questions (§§ 16, 17).
11. **Customer portal** (after the first version) and **native mobile / offline** (§§ 7, 21).
12. **API tokens and webhooks** for integrators; **bank sync** (row 180 Trésorerie is the nearest) (§ 18).
13. **French compliance details**: row 44 (`operation_category`, `vat_on_debits`) and UBL, before pitching France (§ 13).
14. **Published plans and a free tier** (row 159) (§ 23).

## Ideas worth taking (as behaviour, never code)
Each is an observable behaviour from IN's public documentation, to be designed afresh from the law and twes-in's
rulings; none implies reading IN's code.
1. **A per-document « Historique » tab** (who created, edited, issued, sent, paid, credited, and when) on every invoice,
   delivery note and quote, read from the existing `audit_log` — IN's History/Activity panels show the value of putting
   row 161 on the record itself, not only in a journal screen. Field names only, per the audit ruling.
2. **« Envoyé » history per document** — a log of each time a document was sent and to whom (IN's "Email History"), as
   soon as e-mail or WhatsApp sending exists; it is also proof of notification for reminders.
3. **Three reminder stages with offsets**, off by default, late fees separate and off by default (already ruled, row 135);
   IN's three-tier shape is a good default count.
4. **Apply one payment across several invoices** of the same customer, pre-filled oldest-first and editable, the
   remainder going to the credit balance (row 128's mechanics) — matches how a trade customer pays a month in one transfer.
5. **Report preview before export, and a scheduled report mailed to oneself** (IN Reports: preview, then CSV; "Schedule"
   e-mails it) — fits row 89's engine and the daily digest (row 90).
6. **P&L with a cash / accrual switch and a tax summary by rate and period** as two of the ten reports (row 114) — the
   tax summary is the TN monthly declaration's and the FR CA3's raw material.
7. **Clone a document into another type** (invoice → credit note with lines pre-filled, quote → invoice, delivery note →
   invoice), with the new document linked back to its source — twes-in has parts (194); make the "linked back" visible.
8. **Update prices / increase prices by %** on a set of products or on a recurring model (IN recurring invoices) — a
   quincaillerie re-prices often when suppliers raise prices; fits row 77's price lists and row 185's calculator.
9. **Full-company export as one archive** (settings and data), the twin of row 210's restore point — answers "can I
   leave?" during a sale.
10. **Supplier documents attached to the product** (technical sheets, warranty) as IN's product documents — useful to a
    quincaillerie; twes-in has `Files` already.
11. **Quote approval recorded with time and approver** from a link, before any portal exists — row 122's « Bon pour
    accord » can carry the same evidence through the WhatsApp/e-mail link (row 95).
12. **Low-stock e-mail to the owner** in addition to the in-app alert (row 97) once the worker (56) exists — IN's only
    stock feature, but the one an absent owner relies on.

## Clean
What this lens checked and found sound on the twes-in side (no action needed for parity):
- Row 133's "a draft is cancelled, an issued document is only reversed by a credit note" is already enforced in code
  (`Invoice::cancel`, `api/src/Module/Invoices/Domain/Invoice.php:500-505`) while the row is `todo` — for the done-claims
  lenses, not a comparison finding.
- Credit notes as numbered documents with lines and taxes, carrying the invoice's withholding (rows 10, 21, 25, 29).
- Statement of account for a period, printed (row 85) — on par with IN's client statements.
- Customer groups with a settings chain overriding company defaults (SPEC § 3 Settings) — on par with IN group settings.
- Custom fields (`api/src/CustomFields`) — on par (IN: 4 per entity).
- Several companies per login with a switcher (rows 2, 157).
- CSV/xlsx export of every list with its filters (row 60), TOTP plus passkeys (row 3).
- Module switch per company (SPEC § 3 Modules) — IN shows only enabled modules in its portal; twes-in goes further with
  « Bientôt » and « Me prévenir » (row 150).
- Licensing boundary: this lens read no Invoice Ninja code, no GitHub page, no local clone, and not
  `var/claude/invoiceninja-export-study.md` (derived from IN's source for the importer).

## Not checked
- IN's Arabic interface completeness and RTL rendering in the app and PDFs — only forum reports; would need the public
  demo or a hosted trial.
- IN passkeys, SSO providers (Google/Microsoft/Apple), the exact multi-company cap on the Free plan.
- Whether IN assigns invoice numbers at draft or at sending, and whether it ever reuses a number after a delete (gapless
  behaviour) — only learnable from a trial, not from the docs read.
- IN cost price on products, variants, units, barcodes, multi-warehouse — docs silent; a trial would settle it.
- IN live collaboration (two users on one invoice) — unknown.
- Whether gateway fee surcharging is lawful in Tunisia (IN warns it is the user's responsibility) — out of scope.
- No public IN demo was driven in a browser; every IN fact comes from documentation, pricing or forum pages.
- twes-in: a tag concept was not searched beyond the SPEC rows read.
