# Idées tranchées le 2026-09-25

Les 237 idées encore ouvertes après la marche du 2026-09-25, tranchées selon `docs/SPEC.md` § 7 2026-09-25 16:25 :
les neuf principes approuvés, chaque idée non marquée prend la recommandation, les notes du développeur priment.
Source : la page de marquage `BEKRVHDmLSY9bEEpKJhqYy` et `var/claude/ideas-walk/*.json`. Une idée « Oui » ou « Oui, autrement »
se construit avec l’écran ou la ligne du § 8 qu’elle touche ; « Non » est écarté ; « Plus tard » attend sa fonction.

## Difficile à défaire — Ce qu’un document émis garde pour toujours (avant le premier client)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DP-05 | Un code fiscal sur chaque article, repris sur les lignes pour la facture électronique | Oui, autrement | Un code choisi une fois sur l'article (liste du préréglage) ; la catégorie de la ligne en est déduite à l'émission. |
| DP-49 | Savoir de quel document est issu chaque document (devis, bon, facture) | Oui | Un seul lien typé « issu de » migré avant le premier client ; un réglage de société dit quelles étapes sont obligatoires, p. ex. « une facture doit venir d’un bon » (développeur, 2026-09-25). |
| DP-60 | Des champs personnalisés sur les factures et leurs lignes (n° de série, chantier) | Oui |  |
| DP-66 | La référence et le nom de l'article restent sur la facture même s'il est renommé | Oui | Nom et référence figés à l’émission, toujours ; un réglage de société « Un brouillon suit les changements de l’article », activé par défaut (développeur, 2026-09-25). |

## Difficile à défaire — Les suites de numéros (avant le premier client)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DOC-45 | Option : avoirs et factures partagent la même suite de numéros. | Oui, autrement | Un brouillon ne prend pas de numéro ; le prochain numéro se règle librement jusqu’à la première émission de la suite, puis il est verrouillé ; jamais de retour en arrière ni de réemploi : on corrige par un avoir (développeur, 2026-09-25). |
| MON-08 | Les paiements et reçus reçoivent un numéro suivi, comme les factures. | Oui, autrement | Réutiliser les séries de numérotation existantes, en déclarant qu'un numéro de reçu peut avoir des trous, pas une facture. |
| NAV-47 | Composer la numérotation de chaque document : année, mois, compteur remis à zéro. | Oui, autrement | Vérifier la page de numérotation existante contre cette liste, surtout la remise à zéro et le modèle par type. |

## Difficile à défaire — Annuler ou contrepasser (avant le premier client)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DOC-16 | Distinguer classer, annuler un brouillon et corriger une facture émise par un avoir. | Oui, autrement | On garde seulement annuler contre contrepasser par un avoir ; ni suppression douce ni restauration. |

## Difficile à défaire — Le compte courant du client (avant le premier client)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| MON-03 | Un avoir garde un solde utilisable sur une autre facture du client, ou remboursable. | Oui, autrement | L’avoir nomme toujours la facture qu’il corrige ; l’excédent va au solde du client ou en remboursement (ligne 128) ; pas d’avoir autonome. |
| MON-19 | Un compte courant par client, que le relevé, les retards et le plafond lisent tous. | Oui |  |

## Difficile à défaire — Les pénalités de retard automatiques (avant le premier client)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DOC-20 | Relancer automatiquement les factures en retard, en plusieurs étapes. | Oui, autrement | Relances par étapes et heure d'envoi gardées ; la pénalité de retard devient un choix séparé, désactivé par défaut. |
| MON-15 | Ajouter automatiquement des frais de retard quand une facture dépasse son échéance. | Oui, autrement | Règle en paliers configurable, mais livrée sans montant ni taux par défaut tant que la loi n'est pas sourcée. |
| CLI-14 | Ajouter automatiquement des frais de retard (fixes ou en %) quand une relance part. | Oui, autrement | Désactivé par défaut, réglé par entreprise, et facturé sur un document de débit séparé au lieu de modifier la facture émise. |

## Difficile à défaire — Le calcul des remises et des prix TTC (quand la fonction est construite)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| MON-31 | Saisir les prix toutes taxes comprises ou hors taxes, au choix. | Oui, autrement | Déjà largement présent ; on exige surtout des cas TTC à trois décimales dans le jeu de test du calcul des prix. |
| MON-38B | Remise en pourcentage ou en montant, sur une ligne ou sur tout le document. | Oui, autrement | On ajoute remise fixe en ligne et pourcentage sur le document ; avant ou après taxe relève du préset pays, pas d'un réglage. |

