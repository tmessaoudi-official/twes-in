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
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invoice names both its parties as the law asks before it takes a number (Code de la TVA art. 18-II, CGI annexe II
 * art. 242 nonies A): a refused invoice stays a draft, and the series gives its number to the next one issued.
 */
final class IssueIdentityTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue'], 'member');
        $this->login('sales@twes.local', 'password-1234');
    }

    public function testACompanyWithoutItsLegalIdentityIssuesNothingAndLosesNoNumber(): void
    {
        $id = $this->draft($this->customer(new CustomerProfile(CustomerKind::Individual, 'Sami Ben Ali', billingAddress: self::tunis())));
        $this->getJson($this->path($id).'/next-number');
        $told = $this->stringAt($this->json(), 'number');

        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $detail = $this->stringAt($this->json(), 'detail');
        self::assertStringStartsWith('seller_identity:', $detail);
        foreach (['legalName', 'addressLine1', 'city', 'identifiers.matricule_fiscal'] as $field) {
            self::assertStringContainsString($field, $detail);
        }
        $this->getJson($this->path($id));
        self::assertSame('draft', $this->stringAt($this->json(), 'status'));

        $this->nameTheCompany();
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseIsSuccessful();
        self::assertSame($told, $this->stringAt($this->json(), 'number'), 'the refusal took no number from the series');
    }

    public function testACustomerWithoutAnAddressIsRefused(): void
    {
        $this->nameTheCompany();
        $id = $this->draft($this->customer(new CustomerProfile(CustomerKind::Individual, 'Sami Ben Ali')));

        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $detail = $this->stringAt($this->json(), 'detail');
        self::assertStringStartsWith('customer_identity:', $detail);
        self::assertStringContainsString('billingAddressLine1', $detail);
        self::assertStringContainsString('billingCity', $detail);
    }

    public function testABusinessCustomerAtHomeIsRefusedWithoutItsMatriculeAndOneAbroadIsNot(): void
    {
        $this->nameTheCompany();
        $home = $this->draft($this->customer(new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', billingAddress: self::tunis())));
        $abroad = $this->draft($this->customer(new CustomerProfile(CustomerKind::Company, 'Garage Martin', billingAddress: new PostalAddress('3 avenue Foch', null, '75016', 'Paris', 'FR')), 'CLI-0002'));

        $this->postJson($this->path($home).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('customer_identity: An invoice cannot name its customer without identifiers.matricule_fiscal.', $this->stringAt($this->json(), 'detail'));

        $this->postJson($this->path($abroad).'/issue', null);
        self::assertResponseIsSuccessful();
    }

    private function nameTheCompany(): void
    {
        $this->company()->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567A/B/M/000'], addressLine1: 'Rue de Marseille', city: 'Tunis'));
        $this->em()->flush();
    }

    /** The company as the current kernel holds it: the test client boots a new one for every request. */
    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private static function tunis(): PostalAddress
    {
        return new PostalAddress('Rue de Rome', null, '1000', 'Tunis', 'TN');
    }

    /** Saved as it stands, as one saved before its preset required what it lacks would be. */
    private function customer(CustomerProfile $profile, string $number = 'CLI-0001'): string
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company(), $number, $profile, null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer->getId()->toRfc4122();
    }

    private function draft(string $customerId): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path(), [
            'customerId' => $customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => [['description' => 'Conseil', 'quantity' => '1', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '100', 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
