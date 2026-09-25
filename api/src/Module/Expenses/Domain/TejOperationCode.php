<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

/**
 * The nature of a payment the Tunisian tax administration's TEJ platform declares a withholding under
 * (docs/research/tax-data-tunisia.md § 2.2): the 47 codes of `TEJRSCodesOperations_v1.0.xsd` (2026-09-15), each with
 * the label the administration publishes, kept as published, typing included. Which one a payment is depends on the
 * supplier, not on the rate, so it is said by whoever pays and never worked out.
 */
enum TejOperationCode: string
{
    /** The fiscal preset whose companies declare their withholdings to TEJ. */
    public const string PRESET = 'TN';

    case Rs1_000001 = 'RS1_000001';
    case Rs1_000002 = 'RS1_000002';
    case Rs2_000001 = 'RS2_000001';
    case Rs2_000002 = 'RS2_000002';
    case Rs2_000003 = 'RS2_000003';
    case Rs2_000004 = 'RS2_000004';
    case Rs2_000005 = 'RS2_000005';
    case Rs3_000001 = 'RS3_000001';
    case Rs3_000002 = 'RS3_000002';
    case Rs3_000003 = 'RS3_000003';
    case Rs3_000004 = 'RS3_000004';
    case Rs3_000005 = 'RS3_000005';
    case Rs4_000001 = 'RS4_000001';
    case Rs4_000002 = 'RS4_000002';
    case Rs5_000001 = 'RS5_000001';
    case Rs5_000002 = 'RS5_000002';
    case Rs5_000003 = 'RS5_000003';
    case Rs6_000001 = 'RS6_000001';
    case Rs6_000002 = 'RS6_000002';
    case Rs6_000003 = 'RS6_000003';
    case Rs6_000004 = 'RS6_000004';
    case Rs6_000005 = 'RS6_000005';
    case Rs7_000001 = 'RS7_000001';
    case Rs7_000002 = 'RS7_000002';
    case Rs7_000003 = 'RS7_000003';
    case Rs7_000004 = 'RS7_000004';
    case Rs7_000005 = 'RS7_000005';
    case Rs7_000006 = 'RS7_000006';
    case Rs7_000007 = 'RS7_000007';
    case Rs8_000001 = 'RS8_000001';
    case Rs8_000002 = 'RS8_000002';
    case Rs8_000003 = 'RS8_000003';
    case Rs9_000001 = 'RS9_000001';
    case Rs9_000002 = 'RS9_000002';
    case Rs9_000003 = 'RS9_000003';
    case Rs9_000004 = 'RS9_000004';
    case Rs9_000005 = 'RS9_000005';
    case Rs9_000006 = 'RS9_000006';
    case Rs9_000007 = 'RS9_000007';
    case Rs9_000008 = 'RS9_000008';
    case Rs10_000001 = 'RS10_000001';
    case Rs10_000002 = 'RS10_000002';
    case Rs10_000003 = 'RS10_000003';
    case Rs10_000004 = 'RS10_000004';
    case Rs10_000005 = 'RS10_000005';
    case Rs10_000006 = 'RS10_000006';
    case Rs11_000001 = 'RS11_000001';

