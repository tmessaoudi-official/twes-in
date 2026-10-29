<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\LoginAudit;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Mfa\SecondFactorLockout;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\RecordedSecurityNotices;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RecordFailedLoginTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryAuditTrail $audit;
    private RecordedSecurityNotices $notices;
    private MockClock $clock;
    private RecordFailedLogin $useCase;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->audit = new InMemoryAuditTrail();
        $this->notices = new RecordedSecurityNotices();
        $this->clock = new MockClock('2026-09-09 12:00:00');
        $this->useCase = new RecordFailedLogin($this->users, $this->audit, $this->notices, new \App\Identity\Application\Account\AccountChanges(new \App\Tests\Support\InMemoryMemberships(), new \App\Tests\Support\RecordingLiveChanges()), new \App\Tests\Support\FakeTransactions(), $this->clock, 5, 'PT15M');
    }

    public function testAWrongPasswordCountsAgainstTheAccountAndIsAudited(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U', 'fr');
        $this->users->save($user);

        $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongCredential: true));

        self::assertSame(1, $user->getFailedLoginCount());
        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(LoginAudit::ENTITY_TYPE, $entry->entityType);
        self::assertSame(LoginAudit::LOGIN_FAILED, $entry->action);
        self::assertTrue($user->getId()->equals($entry->entityId ?? throw new \LogicException()));
        self::assertNull($entry->actorUserId, 'nobody is authenticated when a login fails');
        self::assertSame(['email' => 'u@example.test', 'reason' => 'BadCredentialsException', 'failed_login_count' => 1, 'locked' => false], $entry->changes);
    }

    public function testTheFifthWrongPasswordLocksTheAccount(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U', 'fr');
        $this->users->save($user);

        for ($i = 0; $i < 5; ++$i) {
            $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongCredential: true));
        }

        self::assertTrue($user->isLockedAt($this->clock->now()));
        self::assertFalse($user->isLockedAt($this->clock->now()->modify('+15 minutes')));
        self::assertTrue($this->audit->entries[4]->changes['locked']);
    }

    /** Its owner is told once, when the lock is set, with when it ends; never once per guess. */
    public function testTheOwnerIsToldOnceWhenTheLockIsSet(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U', 'fr');
        $this->users->save($user);

        for ($i = 0; $i < 4; ++$i) {
            $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongCredential: true));
        }
        self::assertSame([], $this->notices->told);

        for ($i = 0; $i < 3; ++$i) {
            $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongCredential: true));
        }

        self::assertSame([SecurityChange::AccountLocked], $this->notices->told);
        self::assertEquals([$this->clock->now()->modify('+15 minutes')], $this->notices->until);
        self::assertTrue($user->getId()->equals($this->notices->userIds[0]));
    }

    /** Wrong codes are typed after the right password: the lock they set says so, as its owner has more to do than wait. */
    public function testALockSetByAWrongSecondFactorIsToldAsOne(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U', 'fr');
        $this->users->save($user);

        for ($i = 0; $i < 4; ++$i) {
            $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongCredential: true));
        }
        $this->useCase->handle(new FailedLoginAttempt($user->getId(), null, SecondFactorLockout::WRONG_CODE, wrongCredential: true));

        self::assertSame([SecurityChange::SecondFactorLocked], $this->notices->told);
        self::assertEquals([$this->clock->now()->modify('+15 minutes')], $this->notices->until);
    }

    public function testARefusalThatIsNotAWrongPasswordLeavesTheCounterAlone(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U', 'fr');
        $this->users->save($user);

        // Already locked, disabled, throttled: retrying must not extend the lock without end.
        $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'CustomUserMessageAccountStatusException', wrongCredential: false));

        self::assertSame(0, $user->getFailedLoginCount());
        self::assertSame(['email' => 'u@example.test', 'reason' => 'CustomUserMessageAccountStatusException'], $this->audit->entries[0]->changes);
    }

    public function testAnUnknownAccountIsAuditedWithNothingToPointAt(): void
    {
        $this->useCase->handle(new FailedLoginAttempt(null, 'nobody@example.test', 'BadCredentialsException', wrongCredential: true));

        $entry = $this->audit->entries[0];
        self::assertNull($entry->entityId);
        self::assertSame(['email' => 'nobody@example.test', 'reason' => 'BadCredentialsException'], $entry->changes);
    }
}
