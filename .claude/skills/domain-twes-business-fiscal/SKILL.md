---
name: domain-twes-business-fiscal
description: Use when a task touches invoices, credit notes, delivery notes, VAT/FODEC/timbre/retenue, TN or FR fiscal presets, numbering, stock valuation, subscriptions/licensing, tills/cash registers, TEJ/Factur-X/TEIF e-invoicing in twes-in. How an expert in small-business invoicing and POS compliance for Tunisia and France works: rules, sources, evidence, failure modes, reviewer lenses.
---
Review date: 2026-10-02   Validation mode: advisory   Core: .claude/rules/expertise-core.md

<!-- Tags: [TN]=docs/fiscal/TN.md, [FR]=FR.md, [TC]=docs/research/till-certification.md, [SPEC]=docs/SPEC.md §7 date, [R]=research doc. Every fiscal rule is UNVALIDATED by an accountant: use as hypothesis, cite, never assert. Ratified vs ASSUMED/PROVISIONAL SPEC entries: only ratified ones are rulings. -->

## Roles and mental models
- **Expert-comptable (TN/FR)**: only authority that validates a rule; until then each rule is a hypothesis with a cited article and version date. Ask: which article, which law version, which date?  [TN §1]
- **Shop owner (quincaillerie)**: counter sales, constant restocking, bon de livraison then invoice later; lives on stock truth, margin and "who owes me".  [SPEC 2026-09-24]
- **Workshop owner (tourneur)**: quote, deposit invoice, job (material + hours), delivery note + invoice.  [SPEC 2026-09-14]
- **Cafe/till operator**: sale at the counter, shift float, Z report by payment means and VAT rate; a till is a regulated device, not a feature.  [TC]
- **Tax inspector**: reads the printed invoice: mandatory mentions, gapless numbering, stamp, withholding; asks for archive and audit trail.  [Source: docs/fiscal/TN.md §4 mentions/numbering; Unverified as a description of inspector practice]
- **Platform operator (developer)**: confirms cash subscription payments, approves companies; reaches a company only via a membership or `/api/platform/*`.  [SPEC 2026-09-15]
- **Mental models**: presets are DATA, tax kinds a closed set of 3 in code; an issued document is frozen and answered from columns; a guard that feels too strict usually points at a missing feature (overpay -> credit balance, short-pay -> write-off credit note, post-dated cheque -> instruments portfolio).  [SPEC 2026-09-13, 09-21]

