---
name: domain-twes-business-fiscal
description: Use when a task touches invoices, credit notes, delivery notes, VAT/FODEC/timbre/retenue, TN or FR fiscal presets, numbering, stock valuation, subscriptions/licensing, tills/cash registers, TEJ/Factur-X/TEIF e-invoicing in twes-in. How an expert in small-business invoicing and POS compliance for Tunisia and France works: rules, sources, evidence, failure modes, reviewer lenses.
---
Review date: 2026-11-02 22:30   Validation mode: advisory   Core: .claude/rules/expertise-core.md (its fiscal invariants are not repeated here)

<!-- Tags: [TN]=docs/fiscal/TN.md, [FR]=FR.md, [TC]=docs/research/till-certification.md, [SPEC]=docs/SPEC.md §7 date, SPEC.md:N = docs/SPEC.md line N, [R]=research doc. Last checked = oldest re-read of a row's TN/FR/R/TC source; SPEC cites all read 2026-11-02. Every fiscal rule is UNVALIDATED by an accountant: hypothesis, cite, never assert. Only AGREED SPEC entries are rulings. -->

## Roles and mental models
- **Expert-comptable (TN/FR)**: only authority that validates a rule; until then each rule is a hypothesis with article and version date. Open questions go to docs/fiscal/TN-accountant-packet.md (Q24 etc.).  [TN §1; Source: CLAUDE.md « Where things live »]
- **Shop owner (quincaillerie)**: counter sales, restocking, bon de livraison then invoice; lives on stock truth, margin, "who owes me".  [SPEC 2026-09-24]
- **Workshop owner (tourneur)**: quote, deposit invoice, job (material + hours), delivery note + invoice.  [SPEC 2026-09-14]
- **Cafe/till operator**: counter sale, shift float, Z report by payment means and VAT rate; a till is a regulated device.  [TC]
- **Tax inspector**: reads the printed invoice (mentions, numbering, stamp, withholding); asks for archive and trail.  [Unverified as practice]
- **Mental models**: presets are DATA, tax kinds a closed set in code; an issued document is frozen and answered from columns; a guard that feels too strict points at a missing feature (overpay -> credit balance, short-pay -> write-off credit note).  [SPEC 2026-09-13, 09-21]

