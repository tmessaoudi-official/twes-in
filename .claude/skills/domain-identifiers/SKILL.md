---
name: domain-identifiers
description: Use when a task validates, normalises, imports, prints or tests a business identifier in twes-in (Tunisian matricule fiscal or RIB, IBAN, BIC, EAN/UPC/GTIN barcode, phone, e-mail, SIREN/SIRET/NIC, French or EU VAT number, postal code). Structure, normalisation, check algorithm, computed valid and invalid examples, refusal wording and the gaps in today's code, each rule with its source and grade.
---
Review date: 2026-10-21   Validation mode: advisory   Core: .claude/rules/expertise-core.md

<!-- Tags as in the sibling packs: [Source: …] = read in the issuer's or the standard's own text, with the date read; [Observed: …] = reproduced here on real data; [Inferred: …] =
holds on the evidence named, but no issuer text states it; [Unverified: …] = secondary sources only, or unread. Each
example was computed with python3 on 2026-10-21 (/tmp/idcalc/calc.py, not kept); the line after « → » is its output. -->

## Rules that apply to every identifier (developer ruling)
- A format is ENFORCED (a refusal) only where a published standard or the issuer defines it. A rule from a library, a
  blog or our own inference is never a refusal: at most a « À vérifier » mark with its reason.
- Every refusal names the expected format, gives one example and says what to do. Pattern:
  « <Nom> s'écrit <forme> (exemple : <exemple>). <Action : recopiez-le depuis …> ». Send a stable `reason` code and the
  params; the screen words it (`import.rejections.*`, the form's error keys), never the API's English message.
- A doubtful value in an IMPORTED file is KEPT and the record marked « À vérifier » (`CheckMark`, its reason code),
  never a rejected row. A typed value in a form is refused, with the sentence above.
- Normalise before checking, store the normalised form, print the grouped form. Normalising never changes a character
  that carries meaning (no letter guessed, no zero added) without saying so.
- A check digit proves a typo did not happen, never that the number exists. Existence is the registry's (INSEE avis,
  VIES, the TEJ platform), and a registry outage is not a refusal.