## Difficile à défaire — Ce qui sort de l’application : liens, acceptation, dépôts, clés, webhooks (quand la fonction est construite)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| NAV-40 | Créer des clés d'accès pour relier une boutique en ligne ou un logiciel comptable. | Oui |  |
| NAV-41 | Prévenir automatiquement un autre logiciel quand une facture est créée ou payée. | Oui, autrement | Un second consommateur du flux de changements en direct, pas un nouveau système ; les échecs vont au journal système. |
| CLI-05 | Un lien secret par destinataire pour ouvrir le document sans compte, et savoir qui l'a ouvert. | Oui |  |
| CLI-07 | Le client accepte le devis en un clic depuis un lien ; l'acceptation est datée et enregistrée. | Oui |  |
| CLI-11 | Le client renvoie lui-même un fichier (bon tamponné, preuve de virement) attaché au document. | Oui |  |
| CLI-37 | Envoyer automatiquement les événements (facture payée, devis accepté…) vers un autre logiciel. | Oui, autrement | Liste courte d'événements (envoyé, vu, accepté, payé, livraison reçue), journal des échecs et relance manuelle dès la v1. |

## Difficile à défaire — Supprimer, purger, fusionner (quand la fonction est construite)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DOC-52 | Fusionner deux fiches client (ou fournisseur) en double, documents compris. | Oui, autrement | Même fusion, mais rangée dans le module clients et fournisseurs plutôt que dans les documents. |
| NAV-05 | Archiver, supprimer et restaurer une fiche sans rien perdre de son historique. | Oui |  |
| NAV-38 | Un espace à part pour les actions irréversibles, avec confirmation renforcée. | Oui |  |

## Difficile à défaire — Projet ou chantier (quand la fonction est construite)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| WRK-15 | Regrouper tâches et dépenses d'un même chantier ou dossier client sous un « projet ». | Oui, autrement | Un seul objet : le projet regroupe tâches et dépenses ; le chantier de la ligne 80 est un projet né d’un devis accepté, pas un second objet. |
| DP-43 | Rattacher chaque document à un chantier ou un travail pour en suivre le coût | Oui |  |

## Documents (DOC)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DOC-02 | Chaque devis indique jusqu'à quand le prix proposé reste valable. | Oui |  |
| DOC-03 | Transformer un devis accepté en facture sans retaper les lignes, les deux restant liés. | Oui |  |
| DOC-04 | Commander au fournisseur, depuis un devis accepté, ce qu'on vient de promettre au client. | Oui, autrement | Reporté : seconde étape, une fois les bons de commande fournisseur en place, pas dans la première version des devis. |
| DOC-05 | Dupliquer un document pour en préparer un nouveau, par exemple une facture en devis. | Oui |  |
| DOC-06 | Garder la preuve que le client a accepté le devis : qui, quand, avec le scan signé. | Oui, autrement | Un constat d'acceptation (qui, quand, comment, scan signé) au lieu d'une signature dessinée en ligne, reportée. |
| DOC-07 | Laisser le client modifier les quantités au moment d'accepter un devis. | Non |  |
| DOC-08 | Relancer automatiquement un client qui n'a pas répondu à un devis. | Oui, autrement | On garde la relance du devis mais on retire la pénalité, sans objet sur une offre non acceptée. |
| DOC-09 | Ranger automatiquement les devis déjà transformés pour garder une liste claire. | Oui |  |
| DOC-10 | Le devis s'imprime avec les mêmes colonnes que la facture, réglées une seule fois. | Oui, autrement | Pas un interrupteur : l'impression du devis hérite des réglages de la facture, sauf s'ils sont remplacés. |
| DOC-14 | Un espace où le fournisseur voit, accepte et télécharge les commandes qu'on lui passe. | Non |  |
| DOC-15 | Envoyer le bon de commande par e-mail au fournisseur, le document joint. | Oui, autrement | L'envoi par e-mail, document joint, devient une capacité commune à tous les documents, pas propre au bon de commande. |
| DOC-18 | Découper le paiement d'une facture en plusieurs échéances datées (acompte, solde…). | Oui, autrement | Échéancier de montants datés et part due maintenant, mais sans prélèvement automatique faute de passerelle de paiement. |
| DOC-19 | Des conditions de paiement toutes prêtes (30 jours, fin de mois…) choisies par client. | Oui |  |
| DOC-21 | Ranger automatiquement les factures payées ou annulées hors de la liste de travail. | Oui |  |
| DOC-26 | Rembourser une partie d'un paiement en choisissant sur quelles factures. | Oui, autrement | Remboursement partiel réparti par facture, mais sans modèle imprimé propre au remboursement pour l'instant. |
| DOC-27 | Des factures qui se préparent toutes seules chaque mois pour les contrats réguliers. | Oui |  |
| DOC-28 | Augmenter d'un coup les prix de tous les contrats récurrents (révision annuelle). | Oui, autrement | Reporté : une suite des factures récurrentes, quand elles existent et qu'il y a assez de contrats pour le justifier. |
| DOC-29 | Générer automatiquement un nouveau devis à intervalles réguliers. | Non |  |
| DOC-30 | Des dépenses fixes (loyer, assurance) enregistrées automatiquement chaque mois. | Oui, autrement | Pas un sous-système à part : un second usage du même planificateur que les factures récurrentes. |
| DOC-32 | Choisir si la facture reprend le prix du jour de livraison ou le prix actuel. | Oui, autrement | Choix gardé, prix figé par défaut, règle inscrite sur le bon ; la date des taux attend l'avis d'un comptable. |
| DOC-33 | Un prix saisi à la main sur une ligne n'est jamais écrasé par un recalcul. | Oui |  |
| DOC-34 | Un bon de retour numéroté quand le client rapporte de la marchandise. | Oui |  |
| DOC-35 | Un bon de livraison se facture en entier ou pas du tout, jamais à moitié. | Oui, autrement | Pas de mécanisme : on écrit la règle et son avertissement, données prêtes pour un partiel par ligne plus tard. |
| DOC-36 | Chaque ligne de facture indique le bon de livraison et sa date d'origine. | Oui |  |
| DOC-37 | Un bon de livraison réimprimé indique par quelle facture il a été facturé. | Oui |  |
| DOC-38 | Voir sur le document l'historique de qui a changé quoi et quand. | Oui, autrement | Pas de second journal : on affiche sur l'écran du document l'historique d'audit qui existe déjà. |
| DOC-41 | Le client consulte et télécharge ses bons de livraison dans son espace. | Non |  |
| DOC-42 | Envoyer un document par e-mail et savoir s'il a été reçu, ouvert et consulté. | Oui |  |
| DOC-43 | Des modèles d'e-mail (objet et texte) réglables pour chaque type de document. | Oui, autrement | Un modèle par type de document et par étape de relance ; les modèles libres en plus sont retirés. |
| DOC-47 | Refacturer au client une dépense faite pour lui, justificatif joint. | Oui |  |
| DOC-48 | Joindre des fichiers à un document et choisir s'ils partent avec l'e-mail. | Oui, autrement | Fichiers sur tout document et choix de les joindre à l'e-mail ; pas de déblocage des fichiers après paiement. |
| DOC-49 | Envoyer automatiquement et régulièrement un relevé au client. | Oui, autrement | Une seule instance : le relevé client récurrent ; pas de moteur général de planification pour l'instant. |
| DOC-50 | Envoyer les factures électroniques aux formats européens par un réseau. | Non |  |
| DOC-51 | Vendre des abonnements que le client souscrit et paie lui-même en ligne. | Non |  |
| DOC-53 | Agir sur plusieurs documents d'un coup : envoyer, télécharger, classer. | Oui |  |
| DOC-57 | Un reçu de paiement à remettre ou envoyer au client quand il paie. | Oui |  |

