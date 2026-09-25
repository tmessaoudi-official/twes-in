<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Application\TaxComponent\ManageTaxComponents;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\ModuleRegistry\Domain\ModuleState;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Premiers pas » (docs/SPEC.md § 7, 2026-09-25 22:17, row 139; design direction § 4.2): what a new company still has
 * to set up, in the approved order — its profile and registration number, its taxes, a first customer, a first
 * product, its colour, a second member. Each step is worked out from what is there on every read, never ticked by
 * hand, and shown only to a member who may do it, while its module is on.
 */
final class FirstStepsTest extends ApiTestCase
{
    private const array ALL = ['company.profile', 'fiscal.taxes', 'customers.first', 'products.first', 'company.brand', 'company.members'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testANewCompanyHasEveryStepLeftInTheApprovedOrderAndEachIsDoneByWhatIsThere(): void
    {
        $this->signedIn(['*']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertSame(self::ALL, array_column($this->steps(), 'key'));
        self::assertSame([], $this->done(), 'provisioning the preset\'s taxes is not the owner looking at them');
        self::assertSame(6, $this->json()['remaining'] ?? null);

        $company = $this->managed();
        $company->reviseProfile(new CompanyProfile('Quincaillerie SARL', 'SARL', ['matricule_fiscal' => '1234567A/B/M/000'], '12 rue de Rome', null, '1001', 'Tunis'));
        $this->em()->flush();
        $this->getJson($this->path());
        self::assertSame(['company.profile'], $this->done());
        $company = $this->managed();

        $audit = 'INSERT INTO audit_log (id, company_id, entity_type, entity_id, action, actor_user_id, changes, at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())';
        $this->em()->getConnection()->executeStatement($audit, [Uuid::v7()->toRfc4122(), $company->getId()->toRfc4122(), ManageTaxComponents::ENTITY_TYPE, Uuid::v7()->toRfc4122(), 'created', null, '{}']);
        $this->getJson($this->path());
        self::assertSame(['company.profile'], $this->done(), 'a tax the system wrote, with no author, is nobody looking at the taxes');
        $company = $this->managed();
        $this->em()->getConnection()->executeStatement($audit, [Uuid::v7()->toRfc4122(), $company->getId()->toRfc4122(), ManageTaxComponents::ENTITY_TYPE, Uuid::v7()->toRfc4122(), 'revised', $this->ownerId(), '{}']);
        $now = new \DateTimeImmutable();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $this->em()->persist(Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $now));
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($piece);
        $this->em()->persist(Product::create($company, 'VIS', new ProductDetails('Vis 6x40', null, ProductKind::Goods, '10'), $piece, null, [], $now));
        $this->em()->persist(new Setting(SettingAddress::company($company), 'presentation.accent', '#0f766e', $now));
        $this->em()->flush();
        $this->getJson($this->path());
        self::assertSame(['company.profile', 'fiscal.taxes', 'customers.first', 'products.first', 'company.brand'], $this->done());
        self::assertSame(1, $this->json()['remaining'] ?? null);

        $this->createUser('second@twes.local', 'password-1234', $this->managed(), ['company.read'], 'clerk');
        $this->getJson($this->path());
        self::assertSame(self::ALL, $this->done());
        self::assertSame(0, $this->json()['remaining'] ?? null);
    }

    public function testAPendingInvitationCountsAsInvitingAMemberAndAnExpiredOneDoesNot(): void
    {
        $this->signedIn(['*']);
        $insert = 'INSERT INTO invitation (id, email, role_name, token_hash, created_at, expires_at, accepted_at, company_id) VALUES (?, ?, ?, ?, NOW(), NOW() + ?::interval, NULL, ?)';
        $this->em()->getConnection()->executeStatement($insert, [Uuid::v7()->toRfc4122(), 'late@twes.local', 'member', str_repeat('a', 64), '-1 day', $this->company->getId()->toRfc4122()]);
        $this->getJson($this->path());
        self::assertNotContains('company.members', $this->done(), 'an invitation nobody can accept any more');

        $this->em()->getConnection()->executeStatement($insert, [Uuid::v7()->toRfc4122(), 'soon@twes.local', 'member', str_repeat('b', 64), '7 days', $this->company->getId()->toRfc4122()]);
        $this->getJson($this->path());
        self::assertContains('company.members', $this->done());
    }

    public function testEachStepIsShownToWhoMayDoItWhileItsModuleIsOn(): void
    {
        $this->signedIn(['company.read', 'customer.write', 'product.read'], 'clerk@twes.local', 'clerk');
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertSame(['customers.first'], array_column($this->steps(), 'key'), 'reading products is not creating one');
        $this->sendJson('POST', '/api/auth/logout');

        $this->signedIn(['*']);
        $this->em()->persist(ModuleState::of($this->managed(), 'customers', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->getJson($this->path());
        self::assertNotContains('customers.first', array_column($this->steps(), 'key'), 'customers switched off');
        $this->sendJson('POST', '/api/auth/logout');

        $this->signedIn(['customer.write'], 'outsider@twes.local', 'outsider');
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the list is read with company.read');
    }

    /** @return list<array<mixed, mixed>> */
    private function steps(): array
    {
        $steps = $this->json()['steps'] ?? null;
        self::assertIsArray($steps);

        return array_values(array_filter($steps, 'is_array'));
    }

    /** @return list<string> */
    private function done(): array
    {
        return array_values(array_map(
            static fn (array $step): string => \is_string($step['key']) ? $step['key'] : '',
            array_filter($this->steps(), static fn (array $step): bool => true === $step['done']),
        ));
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, string $email = 'owner@twes.local', string $role = 'starter'): void
    {
        $this->createUser($email, 'password-1234', $this->managed(), $permissions, $role);
        $this->login($email, 'password-1234');
    }

    private function ownerId(): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM "user" WHERE email = ?', ['owner@twes.local']);
        self::assertIsString($id);

        return $id;
    }

    /** The company as the entity manager knows it now: the test client reboots the kernel between requests. */
    private function managed(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/first-steps';
    }
}