- Test fixtures: use the « valid » examples below or numbers from GS1 prefix 952 (reserved for examples) — never a
  number copied from an official page without checking it (BOFiP's `FR31734820078` is a dummy, see § 9).

## 1. Tunisian matricule fiscal (identifiant fiscal)
- Structure, 13 characters: `N7…N1` 7 digits, `X1` clé du matricule, `X2` code TVA, `X3` code catégorie, `E1E2E3` 3-digit
  établissement secondaire. Printed on the « carte d'identification fiscale ».  [Source: Ministère des Finances,
  cahier des charges « Déclaration d'employeur » 2006, p. 43-44 « Le matricule fiscal comprend 13 caractères » and the
  N7…E3 diagram; same layout in the 2007 « factures en suspension » cahier; https://www.finances.gov.tn/sites/default/files/2019-08/cahier_charges_fr.pdf, read 2026-10-21]
- The declarant's own establishment in those files is `000` (the seat).  [Source: same cahier, « qui doit être égal à 000 »]
- TEJ (2026) keeps only the first 8: `\d{7}[A-Z]`, and reads PM/PP (legal or natural person) from a separate element.
  [Source: DGI portal, TEJDeclarationRS_v1.0.xsd `TypeMatriculeFiscal`, https://jibaya.tn/wp-content/uploads/2026/09/plateforme-TEJ-shemas-xsd.zip, read 2026-10-21]
- Letter sets: code TVA A/B/D/N/P (+F), catégorie C/M/N/P (+E for a secondary establishment), key letter excluding
  I, O, U.  [Unverified: python-stdnum `stdnum.tn.mf` and a TEIF validator README only, and they disagree; no ministry
  text lists them. Do not refuse on them.]
- Key letter algorithm: NO public algorithm found in any ministry or DGI text. Do not compute it, do not invent one
  (an Odoo module's « mod 23 » claim is unsourced).  [Observed absence: the two cahiers and the TEJ XSD define the key
  as one character, no formula]
- How people write it: `1234567A/B/M/000`, `1234567 A B M 000`, `1234567AAM000`, `1234567/A/B/M/000`, or only the
  first 8 `1234567A` (a person without VAT code on hand).  [Inferred: stdnum examples and the TEJ 8-character form]
- Normalise: uppercase; drop spaces, dots, hyphens and slashes; then read `^(\d{7})([A-Z])([A-Z])?([A-Z])?(\d{3})?$` and
  store `1234567A/B/M/000` (slashes are presentation). A missing tail is « À vérifier », not a refusal, unless the
  document being issued needs it.
- Check: shape only. Valid `1234567A/B/M/000` (the preset example; any key letter passes). Invalid `1234567/B/M/000`
  (no key letter → shape). There is no wrong-check-digit example because there is no check.
- Refusal: « Le matricule fiscal s'écrit 7 chiffres, la lettre clé, le code TVA, le code catégorie et 3 chiffres
  d'établissement (exemple : 1234567A/B/M/000). Recopiez-le depuis la carte d'identification fiscale. »

## 2. Tunisian RIB (relevé d'identité bancaire)
- 20 digits: 2-digit bank code, 16 digits whose split is each bank's presentation, 2-digit key. BIAT prints
  `08 / 601000 / 1910007480 / 54` (2+6+10+2); third parties say 2+3+13+2.  [Source: BIAT-issued RIB PDF,
  https://darna.tn/wp-content/uploads/2021/02/RIB-DARNA.pdf, read 2026-10-21; the 3+13 split Unverified]
- Key: the 20-digit number is a multiple of 97, i.e. key = 97 − (first18 × 100 mod 97), range 01-97.
  [Inferred: holds on three published RIBs (BIAT/Darna, HSBC and iban.com examples); no BCT text found]
- Corollary: every Tunisian IBAN is `TN59` + RIB, so the ISO check on the IBAN already checks the RIB key.  [Inferred: 98 − (292300 mod 97) = 59]
- Normalise: digits only (drop spaces, dots, slashes, hyphens). Accept a bare 20-digit RIB where an IBAN is asked, and
  store `TN59` + RIB.
- Valid `10006035183598478831` → `100060351835984788 key 31`, `int % 97 = 0`. Invalid `10006035183598478832` → `mod 97 = 1`.
- Refusal: « Le RIB compte 20 chiffres : banque, agence, compte et clé (exemple : 10006035183598478831). La clé ne
  correspond pas : recopiez-le depuis le RIB imprimé par la banque. »

## 3. IBAN (ISO 13616)
- Country (2 letters), 2 check digits, BBAN; at most 34 characters, no spaces electronically; printed in groups of four.
  [Source: Banco de Portugal IBAN guide (cites ISO 13616 and ISO/IEC 7064:2003 MOD 97-10),
  https://www.bportugal.pt/sites/default/files/anexos/documentos-relacionados/international_bank_account_number_en.pdf, read 2026-10-21]
- Check: drop non-alphanumerics and a leading « IBAN »; move the first 4 characters to the end; letters A=10 … Z=35;
  the number mod 97 must be 1. To compute: put `00`, then 98 − remainder.  [Source: same guide, steps 1-4]
- Per-country length and BBAN shape come from the SWIFT IBAN registry (site off-line on 2026-10-21); Symfony's
  `IbanValidator` carries a synced copy: TN `TN\d{2}\d{2}\d{3}\d{13}\d{2}` (24), FR `FR\d{2}\d{5}\d{5}[\dA-Z]{11}\d{2}` (27).
  [Source: api/vendor/symfony/validator/Constraints/IbanValidator.php:76,145, which cites the SWIFT registry]
- France: 27 characters = FR + 2 + RIB of 23 (bank 5, guichet 5, account 11 alphanumeric, key 2).  [Source: CFONB
  « Le nouveau Relevé d'Identité Bancaire », 2001, https://www.cfonb.org/fichiers/20140124103020_8_4_RIB_Le_nouveau_Releve_d_Identite_Bancaire_2001_03.pdf, read 2026-10-21]
  The French RIB key weights 89/15/3 are [Unverified: educational PDF only]; the IBAN check covers it anyway.
- Valid TN `TN59 1000 6035 1835 9847 8831` → `mod 97 = 1`; FR `FR76 3000 6000 0112 3456 7890 189` (built by us) → `mod 97 = 1`.
  Invalid `TN58 1000 6035 1835 9847 8831` → `mod 97 = 0`.
- Refusal: « L'IBAN commence par le code pays et 2 chiffres de contrôle (exemple : TN59 1000 6035 1835 9847 8831 ; en
  Tunisie, TN59 suivi des 20 chiffres du RIB). Un caractère est faux : recopiez-le depuis le RIB. »

## 4. BIC / SWIFT (ISO 9362)
- 8 or 11 characters: party prefix 4, country 2 (ISO 3166-1 alpha-2; SWIFT also uses XK), party suffix 2, optional
  branch 3 (`XXX` = head office). 8 characters = head office.  [Unverified: ISO 9362:2022 (edition 5, 2022-04) text is
  paywalled; structure from https://en.wikipedia.org/wiki/ISO_9362 read 2026-10-21; abstract on https://www.iso.org/standard/84108.html]
- Whether the 2022 edition lets the party prefix hold digits (our regex says letters only) is [Unverified].
- Examples on bank documents: `BIATTNTT` (BIAT RIB above), `BNORFRPP` / `BNORFRPPBDX` (CFONB 2001).  [Source: per § 2, § 3]
- Normalise: uppercase, drop spaces. Check: shape + known country; no check digit. Valid `BIATTNTT`. Invalid
  `BIATTNTT1` (9 characters → shape).
- Refusal: « Le BIC compte 8 ou 11 caractères : banque, pays, ville, agence (exemple : BIATTNTT). Recopiez-le depuis le RIB. »

## 5. GTIN-8/12/13/14 (EAN, UPC)
- One check digit algorithm for every fixed-length GS1 key: from the digit left of the check digit, weights 3,1,3,1…;
  check digit = next multiple of ten minus the sum.  [Source: GS1 General Specifications Release 26.0 (Jan 2026),
  § 7.9.1 Table 7-8; its 18-digit example, body `37610425002123456` → check digit 9, reproduced]
- Prefixes worth knowing (Table 1-4, GTIN-13): `02` and `20`-`29` Restricted Circulation Numbers within a geographic
  region (in-store weight/price labels), `04` RCN within a company, `952` demonstrations and examples, `977` ISSN,
  `978`-`979` ISBN/ISMN, `980` refund receipts, `981`-`984`/`99` coupons. GTIN-8 (Table 1-5): `000`-`099` and `200`-`299`
  RCN within a company. An RCN SHALL NOT be used outside its environment and only in EAN-8/13, UPC-A/E.
  [Source: GS1 GenSpecs 26.0 § 1.2.2.2.1, Tables 1-4 and 1-5, https://www.gs1.org/docs/barcodes/GS1_General_Specifications.pdf, read 2026-10-21]
- `619` GS1 Tunisia, `300`-`379` GS1 France.  [Source: https://www.gs1.org/standards/id-keys/company-prefix, read 2026-10-21]
- GS1 compares GTINs right-justified on 14 digits (zero-padded).  [Inferred: GenSpecs Table 7-8 alignment; `Barcode::keyOf` does it]
- UPC-E (8 digits, its own expansion rule) fails the EAN-8 check: refused today by ruling (SPEC § 7, 2026-09-17).
- Normalise: trim; a code that is all digits at length 8/12/13/14 claims a GTIN and is checked; anything else is an
  internal code kept as typed.
- Valid `6190000000019`, `9520000000011`, GTIN-8 `95200002`, GTIN-12 `952000000002`, GTIN-14 `95200000000019`, RCN
  `2000000000015`. Invalid `6190000000018` (digit 9 expected).
- Refusal: « Ce code a la forme d'un EAN/UPC (8, 12, 13 ou 14 chiffres) mais son dernier chiffre de contrôle ne
  correspond pas (exemple valide : 6190000000019). Rescannez-le ou recopiez-le chiffre par chiffre. »

## 6. Phone numbers (E.164)
- International form `+` country code (1-3 digits) + national significant number, at most 15 digits.
  [Unverified: ITU-T E.164 text not retrieved (login wall); https://en.wikipedia.org/wiki/E.164 read 2026-10-21]
- Tunisia +216: 8-digit national numbers, no trunk prefix. Mobile `2X` (Ooredoo), `5[0-5,8]X` (Orange), `9X`, `40`,
  `41`, `42`, `44` (Tunisie Télécom), `46` (Ooredoo), `56` (Orange), MVNO `43`, `45`; fixed `7X`; restricted mobility `30`-`32`, `36`, `39`;
  VAS `80`-`82`, `88`.  [Source: ITU national numbering plan for Tunisia, INT communication of 28.IX.2015,
  https://www.itu.int/dms_pub/itu-t/oth/02/02/T02020000D50002PDFE.pdf, read 2026-10-21 — 2015 data, ranges may have moved]
- France +33: national format 10 digits `0Z…` (01-05 geographic, 06-07 mobile, 08 value-added, 09 multipurpose; 0700
  M2M 14 digits); `+33` drops the 0. Overseas departments have their own country codes (+262, +590, +594, +596).
  [Inferred: Arcep pages and decision 2022-1583 seen through search excerpts only, not read; overseas codes Unverified]
- Enforce: digits only after normalising, at most 15, and for TN exactly 8 national digits. Do NOT refuse on leading
  digits (the plan moves); a TN number outside the ranges above is « À vérifier ».
- Normalise: drop spaces, dots, hyphens, brackets; `00` → `+`; a TN 8-digit number with no prefix gets `+216`; a FR
  10-digit `0…` gets `+33` minus the 0 ONLY outside the overseas blocks (`0262`, `0269`, `0508`, `0590`, `0594`, `0596`, `0639`, `0690`, `0694`, `0696` [Unverified: list from recall]); such a number is kept as typed and marked « À vérifier » until the country is confirmed, since Arcep's 01-05/06-07/09 ranges include Guadeloupe, Guyane, Martinique, Réunion, Mayotte and Saint-Pierre-et-Miquelon [Source: Arcep fiche « Plan de numérotation pour les professionnels », read 2026-10-21]. Store E.164, print grouped (`+216 71 900 868`).
- Valid `+216 71 900 868` (INT's own number, ITU doc) → `+21671900868`. Invalid `+216 7190086` (7 national digits). No check digit.
- Refusal: « Un numéro tunisien compte 8 chiffres, un numéro français 10 chiffres commençant par 0 (exemple : +216 71 900 868).
  Indiquez l'indicatif du pays s'il n'est ni tunisien ni français. »

## 7. E-mail
- RFC 5322's grammar is not what a form should accept; HTML's « valid e-mail address » is `1*(atext / ".") "@" label
  *("." label)`, labels ≤ 63, a willful violation of RFC 5322.  [Source: WHATWG HTML, input type=email « valid e-mail address »,
  https://html.spec.whatwg.org/multipage/input.html#valid-e-mail-address, read 2026-10-21]
- Limits: local part ≤ 64 octets, domain ≤ 255, path ≤ 256 including `<` `>`, so an address ≤ 254.
  [Source: RFC 5321 §§ 4.5.3.1.1-3, read 2026-10-21; the 254 is Inferred from the 256 path]
- The local part is case-sensitive (RFC 5321 § 2.4): lowercase only the domain; compare case-insensitively for duplicates.
- Enforce: the WHATWG pattern and ≤ 254. The dot required in the domain is our own rule (`ContactRules::email`): a departure from WHATWG to record in SPEC § 7, or a dotless domain is « À vérifier », not a refusal. Normalise:
  trim; lowercase the domain. Valid `compta@exemple.tn`. Invalid `jean dupont@exemple.tn` (space); `compta@exemple` is WHATWG-valid, « À vérifier » under the rule above.
- Refusal: « Une adresse e-mail s'écrit nom@domaine.extension (exemple : compta@exemple.tn). Vérifiez l'arobase et le point. »

## 8. SIREN, SIRET, NIC (France)
- SIREN 9 digits, the 9th a check digit; SIRET 14 = SIREN + NIC; NIC = 4-digit order number + 1 check digit checking the
  whole SIRET.  [Source: INSEE definitions c2047 (updated 2025-10-21), c1841, c1981, read 2026-10-21]
- The check is Luhn (double every second digit from the right, subtract 9 above 9, total a multiple of 10) over the 9 and over the 14.
  [Inferred: INSEE does not name it; holds on 4 SIRETs read on two INSEE avis PDFs (12002701600365, 12002701600563, 35600000000048; 35600000009075 is the La Poste case) and on 4 more SIRETs taken from INSEE avis URLs in search results, not opened]
- La Poste: SIREN `356000000`; some of its SIRETs fail Luhn and have a digit sum divisible by 5 (`35600000009075`, sum 35),
  while its head office `35600000000048` passes Luhn (sum mod 5 = 1). Rule: Luhn OR (SIREN 356000000 AND sum mod 5 = 0).
  [Source: INSEE avis https://api-avis-situation-sirene.insee.fr/identification/pdf/35600000009075 and the head office on it, read 2026-10-21]
- Normalise: digits only (drop spaces, dots). Valid SIREN `120027016`, SIRET `12002701600365` → `luhn True`.
  Invalid SIREN `120027017`, SIRET `12002701600366` → `luhn False`.
- Refusal: « Le SIREN compte 9 chiffres (exemple : 120 027 016), le SIRET 14 : le SIREN puis 5 chiffres (exemple :
  120 027 016 00365). Un chiffre est faux : recopiez-le depuis l'avis de situation INSEE ou un extrait Kbis. »

## 9. French VAT number (TVA intracommunautaire)
- `FR` + 2-character key + SIREN = 13 characters.  [Source: BOFiP BOI-TVA-DECLA-20-10-20-20220216 §§ 120-130,
  https://bofip.impots.gouv.fr/bofip/1149-PGP.html/identifiant=BOI-TVA-DECLA-20-10-20-20220216, read 2026-10-21]
- BOFiP says the key is 2 digits; VIES writes `FRXX 999999999`, X a letter or a digit.  [Source: VIES
  https://ec.europa.eu/taxation_customs/vies/assets/i18n/en.json `faq_label_FRformat`, read 2026-10-21] Keep letters accepted.
- Key = (12 + 3 × (SIREN mod 97)) mod 97, two digits.  [Inferred: no DGFiP text states it; VIES answered VALID for
  `FR00333721124` computed by it, and INVALID for a wrong key, 2026-10-21 (INVALID also means « not registered »)]
- BOFiP's own example `FR31 734820078` is a dummy: `734820078` fails Luhn and its key would be 28. Never use it as a fixture.
- Normalise: uppercase, drop spaces, dots, hyphens. Syntactically valid `FR85120027016` (key 85 for INSEE's SIREN, not confirmed by VIES); fixture `FR11123456782`
  (the preset's synthetic example). Invalid `FR86120027016` (key 85 expected).
- Refusal: « Le numéro de TVA s'écrit FR, une clé de 2 caractères puis le SIREN (exemple : FR 85 120027016). La clé ne
  correspond pas au SIREN : recopiez-le depuis une facture du client ou vérifiez-le sur VIES. »

## 10. Other EU VAT numbers, postal codes
- EU: each state's shape is VIES data: read `faq_label_<CC>format`, `faq_lbl_remarks`, `faq_txt_notes` in
  https://ec.europa.eu/taxation_customs/vies/assets/i18n/en.json (not a list to copy by hand; `docs/fiscal/FR.md` § 5a
  holds the read of 2026-10-09). Existence: VIES REST `GET …/vies/rest-api/ms/<CC>/vat/<number>`; its
  `MS_MAX_CONCURRENT_REQ` / `MS_UNAVAILABLE` answers are outages, never a refusal.  [Observed: called 2026-10-21]
- No other state's check digits are applied (not sourced here).
- Postal code: Tunisia 4 digits left of the locality (`3100 KAIROUAN`); France 5 digits (`33500 LIBOURNE`, CEDEX codes
  too).  [Source: UPU addressing sheets tunEn.pdf (04/2014) and fraEn.pdf (09/2011),
  https://www.upu.int/UPU/media/upu/PostalEntitiesFiles/addressingUnit/, read 2026-10-21] Enforce `^\d{4}$` / `^\d{5}$`
  only when the address's country is TN / FR; no check digit. Valid `1002`, `75001`; invalid `100`, `7500`.
- Refusal: « Un code postal tunisien compte 4 chiffres (exemple : 1002), un code postal français 5 (exemple : 75001). »

## 11. Where the code stands (survey of 2026-10-21; `git grep` over api/src, api/config, web/src)
| Identifier | Validated today (file:line) | What is checked | Gap against this skill |
|---|---|---|---|
| ALL, imports | `ImportExport/Infrastructure/Validation/ResourceRules.php:34-53`, `ImportExport/Application/RowFaults.php:52`; vendors `Module/Vendors/Infrastructure/Import/VendorImport.php:175`; texts `import.rejections.{identifier_shape,identifier_checksum,invalid_iban,invalid_bic,invalid_phone,invalid_email,invalid_barcode}` | a bad cell rejects the whole row | Ruling unmet: keep the row, mark « À vérifier » with the reason. One class, every importer naming `RowRejected`: customers, vendors, products (`ProductImport.php:111` `invalid_barcode`), product vendors, opening stock |
| ALL, refusal texts | `fr.json` `import.rejections.identifier_shape` « n'a pas la forme attendue », `invalid_iban` « IBAN refusé. »; API `Fiscal/Application/Preset/IdentifierRules.php:64,67` | reason code only | No format, example or action in any identifier refusal (the barcode `check_digit` text has an action, no example) |
| Matricule | `api/config/fiscal/TN.yaml:23` `^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$`; `Module/Expenses/Application/Tej/TejMatricule.php:23` slashes optional | shape | Preset requires slashes and all 13: `1234567AAM000`, `1234567A` refused though TEJ reads them; normalise per § 1 |
| Company identifiers | `Tenancy/Domain/ValueObject/CompanyProfile.php:65` (trim only) vs `Shared/Domain/Service/RegistrationNumbers.php:26` (customers, vendors: strip spaces, uppercase) | — | The company's own matricule/SIREN typed in lowercase or with spaces is refused; one normaliser for all holders |
| SIRET | `Fiscal/Domain/ValueObject/IdentifierCheck.php:31` | Luhn OR the La Poste sum (fixed 2026-10-21) | Closed: La Poste's head office `35600000000048` is accepted, `IdentifierCheckTest` holds both ways in |
| SIREN | `IdentifierCheck.php:30` Luhn; `api/config/fiscal/FR.yaml:23-26` | shape + Luhn | Luhn is Inferred, not issuer-published: by the ruling, a refusal is defensible only as INSEE's « chiffre de contrôle »; keep, cite § 8 |
| FR VAT | `IdentifierCheck.php:68`; `FR.yaml:33-38` | key formula when digits | Formula not published by DGFiP (§ 9): the ruling says « À vérifier », not a refusal, unless the developer rules the formula enforceable |
| EU VAT | `FR.yaml:42-70` `foreign_patterns` | VIES shapes | None (sourced); no VIES existence check (optional) |
| TN RIB | no field; IBAN only | — | A 20-digit RIB typed in an IBAN field is refused; accept it and prepend TN59 (§ 2) |
| IBAN | `Shared/Domain/Service/ContactRules.php:55,89-97` (mod 97) + `Assert\Iban` at `Module/Vendors/Infrastructure/ApiPlatform/Resource/VendorResource.php:169`, `Tenancy/Infrastructure/ApiPlatform/Resource/CompanyProfileResource.php:136` | mod 97 + country length | Correct; two checks stacked on vendors, one on the company (no domain rule there) |
| BIC | `ContactRules.php:53,81-86`; `CompanyProfileResource.php:140` `Assert\Bic` | shape + country | Two different rules for one identifier; letters-only prefix vs ISO 9362:2022 Unverified |
| GTIN | `Module/Products/Domain/ValueObject/Barcode.php:39,72-91`; `Gs1Scan.php:152` | GS1 mod 10 at 8/12/13/14 | Correct per GS1; UPC-E refused by ruling; RCN prefixes 02/20-29 not told apart (an in-store weight label is looked up as a GTIN) |
| Phone | `ContactRules.php:45` `\+?[0-9 ().\-]{3,40}`; copies `Module/Customers/Infrastructure/ApiPlatform/Resource/ContactResource.php:89`, `CompanyProfileResource.php:127`, `Settings/Application/SupportSettings.php:33` (`{6,30}`), `web/src/app/customers/customer-forms.ts:41` | characters only | No E.164 normalisation, no digit count, four copies of the rule with two lengths |
| E-mail | `ContactRules.php:43` (WHATWG + dot, customers/vendors); `Assert\Email` at `ContactResource.php:84`, `CompanyProfileResource.php:122`, `EstablishmentResource.php:97`, `MemberResource.php:73`, `PlatformOwnerInvitationResource.php:46`; `SupportSettings.php:36`; `DeclareTejWithholdings.php:33` (the TEJ XSD's own pattern, keep) | three different rules | One canon (`ContactRules::email`) everywhere but the TEJ file |
| Postal code | `Shared/Domain/Model/PostalAddress.php:26,57` | length ≤ 20 | No 4-digit TN / 5-digit FR rule by country (§ 10) |
| Web | `web/src/app/company/company-profile-form.ts`, `customers/customer-forms.ts`, `vendors/vendor-forms.ts` | preset `pattern` only | Nothing normalised client-side but `countryCode`; the preset `pattern` is handed to the field as is (`customer-forms.ts:268`), so a pasted `1234567 a/b/m/000` shows red though the API would normalise it [Inferred: pattern applied before any normalising] |