## Argent (MON)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| MON-01 | Un seul virement du client réglant plusieurs factures, réparti facture par facture. | Oui |  |
| MON-02 | Un acompte ou un trop-perçu reste au crédit du client et sert sur ses prochaines factures. | Oui |  |
| MON-02B | Le client dépose une provision que les factures suivantes consomment jusqu'à épuisement. | Oui, autrement | Même solde détenu que l'acompte, avec un libellé et une échéance facultative, pas un second concept ; priorité basse. |
| MON-04 | Rembourser tout ou partie d'un paiement reçu, avec sa date et son moyen, sans rien effacer. | Oui |  |
| MON-05 | Annuler un paiement saisi par erreur par une contre-écriture datée, l'original restant visible. | Oui |  |
| MON-06 | Chaque paiement a son propre état (en attente, encaissé, annulé), distinct de celui de la facture. | Oui, autrement | Trois états seulement (en attente, encaissé, annulé), sans les états d'échec des passerelles en ligne écartées. |
| MON-07 | Imprimer ou envoyer un reçu numéroté quand un paiement est enregistré. | Oui |  |
| MON-12 | Des conditions de paiement nommées (« 30 jours », « à réception ») choisies sur le client ou la facture. | Oui, autrement | On garde le nombre de jours dans la chaîne des réglages et on ajoute seulement une liste de conditions nommées par-dessus. |
| MON-13 | Échéances du type « 30 jours fin de mois » ou « le 10 du mois suivant », calculées automatiquement. | Oui |  |
| MON-14 | Une facture payable en plusieurs échéances, chacune suivie et relancée séparément. | Oui |  |
| MON-16 | Relances de paiement programmées avant et après l'échéance, avec exclusion possible d'un client. | Oui, autrement | On prend le calendrier comme données (décalages, exclusion par client) et un canal hors e-mail, sans attendre l'e-mail. |
| MON-17 | Proposer une remise si le client paie avant une date donnée. | Oui, autrement | Adopté, mais planifié avec le pack pays : l'effet sur la base taxable doit être sourcé d'abord. |
| MON-22 | Voir ce que l'entreprise doit à ses fournisseurs, classé par ancienneté. | Oui |  |
| MON-25 | Calculer la TVA sur les factures émises ou sur les sommes réellement encaissées. | Oui |  |
| MON-26 | Suivre les retenues à la source subies et savoir quels certificats ont été reçus. | Oui |  |
| MON-29 | Inclure ou non les brouillons et éléments supprimés dans les chiffres des rapports. | Non |  |
| MON-34 | Enregistrer l'attestation de suspension de TVA d'un client et être averti quand elle expire. | Oui |  |
| MON-37 | Ajouter des frais au document (livraison, emballage), taxables ou non. | Oui, autrement | Frais typés via les composantes et frais de document existants, pas quatre emplacements anonymes fixes. |
| MON-38 | Le droit de timbre garde le montant en vigueur à la date de chaque facture. | Oui, autrement | On ne retient que le montant en série datée, pour qu'une réimpression garde le montant en vigueur à la date de la facture. |
| MON-39 | Arrondir les totaux aux cinq centimes pour le paiement en espèces. | Non |  |
| MON-41 | Enregistrer une facture fournisseur complète, payable en une ou plusieurs fois. | Oui |  |
| MON-42 | Bon de commande fournisseur accepté, marchandise reçue en stock, puis rapprochée de la facture. | Oui |  |
| MON-43 | Les dépenses fixes (loyer, assurance) se recréent seules chaque mois. | Oui |  |
| MON-44 | Une dépense avec plusieurs taux de taxe, saisie toutes taxes comprises. | Oui, autrement | La dépense reçoit le même jeu de composantes que les ventes (base saisie, timbre) au lieu d'un seul taux. |
| MON-45 | Refacturer à un client une dépense faite pour lui, avec une marge éventuelle. | Oui |  |
| MON-46 | Créer une dépense en brouillon à partir d'un justificatif déposé ou transféré. | Oui, autrement | D'abord une boîte de dépôt (fichier, photo) créant une dépense brouillon ; l'e-mail seulement quand le courrier entrant existe. |
| MON-47 | Enregistrer une dépense en devise étrangère, convertie au taux du jour. | Oui, autrement | Intégré au jalon devises déjà décidé, pour noter taux, source et date comme côté ventes. |
| MON-49 | Prévenir automatiquement le fournisseur quand sa facture est payée. | Oui, autrement | Fait avec l'envoi général et le même choix de canal que les relances : messagerie plutôt que seulement e-mail. |
| MON-50 | Connecter la banque pour importer les opérations automatiquement. | Non |  |
| MON-51 | Rapprocher les lignes du relevé bancaire des paiements et dépenses enregistrés. | Oui, autrement | Même rapprochement, mais alimenté par l'import d'un fichier de relevé bancaire, pas par une connexion bancaire directe. |
| MON-52 | Refuser un prix de vente inférieur au prix de revient du produit. | Oui |  |
| MON-55 | Retirer automatiquement de la liste les factures payées ou annulées. | Non |  |
| MON-56 | Verrouiller les documents d'une période close, et ne livrer les fichiers joints qu'après paiement. | Oui, autrement | La clôture de période est déjà décidée ; on ne prend que la remise des fichiers joints au client après paiement. |

