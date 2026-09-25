# Tunisia: what the tax administration requires, and what twes-in can produce

Research of 2026-09-25, for docs/fiscal/TN.md (read, not modified). Not legal advice; every rule still needs a
Tunisian expert-comptable. Grades: **[Verified: url]** = I read that document; **[Inferred: basis]**;
**[Unverified: why]**.

**Access note.** The Tunisie TradeNet sites could not be read from this host on 2026-09-25: `elfatoora.tn`
(ECONNREFUSED on 443 and 80), `www.tradenet.com.tn` (no answer), `www.ttn.tn` (401), `legislation.tn` (503),
and the Wayback Machine answered 429. So nothing below about TTN's fees, enrolment or test platform is primary.
The DGI portal **jibaya.tn** (Direction Générale des Impôts) and **tuntrust.tn** were readable; the notes communes
read are PDFs in Arabic (translation mine). No JORT PDF was read; the arrêté of 10 May 2024 was read on the 9anoun
mirror.

## Summary

| Obligation | Who, from when (2026-09-25) | Format / channel | What twes-in can do | Partner needed |
|---|---|---|---|---|
| **TEJ withholding certificates** (certificat de retenue à la source) | every payer that withholds; phased 2024-06-01 → **all remaining taxpayers 2026-01-01** | XML `DeclarationRS` v1.0 + XSD, uploaded on tej.finances.gov.tn; cahier des charges **Sept 2026** | generate the monthly XML from supplier payments (row 111) | none (the company uploads with its own TEJ account) |
| **Monthly declaration** (TVA, RS, timbre, FODEC, TCL…) | every real-regime VAT payer, monthly (15th / 28th) | web form on the télédéclaration site, DigiGo or USB certificate; **no import file or API found** | a worksheet per tax with the amounts to type in | none |
| **El Fatoora** e-invoice | DGE firms ↔ public sector; B2B medicines and fuel; **all service providers from 2026-01-01** once enrolled | TEIF XML 1.8.8 + XAdES-B signature, sent to TTN (SOAP web service or SFTP), TTN reference + QR | generate and validate TEIF; signing and sending need the two partners | TTN enrolment per customer; TunTrust certificate / DigiGo integration |
| Suspended-VAT invoice list | issuers under VAT suspension, quarterly | fixed-width ASCII `FAC_Tn_yy` (2007 cahier) on CD | export from invoices with the `suspended` regime | none |
| Employer declaration (annual) | every withholding payer | fixed-width ASCII (DECEMP/ANXEMP, cahier 2025); 2025 filing **exclusively via TEJ** | annexes II/V from supplier withholdings only; payroll is out of scope | none |
| Liasse fiscale | companies filing electronically | XML F6001–F6006 via TEJ | out of scope (accounting) | — |
| Bookkeeping | real regime | keep 10 years; deposit a copy of the accounting program with the tax office | no Tunisian FEC equivalent found | — |

## 1. El Fatoora (electronic invoicing through TTN)

### 1.1 Legal basis and scope, as in force on 2026-09-25

