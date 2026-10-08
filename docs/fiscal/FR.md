# France (FR): fiscal rules behind the preset

Research round of 2026-09-13 (G3a). **Every rule below is `unvalidated`** until a French chartered accountant
(expert-comptable) confirms it; nothing here is legal advice. `api/config/fiscal/FR.yaml` is derived from this
file: a rule changes here first, with its source, and the preset follows in the same change. Where sources
disagree, both are cited and the preset's choice is stated.

## 1. Currency

| Rule | Preset | Source | Status |
|---|---|---|---|
| The euro (EUR) has two decimals | `currency: EUR`, `minor_unit: 2` | ISO 4217; Unicode CLDR through Symfony Intl | unvalidated |

## 2. VAT (TVA)

| Rule | Preset | Source | Status |
|---|---|---|---|
| Normal rate 20 % | component `TVA20`, default | CGI art. 278, read on Légifrance [1] | unvalidated |
| Intermediate rate 10 % | component `TVA10` | CGI art. 279 [3] | unvalidated |
| Reduced rate 5.5 % | component `TVA5_5` | CGI art. 278-0 bis [2] | unvalidated |
| Special rate 2.1 % | component `TVA2_1` | CGI art. 281 quater to 281 nonies [3] | unvalidated |

**Recodification.** Ordonnance n° 2025-1247 du 17 décembre 2025 moves the VAT rules from the CGI into the Code des
impositions sur les biens et services (CIBS, book II, from art. L. 211-1). It was to apply on 1 September 2026;
ordonnance n° 2026-671 du 27 juillet 2026 postpones it to 1 January 2027 [4]. The rates do not change, only the
articles that carry them. Secondary sources only. This file cites the CGI articles in force today.

## 2a. When VAT is due, and when it is declared (row 112, 2026-09-25)

| Rule | What the software does | Source | Status |
|---|---|---|---|
| VAT on a delivery of goods is due when the power to dispose of them as owner passes (the delivery); since 1 January 2023 an advance received before it makes VAT due on the advance, when the goods are precisely identified | a delivered invoice's VAT counts in the month of its issue date; advances are not modelled | CGI art. 269-1-a and 269-2-a; BOI-TVA-BASE-20-10 § 40 and § 65 [10] | unvalidated |
| VAT on a service is due on receipt of the price or of advances (« encaissements »), unless the business opted to pay it on the invoices (« débits ») | the issue date, which matches only the débits option | CGI art. 269-2-c; BOI-TVA-BASE-20-20 § 30 [11] | unvalidated |
| Régime réel normal: a monthly CA3 declaration; quarterly when the year's tax is under 4,000 € | nothing yet: no declaration is produced (row 91) | CGI art. 287-2 (in force since 1 January 2025) [12] | unvalidated |
| The CA3 is due between the 15th and the 24th of the following month, the day set by the business's legal form, its département and its name or SIREN | not modelled | BOI-TVA-DECLA-20-20-10-10 § 200 [13] | unvalidated |
| Régime simplifié: one annual CA12, with two instalments of 55 % (July) and 40 % (December) of the previous year's tax before VAT on fixed assets; the CA12 is due by the second working day after 1 May for a calendar year, within the three months after the year closes otherwise | not modelled | CGI art. 287-3 [12]; impots.gouv.fr [14] | unvalidated |
| Franchise en base: no VAT charged, no declaration of it (§ 3) | the company regime `franchise` | § 3 | unvalidated |

What the home shows is therefore the VAT **on the invoices issued in the month**, labelled « TVA collectée »
(docs/SPEC.md § 7, 2026-09-24 11:40). For goods it is close to the month's due VAT; for services, unless the company
opted for the débits, what is due is the VAT on what was received in the month, which the software does not compute
yet. It is never called « à déclarer ».

## 2b. Advances (acomptes) and the facture d'acompte (row 208, 2026-10-07)

