<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

final class CustomersTest extends ApiTestCase
{
    private const string MATRICULE = '1234567A/B/M/000';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheOptionsSayWhatTheCustomerFormAsksFor(): void
    {
        $this->signedIn(['customer.read']);

        $this->getJson($this->companyPath().'/customer-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame('TN', $options['countryCode']);
        $identifier = $this->arrayAt($options, 'identifiers')[0];
        self::assertIsArray($identifier);
        self::assertSame(['matricule_fiscal', '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$', true], [$identifier['key'], $identifier['pattern'], $identifier['requiredForBusiness']]);
        self::assertSame(['standard', 'exempt', 'suspended', 'export'], array_column($this->arrayAt($options, 'regimes'), 'code'));
        self::assertContains('TVA19', array_column($this->arrayAt($options, 'taxes'), 'code'));
    }

    public function testAWriterAddsATunisianBusinessCustomer(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), $this->customer(['defaultTaxComponentIds' => [$this->taxId('TVA19')], 'defaultDiscountRate' => '5']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('CLI-0001', $created['number']);
        self::assertSame(['matricule_fiscal' => self::MATRICULE], $created['identifiers']);
        self::assertSame('TN', $created['billingCountryCode'], 'an address without a country is in the company’s');
        self::assertSame('5.000', $created['defaultDiscountRate']);
        self::assertSame([$this->taxId('TVA19')], $created['defaultTaxComponentIds']);
        self::assertTrue($created['isActive']);

        $this->getJson($this->path($this->stringAt($created, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('Carthage Conseil', $this->json()['name']);
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'customer.created'"));
    }

    public function testWhatThePresetOrTheCompanyRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        foreach ([
            'identifiers.matricule_fiscal' => ['identifiers' => []],
            'taxRegime' => ['taxRegime' => 'franchise'],
            'kind' => ['kind' => 'robot'],
            'billingCountryCode' => ['billingCountryCode' => 'XX'],
            'defaultTaxComponentIds' => ['taxRegime' => 'exempt', 'defaultTaxComponentIds' => [$this->taxId('TVA19')]],
            'customerGroupId' => ['customerGroupId' => '0192c3a4-0000-7000-8000-000000000000'],
        ] as $field => $change) {
            $this->postJson($this->path(), $this->customer($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }

        $this->postJson($this->path(), $this->customer(['defaultTaxComponentIds' => ['first' => $this->taxId('TVA19')]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the default taxes are a list, never a map');
        self::assertStringContainsString('defaultTaxComponentIds', (string) $this->client->getResponse()->getContent());
    }

    public function testANumberAnotherCustomerHasAnswersConflict(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());

        $this->postJson($this->path(), $this->customer());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChanged(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->customer(['email' => 'compta@carthage.tn', 'isActive' => false, 'shippingCity' => 'Sfax', 'shippingCountryCode' => 'TN']));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['isActive']);
        self::assertSame('Sfax', $this->json()['shippingCity']);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'customer.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['email', 'shippingAddress', 'isActive']], json_decode($changes, true));
    }

    public function testAReaderOnlyReadsAndAnotherCompanysCustomerIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        // Both people exist before the first request: the client reboots the kernel, which detaches the company.
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['customer.read'], 'reader');
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $mine = $this->stringAt($this->json(), 'id');

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->customer());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->customer(['number' => 'CLI-0002']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());

        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/customers');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('not-a-uuid'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysCustomerIsNotFoundThroughThisCompany(): void
    {
        $globex = $this->createCompany('Globex');
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $theirs = Customer::create($globex, 'CLI-0001', new CustomerProfile(CustomerKind::Individual, 'Amel'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path($theirs->getId()->toRfc4122()), $this->customer(['kind' => 'individual', 'identifiers' => []]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListIsOnePageAtATimeWithTheTotal(): void
    {
        $this->seedCustomers(105);
        $this->signedIn(['customer.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertSame(['CLI-0001', 'CLI-0025'], [$this->jsonList()[0]['number'], $this->jsonList()[24]['number']]);
        self::assertCount(25, $this->jsonList(), 'a page holds 25 rows unless asked otherwise');
        $page = $this->jsonPage();
        self::assertSame(105, $page['totalItems']);
        self::assertStringContainsString('page=2', $this->stringAt($this->section($page, 'view'), 'next'));

        $this->getJson($this->path().'?page=5');
        self::assertSame(['CLI-0101', 'CLI-0105'], [$this->jsonList()[0]['number'], $this->jsonList()[4]['number']]);

        $this->getJson($this->path().'?itemsPerPage=50&page=3');
        self::assertCount(5, $this->jsonList());
        $this->getJson($this->path().'?itemsPerPage=1000');
        self::assertCount(100, $this->jsonList(), 'a page never holds more than 100 rows');

        $id = $this->stringAt($this->jsonList()[0], 'id');
        $this->client->request('GET', $this->path($id), [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_ACCEPTABLE, 'a single record is plain JSON only');
        $this->getJson($this->path($id));
        self::assertResponseHeaderSame('Content-Type', 'application/json; charset=utf-8');
        $this->client->request('GET', '/api/docs.jsonld');
        self::assertResponseIsSuccessful('the documentation every Hydra answer links to exists');
    }

    public function testTheListIsSearchedNarrowedAndSortedByTheApi(): void
    {
        $retail = CustomerGroup::create($this->company, 'Retail', null, new \DateTimeImmutable());
        $this->em()->persist($retail);
        $regime = $this->standardRegime();
        $now = new \DateTimeImmutable();
        $this->em()->persist(Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthagé Conseil', identifiers: ['matricule_fiscal' => self::MATRICULE], email: 'contact@carthage.tn', billingAddress: new PostalAddress('12, rue du Lac Léman', null, '1053', 'Sfax')), $retail, $regime, [], $now));
        $this->em()->persist(Customer::create($this->company, 'K9', new CustomerProfile(CustomerKind::Individual, 'Kais'), null, $regime, [], $now));
        $this->em()->persist(Customer::create($this->company, 'CLI-0002', new CustomerProfile(CustomerKind::Individual, 'Amel Ben Salah', billingAddress: new PostalAddress(city: 'Tunis')), null, $regime, [], $now));
        $retired = Customer::create($this->company, 'CLI-0003', new CustomerProfile(CustomerKind::Company, 'Zitouna Négoce', legalName: 'Société Zitouna'), $retail, $regime, [], $now);
        $retired->revise('CLI-0003', $retired->getProfile(), $retail, $regime, [], false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();
        $retailId = $retail->getId()->toRfc4122();
        $this->signedIn(['customer.read']);

        foreach ([
            'q=CARTHAGE' => ['CLI-0001'],
            'q=carthage.tn' => ['CLI-0001'],
            'q=cli-0002' => ['CLI-0002'],
            'q=societe' => ['CLI-0003'],
            'q=tunis' => ['CLI-0002'],
            'q=lac leman' => ['CLI-0001'],
            'q=1053' => ['CLI-0001'],
            'q=1234567a' => ['CLI-0001'],
            'q=matricule' => [],
            'q=ca' => [],
            'q=k9' => ['K9'],
            'order[city]=asc&isActive=true' => ['CLI-0001', 'CLI-0002', 'K9'],
            'order[city]=desc&isActive=true' => ['CLI-0002', 'CLI-0001', 'K9'],
            'order[customerGroup]=desc' => ['CLI-0001', 'CLI-0003', 'CLI-0002', 'K9'],
            'q=nobody' => [],
            'kind=individual' => ['CLI-0002', 'K9'],
            'isActive=false' => ['CLI-0003'],
            'customerGroupId='.$retailId => ['CLI-0001', 'CLI-0003'],
            'customerGroupId='.$retailId.'&isActive=true' => ['CLI-0001'],
            'order[name]=desc' => ['CLI-0003', 'K9', 'CLI-0001', 'CLI-0002'],
            'order[number]=desc' => ['K9', 'CLI-0003', 'CLI-0002', 'CLI-0001'],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($numbers, array_column($this->jsonList(), 'number'), $query);
            self::assertSame(\count($numbers), $this->jsonPage()['totalItems'], $query);
        }

        $this->getJson($this->path().'?kind=robot');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function seedCustomers(int $count): void
    {
        $regime = $this->standardRegime();
        $now = new \DateTimeImmutable();
        foreach (range(1, $count) as $n) {
            $this->em()->persist(Customer::create($this->company, \sprintf('CLI-%04d', $n), new CustomerProfile(CustomerKind::Individual, 'Client '.$n), null, $regime, [], $now));
        }
        $this->em()->flush();
    }

    private function standardRegime(): CustomerTaxRegime
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);

        return $regime;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function customer(array $changes = []): array
    {
        return [...[
            'number' => 'CLI-0001',
            'kind' => 'company',
            'name' => 'Carthage Conseil',
            'identifiers' => ['matricule_fiscal' => self::MATRICULE],
            'taxRegime' => 'standard',
            'billingAddressLine1' => '12, rue du Lac Léman',
            'billingCity' => 'Tunis',
            'defaultTaxComponentIds' => [],
            'isActive' => true,
        ], ...$changes];
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $customerId = null): string
    {
        return $this->companyPath().'/customers'.(null === $customerId ? '' : '/'.$customerId);
    }
}