## Standards, laws, norms
| Rule | Applies when | Source | Last checked |
|---|---|---|---|
| TVA TN 19/13/7 %; base includes FODEC; Ministry overview page is STALE (18/12/6) | TN taxable lines | TN §2; Code TVA art. 6-I, 7 | 2026-09-13 |
| FODEC 1 % ex-tax, listed products only, per product, never default, enters VAT base | TN listed goods | TN §3 | 2026-09-13 |
| Timbre 1.000 TND per invoice (CDET art. 117, LF 2023 art. 69), fixed per document, outside bases and discounts; large-retail 1.5/2 TND (LF 2026 art. 20) NOT modelled, amount editable per company | every TN invoice | TN §4 (verified in file); SPEC 2026-09-13 | 2026-10-02 |
| Retenue a la source 1 % at >= 1000 TND VAT incl. (CIRPP/IS art. 52-I, LF 2021 art. 14; was 1.5 %); not a default; stamp not in base; threshold inclusive on absolute value, excluding fixed charges; reduces only amount due | customer is a withholding payer | TN §5 (verified); SPEC 2026-09-13 | 2026-10-02 |
| Credit notes of a withheld invoice sum exactly to it: the completing one absorbs the rounding remainder (`withholdingCompleting`) | TN credit notes | SPEC 2026-09-16 | 2026-09-16 |
| Company as PAYER: supplier payment >= 1000 TND VAT incl. withholds, rate 1/1.5/0.5 % by supplier, payment states its own rate, "0" = none; proposal only if exactly one whole-amount component | expenses TN | TN §5a; SPEC 2026-09-25 | 2026-09-25 |
| TEJ: withholding certificates are the one MANDATORY e-file (all taxpayers since 2026-01-01; fine 30 % of tax withheld, min 50 TND/certificate); XML + XSD, file `[matricule]-[YYYY]-[MM]-[acte].xml`, 47 operation codes, amounts in integer millimes, web upload only | TN expenses with withholding | R tax-data-tunisia §2.2 | 2026-09-25 |
| VAT recognition: goods on delivery; services on performance or earlier receipt (TN), on receipt unless debits option (FR); shown as "TVA collectee", never "a declarer"; TN monthly (15th natural / 28th legal); FR CA3 monthly/quarterly | home tile, reports | TN §2a, FR §2a; SPEC 2026-09-25 | 2026-09-25 |
| TVA FR 20/10/5.5/2.1 %; franchise: "TVA non applicable, art. 293 B du CGI" (CIBS renumbering 2027-01-01) | FR | FR §2-3 | 2026-09-13 |
| Mentions: TN art. 18-II TVA; FR CGI annexe II art. 242 nonies A, C. com. L441-9/D441-5 (40 EUR indemnity); FR adds customer SIREN, operation category, VAT-on-debits option, delivery address (decret 2022-1299; FAQ says 2026-09-01 for all vs FR §4 by size: source conflict, print now) | printed docs | TN §7, FR §4; R tax-data-france | 2026-09-25 |
| FR e-invoicing: receive 2026-09-01 via a plateforme agreee; issue GE/ETI 2026-09-01, PME/micro 2027-09-01; PDF by email is NOT an e-invoice; sanction 50 EUR/invoice, cap 15,000 EUR/yr | FR B2B | R tax-data-france §1 | 2026-09-25 |
| Factur-X EN 16931 CII (PDF/A-3b via Gotenberg), credit note = type 381 positive amounts, XML written from stored figures never recomputed | issued FR docs | SPEC 2026-09-25 | 2026-09-25 |
| TN El Fatoora/TTN: TEIF 1.8.8 (not UBL/CII), XAdES-B; connector NOT built, blocked on TTN spec + test account; build every field, schedule no connector | TN B2B | R tax-data-tunisia; SPEC 2026-09-20 | 2026-09-25 |
| Identifiers: TN `matricule_fiscal` `^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$` (check letter not implemented; key letter excludes I, O, U [Inferred]); FR SIREN/SIRET Luhn (La Poste exception), VAT key (12 + 3 x (SIREN mod 97)) mod 97; pattern in preset, checksums from a closed set in code | company + customers | TN §8, FR §5; SPEC 2026-09-14 | 2026-09-14 |
| Rounding lives in the PRESET (`half_up`, `per_rate_group` = EN 16931 BR-CO-17), never in settings; each figure rounded once at currency scale (TND 3 decimals, EUR 2); BcMath, never float | every total | TN §10, FR §7; SPEC 2026-09-13 | 2026-09-13 |
| Tax composition: levy into VAT base first, then other percentage taxes; global discount is its own row (BT-92/BT-95), allocated pro rata AFTER line discounts; TTC entry refused with >1 percentage tax or levy-in-base (no source for rounding) | pricing | SPEC 2026-09-13, 2026-09-21 | 2026-09-21 |
| Gapless numbering, one series per document type and establishment, yearly reset default; imported past invoices never enter the series; period closing refuses back-dated documents | issue | TN §11, FR §8; SPEC 2026-09-20 | 2026-09-20 |
| Legal mention unfillable for the customer's regime => issuing REFUSED naming the datum (twig once dropped `%` mentions silently; exposure 250-10,000 TND/invoice) | issue | SPEC 2026-09-21; TN §7 | 2026-09-21 |
| Credit note: states reason + number/date of corrected invoice (EN 16931 BG-3), reason <= 500 chars, immutable; paid invoices creditable, excess goes to refund payment or customer credit balance | credit notes | SPEC 2026-09-24, 09-14 | 2026-09-24 |
| Customer-facing price shows tax-inclusive price the document will charge (TN Law 2015-36 art. 29 read; FR arrete 3 Dec 1987 text UNREAD) | counter, portal | SPEC 2026-09-24 | 2026-09-24 |
| Retention: TN books 10 yrs + accounting program deposit (CIRPP/IS art. 62); FR tax 6 yrs (LPF L102 B), accounting 10 (C. com. L123-22); FR piste d'audit fiable (CGI 289 VII). FEC cannot come from an invoicing app: offer a "journal" in FEC layout, never call it FEC | archive, exports | R tax-data-* §3 | 2026-09-25 |
| FR till: CGI 286 I 3° bis (in force 2026-06-27, loi 2026-534 art. 87): accredited certificate (NF525 AFNOR/Infocert, LNE) OR editor's nominative attestation (BOI-LETTRE-000242, restored 2026-02-21, LF 2026 art. 125); fine 7,500 EUR/system (CGI 1770 duodecies); major version needs a new attestation; a self-hoster who modifies the code becomes the "editor" | B2C till sales | TC (verified in file) | 2026-10-02 |
| TN till: on-site food/drink only; Ministry-HOMOLOGATED register from accredited supplier, permanent MF connection, QR, numbered copies; CDPF art. 94 16 days-3 yrs + 1,000-50,000 TND; twes-in path BLOCKED (homologation spec unreachable) | cafes/restaurants TN | TC; decret 2019-1126; arrete MF 2025-10-14 | 2026-09-25 |
| Training mode (FR/TN/DE alike): journalled, chained, `training` flag, watermark, no payment, excluded from totals/Z; separate sandbox company OK for demos | tills, practice company | TC; SPEC 2026-09-24 | 2026-09-25 |
| Dual licence AGPL-3.0-or-later + commercial; deps must be PERMISSIVE (MIT/Apache/BSD/ISC/0BSD/MIT-0/CC0/BlueOak/Unicode-3.0); CLA before first external patch; gate `scripts/gates/dependency-licences.php`; Invoice Ninja (ELv2) unusable as a base | any dependency/asset | LICENSING.md; R | 2026-09-23 |
| Stock valuation: weighted average (UNCERTIFIED, TN stock standard allowance unsourced); unknown cost reads UNKNOWN never zero | stock, margin | SPEC 2026-09-25 | 2026-09-25 |

