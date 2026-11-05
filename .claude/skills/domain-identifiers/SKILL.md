---
name: domain-identifiers
description: Use when a task validates, normalises, imports, prints or tests a business identifier in twes-in (Tunisian matricule fiscal or RIB, IBAN, BIC, EAN/UPC/GTIN barcode, phone, e-mail, SIREN/SIRET/NIC, French or EU VAT number, postal code). Structure, normalisation, check algorithm, computed valid and invalid examples, refusal wording and the gaps in today's code, each rule with its source and grade.
---
Review date: 2026-11-02 22:30   Validation mode: advisory   Core: .claude/rules/expertise-core.md

<!-- Tags: [Source: …] = read in the issuer's or the standard's own text, with the date read; [Observed: …] = reproduced here on real data; [Inferred: …] =
holds on the evidence named, but no issuer text states it; [Unverified: …] = secondary sources only, or unread; [Ruled: user <date>] = AGREED in SPEC § 7.
Examples computed with python3 on 2026-10-21; « → » gives its output. -->

## Rules that apply to every identifier (core line 34 not repeated)
- Refusal shape: the format, one example, the action; a registration number names the PART that is wrong and shows its
  preset's example, « Ex. … » under the field, on every form and in the import preview.  [Ruled: user 2026-10-20 22:38,
  2026-10-21 12:03, docs/SPEC.md:5545, 5561] Send a stable `reason` code and params; the screen words it.
- A file and a form say the same refusal text, through one reader of the single refusal shape the API answers.
  [Ruled: user 2026-10-15 06:51, docs/SPEC.md:5232] Shown once the field is left, then live until right; a save shows
  every error and focuses the first.  [Ruled: user 2026-10-27 20:57, docs/SPEC.md:5694]
- An identifier a country does not have: « Ce pays ne prévoit pas de numéro « {{identifier}} ». »  [Ruled: user 2026-10-15 12:58, docs/SPEC.md:5248]
- Import « kept » = SET ASIDE: the row is written without the doubtful value (shape or check, IBAN, BIC, e-mail, phone,
  barcode), the record marked « À vérifier » with a note naming the column, the value as typed and why, and the result
  says it on that cell; the domain still refuses the value in its field.  [Ruled: user 2026-10-22 18:09, docs/SPEC.md:5627;
  code `ImportExport/Application/SetAside.php`, `Infrastructure/Import/SetAsideMarks.php`]
- A missing required number (`required_for` in the preset) is refused in a form (`identifier_required`, unless the
  record already lacked it); in a file it is a marker reason: matricule missing for a business customer (`identifier_missing`
  note), barcode missing for a product.  [Ruled: user 2026-10-20 01:40, docs/SPEC.md:5457; « no barcode » out of the home
  count only, 2026-10-24 17:44, :5642; code `IdentifierRules.php:82`, `ManageCustomers.php:253-257`,
  `CustomerInput.php` `identifiersMayFollow`, `CustomerImport.php:324`]
- Normalise before checking, store the normalised form, print the grouped form; never guess a letter or add a zero unsaid.
  What a person typed keeps no `\p{Z}`/`\p{Cf}` at its ends (`TypedText::trimmed()`, gate `typed-trims.sh`).  [Source:
  `.claude/rules/api.md` § Gates] A direction mark before a pasted IBAN in Arabic is not a refusal (`ContactRules::unspaced`
  strips `\s` only; the trim is `ContactRuleValidator.php:49`).  [Observed: `web/src/app/company/company-profile-form.spec.ts:88-89`]
- An Arabic Excel CSV (Windows-1256) reaches identifier columns through glibc iconv `CP1256`, never mbstring.  [Source: the Lessons (CLAUDE.md and `.claude/rules/`) 2026-10-16]
- A check digit proves a typo did not happen, never that the number exists (INSEE avis, VIES, TEJ); an outage is not a refusal.
- An API pattern sent to a screen is read with any PHP delimiter and flags; a guard test runs each with one valid and one
  invalid example.  [Ruled: user 2026-10-29 16:53, docs/SPEC.md:5722]
