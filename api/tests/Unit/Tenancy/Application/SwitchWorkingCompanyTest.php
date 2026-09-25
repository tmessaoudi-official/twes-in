<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Application\Session\SwitchWorkingCompany;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryCurrentCompany;
use App\Tests\Support\InMemoryMemberships;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SwitchWorkingCompany::class)]
final class SwitchWorkingCompanyTest extends TestCase
{
    private InMemoryMemberships $memberships;
    private InMemoryCurrentCompany $currentCompany;
    private SwitchWorkingCompany $switch;
    private User $user;

    protected function setUp(): void
    {
        $this->memberships = new InMemoryMemberships();
        $this->currentCompany = new InMemoryCurrentCompany();
        $this->switch = new SwitchWorkingCompany($this->memberships, $this->currentCompany, new MockClock('2026-09-25 09:00:00'));
        $this->user = new User(Email::fromString('user@twes.local'), 'Someone');
    }

    public function testWorkingCompanyBecomesTheOneChosen(): void
    {
        $company = $this->join('Acme');

        $this->switch->to($this->user->getId(), $company->getId());

        self::assertNotNull($this->currentCompany->id());
        self::assertTrue($company->getId()->equals($this->currentCompany->id()));
    }

    public function testACompanyTheUserIsNotAMemberOfIsRefused(): void
    {
        $this->expectException(NotAMember::class);
        $this->switch->to($this->user->getId(), Uuid::v7());
    }

    public function testARefusedSwitchLeavesTheSessionCompanyAlone(): void
    {
        $mine = $this->join('Mine');
        $this->switch->to($this->user->getId(), $mine->getId());

        try {
            $this->switch->to($this->user->getId(), Uuid::v7());
        } catch (NotAMember) {
        }

        self::assertNotNull($this->currentCompany->id());
        self::assertTrue($mine->getId()->equals($this->currentCompany->id()));
    }

    public function testAnotherUsersMembershipDoesNotOpenTheCompany(): void
    {
        $theirs = new Company('Theirs', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $other = new User(Email::fromString('other@twes.local'), 'Other');
        $this->memberships->save(new Membership($other, $theirs, new Role(Role::OWNER, [Permission::WILDCARD])));

        $this->expectException(NotAMember::class);
        $this->switch->to($this->user->getId(), $theirs->getId());
    }

    private function join(string $name): Company
    {
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($this->user, $company, new Role(Role::OWNER, [Permission::WILDCARD])));

        return $company;
    }
}
