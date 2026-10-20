# Tunisia (TN): fiscal rules behind the preset

Research round of 2026-09-13 (G3a). **Every rule below is `unvalidated`** until a Tunisian chartered accountant
(expert-comptable) confirms it; nothing here is legal advice. `api/config/fiscal/TN.yaml` is derived from this
file: a rule changes here first, with its source, and the preset follows in the same change. Where sources
disagree, both are cited and the preset's choice is stated.

## 1. Currency

| Rule | Preset | Source | Status |
|---|---|---|---|
| The dinar (TND) has three decimals (1 dinar = 1000 millimes) | `currency: TND`, `minor_unit: 3` | ISO 4217; Unicode CLDR through Symfony Intl (`Currencies::getFractionDigits('TND')` = 3) | unvalidated |

## 1a. Keeping records

| Rule | Preset | Source | Status |
|---|---|---|---|
| Every accounting document is kept ten years | `retention_years: 10` | Code de l'IRPP et de l'IS, art. 62 [19] | unvalidated |

The article does not say from when the ten years run, so a company closed down in the app is kept until 31 December of
the year it closed plus ten years, which is never shorter than the law whichever start applies. Unvalidated.

## 2. VAT (TVA)

| Rule | Preset | Source | Status |
|---|---|---|---|
| Rates: 19 % normal, 13 % intermediate, 7 % reduced | components `TVA19` (default), `TVA13`, `TVA7` | Code de la TVA art. 7, as amended by loi n° 2017-66 du 18 décembre 2017 (LF 2018) [1]; LF 2026 (loi n° 2025-17 du 12 décembre 2025) leaves them unchanged [5] | unvalidated |
| The VAT base is the price "tous frais, droits et taxes inclus … à l'exclusion de la TVA", so FODEC enters it | FODEC `enters_vat_base: true` | Code de la TVA art. 6-I [2] | unvalidated |
| Exports are outside VAT (no VAT charged) | customer regime `export` excludes the `vat` family | Code de la TVA art. 11 (exportations), cited in [1] | unvalidated |

**Source conflict.** The Ministry of Finance's overview page [9] still lists 18 %, 12 % and 6 %, the rates in force
before LF 2018. It is stale; the preset follows the amended code [1].

## 2a. When VAT is due, and when it is declared (row 112, 2026-09-25)

| Rule | What the software does | Source | Status |
|---|---|---|---|
| The taxable event of a sale of goods is the delivery; for successive orders it is the delivery, at the latest the invoice date | a delivered invoice's VAT counts in the month of its issue date | Code de la TVA art. 5-2 [17]; Ministère des Finances FAQ [18] | unvalidated |
| The taxable event of a service is its performance, or the receipt of the price or of an advance when that comes first | same as above: the issue date, not the performance or the receipt | Code de la TVA art. 5-3 [17] | unvalidated |
| Every VAT-registered business outside the régime forfaitaire declares monthly, even when nothing is due: within the first 15 days of the following month for a natural person, the first 28 days for a legal person | nothing yet: no declaration is produced (row 91) | Code de la TVA art. 18-I [12] | unvalidated |
| A natural person under IRPP art. 62 III ter declares quarterly, within the first 15 days of the month after each calendar quarter (LF 2023 art. 57) | not modelled | Code de la TVA art. 18 as amended by loi n° 2022-22 [12] | unvalidated |

What the home shows is therefore the VAT **on the invoices issued in the month**, labelled « TVA collectée »
(docs/SPEC.md § 7, 2026-09-24 11:40). For goods invoiced on delivery it is the month's due VAT; for a service invoiced
before its performance and before any payment it comes early, and for a service paid in advance of its invoice it comes
late. It is never called « à déclarer ».

## 2b. Advances (avances, acomptes) and the deposit invoice (row 208, 2026-10-07)

