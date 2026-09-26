<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application\FacturX;

use App\Module\Invoices\Application\FacturX\CiiAllowance;
use App\Module\Invoices\Application\FacturX\CiiInvoice;
use App\Module\Invoices\Application\FacturX\CiiInvoiceXml;
use App\Module\Invoices\Application\FacturX\CiiLine;
use App\Module\Invoices\Application\FacturX\CiiParty;
use App\Module\Invoices\Application\FacturX\CiiTotals;
use App\Module\Invoices\Application\FacturX\CiiVat;
use App\Module\Invoices\Application\FacturX\CiiVatBreakdown;
use PHPUnit\Framework\TestCase;

/** The Cross Industry Invoice written from a described document, in the Factur-X EN 16931 profile's element order. */
final class CiiInvoiceXmlTest extends TestCase
{
    public function testAStandardInvoiceIsWrittenElementByElement(): void
    {
        self::assertSame(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
              <rsm:ExchangedDocumentContext>
                <ram:GuidelineSpecifiedDocumentContextParameter>
                  <ram:ID>urn:cen.eu:en16931:2017</ram:ID>
                </ram:GuidelineSpecifiedDocumentContextParameter>
              </rsm:ExchangedDocumentContext>
              <rsm:ExchangedDocument>
                <ram:ID>FA-2026-00001</ram:ID>
                <ram:TypeCode>380</ram:TypeCode>
                <ram:IssueDateTime>
                  <udt:DateTimeString format="102">20260915</udt:DateTimeString>
                </ram:IssueDateTime>
                <ram:IncludedNote>
                  <ram:Content>Merci &amp; à bientôt &lt;3</ram:Content>
                </ram:IncludedNote>
              </rsm:ExchangedDocument>
              <rsm:SupplyChainTradeTransaction>
                <ram:IncludedSupplyChainTradeLineItem>
                  <ram:AssociatedDocumentLineDocument>
                    <ram:LineID>1</ram:LineID>
                  </ram:AssociatedDocumentLineDocument>
                  <ram:SpecifiedTradeProduct>
                    <ram:SellerAssignedID>ART-001</ram:SellerAssignedID>
                    <ram:Name>Réglage du tour</ram:Name>
                  </ram:SpecifiedTradeProduct>
                  <ram:SpecifiedLineTradeAgreement>
                    <ram:NetPriceProductTradePrice>
                      <ram:ChargeAmount>150.0000</ram:ChargeAmount>
                    </ram:NetPriceProductTradePrice>
                  </ram:SpecifiedLineTradeAgreement>
                  <ram:SpecifiedLineTradeDelivery>
                    <ram:BilledQuantity unitCode="C62">2.000</ram:BilledQuantity>
                  </ram:SpecifiedLineTradeDelivery>
                  <ram:SpecifiedLineTradeSettlement>
                    <ram:ApplicableTradeTax>
                      <ram:TypeCode>VAT</ram:TypeCode>
                      <ram:CategoryCode>S</ram:CategoryCode>
                      <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
                    </ram:ApplicableTradeTax>
                    <ram:SpecifiedTradeSettlementLineMonetarySummation>
                      <ram:LineTotalAmount>300.00</ram:LineTotalAmount>
                    </ram:SpecifiedTradeSettlementLineMonetarySummation>
                  </ram:SpecifiedLineTradeSettlement>
                </ram:IncludedSupplyChainTradeLineItem>
                <ram:ApplicableHeaderTradeAgreement>
                  <ram:BuyerReference>PO-77</ram:BuyerReference>
                  <ram:SellerTradeParty>
                    <ram:Name>Atelier Durand SARL</ram:Name>
                    <ram:SpecifiedLegalOrganization>
                      <ram:ID schemeID="0002">732829320</ram:ID>
                    </ram:SpecifiedLegalOrganization>
                    <ram:PostalTradeAddress>
                      <ram:PostcodeCode>69007</ram:PostcodeCode>
                      <ram:LineOne>12 rue des Forges</ram:LineOne>
                      <ram:CityName>Lyon</ram:CityName>
                      <ram:CountryID>FR</ram:CountryID>
                    </ram:PostalTradeAddress>
                    <ram:SpecifiedTaxRegistration>
                      <ram:ID schemeID="VA">FR44732829320</ram:ID>
                    </ram:SpecifiedTaxRegistration>
                  </ram:SellerTradeParty>
                  <ram:BuyerTradeParty>
                    <ram:Name>Garage Martin SAS</ram:Name>
                    <ram:SpecifiedLegalOrganization>
                      <ram:ID schemeID="0002">542065479</ram:ID>
                    </ram:SpecifiedLegalOrganization>
                    <ram:PostalTradeAddress>
                      <ram:PostcodeCode>75016</ram:PostcodeCode>
                      <ram:LineOne>3 avenue Foch</ram:LineOne>
                      <ram:LineTwo>Bâtiment B</ram:LineTwo>
                      <ram:CityName>Paris</ram:CityName>
                      <ram:CountryID>FR</ram:CountryID>
                    </ram:PostalTradeAddress>
                    <ram:SpecifiedTaxRegistration>
                      <ram:ID schemeID="VA">FR82542065479</ram:ID>
                    </ram:SpecifiedTaxRegistration>
                  </ram:BuyerTradeParty>
                </ram:ApplicableHeaderTradeAgreement>
                <ram:ApplicableHeaderTradeDelivery>
                  <ram:ActualDeliverySupplyChainEvent>
                    <ram:OccurrenceDateTime>
                      <udt:DateTimeString format="102">20260914</udt:DateTimeString>
                    </ram:OccurrenceDateTime>
                  </ram:ActualDeliverySupplyChainEvent>
                </ram:ApplicableHeaderTradeDelivery>
                <ram:ApplicableHeaderTradeSettlement>
                  <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
                  <ram:SpecifiedTradeSettlementPaymentMeans>
                    <ram:TypeCode>30</ram:TypeCode>
                    <ram:PayeePartyCreditorFinancialAccount>
                      <ram:IBANID>FR7630006000011234567890189</ram:IBANID>
                    </ram:PayeePartyCreditorFinancialAccount>
                    <ram:PayeeSpecifiedCreditorFinancialInstitution>
                      <ram:BICID>AGRIFRPP</ram:BICID>
                    </ram:PayeeSpecifiedCreditorFinancialInstitution>
                  </ram:SpecifiedTradeSettlementPaymentMeans>
                  <ram:ApplicableTradeTax>
                    <ram:CalculatedAmount>60.00</ram:CalculatedAmount>
                    <ram:TypeCode>VAT</ram:TypeCode>
                    <ram:BasisAmount>300.00</ram:BasisAmount>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
                  </ram:ApplicableTradeTax>
                  <ram:SpecifiedTradePaymentTerms>
                    <ram:DueDateDateTime>
                      <udt:DateTimeString format="102">20261015</udt:DateTimeString>
                    </ram:DueDateDateTime>
                  </ram:SpecifiedTradePaymentTerms>
                  <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                    <ram:LineTotalAmount>300.00</ram:LineTotalAmount>
                    <ram:TaxBasisTotalAmount>300.00</ram:TaxBasisTotalAmount>
                    <ram:TaxTotalAmount currencyID="EUR">60.00</ram:TaxTotalAmount>
                    <ram:GrandTotalAmount>360.00</ram:GrandTotalAmount>
                    <ram:DuePayableAmount>360.00</ram:DuePayableAmount>
                  </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                </ram:ApplicableHeaderTradeSettlement>
              </rsm:SupplyChainTradeTransaction>
            </rsm:CrossIndustryInvoice>

            XML, new CiiInvoiceXml()->write(self::standard()));
    }

