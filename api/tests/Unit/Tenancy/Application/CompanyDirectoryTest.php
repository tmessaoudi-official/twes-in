<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Company\ListCompaniesOfUser;
use App\Tenancy\Application\Company\ListMembers;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryMemberships;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ListCompaniesOfUser::class)]
#[CoversClass(ListMembers::class)]
final class CompanyDirectoryTest extends TestCase
{
    private InMemoryMemberships $memberships;
    private Role $owner;

    protected function setUp(): void
    {
        $this->memberships = new InMemoryMemberships();
        $this->owner = new Role(Role::OWNER, [Permission::WILDCARD]);
    }

    public function testTheSwitcherSeesEveryCompanyTheUserBelongsTo(): void
    {
        $user = new User(Email::fromString('user@twes.local'), 'Someone');
        $first = $this->join($user, 'Acme');
        $second = $this->join($user, 'Globex');

        $summaries = (new ListCompaniesOfUser($this->memberships))->for($user->getId());

        self::assertCount(2, $summaries);
        self::assertSame([$first->getName(), $second->getName()], array_map(static fn ($s) => $s->name, $summaries));
        self::assertSame([Role::OWNER, Role::OWNER], array_map(static fn ($s) => $s->role, $summaries));
        self::assertSame($first->getId()->toRfc4122(), $summaries[0]->companyId);
        self::assertSame(Company::STATUS_ACTIVE, $summaries[0]->status);
    }

    public function testTheSwitcherOfSomeoneWithNoCompanyIsEmpty(): void
    {
        self::assertSame([], (new ListCompaniesOfUser($this->memberships))->for(Uuid::v7()));
    }

    public function testAnotherUsersCompaniesAreNotListed(): void
    {
        $mine = new User(Email::fromString('mine@twes.local'), 'Mine');
        $theirs = new User(Email::fromString('theirs@twes.local'), 'Theirs');
        $this->join($mine, 'Acme');
        $this->join($theirs, 'Globex');

        $summaries = (new ListCompaniesOfUser($this->memberships))->for($mine->getId());

        self::assertCount(1, $summaries);
        self::assertSame('Acme', $summaries[0]->name);
    }

    public function testTheMemberListNamesEveryoneInTheCompany(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership(new User(Email::fromString('one@twes.local'), 'One'), $company, $this->owner));
        $this->memberships->save(new Membership(new User(Email::fromString('two@twes.local'), 'Two'), $company, $this->owner));

        $members = (new ListMembers($this->memberships))->for($company->getId());

        self::assertCount(2, $members);
        self::assertSame(['one@twes.local', 'two@twes.local'], array_map(static fn ($m) => $m->email, $members));
        self::assertSame(['One', 'Two'], array_map(static fn ($m) => $m->displayName, $members));
    }

    public function testAnotherCompanysMembersAreNotListed(): void
    {
        $mine = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $theirs = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership(new User(Email::fromString('mine@twes.local'), 'Mine'), $mine, $this->owner));
        $this->memberships->save(new Membership(new User(Email::fromString('theirs@twes.local'), 'Theirs'), $theirs, $this->owner));

        $members = (new ListMembers($this->memberships))->for($mine->getId());

        self::assertCount(1, $members);
        self::assertSame('mine@twes.local', $members[0]->email);
    }

    private function join(User $user, string $name): Company
    {
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($user, $company, $this->owner));

        return $company;
    }
}