| Rule | What the software does | Source | Status |
|---|---|---|---|
| Every taxable person issues an invoice for each advance paid to them before the supply | built: a deposit invoice, numbered in the invoice series, issued for an amount or a share of an accepted quote | CGI art. 289-I-1-c, as cited in BOI-TVA-DECLA-30-20-20-20 § 160 [16] | unvalidated |
| VAT on an advance for goods is due at its receipt, up to its amount, since 1 January 2023, when every relevant element of the future supply is known, the goods precisely identified | built: the deposit invoice carries VAT, split over the quote's sets of taxes in proportion to each set's share of its net after the discount (an accepted quote identifies the goods) | CGI art. 269-2-a as amended by loi n° 2021-1900 art. 30; BOFiP actualité ACTU-2022-00148 of 21 December 2022; BOI-TVA-BASE-20-10 § 65 [10][17] | unvalidated |
| VAT on an advance for a service is due at its receipt (the encaissements basis), unless the business opted for the débits | same as above | CGI art. 269-2-c [11] | unvalidated |
| A deposit invoice may leave out a mention not known when it is issued (the exchange rate, the exact supply date, a variable quantity or price) | built: the deposit invoice names the quote it is drawn from and the share, in lines of its own | BOI-TVA-DECLA-30-20-20-20 § 160 and § 170 [16] | unvalidated |
| The date of the advance is printed when it differs from the invoice's date and is known then | built: the deposit invoice is issued as the request for the advance, before it is received; an advance received before its invoice is a known gap | CGI annexe II art. 242 nonies A-I-10°; BOI-TVA-DECLA-30-20-20-10 § 160 [15] | unvalidated |
| Deposit invoices are numbered and dated like any invoice, and the final invoice refers to each of them | built: the final invoice made from the quote names each deposit invoice by number and date and subtracts it as lines of its own, net and VAT rate by rate, so its totals stay exact; its Factur-X names them in the line text only, not yet as preceding invoices (BG-3) | BOI-TVA-DECLA-30-20-20-10 § 60 [15] | unvalidated |
| In the electronic invoice, a deposit invoice is type code 386; the final invoice either deducts the deposits as negative lines or reports them as the amount already paid (EN 16931 BT-113), the first keeping each rate's VAT exact | not produced yet (§ 9) | AFNOR XP Z12-014 use cases 20 and 21, through a secondary summary (Legifiscal) [18] | unvalidated |
| Money received beyond what the customer's invoices owe is not an advance on a supply | the customer's « trop-perçu », recorded only while no issued invoice of theirs is still due | follows from the rules above; no text found | unvalidated |

What is **not** found in a text: whether a deposit drawn from several rates must be split rate by rate or may be
invoiced at one rate (the proportional split is the software's choice, the one that keeps the final invoice's VAT per
rate exact); and how a refunded advance is corrected (a credit note on the deposit invoice is assumed, type 503 in the
electronic invoice [18]).

## 3. Company VAT regime: franchise en base

| Rule | Preset | Source | Status |
|---|---|---|---|
| A business under the thresholds charges no VAT: 85,000 € for sales of goods, 37,500 € for services (2026). The reform lowering it to 25,000 € was abandoned | company regime `franchise` excludes the `vat` family; wired to the company at G3b | secondary sources [5] | unvalidated |
| Its invoices carry "TVA non applicable, art. 293 B du CGI" | mention `fiscal.mention.fr.franchise` | CGI art. 293 B, secondary sources [5] | unvalidated |

**Source conflict on the new wording.** Under the CIBS the reference becomes art. L. 233-3 (one source writes
"223-3") [5]. Sources disagree on when the old wording stops being accepted: 31 December 2027 in one, 30 June 2028
in another [4][5]. The preset keeps the art. 293 B wording; changing it later is a translation edit.

## 4. What an invoice must carry

- CGI annexe II art. 242 nonies A [6]: among others, the seller's and customer's names and addresses, the seller's
  VAT number, the customer's VAT number for an intra-EU supply, the date, a number from a chronological continuous
  series, the quantity and exact designation, the unit price excluding VAT, the rate and amount of VAT per rate,
  any discounts, the supply date, the reference of the exemption provision (12°), and "Autoliquidation" when the
  customer is liable for the tax (13°).
- The e-invoicing reform adds the customer's SIREN, the delivery address when it differs, the category of the
  operation (goods, services or both) and whether the seller opted to pay VAT on debits. They apply from
  1 September 2026 for large and mid-sized companies and from 1 September 2027 for SMEs and micro-businesses [6][7].
  The invoice data model already has `operation_category` and `vat_on_debits` (docs/SPEC.md § 4).
