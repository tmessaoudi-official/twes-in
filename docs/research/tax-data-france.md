# France: what data the DGFiP requires, and what twes-in can produce

Research of 2026-09-25, for the question « can the app generate the data the French tax administration requires,
and work with les impôts? ». Nothing here is legal advice; like `docs/fiscal/FR.md`, every rule is `unvalidated`
until an expert-comptable confirms it. Grades: **[Verified: url]** = I read that page or file myself this session;
**[Inferred: basis]**; **[Unverified: why]** (usually: only a search snippet or a secondary source, primary page
not read, or the primary refused the fetch — Légifrance 404'd three times, economie.gouv.fr answered 403).

## Summary

| Obligation | Who / when | Format / channel | Can twes-in do it? | Build / partner / skip |
|---|---|---|---|---|
| **Receive** e-invoices | Every VAT-registered business established in France, since **1 Sept 2026** | Through a **plateforme agréée (PA)** only | No — reception is the PA's job; the customer already has one | Skip (read-only import later) |
| **Issue** e-invoices (B2B domestic) | GE + ETI since **1 Sept 2026**; PME + micro **by 1 Sept 2027** | Structured invoice (Factur-X / UBL / CII « socle ») sent **through a PA** | Yes as a **« solution compatible »**: produce the file, hand it to a PA by API | **Build** file + **partner** (PA API) before 1 Sept 2027 |
| **4 new mentions** | On every invoice in scope | SIREN of customer, operation category, débits option, delivery address | Yes, data model already has 2 of them | **Build first** |
| **e-reporting of transactions** (B2C, international) | Same calendar as issuing | Via the PA: B2C = daily totals by VAT rate; international B2B = per invoice | Yes: compute the aggregates; the PA transmits | **Build** data + **partner** |
| **e-reporting of payments** | Only where VAT is due on receipt (services, advances), not under débits option or reverse charge | « Encaissée » status on the e-invoice, or a payment flow: date + amount **split by VAT rate** | Yes: payments must be split by rate | **Build** |
| **VAT return CA3** (CA12 abolished 1 Jan 2027) | All non-franchise businesses, monthly or quarterly | Online only: EFI (the business types it on impots.gouv.fr) or EDI (EDIFACT through a *partenaire EDI*) | Worksheet yes; filing = EDI partner (disproportionate) | **Build worksheet**; filing stays with the accountant |
| **FEC** | Businesses keeping computerised accounts, on audit (LPF L47 A) | 18-field flat file, `SirenFECAAAAMMJJ` | No: needs the whole ledger of the exercise | **Skip** (SPEC ruling); offer a FEC-shaped *journal import* file instead |
| **Retention** | 6 years (tax), 10 years (commercial) | Electronic documents kept electronically | Yes (issued documents immutable) | Build retention policy + export |
| **Piste d'audit fiable** | Every business, paper or electronic invoices | Documented controls linking invoice ↔ supply | Supports it (quote → delivery note → invoice → payment chain, audit log) | Build (mostly exists) |
| Cash register data | — | — | Covered by another agent | Link only |

## 1. The e-invoicing and e-reporting reform

### 1.1 Calendar in force on 2026-09-25

- Since 1 Sept 2026, all businesses, whatever their size and legal form, must be able to **receive** electronic
  invoices through a plateforme agréée; GE and ETI must also **issue** all their invoices electronically; PME, small and
  micro-businesses must issue « au plus tard le 1er septembre 2027 » [Verified: FAQ « Je découvre » v. 01/09/2026, Q5.1,
  https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/faq---fe_je-decouvre-la-facturation-electronique.pdf].
- e-reporting of transactions follows the same calendar (GE/ETI 1 Sept 2026, micro/PME 1 Sept 2027) [Verified: same FAQ, Q8.1].
- Legal basis: art. 91 of loi n° 2023-1322 (LF 2024) [Verified: FAQ « J'approfondis » v. 01/09/2026, Q3.2,
  https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/faq---fe_japprofondis-la-facturation-electronique.pdf];
  dates set by décret n° 2024-266, with a possible deferral by decree to 1 Dec 2027 at the latest for PME/micro
  [Unverified: search snippets only, decree not read].
- Size = the legal unit (SIREN), assessed **on 1 January 2025** on the last closed exercise: micro < 10 staff and
  ≤ 2 M€; PME < 250 staff and ≤ 50 M€ turnover or ≤ 43 M€ balance sheet; ETI < 5,000 staff and ≤ 1,500 M€ / ≤ 2,000 M€
  [Verified: FAQ « J'approfondis », Q1.1]. twes-in's target customers are micro/PME, so their **issuing** date is
  1 Sept 2027 [Inferred: from the size rule above].
- Start-up phase: « pas d'application des sanctions » for businesses in « une trajectoire sérieuse de mise en
  conformité »; it is « ni un report ni une suspension » [Verified: guide pratique de démarrage, p. 2,
  https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/guide_pratique_facturation_electronique.pdf].
- A PDF sent by email is not an electronic invoice under the reform [Verified: FAQ « Je découvre », Q3.1].
- Public-sector customers: received through Chorus Pro since 2017; since 1 Sept 2026 the same formats as B2B; a
  supplier may send through a PA connected to Chorus Pro (the target) or keep Chorus Pro's current channels (portal,
  API, EDI), the latter « provisoire » [Verified: FAQ « Je découvre », Q11.1].
- **Source conflict, payment e-reporting date**: the page « qu'est-ce que ça change pour moi » lists payment data
  « pour le 1er septembre 2027 » [Verified: https://www.impots.gouv.fr/facturation-electronique-qu-est-ce-que-ca-change-pour-moi],
  while FAQ « J'approfondis » Q3.2 says GE/ETI transmit transaction « et de paiement le cas échéant » since
  1 Sept 2026 [Verified]. Likely the page shows the PME case [Inferred: the page is a per-profile questionnaire]. For
  twes-in's customers both readings give **1 Sept 2027**.

### 1.2 Actors: PA, PPF, solution compatible

- **Plateforme agréée (PA, formerly PDP)**: « l'intermédiaire indispensable »; emits, transmits and receives the
  invoices, converts formats while preserving integrity, extracts and transmits invoice data, transaction data and
  payment data to the administration [Verified: https://www.impots.gouv.fr/facturation-electronique-et-plateformes-agreees ;
  labels leaflet, https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/fe_presentation-des-labels.pdf].
  A business may use several PAs, different ones for issuing and receiving, and change at any time; it signs a formal
  agreement with the PA registered for it in the annuaire [Verified: FAQ « Je découvre », Q2.1–2.2].
- **PPF (portail public de facturation, run by AIFE)**: since the October 2024 announcement written into the LF 2025, it
  is only the **annuaire** (who receives where) and the **concentrateur** (receives data from PAs for the DGFiP); it is
  no longer a free invoicing platform [Verified: https://aife.economie.gouv.fr/nos-applications/facturation-electronique-b2b/].
- **Solution compatible** (what twes-in can be): not registered by the administration; « ne peut donc ni transmettre
  les factures directement à l'administration fiscale, ni agir en tant qu'intermédiaire officiel »; « doit
  obligatoirement être raccordée à une plateforme agréée » [Verified: labels leaflet]. Two cumulative conditions:
  functions compatible with the reform and connection to at least one PA [Verified: FAQ « Je découvre », Q2.3]. There
  is an official « Solution compatible – Facturation électronique » label with a charter [Verified: labels leaflet];
  how an editor obtains it [Unverified: no primary procedure found].
- « Opérateur de dématérialisation » is the older name of the same non-registered role [Unverified: rename reported by secondary sources; no primary page read names the old term].

### 1.3 Formats

- The PAs offer « un socle minimal de formats commun » guaranteeing interoperability, and a supplier cannot impose a
  format on its customer [Verified: FAQ « J'approfondis », Q2.3]; public entities receive « un des trois formats du
  socle » [Verified: FAQ « Je découvre », Q11.2]. The three are **Factur-X, UBL and CII** [Inferred: FNFE-MPE says
  Factur-X is « l'un des 3 formats obligatoires en réception en France », https://fnfe-mpe.org/factur-x/ ; the spec
  documents naming them were not read].
- Technical frame: external specifications **v3.2 of 30 April 2026**, plus AFNOR **XP Z12-012** (formats and profiles of
  invoice and lifecycle-status messages), **XP Z12-013** (API between a business's system and a PA), **XP Z12-014**
  (B2B use cases), downloadable free from AFNOR [Verified: https://www.impots.gouv.fr/specifications-externes-b2b].
  XP Z12-013 = REST, OAuth2, « Flow » and « Directory » services [Unverified: secondary sources only].
- **Factur-X**: current release **1.09.2 / ZUGFeRD 2.5.2, 4 Aug 2026**, technically identical to ZUGFeRD; profiles
  MINIMUM, BASIC WL, BASIC, EN 16931, EXTENDED; free download against an email [Verified: https://fnfe-mpe.org/factur-x/].
  Which profiles count as a valid invoice under the reform (MINIMUM and BASIC WL carry no lines) [Unverified: specs v3.2
  not read — read them before choosing; EN 16931 is the safe target, Inferred].
- The administration collects only part of the invoice: the list is annexe IV art. 41 septies D; **26 mandatory data in
  2026, 34 from 2027** [Verified: FAQ « Je découvre », Q6.1–6.3]. The issuer's PA extracts them [Verified: Q6.2].

### 1.4 The four new mandatory mentions

Décret n° 2022-1299 amended annexe II art. 242 nonies A: **customer SIREN**; **operation category** (goods, services or
both); **option for VAT on debits**, when taken; **delivery address** when different from the billing address
[Verified: FAQ « Je découvre », Q3.4]. **Source conflict on the date**: that FAQ says « à compter du 1er septembre 2026 »
without a size distinction, while `docs/fiscal/FR.md` § 4 applies them by size (2026 GE/ETI, 2027 PME). Whether a PME
issuing paper/PDF before Sept 2027 must already print them [Unverified: the decree's entry-into-force article was not
read]. Printing them from now on costs nothing and removes the question [Inferred].

### 1.5 e-reporting: what, how often

- In scope: sales to non-taxable persons (individuals, non-profit associations) in France or abroad, and operations
  with foreign businesses (intra-EU supplies and acquisitions, exports), art. 290 I CGI [Verified: FAQ « Je découvre »,
  Q1.1, Q7.1]. Out-of-scope operations (art. 256) and exempt ones (art. 261 to 261 E) are neither e-invoiced nor
  e-reported [Verified: Q1.2]. VAT-on-margin sales are in scope (B2B → e-invoice, B2C → e-reporting)
  [Verified: FAQ « J'approfondis », Q1.3].
- **B2C**: the **total per day** for a period, by VAT rate; **international B2B**: invoice by invoice, the same data as a
  domestic e-invoice [Verified: FAQ « Je découvre », Q9.1]. The business may keep sending its own document to the
  customer by any channel [Verified: Q9.1].
- **Payment data** (art. 290 A; list in annexe II art. 242 nonies P): only where VAT is due on receipt (services,
  advances), not under the débits option nor for reverse-charged operations; always **date of receipt + amount received
  by VAT rate**. For an e-invoiced sale, carried by the « encaissée » status (invoice number, payment date, amount by
  rate); for international B2B not e-invoiced, a per-invoice flow; for B2C, daily aggregates by rate
  [Verified: https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/japprof_donnees-de-paiement-a-transmettre_vf.pdf].
  The obligation is the **seller's** [Verified: FAQ « Je découvre », Q10.2].
- Frequencies (annex « MAJ août 2026 »)
  [Verified: https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/japprof_frequences-et-delais-de-transmission.pdf]:

  | VAT regime | Transactions | Payments |
  |---|---|---|
  | Réel normal monthly | 3 per month (1–10, 11–20, 21–end), due the 20th, the 30th (not Feb) and the 10th of next month | monthly, before the 10th of next month |
  | Réel normal quarterly (< 4,000 € VAT/yr) | monthly, before the 10th of next month | monthly, before the 10th |
  | Réel simplifié | monthly, between the 25th and 30th of next month | monthly, same |
  | Franchise en base | two-monthly (Jan–Feb, …), 25th–30th of the month after | two-monthly, same |

### 1.6 Lifecycle statuses

- A platform **« rejetée »** (format, missing data, routing) is distinct from a buyer **« refusée »**, which is
  mandatory-motivated and limited to the motives in the standard (undetected regulatory non-conformity, wrong
  addressee, contractual conditions preventing processing), never a commercial dispute; a corrected invoice is re-issued
  **with a new number** [Verified: guide pratique, Q11–Q12]. Provisional dispute statuses exist; the buyer's refusal is
  definitive [Verified: FAQ « Je découvre », Q3.2]. **« Encaissée »** carries payment data (§ 1.5).
- The full set of mandatory statuses and their codes [Unverified: defined in XP Z12-012 / specs v3.2, not read].
- A refused invoice is neutralised internally (internal credit note, refund or later regularisation), not blindly
  cancelled [Verified: guide pratique, Q12] — consistent with twes-in's gapless numbering and credit notes
  (docs/SPEC.md § 7, 2026-09-20 00:35).

### 1.7 Sanctions

- 50 € per invoice not issued electronically, capped at 15,000 € per calendar year, first offence not sanctioned;
  500 € three months after a formal notice for having chosen no receiving PA, then 1,000 € per further three months
  [Verified: FAQ « J'approfondis », Q2.4–2.5].
- e-reporting failures: art. 1788 D CGI, 250 € per transmission capped at 15,000 €/year, reportedly raised to 500 € by
  the LF 2026 [Unverified: Légifrance returned 404 on both versions; figures from secondary sources that disagree].

### 1.8 What an invoicing software can be

**(a) A PA.** Registration by the DGFiP for **three years, renewable** [Verified: plateformes-agreees page]. The file
requires: SIREN + Kbis; a GDPR art. 32 security description; a **valid ISO/IEC 27001 certificate**; a commitment to run
the system **from the EU with no transfer outside it**; **SecNumCloud qualification (ANSSI) of any hosting provider**;
a commitment to feed the annuaire; a **conformity audit report within one year**; technical descriptions of sending,
receiving, extraction, authentication and the secure protocol; then **interoperability tests with the PPF and with
another PA** [Verified: guide utilisateur immatriculation, MAJ 10/12/2025,
https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/guide_utilisateur_fe_ds_immatriculation_pdp.pdf].
The official list file « liste_pa_attente_rapport_audit » carries about 150 registered PAs (149 rows dated 12/2025 to
07/2026), and a second file 12 candidates awaiting interop tests
[Verified: https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees, list modified 22/09/2026].
Cost (ISO 27001, SecNumCloud hosting, audit, 24/7 operation) [Unverified: no primary figure; order of magnitude
hundreds of k€ per year, Speculative]. **Verdict: impossible for a small editor, and pointless — 150 exist.**

**(b) A solution compatible connected to one or more PAs** by API. PAs with public developer documentation, both in the
official list:

| PA | Registered | API | Price |
|---|---|---|---|
| **Iopole** | 11/12/25 | Public Swagger at `https://api.iopole.com/v1/api`, one-click free sandbox, Factur-X/CII/ZUGFeRD/JSON, e-reporting, white label | not public [Verified: list file; https://www.iopole.com/developpeurs and the Swagger UI fetched] |
| **SUPER PDP** | 22/12/25 | « Une API pour envoyer et recevoir des factures électroniques », docs page, ISO 27001 (LNE), Peppol AP/SMP | account « gratuit jusqu'à 1000 factures par mois »; API « 0,0025 € par facture, en fonction du volume » [Verified: list file; https://www.superpdp.tech/ home page] |
| B2BRouter, Pennylane, Tiime, Qonto, Sage, Cegid, Indy, Sellsy… | 12/2025–01/2026 | exist; public API for third-party editors not checked | [Verified: in list file; API Unverified] |

Every PA implementing XP Z12-013 exposes the same API shape, so one connector could serve several PAs [Inferred: the
standard's stated purpose; not tested].

## 2. The FEC (Fichier des Écritures Comptables)

- **Who**: a taxpayer whose accounting is kept on computerised systems, when under a *vérification de comptabilité*,
  hands over a copy of the accounting entry files « au début des opérations de contrôle » (LPF L47 A I)
  [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000037526053].
- **Content**: « all accounting entries recorded during the exercise », including opening entries, depreciation,
  provisions and regularisations; detail, not centralisations [Verified: BOI-CF-IOR-60-40-20 §50, §63,
  https://bofip.impots.gouv.fr/bofip/9028-PGP.html/identifiant=BOI-CF-IOR-60-40-20-20170607].
- **Format** (LPF A47 A-1): name `SirenFECAAAAMMJJ` (closing date); flat file separated by tab or `|`, or XML (XSD on
  impots.gouv.fr); ASCII / ISO 8859-15 or UTF-8; decimal comma, no thousands separator; dates `AAAAMMJJ`; **18 fields**:
  JournalCode, JournalLib, EcritureNum, EcritureDate, CompteNum, CompteLib, CompAuxNum, CompAuxLib, PieceRef, PieceDate,
  EcritureLib, Debit, Credit, EcritureLet, DateLet, ValidDate, Montantdevise, Idevise; up to 22 for BA/BNC cash-basis
  bookkeeping [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000027804775/ ; BOFiP §10, §70].
- **Sanction**: 5,000 € or, on reassessment and if higher, 10 % of the rights (CGI 1729 D I)
  [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000033815103].
- **Can an invoicing app produce one?** Not a compliant one: the FEC is the whole ledger of the exercise (bank,
  purchases, payroll, depreciation, closing entries), which only the bookkeeper holds [Inferred: from §50 above]. This
  **confirms** docs/SPEC.md § 7 2026-09-20 01:33 (« double-entry ledger and the French FEC… produced by whoever keeps
  the books »).
- **What it can usefully produce**: a sales / payments journal **in the FEC's 18-column layout**, because accounting
  tools import it: Pennylane has an « Importer un FEC » import (tab-separated UTF-8 `.txt`) [Unverified: help-centre
  snippet, https://help.pennylane.com/fr/articles/18792-importer-un-fec not read]; Tiime Expert imports FEC or CSV
  [Unverified: snippet]. It must be labelled a journal export, never « FEC » [Inferred: to avoid a false claim of
  compliance].
- **Other import formats** [all Unverified: search snippets only]: **Sage 100** `.pnm` fixed-width (first line the company
  name, 105 to 199 character variants) or its parametrable import; **Cegid** `.TRA` (entry labels ≤ 35 characters; also a
  documented API on developers.cegid.com); **EBP** parametrable TXT/CSV import; **Pennylane** CSV/XLS and FEC import.
  The SPEC's CSV/xlsx exports plus one FEC-layout journal cover all of them through their parametrable imports
  [Inferred].

## 3. VAT returns (CA3; CA12 ends)

- **The régime simplifié disappears on 1 January 2027**: « les entreprises ne relevant pas de la franchise en base seront
  toutes soumises au régime réel normal de TVA avec déclaration mensuelle ou trimestrielle » (loi n° 2025-127 art. 38)
  [Verified: https://www.impots.gouv.fr/professionnel/questions/je-suis-soumis-au-regime-simplifie-dimposition-la-tva-quelle-echeance-dois,
  modified 13/04/2026]. So the only return to prepare is the **CA3** (form 3310-CA3-SD).
- **Filing**: « La télétransmission des déclarations de TVA… est obligatoire pour tous les redevables », either **EFI**
  (the business types the figures on impots.gouv.fr) or **EDI** (« un prestataire de l'entreprise transmet… un fichier
  obtenu avec un logiciel de comptabilité », suited to firms using an accountant) [Verified: notice 3310-CA3 2026, p. 1,
  https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5426.pdf]. Never cumulate
  periods; file « Néant » when nothing happened [Verified: same].
- **Partenaire EDI**: habilitation request to the regional authority, a « DGFiP – Partenaire EDI » convention, tax
  compliance certificate, public key; « l'accréditation est délivrée gratuitement »; valid one year, tacitly renewed;
  covers the EDIFACT procedures TDFC, EDI-PAIEMENT, EDI-TVA, EDI-IR, EDI-Requête
  [Verified: https://www.impots.gouv.fr/comment-devenir-partenaire-edi]. Free, but implementing the EDIFACT cahier des
  charges, security and rejects handling « nécessite des compétences informatiques et techniques »
  [Verified: https://www.impots.gouv.fr/qui-sont-les-partenaires-edi-et-quel-est-leur-role]. For a small editor whose
  customers have an accountant: **disproportionate** [Inferred].
- **Data the CA3 needs** (notice 2026): A1 taxable turnover excl. VAT; E1 exports; E2 other non-taxed turnover; F2
  intra-EU supplies B2B; base and tax per rate on lines 08, 09, 9B (« le taux normal est fixé à 20 % ») and T6 at 2.1 %;
  deductible VAT lines 19–20; line 21 other deductible VAT, where credit notes and unpaid operations go (« ne jamais
  indiquer de sommes négatives »); bases and tax rounded to the euro [Verified: notice, lines A1, E1, E2, F2, 08/09/9B,
  T6, 19–21, cadre B]. Which of 08/09/9B carries 5.5 % and 10 % [Unverified: from recall, 08 = 20 %, 09 = 5.5 %, 9B = 10 %; the notice only says « pour chaque taux » and names 20 %].
- **Débits vs encaissements**: for services the collected VAT of a period is the VAT on **amounts received** unless the
  débits option is taken (FR.md § 2a, CGI 269-2-c). That is exactly the « payment by VAT rate » data the reform already
  requires (§ 1.5) [Inferred].
- **Pre-filled CA3 from e-invoicing data**: the purpose of the data collection [Verified: FAQ « Je découvre », Q6.1];
  its calendar [Unverified: a secondary source says none was published by 31 July 2026].
- **What twes-in can prepare**: a **CA3 worksheet** per month or quarter: turnover lines A1/E1/E2/F2, base and VAT per
  rate for goods on the issue/delivery date and for services on receipts (or on invoices when the company opted for
  débits), credit notes separated for line 21, VAT on expenses recorded for lines 19–20, all rounded to the euro, with
  a drill-down to every document. The business or its accountant types it in EFI [Inferred].

## 4. Other data the DGFiP can ask for

- **Retention**: 6 years from the last operation or the document's date; « établis ou reçus sur support informatique »
  they « doivent être conservés sous cette forme »; paper may be digitised under an arrêté (22 March 2017)
  [Verified: LPF L102 B, https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000041471233/]. Accounting documents
  and supporting documents: **10 years**, in euros and French (Code de commerce L123-22)
  [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006219327]. So twes-in must let the company keep an issued
  invoice's **electronic original** (PDF / Factur-X as sent) for 10 years, including after it leaves twes-in — an
  archive export is the answer for departure [Inferred].
- **Readable format on audit**: the administration may run its own processing on the files (LPF L47 A II)
  [Verified: L47 A page]; the XML/flat specifications above are the readable format for accounting data.
- **Piste d'audit fiable**: the invoice is valid for VAT deduction only if authenticity of origin, integrity and
  readability are ensured from issue to the end of retention, by one of three methods of CGI 289 VII: controls
  establishing a reliable audit trail, qualified electronic signature, or EDI [Verified: BOI-TVA-DECLA-30-20-30-10,
  https://bofip.impots.gouv.fr/bofip/8862-PGP.html/identifiant=BOI-TVA-DECLA-30-20-30-10-20180207]; the audit trail
  links « une facture et la livraison de biens ou la prestation de services », for paper and electronic alike
  [Verified: BOI-TVA-DECLA-30-20 §50, §60, https://bofip.impots.gouv.fr/bofip/2416-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20131018].
  twes-in's quote → order → delivery note → invoice → payment chain, immutable issued documents and audit log are the
  material for it; a « PAF » documentation page describing those controls is cheap [Inferred]. During the reform
  start-up, keep the evidence of rejected / re-issued / duplicate-channel invoices [Verified: guide pratique, Q5–Q6, Q16].
- **Cash-register data**: see the cash-register research of the same day (another agent); not covered here.

## 5. Conclusion: what to build for France

Builds on docs/SPEC.md § 7 (2026-09-20 00:35 « ready for e-invoicing, connected to nothing », 01:33 « the accountant »,
09:30 « build every field, schedule no connector »). The research confirms those rulings and dates the connector.

**Build first (ordered)**

1. **The four mentions printed and stored** (customer SIREN, operation category, débits option — on the company, not
   only the invoice — and delivery address). Small; half exists (`operation_category`, `vat_on_debits`). ~1–2 days
   [Speculative].
2. **Payments split by VAT rate** (allocation of each receipt to an invoice's VAT groups) and **daily B2C totals by
   rate**: the core of payment e-reporting and of the encaissements VAT basis. ~3–5 days [Speculative].
3. **CA3 worksheet** (§ 3), monthly or quarterly, no CA12. ~3–5 days once 2 exists [Speculative].
4. **Factur-X (EN 16931 profile) generation**, then UBL, behind the existing sending port; validate with the FNFE and
   EN 16931 validators. Factur-X ~1–2 weeks (PDF/A-3 + CII XML); UBL ~1 week [Speculative].
5. **Accountant exports** already ruled (CSV/xlsx journals, VAT summary) plus one **FEC-layout sales/payments journal**
   for Pennylane/Tiime-style imports. ~2–3 days [Speculative].
6. **10-year archive + export** of issued originals, and a one-page PAF description. ~2 days [Speculative].

**Needs a partner**

7. **PA connector** (issue, lifecycle statuses incl. « encaissée », e-reporting submission) through one PA with a public
   API — Iopole or SUPER PDP first, against XP Z12-013 so a second PA is cheap. The customer signs with the PA (SPEC:
   « carries the company's own… operator contract, never ours »). **Deadline: live before 1 Sept 2027** for PME
   customers, ideally tested in Q2 2027. ~3–6 weeks including sandbox certification [Speculative].
8. **Solution compatible label**: apply once 7 works (procedure Unverified).
9. **Reception**: the customer's own PA receives; twes-in could later import received supplier invoices from the PA API
   into expenses. Optional.

**Impossible or disproportionate for a small editor**

- Becoming a **PA** (ISO 27001, SecNumCloud hosting, audit, EU-only ops, interop tests): impossible now, pointless with
  ~150 registered.
- Becoming a **partenaire EDI** to file CA3s: accreditation is free but EDIFACT + operations are heavy, and the
  accountant or EFI already covers it.
- A **compliant FEC**: needs a full ledger; out by ruling and confirmed here.

## Follow-ups for the team (nothing edited)

- `docs/fiscal/FR.md` § 2a: the CA12 row becomes obsolete on 1 Jan 2027 (loi 2025-127 art. 38) — add it.
- `docs/fiscal/FR.md` § 4 vs FAQ Q3.4: the date of the four new mentions (size-based or flat 1 Sept 2026) — settle with
  the decree text.
- `docs/fiscal/FR.md` § 9 and source 7: the calendar can now cite the impots.gouv.fr FAQs (v. 01/09/2026).
- Read the external specifications v3.2 and XP Z12-012/013 before fixing the sending port's shape (status codes,
  Factur-X profiles accepted).
- Confirm art. 1788 D amounts on Légifrance (250 € vs 500 €).

## Sources read

1. docs/fiscal/FR.md and docs/SPEC.md (this repository)
2. https://www.impots.gouv.fr/professionnel/je-passe-la-facturation-electronique
3. https://www.impots.gouv.fr/facturation-electronique-et-plateformes-agreees
4. https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees, with its files `liste_pa_attente_rapport_audit.pdf` and `liste_pa_attente_test_interop.pdf`
5. https://www.impots.gouv.fr/facturation-electronique-qu-est-ce-que-ca-change-pour-moi
6. Guide pratique de démarrage (PDF, see § 1.1)
7. FAQ « J'approfondis » v. 01/09/2026 (PDF, see § 1.1)
8. FAQ « Je découvre » v. 01/09/2026 (PDF, see § 1.1)
9. Annex « Fréquences et délais » (PDF, see § 1.5)
10. Annex « Données de paiement » (PDF, see § 1.5)
11. Labels leaflet « Plateforme agréée / Solution compatible » (PDF, see § 1.2)
12. Guide utilisateur immatriculation PA, MAJ 10/12/2025 (PDF, see § 1.8)
13. https://www.impots.gouv.fr/specifications-externes-b2b
14. https://aife.economie.gouv.fr/nos-applications/facturation-electronique-b2b/
15. https://fnfe-mpe.org/factur-x/
16. https://www.iopole.com/developpeurs and https://api.iopole.com/v1/api
17. https://www.superpdp.tech/
18. https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000037526053 (LPF L47 A)
19. https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000027804775/ (LPF A47 A-1)
20. https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000033815103 (CGI 1729 D)
21. https://bofip.impots.gouv.fr/bofip/9028-PGP.html/identifiant=BOI-CF-IOR-60-40-20-20170607
22. https://www.impots.gouv.fr/professionnel/questions/je-suis-soumis-au-regime-simplifie-dimposition-la-tva-quelle-echeance-dois
23. Notice 3310-CA3-SD 2026 (PDF, see § 3)
24. https://www.impots.gouv.fr/qui-sont-les-partenaires-edi-et-quel-est-leur-role
25. https://www.impots.gouv.fr/comment-devenir-partenaire-edi
26. https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000041471233/ (LPF L102 B)
27. https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006219327 (Code de commerce L123-22)
28. https://bofip.impots.gouv.fr/bofip/8862-PGP.html/identifiant=BOI-TVA-DECLA-30-20-30-10-20180207
29. https://bofip.impots.gouv.fr/bofip/2416-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20131018
