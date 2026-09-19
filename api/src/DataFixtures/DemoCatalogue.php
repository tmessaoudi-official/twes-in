<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Tenancy\Domain\CompanyProfile;

/**
 * The two demo companies: a Tunisian consultancy that also sells office equipment, and a French cabinetmaker's
 * workshop. Every name is invented; addresses and e-mail domains use the reserved `.example` domain.
 */
final class DemoCatalogue
{
    /** @return list<DemoCompany> */
    public static function all(): array
    {
        return [self::tunisian(), self::french()];
    }

    public static function tunisian(): DemoCompany
    {
        $domestic = [
            'Groupe Hannibal Distribution' => 'Tunis', 'Sfax Textiles' => 'Sfax', 'Sahel Assurances Conseil' => 'Sousse',
            'Djerba Hôtels et Résidences' => 'Houmt Souk', 'Kairouan Tapis Artisanaux' => 'Kairouan', 'Bizerte Marine Services' => 'Bizerte',
            'Nabeul Poterie' => 'Nabeul', 'Sousse Informatique' => 'Sousse', 'Gabès Chimie Industrielle' => 'Gabès',
            'Tozeur Voyages' => 'Tozeur', 'Monastir Pharma' => 'Monastir', 'Hammamet Loisirs' => 'Hammamet',
            'Tabarka Liège' => 'Tabarka', 'Mahdia Pêche' => 'Mahdia', 'Béja Céréales' => 'Béja',
            'Zaghouan Eaux Minérales' => 'Zaghouan', 'Kasserine Bois et Charpente' => 'Kasserine', 'Jendouba Agro' => 'Jendouba',
            'Siliana Huile d\'Olive' => 'Siliana', 'Ariana Conseil RH' => 'Ariana', 'Ben Arous Logistique' => 'Ben Arous',
            'Manouba Imprimerie' => 'Manouba', 'Kébili Palmeraie' => 'Kébili', 'Médenine Bâtiment' => 'Médenine',
        ];
        $customers = [];
        $i = 0;
        foreach ($domestic as $name => $city) {
            $customers[] = new DemoCustomer($name, $city, regime: 5 === $i ? 'exempt' : 'standard', group: $i < 6 ? 0 : 1, inactive: $i >= 22, withheld: $i < 6, identifiers: ['matricule_fiscal' => \sprintf('%07dA/M/A/000', 1_000_003 + $i * 7919)]);
            ++$i;
        }
        foreach (['Amel Ben Salah' => 'La Marsa', 'Youssef Trabelsi' => 'Sfax', 'Leïla Gharbi' => 'Sousse', 'Karim Jaziri' => 'Tunis'] as $name => $city) {
            $customers[] = new DemoCustomer($name, $city, individual: true, group: 2);
        }
        $customers[] = new DemoCustomer('Lyon Import Méditerranée', 'Lyon', 'FR', regime: 'export');
        $customers[] = new DemoCustomer('Hamburger Handelshaus', 'Hamburg', 'DE', regime: 'export');

        return new DemoCompany(
            name: 'Carthage Conseil',
            country: 'TN',
            currency: 'TND',
            timezone: 'Africa/Tunis',
            scale: 3,
            profile: new CompanyProfile(
                legalName: 'Carthage Conseil SARL',
                legalForm: 'SARL',
                identifiers: ['matricule_fiscal' => '1234567A/B/M/000'],
                addressLine1: '12, avenue Habib Bourguiba',
                postalCode: '1000',
                city: 'Tunis',
                email: 'contact@carthage-conseil.example',
                phone: '+216 71 000 100',
                website: 'https://carthage-conseil.example',
            ),
            vatCode: 'TVA19',
            withholdingCode: 'RS1',
            customerGroups: ['Grands comptes', 'PME', 'Particuliers'],
            customers: $customers,
            productCategories: ['Conseil' => null, 'Formation' => null, 'Informatique' => null, 'Accessoires' => 'Informatique', 'Fournitures de bureau' => null, 'Mobilier' => null],
            products: [
                ['ref' => 'SRV-CONSEIL', 'name' => 'Conseil en organisation', 'service' => true, 'unit' => 'HUR', 'price' => '120.000', 'taxes' => ['TVA19'], 'category' => 'Conseil'],
                ['ref' => 'SRV-AUDIT', 'name' => 'Audit fiscal', 'service' => true, 'unit' => 'DAY', 'price' => '850.000', 'taxes' => ['TVA19'], 'category' => 'Conseil'],
                ['ref' => 'SRV-STRAT', 'name' => 'Atelier de stratégie', 'service' => true, 'unit' => 'DAY', 'price' => '1200.000', 'taxes' => ['TVA19'], 'category' => 'Conseil'],
                ['ref' => 'SRV-DOSSIER', 'name' => 'Montage de dossier d\'investissement', 'service' => true, 'unit' => 'C62', 'price' => '1500.000', 'taxes' => ['TVA19'], 'category' => 'Conseil'],
                ['ref' => 'SRV-TRAD', 'name' => 'Traduction certifiée (page)', 'service' => true, 'unit' => 'C62', 'price' => '40.000', 'taxes' => ['TVA19'], 'category' => 'Conseil'],
                ['ref' => 'FOR-EXCEL', 'name' => 'Formation Excel avancé', 'service' => true, 'unit' => 'DAY', 'price' => '600.000', 'taxes' => ['TVA19'], 'category' => 'Formation'],
                ['ref' => 'FOR-MGMT', 'name' => 'Formation management d\'équipe', 'service' => true, 'unit' => 'DAY', 'price' => '750.000', 'taxes' => ['TVA19'], 'category' => 'Formation'],
                ['ref' => 'FOR-FACT', 'name' => 'Formation facturation électronique', 'service' => true, 'unit' => 'DAY', 'price' => '680.000', 'taxes' => ['TVA19'], 'category' => 'Formation'],
                ['ref' => 'IT-MAINT', 'name' => 'Maintenance informatique', 'service' => true, 'unit' => 'HUR', 'price' => '75.000', 'taxes' => ['TVA19'], 'category' => 'Informatique'],
                ['ref' => 'IT-WEB', 'name' => 'Développement web', 'service' => true, 'unit' => 'DAY', 'price' => '700.000', 'taxes' => ['TVA19'], 'category' => 'Informatique'],
                ['ref' => 'IT-HEBERG', 'name' => 'Hébergement annuel', 'service' => true, 'unit' => 'C62', 'price' => '480.000', 'taxes' => ['TVA19'], 'category' => 'Informatique'],
                ['ref' => 'TRP-LIV', 'name' => 'Transport et livraison', 'service' => true, 'unit' => 'C62', 'price' => '35.000', 'taxes' => ['TVA13'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'PAP-A4', 'name' => 'Ramette papier A4', 'unit' => 'C62', 'price' => '12.500', 'taxes' => ['TVA19'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'PAP-A3', 'name' => 'Ramette papier A3', 'unit' => 'C62', 'price' => '24.000', 'taxes' => ['TVA19'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'ENC-NOIR', 'name' => 'Cartouche d\'encre noire', 'unit' => 'C62', 'price' => '45.000', 'taxes' => ['TVA19'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'ENC-COUL', 'name' => 'Cartouche d\'encre couleur', 'unit' => 'C62', 'price' => '62.000', 'taxes' => ['TVA19'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'CLS-LEV', 'name' => 'Classeur à levier', 'unit' => 'C62', 'price' => '6.800', 'taxes' => ['TVA19', 'FODEC'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'STY-BLEU', 'name' => 'Stylos bille bleus (boîte de 50)', 'unit' => 'C62', 'price' => '18.000', 'taxes' => ['TVA19'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'LIV-FISC', 'name' => 'Guide fiscal 2026', 'unit' => 'C62', 'price' => '55.000', 'taxes' => ['TVA7'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'CAF-GRAIN', 'name' => 'Café en grains', 'unit' => 'KGM', 'price' => '48.000', 'taxes' => ['TVA19'], 'category' => 'Fournitures de bureau'],
                ['ref' => 'USB-64', 'name' => 'Clé USB 64 Go', 'unit' => 'C62', 'price' => '28.000', 'taxes' => ['TVA19'], 'category' => 'Accessoires'],
                ['ref' => 'CAB-HDMI', 'name' => 'Câble HDMI 2 m', 'unit' => 'C62', 'price' => '15.000', 'taxes' => ['TVA19'], 'category' => 'Accessoires'],
                ['ref' => 'CLV-AZ', 'name' => 'Clavier AZERTY', 'unit' => 'C62', 'price' => '65.000', 'taxes' => ['TVA19'], 'category' => 'Accessoires'],
                ['ref' => 'SOU-SF', 'name' => 'Souris sans fil', 'unit' => 'C62', 'price' => '38.000', 'taxes' => ['TVA19'], 'category' => 'Accessoires'],
                ['ref' => 'ECR-24', 'name' => 'Écran 24 pouces', 'unit' => 'C62', 'price' => '520.000', 'taxes' => ['TVA19'], 'category' => 'Informatique'],
                ['ref' => 'PC-PORT', 'name' => 'Ordinateur portable 14 pouces', 'unit' => 'C62', 'price' => '2350.000', 'taxes' => ['TVA19'], 'category' => 'Informatique'],
                ['ref' => 'CHS-ERG', 'name' => 'Chaise de bureau ergonomique', 'unit' => 'C62', 'price' => '390.000', 'taxes' => ['TVA19', 'FODEC'], 'category' => 'Mobilier'],
                ['ref' => 'BUR-140', 'name' => 'Bureau 140 cm', 'unit' => 'C62', 'price' => '750.000', 'taxes' => ['TVA19', 'FODEC'], 'category' => 'Mobilier'],
                ['ref' => 'ARM-MET', 'name' => 'Armoire métallique', 'unit' => 'C62', 'price' => '890.000', 'taxes' => ['TVA19', 'FODEC'], 'category' => 'Mobilier'],
                ['ref' => 'IMP-LASER', 'name' => 'Imprimante laser (ancien modèle)', 'unit' => 'C62', 'price' => '690.000', 'taxes' => ['TVA19'], 'category' => 'Informatique', 'inactive' => true],
            ],
            expenseCategories: ['Loyer' => null, 'Fournitures' => null, 'Déplacements' => null, 'Carburant' => 'Déplacements', 'Télécom' => null, 'Honoraires' => null, 'Énergie' => null],
            vendors: [
                ['name' => 'Immobilière du Lac', 'city' => 'Tunis', 'category' => 'Loyer', 'amount' => '2400.000', 'untaxed' => true],
                ['name' => 'Bureautique Plus', 'city' => 'Tunis', 'category' => 'Fournitures', 'amount' => '310.500'],
                ['name' => 'Carburants Express', 'city' => 'Ben Arous', 'category' => 'Carburant', 'amount' => '185.000'],
                ['name' => 'NetLink Télécom', 'city' => 'Tunis', 'category' => 'Télécom', 'amount' => '149.900'],
                ['name' => 'Cabinet Comptable Mansour', 'city' => 'Ariana', 'category' => 'Honoraires', 'amount' => '900.000'],
                ['name' => 'Voyages Atlas', 'city' => 'Tunis', 'category' => 'Déplacements', 'amount' => '640.000'],
                ['name' => 'Imprimerie Nouvelle', 'city' => 'Manouba', 'category' => 'Fournitures', 'amount' => '275.000'],
                ['name' => 'Services Électricité et Eau', 'city' => 'Tunis', 'category' => 'Énergie', 'amount' => '420.000'],
            ],
            contacts: [['Sami', 'Mansouri', 'Directeur financier'], ['Ines', 'Chaabane', 'Comptable'], ['Walid', 'Hammami', 'Acheteur'], ['Rania', 'Ayari', 'Directrice'], ['Mehdi', 'Bouzid', 'Responsable achats'], ['Nour', 'Khelifi', 'Gérante']],
        );
    }

    public static function french(): DemoCompany
    {
        $domestic = [
            'Boulangerie Dupré' => 'Lyon', 'Hôtel des Tilleuls' => 'Annecy', 'Cabinet Moreau Architectes' => 'Grenoble',
            'Restaurant Le Comptoir' => 'Lyon', 'Librairie du Parc' => 'Villeurbanne', 'Crèche Les Petits Pas' => 'Bron',
            'Agence Rivière Immobilier' => 'Chambéry', 'Galerie Lemoine' => 'Paris', 'Studio Blanc Design' => 'Paris',
            'Café de la Gare' => 'Mâcon', 'Clinique vétérinaire Fontaine' => 'Valence', 'Maison Perrin Traiteur' => 'Lyon',
            'Cave Bernard et Fils' => 'Beaune', 'Pharmacie des Remparts' => 'Vienne', 'Salon de coiffure Élise' => 'Villeurbanne',
            'Auberge du Lac' => 'Aix-les-Bains', 'Fromagerie Girard' => 'Annecy', 'Atelier Roux Tapissier' => 'Lyon',
            'École Les Érables' => 'Écully', 'Garage Martin' => 'Givors', 'Fleuriste Marguerite' => 'Lyon',
            'Gîte des Trois Chênes' => 'Cluny', 'Menuiserie Lefèvre' => 'Oyonnax', 'Cabinet dentaire Morel' => 'Caluire',
        ];
        $customers = [];
        $i = 0;
        foreach ($domestic as $name => $city) {
            $customers[] = new DemoCustomer($name, $city, regime: 5 === $i ? 'exempt' : 'standard', group: $i < 6 ? 0 : 1, inactive: $i >= 22, identifiers: ['siren' => self::luhn(\sprintf('%08d', 30_000_000 + $i * 137_911))]);
            ++$i;
        }
        foreach (['Camille Laurent' => 'Lyon', 'Julien Faure' => 'Grenoble', 'Sophie Bonnet' => 'Annecy', 'Thomas Robert' => 'Lyon'] as $name => $city) {
            $customers[] = new DemoCustomer($name, $city, individual: true, group: 2);
        }
        $customers[] = new DemoCustomer('Holzwerk Berlin GmbH', 'Berlin', 'DE', regime: 'intra_eu');
        $customers[] = new DemoCustomer('Maison Dubois SA', 'Genève', 'CH', regime: 'export');

        return new DemoCompany(
            name: 'Atelier Mercier',
            country: 'FR',
            currency: 'EUR',
            timezone: 'Europe/Paris',
            scale: 2,
            profile: new CompanyProfile(
                legalName: 'Atelier Mercier SAS',
                legalForm: 'SAS',
                identifiers: ['siren' => '123456782', 'siret' => '12345678200010', 'vat_number' => 'FR11123456782'],
                addressLine1: '8, rue des Artisans',
                postalCode: '69003',
                city: 'Lyon',
                email: 'bonjour@atelier-mercier.example',
                phone: '+33 4 00 00 01 00',
                website: 'https://atelier-mercier.example',
            ),
            vatCode: 'TVA20',
            withholdingCode: null,
            customerGroups: ['Grands comptes', 'Professionnels', 'Particuliers'],
            customers: $customers,
            productCategories: ['Études' => null, 'Pose' => null, 'Ateliers' => null, 'Mobilier' => null, 'Fournitures' => null, 'Bois' => 'Fournitures', 'Quincaillerie' => 'Fournitures', 'Finitions' => 'Fournitures', 'Librairie' => null],
            products: [
                ['ref' => 'PRS-CONCEPT', 'name' => 'Conception sur mesure', 'service' => true, 'unit' => 'HUR', 'price' => '65.00', 'taxes' => ['TVA20'], 'category' => 'Études'],
                ['ref' => 'PRS-RELEVE', 'name' => 'Métré et relevé sur site', 'service' => true, 'unit' => 'C62', 'price' => '120.00', 'taxes' => ['TVA20'], 'category' => 'Études'],
                ['ref' => 'PRS-PLAN', 'name' => 'Plans 3D', 'service' => true, 'unit' => 'DAY', 'price' => '480.00', 'taxes' => ['TVA20'], 'category' => 'Études'],
                ['ref' => 'PRS-POSE', 'name' => 'Pose et installation', 'service' => true, 'unit' => 'HUR', 'price' => '55.00', 'taxes' => ['TVA20'], 'category' => 'Pose'],
                ['ref' => 'PRS-DEPLAC', 'name' => 'Déplacement', 'service' => true, 'unit' => 'C62', 'price' => '45.00', 'taxes' => ['TVA20'], 'category' => 'Pose'],
                ['ref' => 'PRS-RENOV', 'name' => 'Rénovation de meuble', 'service' => true, 'unit' => 'HUR', 'price' => '58.00', 'taxes' => ['TVA10'], 'category' => 'Pose'],
                ['ref' => 'PRS-ENTRET', 'name' => 'Contrat d\'entretien annuel', 'service' => true, 'unit' => 'C62', 'price' => '240.00', 'taxes' => ['TVA20'], 'category' => 'Pose'],
                ['ref' => 'PRS-LIVR', 'name' => 'Livraison', 'service' => true, 'unit' => 'C62', 'price' => '60.00', 'taxes' => ['TVA20'], 'category' => 'Pose'],
                ['ref' => 'PRS-VERNIS', 'name' => 'Vernissage', 'service' => true, 'unit' => 'MTK', 'price' => '32.00', 'taxes' => ['TVA20'], 'category' => 'Finitions'],
                ['ref' => 'PRS-LAQUE', 'name' => 'Laquage', 'service' => true, 'unit' => 'MTK', 'price' => '48.00', 'taxes' => ['TVA20'], 'category' => 'Finitions'],
                ['ref' => 'ATL-STAGE', 'name' => 'Stage d\'initiation au travail du bois', 'service' => true, 'unit' => 'DAY', 'price' => '190.00', 'taxes' => ['TVA20'], 'category' => 'Ateliers'],
                ['ref' => 'ATL-FAMILLE', 'name' => 'Atelier parents-enfants', 'service' => true, 'unit' => 'C62', 'price' => '35.00', 'taxes' => ['TVA20'], 'category' => 'Ateliers'],
                ['ref' => 'MEU-ETAG', 'name' => 'Étagère en chêne', 'unit' => 'C62', 'price' => '289.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier'],
                ['ref' => 'MEU-TABLE', 'name' => 'Table de ferme 200 cm', 'unit' => 'C62', 'price' => '1450.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier'],
                ['ref' => 'MEU-CHAISE', 'name' => 'Chaise bistrot', 'unit' => 'C62', 'price' => '129.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier'],
                ['ref' => 'MEU-BANC', 'name' => 'Banc en frêne', 'unit' => 'C62', 'price' => '340.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier'],
                ['ref' => 'MEU-BIBLIO', 'name' => 'Bibliothèque modulable', 'unit' => 'C62', 'price' => '890.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier'],
                ['ref' => 'MEU-CHEVET', 'name' => 'Table de chevet', 'unit' => 'C62', 'price' => '175.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier'],
                ['ref' => 'BOI-CHENE', 'name' => 'Planche de chêne', 'unit' => 'MTR', 'price' => '38.50', 'taxes' => ['TVA20'], 'category' => 'Bois'],
                ['ref' => 'BOI-HETRE', 'name' => 'Planche de hêtre', 'unit' => 'MTR', 'price' => '24.90', 'taxes' => ['TVA20'], 'category' => 'Bois'],
                ['ref' => 'BOI-CP18', 'name' => 'Contreplaqué 18 mm', 'unit' => 'MTK', 'price' => '32.00', 'taxes' => ['TVA20'], 'category' => 'Bois'],
                ['ref' => 'QUI-VIS', 'name' => 'Vis inox (boîte de 200)', 'unit' => 'C62', 'price' => '14.90', 'taxes' => ['TVA20'], 'category' => 'Quincaillerie'],
                ['ref' => 'QUI-CHARN', 'name' => 'Charnière invisible', 'unit' => 'C62', 'price' => '6.40', 'taxes' => ['TVA20'], 'category' => 'Quincaillerie'],
                ['ref' => 'QUI-POIGN', 'name' => 'Poignée en laiton', 'unit' => 'C62', 'price' => '12.80', 'taxes' => ['TVA20'], 'category' => 'Quincaillerie'],
                ['ref' => 'FIN-HUILE', 'name' => 'Huile dure', 'unit' => 'LTR', 'price' => '29.00', 'taxes' => ['TVA20'], 'category' => 'Finitions'],
                ['ref' => 'FIN-CIRE', 'name' => 'Cire d\'abeille', 'unit' => 'C62', 'price' => '16.50', 'taxes' => ['TVA20'], 'category' => 'Finitions'],
                ['ref' => 'LIB-GUIDE', 'name' => 'Guide de l\'ébénisterie', 'unit' => 'C62', 'price' => '34.00', 'taxes' => ['TVA5_5'], 'category' => 'Librairie'],
                ['ref' => 'LIB-CARNET', 'name' => 'Carnet de croquis', 'unit' => 'C62', 'price' => '12.00', 'taxes' => ['TVA20'], 'category' => 'Librairie'],
                ['ref' => 'ATL-REPAS', 'name' => 'Plateau-repas d\'atelier', 'unit' => 'C62', 'price' => '18.50', 'taxes' => ['TVA10'], 'category' => 'Ateliers'],
                ['ref' => 'MEU-COMMODE', 'name' => 'Commode ancienne (pièce unique)', 'unit' => 'C62', 'price' => '980.00', 'taxes' => ['TVA20'], 'category' => 'Mobilier', 'inactive' => true],
            ],
            expenseCategories: ['Loyer' => null, 'Matières premières' => null, 'Fournitures' => null, 'Déplacements' => null, 'Carburant' => 'Déplacements', 'Télécom' => null, 'Honoraires' => null, 'Énergie' => null],
            vendors: [
                ['name' => 'SCI Les Ateliers', 'city' => 'Lyon', 'category' => 'Loyer', 'amount' => '1850.00', 'untaxed' => true],
                ['name' => 'Scierie du Haut-Jura', 'city' => 'Morez', 'category' => 'Matières premières', 'amount' => '1240.00'],
                ['name' => 'Quincaillerie Centrale', 'city' => 'Lyon', 'category' => 'Fournitures', 'amount' => '186.40'],
                ['name' => 'Transports Rapides Rhône', 'city' => 'Vénissieux', 'category' => 'Déplacements', 'amount' => '320.00'],
                ['name' => 'Station Relais Carburants', 'city' => 'Lyon', 'category' => 'Carburant', 'amount' => '92.30'],
                ['name' => 'Fibre et Mobile Télécom', 'city' => 'Paris', 'category' => 'Télécom', 'amount' => '64.99'],
                ['name' => 'Cabinet Comptable Garnier', 'city' => 'Lyon', 'category' => 'Honoraires', 'amount' => '450.00'],
                ['name' => 'Énergie Verte Rhône', 'city' => 'Lyon', 'category' => 'Énergie', 'amount' => '210.00'],
            ],
            contacts: [['Claire', 'Dubois', 'Gérante'], ['Antoine', 'Lambert', 'Directeur'], ['Émilie', 'Rousseau', 'Architecte'], ['Nicolas', 'Chevalier', 'Chef de cuisine'], ['Hélène', 'Gauthier', 'Libraire'], ['Pierre', 'Masson', 'Directeur']],
        );
    }

    /** The eight digits followed by the Luhn check digit that makes the nine a valid SIREN. */
    private static function luhn(string $digits): string
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $value = (int) $digit * (0 === $position % 2 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $digits.((10 - $sum % 10) % 10);
    }
}
