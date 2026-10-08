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
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * A French invoice prints every mention the law asks of it, filled (docs/SPEC.md § 7, 2026-09-21 18:30): issuing one
 * whose mention states something the company has not given is refused with a 422 led by the setting to give, it takes
 * no number, and once given the value is printed in the mention and kept with the issued document.
 */
final class FrenchMentionsTest extends ApiTestCase
{
    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Atelier Durand SARL', identifiers: ['siren' => '732829320', 'siret' => '73282932000074'], addressLine1: '12 rue des Forges', postalCode: '69007', city: 'Lyon'));
        $this->em()->persist($this->company);
        $this->em()->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('FR', 'exempt');
        self::assertNotNull($regime);
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'École Martin', identifiers: ['siren' => '542065479'], billingAddress: new PostalAddress('3 avenue Foch', null, '75016', 'Paris', 'FR')), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($this->customer);
        $this->em()->flush();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testIssuingIsRefusedNamingEachMissingDatumThenPrintsTheMentionsFilled(): void
    {
        $id = $this->draft();

        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('document.exemption_reference: ', $this->stringAt($this->json(), 'detail'));

        $this->give(SettingLevel::Customer, 'document.exemption_reference', 'article 261-4-4° du CGI');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('document.late_payment_rate: ', $this->stringAt($this->json(), 'detail'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE number IS NOT NULL'), 'a refused document takes no number');

        $this->client->request('GET', $this->path($id).'/pdf');
        $draft = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Exonération de TVA, article 261-4-4° du CGI.', $draft, 'a draft prints what is given');
        self::assertStringNotContainsString('pénalités sont exigibles', $draft, 'and leaves out what is not');

        $this->give(SettingLevel::Company, 'document.late_payment_rate', 'trois fois le taux d’intérêt légal');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseIsSuccessful();
        $this->give(SettingLevel::Company, 'document.late_payment_rate', '15 %');

        $this->client->request('GET', $this->path($id).'/pdf/current');
        $issued = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Exonération de TVA, article 261-4-4° du CGI.', $issued);
        self::assertStringContainsString('En cas de retard de paiement, des pénalités sont exigibles au taux de trois fois le taux d’intérêt légal.', $issued, 'the rate as it was given at issue');
        self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $issued, 'no mention printed half-written');
    }

    private function draft(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA20', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($tax);
        $this->postJson($this->path(), [
            'customerId' => $this->customer->getId()->toRfc4122(),
            'establishmentId' => null,
            'lines' => [['description' => 'Formation', 'quantity' => '1', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '100', 'discountRate' => null, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function give(SettingLevel $level, string $key, string $value): void
    {
        // The test client's requests reboot the kernel: the company is the one its entity manager holds now.
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $context = SettingLevel::Customer === $level ? new SettingContext($company, customerId: $this->customer->getId()) : new SettingContext($company);
        static::getContainer()->get(ChangeSettings::class)->change($context, $key, $level, $value, null);
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
