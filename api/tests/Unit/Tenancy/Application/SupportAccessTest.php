<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\Exception\InvalidSupportWindow;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Model\SupportGrant;
use App\Tenancy\Infrastructure\Security\PermissionVoter;
use App\Tests\Support\FixedCompanyAccess;
use App\Tests\Support\InMemoryCurrentCompany;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemorySupportGrants;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\SupportDoubles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** « Accès support »: the window an owner opens, who it lets in, and what it lets them read. */
final class SupportAccessTest extends TestCase
{
    private Company $company;
    private InMemoryMemberships $memberships;
    private InMemoryUsers $users;
    private InMemorySupportGrants $grants;
    private MockClock $clock;
    private User $owner;

    protected function setUp(): void
    {
        $this->company = new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships = new InMemoryMemberships();
        $this->users = new InMemoryUsers();
        $this->grants = new InMemorySupportGrants();
        $this->clock = new MockClock('2026-10-12 09:00:00');
        $this->owner = new User(Email::fromString('owner@example.test'), 'Owner');
        $this->memberships->save(new Membership($this->owner, $this->company, new Role(Role::OWNER, ['*'])));
    }

    public function testAWindowIsAnHourADayOrThreeAndLiveFromItsStartToItsEnd(): void
    {
        $grant = new SupportGrant($this->company, $this->owner, 24, $this->clock->now());

        self::assertEquals(new \DateTimeImmutable('2026-10-13 09:00:00'), $grant->getEndsAt());
        self::assertTrue($grant->isLiveAt(new \DateTimeImmutable('2026-10-13 08:59:59')));
        self::assertFalse($grant->isLiveAt(new \DateTimeImmutable('2026-10-13 09:00:00')));
        self::assertFalse($grant->isLiveAt(new \DateTimeImmutable('2026-10-12 08:59:59')));

        foreach ([0, 2, 25, 168, -1] as $hours) {
            try {
                new SupportGrant($this->company, $this->owner, $hours, $this->clock->now());
                self::fail("$hours hours opened a window");
            } catch (InvalidSupportWindow) {
            }
        }
    }

    public function testAWindowEndedIsNoLongerLiveAndKeepsWhenItEnded(): void
    {
        $grant = new SupportGrant($this->company, $this->owner, 1, $this->clock->now());
        $grant->end($this->owner, new \DateTimeImmutable('2026-10-12 09:10:00'));
        $grant->end($this->owner, new \DateTimeImmutable('2026-10-12 09:20:00'));

        self::assertFalse($grant->isLiveAt(new \DateTimeImmutable('2026-10-12 09:15:00')));
        self::assertEquals(new \DateTimeImmutable('2026-10-12 09:10:00'), $grant->getEndedAt());
    }

    public function testSupportReadsEveryReadButWhatGoodsCost(): void
    {
        self::assertSame(ProductPermission::COST_READ, SupportAccess::COST);
        self::assertTrue(SupportAccess::permits('invoice.read'));
        self::assertFalse(SupportAccess::permits(ProductPermission::COST_READ));
        self::assertFalse(SupportAccess::permits('invoice.write'));
        self::assertFalse(SupportAccess::permits('company.settings'));
        self::assertFalse(SupportAccess::permits('*'));
    }

    public function testOnlyAnOperatorWithASecondFactorWhoIsNoMemberReadsThroughALiveGrant(): void
    {
        $this->grants->save(new SupportGrant($this->company, $this->owner, 1, $this->clock->now()));
        $operator = $this->user('operator@example.test', operator: true, secondFactor: true);
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($operator), $this->company, ['customer.read']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($operator), $this->company, ['customer.write']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($operator), $this->company, [ProductPermission::COST_READ]));

        foreach ([$this->user('noone@example.test', operator: false, secondFactor: true), $this->user('password-only@example.test', operator: true, secondFactor: false)] as $refused) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($refused), $this->company, ['customer.read']), $refused->getEmail()->value);
        }

        $this->clock->sleep(3600);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($operator), $this->company, ['customer.read']), 'the hour is over');
    }

    public function testAnOperatorWhoIsAMemberWorksAsTheirRoleSays(): void
    {
        $this->grants->save(new SupportGrant($this->company, $this->owner, 1, $this->clock->now()));
        $operator = $this->user('operator@example.test', operator: true, secondFactor: true);
        $this->memberships->save(new Membership($operator, $this->company, new Role(Role::MEMBER, ['invoice.read'])));

        self::assertNull($this->access()->grantOf($operator->getId(), $this->company->getId()));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token($operator), $this->company, ['customer.read']));
    }

    private function user(string $email, bool $operator, bool $secondFactor): User
    {
        $user = new User(Email::fromString($email), 'Someone');
        $user->setPlatformOperator($operator);
        if ($secondFactor) {
            $user->beginTotpEnrolment('sealed-secret');
            $user->confirmTotpEnrolment(1);
        }
        $this->users->save($user);

        return $user;
    }

    private function access(): SupportAccess
    {
        return SupportDoubles::access($this->memberships, $this->users, $this->grants, $this->clock);
    }

    private function voter(): PermissionVoter
    {
        return new PermissionVoter($this->memberships, new InMemoryCurrentCompany($this->company->getId()), new FixedCompanyAccess(), $this->access());
    }

    private function token(User $user): UsernamePasswordToken
    {
        $account = SecurityUser::of($user);

        return new UsernamePasswordToken($account, 'main', $account->getRoles());
    }
}
