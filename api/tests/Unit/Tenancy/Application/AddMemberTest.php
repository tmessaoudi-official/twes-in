<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Company\AddMember;
use App\Tenancy\Application\Company\AddMemberRequest;
use App\Tenancy\Application\Company\AlreadyAMember;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Application\Company\UserNotFound;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryRoles;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AddMember::class)]
final class AddMemberTest extends TestCase
{
    private InMemoryCompanies $companies;
    private InMemoryUsers $users;
    private InMemoryMemberships $memberships;
    private InMemoryRoles $roles;
    private InMemoryAuditTrail $audit;
    private AddMember $add;
    private Company $company;

    protected function setUp(): void
    {
        $this->companies = new InMemoryCompanies();
        $this->users = new InMemoryUsers();
        $this->memberships = new InMemoryMemberships();
        $this->roles = new InMemoryRoles();
        $this->audit = new InMemoryAuditTrail();
        $this->add = new AddMember($this->companies, $this->users, $this->memberships, $this->roles, $this->audit, new MockClock('2026-09-09 10:00:00'));

        $this->company = Company::pending('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->companies->save($this->company);
        $this->roles->save(new Role(Role::OWNER, [Permission::WILDCARD]));
        $this->roles->save(new Role(Role::MEMBER, ['company.read']));
    }

    public function testAnExistingUserJoinsWithoutAnyAcceptanceStep(): void
    {
        $user = $this->user('joiner@twes.local');

        $membership = $this->add->handle(new AddMemberRequest($this->company->getId(), 'joiner@twes.local', Role::MEMBER), null);

        self::assertSame($user->getId(), $membership->getUser()->getId());
        self::assertSame(Role::MEMBER, $membership->getRole()->getName());
        self::assertNotNull($this->memberships->ofUserInCompany($user->getId(), $this->company->getId()));
    }

    public function testTheFirstOwnerActivatesThePendingCompany(): void
    {
        $this->user('owner@twes.local');

        $this->add->handle(new AddMemberRequest($this->company->getId(), 'owner@twes.local', Role::OWNER), null);

        self::assertSame(Company::STATUS_ACTIVE, $this->company->getStatus());
    }

    public function testAMemberWhoIsNotAnOwnerLeavesTheCompanyPending(): void
    {
        $this->user('member@twes.local');

        $this->add->handle(new AddMemberRequest($this->company->getId(), 'member@twes.local', Role::MEMBER), null);

        self::assertSame(Company::STATUS_PENDING, $this->company->getStatus());
    }

    public function testTheEmailIsMatchedTheWayEmailsAreCompared(): void
    {
        $user = $this->user('Mixed.Case@Twes.Local');

        $membership = $this->add->handle(new AddMemberRequest($this->company->getId(), '  MIXED.CASE@twes.local ', Role::MEMBER), null);

        self::assertSame($user->getId(), $membership->getUser()->getId());
    }

    public function testAnUnknownCompanyIsRefused(): void
    {
        $this->user('joiner@twes.local');

        $this->expectException(CompanyNotFound::class);
        $this->add->handle(new AddMemberRequest(Uuid::v7(), 'joiner@twes.local', Role::MEMBER), null);
    }

    public function testAnAddressWithNoUserIsRefusedHereAndBecomesAnInvitation(): void
    {
        $this->expectException(UserNotFound::class);
        $this->add->handle(new AddMemberRequest($this->company->getId(), 'stranger@twes.local', Role::MEMBER), null);
    }

    public function testARoleThatIsNotBuiltInIsRefused(): void
    {
        $this->user('joiner@twes.local');

        $this->expectException(UnknownRole::class);
        $this->add->handle(new AddMemberRequest($this->company->getId(), 'joiner@twes.local', 'emperor'), null);
    }

    public function testTheSameUserIsNotAddedTwice(): void
    {
        $this->user('joiner@twes.local');
        $this->add->handle(new AddMemberRequest($this->company->getId(), 'joiner@twes.local', Role::MEMBER), null);

        $this->expectException(AlreadyAMember::class);
        $this->add->handle(new AddMemberRequest($this->company->getId(), 'joiner@twes.local', Role::OWNER), null);
    }

    public function testJoiningIsAudited(): void
    {
        $user = $this->user('joiner@twes.local');
        $actor = Uuid::v7();

        $this->add->handle(new AddMemberRequest($this->company->getId(), 'joiner@twes.local', Role::MEMBER), $actor);

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame('membership', $entry->entityType);
        self::assertSame('membership.added', $entry->action);
        self::assertSame($actor, $entry->actorUserId);
        self::assertNotNull($entry->companyId);
        self::assertTrue($this->company->getId()->equals($entry->companyId));
        self::assertSame($user->getId()->toRfc4122(), $entry->changes['user_id'] ?? null);
    }

    private function user(string $email): User
    {
        $user = new User(Email::fromString($email), 'Someone');
        $this->users->save($user);

        return $user;
    }
}