- Fixtures: the « valid » examples below or GS1 prefix 952 (reserved for examples), never an unchecked official number
  (BOFiP's VAT example is a dummy, § 9).

## 1. Tunisian matricule fiscal (identifiant fiscal)
- 13 characters: `N7…N1` 7 digits, `X1` clé, `X2` code TVA, `X3` code catégorie, `E1E2E3` établissement secondaire; the
  seat is `000`.  [Source: Ministère des Finances, cahier « Déclaration d'employeur » 2006, p. 43-44,
  https://www.finances.gov.tn/sites/default/files/2019-08/cahier_charges_fr.pdf, read 2026-10-21]
- TEJ (2026) keeps only the first 8, `\d{7}[A-Z]`, and reads PM/PP from a separate element.  [Source: DGI TEJDeclarationRS_v1.0.xsd
  `TypeMatriculeFiscal`, https://jibaya.tn/wp-content/uploads/2026/09/plateforme-TEJ-shemas-xsd.zip, read 2026-10-21]
- Checked PART BY PART: key letter not I, O, U; VAT code A/P/B/D/N; category M/P/C/N/E; category E when the establishment
  is not 000. Every writing (`1234567/A/A/M/000`, spaces, `1234567AAM000`, lowercase) is stored `1234567A/A/M/000`. The
  letter sets come from secondary sources (python-stdnum `tn.mf`, lookuptax, copeps), unvalidated until an accountant
  confirms.  [Ruled: user 2026-10-20 22:38, docs/SPEC.md:5545; code `api/config/fiscal/TN.yaml:23-39`, `Fiscal/Application/Preset/IdentifierPartRule.php`]
- Key letter algorithm: none published by the ministry or DGI; do not compute or invent one (an Odoo « mod 23 » claim is
  unsourced).  [Observed absence: both cahiers and the TEJ XSD define the key as one character]
- A short form (`1234567A`, a TEJ-only number) is a refusal naming the missing part in a form and set aside in a file
  (rules above); the preset has no optional part.  [Observed: TN.yaml parts, 2026-11-02]
- Valid `1234567A/B/M/000`. Invalid `1234567I/B/M/000` (key I), `1234567A/B/M/001` (establishment ≠ 000 needs E), `1234567/B/M/000` (no key).
- A Tunisian individual identified by CIN for TEJ: source the TEJ identifier types in `docs/fiscal/TN.md` first, then
  support CIN; until then the vendor says so.  [Ruled: user 2026-10-15 20:00, docs/SPEC.md:5285]

## 2. Tunisian RIB (relevé d'identité bancaire)
- 20 digits: 2-digit bank, 16 digits split by each bank (BIAT prints 2+6+10+2), 2-digit key.  [Source: BIAT-issued RIB,
  https://darna.tn/wp-content/uploads/2021/02/RIB-DARNA.pdf, read 2026-10-21; the 3+13 split Unverified]
- Key: the 20-digit number is a multiple of 97 (key = 97 − (first18 × 100 mod 97)).  [Inferred: three published RIBs; no BCT text]
- A bare 20-digit RIB where an IBAN is asked is taken as `TN59` + RIB, in forms as in files.  [Ruled: user 2026-10-22 18:09,
  docs/SPEC.md:5627; code `Shared/Domain/Service/ContactRules.php:112`] The IBAN check then checks the RIB key (98 − (292300 mod 97) = 59).
- Valid `10006035183598478831` → `mod 97 = 0`. Invalid `10006035183598478832` → `mod 97 = 1`.

## 3. IBAN (ISO 13616)
- Country 2 letters, 2 check digits, BBAN; ≤ 34 characters; printed in groups of four. Check: drop non-alphanumerics and a
  leading « IBAN », move the first 4 to the end, A=10 … Z=35, mod 97 = 1.  [Source: Banco de Portugal IBAN guide (ISO 13616,
  ISO/IEC 7064 MOD 97-10), https://www.bportugal.pt/sites/default/files/anexos/documentos-relacionados/international_bank_account_number_en.pdf, read 2026-10-21]
- Per-country length from the SWIFT registry, as Symfony's `IbanValidator` copies it: TN 24, FR 27.  [Source: api/vendor/symfony/validator/Constraints/IbanValidator.php, cites the registry]
- France: FR + 2 + RIB of 23 (bank 5, guichet 5, account 11 alphanumeric, key 2).  [Source: CFONB « Le nouveau RIB », 2001,
  https://www.cfonb.org/fichiers/20140124103020_8_4_RIB_Le_nouveau_Releve_d_Identite_Bancaire_2001_03.pdf, read 2026-10-21]
- Valid `TN59 1000 6035 1835 9847 8831`, `FR76 3000 6000 0112 3456 7890 189` (built) → `mod 97 = 1`. Invalid `TN58 1000 6035 1835 9847 8831` → `mod 97 = 0`.
- Shipped text: « L’IBAN s’écrit avec le code du pays, 2 chiffres de contrôle puis le compte (exemple : TN59 1000 6035 1835
  9847 8831). Recopiez-le depuis le RIB. »  [Observed: web/public/i18n/fr.json `invalid_iban`, 2026-11-02]

## 4. BIC / SWIFT (ISO 9362)
- 8 or 11: party prefix 4, country 2 (ISO 3166-1, SWIFT also XK), suffix 2, optional branch 3 (`XXX` head office).
  [Unverified: ISO 9362:2022 paywalled; https://en.wikipedia.org/wiki/ISO_9362 read 2026-10-21] Whether 2022 lets the
  prefix hold digits (our regex: letters only) is [Unverified].
- Normalise uppercase, no spaces; shape + known country, no check digit. Valid `BIATTNTT`, `BNORFRPPBDX`. Invalid `BIATTNTT1`.

## 5. GTIN-8/12/13/14 (EAN, UPC)
- One check digit for every GS1 key: weights 3,1,3,1… from the digit left of it; check = next multiple of ten − sum.
  [Source: GS1 General Specifications 26.0 (Jan 2026) § 7.9.1 Table 7-8, https://www.gs1.org/docs/barcodes/GS1_General_Specifications.pdf, read 2026-10-21]
- GTIN-13 prefixes: `02`, `20`-`29` RCN within a region (in-store weight/price labels), `04` RCN within a company, `952`
  examples, `977` ISSN, `978`-`979` ISBN/ISMN, `980` refunds, `981`-`984`/`99` coupons; GTIN-8 `000`-`099`, `200`-`299` RCN.
  An RCN SHALL NOT leave its environment.  [Source: same, § 1.2.2.2.1, Tables 1-4, 1-5] `619` GS1 Tunisia, `300`-`379` France.
  [Source: https://www.gs1.org/standards/id-keys/company-prefix, read 2026-10-21]
- Compare GTINs right-justified on 14 digits.  [Inferred: GenSpecs Table 7-8; `Barcode::keyOf`, Barcode.php:51]
- UPC-E (8 digits, own expansion) fails the EAN-8 check and is refused by ruling (SPEC § 7, 2026-09-17).
- An all-digit code of 8/12/13/14 claims a GTIN and is checked; anything else is an internal code kept as typed.
- A purchase workbook's « Code »/« Réf » cell of 8, 12 or 13 digits whose check digit holds is the barcode (a trailing
  star dropped); any other value is that vendor's own code; one failing both stays in the marker note as « code à vérifier ».
  [Ruled: user 2026-10-20 01:55, docs/SPEC.md:5471, « for now »]
- No barcode: found by name or reference; after the release the label prints the reference as Code 128 and a scan
  matching no barcode tries the references.  [Ruled: user 2026-10-22 18:58, docs/SPEC.md:5628]
- Valid `6190000000019`, `9520000000011`, `95200002`, `952000000002`, `95200000000019`, RCN `2000000000015`. Invalid `6190000000018`.

## 6. Phone numbers (E.164)
- `+` country code (1-3 digits) + national number, ≤ 15 digits.  [Unverified: ITU-T E.164 behind a login; Wikipedia read 2026-10-21]
- Checked by country through libphonenumber on both sides (full metadata, loaded with the phone field, never on the first
  page; PHP and JS updated in step); numbering plans are the library's, never a list here. One field everywhere: two
  boxes, the country (flag, name, calling code, default the record's country, else the company's, the platform's for its
  fields) and the local number; a typed + or 00 overrides; stored E.164, shown grouped « +216 71 234 567 ». A wrong
  length is refused.  [Ruled: user 2026-10-27 17:51, 2026-10-29 16:53, 17:44, 20:20, docs/SPEC.md:5693, 5723, 5725, 5730]
- Outside the known ranges: platform setting `platform.phones.outside_ranges`, « Refuser » (default) or « Garder et marquer
  À vérifier », whose line reads « Téléphone que son pays n'attribue pas : à vérifier ».  [Ruled: user 2026-10-29 20:20,
  2026-10-30 05:30, docs/SPEC.md:5730, 5734; code `Settings/Application/PhoneSettings.php:25`] A number right but outside the ranges is
  confirmed with « C'est juste », kept until the number changes; the nightly check leaves it alone; company profile and
  establishments gain the action.  [Ruled: user 2026-10-30 05:30, docs/SPEC.md:5732]
- Code `Shared/Infrastructure/Intl/LibPhoneNumbers.php`, `GroupedPhones.php`, `web/src/app/shared/form/phone-numbers.ts`,
  vectors `phone-vectors.spec.ts`. Valid `+216 71 900 868` → `+21671900868`. Invalid `+216 7190086` (7 national digits).

## 7. E-mail
- HTML's « valid e-mail address » is `1*(atext / ".") "@" label *("." label)`, labels ≤ 63, a willful violation of RFC 5322.
  [Source: WHATWG HTML, https://html.spec.whatwg.org/multipage/input.html#valid-e-mail-address, read 2026-10-21]
  Local part ≤ 64, domain ≤ 255, path ≤ 256, so ≤ 254.  [Source: RFC 5321 §§ 4.5.3.1.1-3; 254 Inferred]
- Local part case-sensitive (RFC 5321 § 2.4): lowercase the domain only, compare case-insensitively for duplicates.
- One rule, `ContactRules::email` (WHATWG plus a dot in the domain, ≤ 254), for every new or changed address; each refusal
  worded with an example; a typo hint offered, never applied; no live domain check (a shop's computer may be offline);
  an account still signs in with the address it has.  [Ruled: user 2026-10-27 17:51, docs/SPEC.md:5693] The dot departs
  from WHATWG; SPEC names no such departure.  [Observed: `git grep WHATWG docs/SPEC.md` empty]
- Web: `emailRule` (`shared/form/email-rule.ts`) and `EmailRuleTest` hold both sides to `email-vectors.json`.  [Source: `.claude/rules/web.md` § Gates, `typed-emails.sh`]
- Valid `compta@exemple.tn`. Invalid `jean dupont@exemple.tn`, `compta@exemple` (no dot: refused by the canon).

## 8. SIREN, SIRET, NIC (France)
- SIREN 9 digits, the 9th a check digit; SIRET 14 = SIREN + NIC (4-digit order + 1 check digit over the whole SIRET).
  [Source: INSEE definitions c2047, c1841, c1981, read 2026-10-21]
- The check is Luhn over the 9 and the 14.  [Inferred: not named by INSEE; holds on 4 SIRETs read on INSEE avis PDFs, 4 more from avis URLs not opened]
- La Poste (SIREN `356000000`): some SIRETs fail Luhn with a digit sum divisible by 5 (`35600000009075`), its head office
  `35600000000048` passes Luhn. Rule: Luhn OR (SIREN 356000000 AND sum mod 5 = 0).  [Source: INSEE avis
  https://api-avis-situation-sirene.insee.fr/identification/pdf/35600000009075, read 2026-10-21; code `Fiscal/Domain/ValueObject/IdentifierCheck.php:25-39`]
- Digits only. Valid `120027016`, `12002701600365`. Invalid `120027017`, `12002701600366`.

## 9. French VAT number (TVA intracommunautaire)
- `FR` + 2-character key + SIREN = 13.  [Source: BOFiP BOI-TVA-DECLA-20-10-20-20220216 §§ 120-130, read 2026-10-21] BOFiP says
  2 digits; VIES writes `FRXX 999999999`, X letter or digit: keep letters accepted.  [Source: VIES en.json `faq_label_FRformat`, read 2026-10-21]
- Key = (12 + 3 × (SIREN mod 97)) mod 97.  [Inferred: no DGFiP text; VIES VALID for `FR00333721124`, INVALID for a wrong key (INVALID also = not registered), 2026-10-21] A letter
  key passes (issued without SIREN).  [Observed: `IdentifierCheck.php:59-60`]
- BOFiP's `FR31 734820078` is a dummy (fails Luhn, key would be 28): never a fixture.
- Valid `FR85120027016` (not confirmed by VIES), fixture `FR11123456782` (preset example). Invalid `FR86120027016`.

## 10. Other EU VAT numbers, postal codes
- EU shapes are VIES data (`faq_label_<CC>format` in https://ec.europa.eu/taxation_customs/vies/assets/i18n/en.json;
  `docs/fiscal/FR.md` § 5a holds the read of 2026-10-09); no other state's check digits applied. Existence: VIES REST
  `…/rest-api/ms/<CC>/vat/<number>`; `MS_MAX_CONCURRENT_REQ` / `MS_UNAVAILABLE` are outages, never a refusal.  [Observed: called 2026-10-21]
- Postal code: TN 4 digits (`3100 KAIROUAN`), FR 5 (`33500 LIBOURNE`, CEDEX too).  [Source: UPU sheets tunEn.pdf (04/2014),
  fraEn.pdf (09/2011), https://www.upu.int/UPU/media/upu/PostalEntitiesFiles/addressingUnit/, read 2026-10-21] Enforce
  `^\d{4}$` / `^\d{5}$` only for a TN / FR address. Valid `1002`, `75001`; invalid `100`, `7500`.

## 11. Open gaps (survey of 2026-11-02; closed rows are in the change list)
| Identifier | Where | Gap |
|---|---|---|
| BIC | `ContactRules.php` `BIC` vs `CompanyProfileResource.php:142` `Assert\Bic` | two rules for one identifier; letters-only prefix vs ISO 9362:2022 Unverified |
| Postal code | `Shared/Domain/Model/PostalAddress.php:27` | length ≤ 20 only; no TN 4 / FR 5 rule by country (§ 10) |
| SIREN, FR VAT | `IdentifierCheck.php` | Luhn and the VAT key are Inferred, not issuer-published; refusing on them needs a ruling |
| GTIN | `Module/Products/Domain/ValueObject/Barcode.php` | RCN prefixes 02/20-29 not told apart: an in-store weight label is looked up as a GTIN |
| Matricule | `TN.yaml` letter sets | secondary sources, unvalidated until an accountant confirms (§ 1) |
| Hand-written fields | product barcode, support-access reason | not yet built from the rule the API sends; one plan row and a gate.  [Ruled: user 2026-10-27 20:57, docs/SPEC.md:5695] |