| Rule | What the software does | Source | Status |
|---|---|---|---|
| For a service, the receipt of the price or of an advance before the service is performed is the taxable event: VAT is due on the advance | built: a deposit invoice drawn from an accepted quote, carrying VAT on the advance, split over the quote's sets of taxes in proportion to each set's share of its net after the discount | Code de la TVA art. 5-3 [17] | unvalidated |
| For a sale of goods the taxable event is the delivery: an advance received before it is not a taxable event | built: the deposit invoice carries VAT on a goods advance too, which collects it earlier than the law requires, never later; an untaxed goods advance is a known gap | Code de la TVA art. 5-2 [17] | unvalidated |
| For building works, a provisional statement or partial invoice makes VAT due | not modelled (no works regime) | Code de la TVA art. 5-5-a [17] | unvalidated |
| Every VAT-registered business outside the régime forfaitaire issues an invoice for each operation it carries out, unless the contract stands as proof | built: the deposit invoice is an invoice, numbered in the invoice series, with the mentions of § 7; the final invoice subtracts each deposit as lines of its own, net and tax rate by rate | Code de la TVA art. 18-II [13] | unvalidated |
| A credit note of part of a final invoice gives no deposit back: the goods or services returned are credited at what they cost, the deposit staying taken off what is kept | built: only a credit note of every line the final invoice sold, each charged as invoiced, carries its deposit lines; one of part of it carries none, and a return worth more than the final invoice still owes is refused there, the rest being corrected by a credit note of the deposit invoice | the developer's ruling (docs/SPEC.md § 7, 2026-10-14 21:54); no text found | unvalidated |
| Money received beyond what the customer's invoices owe is not an advance on a supply | the customer's « trop-perçu », recorded only while no issued invoice of theirs is still due | follows from the rules above; no text found | unvalidated |

What is **not** found in a text: whether issuing an invoice that shows VAT on a goods advance makes that VAT due anyway
(France has such a rule; none was found here); whether the 1 TND stamp of § 4 is due on a deposit invoice as on any
invoice; and how FODEC (§ 3) applies to an advance on goods it covers. All three are open questions for the accountant.

## 3. FODEC (taxe professionnelle de compétitivité)

| Rule | Preset | Source | Status |
|---|---|---|---|
| 1 % of the price excluding taxes, for listed industrial products, due at the production and import stages | component `FODEC`, rate 1, **not** a default: it applies to listed products only, chosen per product at G5 | décret n° 2000-634 du 13 mars 2000 (product list) [6]; Note commune n° 13 (product list) [7] | unvalidated |
| FODEC enters the VAT base | `enters_vat_base: true` | Code de la TVA art. 6-I [2] | unvalidated |
| FODEC is rounded where VAT is rounded | follows `rounding.vat_point` | no source found; the preset's choice | unvalidated |

**Source conflict on the founding text.** Secondary sources name loi n° 88-145 du 31 décembre 1988 (LF 1989) as
amended, loi n° 94-127 du 26 décembre 1994, and art. 37 of loi n° 99-101 du 31 décembre 1999 for the computation
[6][8]. No primary text was readable in this round: the Ministry's compilation of taxes outside the codes [10] is a
scanned PDF. The rate and base above agree across every source read.

## 4. Stamp duty on invoices (droit de timbre)

| Rule | Preset | Source | Status |
|---|---|---|---|
| 1.000 TND per invoice, since 1 January 2023 (was 0.600) | component `TIMBRE`, `fixed_document`, amount 1.000, default | Code des droits d'enregistrement et de timbre art. 117 n° 6, as amended by décret-loi n° 2022-79 (LF 2023) art. 69; Note commune DGELF n° 02/2023 du 14 février 2023 [3] | unvalidated |
| The stamp is outside every tax base and untouched by every discount | calculator rule, pinned by pricing vectors | follows from its nature as a per-document duty; no explicit text found, and the VAT code's art. 6-I counts « tous frais, droits et taxes » in the base (VAT aside) [17], which could be read to take the stamp in: an accountant question | unvalidated |
| Large retail outlets pay 1.5 TND on invoices of 50 to 100 TND and 2 TND above | **not modelled** (ruled 2026-09-13; amount editable per company) | LF 2026 art. 20 [5] | unvalidated |
| Large retail (grandes surfaces, multi-department stores, franchises; in practice DGE/DME enterprises) pay **0.100 TND on each till receipt** (ticket de caisse), issued in a continuous series | a component of its own for the till, opt-in per company and **never an invoice default** (ruled 2026-10-04); built with the till module, not yet modelled | LF 2022 art. 54, new art. 135 bis of the Code des droits d'enregistrement et de timbre, in force 1 February 2022; press summaries only (Gnet, Tunisie Numérique, tustex, Tekiano) and the ministry's Note commune n° 15, not opened; a 50 TND threshold appeared in one draft-stage article and is not confirmed | unvalidated |