## Navigation (NAV)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| NAV-Q1 | Un menu rangé comme l'entreprise travaille : vendre d'un côté, gérer de l'autre. | Oui, autrement | Les clients passent avant le stock, et l'ordre du menu devient un réglage d'entreprise avec un ordre par défaut. |
| NAV-Q3 | La page d'administration de la plateforme découpée en onglets au lieu d'une longue page. | Oui |  |
| NAV-Q4 | Choisir combien de lignes afficher par page, et que ce choix soit retenu. | Oui |  |
| NAV-02 | Voir en bas d'une liste filtrée le total des montants affichés, sans exporter. | Oui |  |
| NAV-03 | Cocher plusieurs lignes et agir sur toutes d'un coup (envoyer, télécharger…). | Oui |  |
| NAV-04 | Savoir clairement si « tout sélectionner » prend la page ou toute la recherche. | Oui |  |
| NAV-06 | Chaque liste se rouvre comme on l'a laissée (tri, filtres, taille de page). | Oui |  |
| NAV-08 | Modifier une ligne directement dans la liste sans ouvrir la fiche. | Non |  |
| NAV-09 | Parcourir une liste au clavier : flèches pour bouger, une touche pour ouvrir ou cocher. | Oui, autrement | On garde le strict minimum : se déplacer, ouvrir, sélectionner, chercher ; rien de plus tant que personne ne le demande. |
| NAV-10 | Passer à la fiche précédente ou suivante de la liste sans revenir en arrière. | Oui |  |
| NAV-11 | Créer vite une fiche avec un formulaire court, sans le formulaire complet. | Oui, autrement | Seulement là où le formulaire est vraiment long (bon de livraison, facture), pas pour les fiches courtes. |
| NAV-14 | Des rapports calculés, plus une liste exportable de n'importe quelles fiches. | Oui |  |
| NAV-15 | Un seul écran pour tous les rapports : période, filtres, aperçu puis export. | Oui |  |
| NAV-16 | Exporter un rapport en tableur ou l'envoyer par e-mail. | Oui, autrement | L'export d'abord ; l'envoi par e-mail vient plus tard, avec le moteur de planification. |
| NAV-17 | Recevoir automatiquement un rapport choisi (ex. chaque lundi) par e-mail. | Oui, autrement | Reporté après le moteur de planification au lieu d'être construit avant lui. |
| NAV-18 | Choisir quelles cartes s'affichent sur l'accueil et dans quel ordre. | Oui |  |
| NAV-19 | Changer la période des chiffres de l'accueil (jour, mois, année…). | Oui |  |
| NAV-20 | Sur l'accueil : factures bientôt dues, en retard, paiements reçus, devis qui expirent. | Oui |  |
| NAV-21 | Composer soi-même ses indicateurs (nombre, somme, moyenne sur une période). | Oui, autrement | Des cartes fixes d'abord ; leur définition reste prête pour accueillir plus tard des cartes créées par la personne. |
| NAV-27 | Accéder directement aux avoirs depuis le menu. | Oui |  |
| NAV-28 | Importer les relevés bancaires et les rapprocher des factures et dépenses. | Non |  |
| NAV-29 | Suivre le temps passé et les tâches, puis les facturer. | Non |  |
| NAV-30 | Définir ce que chaque rôle peut voir, créer et modifier, par type de fiche. | Oui, autrement | Des rôles nommés au lieu d'une grille par personne : un rôle est une grille enregistrée, et les options spéciales des permissions. |
| NAV-32 | Voir les versions précédentes d'un document : qui a changé quoi et quand. | Oui |  |
| NAV-33 | Voir sur un document ce qui a été envoyé, à qui, quand, et si c'est arrivé. | Oui, autrement | Envoyé à qui et quand dès maintenant ; remis, rejeté ou ouvert seulement avec un fournisseur mail qui le rapporte. |
| NAV-34 | Programmer des envois automatiques (relevés, rapports) avec leur prochaine date. | Oui, autrement | Un moteur commun où chaque module déclare ses propres types de planification, un seul type livré d'abord. |
| NAV-35 | Des réglages pour ce que l'appli fait seule : archiver, envoyer, convertir. | Oui |  |
| NAV-36 | Relances automatiques avant ou après l'échéance, avec leur texte et pénalité. | Oui |  |
| NAV-39 | Régler la durée des sessions et quand redemander le mot de passe. | Oui, autrement | La page sécurité existe : on ajoute le délai de session et la fenêtre de réauthentification, appliquée aux actions destructives. |
| NAV-42 | Exporter toutes les données de la société en un fichier, et pouvoir les restaurer. | Oui, autrement | L'export reste côté entreprise ; la restauration devient une action réservée à l'opérateur de la plateforme. |
| NAV-43 | Un journal des envois et échanges automatiques pour comprendre une panne. | Oui |  |
| NAV-45 | Importer un fichier en associant soi-même ses colonnes aux bons champs. | Oui, autrement | Le guide et les refus par ligne existent déjà ; on ajoute seulement l'écran d'association pour les fichiers hors modèle. |
| NAV-46 | Exporter toute la société depuis la même page que l'import. | Oui |  |
| NAV-48 | Des étiquettes libres sur les fiches pour les filtrer dans les listes et rapports. | Oui, autrement | Seulement une fois les filtres de liste et l'état mémorisé en place, sinon ils n'ont nulle part où servir. |
| NAV-49 | Des documents et un espace client à vos couleurs, sans la marque twes-in. | Oui, autrement | Pas de clé de déverrouillage : l'image de marque par entreprise devient une capacité liée à l'abonnement. |
| NAV-53 | Regrouper plusieurs bons de livraison en une facture, chaque ligne citant son bon. | Oui |  |
| NAV-54 | Passer d'une facture à ses bons de livraison et inversement en un clic. | Oui |  |
| NAV-55 | Enregistrer un retour de marchandise sur un bon de livraison, le stock suit. | Oui |  |