## Tools of the trade
- `docs/spec/pricing-vectors.json` + calculator on `BcMath\Number` (24-decimal intermediates): proves arithmetic and rounding incl. TND; a rule change = vector change.  [SPEC 2026-09-13]
- Presets `api/config/fiscal/<CC>.yaml` (Symfony Config tree) + country-pack conformance test loading every pack: proves "new country = data".  [SPEC 2026-09-20]
- Official XSDs: `TEJRSCodesOperations_v1.0.xsd` / DeclarationRS (TEJ), TEIF 1.8.8, EN 16931 CII (Factur-X); validate generated XML against them; PA sandboxes (Iopole; SUPER PDP) for FR e-invoicing.  [R tax-data-*]
- Primary portals: Legifrance, BOFiP, JORT, finances.gov.tn, teledecgo.finances.gov.tn (monthly TN form, no API), impots.gouv.fr.  [TN, FR]
- Till hardware via browser only: Epson ePOS-Print XML over HTTP to the printer, ESC/POS drawer pulse `1B 70 m t1 t2`, ZXing-wasm camera scan, `/customer-display` on screen 2; no QZ Tray (LGPL), no Star/Sunmi SDKs; card: FR via provider cloud API (SumUp/Stripe/Adyen), TN manual "carte" tender. Printer voltage and hardware behaviour NOT tested [Unverified].  [R till-hardware]
- `bin/console app:stock:replay-delivery-note <company> <dn>`; `make notices`; `--dump-rules` of the licence gate.  [SPEC §8, LICENSING.md]

## What "good" looks like (acceptance criteria)
- Pricing vectors green incl. TND (3 decimals) and per-rate-group rounding; no float on money; NUMERIC(14,3).
- Rule change: docs/fiscal/<CC>.md (with primary source, marked unvalidated) AND preset AND vector in ONE change; a new country touches no code.
- Issued invoice: figures frozen in columns, `seller_snapshot` + customer snapshot + print settings kept; `amount_due = total_gross - withholding - paid - credited`; no edit path, only credit note <= still-charged amount.
- Printed document shows every preset mention as a translation key, regime mention for exempt/suspended/export, bank details when present, DRAFT watermark on drafts, paid stamp only on a dated "COPIE" and computed from payments.
- Numbering gapless per series under concurrency; closed period refuses back-dated documents.
- Stock = sum of signed movements; a location with movements is kept (409); delivery note validated -> stock out, cancelled -> back; delivery note figures recomputed on every read, never stored.
- FR Factur-X XML/PDF validates; 422 names every gap; TEJ file validates against the XSD.
- Licensing: subscription state decided from dates in `CompanyGuard`/`PermissionVoter`, never a scheduled job; module off = 404 with data kept; documents always carry the company logo.  [SPEC 2026-09-09, 09-14]
- Till claims: never "compliant"; FR needs certificate or attestation plus fiscal journal (append-only, hash-chained, signed); TN stays blocked.

