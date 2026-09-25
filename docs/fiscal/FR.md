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
  series per document type, reset every year. Unvalidated.
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
- Mention wording (§ 4, § 6) is unsourced beyond the articles named.

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
