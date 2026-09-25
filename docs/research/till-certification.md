# Till (point-of-sale) certification: France, Tunisia, and comparison

Research of 2026-09-25 for twes-in. Not legal advice. Grades: **[Verified: url]** means the text was read at
that URL; **[Inferred: basis]**; **[Unverified: why]**. "Via republication" means an official text read in a
faithful copy on a third-party site, not in the Journal officiel scan itself.

## Summary

| | France | Tunisia |
|---|---|---|
| Legal basis | CGI art. 286 I 3° bis (LF 2016 art. 88), as amended by LF 2026 art. 125 and loi 2026-534 art. 87 | CIRPP/IS art. 59 ter (LF 2016, loi 2015-53, art. 48); décret gouv. 2019-1126; arrêté MF 14 Oct 2025 (the "JORT n° 125" text) |
| Who | Every VAT-liable business that records sales to non-business customers in a till software; not franchise-en-base, not B2B-only | Only **on-site consumption** businesses (cafés, restaurants, salons de thé…); no turnover threshold. Not general retail |
| From when | Since 1 Jan 2018 (LF 2016 art. 88 as amended by LF 2018 art. 105) [Inferred: Légifrance search listing for LPF L80 O; history not opened] | Legal entities: classified tourist restaurants, salons de thé, 2nd/3rd-category cafés 1 Nov 2025; all other legal entities **1 Jul 2026**; natural persons (réel, monthly) 1 Jul 2027; others 1 Jul 2028 |
| Proof | **Either** a certificate from an accredited body (NF525 by AFNOR/Infocert, or BYCYB/LNE) **or** the editor's individual attestation (model BOI-LETTRE-000242), restored 21 Feb 2026 | Register **homologated by the Ministry of Finance**, sold only by an **accredited supplier**, one electronic certificate per register, registered with the MF before use |
| Technical model | Software-only: chained/signed records, closures with perpetual total, archives. Software keys allowed | A "module de données fiscales" that protects **and transmits** to an MF platform, with **permanent connection**; QR code on each ticket |
| Penalty | €7,500 per system (CGI 1770 duodecies), again if not regularised within 60 days; false attestation is forgery | CDPF art. 94: **16 days to 3 years' prison and 1,000–50,000 TND** |
| Realistic path for twes-in | **Attestation: yes, now, at ~no cost** (SaaS and unmodified self-hosted). NF525/LNE optional, ~€10–20k + ~€5–7k/yr | **Accreditation as supplier: possible in principle, blocked** on the cahier des charges behind homologation.nacef.tn (unreachable). Needs a Tunisian partner/lawyer |
| Training mode | Allowed, recorded, watermarked « factice »/« simulation », operator identified (BOFiP § 150) | Training operations **must** go through the register; ticket typed « opération de formation » (décret art. 5, 7) |

## 1. France

### 1.1 The text in force on 2026-09-25

- CGI art. 286 I 3° bis, version in force since **27 June 2026** (loi n° 2026-534 du 25 juin 2026, art. 87): a
  VAT-liable person who makes supplies "ne donnant pas lieu à facturation" and records them "au moyen d'un logiciel ou
  d'un système de caisse" must use one satisfying "des conditions d'inaltérabilité, de sécurisation, de conservation
  et d'archivage des données", "attestées par un certificat délivré par un organisme accrédité dans les conditions
  prévues à l'article L. 433-4 du code de la consommation **ou par une attestation individuelle de l'éditeur,
  conforme à un modèle fixé par l'administration**" [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051764897].
- New since 27 June 2026, from loi 2026-534 art. 87: "Les données archivées … sont restituées dans un format
  répondant aux normes établies par l'administration" [Verified: same URL, and
  https://www.legifrance.gouv.fr/eli/loi/2026/6/25/2026-534/jo/article_87]. No published "norme" was found: a
  pending requirement to watch [Unverified: nothing found; the article gives no date or decree].
