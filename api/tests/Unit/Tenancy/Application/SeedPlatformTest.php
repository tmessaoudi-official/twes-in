<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Identity\Application\PasswordHasher;
use App\Tenancy\Application\Seed\OperatorPasswordRequired;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Seed\SeedRequest;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryCustomerTaxRegimes;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryRoles;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SeedPlatformTest extends TestCase
{
    private InMemoryRoles $roles;
    private InMemoryUsers $users;
    private InMemoryCompanies $companies;
    private InMemoryMemberships $memberships;
    private InMemoryTaxComponents $components;
    private InMemoryCustomerTaxRegimes $regimes;
    private SeedPlatform $seed;

    protected function setUp(): void
    {
        $this->roles = new InMemoryRoles();
        $this->users = new InMemoryUsers();
        $this->companies = new InMemoryCompanies();
        $this->memberships = new InMemoryMemberships();
        $this->components = new InMemoryTaxComponents();
        $this->regimes = new InMemoryCustomerTaxRegimes();
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }
        };
        $clock = new MockClock('2026-09-09 12:00:00');
        $presets = ShippedFiscalPresets::presets();
        $this->seed = new SeedPlatform(
            $this->roles,
            $this->users,
            $this->companies,
            $this->memberships,
            $hasher,
            new SyncCustomerTaxRegimes($presets, $this->regimes, $clock),
            new ProvisionCompany($presets, $this->components, new InMemoryUnits(), ShippedFiscalPresets::scales(), $clock),
            $clock,
        );
    }

    public function testARoleWhosePermissionsChangedIsBroughtUpToDate(): void
    {
        // A database seeded by an earlier release carries an older permission set; the next release adds one.
        $this->roles->save(new Role(Role::ADMIN, ['company.read'], null, new \DateTimeImmutable()));

        $created = $this->seed->seed($this->request(password: 'secret'));

        self::assertSame(SeedPlatform::BUILT_IN_ROLES[Role::ADMIN], $this->roles->builtIn(Role::ADMIN)?->getPermissions());
        self::assertContains('role admin updated', $created);
    }

    public function testTheBuiltInRolesReachTheFiscalSetup(): void
    {
        self::assertContains('fiscal.write', SeedPlatform::BUILT_IN_ROLES[Role::ADMIN]);
        self::assertContains('fiscal.read', SeedPlatform::BUILT_IN_ROLES[Role::MEMBER]);
        self::assertNotContains('fiscal.write', SeedPlatform::BUILT_IN_ROLES[Role::MEMBER]);
    }

    public function testARoleThatAlreadyMatchesIsLeftAlone(): void
    {
        $this->seed->seed($this->request(password: 'secret'));

        $created = $this->seed->seed($this->request(password: 'secret'));

        self::assertSame([], $created);
        self::assertCount(3, $this->roles->roles);
    }

    public function testACustomCompanyRoleWithABuiltInNameIsNotTouched(): void
    {
        $company = new \App\Tenancy\Domain\Company('Other', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $custom = new Role(Role::ADMIN, ['company.read'], $company, new \DateTimeImmutable());
        $this->roles->save($custom);

        $this->seed->seed($this->request(password: 'secret'));

        self::assertSame(['company.read'], $custom->getPermissions());
    }

    public function testItCreatesTheRolesTheOperatorTheCompanyTheOwnershipAndTheFiscalRows(): void
    {
        $created = $this->seed->seed($this->request(password: 'secret'));

        self::assertSame([
            'role owner', 'role admin', 'role member', 'operator op@example.test', 'company Seeded', 'membership op@example.test owns Seeded',
            'customer tax regimes of FR', 'customer tax regimes of TN', 'tax components of Seeded', 'units of Seeded',
        ], $created);
        self::assertCount(3, $this->roles->roles);
        self::assertSame(['*'], $this->roles->builtIn(Role::OWNER)?->getPermissions());
        $operator = $this->users->ofEmail(\App\Identity\Domain\Email::fromString('op@example.test'));
        self::assertNotNull($operator);
        self::assertTrue($operator->isPlatformOperator());
        self::assertSame('hashed:secret', $operator->getPasswordHash());
        $company = $this->companies->ofName('Seeded');
        self::assertNotNull($company);
        self::assertSame('owner', $this->memberships->ofUserInCompany($operator->getId(), $company->getId())?->getRole()->getName());
        self::assertCount(6, $this->components->ofCompany($company->getId()));
        self::assertCount(8, $this->regimes->regimes);
    }

    public function testEveryCompanyCreatedBeforeTheFiscalPresetsGetsItsTaxesAndUnitsOnTheNextRun(): void
    {
        // The migration records a preset for every existing company but cannot read the preset files.
        $other = new \App\Tenancy\Domain\Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->companies->save($other);

        $created = $this->seed->seed($this->request(password: 'secret'));

        self::assertContains('tax components of Globex', $created);
        self::assertContains('units of Globex', $created);
        self::assertCount(6, $this->components->ofCompany($other->getId()));
        self::assertSame([], $this->seed->seed($this->request(password: null)));
    }

    public function testACompanySeededBeforeTheFiscalPresetsGetsItsTaxesOnTheNextRun(): void
    {
        $this->companies->save(new \App\Tenancy\Domain\Company('Seeded', 'TN', 'TND', 'fr', 'Africa/Tunis'));

        $created = $this->seed->seed($this->request(password: 'secret'));

        self::assertContains('tax components of Seeded', $created);
        self::assertNotContains('company Seeded', $created);
    }

    public function testASecondRunConverges(): void
    {
        $this->seed->seed($this->request(password: 'secret'));

        self::assertSame([], $this->seed->seed($this->request(password: null)));
        self::assertCount(3, $this->roles->roles);
        self::assertCount(1, $this->companies->companies);
    }

    public function testCreatingTheOperatorWithoutAPasswordIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->seed->seed($this->request(password: null));
            self::fail('expected OperatorPasswordRequired');
        } catch (OperatorPasswordRequired) {
        }

        self::assertNull($this->users->ofEmail(\App\Identity\Domain\Email::fromString('op@example.test')));
        self::assertCount(0, $this->companies->companies);
        self::assertSame([], $this->regimes->regimes);
    }

    private function request(?string $password): SeedRequest
    {
        return new SeedRequest('op@example.test', $password, 'Op', 'Seeded', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