## Standards, laws, norms
| Rule | Applies when | Source | Last checked |
|---|---|---|---|
| TVA TN 19/13/7 %; base includes FODEC; Ministry page STALE (18/12/6). Regime « forfaitaire » in the TN preset: no VAT charged, purchase costs kept with their tax, chosen in the profile | TN lines | TN §2, §9a; Ruled: user 2026-10-20 21:34, SPEC.md:5540 | 2026-09-13 |
| FODEC 1 % ex-tax, listed products only, never default, enters VAT base | TN listed goods | TN §3 | 2026-09-13 |
| Timbre 1.000 TND per invoice (CDET art. 117, LF 2023 art. 69), outside bases and discounts; large-retail 1.5/2 TND (LF 2026 art. 20) NOT modelled; 0.100 TND till-receipt duty separate opt-in. On credit notes: preset key `corrections.stamp` covers EVERY fixed charge per document (not a TIMBRE property); the preview says why a fixed charge is left out (completing note / kept by preset / never charged) | every TN invoice | TN §4, §4a; Ruled: user 2026-10-14 19:03, 2026-10-15 00:23, SPEC.md:5204, 5221 | 2026-10-02 |
| Retenue 1 % at >= 1000 TND VAT incl. (CIRPP/IS art. 52-I, LF 2021 art. 14; was 1.5 %); not default; fixed charges (stamp included) in neither threshold nor base; threshold inclusive on absolute value; reduces only amount due. Credit notes of a withheld invoice sum exactly to it (`withholdingCompleting`) | withholding customer | TN §5; SPEC 2026-09-13, 09-16 | 2026-11-02 |
| Company as PAYER: supplier payment >= 1000 TND withholds 1/1.5/0.5 % by supplier, payment states its rate, "0" = none; proposed only from the ONE active whole-amount component, else none. Expense: amount lines on the invoices' calculator, entry from TTC worked back; a typed bill amount accepted within 0.001 per tax line and recorded as typed | expenses TN | TN §5a; Ruled: user 2026-10-14 19:19, 2026-10-15 20:00, SPEC.md:5206, 5286 | 2026-11-02 |
| TEJ: withholding certificates MANDATORY e-file (since 2026-01-01; fine 30 %, min 50 TND/cert); XML + XSD, `[matricule]-[YYYY]-[MM]-[acte].xml`, amounts in millimes, web upload only. TEJ code only under the TN preset; an individual by CIN: source the TEJ identifier types in TN.md first, until then say so on the vendor | TN expenses | R tax-data-tunisia §2.2; Ruled: user 2026-10-15 17:02, 20:00, SPEC.md:5266, 5285 | 2026-09-25 |
| VAT recognition: goods on delivery; services on performance/receipt (TN), receipt unless débits (FR); "TVA collectée" + footnote « sur les factures émises » until the cash basis is modelled. A draft invoice holding an earlier month's delivery notes says so (draft + « À surveiller »): tax owed from delivery | home tile, reports | TN §2a, FR §2a; Ruled: user 2026-10-15 20:00, 2026-10-11 11:00, SPEC.md:5293, 5023 | 2026-11-02 |
| TVA FR 20/10/5.5/2.1 %; franchise "TVA non applicable, art. 293 B du CGI" (CIBS renumbering 2027-01-01) | FR | FR §2-3 | 2026-09-13 |
| Mentions: TN art. 18-II TVA; FR CGI ann. II 242 nonies A, C. com. L441-9/D441-5; FR adds customer SIREN, operation category, débits option, delivery address (décret 2022-1299). `vatOnDebits` and « nature des opérations » shapes are ASSUMED (SPEC.md:4907-4910), not rulings | printed docs | TN §7, FR §4 | 2026-09-25 |
| FR e-invoicing: receive 2026-09-01 via a plateforme agréée; issue GE/ETI 2026-09-01, PME/micro 2027-09-01; PDF by mail is NOT an e-invoice; 50 EUR/invoice, cap 15,000 EUR/yr | FR B2B | R tax-data-france §1 | 2026-09-25 |
| Factur-X EN 16931 CII (PDF/A-3b), credit note type 381 positive amounts, XML from stored figures; refused in a demo sandbox (« Non disponible dans la démo »); the « DÉMO — sans valeur fiscale » banded PDF still downloads; fiscal XML schema validation is release scope | issued FR docs | SPEC 2026-09-25; Ruled: user 2026-10-17 22:22, 2026-10-31 20:55, SPEC.md:5365, 5366, 5749 | 2026-11-02 |
| TN El Fatoora/TTN: TEIF 1.8.8, XAdES-B; connector NOT built (no TTN spec/test account) | TN B2B | R tax-data-tunisia; SPEC 2026-09-20 | 2026-09-25 |
| Identifiers: TN matricule checked part by part (check letter not I/O/U), stored `1234567A/A/M/000`, « Ex. … » under every field; detail in domain-identifiers | company, customers | Ruled: user 2026-10-20 22:38, SPEC.md:5545 | 2026-11-02 |
| Rounding in the PRESET (`half_up`, `per_rate_group` = BR-CO-17); each figure rounded once at currency scale; BcMath; TTC shelf price 1.001 × 3 = 3.004: Q24 (core) | every total | TN §10, FR §7; Ruled: user 2026-10-22 19:34, SPEC.md:5629 | 2026-09-13 |
| Preset = typed, versioned contract (schema, effective dates, checked against the vectors); operator editor after the pilot; time zone from the preset (Africa/Tunis, Europe/Paris), creation sends name + country only; `taxBasis` a preset default overridable where the preset allows; no stable tag by 2026-12-15 -> interim patch with only the TN 2027 finance-law values | presets | Ruled: user 2026-10-11 23:52, 2026-10-29 19:48, 2026-10-31 21:14 (Q20), 20:40; SPEC.md:5048, 5728, 5755, 5747 | 2026-11-02 |
| Tax composition: levy into VAT base first; global discount its own row (BT-92/95), pro rata AFTER line discounts; TTC entry refused with >1 percentage tax or levy-in-base | pricing | SPEC 2026-09-13, 09-21 | 2026-11-02 |
| Numbering gapless per type and establishment under concurrency, yearly reset default, closed period refuses back-dated; imported past invoices never enter a series; numbered at « Émettre » (invoice), « Valider » (BL), « Envoyer » (quote), never (expense: vendor's ref). Back-dating: Q7 month grain (core) | issue | TN §11, FR §8; Ruled: user 2026-10-11 11:00, 2026-10-31 21:14 (Q7), SPEC.md:5023, 5755 | 2026-09-20 |
| Hash chain (core): backfilled in issue order; Factur-X unchanged; the nightly data check reads numbers in issue order, a restart at one only where a month begins, plus six arithmetic identities; stock left out by construction; a failing chain-sealing start step only warns and reruns | issued invoices | Ruled: user 2026-10-12 01:38, 07:53, 04:03, 2026-11-01 21:13; SPEC.md:5067, 5068, 5081, 5071, 5763 | 2026-11-02 |
| Customer-facing price is the tax-inclusive price charged (TN Law 2015-36 art. 29; FR arrêté 3 Dec 1987 UNREAD) | counter, portal | SPEC 2026-09-24 | 2026-11-02 |
| Legal mention unfillable for the customer's regime => issuing REFUSED naming the datum | issue | SPEC 2026-09-21; TN §7 | 2026-09-21 |
| Credit note: reason + corrected invoice (BG-3), <= 500 chars, immutable, « définitif » (corrected only by another document). Each credit line names the invoice line; 3 of 10 at 100.000 with a 50.000 discount credits 285.000, the completing note takes the rest | credit notes | SPEC 2026-09-24; Ruled: user 2026-10-14 18:07, 2026-10-15 20:00, SPEC.md:5202, 5280 | 2026-11-02 |
| Printed names: units, taxes, fiscal labels keep a name per printed language; issued doc snapshots them (company setting may keep them live); EUR invoice in Arabic drops amount-in-words; cancelled watermark by type (« SANS SUITE », « ANNULÉE », « ANNULÉ ») | PDFs | Ruled: user 2026-10-18 21:20, 2026-10-19 12:01, 2026-10-16 17:40; SPEC.md:5376, 5401, 5404, 5315 | 2026-11-02 |
| Retention: TN 10 yrs (CIRPP/IS art. 62); FR tax 6 (LPF L102 B), accounting 10 (L123-22); FR piste d'audit fiable (CGI 289 VII); FEC-layout "journal", never "FEC". A yearly sealed archive off-site 10 yrs + USB copy| archive | R tax-data-* §3; Ruled: user 2026-10-31 21:14 (Q11), SPEC.md:5755 | 2026-09-25 |
| FR till: CGI 286 I 3° bis (loi 2026-534 art. 87): certificate (NF525, LNE) OR editor's attestation (BOI-LETTRE-000242, LF 2026 art. 125); 7,500 EUR/system; a self-hoster modifying code becomes the "editor". Training mode (FR/TN alike): journalled and chained, flagged, excluded from Z | B2C till | TC | 2026-10-02 |
| TN till: on-site food/drink only; HOMOLOGATED register, MF connection, QR; CDPF art. 94; twes-in path BLOCKED | cafes TN | TC; décret 2019-1126 | 2026-09-25 |
| Stock valuation: weighted average (UNCERTIFIED); unknown cost UNKNOWN never zero; no-cost stock valued at today's cost « estimé »; as-of date + year-end export, accountant template gets `stock.read` + `product.cost.read`; « Annuler ce mouvement » counter-movement corrects the average | stock, margin | SPEC 2026-09-25; Ruled: user 2026-10-15 20:00, SPEC.md:5283, 5284 | 2026-11-02 |
| Purchase cost = paid after every discount; VAT out when the regime recovers it (preset fact); « FODEC dans le coût » on, « Timbre dans le coût » off. A cost change: setting keep price / offer (default, « Marge : 35 % → 30 % ») / keep margin | receipts | Ruled: user 2026-10-20 01:43, 2026-10-21 00:36; SPEC.md:5459, 5553 | 2026-11-02 |

## Tools of the trade
- `docs/spec/pricing-vectors.json` + `BcMath\Number` calculator: proves arithmetic and rounding; a rule change = vector change.  [SPEC 2026-09-13]
- Presets `api/config/fiscal/<CC>.yaml` + country-pack conformance test: "new country = data".  [SPEC 2026-09-20]
- `app:data:check` (nightly `DataHealth`, « Chaîne des factures »).  [Ruled: user 2026-10-12 07:53, SPEC.md:5080]
- Official XSDs: TEJ, TEIF 1.8.8, EN 16931 CII; PA sandboxes for FR.  [R tax-data-*]
- Portals: Legifrance, BOFiP, JORT, finances.gov.tn. Till hardware: REFERENCE §15.

## What "good" looks like (acceptance criteria)
- Vectors green incl. TND 3 decimals and per-rate-group rounding; no float on money.
- Rule change: docs/fiscal/<CC>.md (sourced, unvalidated) AND preset AND vector in ONE change; a new country touches no code.
- Issued invoice: figures frozen, seller and customer snapshots; `amount_due = total_gross - withholding - paid - credited`; no edit path.
- PDF: every preset mention, regime mention, DRAFT watermark on drafts, paid stamp only on a dated « COPIE »; signature legend the company picks (« Reçu par » default, off by default).  [Ruled: user 2026-10-16 17:40, SPEC.md:5316]
- BL from an issued invoice linked line by line, « Livré · facturé », never in « Facturer »; invoiced before it left: « Livraison non enregistrée ».  [Ruled: user 2026-10-16 17:40, 2026-10-26 20:49; SPEC.md:5318, 5684]
- Subscriptions from dates, never a job; no subscription = full access said « Non géré : accès complet », no default trial; plans and add-ons are dated data versions, a subscribed company keeps its price.  [Ruled: user 2026-10-23 03:31, 2026-10-12 00:23; SPEC.md:5637, 5057]
- Stock = sum of signed movements; BL validated -> out, cancelled -> back (`app:stock:replay-delivery-note`); module off = 404, data kept; 422 names every Factur-X gap.  [SPEC 2026-09-14, §8]
- Till claims: never "compliant"; FR needs certificate or attestation plus a hash-chained journal; TN stays blocked.

## Classic failure modes
- Rate/threshold from memory; stale sources (Ministry TN page, 9anoun art. 52 mirror) -> check article + amending law date.  [TN §2, §5]
- Stamp inside a base or discount; FODEC/withholding by default -> vector with stamp + discount.
- "TVA à déclarer" on issued invoices -> translation test bans it.  [SPEC 2026-09-25]
- Rounding per line where the preset says per rate group -> mixed-rate vector.
- Partial credit note prorating wrong -> vector "3 of 10 -> 285.000".  [Ruled: user 2026-10-14 18:07, SPEC.md:5202]
- Mention silently dropped (`%` placeholder in twig) -> render the issued PDF per regime and read it.  [SPEC 2026-09-21]
- Credit notes of a withheld invoice off by one millime -> sum == invoice withholding.
- Margin read as 0 when cost unknown.
- A fiscal fix made before it is sourced in TN.md.  [Ruled: user 2026-10-14 15:13, SPEC.md:5190]
- Claiming till/e-invoicing compliance; calling a FEC-layout journal "FEC".  [TC, R]

## Evidence surfaces (what counts as proof here)
- Money/tax: vectors + unit tests per tax component and state transition; Functional tests (happy, validation, unauthenticated, wrong company); Playwright on the real stack.
- A test's day is the company's (`ApiTestCase::dayOf`): Tunis is on the next day from 23:00 UTC; read the clock once per test.  [the Lessons (CLAUDE.md and `.claude/rules/`) 2026-10-21, 2026-10-26]
- Stock lock under concurrency: race a delivery note against an INVOICE (two series); two notes are serialized by the series' row lock.  [the Lessons (CLAUDE.md and `.claude/rules/`) 2026-11-01]
- Printed output: the RENDERED PDF per regime/language, looked at.
- Machine files: XML validated against the official XSD / schematron; round-trip from stored figures.
- Fiscal claim: primary source + law-version date; "unvalidated" until an accountant confirms. Till/TTN claims without device or test account are [Unverified].
- Not proof: "tests pass" alone for a rule change, a memory of a rule, a reviewer reading a diff.

## Reviewer lenses (for 3C/6C and the milestone panel)
1. **The accountant**: each rule sourced, current, rounded per preset, unvalidated; vector exists.
2. **The inspector**: gapless numbering, chain intact, mentions printed, frozen documents, credit-note trail, withholding sums, retention, no overclaim.
3. **The shop owner at 6 pm**: a sale, delivery or stock correction in few steps on a phone/till; stock and margin truthful.
4. **The tenant attacker**: company A vs B, module-off 404, subscription state.

## Vocabulary
| Term | Meaning |
|---|---|
| avoir | credit note; reason + corrected invoice |
| bon de livraison | delivery note; invoiced when an invoice consumes it |
| acompte | deposit invoice, own number and VAT, subtracted from final |
| timbre | fixed per-invoice stamp duty (TND) |
| retenue à la source (RS) | withholding at payment; TEJ = its e-certificate platform |
| FODEC | 1 % levy on listed TN goods, in VAT base |
| forfaitaire | TN regime charging no VAT |
| empreinte | printed short form of the invoice's chain hash |
| SIREN/SIRET | FR company/establishment id |
| franchise en base | FR VAT exemption, art. 293 B |
| PA / PPF | FR plateforme agréée / public portal |
| TTN / El Fatoora / TEIF | TN e-invoicing network / portal / XML |
| Z report | till closure by payment means and VAT rate |
| NF525 / attestation | FR till proof routes |

## Canonical sources
- docs/fiscal/TN.md, FR.md, TN-accountant-packet.md; docs/research/till-certification.md, till-hardware.md, tax-data-tunisia.md, tax-data-france.md; LICENSING.md; docs/SPEC.md §7-8 (read to SPEC.md:5767 on 2026-11-02). Unsourced entries stay [Unverified].
