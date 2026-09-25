# Tunisia (TN): fiscal rules behind the preset

Research round of 2026-09-13 (G3a). **Every rule below is `unvalidated`** until a Tunisian chartered accountant
(expert-comptable) confirms it; nothing here is legal advice. `api/config/fiscal/TN.yaml` is derived from this
file: a rule changes here first, with its source, and the preset follows in the same change. Where sources
disagree, both are cited and the preset's choice is stated.

## 1. Currency

| Rule | Preset | Source | Status |
|---|---|---|---|
| The dinar (TND) has three decimals (1 dinar = 1000 millimes) | `currency: TND`, `minor_unit: 3` | ISO 4217; Unicode CLDR through Symfony Intl (`Currencies::getFractionDigits('TND')` = 3) | unvalidated |

## 2. VAT (TVA)

| Rule | Preset | Source | Status |
|---|---|---|---|
| Rates: 19 % normal, 13 % intermediate, 7 % reduced | components `TVA19` (default), `TVA13`, `TVA7` | Code de la TVA art. 7, as amended by loi n° 2017-66 du 18 décembre 2017 (LF 2018) [1]; LF 2026 (loi n° 2025-17 du 12 décembre 2025) leaves them unchanged [5] | unvalidated |
| The VAT base is the price "tous frais, droits et taxes inclus … à l'exclusion de la TVA", so FODEC enters it | FODEC `enters_vat_base: true` | Code de la TVA art. 6-I [2] | unvalidated |
| Exports are outside VAT (no VAT charged) | customer regime `export` excludes the `vat` family | Code de la TVA art. 11 (exportations), cited in [1] | unvalidated |

**Source conflict.** The Ministry of Finance's overview page [9] still lists 18 %, 12 % and 6 %, the rates in force
before LF 2018. It is stale; the preset follows the amended code [1].

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
| The stamp is outside every tax base and untouched by every discount | calculator rule, pinned by pricing vectors | follows from its nature as a per-document duty; no explicit text found | unvalidated |
| Large retail outlets pay 1.5 TND on invoices of 50 to 100 TND and 2 TND above | **not modelled** (ruled 2026-09-13; amount editable per company) | LF 2026 art. 20 [5] | unvalidated |

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
| The threshold is compared with the amount VAT included, and the withholding is taken on it | the gross of the expense (net plus its VAT) | "y compris la taxe sur la valeur ajoutée" [11]; one secondary source [16] says the base is the amount before VAT, contradicting the article's text | unvalidated |
| Other natures: fees 3 %, commercial rents 15 %, and others | **not modelled**: an expense does not say its nature; the rate is said on the payment | [16], not checked against the code | unvalidated |
| The certificate of withholding given to the supplier, and the monthly declaration | **not modelled** here: printed and declared output, row 91 | — | — |

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

## 8. Tax identifier (matricule fiscal)

| Rule | Preset | Source | Status |
|---|---|---|---|
| Seven digits, a check letter, the VAT code, the category code and a three-digit establishment number: `1234567A/B/M/000` | identifier `matricule_fiscal`, pattern `^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$`, required for the company and business customers | secondary sources only [8] | unvalidated |
| The establishment number is the establishment's code | `establishment.code_pattern` `^[0-9]{3}$`; a company's first establishment starts as `000`, which the company corrects if its matricule says otherwise | same | unvalidated |
| The check letter has an algorithm | **not implemented** (no primary source) | none | unvalidated |

## 9. Customer tax regimes

| Code | Excludes | Mention | Status |
|---|---|---|---|
| `standard` | nothing | none | unvalidated |
| `exempt` | the `vat` family | `fiscal.mention.tn.exempt` | unvalidated |
| `suspended` | the `vat` family | `fiscal.mention.tn.suspended` (loi n° 2006-85) | unvalidated |
| `export` | the `vat` family | `fiscal.mention.tn.export` | unvalidated |

Whether FODEC applies to exempt, suspended or export sales was not established in this round. The regimes exclude
VAT only.

## 10. Rounding

No official rounding rule was found. The preset uses half-up to the millime and rounds each tax once per rate group
(`per_rate_group`), the order EN 16931 BR-CO-17 prescribes for a VAT category [14]. A company may choose `per_line`
once the settings engine carries the choice (G3b). Unvalidated.

## 11. Numbering and languages

- Invoices are numbered from an uninterrupted series (art. 18-II) [13]. The preset's defaults are one series per
  document type, reset every year. Unvalidated.
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
- On supplier payments (§ 5a): the withholding by nature (fees, rents), the certificate and the declaration, not modelled.
- Matricule check letter (§ 8), not verified.
- Regime mention wording (§ 7, § 9), unsourced.

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