- **The option and its conditions.** Code de la TVA art. 18-II ter lets VAT payers issue electronic invoices,
  "registered with a structure authorised to manage the automated system of electronic invoices"; they carry the
  same mandatory mentions as paper, the issuer's electronic signature (the seller, the service provider, **or a
  person the issuer authorises for that purpose**) and a unique reference assigned by that structure
  [Verified: NC 10/2025 §I and NC 02/2026 §I.2, https://jibaya.tn/docs/note-commune-n10/ ,
  https://jibaya.tn/docs/note-commune-n02-2026/ (Arabic PDFs)].
- **Décret gouvernemental n° 2016-1066 du 15 août 2016** designates Tunisie TradeNet (TTN) as manager of the system;
  TTN must register and keep the invoices, give a copy to the issuer or receiver on request, and send copies
  automatically to the Ministry of Finance. The issuer must file a declaration (administration model) with its
  tax office together with TTN's certificate of membership, and give a paper copy on request or when goods travel,
  worded *« Copie de la facture électronique enregistrée auprès de [TTN] sous la référence unique n° … »*, with its
  stamp and signature, which a **visible electronic seal** may replace [Verified: NC 02/2026 §I.2, which recites the
  decree; the decree itself not read, legislation.tn down].
- **Mandatory scope until 31 December 2025:** operations of firms under the Direction des grandes entreprises (DGE)
  with the State, local authorities and public establishments and enterprises; sales of medicines and fuel between
  professionals, retailers excepted [Verified: NC 02/2026 §I.2; DGI notice
  https://jibaya.tn/blog/avis-aux-contribuables-concernes-par-la-facturation-electronique/]. The year each part came
  in (2016 for DGE, 2019/2020 for medicines and fuel) [Unverified: only press, e.g.
  https://www.albawsala.com/fr/publications/20267325].
- **LF 2024 (loi n° 2023-13): no extension.** Nothing on e-invoicing was found, and NC 02/2026's recap of the law in
  force to 31 Dec 2025 lists only the two cases above [Inferred: that recap; a search found only LF 2024 art. 33, on
  company creation].
- **LF 2025 (loi n° 2024-48 du 9 décembre 2024) art. 71: penalties only.** 100 to 500 TND per paper invoice issued
  for an operation that must be electronic, capped at 50,000 TND for all invoices found; the penalty for missing
  mentions now also covers electronic invoices; goods may travel with a paper copy of the e-invoice or a document
  standing in for it (delivery note). The paper-invoice fine applies to offences **from 1 July 2025**, the
  missing-mentions fine **from 1 January 2025** [Verified: NC 10/2025 summary §1–5]. The amount of the
  missing-mentions fine (250 to 10,000 TND, TN.md §12) [Unverified: in the NC's annex table, not read closely].
- **LF 2026 (loi n° 2025-17 du 12 décembre 2025) art. 53: services.** From **1 January 2026** e-invoicing is
  compulsory for **service provision operations**, in addition to the two cases above. In practice it binds the
  providers who declared services as their main **or secondary** activity in their déclaration d'existence;
  "provider" includes natural persons and the non-commercial professions, so **notes d'honoraires** are covered
  [Verified: NC 02/2026 §II.1].
  - **Out of scope:** documents standing in for an invoice (contracts, debit notes, account statements accepted by
    sector custom); services accessory to the main activity (transport on sale, installation with sale) unless
    declared as a secondary activity [Verified: NC 02/2026 §II.2].
  - **Transitional rule, load-bearing:** the obligation applies from 1 Jan 2026 to providers **who have joined
    the network and met its conditions**; those who filed a membership request with TTN and have not completed it
    **keep issuing paper invoices**; those bound must file the request [Verified: NC 02/2026 §III, citing LF 2026
    arts 53 **and 110**; art. 110 itself not read, Unverified].
  - Sales of goods between private companies (outside medicines and fuel) are still **not** in scope [Inferred:
    NC 02/2026 lists no such case].
- **Consequence for twes-in:** a Tunisian service company using twes-in must, from its TTN enrolment, issue its
  invoices through El Fatoora; until then, paper/PDF stays lawful. VAT deduction by the buyer survives a supplier's
  failure to e-invoice if the buyer holds a compliant paper invoice [Verified: NC 02/2026 §I.3].

### 1.2 The TEIF format

- TEIF (Tunisian Electronic Invoice Format) is an XML structure with EDIFACT INVOIC-style segments, not UBL or CII:
  root `TEIF` with `@version` and `@controlingAgency="TTN"`, `InvoiceHeader` (sender and receiver identifiers),
  `InvoiceBody` with `Bgm` (number, document type code, e.g. `I-11` Facture), `Dtm` (dates, `functionCode` I-31…),
  `PartnerSection` (`Nad`, function I-62 seller / I-64 buyer), `PytSection`, `LinSection` (lines: `LinImd`, `LinQty`,
  `LinTax`, `LinMoa`), `InvoiceMoa` (totals, amount type codes I-176…I-181), `InvoiceTax`, then `ds:Signature`
  elements and, after TTN validation, `RefTtnVal` [Inferred: types in the Apache-2.0 middleware
  https://github.com/tekru-labs/elfatoora-middleware (`src/business-logic/teif/teif-types.ts`), which builds
  version `1.8.8`].
- **Current version 1.8.8**, published by TTN as two XSD **1.1** files (`facture_INVOIC_V1.8.8_withSig.xsd`,
  `…_withoutSig.xsd`), with a *Guide d'Implémentation TEIF V2.0* (18 code lists, 136 codes in its Annexe A) and the
  *Spécifications Techniques de la Signature Fournisseur V3.0* (XAdES-B, UTF-8) [Inferred: the MIT validator
  https://github.com/ayoubgaouet/tn-einvoice-validator (v1.0.0, 2026-08-29) vendors what it says are byte-for-byte
  TTN files; the TTN download page was unreachable].
- Traps the same README documents: XSD 1.1 (`xs:assert`, `xs:alternative`) is not supported by libxml, so PHP cannot
  validate the official schema as is; the XSD's matricule assertion accepts VAT codes A/B/D/N/P, categories C/M/N/P
  and establishment `000` only, while the Guide also allows `F` (forfaitaire) and category `E` with `001`…, so a
  secondary establishment is rejected by TTN's schema; five official codes are missing from the XSD, among them
  **I-1604 « Retenue à la source »**; `I-1602` is TVA [Inferred: same source].
- Mandatory content = the Code de la TVA art. 18-II mentions (date, customer identity and address and its matricule
  when it must declare its existence, the supplier's matricule, designation and price excluding VAT, VAT rates and
  amounts, suspended VAT) + the signature + the TTN reference [Verified: NC 10/2025 §I]. The field-level list
  (which TEIF elements are `minOccurs=1`) [Unverified: official XSD not read directly].
- Credit notes and debit notes have their own document type codes [Inferred: the middleware's schema offers
  INVOICE / CREDIT_NOTE / DEBIT_NOTE; codes not read].

### 1.3 Electronic signature (ANCE / TunTrust)

All prices read on tuntrust.tn (the site's certificate chain is incomplete; read with `curl -k`):

- **DigiGo** (remote signature on the mobile): qualified signature, SHA-256, formats **PAdES, XAdES, CAdES**; certificate
  **50 TND HT for two years** since 1 Jan 2024; it is also what the télédéclaration and CNSS accept
  [Verified: https://www.tuntrust.tn/fr/solutions/digigo ;
  https://www.tuntrust.tn/fr/nos-actualites/Nouveaux-tarifs-des-certificats-ID-Trus-et-DIGIGO].
- **Signing through an integrator:** TunTrust sells prepaid DigiGo signature tokens to partners, **0.250 TND HT each
  for 1–10,000 (2,500 TND)** down to **0.140 TND above 50,000 (from 7,000 TND)**, valid one year; to integrate, a
  company signs a convention as *entité d'intégration* and consumes TunTrust's DigiGo API [Verified: same DigiGo
  page]. Each signature is authorised by the signer on the phone (OTP) [Verified: same page, "OTP par SMS ou
  email"], so DigiGo is interactive, not unattended.
- **Enterprise-ID electronic seal** (for a legal person, names its matricule): certificate on **HSM**, ECDSA-256,
  ETSI EN 319 411-1/-2, usages "factures électroniques"; **1,150 TND HT one year, 2,215 TND HT two years**; HSM set-up
  on request [Verified: https://www.tuntrust.tn/fr/nos-produits/cachet-electronique-enterprise-id]. This is the only
  unattended option.
- **ID-Trust** USB token: 350 TND HT for two years (2020 price, still listed) [Verified: the communiqué above].
- A SaaS may sign **on behalf of** its customer if the customer authorises it (art. 18-II ter, "the person authorised
  by the issuer") [Verified: NC 02/2026 §I.2]; whether TTN accepts a signature by the editor's own seal rather than the
  customer's [Unverified: TTN documentation unreachable].

### 1.4 Connecting a software to TTN

- **Web service:** SOAP 1.1, document/literal, service `EfactService` at
  `http://elfatoura.tradenet.com.tn:80/ElfatouraServices/EfactService`, operations **`saveEfact`** (body: `login`,
  `password`, `matricule`, `documentEfact` = the signed TEIF in base64), **`consultEfact`** (status and TTN
  reference, polled) and **`verifyQrCode`**; synchronous, errors as SOAP faults [Inferred: WSDL and code in
  tekru-labs/elfatoora-middleware `docs/ttn/`]. Observation, not a fact about TTN: that endpoint is plain HTTP with
  the password in the body.
- **Other channels:** SFTP (EDI) and a web portal for manual upload [Inferred: same repo; Unverified: finco.tn,
  efacturetn.com].
- **Enrolment ("adhésion")** is by the **invoicing company**, not the software: free; since 15 Feb 2026 online at
  adhesion.elfatoora.tn; the company picks EDI mode, SOAP or SFTP, and gives **the IP address its software sends
  from**; TTN returns a signed contract and credentials, then validates, which "can take several days to several
  months" [Unverified: https://efacturetn.com/fr/adhesion-elfatoora-ttn, a competitor's page; TTN down]. There is
  no evidence of a separate "opérateur" licence for software editors [Inferred: none in NC 02/2026, NC 10/2025 or
  the secondary sources read]. A claim that software must be "homologated by ANCE" (finco.tn) has no primary source
  [Unverified].
- **Fees:** 10 TND a month + **0.190 TND HT per invoice** of up to 50 KB [Unverified: finco.tn and efacturetn.com;
  TTN tariff unreachable].
- **Test environment:** said to exist before production [Unverified: finco.tn only].
- **What comes back:** the TTN unique reference, a QR code, and TTN's seal on the XML (`RefTtnVal`); the paper or
  PDF copy prints the reference sentence above and the QR [Verified for the reference sentence: NC 02/2026 §I.2;
  Inferred for the QR: `verifyQrCode` in the WSDL and the middleware's `qr_code_base64` migration]. Infrastructure
  saturated in January 2026 (hours-long outages, long certificate queues) [Unverified: press, albawsala].

## 2. Tax declarations

### 2.1 Monthly declaration

- Every VAT payer outside the forfait declares monthly, even with nothing due: first **15 days** (natural persons),
  first **28 days** (legal persons) [Verified: https://www.finances.gov.tn/fr/apercu-general-sur-la-fiscalite — a
  stale page, see TN.md §2; same rule in Code TVA art. 18-I per TN.md §2a].
- One form carries TVA (collected, deductible, credit), retenues à la source operated in the month by nature, droit
  de timbre, FODEC, TCL, TFP/FOPROLOS on payroll, and others [Unverified: form not read; secondary
  https://www.proservy.com/blog/imposition-sur-les-societes-en-tunisie-tout-savoir-sur-les-declarations-mensuelles-obligatoires].
  **TCL**: 0.2 % of local gross turnover, 0.1 % on exports, annual minimum [Verified: finances.gov.tn aperçu, stale].
- **Filing:** the *télédéclaration* site (teledecgo.finances.gov.tn): adhesion form or online adhesion, then a
  TunTrust certificate (DigiGo or USB), then the declaration is **liquidated online**, paid by a direct-debit
  authorisation [Verified: https://jibaya.tn/blog/la-tele-declaration-fiscale/]. That page describes **no import
  file and no API**; the only file deposits the DGI documents are TEJ and the "support magnétique" list below
  [Inferred: jibaya's service menu]. Which taxpayers **must** télédéclarer [Unverified: not researched].
- **What twes-in can prepare:** a per-month worksheet: VAT collected by rate from issued invoices and credit notes
  (TN.md §2a taxable event), VAT deductible from expenses, stamp duties charged (count × 1 TND), FODEC by product,
  TCL base, and withholdings the company **operated** as a payer grouped by TEJ operation code. The person types it
  in.

### 2.2 TEJ: withholding certificates (the one mandatory file)

- **Law:** LF 2022 art. 41 amended Code IRPP/IS art. 55: certificates of withholding must be drawn up on the
  Ministry's electronic platform. A certificate given outside it: fine of **30 % of the tax withheld, minimum 50 TND
  per certificate**; the beneficiary's deduction is **limited to what is on the platform**
  [Verified: https://jibaya.tn/blog/plateforme-de-transfert-et-echange-des-donnees-fiscales-tej/].
- **Arrêté de la ministre des finances du 10 mai 2024** (JORT 2024-060): payers of DGE and DME + accounting and tax
  professionals from **1 June 2024**; télédéclarants from **1 January 2025**; **all remaining taxpayers from
  1 January 2026**; the State, local authorities and public administrative establishments excluded. Covers
  withholdings of Code IRPP/IS art. 52 and **Code TVA arts 19 and 19 bis** (so the 25 % VAT withholding by public
  buyers too). Deadline: **end of the month following the payment**; spontaneous corrections until 30 April of the
  following year [Verified: 9anoun mirror
  https://www.9anoun.tn/fr/kb/jorts/jort-2024-060-90d29/arrete-de-la-ministre-des-finances-du-10-mai-2024-fixant-le-champ-dapplication-de-la-procedure-de-l-elaboration-des-certificats-de-retenue-a-la-source-a-travers-la-plateforme-elect-9a1276292df40ad1b65049953f48e182].
- **Format** (cahier des charges **Septembre 2026**, 64 pages, + XSD zip) [Verified:
  https://jibaya.tn/wp-content/uploads/2026/09/cahier%20des%20chargesRS_TEJ_09_26.pdf ,
  https://jibaya.tn/wp-content/uploads/2026/09/plateforme-TEJ-shemas-xsd.zip (`TEJDeclarationRS_v1.0.xsd`,
  `TEJRSCodesOperations_v1.0.xsd` dated 2026-09-15, `TEJISOPaysDevises.xsd`)]:
  - XML 1.0, UTF-8, `.xml`; file name `[MATRICULE 7 digits + key]-[YYYY]-[MM]-[acte].xml`, acte 0 = initial,
    1 = rectificative (example `0001238L-2024-01-0.xml`); two checks on upload: schema, then content.
  - Root `DeclarationRS` (`DeclarationsRS` in the XSD) `@VersionSchema="1.0"`: `Declarant` (only matricule fiscal
    accepted in this phase), `ReferenceDeclaration`, then `AjouterCertificats` / `ModifierCertificats` /
    `AnnulerCertificats`. A certificate carries `Beneficiaire` (identifier, name, address, activity, contact),
    `DatePayement`, `Ref_certif_chez_declarant` (the payer's own reference), `ListeOperations/Operation`
    (`@IdTypeOperation`, `AnneeFacturation`, `MontantHT`, `TauxRS`, `TauxTVA` (decimal 0–100; a `TypeTauxTVA` enumeration 7/13/19 also exists, which one
    applies is unchecked), `MontantTVA`,
    `MontantTTC`, `MontantRS`, optional `TaxeAdditionnelle`, `MontantNetServi`, optional `Devise` with exchange
    rate) and `TotalPayement`. `MontantHT` is an integer in millimes ("sans partie décimale"); the other amounts
    likely the same [Inferred: read on `MontantHT` only].
  - 47 operation codes, RS1…RS11 (rents, fees, capital income, dividends, disposals, purchases, board fees,
    non-residents, salaries, gambling). The ones twes-in's supplier payments hit:

    | Code | Label (abridged) | TN.md §5a rate |
    |---|---|---|
    | RS7_000001 | purchases ≥ 1,000 TND VAT incl., supplier at a corporate rate other than 15 % and 10 % | 1.5 % |
    | RS7_000002 | same, supplier at the 15 % corporate rate | 1 % |
    | RS7_000003 | same, natural person with the two-thirds deduction or company at 10 % | 0.5 % |
    | RS7_000006 | same, supplier exempt from withholding | 0 |
    | RS2_000002 | fees to BNC professionals under the real regime | — |
    | RS1_000002 | rents to resident established persons | — |

    [Verified: the codes and labels, `TEJRSCodesOperations_v1.0.xsd`; Inferred: the rate column, which the XSD does
    not carry — it is TN.md §5a's split matched by label].
- **So the "certificat de retenue à la source" is no longer a document twes-in prints:** the platform issues it;
  the app's deliverable is the XML (or the data to type in). The beneficiary can check on TEJ whether its customer
  must use it [Verified: TEJ page, `https://tej.finances.gov.tn/tax-file`].
- TEJ is a web upload with a login; no machine API for third-party software is documented [Inferred: the cahier
  describes only a file deposit on the site].

### 2.3 Annual declarations

- **Déclaration d'employeur** (annual recap of all withholdings): a 51-record recap `DECEMP_yy` (38 characters per
  record) + annexes `ANXEMP_n_yy_1` (399 characters): I salaries, **II fees, rents, commissions to residents**,
  III capital income, IV non-residents, **V other amounts withheld** (where the 1 % purchases fall [Inferred]), VI
  rebates and cash sales,
  VII amounts paid for others; ASCII fixed-width, historically on CD-ROM [Verified: cahier 2025
  https://jibaya.tn/wp-content/uploads/2026/01/EMPCCA_25V3.pdf]. For 2025 it is filed **exclusively via TEJ**, by
  30 April 2026, "per the updated cahier des charges" [Verified:
  https://jibaya.tn/blog/avis-la-dgi-informe/]; the TEJ-specific cahier for it was not read [Unverified].
- **Déclaration annuelle IS / IRPP** and **liasse fiscale** (XML F6001–F6006 via TEJ, four sector variants, XSDs
  published) [Verified: TEJ page]. These are accounting outputs, not invoicing ones.

## 3. Lists, books and audit

- **Suspended-VAT invoices:** issuers selling under VAT suspension deposit a **quarterly** detailed list, file
  `FAC_T<n>_<yy>`, records FACDEB/FACDET/FACFIN of 231 characters, ASCII [Verified: 2007 cahier
  https://jibaya.tn/wp-content/uploads/2023/11/cah_charg_dep_fact_compressed.pdf, still linked from
  https://jibaya.tn/blog/depot-sur-support-magnetique/]. Buyers deposit the list of their suspended purchase orders
  (another cahier, not read). Both matter to twes-in's `suspended` customer regime (TN.md §9).
  Its header confirms four of TN.md §8's five matricule parts: 7 digits, key letter, category letter, 3-digit
  establishment number, with **no VAT-code field** [Verified: same PDF]; the key letter excludes I, O and U [Inferred: validator README citing the Guide TEIF
  §5.2.1.1]. Useful for TN.md §8.
- **No "état clients / fournisseurs"** annual listing was found beyond the above [Inferred: jibaya's deposit list];
  public companies can now read their suppliers' tax status on TEJ [Verified:
  https://jibaya.tn/blog/avis-nouvelles-fonctionnalites-a-la-plateforme-tej/].
- **Books:** accounts per the enterprise accounting legislation; **all documents kept ten years**; a computerised
  accountant must **deposit the initial or modified program on magnetic media** with the tax office and declare the
  hardware, its location and any change [Verified: Code IRPP/IS art. 62 on the 9anoun mirror
  https://9anoun.tn/fr/kb/codes/code-impot-sur-revenu-personnes-physiques-impot-sur-les-societes/code-impot-sur-revenu-personnes-physiques-impot-sur-les-societes-article-62].
  NC 28/2018 confirms: software-printed invoices are exempt from the printer's register **but** stay subject to that
  program deposit [Verified: https://jibaya.tn/wp-content/uploads/2024/02/Note-Communne-N°28.pdf]. Loi n° 96-112
  (système comptable des entreprises) [Unverified: not read].
- **FEC-like audit file:** none found. The Tunisian structured annual files are the liasse fiscale and the employer
  declaration [Inferred: jibaya service list; a search for a Tunisian audit file standard returned only French FEC
  pages and a vendor's claim of an 18-column "FEC" for the DGI, unsourced].

## 4. Cash registers

Out of this note. DGI page: https://jibaya.tn/blog/nacef-le-systeme-national-de-caisses-enregistreuses-fiscales-2/
(NACEF, national system of fiscal cash registers).

## 5. Conclusion: what to build, in order

1. **TEJ `DeclarationRS` export** from supplier payments with withholding (row 111 already records rate and
   amount). Mandatory for every Tunisian payer since 1 Jan 2026, 30 % fine per missing certificate, public XSD,
   no signature, no partner, no fee. Needs: the supplier's identifier and type, the operation code per payment
   (RS7_* by supplier regime, RS2/RS1 when natures arrive), integer millimes, the file name rule, rectificative
   and cancellation files, and an XSD validation test against the three published XSDs. **Effort: ~1–2 weeks.**
2. **Monthly declaration worksheet** (TVA collected / deductible by rate, stamps, FODEC, TCL base, withholdings
   by code). Data only; the person types it into the télédéclaration. **~1 week**, most of it already computed.
3. **Suspended-VAT quarterly list** `FAC_*` for companies selling under suspension. Fixed-width ASCII, small.
   **~2–3 days**; confirm with an accountant that the 2007 cahier is still the one in force.
4. **TEIF 1.8.8 generation + local validation** (unsigned), with the TTN reference and QR slot on the PDF. The two
   reference implementations found are MIT and Apache-2.0, both on twes-in's permitted list, but read them for
   behaviour only and build from the Guide, and note PHP cannot load the official XSD 1.1 directly. Needs the
   official Guide V2.0 and XSD from TTN (unreachable today). **~2–3 weeks.**
5. **Signing + sending to TTN: needs partners.** Per customer: TTN enrolment naming twes-in's egress IP (or
   SFTP), credentials kept per company, polling `consultEfact`. Signature: either the customer's DigiGo (interactive,
   a phone OTP per signature — fine for a few invoices a month) through a TunTrust *entité d'intégration*
   convention and prepaid tokens (0.14–0.25 TND HT each), or the customer's Enterprise-ID seal on an HSM
   (1,150 TND HT/year) for unattended signing. Plus ~0.19 TND per invoice to TTN [Unverified]. The alternative is
   to hand off signing and transmission to an existing Tunisian middleware. **~4–6 weeks of work plus a contract
   lead time of months; a one-way door for a self-hosted edition** (egress IP and certificates are per
   installation).

**Disproportionate or impossible for a small editor now:** payroll and the full employer declaration; the
liasse fiscale; filing the monthly declaration for the user (no API); becoming a TunTrust certification partner
before there are Tunisian customers; an HSM for every self-hosted install. **Deferred as TN.md already says:** art.
19 bis VAT withholding by public buyers — but note it is inside TEJ's scope and inside El Fatoora's original
DGE ↔ public-sector scope, so it returns together with public-sector invoicing.

**To confirm with a Tunisian expert-comptable before building:** whether a small service company that has not
applied to TTN is exposed today (NC 02/2026 §III says the pending ones stay on paper; the fine targets those bound);
whether the editor may sign with its own seal on the customer's mandate; the RS7 code ↔ rate mapping; the current
suspended-VAT list format.

## Sources actually read

1. NC 02/2026 (LF 2026 art. 53), Arabic PDF, https://jibaya.tn/wp-content/uploads/2026/01/ (from https://jibaya.tn/docs/note-commune-n02-2026/)
2. NC 10/2025 (LF 2025 art. 71), Arabic PDF, from https://jibaya.tn/docs/note-commune-n10/
3. NC 28/2018 (invoicing by software), https://jibaya.tn/wp-content/uploads/2024/02/Note-Communne-N°28.pdf
4. DGI notice on e-invoicing, https://jibaya.tn/blog/avis-aux-contribuables-concernes-par-la-facturation-electronique/
5. TEJ page, https://jibaya.tn/blog/plateforme-de-transfert-et-echange-des-donnees-fiscales-tej/
6. TEJ cahier des charges RS, Sept 2026, and XSD zip (links in §2.2)
7. DGI notices on TEJ, https://jibaya.tn/blog/avis-la-dgi-informe/ and https://jibaya.tn/blog/avis-nouvelles-fonctionnalites-a-la-plateforme-tej/
8. Télédéclaration page, https://jibaya.tn/blog/la-tele-declaration-fiscale/
9. Support magnétique page and the 2007 suspended-invoice cahier, https://jibaya.tn/blog/depot-sur-support-magnetique/
10. Employer declaration cahier 2025, https://jibaya.tn/wp-content/uploads/2026/01/EMPCCA_25V3.pdf
11. Arrêté du 10 mai 2024, 9anoun mirror (link in §2.2)
12. Code IRPP/IS art. 62, 9anoun mirror (link in §3)
13. Ministère des Finances, aperçu (stale), https://www.finances.gov.tn/fr/apercu-general-sur-la-fiscalite
14. TunTrust DigiGo, Enterprise-ID, tariff communiqué (links in §1.3)
15. tekru-labs/elfatoora-middleware (Apache-2.0): WSDL doc, TEIF types, submit code
16. ayoubgaouet/tn-einvoice-validator (MIT): README, xsd/README
17. Secondary: albawsala.com, finco.tn, efacturetn.com, swiver.io (TEJ), lapresse.tn, proservy.com