## Clients (CLI)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| CLI-01 | Choisir, pour chaque document, à quels contacts du client il part (destinataire, copie) et suivre chacun. | Oui |  |
| CLI-03 | Corriger après coup la date, le nom du réceptionnaire ou la facture liée d'un bon livré, avec historique. | Oui, autrement | Même liste de trois champs corrigeables, mais l'historique passe par l'audit existant, pas par un second registre. |
| CLI-04 | Un bloc « reçu par » complet : nom, heure, moyen de remise, photo du bon signé, qui l'a saisi. | Oui |  |
| CLI-06 | Voir si le client a bien ouvert la facture ou le devis, et quand, avant de le relancer. | Oui |  |
| CLI-10 | Un espace client où le client se connecte pour voir ses factures, devis, relevés et commander. | Oui, autrement | Par étapes : lien sans compte, puis mot de passe sur le lien, comptes seulement si demandés ; ni inscription libre ni tableau de bord. |
| CLI-12 | Un relevé de compte client : factures, paiements et avoirs sur une période, avec le solde. | Oui |  |
| CLI-13 | Relances automatiques des impayés en plusieurs paliers, qui s'arrêtent dès que la facture est payée. | Oui |  |
| CLI-15 | Un modèle de message par type de document (facture, devis, relance…) avec des champs qui se remplissent. | Oui |  |
| CLI-16 | Avant l'envoi, voir et retoucher le message, les destinataires et les pièces jointes pour cet envoi. | Oui |  |
| CLI-17 | Choisir si le PDF est joint au mail ou seulement en lien, et quelles pièces l'accompagnent. | Oui, autrement | Quelques interrupteurs par entreprise ; pas de fusion du fichier structuré dans le PDF tant que la facture électronique n'existe pas. |
| CLI-18 | Programmer l'envoi d'un document plus tard et fixer l'heure d'envoi des mails automatiques. | Oui, autrement | Heure d'envoi et envoi différé adoptés ; les rapports envoyés à intervalles réguliers attendent qu'il y ait des rapports utiles. |
| CLI-19 | Un contact peut refuser les mails automatiques ; l'équipe le voit, les envois manuels restent possibles. | Oui, autrement | D'abord l'enregistrement du refus et son signalement à l'équipe ; la page publique de préférences viendra avec le portail. |
| CLI-20 | Une adresse qui rejette les mails est marquée ; les envois automatiques s'arrêtent jusqu'à correction. | Oui, autrement | Marquage et blocage des adresses dès maintenant ; le retour automatique du fournisseur de mails sera branché une fois choisi. |
| CLI-21 | Les mails partent au nom de l'entreprise (nom, adresse de réponse, copie cachée), pas du logiciel. | Oui, autrement | Nom d'expéditeur, adresse de réponse et copie cachée tout de suite ; envoi par le domaine ou la boîte de l'entreprise plus tard. |
| CLI-22 | Envoyer le lien du document par SMS ou messagerie, avec le même suivi qu'un e-mail. | Oui, autrement | Chaque envoi porte un canal et un résultat par destinataire dès le départ, même si seul l'e-mail est fait au début. |
| CLI-23 | Choisir une mise en page différente pour chaque type de document parmi quelques modèles fournis. | Oui, autrement | Deux ou trois mises en page fournies par type de document ; ni éditeur, ni import/export, ni grande bibliothèque. |
| CLI-24 | Choisir quelles infos s'impriment en tête du document (réf. client, n° de commande, date de livraison…). | Oui |  |
| CLI-25 | Réglages d'impression pour l'entreprise : logo, couleurs, numéros de page, tampon « payée »… | Oui, autrement | Seulement couleurs, logo, format, en-tête et pied, numéros de page, tampon « payée », colonnes vides masquées, ligne de signature. |
| CLI-26 | Des conditions et un pied de page par défaut pour chaque type de document, modifiables sur chacun. | Oui, autrement | La chaîne de valeurs existe déjà ; seul ajout : des valeurs par type de document, modifiables sur chaque document. |
| CLI-28 | Créer un bon de livraison depuis une facture et choisir si, et quels, prix y figurent. | Oui, autrement | Choix des prix affichés, numérotation, conditions et pied propres au bon ; pas de création automatique depuis la facture. |
| CLI-29 | Produire, à côté du PDF, le fichier de facture électronique normalisé du pays. | Oui, autrement | Architecture multi-formats posée tout de suite, mais seul le format national est implémenté, comme premier et unique profil. |
| CLI-30 | Transmettre la facture par la plateforme officielle et voir sur le document si elle est bien passée. | Oui, autrement | Un registre de transmission par document (canal, résultat, date, nouvel essai), sans rejoindre le réseau européen. |
| CLI-31 | S'inscrire à un réseau européen de facturation électronique et vérifier que le client y est joignable. | Non |  |
| CLI-32 | Transmettre automatiquement les données de facturation au fisc au rythme exigé par le pays. | Oui, autrement | Bâti comme une obligation du pack fiscal de chaque pays, pas comme une fonction codée en dur, prêt pour le dispositif local. |
| CLI-33 | Transférer vers une adresse mail les factures électroniques reçues, et prévenir l'équipe. | Non |  |
| CLI-34 | Chaque membre choisit de quels événements il veut être prévenu, et comment. | Oui |  |
| CLI-35 | Être alerté quand le stock passe sous le seuil ou qu'un chiffre franchit une limite choisie. | Oui |  |
| CLI-38 | Publier les événements importants dans le groupe de discussion de l'équipe. | Oui, autrement | Une simple cible préconfigurée sur les webhooks sortants, vers l'outil de discussion que l'équipe utilise vraiment. |
| CLI-39 | Envoyer un mail à une adresse dédiée pour que la pièce jointe s'ajoute comme dépense ou document. | Non |  |
| CLI-42 | Importer les mouvements bancaires et les rapprocher automatiquement des factures et dépenses. | Non |  |
| CLI-43 | Synchroniser clients, factures et paiements avec un logiciel comptable, dans les deux sens. | Non |  |
| CLI-44 | Le client achète un abonnement en ligne, avec essai, changement de formule et résiliation. | Non |  |
| CLI-45 | Un site web externe affiche vos produits et crée clients et factures via une clé. | Non |  |
| CLI-46 | Le fournisseur ouvre un lien pour voir, accepter la commande et y déposer ses documents. | Non |  |
| CLI-47 | Voir les échéances des documents et les tâches dans son agenda habituel. | Non |  |
| CLI-48 | Pour chaque contact : reçoit les documents, seulement en copie, ou peut signer. | Oui |  |
| CLI-49 | Ajouter un nouveau contact depuis le document, sans quitter ni perdre la saisie. | Oui |  |
| CLI-50 | Joindre des fichiers à toute fiche et choisir lesquels le client peut voir. | Oui |  |
| CLI-51 | Rendre visibles au client les fichiers joints une fois la facture payée ou partagée. | Oui, autrement | Le verrouillage existe déjà ; on ne garde que la visibilité client des fichiers joints une fois le document payé ou partagé. |
| CLI-52 | Un petit message au client selon l'état du document (facture impayée, devis à accepter). | Oui, autrement | Seulement facture impayée et devis à accepter, sur la page du lien partagé ; le message du tableau de bord attend le portail. |
| CLI-55 | Documents et mails partent dans la langue du client, pas celle de la personne qui envoie. | Oui, autrement | La langue du document suit déjà le client ; on applique la même langue au mail d'accompagnement, puis on ajoute l'arabe. |
| CLI-56 | Un historique sur chaque fiche : créé, envoyé, vu, accepté, payé, corrigé — par qui et quand. | Oui |  |