## 4a. Correcting an invoice: the stamp and a discount on a credit note

| Rule | Preset | Source | Status |
|---|---|---|---|
| A credit note (facture d'avoir) is itself an invoice subject to the stamp of art. 117-I 6°: « le droit de timbre couvre : toutes les factures y compris les factures partielles ainsi que les factures d'avoir » | `corrections.stamp: given_back_once`: the credit note completing the invoice gives its stamp back once, a partial one none (below; developer ruling, the treatment the texts leave open) | Note commune n° 6/2004 [20], commenting art. 76 of LF 2003-80, the text the 2025 code still cites as the source of n° 6's wording [21]; the duty was 0.200 then, 1.000 now (§ 4) | unvalidated |
| The invoice's stamp falls due when the invoice is issued, so a later credit note does not undo it; no text found provides for giving it back through a credit note | as above | Code des droits d'enregistrement et de timbre art. 119-I 7° « à l'émission pour les factures » [21] | unvalidated |
| Who bears the stamp on a credit note is not settled by a text: art. 129 makes everyone who drew up a stamped writing jointly liable, and says nothing of passing it on | as above | art. 129-I [21] | unvalidated |
| A discount on the whole document leaves the base of the lines it was shared over; a credit note of part of the lines gives back their share, not the whole discount, because the base is the price actually charged | built: a credit note gives back its share of the document discount by its lines' base and of each line's discount amount by quantity, the completing one what is left (below) | Code de la TVA art. 6-I, the base is « le prix des marchandises, des travaux ou des services » [17]; the sharing itself is the calculator's (§ 10), not a text's | unvalidated |

**What the 2004 note covers.** Its scope sentence reads « Le droit de timbre fixé à 0.200 dinars prévu par le n° 6 du
paragraphe I de l'article 117 … s'applique aux factures et documents en tenant lieu utilisés par les professions
libérales ainsi que par les entrepreneurs de bâtiments et de travaux », and the list that follows opens with « toutes les
factures y compris les factures partielles ainsi que les factures d'avoir » before the documents of those professions.
It is read here as covering every invoice, the professions' documents being added to them; an accountant should confirm
that reading.

**Two readings left open.** Art. 118-13° exempts « les factures des commerçants non acceptées par les débiteurs ou non
acquittées par ces derniers » [21]: read literally it would free an invoice the customer refuses or never pays, against
art. 119-I 7°, which makes the duty due at issue. Art. 74, which makes duties « devenus restituables suite à la survenance
d'un événement postérieur » restituable, sits in the registration part of the same code [21]; whether it reaches a stamp
paid on an invoice later credited was not found. Nothing is built on either.

**What twes-in does, until an accountant reads this section.** The preset says `corrections.stamp: given_back_once`: a
credit note gives the invoice's stamp back once at most, all of it on the credit note completing the invoice (the one
crediting at least what the invoice's lines came to, less what its credit notes already issued credited), and none on a
partial one, whose editor says under the charges that the completing credit note gives it back (the preview names
why a chosen stamp is left out, so a note of an invoice that charged none promises nothing). That keeps the full
refund the product made before, bounded so the stamp is never given back twice; the
other value, `kept`, never gives it back. A credit note gives back its share of the document discount by its lines'
base, and each line naming the invoice line it credits gives back its share of that line's discount amount by quantity;
the credit note completing the invoice, or the line, takes what is left, so the credit notes add up to the invoice's
discount exactly and never to more. A credit note takes no document discount of its own. Developer ruling, unvalidated.
Each percentage tax goes the same way: the invoice rounds a rate's tax once over its lines and each credit note rounds
its own, so the credit notes of its lines one by one could come to a millime more than it charged and the last be
refused; the credit note completing the invoice takes what is left of each tax instead, where the difference is no
more than that rounding (one smallest unit a credit note), and a credit note taxing other figures keeps its own.
Arithmetic, not a text: unvalidated.

## 5. Withholding on payments (retenue à la source)

| Rule | Preset | Source | Status |
|---|---|---|---|
| 1 % of amounts of 1000 TND or more, VAT included, paid by the State, local authorities, legal persons and individuals under the real regime | component `RS1`, `withholding_total`, rate 1, threshold 1000.000, **not** a default: it depends on the customer being a withholding payer (G4, G7) | Code de l'IRPP et de l'IS art. 52-I; rate lowered from 1.5 % to 1 % by loi n° 2020-46 du 23 décembre 2020 (LF 2021) art. 14 [4] | unvalidated |
| The threshold and the base are the amount including VAT; the stamp duty is **not** part of either | calculator rule: base = total of lines and percentage taxes, fixed charges excluded | "y compris la TVA" is in the text [11]; nothing found on the stamp, so this is the preset's choice | unvalidated |
| A credit note of an invoice that withheld withholds at the same rate, whatever the credit note's own total, so the credit notes of an invoice add up to it exactly | calculator rule: a correction takes the withholdings its document charged, threshold aside, and the one completing the invoice's withholding base takes what is left of its amount instead of rounding a share of its own | nothing found on correcting a withheld invoice; this is the preset's choice | unvalidated |
| Exclusions: subscriptions, insurance contracts, leasing and Islamic finance contracts, price-controlled products, purchases from farmers and fishers | not modelled (customer and product level) | art. 52-I as shown by [11] | unvalidated |
| Reduced rates of 0.5 % apply to some suppliers (for example those taxed at the 10 % corporate rate) | not modelled; the rate is editable per company | [11] | unvalidated |

**Source conflict.** The article 52 mirror at 9anoun.tn [11] shows 1.5 % as the standard rate and cites LF 2013 as the
latest amendment; four secondary sources on LF 2021 [4] report the reduction to 1 %. The mirror looks stale; the
preset uses 1 %.

### 5a. When the company pays a supplier (row 111, 2026-09-25)

The same article binds the company as a PAYER: a legal person, or an individual under the real regime, withholds on
what it pays for goods, equipment and services from 1000 TND VAT included, and hands the supplier the rest [4] [11].

| Rule | Modelled as | Source | Status |
|---|---|---|---|
| Paying a supplier 1000 TND or more, VAT included, withholds 1 % | an expense's payment withholds the preset's one active `withholding_total` component (`RS1`, rate 1, threshold 1000.000) when its gross reaches the threshold; `withholding_rate`, `withholding_amount` and the amount paid are kept on the expense | art. 52-I; LF 2021 art. 14 [4] [11] | unvalidated |
| The rate depends on the SUPPLIER: 1 % for one whose profits bear the 15 % corporate tax, 1.5 % in the article's general case, 0.5 % for one at the 10 % rate or with the two-thirds deduction | the payment says its own rate, which replaces the default; "0" withholds nothing (an excluded purchase, an exempt supplier) | [11]; the 0.5 / 1 / 1.5 split is also reported by [16] | unvalidated |
| The threshold is compared with the amount VAT included, and the withholding is taken on it | the gross of the expense (its lines, their FODEC and their VAT) less its fixed charges: the stamp is in neither the threshold nor the base | "y compris la taxe sur la valeur ajoutée" [11]; one secondary source [16] says the base is the amount before VAT, contradicting the article's text; the stamp's place is not found in a text (accountant question) | unvalidated |
| A bill at several VAT rates is withheld rate by rate | the payment withholds on each VAT rate's part taxes included (its base, FODEC inside it, plus its VAT) and rounds each part; the expense's withholding is their sum, and its TEJ certificate carries one operation a rate | TEJ cahier des charges, September 2026 § 7.3.2.4: one `TauxTVA` an operation, `MontantRS = MontantTTC × TauxRS` [22] | unvalidated |
| Other natures: fees 3 %, commercial rents 15 %, and others | **not modelled**: an expense does not say its nature; the rate is said on the payment | [16], not checked against the code | unvalidated |
| The certificate of withholding given to the supplier, and the monthly declaration | the month's TEJ file (`DeclareTejWithholdings`), one certificate a payment and one operation a VAT rate of its bill; the stamp has no place in it, so what the supplier is handed exceeds the declared net served by the stamp | TEJ cahier des charges, September 2026 [22]; FODEC inside `MontantHT` and the stamp left out are an accountant question | unvalidated |

## 6. VAT withholding by public buyers (art. 19 bis): deferred

The State, local authorities and public establishments and enterprises withhold 25 % of the VAT on purchases of
1000 TND or more, VAT included, since 1 January 2016, with exceptions (utilities, leasing, microfinance,
price-controlled products) [12]. Its base is the VAT amount, which none of the three tax kinds expresses. **Deferred
by ruling of 2026-09-13**; the revisit is a fourth kind when public-sector invoicing is in scope.

## 7. What an invoice must carry

Code de la TVA art. 18-II [13]: the date; a serial number from an uninterrupted series; the name, address and tax
identifier of the seller; the name and address of the customer and, with exceptions, its tax identifier; the
designation and the price excluding VAT of each item; the VAT rates and amounts; and, for operations under VAT
suspension (loi n° 2006-85), the suspended VAT amount. These are document fields printed at G6 and G7. The preset
lists them as translation keys under `mentions`, and each customer regime names its own mention. **The wording of
the regime mentions is unsourced.**

The total written out in words beneath the figures (« Arrêtée la présente facture à la somme de … ») is a custom of
Tunisian invoices, not a requirement found in art. 18-II: the preset turns `document.amount_in_words` on by default
under `settings`, and a company may turn it off. Status: custom, unsourced as law.

## 8. Tax identifier (matricule fiscal)

| Rule | Preset | Source | Status |
|---|---|---|---|
| Seven digits, a check letter, the VAT code, the category code and a three-digit establishment number: `1234567A/B/M/000` | identifier `matricule_fiscal`, pattern `^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$`, required for the company and business customers | secondary sources only [8] | unvalidated |
| The establishment number is the establishment's code | `establishment.code_pattern` `^[0-9]{3}$`; a company's first establishment starts as `000`, which the company corrects if its matricule says otherwise | same | unvalidated |
| The check letter has an algorithm | **not implemented** (no primary source) | none | unvalidated |

The preset's `example` for the matricule is `1234567A/B/M/000`, the shape above, which an import guide shows. A
customer's or supplier's matricule is kept without spaces and in capitals (« 1234567 a / b / m / 000 » is read as
`1234567A/B/M/000`). A customer file may bring a business customer without its matricule: the customer is created with a
note in the preview, and the matricule is still required on the invoice.

## 9. Customer tax regimes

| Code | Excludes | Mention | Status |
|---|---|---|---|
| `standard` | nothing | none | unvalidated |
| `exempt` | the `vat` family | `fiscal.mention.tn.exempt` | unvalidated |
| `suspended` | the `vat` family | `fiscal.mention.tn.suspended` (loi n° 2006-85) | unvalidated |
| `export` | the `vat` family | `fiscal.mention.tn.export` | unvalidated |

Whether FODEC applies to exempt, suspended or export sales was not established in this round. The regimes exclude
VAT only.

## 9a. Company VAT regimes

| Rule | Preset | Source | Status |
|---|---|---|---|
| A person under the income tax's « forfaitaire » regime (art. 44 bis of the Code de l'IRPP et de l'IS) is outside VAT unless it opts to become liable for all its activity | company regime `forfaitaire` excludes the `vat` family | Code de la TVA art. 2-I-3 [17] | unvalidated |
| Whoever writes VAT on an invoice owes it from that alone, so a forfaitaire company's documents charge none | the regime removes VAT from every line; a line naming it is refused | Code de la TVA art. 2-I-2 [17] | unvalidated |
| The invoice duty of art. 18-II is laid on liable persons « autres que ceux soumis au régime forfaitaire »; nothing forbids a forfaitaire from issuing one | the company still issues its documents, under the same numbering | Code de la TVA art. 18-II [17] | unvalidated |
| VAT on goods sold by a person under the art. 44 bis tax gives its buyer no deduction | the mention `fiscal.mention.tn.forfaitaire` says so on each document | Code de la TVA art. 10-2 [17] | unvalidated |
| Only a liable person deducts the VAT it paid, so a forfaitaire company's purchase cost is the price with its tax | an import's cost given with its tax is kept as it is under `forfaitaire`, brought to the price before tax otherwise | Code de la TVA art. 9-I [17]: « les assujettis imputent » the VAT on their purchases (the cost that follows is inferred) | unvalidated |

Not established: whether FODEC (§ 3) and the stamp duty of § 4 fall on a forfaitaire's documents (the regime removes
VAT only, so both stay as the products and the document carry them), the mention's
wording (no text prescribes one), and which turnover keeps a retailer in the regime (art. 44 bis's thresholds, not read).

## 10. Rounding

No official rounding rule was found. The preset uses half-up to the millime and rounds each tax once per rate group
(`per_rate_group`), the order EN 16931 BR-CO-17 prescribes for a VAT category [14]. A company may choose `per_line`
once the settings engine carries the choice (G3b). Unvalidated.

## 11. Numbering and languages

- Invoices are numbered from an uninterrupted series (art. 18-II) [13]. The preset's defaults are one series per
  document type, written `FAC-{YYYY}-{MM}-{SEQ:5}`, `AV-…` and `BL-…` (ruled 2026-10-04), the sequence reset every year, so
  the month is printed but never restarts the count. A company changes the format in the app. Unvalidated.
- The preset's document language is French. Arabic is not in the POC.

## 12. Out of POC scope: El Fatoora e-invoicing

Electronic invoicing through Tunisie TradeNet (TTN) with an ANCE signature was mandatory for companies under the
Direction des grandes entreprises dealing with the State and public bodies, and for sales of medicines and
hydrocarbons. LF 2026 art. 53 extends it to all provision of services from 1 January 2026. Fines are 100 to 500 TND
per paper invoice, capped at 50,000 TND, and 250 to 10,000 TND for missing mentions [5]. Unvalidated; e-invoicing
comes after the POC (docs/SPEC.md § 2).

## Known gaps

- Art. 19 bis VAT withholding by public buyers (§ 6), deferred.
- LF 2026 stamp brackets for large retail (§ 4), not modelled.
- Tax-inclusive entry with FODEC and VAT on one line, refused by the calculator (ruling of 2026-09-13).
- Withholding exclusions and reduced rates (§ 5), not modelled.
- On supplier payments (§ 5a): the withholding by nature (fees, rents), not modelled; whether FODEC belongs in a TEJ operation's `MontantHT` and the stamp outside every operation and the withholding base, not found in a text.
- The monthly declaration itself and its forms (§ 2a), not produced; the declaration of a régime forfaitaire, not researched.
- Matricule check letter (§ 8), not verified.
- Regime mention wording (§ 7, § 9, § 9a), unsourced; the stamp on a forfaitaire's invoice and the regime's thresholds (§ 9a), not read.
- Credit notes (§ 4a): who bears a credit note's own stamp, whether a refused or fully credited invoice's stamp is ever given back (art. 118-13°, art. 74), and whether art. 6-I's « droits et taxes » takes the stamp into the VAT base (§ 4).
- Advances (§ 2b): VAT shown on a goods advance, the stamp on a deposit invoice and FODEC on an advance, not found in a text; an untaxed goods advance (art. 5-2) is not offered.

## Sources

1. Code de la TVA, art. 7 (rates), jurisitetunisie mirror: https://www.jurisitetunisie.com/tunisie/codes/tva/tva1035.htm
2. Code de la TVA, art. 6 (base): https://www.jurisitetunisie.com/tunisie/codes/tva/tva1030.htm
3. Note commune DGELF n° 02/2023 (stamp duty of 1 dinar on invoices), read from the jurisitetunisie PDF.
4. LF 2021 withholding changes: https://www.ilboursa.com/marches/les-taux-de-la-retenue-a-la-source-applicables-en-2021_25887 ; https://cktaudit.com/les-taux-des-retenues-a-la-source/ ; https://swiver.io/blog/retenues-a-la-source/ ; http://kapitalis.com/tunisie/2020/12/28/document-les-principales-dispositions-de-la-loi-de-finances-2021/
5. InFirst Auditors, commentary on LF 2026 (art. 20 stamp brackets p. 20, art. 53 El Fatoora pp. 30–31).
6. FODEC overview: https://finco.tn/blog/fodec-tunisie-guide-complet ; https://swiver.io/blog/fodec/
7. Note commune n° 13 (FODEC product list): https://jibaya.tn/docs/note-commune-numero-13-fixation-de-la-liste-des-produits-soumis-a-la-taxe-professionnelle-au-taux-de-1-au-profit-du-fonds-de-developpement-de-la-competitivite-dans-les-secteurs-indust/
8. Digest de la fiscalité tunisienne: http://www.profiscal.com/Impot_en_Tunisie/Digest5.htm
9. Ministère des Finances, overview (stale rates): https://www.finances.gov.tn/fr/apercu-general-sur-la-fiscalite
10. Recueil des textes relatifs aux droits et taxes non incorporés dans les codes fiscaux (2017): https://www.finances.gov.tn/sites/default/files/RECUEIL%20DES%20TEXTES%20RELATIFS%20AUX%20DROITS%20ET%20TAXES%20NON%20INCORPORES%20DANS%20LES%20CODES%20FISCAUX%202017.pdf
11. Code de l'IRPP et de l'IS, art. 52, 9anoun mirror: https://9anoun.tn/fr/kb/codes/code-impot-sur-revenu-personnes-physiques-impot-sur-les-societes/code-impot-sur-revenu-personnes-physiques-impot-sur-les-societes-article-52
12. VAT withholding (art. 19 bis): https://www.finances.gov.tn/fr/node/905 ; https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm
13. Code de la TVA, art. 18 (invoices), jurisitetunisie mirror of the code.
14. EN 16931-1:2017, business rule BR-CO-17 (VAT category tax amount).
15. Note commune n° 6/2025 (LF 2025 art. 68, 3 % withheld by delivery services from sellers without a tax card, not modelled): https://jibaya.tn/wp-content/uploads/2025/03/Note-Commune-N%C2%B006.pdf
16. Hesabi, « Taux de retenue à la source en Tunisie 2026 » (secondary): https://hesabi.tn/actualites/taux-retenue-source-tunisie-2026
17. Code de la TVA, art. 5 (fait générateur) and art. 6-I (base), read from the 2024 consolidated code: https://alliance-tunisie.com/wp-content/uploads/2024/06/CODE-DE-LA-TVA-2024.pdf ; and from the ministry's edition « mis à jour au 1er janvier 2023 » (art. 5 and 18-II): https://jibaya.tn/wp-content/uploads/2024/02/Code-de-la-taxe-sur-la-valeur-ajoutee-2023_compressed.pdf
18. Ministère des Finances, FAQ « Quel est le fait générateur de la TVA en cas de vente de marchandises (cas des commandes successives) ? »: https://www.finances.gov.tn/fr/node/895
19. Code de l'IRPP et de l'IS, art. 62, 9anoun mirror: https://9anoun.tn/fr/kb/codes/code-impot-sur-revenu-personnes-physiques-impot-sur-les-societes/code-impot-sur-revenu-personnes-physiques-impot-sur-les-societes-article-62 (read through docs/research/tax-data-tunisia.md § 3).
20. Note commune n° 6/2004 (Texte DGI 2004/07, Bulletin officiel des douanes et des impôts n° 2004/01/05), commenting arts. 76 and 77 of LF 2003-80, read from an audit firm's copy: https://www.exacomaudit.com/docs/notes-communes-tunisie/2004/Note-Commune-N-06-2004.pdf
21. Code des droits d'enregistrement et de timbre, DGELF edition of 2025 (arts. 74, 117-I 6°, 118-13°, 119-I 7°, 129): https://jibaya.tn/wp-content/uploads/2025/04/Code-des-Droits-dEnregistrement-et-de-Timbre-2025.pdf
22. Cahier des charges des certificats de retenue à la source TEJ, September 2026, § 7.3.2.4: https://jibaya.tn/wp-content/uploads/2026/09/cahier%20des%20chargesRS_TEJ_09_26.pdf (read through docs/research/tax-data-tunisia.md § 2.2).
