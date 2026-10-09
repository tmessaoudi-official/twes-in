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
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * What France's law adds to an invoice from 1 September 2026 (CGI ann. II art. 242 nonies A I 8° bis and 11° bis,
 * docs/fiscal/FR.md § 4a): the category of its operations, chosen on the draft or worked out at issue from its lines'
 * products, and, when the company opted to pay VAT on the débits and the operations include services, the mention
 * « Option pour le paiement de la taxe d'après les débits ». Both are fixed at issue; a Tunisian invoice has neither.
 */
final class FrenchInvoiceFieldsTest extends ApiTestCase
{
    private const string MENTION = 'Option pour le paiement de la taxe d’après les débits';
    private const array WRITER = ['company.read', 'company.settings', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'quote.read', 'quote.write'];

    private Company $company;
    private Customer $customer;
    private string $chair;
    private string $fitting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->em()->persist($this->company);
        $this->em()->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Atelier Durand SARL', identifiers: ['siren' => '732829320', 'siret' => '73282932000074'], addressLine1: '12 rue des Forges', postalCode: '69007', city: 'Lyon'));
        $this->em()->flush();
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($this->company), 'document.late_payment_rate', SettingLevel::Company, 'trois fois le taux d’intérêt légal', null);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('FR', 'standard');
        self::assertNotNull($regime);
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Garage Martin', identifiers: ['siren' => '542065479'], billingAddress: new PostalAddress('3 avenue Foch', null, '75016', 'Paris', 'FR')), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($this->customer);
        $chair = Product::create($this->company, 'MEU-CHAISE', new ProductDetails('Chaise bistrot', null, ProductKind::Goods, '129.00'), $this->unit('C62'), null, [$this->taxId('TVA20')], new \DateTimeImmutable());
        $fitting = Product::create($this->company, 'PRS-POSE', new ProductDetails('Pose et installation', null, ProductKind::Service, '55.00'), $this->unit('HUR'), null, [$this->taxId('TVA20')], new \DateTimeImmutable());
        $this->em()->persist($chair);
        $this->em()->persist($fitting);
        $this->em()->flush();
        $this->chair = $chair->getId()->toRfc4122();
        $this->fitting = $fitting->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, self::WRITER, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testAFrenchCompanyMayOptForTheDebitsAndItsProfileSaysSo(): void
    {
        $this->getJson($this->companyPath().'/profile');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['offersVatOnDebits'], 'France offers the option');
        self::assertFalse($this->json()['vatOnDebits'], 'off until the company opts');

        $this->optForTheDebits(true);

        self::assertTrue($this->json()['vatOnDebits']);
        $fields = $this->em()->getConnection()->fetchOne("SELECT changes->>'fields' FROM audit_log WHERE action = 'company.profile_revised' ORDER BY at DESC LIMIT 1");
        self::assertSame('["vatOnDebits"]', $fields, 'audited by its name, as every profile field');
    }

    public function testTheInvoiceOptionsSayAFrenchInvoiceStatesItsOperations(): void
    {
        $this->getJson($this->companyPath().'/invoice-options');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['operationCategory']);
    }

    public function testACategoryIsChosenOnTheDraftAndKeptAsChosen(): void
    {
        $id = $this->draft([$this->freeLine()], 'services');

        $invoice = $this->invoice($id);
        self::assertSame('services', $invoice['operationCategory']);

        $this->sendJson('PUT', $this->invoicePath($id), [...$this->editable($invoice), 'operationCategory' => 'everything']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('services', $this->invoice($id)['operationCategory'], 'a refused value changes nothing');
    }

    public function testAFreeLineWithNoCategoryRefusesTheIssueBeforeANumberIsTaken(): void
    {
        $id = $this->draft([$this->line($this->chair), $this->freeLine()]);

        $this->postJson($this->invoicePath($id).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('operationCategory: ', $this->stringAt($this->json(), 'detail'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE number IS NOT NULL'), 'a refused document takes no number');

        $this->sendJson('PUT', $this->invoicePath($id), [...$this->editable($this->invoice($id)), 'operationCategory' => 'both']);
        self::assertResponseIsSuccessful();
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();
        self::assertSame('both', $this->invoice($id)['operationCategory']);
    }

    public function testIssuingWorksTheCategoryOutFromTheProductsAndPrintsTheDebitsMentionForServices(): void
    {
        $this->optForTheDebits(true);
        $id = $this->draft([$this->line($this->chair), $this->line($this->fitting)]);
        self::assertNull($this->invoice($id)['operationCategory'] ?? null, 'a draft left to its lines says no category');

        $this->client->request('GET', $this->invoicePath($id).'/pdf');
        $draft = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Livraisons de biens et prestations de services', $draft, 'a draft prints what its lines say');
        self::assertStringContainsString(self::MENTION, $draft);

        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();
        $issued = $this->invoice($id);
        self::assertSame('both', $issued['operationCategory']);
        self::assertTrue($issued['vatOnDebits']);
        self::assertContains('fiscal.mention.fr.vat_on_debits', $this->arrayAt($issued, 'mentions'));

        $this->optForTheDebits(false);
        $this->client->request('GET', $this->invoicePath($id).'/pdf/current');
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Nature des opérations', $pdf);
        self::assertStringContainsString('Livraisons de biens et prestations de services', $pdf);
        self::assertStringContainsString(self::MENTION, $pdf, 'an issued invoice keeps what was true when it was issued');
        self::assertTrue($this->invoice($id)['vatOnDebits']);
    }

    public function testTheQuestionBeforeIssuingSaysWhatTheOperationsWillBe(): void
    {
        $id = $this->draft([$this->line($this->chair), $this->line($this->fitting)]);
        $this->getJson($this->invoicePath($id).'/next-number');
        self::assertResponseIsSuccessful();
        self::assertSame('both', $this->json()['operationCategory'], 'worked out from its lines, said and never stored');
        self::assertNull($this->invoice($id)['operationCategory'] ?? null);

        $free = $this->draft([$this->freeLine()]);
        $this->getJson($this->invoicePath($free).'/next-number');
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['operationCategory'] ?? null, 'nothing to say yet: issuing will ask');
    }

    public function testGoodsAloneAreDeliveriesAndPrintNoDebitsMention(): void
    {
        $this->optForTheDebits(true);
        $id = $this->draft([$this->line($this->chair)]);

        $this->postJson($this->invoicePath($id).'/issue', null);

        self::assertResponseIsSuccessful();
        $issued = $this->invoice($id);
        self::assertSame('goods', $issued['operationCategory']);
        self::assertNotContains('fiscal.mention.fr.vat_on_debits', $this->arrayAt($issued, 'mentions'), 'the option concerns services, not goods');
        $this->client->request('GET', $this->invoicePath($id).'/pdf/current');
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Livraisons de biens', $pdf);
        self::assertStringNotContainsString(self::MENTION, $pdf);
    }

    public function testServicesOfACompanyThatDidNotOptPrintNoDebitsMention(): void
    {
        $id = $this->draft([$this->line($this->fitting)]);

        $this->postJson($this->invoicePath($id).'/issue', null);

        self::assertResponseIsSuccessful();
        $issued = $this->invoice($id);
        self::assertSame('services', $issued['operationCategory']);
        self::assertFalse($issued['vatOnDebits']);
        self::assertNotContains('fiscal.mention.fr.vat_on_debits', $this->arrayAt($issued, 'mentions'));
    }

    public function testACreditNoteStatesTheOperationsOfTheInvoiceItCorrects(): void
    {
        $id = $this->draft([$this->line($this->chair), $this->freeLine()], 'both');
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->invoicePath($id).'/credit-notes', ['creditNoteReason' => 'Chaise rendue']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $creditId = $this->stringAt($this->json(), 'id');
        $credit = $this->invoice($creditId);
        $this->sendJson('PUT', $this->invoicePath($creditId), [...$this->editable($credit), 'operationCategory' => 'goods', 'lines' => [$this->line($this->chair)]]);
        self::assertResponseIsSuccessful();
        $this->postJson($this->invoicePath($creditId).'/issue', null);

        self::assertResponseIsSuccessful();
        self::assertSame('both', $this->invoice($creditId)['operationCategory'], 'a correction concerns the same operations');
    }

    public function testACopyOfAnIssuedInvoiceKeepsWhatItsOperationsWereSaidToBe(): void
    {
        $id = $this->draft([$this->freeLine()], 'services');
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->invoicePath($id).'/duplicate', null);

        self::assertResponseIsSuccessful();
        $copy = $this->stringAt($this->json(), 'id');
        self::assertSame('services', $this->invoice($copy)['operationCategory'], 'the same sale, the same answer, so it issues as the original did');
        $this->postJson($this->invoicePath($copy).'/issue', null);
        self::assertResponseIsSuccessful();
    }

    public function testADepositStatesTheOperationsOfItsQuote(): void
    {
        $this->postJson($this->companyPath().'/quotes', ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'lines' => [$this->line($this->chair), $this->line($this->fitting)]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $quote = $this->companyPath().'/quotes/'.$this->stringAt($this->json(), 'id');
        $this->postJson($quote.'/send', null);
        $this->postJson($quote.'/accept', ['answeredOn' => null]);
        self::assertResponseIsSuccessful();

        $this->postJson($quote.'/deposit-invoices', ['depositPercentage' => '30', 'depositAmount' => null]);
        self::assertResponseIsSuccessful();
        $deposits = $this->arrayAt($this->json(), 'deposits');
        self::assertIsArray($deposits[0]);
        $depositId = $deposits[0]['invoiceId'];
        self::assertIsString($depositId);

        self::assertSame('both', $this->invoice($depositId)['operationCategory'], 'an advance is on the operations of its quote');
        $this->postJson($this->invoicePath($depositId).'/issue', null);
        self::assertResponseIsSuccessful();
        self::assertSame('both', $this->invoice($depositId)['operationCategory']);
    }

    public function testATunisianInvoiceHasNoCategoryAndRefusesOne(): void
    {
        $carthage = $this->createCompany('Carthage');
        static::getContainer()->get(ProvisionCompany::class)->handle($carthage);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($carthage, 'CLI-0001', self::aTunisianBusiness('Sfax Négoce'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->createUser('vente@twes.tn', 'password-1234', $carthage, self::WRITER, 'member');
        $this->login('vente@twes.tn', 'password-1234');
        $path = '/api/companies/'.$carthage->getId()->toRfc4122();
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $carthage->getId());
        self::assertNotNull($unit);
        $body = ['customerId' => $customer->getId()->toRfc4122(), 'establishmentId' => null, 'lines' => [['description' => 'Conseil', 'quantity' => '1', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '100', 'taxComponentIds' => []]]];

        $this->getJson($path.'/profile');
        self::assertFalse($this->json()['offersVatOnDebits']);
        $this->getJson($path.'/invoice-options');
        self::assertFalse($this->json()['operationCategory']);

        $this->postJson($path.'/invoices', [...$body, 'operationCategory' => 'services']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson($path.'/invoices', $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($path.'/invoices/'.$id.'/issue', null);
        self::assertResponseIsSuccessful();
        $this->getJson($path.'/invoices/'.$id);
        $issued = $this->json();
        self::assertNull($issued['operationCategory'] ?? null, 'a free line is no question where the law asks nothing');
        self::assertNull($issued['vatOnDebits'] ?? null);
        self::assertSame([null, null], array_values((array) $this->em()->getConnection()->fetchAssociative('SELECT operation_category, vat_on_debits FROM invoice WHERE id = :id', ['id' => $id])));
    }

    public function testATunisianCompanyMayNotOptForTheDebits(): void
    {
        $carthage = $this->createCompany('Carthage');
        $this->createUser('admin@twes.tn', 'password-1234', $carthage, ['company.read', 'company.settings'], 'admin');
        $this->login('admin@twes.tn', 'password-1234');

        $this->sendJson('PUT', '/api/companies/'.$carthage->getId()->toRfc4122().'/profile', [
            'legalName' => 'Carthage SARL', 'legalForm' => null, 'identifiers' => ['matricule_fiscal' => '1234567A/B/M/000'],
            'addressLine1' => 'Rue de Marseille', 'addressLine2' => null, 'postalCode' => '1000', 'city' => 'Tunis', 'email' => null,
            'phone' => null, 'website' => null, 'iban' => null, 'bic' => null, 'vatRegime' => 'standard', 'vatOnDebits' => true,
            'invoiceFooterText' => null, 'latePenaltyText' => null,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('vatOnDebits: ', $this->stringAt($this->json(), 'detail'));
    }

    public function testAnotherCompanysInvoiceLooksAbsent(): void
    {
        $id = $this->draft([$this->freeLine()], 'services');
        $globex = $this->createCompany('Globex');
        $this->createUser('other@twes.local', 'password-1234', $globex, self::WRITER, 'member');
        $this->login('other@twes.local', 'password-1234');

        $this->sendJson('PUT', '/api/companies/'.$globex->getId()->toRfc4122().'/invoices/'.$id, ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'operationCategory' => 'goods', 'lines' => [$this->freeLine()]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAReaderMayNotChooseTheCategory(): void
    {
        $id = $this->draft([$this->freeLine()], 'services');
        $invoice = $this->invoice($id);
        // The test client's requests reboot the kernel: the company is the one its entity manager holds now.
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['invoice.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');

        $this->sendJson('PUT', $this->invoicePath($id), [...$this->editable($invoice), 'operationCategory' => 'goods']);

        // Refused as everything a role does not allow is here: as if the write did not exist.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('services', $this->invoice($id)['operationCategory']);
    }

    private function optForTheDebits(bool $opted): void
    {
        $this->getJson($this->companyPath().'/profile');
        $profile = array_intersect_key($this->json(), array_flip(['legalName', 'legalForm', 'identifiers', 'addressLine1', 'addressLine2', 'postalCode', 'city', 'email', 'phone', 'website', 'iban', 'bic', 'vatRegime', 'invoiceFooterText', 'latePenaltyText']));
        $this->sendJson('PUT', $this->companyPath().'/profile', [...$profile, 'vatOnDebits' => $opted]);
        self::assertResponseIsSuccessful();
    }

    /** @param list<array<string, mixed>> $lines */
    private function draft(array $lines, ?string $category = null): string
    {
        $this->postJson($this->invoicePath(), ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'operationCategory' => $category, 'lines' => $lines]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @return array<string, mixed> */
    private function line(string $productId): array
    {
        return ['productId' => $productId, 'quantity' => '1'];
    }

    /** @return array<string, mixed> */
    private function freeLine(): array
    {
        return ['description' => 'Réglage sur place', 'quantity' => '1', 'unitId' => $this->unit('C62')->getId()->toRfc4122(), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA20')->toRfc4122()]];
    }

    /** @return array<string, mixed> */
    private function invoice(string $id): array
    {
        $this->getJson($this->invoicePath($id));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * What a revision sends back of a read document, its lines as written.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function editable(array $body): array
    {
        $kept = array_intersect_key($body, array_flip(['customerId', 'establishmentId', 'supplyDate', 'paymentTermsDays', 'customerReference', 'notesPrinted', 'notesInternal', 'discountAmount', 'documentTaxComponentIds', 'operationCategory']));
        $lines = [];
        foreach ($this->arrayAt($body, 'lines') as $line) {
            self::assertIsArray($line);
            $lines[] = array_intersect_key($line, array_flip(['productId', 'description', 'quantity', 'unitId', 'unitPriceNet', 'discountRate', 'discountAmount', 'taxComponentIds']));
        }

        return [...$kept, 'lines' => $lines];
    }

    private function unit(string $code): \App\Fiscal\Domain\Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function taxId(string $code): \Symfony\Component\Uid\Uuid
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function invoicePath(?string $id = null): string
    {
        return $this->companyPath().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
