<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Application\PasswordHasher;
use App\Tenancy\Application\Seed\OperatorPasswordRequired;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Seed\SeedRequest;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryRoles;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SeedPlatformTest extends TestCase
{
    private InMemoryRoles $roles;
    private InMemoryUsers $users;
    private InMemoryCompanies $companies;
    private InMemoryMemberships $memberships;
    private SeedPlatform $seed;

    protected function setUp(): void
    {
        $this->roles = new InMemoryRoles();
        $this->users = new InMemoryUsers();
        $this->companies = new InMemoryCompanies();
        $this->memberships = new InMemoryMemberships();
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }
        };
        $this->seed = new SeedPlatform($this->roles, $this->users, $this->companies, $this->memberships, $hasher, new MockClock('2026-09-09 12:00:00'));
    }

    public function testItCreatesTheRolesTheOperatorTheCompanyAndTheOwnership(): void
    {
        $created = $this->seed->seed($this->request(password: 'secret'));

        self::assertSame(['role owner', 'role admin', 'role member', 'operator op@example.test', 'company Seeded', 'membership op@example.test owns Seeded'], $created);
        self::assertCount(3, $this->roles->roles);
        self::assertSame(['*'], $this->roles->builtIn(Role::OWNER)?->getPermissions());
        $operator = $this->users->ofEmail(\App\Identity\Domain\Email::fromString('op@example.test'));
        self::assertNotNull($operator);
        self::assertTrue($operator->isPlatformOperator());
        self::assertSame('hashed:secret', $operator->getPasswordHash());
        $company = $this->companies->ofName('Seeded');
        self::assertNotNull($company);
        self::assertSame('owner', $this->memberships->ofUserInCompany($operator->getId(), $company->getId())?->getRole()->getName());
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
    }

    private function request(?string $password): SeedRequest
    {
        return new SeedRequest('op@example.test', $password, 'Op', 'Seeded', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
