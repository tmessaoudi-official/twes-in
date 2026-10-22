# Dossier pour l'expert-comptable : règles fiscales tunisiennes appliquées par twes-in

| | |
|---|---|
| Objet | Faire valider ou corriger, règle par règle, ce que le logiciel de facturation twes-in applique pour la Tunisie : TVA, FODEC, droit de timbre, retenue à la source, numérotation, mentions obligatoires, avoirs, arrondis. |
| Date | 2026-10-22 (les numéros de ligne cités renvoient à l'état du dépôt à cette date) |
| Statut | **À valider par l'expert-comptable ; rien ici n'est un avis juridique.** Toutes les règles sont aujourd'hui `unvalidated` (docs/fiscal/TN.md, l. 3-4). |
| Base | `docs/fiscal/TN.md` (règles sourcées, recherche du 2026-09-13 et suivantes), `api/config/fiscal/TN.yaml` (paramètres appliqués), le code sous `api/src`, `api/templates/pdf/invoice.html.twig`, `docs/spec/pricing-vectors.json` (jeu de cas chiffrés du calculateur). |

**Comment répondre.** Pour chaque ligne, remplir la dernière colonne par :
- **Confirmé**, ou
- **À corriger** : la bonne règle, en chiffres, et sa source (texte, article, version ou date).

Les numéros (T1, F1, S1…) servent de référence dans la réponse. Les « Statut actuel » distinguent : `unvalidated` (règle trouvée dans un texte, non validée), `décision du développeur` (aucun texte trouvé, choix fait faute de mieux), `usage, non sourcé comme loi`, `non modélisé` (le logiciel ne le fait pas). Les sources sont citées telles que docs/fiscal/TN.md les donne ; leurs numéros [n] renvoient à sa section « Sources » (l. 250 et suivantes). « Où dans le code » est donné en `fichier:ligne` pour que le développeur retrouve chaque correction.

**Taux et montants modifiables.** À sa création, une société reçoit une copie des taxes du preset (taux de TVA, FODEC, timbre, retenue) et peut ensuite modifier sa copie (`api/src/Fiscal/Application/Company/ProvisionCompany.php:30-35`). Le logiciel guide donc les taux mais ne les verrouille pas : une société peut saisir une valeur non conforme. Si une valeur doit être fixée par la loi plutôt que modifiable, le dire dans la réponse.

## 1. TVA

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| T1 | Trois taux : 19 % (taux par défaut d'une ligne), 13 %, 7 %. | Code de la TVA art. 7, modifié par la loi n° 2017-66 du 18/12/2017 (LF 2018) [1] ; LF 2026 (loi n° 2025-17 du 12/12/2025) sans changement [5]. La page d'aperçu du Ministère [9] affiche encore 18/12/6 % (périmée). | `api/config/fiscal/TN.yaml:39-54` ; défaut d'une ligne sans produit `api/src/Module/Invoices/Application/ManageInvoices.php:584-589` | unvalidated | |
| T2 | L'assiette de la TVA d'une ligne est son montant net après remise de ligne et après sa part de remise globale, **FODEC comprise** ; le timbre n'y entre pas. | Code de la TVA art. 6-I : prix « tous frais, droits et taxes inclus … à l'exclusion de la TVA » [2] | `api/src/Fiscal/Domain/Calculation/DocumentCalculator.php:211-230` ; `TN.yaml:60` (`enters_vat_base: true`) | unvalidated | |
| T3 | La TVA est arrondie **une fois par taux** sur la somme des bases des lignes de ce taux (pas ligne par ligne) ; le montant par ligne est ensuite réparti pour que la colonne s'additionne exactement au total. | Aucune règle officielle trouvée ; ordre de la norme EN 16931 BR-CO-17 [14] | `TN.yaml:13` (`per_rate_group`) ; `DocumentCalculator.php:241-245` ; lu par `api/src/Module/Invoices/Application/InvoiceTotals.php:441` | unvalidated (choix du preset) | |
| T4 | Les prix sont saisis hors taxes. La saisie TTC est refusée sur une ligne qui porte la FODEC ou plus d'une taxe en pourcentage. | source non citée dans docs/fiscal/TN.md (décision du 2026-09-13, TN.md l. 241) | `TN.yaml:14` (`tax_basis: exclusive`) ; `DocumentCalculator.php:446-449` ; `InvoiceTotals.php:440` | décision du développeur | |
| T5 | Client au régime **exonéré**, **en suspension** ou **export** : aucune TVA sur ses documents, et une mention propre au régime est imprimée. L'émission est refusée si une ligne porte encore une TVA que le régime exclut. | Export : Code de la TVA art. 11 [1] ; suspension : loi n° 2006-85 [13] ; exonéré : source non citée dans docs/fiscal/TN.md | `TN.yaml:74-89` ; `api/src/Fiscal/Application/Regime/ExcludedTaxFamilies.php:49` ; refus `api/src/Module/Invoices/Application/InvoiceWorkflow.php:198-224` | unvalidated ; libellés des mentions non sourcés (TN.md l. 166) | |
| T6 | Société au **régime forfaitaire** (art. 44 bis IRPP/IS) : aucune TVA sur aucun de ses documents ; une ligne qui en porte est refusée ; mention « Non assujetti à la TVA : régime forfaitaire, article 44 bis du code de l'IRPP et de l'IS. » | Code de la TVA art. 2-I-3, 2-I-2, 10-2, 18-II [17] | `TN.yaml:91-98` ; `api/translations/fiscal.fr.yaml:25` ; `ExcludedTaxFamilies.php:30` | unvalidated | |
| T7 | L'accueil affiche la TVA **des factures émises dans le mois** (avoirs déduits), libellée « TVA collectée », jamais « à déclarer ». Aucune déclaration mensuelle n'est produite. | Fait générateur : Code de la TVA art. 5-2 (livraison), 5-3 (service : exécution ou encaissement) [17] ; FAQ Ministère [18] ; déclaration mensuelle art. 18-I [12] | `api/src/Module/Invoices/Application/SummarizeInvoices.php:29-30` | unvalidated ; déclaration non modélisée | |
| T8 | Facture d'acompte tirée d'un devis accepté : elle porte la TVA de l'acompte, répartie entre les jeux de taxes du devis au prorata de leur net après remise, **y compris pour un acompte sur des biens** ; la facture finale déduit chaque acompte en lignes propres, taux par taux. | Service : art. 5-3 ; biens : art. 5-2 (l'avance n'est pas un fait générateur) [17] ; obligation de facturer : art. 18-II [13] | `api/src/Module/Invoices/Application/Deposit/DepositDeductions.php:55` ; `DocumentCalculator.php:38-39, 251-253` | unvalidated ; lacune connue (voir écart E6) | |

## 2. FODEC

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| F1 | FODEC à 1 % du prix hors taxes, uniquement sur les produits listés, due aux stades de la production et de l'importation selon la doc : elle est choisie produit par produit, **jamais appliquée par défaut**. Le logiciel laisse toute société la porter sur tout produit, revendeur compris (Q23). | Décret n° 2000-634 du 13/03/2000 (liste) [6] ; Note commune n° 13 (liste) [7]. Texte fondateur non lu en source primaire : loi n° 88-145 (LF 1989), loi n° 94-127, art. 37 loi n° 99-101 selon des sources secondaires [6][8] | `TN.yaml:55-60` (pas de `is_default`) | unvalidated | |
| F2 | Ordre de calcul : la FODEC est calculée d'abord, sur le net remisé de la ligne, puis son montant s'ajoute à la base de la TVA de **la même ligne**. | Code de la TVA art. 6-I [2] | `DocumentCalculator.php:216-228` | unvalidated | |
| F3 | La FODEC est arrondie comme la TVA : une fois sur la somme des lignes qui la portent, puis répartie par ligne ; la TVA de chaque ligne se calcule sur son net plus sa part de FODEC. | aucune source trouvée ; choix du preset (TN.md l. 69) | `DocumentCalculator.php:241-245` | unvalidated (choix du preset) | |
| F4 | Les régimes client exonéré, suspension, export, et le régime forfaitaire de la société, n'excluent que la TVA : **la FODEC reste due** sur leurs documents si le produit la porte. | non établi (TN.md l. 199-200, 211-212) | `TN.yaml:80, 84, 88, 97` (`excluded_families: [vat]`) | ouvert | |

## 3. Droit de timbre

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| S1 | 1,000 TND par facture, montant fixe par document, ajouté **par défaut** à chaque facture (la société peut le retirer d'un document ou changer le montant). | Code des droits d'enregistrement et de timbre art. 117 n° 6, modifié par le décret-loi n° 2022-79 (LF 2023) art. 69 ; Note commune DGELF n° 02/2023 du 14/02/2023 [3] (0,600 TND avant le 01/01/2023) | `TN.yaml:61-66` ; ajout `DocumentCalculator.php:107-112` ; défauts du document `ManageInvoices.php:602-618` | unvalidated | |
| S2 | Le timbre est hors de toute assiette (ni dans la base TVA, ni dans le seuil ou la base de la retenue à la source) et n'est touché par aucune remise. | Pas de texte explicite ; l'art. 6-I du Code de la TVA compte « tous frais, droits et taxes » dans la base (TVA exclue) [17], ce qui pourrait l'y inclure | `DocumentCalculator.php:99-116` | unvalidated (question Q1) | |
| S3 | Timbre majoré des grandes surfaces (1,5 TND de 50 à 100 TND, 2 TND au-delà) : **non modélisé** ; seul le montant est modifiable par la société. | LF 2026 art. 20 [5] | — | non modélisé | |
| S4 | Timbre de 0,100 TND par ticket de caisse (grandes surfaces, séries continues) : **non modélisé** ; prévu comme composante propre de la caisse, jamais par défaut sur une facture. | LF 2022 art. 54, art. 135 bis du Code des droits d'enregistrement et de timbre ; résumés de presse seulement, Note commune n° 15 non ouverte (TN.md l. 83) | — | non modélisé | |

Le timbre d'un avoir est traité en A5.

## 4. Retenue à la source

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| R1 | Facture à un client payeur soumis à la retenue : 1 % retenu lorsque le montant **TVA comprise** atteint **1 000,000 TND (seuil inclus)**. La retenue n'est pas un défaut de la société : elle vient des taxes par défaut du client. | Code de l'IRPP et de l'IS art. 52-I ; taux ramené de 1,5 % à 1 % par la loi n° 2020-46 du 23/12/2020 (LF 2021) art. 14 [4]. Le miroir 9anoun [11] montre encore 1,5 % (jugé périmé) | `TN.yaml:67-72` ; `DocumentCalculator.php:113-128` ; taxes du client `ManageInvoices.php:602-618` | unvalidated | |
| R2 | Seuil et base : total HT après remises + FODEC + TVA. Le timbre est exclu du seuil et de la base. | « y compris la TVA » est dans le texte [11] ; rien trouvé sur le timbre : choix du preset | `DocumentCalculator.php:101, 115-116, 127` | unvalidated (choix du preset pour le timbre) | |
| R3 | La retenue réduit le **net à payer**, jamais le total TTC de la facture ; la facture imprime le total, la retenue, puis le net à payer. | art. 52-I [11] | `DocumentCalculator.php:146` ; `api/templates/pdf/invoice.html.twig:148-155` | unvalidated | |
| R4 | Le seuil est apprécié **document par document** (le total de la facture), non par paiement, par marché ou par cumul. | source non citée dans docs/fiscal/TN.md | `DocumentCalculator.php:116` | décision du développeur (question Q7) | |
| R5 | Avoir d'une facture qui a subi une retenue : même taux, quel que soit le total de l'avoir (le seuil ne joue pas) ; l'avoir qui complète la base de la facture prend le reste exact du montant retenu, pour que les avoirs s'additionnent à la retenue de la facture. | rien trouvé sur la correction d'une facture retenue : choix du preset (TN.md l. 129) | `InvoiceTotals.php:425-434, 476-505` ; `DocumentCalculator.php:119-125` | unvalidated (choix du preset) | |
| R6 | Exclusions (abonnements, assurances, leasing et finance islamique, produits à prix homologués, achats aux agriculteurs et pêcheurs) et taux réduit de 0,5 % : **non modélisés** ; le taux est modifiable par société. | art. 52-I tel que montré par [11] | — | non modélisé | |
| R7 | La société qui **paie un fournisseur** : 1 % proposé dès 1 000,000 TND TVA comprise (timbre exclu du seuil et de la base) ; le paiement peut dire son propre taux (0,5 / 1 / 1,5, ou 0 pour aucun). Une dépense à plusieurs taux de TVA est retenue taux par taux (base + FODEC + TVA de chaque taux, chaque part arrondie), la retenue de la dépense est leur somme. | art. 52-I ; LF 2021 art. 14 [4][11] ; répartition 0,5 / 1 / 1,5 aussi chez [16] ; une source secondaire [16] dit « base hors TVA », contre le texte ; cahier des charges TEJ sept. 2026 § 7.3.2.4 : `MontantRS = MontantTTC × TauxRS` par opération [22] | `api/src/Module/Expenses/Application/ManageExpenses.php:264-283` ; `api/src/Module/Expenses/Domain/Model/Expense.php:612-615, 623-650` ; `api/src/Module/Expenses/Domain/ValueObject/WithholdingOperation.php:30-33` | unvalidated | |
| R8 | Retenue par nature (honoraires 3 %, loyers commerciaux 15 %…) : **non modélisée** ; la dépense ne dit pas sa nature, le taux est dit au paiement. | [16], non vérifié contre le code | — | non modélisé | |
| R9 | Déclaration TEJ du mois : un certificat par paiement, une opération par taux de TVA de la facture fournisseur, montants en millimes ; FODEC incluse dans `MontantHT`, timbre absent de toute opération. | Cahier des charges TEJ, sept. 2026 [22] | `api/src/Module/Expenses/Application/Tej/DeclareTejWithholdings.php:48, 128-170` ; `api/src/Module/Expenses/Infrastructure/Tej/TejDeclarationXml.php:92-107` | unvalidated (question Q9) | |
| R10 | Retenue de 25 % de la TVA par les acheteurs publics (art. 19 bis, dès 1 000 TND TTC) : **différée**, non modélisée. | Code de la TVA art. 19 bis [12] | — | non modélisé (décision du 2026-09-13) | |

## 5. Numérotation

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| N1 | Numéro pris **au moment de l'émission** (jamais sur un brouillon), dans la même transaction que l'enregistrement de la facture, série verrouillée : deux factures ne prennent jamais le même numéro, et une émission qui échoue rend son numéro (aucun trou). | Code de la TVA art. 18-II : « numéro … d'une série ininterrompue » [13] | `api/src/Tenancy/Application/Numbering/AllocateNumber.php:39-57` ; `InvoiceWorkflow.php:125` | unvalidated | |
| N2 | Une série par type de document **et par établissement**. Format par défaut `FAC-{YYYY}-{MM}-{SEQ:5}` (ex. FAC-2026-10-00042) ; la séquence repart à 1 au premier numéro de chaque année ; le mois est imprimé mais ne relance pas le compte. | art. 18-II pour la continuité [13] ; le format est une décision du 2026-10-04 (TN.md l. 224-226) | `TN.yaml:112-117` ; `api/src/Tenancy/Domain/Model/NumberingSeries.php:142-188` | unvalidated (format : décision du développeur) | |
| N3 | La date d'émission est le jour de la société (son fuseau) au moment de l'émission. Une date antérieure au mois du dernier numéro est refusée, ainsi qu'une date dans une période clôturée. | source non citée dans docs/fiscal/TN.md | `AllocateNumber.php:50-54` ; `NumberingSeries.php:179-181` ; `api/src/Tenancy/Domain/Model/Company.php:428` | décision du développeur | |
| N4 | Les **avoirs** ont leur propre série (`AV-{YYYY}-{MM}-{SEQ:5}`), distincte de celle des factures. | continuité : art. 18-II [13] ; série séparée : source non citée dans docs/fiscal/TN.md | `TN.yaml:114` ; `InvoiceWorkflow.php:125` (série du type de document) | décision du développeur | |
| N5 | La facture d'acompte est une facture : numérotée dans la série des factures. | art. 18-II [13] (TN.md l. 55) | `InvoiceWorkflow.php:125` | unvalidated | |
| N6 | Un numéro déjà porté par un document d'un autre établissement de la société est refusé (la société doit alors mettre le code d'établissement `{EST}` dans le format). Le format d'une série ne peut plus reculer une fois des numéros donnés. | source non citée dans docs/fiscal/TN.md | `InvoiceWorkflow.php:126-128` ; `NumberingSeries.php:120-121` | décision du développeur | |

## 6. Mentions obligatoires sur la facture

Art. 18-II du Code de la TVA [13] tel que résumé par TN.md l. 160-163 : date ; numéro d'une série ininterrompue ; nom, adresse et identifiant fiscal du vendeur ; nom et adresse du client et, sauf exceptions, son identifiant fiscal ; désignation et prix hors TVA de chaque article ; taux et montants de TVA ; pour les opérations en suspension (loi n° 2006-85), le montant de TVA suspendu.

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| M1 | Date d'émission et numéro imprimés en tête. | art. 18-II [13] | `invoice.html.twig:75, 86` | unvalidated | |
| M2 | Vendeur : raison sociale, adresse, matricule fiscal ; l'émission est **refusée** (et aucun numéro n'est pris) si l'un manque. | art. 18-II [13] | `api/src/Module/Invoices/Application/Print/PartyIdentity.php:32-37` ; `InvoiceWorkflow.php:115` ; `invoice.html.twig:46-56` | unvalidated | |
| M3 | Client : nom et adresse (ligne 1 et ville) toujours ; matricule fiscal exigé pour un **client professionnel tunisien**, pas pour un particulier ni un client étranger ; sinon émission refusée. | art. 18-II, « sauf exceptions » [13] | `PartyIdentity.php:38-52` ; `TN.yaml:25` ; `invoice.html.twig:59-64` | unvalidated | |
| M4 | Matricule fiscal lu partie par partie et enregistré `1234567A/B/M/000` : 7 chiffres, lettre clé (jamais I, O, U), code TVA (A, B, D, N, P), catégorie (C, M, N, P, ou E pour un établissement secondaire), 3 chiffres d'établissement. La lettre clé n'est **pas contrôlée** (aucun algorithme sourcé). | Disposition : cahier des charges « Déclaration d'employeur », 2006, p. 43-44 ; jeux de lettres : sources secondaires seulement, discordantes (F comme code TVA) [8] | `TN.yaml:20-36` | unvalidated | |
| M5 | Pour chaque ligne : désignation, quantité, unité, prix unitaire HT, remise, taxes, net HT de la ligne. | art. 18-II [13] | `invoice.html.twig:99-119` | unvalidated | |
| M6 | Pour chaque taux : libellé, base et montant ; puis total des taxes, timbre, total TTC, retenue, net à payer. | art. 18-II (taux et montants de TVA) [13] ; le reste n'est pas exigé par l'article | `invoice.html.twig:132-155` | unvalidated | |
| M7 | Opération en suspension de TVA : la mention « Vente en suspension de la TVA. » est imprimée, mais **aucun montant de TVA suspendu** (voir écart E2). | art. 18-II, loi n° 2006-85 [13] | `TN.yaml:82-85` ; `fiscal.fr.yaml:23` | unvalidated | |
| M8 | Mentions de régime imprimées : exonéré « Opération exonérée de la TVA. » ; suspension « Vente en suspension de la TVA. » ; export « Exportation exonérée de la TVA. » ; forfaitaire (T6). L'émission est refusée si une mention à compléter (une référence) n'a pas sa donnée. | **libellés non sourcés** (TN.md l. 166) | `api/translations/fiscal.fr.yaml:20-25` ; `api/src/Module/Invoices/Application/Print/InvoiceMentions.php:53-62, 81-109` ; `invoice.html.twig:174-181` | non sourcé | |
| M9 | Montant total en toutes lettres (« Arrêtée la présente facture à la somme de … »), activé par défaut, désactivable. | usage des factures tunisiennes, non trouvé dans l'art. 18-II (TN.md l. 168-170) | `TN.yaml:105-106` ; `api/src/Settings/Application/BusinessDefaultSettings.php:47` ; `invoice.html.twig:160-161` | usage, non sourcé comme loi | |
| M10 | Langue des documents du preset : français (l'arabe n'est pas dans le POC, TN.md l. 227). Devise imprimée sous les totaux. | langue : source non citée dans docs/fiscal/TN.md | `TN.yaml:9` ; `invoice.html.twig:172` | — | |

## 7. Avoirs et corrections

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| A1 | Une facture émise ne se modifie plus ; toute correction passe par un avoir, qui cite le numéro et la date de la facture corrigée et son motif. | source non citée dans docs/fiscal/TN.md (règle produit ; EN 16931 BG-3 pour la référence) | `api/src/Module/Invoices/Domain/Model/Invoice.php:690, 1353` ; `invoice.html.twig:77-79` | décision du développeur | |
| A2 | Un avoir applique exactement les règles d'une facture, tous les montants calculés étant négatifs (lignes saisies en positif). | pricing-vectors.json, convention `credit_sign` | `DocumentCalculator.php:32, 39` | décision du développeur | |
| A3 | Un avoir ne peut dépasser ce que la facture a facturé moins ses avoirs déjà émis ; si une partie était déjà payée, l'excédent va au crédit du client ou à un remboursement, au choix de la personne. | source non citée dans docs/fiscal/TN.md | `Invoice.php:850-868` ; `InvoiceWorkflow.php:160-163` | décision du développeur | |
| A4 | Un avoir rend les taxes que la facture a facturées, même si le régime du client a changé depuis. | source non citée dans docs/fiscal/TN.md | `InvoiceWorkflow.php:198-224` | décision du développeur | |
| A5 | **Timbre** : l'avoir ne porte pas de timbre propre. Le timbre de la facture est rendu **une seule fois, en entier**, par l'avoir qui complète la facture (celui qui crédite au moins ce qui reste de ses lignes) ; un avoir partiel ne rend rien. Autre valeur possible du preset : `kept`, jamais rendu. | NC n° 6/2004 [20] : « le droit de timbre couvre : toutes les factures y compris … les factures d'avoir » ; art. 119-I 7° (dû à l'émission), art. 129 (solidarité) [21] ; aucun texte trouvé sur la restitution : **décision du développeur** (voir écart E7) | `TN.yaml:16-18` ; `InvoiceTotals.php:278-286, 329-336, 381-396` ; `api/src/Fiscal/Domain/ValueObject/StampOnCorrection.php` | décision du développeur, unvalidated | |
| A6 | Remise globale : un avoir rend sa part au prorata de la base des lignes créditées ; l'avoir qui complète la facture rend le reste exact. Remise de ligne en montant : rendue au prorata des quantités, le reste à l'avoir qui complète la ligne. Un avoir n'a pas de remise globale propre. | Code de la TVA art. 6-I : la base est le prix réellement facturé [17] ; le partage lui-même est un choix de calcul | `InvoiceTotals.php:193-239, 295-316` | unvalidated | |
| A7 | TVA et FODEC : chaque avoir arrondit ses propres taxes ; l'avoir qui complète la facture prend le reste exact de chaque taxe lorsque l'écart n'excède pas 1 millime par avoir. | arithmétique, aucun texte | `InvoiceTotals.php:156-181` | unvalidated | |
| A8 | Retenue à la source d'un avoir : voir R5. Acompte : un avoir de la totalité d'une facture finale reprend ses lignes d'acompte ; un avoir partiel n'en reprend aucune. | décision du développeur (SPEC § 7, 2026-10-14 21:54) ; aucun texte (TN.md l. 56) | `DepositDeductions.php:55-98` | décision du développeur | |

