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
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RecordFailedLoginTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryAuditTrail $audit;
    private MockClock $clock;
    private RecordFailedLogin $useCase;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->audit = new InMemoryAuditTrail();
        $this->clock = new MockClock('2026-09-09 12:00:00');
        $this->useCase = new RecordFailedLogin($this->users, $this->audit, $this->clock, 5, 'PT15M');
    }

    public function testAWrongPasswordCountsAgainstTheAccountAndIsAudited(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U');
        $this->users->save($user);

        $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongPassword: true));

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
        $user = new User(Email::fromString('u@example.test'), 'U');
        $this->users->save($user);

        for ($i = 0; $i < 5; ++$i) {
            $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'BadCredentialsException', wrongPassword: true));
        }

        self::assertTrue($user->isLockedAt($this->clock->now()));
        self::assertFalse($user->isLockedAt($this->clock->now()->modify('+15 minutes')));
        self::assertTrue($this->audit->entries[4]->changes['locked']);
    }

    public function testARefusalThatIsNotAWrongPasswordLeavesTheCounterAlone(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U');
        $this->users->save($user);

        // Already locked, disabled, throttled: retrying must not extend the lock without end.
        $this->useCase->handle(new FailedLoginAttempt($user->getId(), 'u@example.test', 'CustomUserMessageAccountStatusException', wrongPassword: false));

        self::assertSame(0, $user->getFailedLoginCount());
        self::assertSame(['email' => 'u@example.test', 'reason' => 'CustomUserMessageAccountStatusException'], $this->audit->entries[0]->changes);
    }

    public function testAnUnknownAccountIsAuditedWithNothingToPointAt(): void
    {
        $this->useCase->handle(new FailedLoginAttempt(null, 'nobody@example.test', 'BadCredentialsException', wrongPassword: true));

        $entry = $this->audit->entries[0];
        self::assertNull($entry->entityId);
        self::assertSame(['email' => 'nobody@example.test', 'reason' => 'BadCredentialsException'], $entry->changes);
    }
}
