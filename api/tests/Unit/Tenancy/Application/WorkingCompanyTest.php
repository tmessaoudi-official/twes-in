<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Files\Application\Attachments;
use App\Files\Application\Files;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Application\Session\ChooseWorkingCompany;
use App\Tenancy\Application\Session\DescribeWorkingContext;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAttachments;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCurrentCompany;
use App\Tests\Support\InMemoryFileStorage;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryStoredFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

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

        $logos = $this->logos();
        $context = (new DescribeWorkingContext($this->memberships, $this->current, $logos))->for($this->user->getId());

        self::assertNotNull($context);
        self::assertSame($company->getId()->toRfc4122(), $context->companyId);
        self::assertSame('Demo', $context->name);
        self::assertSame('TN', $context->countryCode);
        self::assertSame('TND', $context->currency);
        self::assertSame('active', $context->status);
        self::assertSame('admin', $context->role);
        self::assertSame(['invoice.read', 'invoice.write'], $context->permissions);
        self::assertNull($context->logoVersion, 'no logo, no version');

        // The customer screen names the company with its logo, read from this (audit 2026-10-06, V-33).
        $logo = $logos->set($company, 'logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), null);
        $context = (new DescribeWorkingContext($this->memberships, $this->current, $logos))->for($this->user->getId());
        self::assertSame($logo->getFile()->getId()->toRfc4122(), $context?->logoVersion);
    }

    public function testWithoutASessionCompanyOrAMembershipThereIsNoContext(): void
    {
        $describe = new DescribeWorkingContext($this->memberships, $this->current, $this->logos());
        self::assertNull($describe->for($this->user->getId()));

        $this->current->set((new Company('Other', 'FR', 'EUR', 'fr', 'Europe/Paris'))->getId());
        self::assertNull($describe->for($this->user->getId()), 'a session company the user is no longer a member of yields nothing');
    }

    private function logos(): CompanyLogo
    {
        $clock = new MockClock();

        return new CompanyLogo(
            new Attachments(new Files(new InMemoryFileStorage(), new InMemoryStoredFiles(), $clock), new InMemoryAttachments(), $clock, 1024, ['image/png'], 2),
            new InMemoryAuditTrail(),
            new FakeTransactions(),
        );
    }
}