- History: LF 2025 art. 43 had removed the attestation (only a certificate would do, with a deadline pushed to
  1 Sept 2026); LF 2026 (**loi n° 2026-103 du 19 février 2026**) art. 125 modified CGI 286 and 1770 duodecies and
  restored the attestation from **21 Feb 2026**, the day after publication
  [Verified: https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000053511553/2026-02-21 (lists art. 125 as
  modifying 286 and 1770 duodecies); https://www.legifiscal.fr/actualites-fiscales/4460-loi-finances-2026-retablissement-auto-certification-logiciels-caisse.html (date)].
  The Government's answer of 10 March 2026 to AN question 8260 confirms accredited bodies are "no longer mandatory"
  [Verified: https://questions.assemblee-nationale.fr/q17/17-8260QE.htm]. **The SPEC's 2026-09-20 correction stands.**
- Recodification: Légifrance flags art. 286 as abrogated by ordonnance 2025-1247 and 1770 duodecies "in force until
  1 Jan 2027"; this is the VAT move into the CIBS (postponed to 1 Jan 2027, already in `docs/fiscal/FR.md` § 2), not
  a repeal: LPF L80 O already cites the target, "l'article L. 216-48 du code des impositions sur les biens et
  services" [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000036432343].

### 1.2 Who is concerned

VAT-liable persons, natural or legal, "quel que soit le secteur d'activité", making supplies not invoiced; excluded:
B2B-only, franchise en base (293 B), exempt-only operations; no obligation to own a till, only to use a compliant one
if one is used [Verified: https://www.impots.gouv.fr/professionnel/questions/quel-est-le-champ-dapplication-de-lobligation-de-detenir-un-logiciel-de
(updated 21/05/2026); BOI-TVA-DECLA-30-10-30-20260325 §§ 10–25: https://bofip.impots.gouv.fr/bofip/10691-PGP.html].
Online-accessible tills are in scope: "Cette obligation s'applique y compris en cas d'enregistrement sur un logiciel
ou système de caisse accessible en ligne" (§ 30) [Verified: BOFiP above]. BYCYB's statement that "une solution 100%
en ligne … est exclue du champ" concerns a **100 % online payment flow with no physical point of sale** (e-commerce),
not a cloud POS [Verified: https://bycyb.com/certification-des-systemes-de-caisse].

### 1.3 Controls and fines

- CGI 1770 duodecies: "7 500 € par logiciel ou système de caisse concerné"; the taxpayer then has **60 days** to
  comply, failing which the fine applies again [Verified: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051203251].
- LPF L80 O (version of 27 June 2026): unannounced visits 8h–20h or opening hours; after a shortfall is recorded
  the taxpayer "dispose d'un délai de trente jours pour formuler ses observations" and, if the document is supplied,
  the 1770 duodecies fine is not applied [Verified: L80 O URL above]. L80 O speaks of the "certificat"; whether an
  attestation supplied in those 30 days counts is not spelled out [Unverified: summariser-level reading only — a
  question for a lawyer, low risk since 286 names both].
- Loi 2026-534 art. 87 also adds CGI 1770 quaterdecies: €7,500 per payment terminal not presented [Verified: art. 87 URL].
- A false certificate or attestation: forgery, 3 years and €45,000 (C. pén. 441-1), including for foreign editors
  (BOFiP §§ 400–410) [Verified: BOFiP URL, via summary].

### 1.4 What the four conditions mean technically (BOFiP BOI-TVA-DECLA-30-10-30, 25/03/2026)

- **Inaltérabilité** (§§ 80–120): no modification without trace; corrections are "+/-" counter-entries; the
  low-level means named include "empreinte numérique à clé privée, chaînage" [Verified: BOFiP].
- **Sécurisation** (§§ 130–150): "chaînage des enregistrements ou … signature électronique"; training mode, see § 4 below.
- **Conservation** (§§ 155–200): "une clôture journalière et une clôture mensuelle et annuelle (ou par exercice)…
  cumulatives et impératives" (§ 170); each closure computes "le cumul du grand total de la période et le total
  perpétuel", the perpetual total being "depuis le début de l'utilisation … et ne se remettant jamais à zéro";
  closure totals "ne doivent donc jamais être purgées"; line-level data retained, a Z alone is not enough; a
  central aggregation point is allowed (§ 190) [Verified: BOFiP].
- **Archivage** (§§ 220–260): at most annual periodicity, open format with a French explanatory notice, external
  media or storage server, full archive before any purge, cumulative totals stay online [Verified: BOFiP].
- **Duplicates**: the BOFiP has **no « duplicata » clause** [Verified: local grep of the full page
  https://bofip.impots.gouv.fr/bofip/10691-PGP.html, 0 hits for duplicat/réédit/réimpr; "copie" only for backups and
  certificate copies]. Marking
  and numbering reprints is expected by the NF525/LNE referentials [Unverified: referentials are not public]; the
  Tunisian decree requires it (§ 2.4). The SPEC's "reprints marked as duplicates" should cite those, not the BOFiP.

### 1.5 The attestation: what the editor delivers

- Model **BOI-LETTRE-000242-20260325**: part 1 (editor) — legal representative, company, software name and
  references, market date, version and licence number, scope of functions covered; commitment that the software
  "satisfait aux conditions d'inaltérabilité, de sécurisation, de conservation et d'archivage"; optional declaration
  of the **major-version root** and commitment that "ces subdivisions ne soient utilisées … que pour l'identification
  des versions mineures ultérieures"; part 2 (user) — company, acquisition date and distributor, start date,
  statement that it records "les règlements de mes clients particuliers" [Verified: https://bofip.impots.gouv.fr/bofip/10692-PGP.html/identifiant=BOI-LETTRE-000242-20260325].
- "L'attestation doit être individuelle, c'est-à-dire délivrée nominativement à l'assujetti" (§ 370); a pre-filled,
  editor-signed form completed by the buyer is acceptable [Verified: BOFiP].
- The editor is "la personne qui détient le code source … et qui a la maîtrise de la modification des paramètres"
  (§ 300); its editing activity must be "réelle et corroborée" (§ 375) [Verified: BOFiP].
- **Version majeure** = a version "obtenue en ayant modifié … un ou plusieurs paramètres impactant le respect des
  conditions d'inaltérabilité…" (§ 340); an attestation covers later minor versions if root and subdivisions are
  identified (§ 380) [Verified: BOFiP]. A major version needs a new attestation [Inferred: § 340 read with § 380].

### 1.6 Certification (NF525 / BYCYB-LNE): cost and delay

- NF525: AFNOR Certification mark, Infocert as secretariat and inspection body; initial audit "generally two days";
  time to certificate "can vary from one to several months"; annual surveillance audits; no public price
  [Verified: https://infocert.org/en/nf525/].
- BYCYB (LNE's subsidiary, COFRAC 5-0696, ISO/IEC 17065): documentary review, admissibility, on-site audit,
  reading committee, decision, public registry; mandatory annual follow-up; major evolutions notified and reviewed;
  referential v1.8 (April 2025); price on quote [Verified: https://bycyb.com/certification-des-systemes-de-caisse].
- Price, all secondary: "frequently exceeds 10,000 euros" HT initial plus annual audits (AN question 8260 text)
  [Verified: AN URL]; Paheko cites "10.000 à 20.000 € pour l'audit initial, plus 5.000 à 7.000 € ensuite chaque
  année" [Verified: https://paheko.cloud/caisse-nf525-loi-de-finances-certification-attestation]. Treat as order of
  magnitude, not a quote. Timeline: 2–6 months for a small editor once the software is ready [Inferred: Infocert's
  "one to several months" plus preparation].

### 1.7 SaaS, open source (AGPL), self-hosted

- SaaS: in scope (§ 30) and attestable like any software; twes-in as SaaS operator holds the source and the
  parameters, so it is the editor [Inferred: §§ 30, 300].
- Open source: "L'entreprise utilisatrice est donc considérée comme « l'éditeur » soumis à obligation de certification
  de la version actuellement en service" when it modifies the code (§ 45); "Un intervenant, quel qu'il soit,
  modifiant le fonctionnement … (par modification du code source, patch logiciel, paramétrage ou autre) … invalide le
  certificat ou l'attestation" (§ 315); and self-developed software "ne pourra justifier que par la production d'un
  certificat" (§ 375) [Verified: BOFiP]. So a self-hoster who **modifies the fiscal code** loses twes-in's
  attestation and must get a certificate itself; an **unmodified** install of an attested version keeps it
  [Inferred: §§ 45, 315, 375, 380].
- Free download: "s'il se procure librement et gratuitement un logiciel en ligne, il lui appartient de se faire
  produire une attestation individuelle ou d'obtenir une copie du certificat" [Verified: same BOFiP grep] — so a
  self-hoster downloading twes-in needs a way to obtain its nominative attestation (a request flow).
- The AGPL does not stop attestation; the April/LinuxFr campaign that obtained LF 2026 art. 125 was precisely about
  free-software tills [Verified: https://linuxfr.org/news/retablissement-de-l-auto-attestation-pour-les-logiciels-de-caisse-l-aboutissement-d-une-annee-de-mobilisation].

## 2. Tunisia

### 2.1 The texts (the "JORT n° 125" is now read)

1. **LF 2016, loi n° 2015-53 du 25 décembre 2015, art. 48** (JORT n° 104, 29 Dec 2015, p. 3152): creates CIRPP/IS
   art. 59 ter, "Les entreprises prestataires de services de consommation sur place doivent mettre en place « une
   caisse enregistreuse », et ce, pour toutes leurs transactions avec les clients"; practical terms by government
   decree; applicable "à partir du 1er juin 2016"; and adds to CDPF art. 94 "toute personne qui manque aux
   dispositions de l'article 59 ter … ou qui introduit des modifications à la caisse enregistreuse ou qui détruit ou
   falsifie les informations qui y sont enregistrées" [Verified: http://chaexpert.com/documents/2016%20-%20Loi%20de%20finances%20ann%C3%A9e%202016.pdf, JORT scan].
2. **Décret gouvernemental n° 2019-1126 du 26 novembre 2019** "fixant les modalités pratiques de la mise en place de
   la caisse enregistreuse pour les services de consommation sur place" (content in § 2.3) [Verified via republication:
   https://jibaya.tn/wp-content/uploads/2025/10/1126%20trait%C3%A9%20fr.pdf].
3. **Arrêté de la ministre des finances du 14 octobre 2025** fixing the classification criteria and deadlines — the
   text press call "JORT n° 125" [Verified via republication: https://jibaya.tn/wp-content/uploads/2025/10/arrete-14Oct25.pdf;
   linked from https://jibaya.tn/blog/nacef-le-systeme-national-de-caisses-enregistreuses-fiscales-2/]. The JORT
   issue number and date (press say n° 125 of 14 Oct 2025) are not read in the scan
   [Unverified: https://caisses.tn/caisses-obligatoires-en-tunisie/ says "JORT n° 125 du 14 octobre 2025"; the
   arrêté itself is dated 14 Oct, so publication may be later].
4. **LF 2025 (loi 2024-48)** appears in the arrêté's visas only as the latest text amending the code ("tel que
   modifié … notamment la loi n° 2024-48 … et notamment son article 59 ter", "son" being the code's); a search of the
   official LF 2025 PDF finds no amendment of art. 59 ter. So: LF 2016 created the obligation, nothing found amended
   it since, the 2019 decree and the 2025 arrêté activated it; its art. 71 instead adds to CDPF art. 94 a 100–500 TND per-invoice fine for paper
   invoices where e-invoicing is mandatory (e-invoicing link, out of scope here) [Inferred: text search of
   https://www.finances.gov.tn/sites/default/files/2024-12/LF2025.pdf, Arabic, JORT n° 149 of 10 Dec 2024].
   No LF 2019, 2022 or 2024 article on the till was found [Unverified: not searched exhaustively]. Some press
   attribute the obligation to "article 59 ter, Finance Law 2025" [https://managers.tn/2025/10/16/a-partir-de-novembre-2025-restaurants-cafes-et-salons-de-the-devront-utiliser-des-appareils-denregistrement-des-operations-details/];
   the primary texts point to LF 2016 [Inferred: items 1 and 4].

### 2.2 Who and when (arrêté 14 Oct 2025)

- Art. 1: businesses "qui exercent une activité, à titre principale ou secondaire, de vente de nourriture et/ou de
  boissons préparés ou prêts à la consommation et fournissent à leurs clients des services de consommation sur place".
- Art. 2: 1 Nov 2025 — legal entities running "Restaurants classés touristiques, Salons de thé, Cafés de deuxième et
  troisième catégorie"; **1 Jul 2026** — all other legal entities; 1 Jul 2027 — natural persons under the régime réel
  filing monthly; 1 Jul 2028 — all other natural persons [Verified via republication: arrêté PDF above].
- **No turnover threshold, and no general retail**: a shop without on-site consumption is outside this regime
  [Inferred: art. 1 and art. 59 ter wording]. Press lists "structures hôtelières, buvettes… espaces commerciaux et
  de loisirs" [https://www.lapresse.tn/2026/07/01/restauration-et-hotellerie-entree-en-vigueur-de-lobligation-des-caisses-enregistreuses-numeriques-certifiees-des-aujourdhui/],
  which is wider than the arrêté [Unverified: not in the text read].

### 2.3 What the register must be (décret 2019-1126)

- Art. 2: a "caisse enregistreuse" made of a « module de caisse enregistreuse » (collects and records) and a « module
  de données fiscales » "qui permet de protéger et d'envoyer les données collectées", plus the MF's « plateforme de
  gestion du système de caisses enregistreuses ».
- Art. 3: homologated under **décret 2008-2639** (import and sale of encryption means over telecom networks), and
  compliant with a **cahier des charges**, "homologuée par les services compétents du ministère des Finances".
  Décret 2008-2639 appears to have been repealed by décret gouv. 2020-48 [Unverified: search snippet only].
- Art. 4: "communication permanente avec la plateforme"; no feature to modify or erase operations, nor to modify
  preprogrammed product data between entry and ticket printing; collect traces of the recording software and
  operating system; raise alarms on mishandling or fraud.
- Art. 5: sales, refunds, and "les opérations effectuées durant la période de formation" all go through the register.
- Art. 6: a daily electronic closing report and a daily financial report.
- Art. 7: ticket fields — ticket number, fiscal-module identifier, date, company name, trade name and matricule
  fiscal, operation type (sale, refund, **formation**), ticket type ("normal, copie avec un numéro différent,
  pro-forma"), items with quantity and price excl. tax, total, tax amounts and rates, payment method, paid and
  change, **QR code**.
- Arts. 8–10 (suppliers): only suppliers accredited by the MF may supply registers; per sale they report brand,
  model, serial, customer identity, installation address, installed software, date of service; deliver a copy of
  the "certificat électronique"; provide after-sales service and report every maintenance.
- Arts. 11–14 (users): buy from an accredited supplier; "acquérir un certificat électronique pour chaque caisse";
  register it with the MF before use; one identifier per agent; permanent use and uninterrupted connection; repair
  within 3 days, at most 10 days of breakdown a year; report outages.
- Art. 15: no modification, destruction or falsification; a supplier who fails to report fraud it sees loses its
  accreditation. [All: Verified via republication, décret PDF above.]

### 2.4 How a supplier gets homologated

The MF opened in Oct 2025 an information system and a "virtual space" for suppliers and software editors to download
the "cahier des charges techniques et fonctionnelles" and the "manuel des procédures techniques", run integration
tests against the central system and file the accreditation request, at **https://homologation.nacef.tn**; helpline
81 100 400 [Verified: https://www.webmanagercenter.com/2025/10/17/553889/caisse-enregistreuse-la-tunisie-met-en-place-une-plateforme-pour-les-fournisseurs-et-tests-dintegration].
A Tunisian vendor describes the same four steps and that its approval is by the MF [Verified: https://asmpos.com/caisse-fiscale-tunisie/].
The portal refused connection from here (ECONNREFUSED / curl timeout) [Verified: attempted 2026-09-25]. So the
cahier des charges — **whether the fiscal module must be hardware, whether a cloud or browser till can qualify,
whether a foreign company can be the accredited supplier, fees and duration** — is **unknown** [Unverified: portal
unreachable]. Costs: none published [Unverified: none found].

### 2.5 Penalties

CDPF art. 94: "emprisonnement de seize jours à trois ans et … amende de 1000 dinars à 50000 dinars" for anyone who
breaches art. 59 ter or modifies the register or destroys/falsifies its data [Verified: https://jibaya.tn/wp-content/uploads/2024/02/CODE-DES-DROITS-ET-PROCEDURES-FISCAUX-2023.pdf].
The DGI announced an initial support phase before sanctions [Verified: lapresse 2026-07-01 URL above; press].

## 3. Other markets (brief)

- **Germany**: AO § 146a — electronic recording systems protected by a **BSI-certified TSE** ("Sicherheitsmodul,
  Speichermedium, einheitliche digitale Schnittstelle"), receipt obligation, notification to the tax office within a
  month [Verified: https://www.gesetze-im-internet.de/ao_1977/__146a.html]; fines "bis zu 25 000 Euro" (§ 379)
  [Verified: https://www.gesetze-im-internet.de/ao_1977/__379.html]. A software till needs a TSE (hardware or a
  certified cloud TSE provider) [Inferred: cloud TSEs are sold; not read].
- **Portugal**: prior certification of invoicing programs by the **AT** (Portaria 363/2010), covering "talões de
  venda"; decision in "30 dias", suspendable for tests [Verified: https://www.gov.pt/servicos/programa-de-faturacao-certificacao];
  RSA signature chaining and SAF-T [Inferred: search snippets of Portaria 363/2010]. Cost not stated.
- **Belgium**: SCE/GKS mandatory for restaurants and caterers whose food-service turnover exceeds €25,000 excl. VAT
  (drinks excluded) [Verified: https://www.systemedecaisseenregistreuse.be/fr/dois-je-utiliser-un-sce]; SCE 2.0 with
  near-real-time transmission, migration 2026–2028 [Unverified: secondary sources only].
- **Algeria**: LF 2026 (JORA n° 88, 31 Dec 2025) art. 58 creates TCA art. 51 bis — a near copy of the French rule:
  "un engagement de l'éditeur … ou un certificat délivré par un organisme habilité", ISCA conditions; art. 74 (CPF
  20 ter: models by arrêté), art. 81 (editors must hand over code and documentation on request); **effective 1 Jan
  2027** (art. 109) [Verified: https://www.mf.gov.dz/images/pdf/loidefinance/Loi_de_Finances_2026_FR.pdf].
- **Morocco**: no cash-register regime found; only the e-invoicing clearance reform [Unverified: search found none;
  not "none exists"].

## 4. Training / practice mode

No regime allows a practice mode that leaves **no record**:
- **France** § 150: a « école » or « test » function "doit être sécurisé, par une identification très claire des
  données de règlement, des pièces justificatives (par exemple en apposant la mention « factice » ou « simulation »
  en trame de fond)" and "l'identification de l'opérateur sous la responsabilité duquel le personnel en formation
  enregistre les données"; simulated data are covered by the same protection (§ 50) [Verified: BOFiP].
- **Germany** DSFinV-K 2.1 § 4.2.6: training bookings "sind … zu protokollieren und abzusichern gemäß KassenSichV",
  BON_TYP „AVTraining“, no cash or VAT effect; no payment may be taken; no effect on the closure
  [Verified: https://kassensichv.com/downloads/DSFinV-K-Vers-2-1.pdf; newer DSFinV-K versions exist, not read].
- **Tunisia** décret arts. 5 and 7: training operations are register transactions and the ticket says so.

So the developer's "dry run" is sound **as a flagged, journalled, excluded-from-totals mode**: every training entry
is chained and signed like a real one, carries `training`, prints a « factice / simulation » watermark (FR) or
« opération de formation » (TN), names the supervising operator, takes no payment, and never enters grand totals,
perpetual total or Z-closures. That is exactly the `training` flag ruled on 2026-09-20 [Inferred: the three texts].
A separate sandbox company with no fiscal journal is also fine for demos, since no real sale is recorded there
[Inferred: § 150 governs training inside a live till].

## 5. Conclusion

### France — feasible now

- **Build** (on row 103's journal): chained and signed entries; corrections only as counter-entries; line-level
  retention; daily/monthly/annual (or fiscal-year) closures with period grand total and **perpetual total**, never
  purged; archives at most yearly, in an open documented format with a French notice, exportable before any purge,
  ready to adopt the administration's coming "format normé"; training mode as in § 4; operator identity on every
  entry; duplicate marking (best practice, required by NF525/TN).
- **Version the fiscal core separately** from the rest of the app, with a declared root, so a UI release is a minor
  version and the attestation survives; a change to the journal is a major version and a new attestation.
- **Deliver the attestation** in-product: a BOI-LETTRE-000242 form pre-filled and signed by twes-in, completed by
  each company (nominative, § 370). Cost: internal only. Delay: the build.
- **Self-hosted**: attest the unmodified version, show a verifiable build hash of the fiscal module in the app and
  state in the attestation that it covers that hash; a customer who patches the fiscal module becomes the editor and
  needs a certificate (§§ 45, 315, 375).
- **NF525/BYCYB**: possible for a web product, optional; ~€10–20k first year, ~€5–7k/yr, 2–6 months. Defer until a
  customer or distributor demands it [Inferred: costs secondary].
- **Needs a lawyer**: the attestation's wording and the editor's penal exposure (441-1); whether L80 O's 30-day cure
  accepts an attestation; the contract clause shifting editor status to a self-hoster who modifies the code.

### Tunisia — not feasible yet, and narrower than it looked

- The obligation covers **only on-site consumption** businesses (in force for all legal entities since
  1 Jul 2026); a Tunisian retail shop using twes-in's till has **no** register obligation under this regime.
- For cafés/restaurants twes-in could only sell a till as (or through) an **MF-accredited supplier**, with a
  homologated fiscal data module transmitting permanently to the MF platform, per-register electronic certificates,
  QR tickets and per-sale reporting of each installation. Whether a cloud/browser till can meet the cahier des
  charges, and whether a foreign editor can be accredited, is **unknown until someone reads the cahier des charges**
  on homologation.nacef.tn (needs access from Tunisia) — row 103's TN adapter stays blocked on that document, no
  longer on the arrêté.
- **Realistic path**: partner with an already accredited Tunisian supplier (their fiscal module, our software
  behind it) or register as a supplier through a Tunisian entity; cost and delay unknown. **Needs a Tunisian lawyer
  or expert-comptable** before any claim of compliance.
- **Impossible**: selling a till to Tunisian cafés/restaurants without accreditation (CDPF 94: prison 16 days–3 years).

### Corrections to earlier records

1. SPEC 2026-09-20: "JORT n° 125 which nobody has read" — now read via republication; it only sets scope and dates;
   the requirements are in décret 2019-1126. Tunisia's penalties are CDPF art. 94, now sourced.
2. SPEC: "reprints marked as duplicates" is not in the French BOFiP; it is in TN décret art. 7.
3. France gained on 27 June 2026 the archive-format sentence and the terminal fine (loi 2026-534 art. 87).