    public function testDiscountsExemptionsAndTheCorrectedInvoiceAreWrittenWhereTheProfilePutsThem(): void
    {
        $xml = self::xpath(new CiiInvoiceXml()->write(self::creditNote()));

        self::assertSame('381', $xml->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode)'));
        $line = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem[1]/ram:SpecifiedLineTradeSettlement/';
        self::assertSame(['false', '10.00', '300.00', '30.00', '95'], [
            $xml->evaluate('string('.$line.'ram:SpecifiedTradeAllowanceCharge/ram:ChargeIndicator/udt:Indicator)'),
            $xml->evaluate('string('.$line.'ram:SpecifiedTradeAllowanceCharge/ram:CalculationPercent)'),
            $xml->evaluate('string('.$line.'ram:SpecifiedTradeAllowanceCharge/ram:BasisAmount)'),
            $xml->evaluate('string('.$line.'ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount)'),
            $xml->evaluate('string('.$line.'ram:SpecifiedTradeAllowanceCharge/ram:ReasonCode)'),
        ]);
        $settlement = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/';
        self::assertSame(['K', 'VATEX-EU-IC', '0.00', '0.00'], [
            $xml->evaluate('string('.$settlement.'ram:ApplicableTradeTax[2]/ram:CategoryCode)'),
            $xml->evaluate('string('.$settlement.'ram:ApplicableTradeTax[2]/ram:ExemptionReasonCode)'),
            $xml->evaluate('string('.$settlement.'ram:ApplicableTradeTax[2]/ram:RateApplicablePercent)'),
            $xml->evaluate('string('.$settlement.'ram:ApplicableTradeTax[2]/ram:CalculatedAmount)'),
        ]);
        self::assertSame(['false', '5.00', '95', 'VAT', 'S', '20.00'], [
            $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeAllowanceCharge/ram:ChargeIndicator/udt:Indicator)'),
            $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount)'),
            $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeAllowanceCharge/ram:ReasonCode)'),
            $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeAllowanceCharge/ram:CategoryTradeTax/ram:TypeCode)'),
            $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeAllowanceCharge/ram:CategoryTradeTax/ram:CategoryCode)'),
            $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeAllowanceCharge/ram:CategoryTradeTax/ram:RateApplicablePercent)'),
        ]);
        self::assertSame('5.00', $xml->evaluate('string('.$settlement.'ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:AllowanceTotalAmount)'));
        self::assertSame(['FA-2026-00001', '20260915'], [
            $xml->evaluate('string('.$settlement.'ram:InvoiceReferencedDocument/ram:IssuerAssignedID)'),
            $xml->evaluate('string('.$settlement.'ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString[@format="102"])'),
        ]);
        self::assertSame(0.0, $xml->evaluate('count(//ram:SpecifiedTradeSettlementPaymentMeans)'), 'no IBAN, no payment means');
    }

    public function testTheSameDocumentIsWrittenByteForByteTheSame(): void
    {
        $writer = new CiiInvoiceXml();

        self::assertSame($writer->write(self::creditNote()), new CiiInvoiceXml()->write(self::creditNote()));
    }

    public static function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        foreach (['rsm' => 'CrossIndustryInvoice', 'ram' => 'ReusableAggregateBusinessInformationEntity', 'udt' => 'UnqualifiedDataType', 'qdt' => 'QualifiedDataType'] as $prefix => $name) {
            $xpath->registerNamespace($prefix, "urn:un:unece:uncefact:data:standard:$name:100");
        }

        return $xpath;
    }

    public static function standard(): CiiInvoice
    {
        $vat = new CiiVat('S', '20.00', null);

        return new CiiInvoice(
            '380',
            'FA-2026-00001',
            new \DateTimeImmutable('2026-09-15'),
            'EUR',
            ['Merci & à bientôt <3'],
            new CiiParty('Atelier Durand SARL', '732829320', 'FR44732829320', '12 rue des Forges', null, '69007', 'Lyon', 'FR'),
            new CiiParty('Garage Martin SAS', '542065479', 'FR82542065479', '3 avenue Foch', 'Bâtiment B', '75016', 'Paris', 'FR'),
            'PO-77',
            new \DateTimeImmutable('2026-09-14'),
            null,
            null,
            'FR7630006000011234567890189',
            'AGRIFRPP',
            new \DateTimeImmutable('2026-10-15'),
            [new CiiLine('1', 'Réglage du tour', 'ART-001', '150.0000', '2.000', 'C62', null, null, null, $vat, '300.00')],
            [],
            [new CiiVatBreakdown($vat, '300.00', '60.00')],
            new CiiTotals('300.00', '0.00', '300.00', '60.00', '360.00', '360.00'),
        );
    }

    /** A credit note of a line at 20 % with its own 10 % discount and an intra-EU line, 5.00 off the whole at 20 %. */
    public static function creditNote(): CiiInvoice
    {
        $s = new CiiVat('S', '20.00', null);
        $k = new CiiVat('K', '0.00', 'VATEX-EU-IC');

        return new CiiInvoice(
            '381',
            'AV-2026-00001',
            new \DateTimeImmutable('2026-09-20'),
            'EUR',
            ['Pièce défectueuse'],
            new CiiParty('Atelier Durand SARL', '732829320', 'FR44732829320', '12 rue des Forges', null, '69007', 'Lyon', 'FR'),
            new CiiParty('Werkstatt Müller GmbH', null, 'DE123456789', 'Hauptstraße 1', null, '10115', 'Berlin', 'DE'),
            null,
            null,
            'FA-2026-00001',
            new \DateTimeImmutable('2026-09-15'),
            null,
            null,
            new \DateTimeImmutable('2026-09-20'),
            [
                new CiiLine('1', 'Arbre usiné', null, '100.0000', '3.000', 'C62', '10.00', '300.00', '30.00', $s, '270.00'),
                new CiiLine('2', 'Graisse', null, '20.0000', '1.000', 'C62', null, null, null, $k, '20.00'),
            ],
            [new CiiAllowance('5.00', $s)],
            [new CiiVatBreakdown($s, '265.00', '53.00'), new CiiVatBreakdown($k, '20.00', '0.00')],
            new CiiTotals('290.00', '5.00', '285.00', '53.00', '338.00', '338.00'),
        );
    }
}
