<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * A described document as a UN/CEFACT Cross Industry Invoice (D16B) in the Factur-X EN 16931 profile, with PHP's own DOM:
 * the elements in the order the profile's schema lists them, an optional element left out rather than written empty,
 * dates as format 102 (YYYYMMDD), amounts as the description gives them and a currency only on the VAT total, as the
 * profile asks. The same description is always written byte for byte the same.
 */
final class CiiInvoiceXml
{
    /** The Factur-X EN 16931 profile's guideline (BT-24). */
    public const string GUIDELINE = 'urn:cen.eu:en16931:2017';

    private const string RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const string RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const string UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    private const string QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    /** UNTDID 4461: credit transfer. */
    private const string CREDIT_TRANSFER = '30';
    /** UNTDID 5189: discount. */
    private const string DISCOUNT = '95';
    /** ISO 6523 ICD of the French SIRENE register (SIREN, SIRET). */
    private const string SIRENE = '0002';

    private \DOMDocument $document;

    public function write(CiiInvoice $invoice): string
    {
        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
        $root = $this->document->createElementNS(self::RSM, 'rsm:CrossIndustryInvoice');
        $this->document->appendChild($root);
        // Every prefix declared once, on the root, as the published examples do.
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::QDT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::UDT);

        $context = $this->rsm($root, 'ExchangedDocumentContext');
        $this->ram($this->ram($context, 'GuidelineSpecifiedDocumentContextParameter'), 'ID', self::GUIDELINE);

        $header = $this->rsm($root, 'ExchangedDocument');
        $this->ram($header, 'ID', $invoice->number);
        $this->ram($header, 'TypeCode', $invoice->typeCode);
        $this->date($this->ram($header, 'IssueDateTime'), $invoice->issueDate);
        foreach ($invoice->notes as $note) {
            $this->ram($this->ram($header, 'IncludedNote'), 'Content', $note);
        }

        $transaction = $this->rsm($root, 'SupplyChainTradeTransaction');
        foreach ($invoice->lines as $line) {
            $this->line($transaction, $line);
        }
        $this->agreement($transaction, $invoice);
        $delivery = $this->ram($transaction, 'ApplicableHeaderTradeDelivery');
        if (null !== $invoice->deliveryDate) {
            $this->date($this->ram($this->ram($delivery, 'ActualDeliverySupplyChainEvent'), 'OccurrenceDateTime'), $invoice->deliveryDate);
        }
        $this->settlement($transaction, $invoice);

