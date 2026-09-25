<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Session\ChooseWorkingCompany;
use App\Tenancy\Application\Session\DescribeWorkingContext;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryCurrentCompany;
use App\Tests\Support\InMemoryMemberships;
use PHPUnit\Framework\TestCase;

final class WorkingCompanyTest extends TestCase
{
    private User $user;
    private InMemoryMemberships $memberships;
    private InMemoryCurrentCompany $current;

    protected function setUp(): void
    {
        $this->user = new User(Email::fromString('u@example.test'), 'U');
        $this->memberships = new InMemoryMemberships();
        $this->current = new InMemoryCurrentCompany();
    }

    public function testOneMembershipBecomesTheSessionCompany(): void
    {
        $company = new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($this->user, $company, new Role(Role::OWNER, ['*'])));

        (new ChooseWorkingCompany($this->memberships, $this->current))->for($this->user->getId());

        self::assertTrue($company->getId()->equals($this->current->id() ?? throw new \LogicException()));
    }

    // docs/SPEC.md § 7, 2026-09-25 09:03: several companies never leave the choice open; the first by name opens.
    public function testNoMembershipLeavesTheChoiceOpenAndSeveralOpenTheFirstByName(): void
    {
        $chooser = new ChooseWorkingCompany($this->memberships, $this->current);
        $chooser->for($this->user->getId());
        self::assertNull($this->current->id());

        $b = new Company('B', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $a = new Company('A', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($this->user, $b, new Role(Role::MEMBER, [])));
        $this->memberships->save(new Membership($this->user, $a, new Role(Role::MEMBER, [])));
        $chooser->for($this->user->getId());
        self::assertTrue($a->getId()->equals($this->current->id()), 'several companies, none used yet: the first by name');
    }

    public function testTheWorkingContextDescribesTheCompanyTheRoleAndThePermissions(): void
    {
        $company = new Company('Demo', 'tn', 'tnd', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($this->user, $company, new Role(Role::ADMIN, ['invoice.read', 'invoice.write'])));
        $this->current->set($company->getId());

        $context = (new DescribeWorkingContext($this->memberships, $this->current))->for($this->user->getId());

        self::assertNotNull($context);
        self::assertSame($company->getId()->toRfc4122(), $context->companyId);
        self::assertSame('Demo', $context->name);
        self::assertSame('TN', $context->countryCode);
        self::assertSame('TND', $context->currency);
        self::assertSame('active', $context->status);
        self::assertSame('admin', $context->role);
        self::assertSame(['invoice.read', 'invoice.write'], $context->permissions);
    }

    public function testWithoutASessionCompanyOrAMembershipThereIsNoContext(): void
    {
        $describe = new DescribeWorkingContext($this->memberships, $this->current);
        self::assertNull($describe->for($this->user->getId()));

        $this->current->set((new Company('Other', 'FR', 'EUR', 'fr', 'Europe/Paris'))->getId());
        self::assertNull($describe->for($this->user->getId()), 'a session company the user is no longer a member of yields nothing');
    }
}
