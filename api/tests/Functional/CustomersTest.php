<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\TaxComponentRepository;
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
        $regime = static::getContainer()->get(\App\Fiscal\Domain\CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $theirs = \App\Module\Customers\Domain\Customer::create($globex, 'CLI-0001', new \App\Module\Customers\Domain\CustomerProfile(\App\Module\Customers\Domain\CustomerKind::Individual, 'Amel'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path($theirs->getId()->toRfc4122()), $this->customer(['kind' => 'individual', 'identifiers' => []]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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