## 8. Arrondis et devise

| N° | Règle appliquée par le logiciel | Source citée | Où dans le code | Statut actuel | Réponse de l'expert |
|---|---|---|---|---|---|
| D1 | Le dinar a 3 décimales (1 TND = 1 000 millimes) ; tout montant est tenu et imprimé au millime ; un montant plus fin (remise, timbre) est refusé. | ISO 4217 ; Unicode CLDR via Symfony Intl [TN.md l. 12] | `TN.yaml:5-6` ; `DocumentCalculator.php:481-487` | unvalidated | |
| D2 | Arrondi au millime le plus proche, la demie arrondie en s'éloignant de zéro (0,0005 → 0,001 ; −0,0005 → −0,001). Calcul en décimal exact, jamais en virgule flottante. | aucune règle officielle trouvée (TN.md l. 218) | `TN.yaml:12` ; `api/src/Fiscal/Domain/Calculation/Decimal.php:40-42` | unvalidated | |
| D3 | Remise de ligne (taux ou montant) arrondie au millime et déduite du montant de la ligne avant la remise globale et avant toute taxe. | EN 16931 BT-136 / BT-131 ; vecteur `line-discount-reduces-the-vat-base` | `DocumentCalculator.php:39-48` | décision du développeur (2026-08-23) | |
| D4 | Remise globale (un montant) répartie d'abord entre les groupes de lignes portant les mêmes taxes, au prorata de leur net, puis entre les lignes de chaque groupe ; méthode du plus fort reste, égalité à la première ligne. Les taxes se calculent ensuite sur les nets remisés. | décision du 2026-08-23 ; aucun texte | `DocumentCalculator.php:160-200` | décision du développeur | |
| D5 | Le montant de TVA (et de FODEC) arrondi une fois par taux est réparti entre les lignes : chaque part arrondie vers le bas, puis le millime manquant donné aux lignes au plus grand reste, de sorte que la colonne par ligne s'additionne exactement au total. | pricing-vectors.json, convention `per_line_vat` | `DocumentCalculator.php:244` ; `Decimal.php` (`allocate`) | décision du développeur | |
| D6 | Le preset peut aussi arrondir ligne par ligne (`per_line`), ce qui peut différer d'un millime (vecteurs `vat-rounding-order-diverges` 0,005 contre `…-per-line` 0,004) ; la Tunisie utilise l'arrondi par taux (T3). | TN.md l. 216-220 | `TN.yaml:13` ; `DocumentCalculator.php:241-242` | unvalidated | |
| D7 | Chaque document comptable est conservé 10 ans ; une société fermée est gardée jusqu'au 31 décembre de son année de fermeture plus 10 ans. | Code de l'IRPP et de l'IS art. 62 [19] ; le point de départ n'est pas dit par l'article | `TN.yaml:7-8` | unvalidated | |