        return (string) $this->document->saveXML();
    }

    private function line(\DOMElement $transaction, CiiLine $line): void
    {
        $item = $this->ram($transaction, 'IncludedSupplyChainTradeLineItem');
        $this->ram($this->ram($item, 'AssociatedDocumentLineDocument'), 'LineID', $line->id);
        $product = $this->ram($item, 'SpecifiedTradeProduct');
        if (null !== $line->sellerItemId) {
            $this->ram($product, 'SellerAssignedID', $line->sellerItemId);
        }
        $this->ram($product, 'Name', $line->name);
        $this->ram($this->ram($this->ram($item, 'SpecifiedLineTradeAgreement'), 'NetPriceProductTradePrice'), 'ChargeAmount', $line->netPrice);
        $this->ram($this->ram($item, 'SpecifiedLineTradeDelivery'), 'BilledQuantity', $line->quantity)->setAttribute('unitCode', $line->unitCode);

        $settlement = $this->ram($item, 'SpecifiedLineTradeSettlement');
        $tax = $this->ram($settlement, 'ApplicableTradeTax');
        $this->ram($tax, 'TypeCode', 'VAT');
        $this->ram($tax, 'CategoryCode', $line->vat->category);
        if (null !== $line->vat->rate) {
            $this->ram($tax, 'RateApplicablePercent', $line->vat->rate);
        }
        if (null !== $line->allowanceAmount) {
            $allowance = $this->ram($settlement, 'SpecifiedTradeAllowanceCharge');
            $this->indicator($allowance, false);
            if (null !== $line->allowancePercent) {
                $this->ram($allowance, 'CalculationPercent', $line->allowancePercent);
            }
            if (null !== $line->allowanceBasis) {
                $this->ram($allowance, 'BasisAmount', $line->allowanceBasis);
            }
            $this->ram($allowance, 'ActualAmount', $line->allowanceAmount);
            $this->ram($allowance, 'ReasonCode', self::DISCOUNT);
        }
        $this->ram($this->ram($settlement, 'SpecifiedTradeSettlementLineMonetarySummation'), 'LineTotalAmount', $line->lineTotal);
    }

    private function agreement(\DOMElement $transaction, CiiInvoice $invoice): void
    {
        $agreement = $this->ram($transaction, 'ApplicableHeaderTradeAgreement');
        if (null !== $invoice->buyerReference) {
            $this->ram($agreement, 'BuyerReference', $invoice->buyerReference);
        }
        $this->party($this->ram($agreement, 'SellerTradeParty'), $invoice->seller);
        $this->party($this->ram($agreement, 'BuyerTradeParty'), $invoice->buyer);
    }

    private function party(\DOMElement $party, CiiParty $of): void
    {
        $this->ram($party, 'Name', $of->name);
        if (null !== $of->legalId) {
            $this->ram($this->ram($party, 'SpecifiedLegalOrganization'), 'ID', $of->legalId)->setAttribute('schemeID', self::SIRENE);
        }
        $address = $this->ram($party, 'PostalTradeAddress');
        foreach (['PostcodeCode' => $of->postcode, 'LineOne' => $of->line1, 'LineTwo' => $of->line2, 'CityName' => $of->city] as $name => $value) {
            if (null !== $value) {
                $this->ram($address, $name, $value);
            }
        }
        $this->ram($address, 'CountryID', $of->countryCode);
        if (null !== $of->vatId) {
            $this->ram($this->ram($party, 'SpecifiedTaxRegistration'), 'ID', $of->vatId)->setAttribute('schemeID', 'VA');
        }
    }

    private function settlement(\DOMElement $transaction, CiiInvoice $invoice): void
    {
        $settlement = $this->ram($transaction, 'ApplicableHeaderTradeSettlement');
        $this->ram($settlement, 'InvoiceCurrencyCode', $invoice->currency);
        if (null !== $invoice->payeeIban) {
            $means = $this->ram($settlement, 'SpecifiedTradeSettlementPaymentMeans');
            $this->ram($means, 'TypeCode', self::CREDIT_TRANSFER);
            $this->ram($this->ram($means, 'PayeePartyCreditorFinancialAccount'), 'IBANID', $invoice->payeeIban);
            if (null !== $invoice->payeeBic) {
                $this->ram($this->ram($means, 'PayeeSpecifiedCreditorFinancialInstitution'), 'BICID', $invoice->payeeBic);
            }
        }
        foreach ($invoice->vatBreakdown as $each) {
            $tax = $this->ram($settlement, 'ApplicableTradeTax');
            $this->ram($tax, 'CalculatedAmount', $each->tax);
            $this->ram($tax, 'TypeCode', 'VAT');
            $this->ram($tax, 'BasisAmount', $each->basis);
            $this->ram($tax, 'CategoryCode', $each->vat->category);
            if (null !== $each->vat->exemptionCode) {
                $this->ram($tax, 'ExemptionReasonCode', $each->vat->exemptionCode);
            }
            if (null !== $each->vat->rate) {
                $this->ram($tax, 'RateApplicablePercent', $each->vat->rate);
            }
        }
        foreach ($invoice->allowances as $each) {
            $allowance = $this->ram($settlement, 'SpecifiedTradeAllowanceCharge');
            $this->indicator($allowance, false);
            $this->ram($allowance, 'ActualAmount', $each->amount);
            $this->ram($allowance, 'ReasonCode', self::DISCOUNT);
            $category = $this->ram($allowance, 'CategoryTradeTax');
            $this->ram($category, 'TypeCode', 'VAT');
            $this->ram($category, 'CategoryCode', $each->vat->category);
            if (null !== $each->vat->rate) {
                $this->ram($category, 'RateApplicablePercent', $each->vat->rate);
            }
        }
        if (null !== $invoice->dueDate) {
            $this->date($this->ram($this->ram($settlement, 'SpecifiedTradePaymentTerms'), 'DueDateDateTime'), $invoice->dueDate);
        }

        $totals = $invoice->totals;
        $summation = $this->ram($settlement, 'SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->ram($summation, 'LineTotalAmount', $totals->lineTotal);
        if ([] !== $invoice->allowances) {
            $this->ram($summation, 'AllowanceTotalAmount', $totals->allowanceTotal);
        }
        $this->ram($summation, 'TaxBasisTotalAmount', $totals->taxBasisTotal);
        $this->ram($summation, 'TaxTotalAmount', $totals->taxTotal)->setAttribute('currencyID', $invoice->currency);
        $this->ram($summation, 'GrandTotalAmount', $totals->grandTotal);
        $this->ram($summation, 'DuePayableAmount', $totals->duePayable);

        if (null !== $invoice->precedingInvoiceNumber) {
            $preceding = $this->ram($settlement, 'InvoiceReferencedDocument');
            $this->ram($preceding, 'IssuerAssignedID', $invoice->precedingInvoiceNumber);
            if (null !== $invoice->precedingInvoiceIssueDate) {
                $this->element($this->ram($preceding, 'FormattedIssueDateTime'), self::QDT, 'qdt:DateTimeString', $invoice->precedingInvoiceIssueDate->format('Ymd'))->setAttribute('format', '102');
            }
        }
    }

    private function indicator(\DOMElement $parent, bool $charge): void
    {
        $this->element($this->ram($parent, 'ChargeIndicator'), self::UDT, 'udt:Indicator', $charge ? 'true' : 'false');
    }

    private function date(\DOMElement $parent, \DateTimeImmutable $day): void
    {
        $this->element($parent, self::UDT, 'udt:DateTimeString', $day->format('Ymd'))->setAttribute('format', '102');
    }

    private function rsm(\DOMElement $parent, string $name): \DOMElement
    {
        return $this->element($parent, self::RSM, 'rsm:'.$name, null);
    }

    private function ram(\DOMElement $parent, string $name, ?string $text = null): \DOMElement
    {
        return $this->element($parent, self::RAM, 'ram:'.$name, $text);
    }

    /** Text goes in as a text node, so an ampersand or an angle bracket in a name or a note is escaped, never parsed. */
    private function element(\DOMElement $parent, string $namespace, string $qualifiedName, ?string $text): \DOMElement
    {
        $element = $this->document->createElementNS($namespace, $qualifiedName);
        if (null !== $text) {
            $element->appendChild($this->document->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }
}