- Code de commerce art. L441-9 [8]: the payment date, the conditions of any early-payment discount, the rate of late
  payment penalties, and the fixed recovery indemnity. Art. D441-5 [8] sets that indemnity at 40 €.

The document fields are printed at G6 and G7. The preset lists the printed wordings as translation keys:
`fiscal.mention.fr.late_payment`, `fiscal.mention.fr.recovery_indemnity` and `fiscal.mention.fr.no_early_discount`.
Their wording is unsourced beyond the articles named.

French invoices are not customarily closed with their total written out in words, and no mention requires it: the
preset turns `document.amount_in_words` off by default under `settings`, and a company may turn it on. Status: custom,
unsourced as law.

## 5. Identifiers

| Identifier | Preset pattern | Source | Status |
|---|---|---|---|
| SIREN: 9 digits, the last a Luhn check digit | `^[0-9]{9}$` | INSEE, secondary sources | unvalidated |
| SIRET: 14 digits (SIREN + 5-digit NIC), Luhn over all 14 (La Poste's establishments are an exception) | `^[0-9]{14}$` | INSEE, secondary sources | unvalidated |
| The NIC, the SIRET's last five digits, tells a company's establishments apart | `establishment.code_pattern` `^[0-9]{5}$`; a company's first establishment starts as `00001`, a placeholder the company corrects to its real NIC | INSEE, secondary sources | unvalidated |
| Intra-EU VAT number: `FR`, a two-character key, the SIREN; key = (12 + 3 × (SIREN mod 97)) mod 97 | `^FR[0-9A-Z]{2}[0-9]{9}$` | secondary sources | unvalidated |

The preset names each identifier's check beside its pattern: `luhn` for the SIREN, `siret` for the SIRET (Luhn, or for
La Poste's SIREN 356000000 a digit sum divisible by five) and `fr_vat_key` for the VAT number, whose key is checked
only when it is two digits. Unvalidated, like the rest of this section.

## 5a. Other member states' VAT numbers

An intra-community customer is named on the invoice by its own VAT number, issued by its own state (CGI annexe II
art. 242 nonies A [6]; in the electronic invoice BT-48, which BR-IC-04 requires for an intra-community supply [9]). So a
customer, or a supplier, may hold another member state's number, held to that state's shape; the company's own number
keeps France's pattern and key above. The shapes are the European Commission's, as its VIES service publishes them
(« VAT identification number structure » [19]). VIES writes some numbers with spaces between blocks (`FRXX 999999999`,
`DK99 99 99 99`); the patterns take the number without them, as § 5 does for France's. No other state's check digits
are applied (Known gaps).

| Prefix | VIES format | Preset pattern (`vat_number.foreign_patterns`) | Status |
|---|---|---|---|
| AT | ATU99999999 (the first position after the prefix is always « U ») | `^ATU[0-9]{8}$` | unvalidated |
| BE | BE0999999999, BE1999999999 | `^BE[01][0-9]{9}$` | unvalidated |
| BG | BG999999999 or BG9999999999 | `^BG[0-9]{9,10}$` | unvalidated |
| CY | CY99999999L | `^CY[0-9]{8}[A-Z]$` | unvalidated |
| CZ | CZ99999999, CZ999999999 or CZ9999999999 | `^CZ[0-9]{8,10}$` | unvalidated |
| DE | DE999999999 | `^DE[0-9]{9}$` | unvalidated |
| DK | DK99 99 99 99 | `^DK[0-9]{8}$` | unvalidated |
| EE | EE999999999 | `^EE[0-9]{9}$` | unvalidated |
| EL (Greece) | EL999999999 | `^EL[0-9]{9}$` | unvalidated |
| ES | ESX9999999X, the first and last characters a letter or a digit, never both digits | `^ES(?:[0-9A-Z][0-9]{7}[A-Z]\|[A-Z][0-9]{7}[0-9])$` | unvalidated |
| FI | FI99999999 | `^FI[0-9]{8}$` | unvalidated |
| HR | HR99999999999 | `^HR[0-9]{11}$` | unvalidated |
| HU | HU99999999 | `^HU[0-9]{8}$` | unvalidated |
| IE | IE9S99999L or IE9999999WI (W and I read as letters, which the legend does not define) | `^IE(?:[0-9][0-9A-Z+*][0-9]{5}[A-Z]\|[0-9]{7}[A-Z]{2})$` | unvalidated |
| IT | IT99999999999 | `^IT[0-9]{11}$` | unvalidated |
| LT | LT999999999 or LT999999999999 | `^LT(?:[0-9]{9}\|[0-9]{12})$` | unvalidated |
| LU | LU99999999 | `^LU[0-9]{8}$` | unvalidated |
| LV | LV99999999999 | `^LV[0-9]{11}$` | unvalidated |
| MT | MT99999999 | `^MT[0-9]{8}$` | unvalidated |
| NL | NLSSSSSSSSSSSS (S a letter, a digit, « + » or « * ») | `^NL[0-9A-Z+*]{12}$` | unvalidated |
| PL | PL9999999999 | `^PL[0-9]{10}$` | unvalidated |
| PT | PT999999999 | `^PT[0-9]{9}$` | unvalidated |
| RO | RO999999999, 2 to 10 digits | `^RO[0-9]{2,10}$` | unvalidated |
| SE | SE999999999999 | `^SE[0-9]{12}$` | unvalidated |
| SI | SI99999999 | `^SI[0-9]{8}$` | unvalidated |
| SK | SK9999999999 | `^SK[0-9]{10}$` | unvalidated |
| XI (Northern Ireland) | XI999 9999 99, XI999 9999 99 999, XIGD999, XIHA999 | `^XI(?:[0-9]{9}\|[0-9]{12}\|GD[0-9]{3}\|HA[0-9]{3})$` | unvalidated |

Northern Ireland is not a member state, but VIES checks its numbers for the goods it trades with the Union under the
prefix XI; a customer there holds one.

## 6. Customer tax regimes

| Code | Excludes | Mention | Status |
|---|---|---|---|
| `standard` | nothing | none | unvalidated |
| `exempt` | the `vat` family | `fiscal.mention.fr.exempt`: the exemption's legal reference is still required on the invoice (242 nonies A 12°) | unvalidated |
| `intra_eu` | the `vat` family | `fiscal.mention.fr.intra_eu`: "Autoliquidation" for services (CGI art. 283-2), "Exonération de TVA, article 262 ter I du CGI" for goods | unvalidated |
| `export` | the `vat` family | `fiscal.mention.fr.export`: "Exonération de TVA, article 262 I du CGI" | unvalidated |

In an EN 16931 invoice (Factur-X), a line without VAT carries the VAT category and VATEX exemption code its regime
declares: `intra_eu` K and `VATEX-EU-IC`, `export` G and `VATEX-EU-G`, and the company regime `franchise` E and
`VATEX-FR-FRANCHISE` (EN 16931-1 BT-118, BT-121; codes read in the CEN validation artefacts' code list, CEF VATEX).
`exempt` declares neither, because it does not say which article exempts the sale, so such an invoice is not written
as Factur-X until it does. Unvalidated; `intra_eu` covers goods and services alike, while the printed mention says
« Autoliquidation » for services.

The data model's list `standard, exempt, suspended, export` is Tunisia's. Regime codes are preset data, not a
closed set, and France has no suspended regime in this preset.

## 7. Rounding

EN 16931 BR-CO-17 computes the VAT of each category on the category's taxable amount and rounds the result, not the
sum of rounded line amounts [9]. The preset rounds half-up to the cent, once per rate group (`per_rate_group`).
Unvalidated.

## 8. Numbering and languages

- One or more chronological continuous series, with no gaps (242 nonies A) [6]. The preset's defaults are one
  series per document type, written `FA-{YYYY}-{MM}-{SEQ:5}`, `AV-…` and `BL-…` (month printed since 2026-10-04), the
  sequence reset every year. Unvalidated.
- The preset's document language is French.

## 9. Out of POC scope: electronic invoicing

Receiving electronic invoices is mandatory for every business from 1 September 2026. Issuing them is mandatory for
large and mid-sized companies from 1 September 2026 and for SMEs and micro-businesses from 1 September 2027 [7].
E-invoicing comes after the POC (docs/SPEC.md § 2).

## Known gaps

- The CIBS article numbers replace the CGI references from 1 January 2027 (§ 2, § 3), not yet reflected.
- La Poste's SIRET exception (§ 5) is taken from secondary sources; no primary INSEE text was found for it.
- The encaissements basis for services (§ 2a): the VAT on what was received in a period, not computed; the débits option is not recorded on the company.
- The CA3/CA12 themselves and the CIBS article numbers for § 2a, not researched.
- A VAT key of letters (numbers issued without a SIREN) is accepted unchecked (§ 5).
- Another member state's VAT number (§ 5a) is checked for its shape only, never for that state's check digits, and its
  prefix is not compared with the customer's country.
- Mention wording (§ 4, § 6) is unsourced beyond the articles named.
- Advances (§ 2b): the split of a deposit over several rates and the correction of a refunded advance are not in any text found; CGI art. 289 itself was read only as cited by the BOFiP; an advance received before its deposit invoice (its date printed) is not offered.

## Sources

1. CGI art. 278: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000026950057
2. CGI art. 278-0 bis: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051215148
3. CGI, rates section (art. 278-0 to 281 octies): https://www.legifrance.gouv.fr/codes/section_lc/LEGITEXT000006069577/LEGISCTA000006179654/
4. CIBS postponement: https://fiscalonline.com/Entreprise/report-1er-janvier-2027-recodification-tva-cibs ; https://www.cridon-ne.org/recodification-tva-cibs-reportee/ ; https://www.pwcavocats.com/fr/ealertes/ealertes-france/2026/fevrier/la-recodification-de-la-tva-dans-le-cibs-un-nouveau-referentiel-a-apprivoiser.html
5. Franchise mention: https://gesticompta.com/franchise-de-tva-la-mention-obligatoire-sur-les-factures-des-micro-entrepreneurs-change-au-1er-septembre/ ; https://synapx.fr/blog/mention-tva-franchise-cibs-facture/
6. CGI annexe II art. 242 nonies A: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000050811276
7. E-invoicing calendar: economie.gouv.fr (fetch refused with 403 in this round) and secondary sources.
8. Code de commerce art. L441-9 and D441-5 (décret n° 2012-1115), Légifrance.
9. EN 16931-1:2017, business rule BR-CO-17.
10. BOI-TVA-BASE-20-10 (delivery of goods): https://bofip.impots.gouv.fr/bofip/534-PGP.html/identifiant=BOI-TVA-BASE-20-10-20221221
11. BOI-TVA-BASE-20-20 (services): https://bofip.impots.gouv.fr/bofip/283-PGP.html/identifiant=BOI-TVA-BASE-20-20-20181107
12. CGI art. 287: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000048826856
13. BOI-TVA-DECLA-20-20-10-10 (filing dates): https://bofip.impots.gouv.fr/bofip/1001-PGP.html/identifiant=BOI-TVA-DECLA-20-20-10-10-20150506
14. impots.gouv.fr, CA12 due date: https://www.impots.gouv.fr/professionnel/questions/je-suis-soumis-au-regime-simplifie-dimposition-la-tva-quelle-echeance-dois
15. BOI-TVA-DECLA-30-20-20-10 (invoice mentions), 18 October 2013: https://bofip.impots.gouv.fr/export/pdf/319741
16. BOI-TVA-DECLA-30-20-20-20 (simplified and particular invoices), 25 September 2019: https://bofip.impots.gouv.fr/export/pdf/320369
17. BOFiP actualité ACTU-2022-00148, « Exigibilité de la TVA sur les acomptes perçus dans le cadre de livraisons de biens », 21 December 2022: https://bofip.impots.gouv.fr/bofip/13758-PGP.html/ACTU-2022-00148
18. Legifiscal, « Facturation électronique – cas d'usage n° 20 et 21 : les acomptes » (secondary, on AFNOR XP Z12-014): https://www.legifiscal.fr/creation-entreprise/facturation-obligations/facturation-electronique-cas-usage-n20-21-acomptes.html
19. European Commission, VIES, FAQ « VAT identification number structure »: https://ec.europa.eu/taxation_customs/vies/#/faq ; the table was read from the page's own English text, https://ec.europa.eu/taxation_customs/vies/assets/i18n/en.json (keys `faq_label_<prefix>format`, `faq_lbl_remarks`, `faq_txt_notes`), on 2026-10-09.
