<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tests\Support\InMemoryCompanyProofs;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\SupportDoubles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CompanyEntryTest extends TestCase
{
    private InMemoryMemberships $memberships;
    private User $user;
    private Company $first;
    private Company $second;

    protected function setUp(): void
    {
        $this->memberships = new InMemoryMemberships();
        $this->user = new User(Email::fromString('m@example.test'), 'M', 'fr');
        $this->first = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->second = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($this->user, $this->first, new Role(Role::OWNER, ['*'])));
        $this->memberships->save(new Membership($this->user, $this->second, new Role(Role::OWNER, ['*'])));
    }

    public function testAProofUsedEveryTwentyNineMinutesEndsTwelveHoursAfterItWasGiven(): void
    {
        $clock = new MockClock('2026-10-29 08:00:00');
        $entry = SupportDoubles::entry($this->memberships, new InMemoryCompanyProofs(), $clock);
        self::assertTrue($entry->awaitsProof($this->user->getId(), $this->second->getId()));
        $entry->proved($this->second->getId());

        for ($minutes = 29; $minutes <= 12 * 60; $minutes += 29) {
            $clock->modify('+29 minutes');
            self::assertFalse($entry->awaitsProof($this->user->getId(), $this->second->getId()), "after $minutes minutes");
        }
        $clock->modify('2026-10-29 20:00:01');
        self::assertTrue($entry->awaitsProof($this->user->getId(), $this->second->getId()));
    }

    public function testAProofLeftAloneThirtyMinutesEnds(): void
    {
        $clock = new MockClock('2026-10-29 08:00:00');
        $entry = SupportDoubles::entry($this->memberships, new InMemoryCompanyProofs(), $clock);
        $entry->proved($this->second->getId());

        $clock->modify('+30 minutes');
        self::assertFalse($entry->awaitsProof($this->user->getId(), $this->second->getId()));
        $clock->modify('+30 minutes 1 second');
        self::assertTrue($entry->awaitsProof($this->user->getId(), $this->second->getId()));
        self::assertEquals([$this->first->getId(), $this->second->getId()], $entry->awaitingProof($this->user->getId()));
    }

    public function testANewSignInStartsWithNothingProved(): void
    {
        $entry = SupportDoubles::entry($this->memberships);
        $entry->proved($this->first->getId());
        $entry->proved($this->second->getId());

        $entry->signedIn();

        self::assertTrue($entry->awaitsProof($this->user->getId(), $this->first->getId()));
        self::assertTrue($entry->awaitsProof($this->user->getId(), $this->second->getId()));
    }

    public function testOnlyAMemberOfTwoCompaniesOrMoreIsAsked(): void
    {
        $entry = SupportDoubles::entry($this->memberships);
        $stranger = new Company('Initech', 'TN', 'TND', 'fr', 'Africa/Tunis');
        self::assertFalse($entry->awaitsProof($this->user->getId(), $stranger->getId()), 'whoever is not a member is refused elsewhere');

        $alone = new User(Email::fromString('a@example.test'), 'A', 'fr');
        $this->memberships->save(new Membership($alone, $stranger, new Role(Role::OWNER, ['*'])));
        self::assertFalse($entry->awaitsProof($alone->getId(), $stranger->getId()));
        self::assertSame([], $entry->awaitingProof($alone->getId()));
    }
}
