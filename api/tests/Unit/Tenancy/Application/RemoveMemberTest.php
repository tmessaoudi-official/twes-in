<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Company\LastOwner;
use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Application\Company\RemoveMember;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryMemberships;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RemoveMember::class)]
final class RemoveMemberTest extends TestCase
{
    private InMemoryMemberships $memberships;
    private InMemoryAuditTrail $audit;
    private RemoveMember $remove;
    private Company $company;
    private Role $owner;
    private Role $member;

    protected function setUp(): void
    {
        $this->memberships = new InMemoryMemberships();
        $this->audit = new InMemoryAuditTrail();
        $this->remove = new RemoveMember($this->memberships, $this->audit, new MockClock('2026-09-09 10:00:00'));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->owner = new Role(Role::OWNER, [Permission::WILDCARD]);
        $this->member = new Role(Role::MEMBER, ['company.read']);
    }

    public function testAMemberIsRemoved(): void
    {
        $this->join('owner@twes.local', $this->owner);
        $leaving = $this->join('member@twes.local', $this->member);

        $this->remove->handle($this->company->getId(), $leaving->getUser()->getId(), null);

        self::assertNull($this->memberships->ofUserInCompany($leaving->getUser()->getId(), $this->company->getId()));
    }

    public function testSomeoneWhoIsNotAMemberCannotBeRemoved(): void
    {
        $this->expectException(NotAMember::class);
        $this->remove->handle($this->company->getId(), Uuid::v7(), null);
    }

    public function testTheOnlyOwnerCannotBeRemoved(): void
    {
        $only = $this->join('owner@twes.local', $this->owner);
        $this->join('member@twes.local', $this->member);

        $this->expectException(LastOwner::class);
        $this->remove->handle($this->company->getId(), $only->getUser()->getId(), null);
    }

    public function testTheRefusedOwnerIsStillAMember(): void
    {
        $only = $this->join('owner@twes.local', $this->owner);

        try {
            $this->remove->handle($this->company->getId(), $only->getUser()->getId(), null);
        } catch (LastOwner) {
        }

        self::assertNotNull($this->memberships->ofUserInCompany($only->getUser()->getId(), $this->company->getId()));
    }

    public function testOneOfTwoOwnersCanBeRemoved(): void
    {
        $first = $this->join('one@twes.local', $this->owner);
        $this->join('two@twes.local', $this->owner);

        $this->remove->handle($this->company->getId(), $first->getUser()->getId(), null);

        self::assertNull($this->memberships->ofUserInCompany($first->getUser()->getId(), $this->company->getId()));
    }

    public function testRemovalIsAudited(): void
    {
        $this->join('owner@twes.local', $this->owner);
        $leaving = $this->join('member@twes.local', $this->member);
        $actor = Uuid::v7();

        $this->remove->handle($this->company->getId(), $leaving->getUser()->getId(), $actor);

        self::assertCount(1, $this->audit->entries);
        self::assertSame('membership.removed', $this->audit->entries[0]->action);
        self::assertSame($actor, $this->audit->entries[0]->actorUserId);
    }

    private function join(string $email, Role $role): Membership
    {
        $membership = new Membership(new User(Email::fromString($email), 'Someone'), $this->company, $role);
        $this->memberships->save($membership);

        return $membership;
    }
}
