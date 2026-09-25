<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Tej;

use App\Module\Expenses\Application\Tej\TejCertificate;
use App\Module\Expenses\Application\Tej\TejDeclaration;
use App\Module\Expenses\Application\Tej\TejOperation;

/**
 * Writes a month's TEJ declaration as the file the platform takes: XML 1.0 in UTF-8, shaped by
 * `TEJDeclarationRS_v1.0.xsd` (cahier des charges TEJ, September 2026, § 4 and § 7). Where the cahier's prose or its
 * example and the schema disagree, the schema is followed, since it is the platform's first check: `TotalMontantTTC`
 * is written although the example leaves it out, and the element names keep the schema's spelling
 * (`NometprenonOuRaisonsociale`, `DatePayement`, `InfosContact`, `TotalPayement`).
 *
 * Every certificate is an addition by a matricule-identified, resident beneficiary, with no double-taxation
 * convention and no withholding borne by the payer: twes-in records none of those cases.
 */
final readonly class TejDeclarationXml
{
    private const string SCHEMA_VERSION = '1.0';

    /** `TypeIDTaxpayer`: 1 is a matricule fiscal. */
    private const string BY_MATRICULE = '1';

    /** `TypeTorF`. */
    private const string YES = '1';
    private const string NO = '0';

    public function write(TejDeclaration $declaration): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->appendChild($document->createElement('DeclarationsRS'));
        $root->setAttribute('VersionSchema', self::SCHEMA_VERSION);

        $this->matricule($root->appendChild($document->createElement('Declarant')), $declaration->declarant, $declaration->declarantCategory);

        $reference = $root->appendChild($document->createElement('ReferenceDeclaration'));
        $this->leaf($reference, 'ActeDepot', TejDeclaration::INITIAL);
        $this->leaf($reference, 'AnneeDepot', \sprintf('%04d', $declaration->year));
        $this->leaf($reference, 'MoisDepot', \sprintf('%02d', $declaration->month));

        if ([] !== $declaration->certificates) {
            $added = $root->appendChild($document->createElement('AjouterCertificats'));
            foreach ($declaration->certificates as $certificate) {
                $this->certificate($added->appendChild($document->createElement('Certificat')), $certificate);
            }
        }

        $xml = $document->saveXML();
        if (false === $xml) {
            throw new \RuntimeException('The TEJ declaration could not be written.');
        }

        return $xml;
    }

    private function certificate(\DOMElement $element, TejCertificate $certificate): void
    {
        $document = $this->document($element);
        $beneficiary = $element->appendChild($document->createElement('Beneficiaire'));
        $id = $beneficiary->appendChild($document->createElement('IdTaxpayer'));
        $this->matricule($id->appendChild($document->createElement('MatriculeFiscal')), $certificate->beneficiary, $certificate->beneficiaryCategory);
        $this->leaf($beneficiary, 'Resident', self::YES);
        $this->leaf($beneficiary, 'NometprenonOuRaisonsociale', $certificate->beneficiaryName);
        $this->leaf($beneficiary, 'Adresse', $certificate->beneficiaryAddress);
        $contact = $beneficiary->appendChild($document->createElement('InfosContact'));
        $this->leaf($contact, 'AdresseMail', $certificate->beneficiaryEmail);
        $this->leaf($contact, 'NumTel', $certificate->beneficiaryPhone);

        $this->leaf($element, 'DatePayement', $certificate->paidOn->format('d/m/Y'));
        $this->leaf($element, 'Ref_certif_chez_declarant', $certificate->reference);

        $operations = $element->appendChild($document->createElement('ListeOperations'));
        foreach ($certificate->operations as $operation) {
            $this->operation($operations->appendChild($document->createElement('Operation')), $operation);
        }

        $totals = $certificate->totals();
        $total = $element->appendChild($document->createElement('TotalPayement'));
        $this->leaf($total, 'TotalMontantHT', (string) $totals['amountNet']);
        $this->leaf($total, 'TotalMontantTVA', (string) $totals['vatAmount']);
        $this->leaf($total, 'TotalMontantTTC', (string) $totals['amountGross']);
        $this->leaf($total, 'TotalMontantRS', (string) $totals['withheld']);
        $this->leaf($total, 'TotalMontantNetServi', (string) $totals['netPaid']);
    }

    private function operation(\DOMElement $element, TejOperation $operation): void
    {
        $element->setAttribute('IdTypeOperation', $operation->code->value);
        $this->leaf($element, 'AnneeFacturation', \sprintf('%04d', $operation->invoiceYear));
        $this->leaf($element, 'CNPC', self::NO);
        $this->leaf($element, 'P_Charge', self::NO);
        $this->leaf($element, 'MontantHT', (string) $operation->amountNet);
        $this->leaf($element, 'TauxRS', $operation->withholdingRate);
        $this->leaf($element, 'TauxTVA', $operation->vatRate);
        $this->leaf($element, 'MontantTVA', (string) $operation->vatAmount);
        $this->leaf($element, 'MontantTTC', (string) $operation->amountGross);
        $this->leaf($element, 'MontantRS', (string) $operation->withheld);
        $this->leaf($element, 'MontantNetServi', (string) $operation->netPaid);
    }

    /** `TypeMatriculeFiscal`: the declarant, and a beneficiary under `IdTaxpayer`. */
    private function matricule(\DOMElement $element, string $identifier, string $category): void
    {
        $this->leaf($element, 'TypeIdentifiant', self::BY_MATRICULE);
        $this->leaf($element, 'Identifiant', $identifier);
        $this->leaf($element, 'CategorieContribuable', $category);
    }

    /** A child holding text, escaped by the DOM whatever it holds. */
    private function leaf(\DOMElement $parent, string $name, string $text): void
    {
        $document = $this->document($parent);
        $parent->appendChild($document->createElement($name))->appendChild($document->createTextNode($text));
    }

    private function document(\DOMElement $element): \DOMDocument
    {
        return $element->ownerDocument ?? throw new \LogicException('An element being written belongs to its document.');
    }
}
