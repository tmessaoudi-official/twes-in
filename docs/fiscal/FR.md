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
| Intra-EU VAT number: `FR`, a two-character key, the SIREN; key = (12 + 3 × (SIREN mod 97)) mod 97 | `^FR[0-9A-Z]{2}[0-9]{9}$` | secondary sources | unvalidated |

The preset carries patterns only. The Luhn and key checks are validators for G4, where identifiers are typed.

## 6. Customer tax regimes

| Code | Excludes | Mention | Status |
|---|---|---|---|
| `standard` | nothing | none | unvalidated |
| `exempt` | the `vat` family | `fiscal.mention.fr.exempt`: the exemption's legal reference is still required on the invoice (242 nonies A 12°) | unvalidated |
| `intra_eu` | the `vat` family | `fiscal.mention.fr.intra_eu`: "Autoliquidation" for services (CGI art. 283-2), "Exonération de TVA, article 262 ter I du CGI" for goods | unvalidated |
| `export` | the `vat` family | `fiscal.mention.fr.export`: "Exonération de TVA, article 262 I du CGI" | unvalidated |

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
- SIREN, SIRET and VAT key checksums (§ 5) are left to G4.
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