    /** The label the administration publishes beside the code, in French. */
    public function label(): string
    {
        return match ($this) {
            self::Rs1_000001 => 'Loyers d’hôtels servis aux personnes morales et aux personnes physiques soumises à l’impôt sur le revenu selon le régime réel',
            self::Rs1_000002 => 'Loyers servis à des résidents établis',
            self::Rs2_000001 => 'Honoraires servis aux BNC forfait d’assiette, commissions, courtages, rémunérations des activités non commerciales qu\'elle qu\'en soit l\'appellation servis à des résidents établis.',
            self::Rs2_000002 => 'Honoraires servis aux BNC régime réel résidents établis.',
            self::Rs2_000003 => 'Rémunérations en contrepartie de la performance.',
            self::Rs2_000004 => 'Rémunérations servies aux artistes, aux créateurs soumis à l’impôt sur le revenu selon le régime réel et aux personnes morales au titre de la production, la diffusion et la présentation des œuvres théâtrales, scénique, musicale, littéraire et plastiques et cinématographique, et au titre des rémunérations servies aux titulaires des droits d\'auteur et des droits voisins dans le cadre de la gestion collective des droits de la propriété littéraire et artistique',
            self::Rs2_000005 => 'Honoraires servis aux BNC régime réel résidents, établis et exonérés de la retenue à la source au titre de l\'IRPP ou de l\'IS',
            self::Rs3_000001 => 'Revenus de capitaux mobiliers (autres que les dépôts en devise ou en dinars convertible) servis aux résidents soumi à l\'impots (IS ou IRPP).',
            self::Rs3_000002 => 'Revenus de capitaux mobiliers (autres que les dépôts en devise ou en dinars convertible) servis aux résidents non soumis à l\'impots (IS)..',
            self::Rs3_000003 => 'Revenus de capitaux mobiliers (autres que les dépôts en devise ou en dinars convertible) servis aux non résidents autres que banques',
            self::Rs3_000004 => 'Revenus de capitaux mobiliers servis aux banques non établit',
            self::Rs3_000005 => 'Revenus de capitaux mobiliers servis aux non établit (autres que les banques) et résidents dans un Etat ou un territoire dont le régime fiscal est privilégié',
            self::Rs4_000001 => 'Revenus de valeurs mobilières(cession d’actions, parts sociales et parts des fonds) servis à des personnes morales non résidentes non établis.',
            self::Rs4_000002 => 'Revenus de valeurs mobilières(cession d’actions, parts sociales et parts des fonds) servis à des personnes physiques non résidentes non établis.',
            self::Rs5_000001 => 'Dividendes servies à des personnes physiques résidentes',
            self::Rs5_000002 => 'Dividendes servis à des personnes physiques et personnes morales non résidentes.',
            self::Rs5_000003 => 'Dividendes servis à des personnes physiques et personnes morales résidentes dans un Etat ou un territoire dont le régime fiscal est privilégié',
            self::Rs6_000001 => 'Cession de fonds de commerce par les personnes morales et les personnes physiques résidentes',
            self::Rs6_000002 => 'Cession d’immeubles et des droits sociaux dans les sociétés immobilières par les personnes morales et les personnes physiques résidentes',
            self::Rs6_000003 => 'Cession d’immeubles et des droits sociaux dans les sociétés immobilières par les personnes physiques non residentes',
            self::Rs6_000004 => 'Cession d’immeubles et des droits sociaux dans les sociétés immobilières par les personnes physiques',
            self::Rs6_000005 => 'Cession d\'immeubles et des droits sociaux dans les sociétés immobilières par les personnes morales non résidentes et non établies en Tunisie.',
            self::Rs7_000001 => 'Montants égaux ou supérieurs à 1.000 D y compris la TVA au titre des acquisitions des marchandises, matériel équipements et de services, auprés des personnes physiques et des peronnes morales soumis à l\'IS au taux autres que 15% et 10%',
            self::Rs7_000002 => 'Montants égaux ou supérieurs à 1.000 D y compris la TVA au titre des acquisitions des marchandises, matériel équipements et de services, auprés des personnes physiques et des peronnes morales soumis à l\'IS au taux de 15%',
            self::Rs7_000003 => 'Montants égaux ou supérieurs à 1.000 D y compris la TVA au titre des acquisitions des marchandises, matériel équipements et de servicesauprés des personnes physiques bénéfiçiant de la déduction de 2/3 et des peronnes morales soumois à l\'IS au taux de 10%',
            self::Rs7_000004 => 'Le montant de la commission revenant aux distributeurs agréés des opérateurs de réseaux des télécommunications(personne physique).',
            self::Rs7_000005 => 'Le montant de la commission revenant aux distributeurs agréés des opérateurs de réseaux des télécommunications( personne morale).',
            self::Rs7_000006 => 'Montants égaux ou supérieurs à 1.000 D y compris la TVA au titre des acquisitions des marchandises, matériel équipements et de services, auprès des personnes physiques et des personnes morales exonérés de la retenue à la source au titre de l\'IRPP ou de l\'IS',
            self::Rs7_000007 => 'Montants payés par les prestataires de services de livraison aux personnes qui vendent leurs marchandises à travers l\'internet et les moyens de diffusion audiovisuelle',
            self::Rs8_000001 => 'Rémunérations et primes servies aux membres des conseils, des directoires et des comités des sociétés anonymes payes aux résidents.',
            self::Rs8_000002 => 'Rémunérations et primes servies aux membres des conseils, des directoires et des comités des sociétés anonymes payes aux non résidents.',
            self::Rs8_000003 => 'Rémunérations et primes servies aux membres des conseils, des directoires et des comités des sociétés anonymes payes aux résidents dans ans un Etat ou un territoire dont le régime fiscal est privilégié',
            self::Rs9_000001 => 'Rémunérations servies à des non-résidents non établis en Tunisie.',
            self::Rs9_000002 => 'Redevances (Rémunérations servies à des non-résidents non établis en Tunisie.)',
            self::Rs9_000003 => 'Rémunérations payées aux nresidents dans un Etat ou un territoire dont le régime fiscal est privilégié',
            self::Rs9_000004 => 'Rémunérations payées aux non-résidents et établis en Tunisie pour une période n’excédant pas 6 mois au titre : des travaux de construction',
            self::Rs9_000005 => 'Rémunérations payées aux non-résidents et établis en Tunisie pour une période n’excédant pas 6 mois au titre : des opérations de montage',
            self::Rs9_000006 => 'Rémunérations payées aux non-résidents et établis en Tunisie pour une période n’excédant pas 6 mois au titre : des autres services',
            self::Rs9_000007 => 'Les rémunérations payées aux non résidents établis en Tunisie qui ne procèdent pas au dépôt de la déclaration d\'existence autre qu’établissement stable d\'une spersonne physique ou morale residente dans un Etat ou un territoire dont le régime fiscal est privilégié',
            self::Rs9_000008 => 'Les rémunérations payées aux non résidents établis en Tunisie et residents dans un Etat ou un territoire dont le régime fiscal est privilégié qui ne procèdent pas au dépôt de la déclaration d\'existence',
            self::Rs10_000001 => 'Traitements, salaires, pensions et rentes viagères',
            self::Rs10_000002 => 'Traitements, salaires, pensions et rentes viagères pour une période dépassant pas 6 mois',
            self::Rs10_000003 => 'Traitements et salaires payés aux salariés de nationalité étrangère par les sociétés totalement exportatrices dans le cadre du code d’investissements, ou les établissements de crédits non résidents exerçant dans le cadre du code de prestation des services aux non résidents ou les entreprises exerçant dans les parcs d’activités économiques ou selon la réglementation relative à la production des carburants ou dans le cadre du code des mines ou selon une convention avec l’Etat tunisien',
            self::Rs10_000004 => 'Rémunérations payées aux salariés et aux non salariés en contrepartie d’un travail occasionnel ou accidentel en dehors de leur activité principale.',
            self::Rs10_000005 => 'Les rémunérations servies aux salariés non-résidents en Tunisie exerçant un emploi en Tunisie pour une période qui ne dépasse pas 6 mois,',
            self::Rs10_000006 => 'Contribution sociale de solidarité',
            self::Rs11_000001 => 'Jeux de pari et loterie autres que les paris mutuels sur les courses de chevaux et des concours de pronostics sportifs et les gains en nature des jeux de pari, de hasard et de loterie.',
        };
    }
}
