<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\FakeFacturXPdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * An issued French invoice or credit note as its Factur-X file (docs/SPEC.md § 8 row 145): the EN 16931 CII XML alone,
 * or embedded in the invoice's PDF as PDF/A-3. Read with invoice.read, within the company.
 */
final class FacturXTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->frenchCompany('Atelier Durand');
        $this->company->reviseProfile(new CompanyProfile(
            legalName: 'Atelier Durand SARL',
            identifiers: ['siren' => '732829320', 'siret' => '73282932000013', 'vat_number' => 'FR44732829320'],
            addressLine1: '12 rue des Forges',
            postalCode: '69007',
            city: 'Lyon',
            iban: 'FR7630006000011234567890189',
            bic: 'AGRIFRPP',
        ));
        $this->em()->flush();
        $this->customerId = $this->customer($this->company, 'standard', ['siren' => '542065479', 'vat_number' => 'FR82542065479'])->getId()->toRfc4122();
    }

    public function testAnIssuedInvoiceAnswersItsCrossIndustryInvoice(): void
    {
        $this->signedIn();
        $id = $this->issued([
            'supplyDate' => '2026-09-14',
            'customerReference' => 'PO-77',
            'discountAmount' => '20',
            'lines' => [
                $this->line('Arbre usiné', '3', 'C62', '100', 'TVA20', '10'),
                $this->line('Main-d\'œuvre', '2', 'HUR', '45.5', 'TVA5_5'),
                $this->line('Graisse', '1', 'C62', '19.99', 'TVA10'),
            ],
        ]);
        $number = $this->stringAt($this->json(), 'number');

        $this->client->request('GET', $this->path($id).'/factur-x.xml');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/xml', $response->headers->get('content-type'));
        self::assertSame('attachment; filename='.$number.'.xml', $response->headers->get('content-disposition'));
        $xml = (string) $response->getContent();
        $read = self::xpath($xml);
        self::assertSame(['urn:cen.eu:en16931:2017', '380', $number, '20260914', 'PO-77'], [
            $read->evaluate('string(//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'),
            $read->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)'),
            $read->evaluate('string(//rsm:ExchangedDocument/ram:ID)'),
            $read->evaluate('string(//ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString)'),
            $read->evaluate('string(//ram:BuyerReference)'),
        ]);
        self::assertSame(['732829320', 'FR44732829320', 'FR82542065479', 'FR7630006000011234567890189'], [
            $read->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID[@schemeID="0002"])'),
            $read->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"])'),
            $read->evaluate('string(//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID)'),
            $read->evaluate('string(//ram:PayeePartyCreditorFinancialAccount/ram:IBANID)'),
        ]);
        self::assertSame(['270.00', '91.00', '19.99'], self::strings($read, '//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'));
        self::assertSame(['30.00'], self::strings($read, '//ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'));
        self::assertSame(['14.17', '4.78', '1.05'], self::strings($read, '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'));
        self::assertSame(['255.83', '86.22', '18.94'], self::strings($read, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:BasisAmount'));
        self::assertSame(['380.99', '20.00', '360.99', '57.80', '418.79', '418.79'], self::strings($read, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/*'));
        self::assertValidIfTheSchemaIsGiven($xml);

        $this->client->request('GET', $this->path($id).'/factur-x.xml');
        self::assertSame($xml, (string) $this->client->getResponse()->getContent(), 'the same document is written byte for byte the same');
    }

    public function testACreditNoteAnswersA381NamingItsInvoice(): void
    {
        $this->signedIn();
        $invoiceId = $this->issued(['lines' => [$this->line('Réglage du tour', '2', 'C62', '150', 'TVA20')]]);
        $invoiceNumber = $this->stringAt($this->json(), 'number');
        $invoiceDate = str_replace('-', '', $this->stringAt($this->json(), 'issueDate'));
        $this->postJson($this->path($invoiceId).'/credit-notes', ['creditNoteReason' => 'Pièce défectueuse']);
        $creditId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($creditId).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->request('GET', $this->path($creditId).'/factur-x.xml');

        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        $read = self::xpath($xml);
        self::assertSame(['381', 'Pièce défectueuse', $invoiceNumber, $invoiceDate], [
            $read->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)'),
            $read->evaluate('string(//rsm:ExchangedDocument/ram:IncludedNote/ram:Content)'),
            $read->evaluate('string(//ram:InvoiceReferencedDocument/ram:IssuerAssignedID)'),
            $read->evaluate('string(//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString)'),
        ]);
        self::assertSame(['300.00', '300.00', '60.00', '360.00', '360.00'], self::strings($read, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/*'), 'a credit note\'s amounts are positive');
        self::assertValidIfTheSchemaIsGiven($xml);
    }

    public function testADocumentLackingWhatEn16931AsksForAnswersEveryGapAndADraftIsNotAnInvoiceYet(): void
    {
        $this->signedIn();
        $this->postJson($this->path(), $this->invoice(['lines' => [$this->line('Réglage', '1', 'C62', '10', 'TVA20')]]));
        $draftId = $this->stringAt($this->json(), 'id');

        $this->client->request('GET', $this->path($draftId).'/factur-x.xml');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['not_issued', ['status' => 'draft']], [$this->json()['code'], $this->json()['params']]);
        $this->client->request('GET', $this->path($draftId).'/factur-x.pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $id = $this->issued(['lines' => [$this->line('Réglage', '1', 'C62', '10', 'TVA20')]]);
        $this->company = $this->reloaded();
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Atelier Durand SARL', identifiers: ['siren' => '732829320'], addressLine1: '12 rue des Forges', city: 'Lyon'));
        $this->em()->flush();

        $this->client->request('GET', $this->path($id).'/factur-x.xml');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $refusal = $this->json();
        self::assertSame(['incomplete_document', ['count' => 2]], [$refusal['code'], $refusal['params']]);
        self::assertSame([
            ['code' => 'seller_vat_number_missing', 'params' => []],
            ['code' => 'seller_address_incomplete', 'params' => ['missing' => ['postalCode']]],
        ], $refusal['gaps']);
        $this->client->request('GET', $this->path($id).'/factur-x.pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], static::getContainer()->get(FakeFacturXPdf::class)->embedded, 'nothing refused reaches the PDF engine');
    }

    public function testThePdfCarriesTheXmlInTheInvoicesOwnPdf(): void
    {
        $this->signedIn();
        $id = $this->issued(['lines' => [$this->line('Réglage du tour', '2', 'C62', '150', 'TVA20')]]);
        $number = $this->stringAt($this->json(), 'number');
        $this->client->request('GET', $this->path($id).'/pdf');
        $stored = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', $this->path($id).'/factur-x.xml');
        $xml = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', $this->path($id).'/factur-x.pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('content-type'));
        self::assertSame('inline; filename='.$number.'-factur-x.pdf', $response->headers->get('content-disposition'));
        $embedded = static::getContainer()->get(FakeFacturXPdf::class)->embedded;
        self::assertCount(1, $embedded);
        self::assertSame([$stored, $xml], $embedded[0], 'the PDF the invoice was issued with, and the same XML the XML route answers');
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());

        // Kept for the next request: a reboot would hand it a fresh fake that does not fail.
        $this->client->disableReboot();
        static::getContainer()->get(FakeFacturXPdf::class)->failing = true;
        $this->client->request('GET', $this->path($id).'/factur-x.pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public function testAnotherCompanysDocumentAndAMemberWithoutInvoiceReadFindNothing(): void
    {
        $this->signedIn();
        $id = $this->issued(['lines' => [$this->line('Réglage', '1', 'C62', '10', 'TVA20')]]);
        $globex = $this->frenchCompany('Globex');
        $this->createUser('globex@twes.local', 'password-1234', $globex, ['invoice.read'], 'member');
        $this->createUser('stock@twes.local', 'password-1234', $this->reloaded(), ['product.read'], 'stock');

        foreach (['globex@twes.local' => '/api/companies/'.$globex->getId()->toRfc4122().'/invoices/'.$id, 'stock@twes.local' => $this->path($id)] as $email => $path) {
            $this->sendJson('POST', '/api/auth/logout');
            $this->login($email, 'password-1234');
            foreach (['/factur-x.xml', '/factur-x.pdf'] as $file) {
                $this->client->request('GET', $path.$file);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $email.$file);
            }
        }
    }

    public static function assertValidIfTheSchemaIsGiven(string $xml): void
    {
        $directory = getenv('FACTURX_XSD_DIR');
        if (!\is_string($directory) || '' === $directory) {
            return;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $valid = $document->schemaValidate(rtrim($directory, '/').'/Factur-X_EN16931.xsd');
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertSame([true, []], [$valid, $errors]);
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        foreach (['rsm' => 'CrossIndustryInvoice', 'ram' => 'ReusableAggregateBusinessInformationEntity', 'udt' => 'UnqualifiedDataType', 'qdt' => 'QualifiedDataType'] as $prefix => $name) {
            $xpath->registerNamespace($prefix, "urn:un:unece:uncefact:data:standard:$name:100");
        }

        return $xpath;
    }

    /** @return list<string> */
    private static function strings(\DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        $values = [];
        foreach ($nodes as $node) {
            $values[] = (string) $node->textContent;
        }

        return $values;
    }

    /** @param array<string, mixed> $invoice */
    private function issued(array $invoice): string
    {
        $this->postJson($this->path(), $this->invoice($invoice));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function invoice(array $changes): array
    {
        return [...['customerId' => $this->customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => 30, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => [], 'lines' => []], ...$changes];
    }

    /** @return array<string, mixed> */
    private function line(string $description, string $quantity, string $unit, string $price, string $tax, ?string $discountRate = null): array
    {
        $unitEntity = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($unit, $this->company->getId());
        $taxEntity = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($tax, $this->company->getId());
        self::assertNotNull($unitEntity);
        self::assertNotNull($taxEntity);

        return ['description' => $description, 'quantity' => $quantity, 'unitId' => $unitEntity->getId()->toRfc4122(), 'unitPriceNet' => $price, 'discountRate' => $discountRate, 'taxComponentIds' => [$taxEntity->getId()->toRfc4122()]];
    }

    private function frenchCompany(string $name): Company
    {
        $company = new Company($name, 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->em()->persist($company);
        $this->em()->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();

        return $company;
    }

    /** @param array<string, string> $identifiers */
    private function customer(Company $company, string $regime, array $identifiers): Customer
    {
        $taxRegime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('FR', $regime);
        self::assertNotNull($taxRegime);
        $profile = new CustomerProfile(CustomerKind::Company, 'Garage Martin', 'Garage Martin SAS', $identifiers, billingAddress: new PostalAddress('3 avenue Foch', null, '75016', 'Paris', 'FR'));
        $customer = Customer::create($company, 'CLI-0001', $profile, null, $taxRegime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    /** The company as the database holds it now: the test client's requests reboot the kernel and its entity manager. */
    private function reloaded(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function signedIn(): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