## Classic failure modes
- Rate/threshold from memory -> demand citation; stale sources: Ministry TN page (18/12/6), 9anoun art. 52 mirror (1.5 %); detect by checking article + amending law date.  [TN §2, §5]
- Stamp inside a tax base or discount; FODEC or withholding applied by default -> pricing vector with stamp + discount.
- "TVA a declarer" on issued invoices -> translation test bans the string.  [SPEC 2026-09-25]
- Rounding per line when preset says per rate group (0.002 drift) -> vector with mixed-rate lines.
- Partial credit note copying the whole document discount/fixed charges; `line_gross` mixing pre-discount net with post-discount tax (D3, D5-D7 open) -> credit-note vectors on discounted invoices.  [SPEC §8]
- Mention silently dropped after issue (`%` placeholder in twig) -> render the issued PDF for every regime and read it.  [SPEC 2026-09-21]
- Delivery-note stock move failed after validation -> replay command; check stock = movements.
- Credit notes of a withheld invoice off by one millime -> sum credit notes == invoice withholding.
- Setting declared in web only (`UnknownSetting` swallowed) so preference dies on reload; definition naming an unreachable level never works -> grep `api/src`, catalogue test.  [SPEC 2026-09-21]
- Margin read as 0 when cost unknown; ASSUMED/PROVISIONAL log entries taken as rulings -> check ratification.
- Claiming tills/e-invoicing compliance; calling a FEC-layout journal "FEC".  [TC, R]
- Dependency or asset breaking the permissive-only rule (copyleft, ELv2, proprietary SDK) -> run the licence gate.

## Evidence surfaces (what counts as proof here)
- Money/tax logic: pricing vectors + unit tests per tax component and state transition; Functional tests per endpoint (happy, validation, unauthenticated, wrong company); Playwright through the real stack.
- Printed output: the RENDERED PDF per regime/language, looked at (screenshot or text-extracted), not only asserted.
- Machine files: XML validated against the official XSD / EN 16931 schematron where available; round-trip from stored figures.
- Fiscal claim: counts only with a primary source (Legifrance, BOFiP, JORT, Code de la TVA article) plus its law-version date; secondary sources labelled; stays "unvalidated" until an accountant confirms. Hardware/till/TTN claims without a device or test account are [Unverified].
- Not proof: "tests pass" alone for a rule change, a summary or memory of a rule, a reviewer reading a diff.

## Reviewer lenses (for 3C/6C and the milestone panel)
1. **The accountant**: each rule sourced, current, rounded per preset, labelled, still marked unvalidated; vector exists.
2. **The inspector**: gapless numbering, mandatory mentions printed, frozen issued document, credit-note trail, withholding sums, retention/archive, no compliance overclaim.
3. **The shop owner at 6 pm**: a sale, delivery or stock correction in few steps on a phone/till, French first, Arabic/RTL later, stock and margin truthful.
4. **The tenant attacker / licence lawyer** (when relevant): company A vs B, module-off 404, licensing state; dependency and copied-idea licences.

## Vocabulary
| Term | Meaning |
|---|---|
| avoir | credit note; states reason + corrected invoice |
| bon de livraison | delivery note; invoiced when an invoice consumes it |
| acompte | deposit invoice with own number and VAT, subtracted from final |
| timbre | fixed per-invoice stamp duty (TND) |
| retenue a la source (RS) | withholding at payment; TEJ = its e-certificate platform |
| FODEC | 1 % levy on listed TN industrial goods, enters VAT base |
| matricule fiscal | TN tax id | 
| SIREN/SIRET | FR company/establishment id (Luhn) |
| franchise en base | FR VAT exemption, art. 293 B |
| CA3/CA12 | FR VAT returns (CA3 only after 2027-01-01) |
| PA / PPF | FR plateforme agreee / public portal (directory + concentrator only) |
| TTN / El Fatoora / TEIF | TN e-invoicing network / portal / XML format |
| Z report | till closure by payment means and VAT rate |
| NF525 / attestation | FR till proof routes |
| homologation | TN Ministry approval of a register |
| preset / pack | per-country data: taxes, ids, mentions, rounding, formats |

## Canonical sources
- docs/fiscal/TN.md, FR.md (source lists: Code TVA, CGI, CIRPP/IS, Legifrance, BOFiP, finances.gov.tn, EN 16931-1:2017); docs/research/till-certification.md, till-hardware.md, tax-data-tunisia.md, tax-data-france.md; LICENSING.md; docs/SPEC.md §7-8. Read 2026-10-02 (digests of 2026-09-25 research). Unsourced entries stay [Unverified].

## Changes vs v2
- Added TEJ (mandatory since 2026-01-01), FR e-invoicing calendar, Factur-X, TTN/TEIF status, retention and FEC caveat.
- Added till training mode, fiscal-journal requirement, attestation/major-version/self-hoster-editor nuance (TC), hardware tools.
- Fixed: rounding is preset-only (not a setting); TTC entry refusal; credit note of paid invoice allowed; `withholdingCompleting` supersedes `withholdingAsCharged`.
- Added licensing/dual-licence dependency rule, subscription-from-dates invariant, stock valuation (uncertified), margin-unknown rule.
- Added ratified-vs-ASSUMED caution; mentions-refusal at issue; dropped lens "licence lawyer" as its own (merged); 5 lenses -> 4.