## 9. Exemples de factures (montants en TND)

Chaque exemple est un cas de `docs/spec/pricing-vectors.json`, section `document_totals`, nommé par son identifiant. Les chiffres sont ceux que le fichier attend, et le calculateur du logiciel est tenu à ce fichier par `api/tests/Unit/Fiscal/Domain/PricingVectorsTest.php`. Chaque cas a été **recalculé indépendamment** (Python, `decimal`, arrondi au millime demi vers le haut, mêmes règles d'ordre et de répartition) : les 7 cas `tn-*` utilisés concordent tous au millime avec le fichier ; le cas `line-discount-reduces-the-vat-base` (exemple 5) a été vérifié à la main et concorde (150,000 × 10 % = 15,000 ; 135,000 × 19 % = 25,650, sans arrondi). Arrondi par taux (T3), prix saisis HT.

Pour la réponse : indiquer pour chaque exemple **Confirmé** ou le chiffre correct et pourquoi.

### Exemple 1 : TVA seule et timbre, sous le seuil de retenue (`tn-withholding-ignores-the-stamp-below-the-threshold`)
- Ligne : 1 × 840,000 HT, TVA 19 %. Document : timbre ; retenue 1 % portée par le client.
- Total HT 840,000 · FODEC — · base TVA 19 % 840,000 · TVA 19 % **159,600** · total des taxes 159,600 · timbre **1,000** · **total TTC 1 000,600**
- Montant pour le seuil de retenue (timbre exclu, R2) : 999,600 < 1 000,000 → **retenue 0,000** · **net à payer 1 000,600**
- Réponse : 

### Exemple 2 : deux taux de TVA et FODEC sur deux lignes (`tn-three-components-allocate-per-rate-group`)
- Lignes : L1 1 × 10,055 (FODEC + TVA 19 %) ; L2 1 × 20,055 (FODEC + TVA 19 %) ; L3 2 × 12,250 (TVA 7 %). Document : timbre.
- Total HT **54,610**
- FODEC : base 30,110 → **0,301** (arrondie une fois ; répartie L1 0,101, L2 0,200)
- TVA 19 % : base 30,411 (= 30,110 + 0,301) → **5,778** (L1 base 10,156 → 1,930 ; L2 base 20,255 → 3,848)
- TVA 7 % : base 24,500 → **1,715**
- Total des taxes 7,794 · timbre 1,000 · **total TTC 63,404** · retenue — · **net à payer 63,404**
- À titre de comparaison, arrondi ligne par ligne (`tn-three-components-per-line`) : FODEC 0,302, TVA 19 % 5,779 (base 30,412), TTC 63,406. La question de l'arrondi (Q16) porte sur cet écart de 2 millimes.
- Réponse : 

### Exemple 3 : FODEC dans la base de la TVA (`tn-fodec-enters-the-vat-base`)
- Ligne : 10 × 100,000 HT, FODEC + TVA 19 %. Document : timbre.
- Total HT 1 000,000 · FODEC 1 % **10,000** · base TVA 19 % **1 010,000** · TVA 19 % **191,900** · total des taxes 201,900 · timbre 1,000 · **total TTC 1 202,900** · retenue — · **net à payer 1 202,900**
- Lecture refusée par le logiciel : TVA sur le seul HT = 190,000, TTC 1 201,000.
- Réponse : 

### Exemple 4 : retenue à la source (`tn-withholding-applies-from-the-threshold`)
- La facture de l'exemple 3, à un client payeur de la retenue (RS 1 %, seuil 1 000,000).
- Total HT 1 000,000 · FODEC 10,000 · base TVA 1 010,000 · TVA 191,900 · timbre 1,000 · **total TTC 1 202,900**
- Base de retenue (HT + FODEC + TVA, timbre exclu) **1 201,900** ≥ 1 000,000 → retenue 1 % **12,019** · **net à payer 1 190,881**
- Seuil inclus (`tn-withholding-applies-exactly-at-the-threshold`) : 1 × 840,336 + TVA 19 % 159,664 = **1 000,000** exactement → retenue **10,000** ; TTC 1 001,000 (timbre compris) ; net à payer 991,000.
- Réponse : 

### Exemple 5 : remise globale sur des lignes avec et sans FODEC (`tn-document-discount-reaches-fodec-lines`)
- Lignes : L1 1 × 100,000 (FODEC + TVA 19 %) ; L2 1 × 50,000 (TVA 19 %). Remise globale 15,000. Ce cas du fichier ne porte **pas** de timbre.
- Total HT avant remise 150,000 · remise répartie au prorata : L1 **10,000**, L2 **5,000** · net après remise 135,000
- FODEC : base 90,000 → **0,900** · TVA 19 % : base 135,900 (L1 90,900, L2 45,000) → **25,821** (L1 17,271, L2 8,550)
- Total des taxes 26,721 · **total TTC 161,721** · **net à payer 161,721**
- Remise de ligne (`line-discount-reduces-the-vat-base`) : 10 × 15,000 = 150,000, remise 10 % = 15,000 → net 135,000 · TVA 19 % **25,650** · total 160,650 (la TVA sur 150,000, 28,500, est refusée).
- Réponse : 

### Exemple 6 : avoir total de l'exemple 3 (`tn-credit-note-refunds-fodec-and-stamp`)
- Lignes saisies comme sur la facture : 10 × 100,000, FODEC + TVA 19 %. Document : timbre. Tous les montants calculés sont négatifs.
- Total HT −1 000,000 · FODEC **−10,000** · base TVA −1 010,000 · TVA **−191,900** · total des taxes −201,900 · timbre **−1,000** · **total −1 202,900** · net −1 202,900
- Attention : ce cas exerce le calculateur seul. Dans le logiciel, le −1,000 de timbre n'apparaît que sur l'avoir qui **complète** la facture ; un avoir partiel ne rend pas de timbre (A5, `InvoiceTotals.php:329-336`). Ici l'avoir crédite toute la facture, donc le résultat est le même.
- Réponse : 

## 10. Écarts constatés

Aucun écart entre les chiffres du fichier de cas et le recalcul indépendant (section 9). Les écarts ci-dessous opposent un texte, la doc `docs/fiscal/TN.md`, le preset ou le code entre eux.

| N° | Écart | D'un côté | De l'autre | Ce qu'il faut trancher |
|---|---|---|---|---|
| E1 | La doc disait que les mentions de l'art. 18-II étaient listées dans le preset comme clés de traduction ; le preset n'en liste aucune : ces champs sont imprimés par le gabarit (section 6), seules les mentions de régime sont des « mentions ». Écart de rédaction, pas d'erreur fiscale. **Corrigé dans la doc le 2026-10-22.** | `docs/fiscal/TN.md:160-165` | `api/config/fiscal/TN.yaml:100-101` (`mentions: invoice: []`) ; `invoice.html.twig:46-155` | rien |
| E2 | L'art. 18-II demande, pour une opération en suspension, le **montant de TVA suspendu**. Le régime `suspended` retire la TVA de la facture : aucun montant n'est calculé ni imprimé, seule la phrase « Vente en suspension de la TVA. » l'est. | `docs/fiscal/TN.md:162-163` | `TN.yaml:82-85` ; `api/translations/fiscal.fr.yaml:23` (aucune autre occurrence de « suspen » liée à la TVA dans `api/src` ni `api/templates` : les autres concernent la suspension d'une société) | Q11 |
| E3 | Deux calculs de la même retenue de 1 % : sur la facture d'un client, arrondie une fois sur le total TTC hors timbre ; sur le paiement d'un fournisseur, arrondie taux de TVA par taux (TEJ). Exemple : 840,378 HT à 19 % (TTC 1 000,050) + 467,336 HT à 7 % (TTC 500,050) : 15,001 côté facture client, 15,002 côté paiement fournisseur. | `DocumentCalculator.php:127` ; TN.md § 5 l. 127-128 | `Expense.php:623-650` ; `WithholdingOperation.php:32` ; TN.md § 5a l. 147 | Q8 |
| E4 | Trois cas anciens du fichier de calcul portent un « droit de timbre » de **0,100**, alors que le timbre sur facture est de 1,000 (S1). Ce sont des cas d'arithmétique d'une charge fixe, pas l'énoncé de la règle tunisienne ; ils ne sont pas repris en exemples. | `pricing-vectors.json` : `consolidated-invoice-with-tunisian-stamp-duty`, `multi-rate-vat-breakdown-with-stamp-duty`, `credit-note-with-stamp-duty-refunded` | `TN.yaml:65` (`amount: '1.000'`) ; TN.md l. 80 | rien pour l'expert ; à renommer ou corriger dans le fichier |
| E5 | Le cas d'avoir du fichier rend −1,000 de timbre parce qu'il teste le calculateur seul ; le logiciel ne le rend que sur l'avoir qui complète la facture. Lu seul, le cas laisse croire que tout avoir rend le timbre. | `pricing-vectors.json` `tn-credit-note-refunds-fodec-and-stamp` | `InvoiceTotals.php:329-336, 381-396` ; `TN.yaml:17-18` | A5, Q2 |
| E6 | Acompte sur des **biens** : le logiciel facture la TVA sur l'acompte, alors que l'art. 5-2 fait de la livraison le fait générateur (l'avance n'en est pas un) : la TVA est collectée plus tôt que la loi ne l'exige ; un acompte de biens sans TVA n'est pas proposé. | Code de la TVA art. 5-2, TN.md l. 53 | `DepositDeductions.php:55` (lignes d'acompte avec leurs taxes) ; TN.md l. 248 (lacune connue) | Q14 |
| E7 | La source citée pour le timbre d'un avoir dit que **les factures d'avoir sont elles-mêmes soumises au droit de timbre** ; le logiciel ne fait porter aucun timbre propre à un avoir et rend au contraire celui de la facture, une fois. La doc le présente comme une décision du développeur. | NC n° 6/2004 [20], TN.md l. 89 | `TN.yaml:17-18` ; `InvoiceTotals.php:329-336, 413-423` | Q2, Q3 |

## 11. Questions ouvertes

Chacune reprend un point que `docs/fiscal/TN.md` marque comme non établi, non trouvé dans un texte ou non modélisé.

| N° | Question | D'où elle vient | Réponse de l'expert |
|---|---|---|---|
| Q1 | Le droit de timbre de 1,000 TND entre-t-il dans la base de la TVA au titre des « frais, droits et taxes » de l'art. 6-I du Code de la TVA, ou reste-t-il hors base comme le fait le logiciel (S2) ? | TN.md l. 81, 246 | |
| Q2 | Un avoir doit-il porter **son propre** timbre de 1,000 TND (NC n° 6/2004 : « y compris … les factures d'avoir ») ? Si oui, qui le supporte (art. 129) ? Le timbre de la facture créditée peut-il être rendu, ou reste-t-il acquis puisqu'il est dû à l'émission (art. 119-I 7°) ? | TN.md l. 89-91, 246 | |
| Q3 | La lecture de la NC n° 6/2004 comme couvrant toutes les factures (et non les seules professions libérales et entrepreneurs de bâtiment) est-elle la bonne ? L'art. 118-13° (factures « non acceptées … ou non acquittées ») et l'art. 74 (droits restituables) changent-ils quelque chose pour une facture refusée ou entièrement créditée ? | TN.md l. 94-105 | |
| Q4 | La FODEC s'applique-t-elle aux ventes à un client exonéré, en suspension de TVA, ou à l'export ? Et aux documents d'une société au régime forfaitaire ? | TN.md l. 199, 211 | |
| Q5 | Quel est le texte fondateur en vigueur de la FODEC (loi n° 88-145 modifiée, loi n° 94-127, art. 37 de la loi n° 99-101) et la liste de produits à jour (décret n° 2000-634, Note commune n° 13) ? | TN.md l. 71-74 | |
| Q6 | Existe-t-il une règle d'arrondi de la FODEC ? Le logiciel l'arrondit comme la TVA, une fois par taux puis réparti par ligne (F3). | TN.md l. 69 | |
| Q7 | Le seuil de 1 000 TND de la retenue s'apprécie-t-il par facture (comme le logiciel), par paiement, ou par marché / cumul ? Le timbre est-il bien exclu du seuil et de la base ? | TN.md l. 128 ; R2, R4 | |
| Q8 | Pour une facture à plusieurs taux de TVA, la retenue se calcule-t-elle sur le total (facture client, E3) ou taux par taux (TEJ, paiement fournisseur) ? Un écart d'un millime entre les deux est-il admis ? | TN.md l. 147 ; E3 | |
| Q9 | Dans le certificat TEJ, la FODEC doit-elle figurer dans `MontantHT`, et le timbre rester hors de toute opération et de la base de retenue ? | TN.md l. 149, 242 | |
| Q10 | Quel taux de retenue s'applique à une facture **émise** par la société quand son client retient : 1 % (régime IS à 15 %), 1,5 % (cas général de l'article) ou 0,5 % (IS à 10 %, déduction des deux tiers) ? Le logiciel propose 1 % et le taux se modifie par société (R1, R6). | TN.md l. 131, 145 | |
| Q11 | Pour une vente en suspension de TVA, comment le montant de TVA suspendu doit-il être imprimé (par taux, en total, avec la référence de l'attestation d'achat en suspension) ? | TN.md l. 162-163 ; E2 | |
| Q12 | Quels libellés exacts pour les mentions de régime : exonéré (avec quel article), suspension (loi n° 2006-85 et numéro d'attestation ?), export (« exonérée » ou « hors champ », art. 11), forfaitaire ? | TN.md l. 166, 245 | |
| Q13 | Une société au régime forfaitaire doit-elle le timbre sur ses factures ? Quels seuils de chiffre d'affaires la maintiennent dans le régime (art. 44 bis) ? | TN.md l. 212-214 | |
| Q14 | Acompte sur des biens : la TVA facturée sur l'acompte devient-elle due du seul fait qu'elle figure sur la facture ? Faut-il proposer un acompte de biens sans TVA ? Le timbre est-il dû sur une facture d'acompte ? Comment la FODEC s'applique-t-elle à un acompte sur des produits qui la portent ? | TN.md l. 59-61, 247 ; E6 | |
| Q15 | Matricule fiscal : quel est l'algorithme de la lettre clé ? Les jeux de lettres retenus (clé sans I/O/U, code TVA A/B/D/N/P, catégorie C/M/N/P/E) sont-ils exacts ; F est-il un code TVA valide ? | TN.md l. 177, 179 | |
| Q16 | Existe-t-il une règle officielle d'arrondi de la TVA : par taux sur la somme des lignes (logiciel) ou ligne par ligne (exemple 2 : 2 millimes d'écart) ? Le demi-millime arrondi vers le haut est-il admis ? | TN.md l. 218-220 | |
| Q17 | La série séparée des avoirs (AV-), la remise à 1 chaque année avec le mois imprimé, et une série par établissement satisfont-elles l'exigence d'une « série ininterrompue » de l'art. 18-II ? | TN.md l. 224-226 ; N2, N4 | |
| Q18 | Le montant en toutes lettres est-il une obligation (texte ?) ou un usage ? | TN.md l. 168-170 | |
| Q19 | Les ventes des premiers clients (quincaillerie, atelier d'usinage) peuvent-elles relever du timbre majoré des grandes surfaces (LF 2026 art. 20) ou du timbre de 0,100 TND par ticket de caisse (art. 135 bis) ? Le seuil de 50 TND aperçu dans un projet d'article est-il en vigueur ? | TN.md l. 82-83 | |
| Q20 | Afficher comme « TVA collectée » la TVA des factures émises dans le mois (pas encore une déclaration) est-il un indicateur acceptable, sachant qu'un service facturé avant exécution et avant paiement l'avance, et qu'un service payé avant sa facture la retarde ? | TN.md l. 43-46 | |
| Q21 | Les dix ans de conservation (art. 62) courent-ils de la clôture de l'exercice, de la date du document, ou d'ailleurs ? | TN.md l. 20-21 | |
| Q22 | Un avoir partiel d'une facture finale ne rend aucun acompte (A8) : est-ce correct ? | TN.md l. 56 | |
| Q23 | Un détaillant qui revend des produits listés (la quincaillerie) facture-t-il la FODEC, ou n'est-elle due qu'au producteur et à l'importateur ? | TN.md l. 67 | |
