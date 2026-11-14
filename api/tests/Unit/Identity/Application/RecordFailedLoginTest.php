<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Account\AccountChanges;
use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\LoginAudit;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Mfa\SecondFactorLockout;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\FixedCallerAddress;
use App\Tests\Support\InMemoryAddressLocks;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\RecordedSecurityNotices;
use App\Tests\Support\RecordingLiveChanges;
use App\Tests\Support\RecordingRealtimeCuts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Five wrong guesses from one address lock the password from that address alone; fifty, five from each of ten addresses,
 * lock it everywhere. An address keeps counting past its five, but only its first five count toward the fifty, so one
 * address alone never locks the account's owner out from another.
 */
final class RecordFailedLoginTest extends TestCase
{
    private const string HERE = '203.0.113.9';

    private InMemoryUsers $users;
    private InMemoryAddressLocks $locks;
    private FixedCallerAddress $caller;
    private InMemoryAuditTrail $audit;
    private RecordedSecurityNotices $notices;
    private MockClock $clock;
    private RecordFailedLogin $useCase;
    private User $user;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->locks = new InMemoryAddressLocks();
        $this->caller = new FixedCallerAddress(self::HERE);
        $this->audit = new InMemoryAuditTrail();
        $this->notices = new RecordedSecurityNotices();
        $this->clock = new MockClock('2026-09-09 12:00:00');
        $this->useCase = new RecordFailedLogin($this->users, $this->locks, $this->caller, $this->audit, $this->notices, new AccountChanges(new InMemoryMemberships(), new RecordingLiveChanges()), new RecordingRealtimeCuts(), new FakeTransactions(), $this->clock, 5, 50, 'PT15M');
        $this->user = new User(Email::fromString('u@example.test'), 'U', 'fr', new \DateTimeImmutable());
        $this->users->save($this->user);
    }

    public function testAWrongPasswordCountsAgainstTheAddressAndTheAccountAndIsAudited(): void
    {
        $this->wrongPassword();

        self::assertSame(1, $this->user->getFailedLoginCount());
        self::assertSame(1, $this->locks->rows[$this->user->getId()->toRfc4122().' '.self::HERE]['count']);
        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(LoginAudit::ENTITY_TYPE, $entry->entityType);
        self::assertSame(LoginAudit::LOGIN_FAILED, $entry->action);
        self::assertTrue($this->user->getId()->equals($entry->entityId ?? throw new \LogicException()));
        self::assertNull($entry->actorUserId, 'nobody is authenticated when a login fails');
        self::assertSame(['email' => 'u@example.test', 'reason' => 'BadCredentialsException', 'failed_login_count' => 1, 'locked' => false], $entry->changes);
    }

    public function testTheFifthWrongPasswordLocksThePasswordFromThatAddressAlone(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->wrongPassword();
        }

        $now = $this->clock->now();
        self::assertEquals($now->modify('+15 minutes'), $this->locks->lockedUntil($this->user->getId(), self::HERE, $now));
        self::assertNull($this->locks->lockedUntil($this->user->getId(), '198.51.100.7', $now), 'the owner elsewhere is not locked out');
        self::assertFalse($this->user->isLockedAt($now), 'nor is the account');
        self::assertTrue($this->audit->entries[4]->changes['locked']);
    }

    /** Its owner is told once, when an address's lock is first set, with when it ends; never once per guess. */
    public function testTheOwnerIsToldOnceWhenAnAddressIsLocked(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->wrongPassword();
        }
        self::assertSame([], $this->notices->told);

        for ($i = 0; $i < 3; ++$i) {
            $this->wrongPassword();
        }
        self::assertSame([SecurityChange::AddressLocked], $this->notices->told);
        self::assertEquals([$this->clock->now()->modify('+15 minutes')], $this->notices->until);
        self::assertTrue($this->user->getId()->equals($this->notices->userIds[0]));

        // Past its end, each guess from there locks it again, untold: the owner heard once already.
        $this->clock->modify('+16 minutes');
        $this->wrongPassword();
        self::assertNotNull($this->locks->lockedUntil($this->user->getId(), self::HERE, $this->clock->now()));
        self::assertSame([SecurityChange::AddressLocked], $this->notices->told);
    }

    /** Wrong codes are typed after the right password: the lock they set says so, as its owner has more to do than wait. */
    public function testALockSetByAWrongSecondFactorIsToldAsOne(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->wrongPassword();
        }
        $this->useCase->handle(new FailedLoginAttempt($this->user->getId(), null, SecondFactorLockout::WRONG_CODE, wrongCredential: true));

        self::assertSame([SecurityChange::SecondFactorAddressLocked], $this->notices->told);
        self::assertEquals([$this->clock->now()->modify('+15 minutes')], $this->notices->until);
    }

    public function testGuessesFromManyAddressesLockThePasswordEverywhere(): void
    {
        for ($address = 1; $address <= 10; ++$address) {
            $this->caller->address = '198.51.100.'.$address;
            for ($i = 0; $i < 5; ++$i) {
                $this->wrongPassword();
            }
        }

        self::assertTrue($this->user->isLockedAt($this->clock->now()));
        self::assertSame(50, $this->user->getFailedLoginCount());
        self::assertSame(SecurityChange::AccountLocked, end($this->notices->told), 'the guess locking it everywhere tells that');
        self::assertCount(10, $this->notices->told, 'nine addresses locked, then the password everywhere');
    }

    /** Guessing on from one address, a guess each time its lock ends, never reaches the account's fifty. */
    public function testOneAddressAloneNeverLocksThePasswordEverywhere(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            $this->wrongPassword();
            $this->clock->modify('+16 minutes');
        }

        self::assertSame(5, $this->user->getFailedLoginCount());
        self::assertFalse($this->user->isLockedAt($this->clock->now()));
    }

    public function testAGuessWhileTheAddressIsLockedCountsNothing(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->wrongPassword();
        }

        self::assertSame(5, $this->locks->rows[$this->user->getId()->toRfc4122().' '.self::HERE]['count']);
        self::assertSame(5, $this->user->getFailedLoginCount());
    }

    public function testARefusalThatIsNotAWrongPasswordLeavesTheCounterAlone(): void
    {
        // Already locked, disabled, throttled: retrying must not extend the lock without end.
        $this->useCase->handle(new FailedLoginAttempt($this->user->getId(), 'u@example.test', 'CustomUserMessageAccountStatusException', wrongCredential: false));

        self::assertSame(0, $this->user->getFailedLoginCount());
        self::assertSame([], $this->locks->rows);
        self::assertSame(['email' => 'u@example.test', 'reason' => 'CustomUserMessageAccountStatusException'], $this->audit->entries[0]->changes);
    }

    public function testAnUnknownAccountIsAuditedWithNothingToPointAt(): void
    {
        $this->useCase->handle(new FailedLoginAttempt(null, 'nobody@example.test', 'BadCredentialsException', wrongCredential: true));

        $entry = $this->audit->entries[0];
        self::assertNull($entry->entityId);
        self::assertSame(['email' => 'nobody@example.test', 'reason' => 'BadCredentialsException'], $entry->changes);
        self::assertSame([], $this->locks->rows);
    }

    private function wrongPassword(): void
    {
        $this->useCase->handle(new FailedLoginAttempt($this->user->getId(), 'u@example.test', 'BadCredentialsException', wrongCredential: true));
    }
}