## Travail (WRK)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| WRK-01 | Déclarer un travail fait pour un client (ou en interne), pour pouvoir le facturer ensuite. | Oui |  |
| WRK-02 | Noter le temps passé en plusieurs fois sur la même tâche, et voir le total. | Oui |  |
| WRK-03 | Choisir, pour chaque temps noté, s'il est facturable ou non, sans dédoubler la tâche. | Oui |  |
| WRK-04 | Un chronomètre qu'on lance et arrête sur une tâche, qui tourne même si l'onglet se ferme. | Oui |  |
| WRK-05 | Arrondir chaque temps noté (au quart d'heure, par exemple) pour facturer des chiffres propres. | Oui |  |
| WRK-08 | Transformer des tâches en lignes de facture en un clic, puis les verrouiller une fois facturées. | Oui |  |
| WRK-09 | En fin de mois, préparer d'un coup un brouillon de facture par client pour tout le travail non facturé. | Oui, autrement | Produit des brouillons de facture et une notification, au lieu d'émettre directement des factures numérotées. |
| WRK-10 | Chaque entreprise choisit ses propres étapes pour les tâches (à faire, en cours, fini…). | Oui |  |
| WRK-11 | Voir les tâches en colonnes par étape et les déplacer à la souris. | Oui, autrement | Tableau par étape conservé, mais sans ordre manuel mémorisé à l'intérieur d'une colonne (plus tard, en option). |
| WRK-12 | Confier une tâche à un membre de l'équipe et voir « mes tâches ». | Oui |  |
| WRK-13 | Saisir après coup « tel jour, tant d'heures » au lieu d'utiliser un chronomètre. | Oui |  |
| WRK-14 | Chaque matin, un résumé de ses tâches de la veille, dans les notifications de l'appli. | Oui, autrement | Résumé livré dans la cloche des notifications, activé par chaque membre, et non envoyé par e-mail. |
| WRK-17 | Savoir si un chantier rapporte : budget prévu contre heures, facturé, encaissé et dépenses. | Oui |  |
| WRK-18 | Une courbe qui montre si un projet dépasse son budget au fil du temps. | Oui, autrement | Reporté : d'abord les totaux et une barre de progression, la courbe dans le temps viendra plus tard. |
| WRK-19 | Rattacher une dépense à un projet, et proposer de la refacturer au client. | Oui, autrement | La dépense à refacturer produit une ligne proposée à valider, jamais une ligne ajoutée d'office à la facture. |
| WRK-20 | Donner un numéro à chaque projet, comme pour les autres documents. | Oui, autrement | Réutilise le mécanisme de numérotation existant au lieu d'inventer un compteur propre aux projets. |
| WRK-21 | Un modèle de facture qui se répète tout seul (mensuel, trimestriel…) pour les contrats. | Oui |  |
| WRK-22 | Choisir le rythme : chaque mois, chaque trimestre, toutes les 4 semaines, chaque année… | Oui |  |
| WRK-23 | Dire combien de fois la facture se répète (12 mois) ou la laisser tourner sans fin. | Oui |  |
| WRK-24 | Mettre en pause une facture récurrente quand un client suspend son contrat, sans rien perdre. | Oui |  |
| WRK-25 | Chaque facture générée calcule sa propre échéance (30 jours après, ou le 10 du mois). | Oui |  |
| WRK-26 | Le mois et l'année s'écrivent tout seuls dans les lignes (« Maintenance septembre 2026 »). | Oui |  |
| WRK-27 | Augmenter tous les prix d'un contrat de X % d'un coup (indexation annuelle). | Oui |  |
| WRK-28 | Remettre les prix d'un contrat à jour avec ceux du catalogue en un clic. | Oui |  |
| WRK-29 | Ne plus générer de facture à un client tant que la précédente n'est pas payée, avec une alerte. | Oui |  |
| WRK-30 | Choisir si la facture générée reste en brouillon ou part directement par e-mail au client. | Oui, autrement | Option à activer modèle par modèle, brouillon par défaut ; envoi aux adresses des contacts, sans lien vers un portail. |
| WRK-32 | Rattacher un contrat récurrent à un projet pour que ses revenus comptent dans le projet. | Oui |  |
| WRK-33 | Afficher la date de la prochaine facture dans le fuseau horaire du client. | Non |  |
| WRK-34 | Renvoyer automatiquement le même devis à intervalles réguliers. | Non |  |
| WRK-35 | Les dépenses fixes (loyer, assurance, internet) s'enregistrent toutes seules chaque mois. | Oui |  |
| WRK-36 | Les dépenses récurrentes se mettent en pause et s'arrêtent comme les factures récurrentes. | Oui |  |
| WRK-37 | Une dépense récurrente à refacturer est proposée quand on facture ce client. | Oui, autrement | La dépense à refacturer est proposée au moment de facturer ce client, et non ajoutée automatiquement. |
| WRK-38 | Donner un numéro à chaque dépense récurrente pour la retrouver facilement. | Oui, autrement | Numéro attribué par le mécanisme de numérotation existant, pas par un compteur dédié réglable à part. |
| WRK-39 | Une page publique où un inconnu s'abonne seul à une offre, avec code promo et essai. | Non |  |
| WRK-40 | Faire payer un abonnement selon le nombre d'utilisateurs, avec un maximum. | Non |  |
| WRK-41 | Changer de formule en cours de mois et ne payer que la différence au prorata. | Non |  |
| WRK-42 | Prévenir automatiquement un autre logiciel quand un abonnement change. | Non |  |
| WRK-43 | Relancer automatiquement les clients dont les factures sont en retard. | Oui, autrement | Sans cadre abonnement : une passe planifiée sur toute facture en retard, qui déclenche l'échelle de relances. |
| WRK-44 | Le client voit ses tâches et ses factures récurrentes dans son espace en ligne. | Non |  |
| WRK-45 | Programmer soi-même des envois réguliers (un relevé, un rapport) à la date choisie. | Oui, autrement | Moteur conservé, mais limité au départ à deux types de tâches programmées au lieu d'un catalogue complet. |
| WRK-46 | Faire payer une facture en plusieurs fois, chaque part avec sa date d'échéance. | Oui, autrement | Conçu depuis le contrat : des échéances (montant, date) sur une seule facture, reliées aux relances, pas un document récurrent. |

## Passe détaillée (DP)

| Idée | Ce que c’est | Décision | Précision |
|---|---|---|---|
| DP-10 | Une photo sur chaque article, pour le reconnaître d'un coup d'œil au comptoir | Oui |  |
| DP-11 | Une quantité proposée par défaut, pour les articles vendus par boîte | Oui, autrement | Surtout couvert par l'unité de vente ou le colis ; un simple champ par défaut là où ça ne suffit pas, sans réglage société. |
| DP-12 | Proposer de mettre à jour la fiche article quand on change un prix sur une ligne | Oui |  |
| DP-13 | Dupliquer un article pour en créer un presque identique | Oui |  |
| DP-16 | Relier chaque article à son fournisseur habituel | Oui |  |
| DP-38 | Indiquer sur la facture la période couverte (du … au …) | Oui |  |
| DP-40 | Désigner le vendeur ou l'employé responsable d'un client ou d'un document | Oui, autrement | Une seule référence facultative à un membre, ajoutée quand le filtrage « _own » ou une liste de relance en a besoin. |
| DP-41 | Garder qui a créé un brouillon : déjà connu par l'historique | Non |  |
| DP-50 | Garantir qu'aucun numéro de facture ne peut jamais être réutilisé | Oui |  |
| DP-61 | Garder la date exacte à laquelle un bon de livraison a sorti le stock | Oui |  |
| DP-64 | Des lignes de titre pour organiser un long devis en sections | Oui |  |
| DP-65 | Réordonner les lignes d'un document par glisser-déposer | Oui |  |
| DP-68 | Une date sur chaque ligne, pour les interventions faites à des jours différents | Oui, autrement | Reporté jusqu'aux lignes de temps de travail, et une période (du … au) plutôt qu'une seule date. |
| DP-70 | Vérifier la retenue à la source quand une remise fait passer sous le seuil | Oui |  |
| DP-73 | Enregistrer plusieurs adresses de chantier par client et les choisir à la livraison | Oui |  |
| DP-74 | Numéro client et fournisseur attribué automatiquement, modifiable si besoin | Oui |  |
| DP-75 | Ajouter le gouvernorat dans les adresses | Oui |  |
| DP-79 | Plusieurs contacts chez chaque fournisseur (commercial, comptabilité) | Oui |  |
| DP-81 | Indiquer si le client veut ses documents par e-mail, sur papier ou les deux | Oui |  |
| DP-82 | Des champs personnalisés sur les fiches fournisseur | Oui |  |
| DP-83 | Noter qui a vérifié le matricule fiscal d'un client, et quand | Oui, autrement | Pas une simple case cochée : on garde la date de vérification et le membre qui l'a faite, pour voir qu'elle vieillit. |
