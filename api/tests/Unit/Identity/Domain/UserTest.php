<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testAUserIsBornWithAnIdentityAStampAndTimestamps(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 12:00:00');
        $user = new User(Email::fromString('Owner@Example.test'), 'Owner', 'fr', $now);

        self::assertSame('owner@example.test', $user->getEmail()->value);
        self::assertSame(7, '7' === $user->getId()->toRfc4122()[14] ? 7 : 0, 'identifiers are UUID v7');
        self::assertSame(32, \strlen($user->getSecurityStamp()));
        self::assertTrue($user->isActive());
        self::assertFalse($user->isPlatformOperator());
        self::assertSame($now, $user->getCreatedAt());
        self::assertSame($now, $user->getUpdatedAt());
    }

    public function testANewPasswordForcesEverySessionOutAndARehashDoesNot(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U');
        $stamp = $user->getSecurityStamp();

        $user->upgradePasswordHash('same-password-newer-parameters');
        self::assertSame($stamp, $user->getSecurityStamp());

        $user->setPasswordHash('a-new-password', new \DateTimeImmutable('2026-09-15 12:00:00'));
        self::assertNotSame($stamp, $user->getSecurityStamp());
    }

    public function testTheAccountLocksOnTheNthConsecutiveFailureAndOnlyUntilTheDeadline(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 12:00:00');
        $user = new User(Email::fromString('u@example.test'), 'U');

        for ($i = 1; $i <= 4; ++$i) {
            $user->recordFailedLogin($now, 5, new \DateInterval('PT15M'));
            self::assertFalse($user->isLockedAt($now), "not locked after $i failures");
        }
        $user->recordFailedLogin($now, 5, new \DateInterval('PT15M'));

        self::assertSame(5, $user->getFailedLoginCount());
        self::assertTrue($user->isLockedAt($now));
        self::assertTrue($user->isLockedAt($now->modify('+14 minutes 59 seconds')));
        self::assertFalse($user->isLockedAt($now->modify('+15 minutes')));
        self::assertEquals($now->modify('+15 minutes'), $user->getLockedUntil());
    }

    public function testASuccessfulLoginResetsTheCounterAndTheLock(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 12:00:00');
        $user = new User(Email::fromString('u@example.test'), 'U');
        for ($i = 0; $i < 5; ++$i) {
            $user->recordFailedLogin($now, 5, new \DateInterval('PT15M'));
        }

        $user->recordSuccessfulLogin($later = $now->modify('+20 minutes'));

        self::assertSame(0, $user->getFailedLoginCount());
        self::assertNull($user->getLockedUntil());
        self::assertEquals($later, $user->getLastLoginAt());
    }

    public function testChangingThePasswordIsDatedButUpgradingItsHashIsNot(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 12:00:00');
        $user = new User(Email::fromString('u@example.test'), 'U', 'fr', $now);
        self::assertNull($user->getPasswordChangedAt());

        $user->setPasswordHash('hash-1', $changed = $now->modify('+1 hour'));
        self::assertSame('hash-1', $user->getPasswordHash());
        self::assertEquals($changed, $user->getPasswordChangedAt());

        $user->upgradePasswordHash('hash-2');
        self::assertSame('hash-2', $user->getPasswordHash());
        self::assertEquals($changed, $user->getPasswordChangedAt(), 'a rehash with new parameters is not a password change');
    }

    public function testRotatingTheSecurityStampChangesIt(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U');
        $before = $user->getSecurityStamp();

        $user->rotateSecurityStamp();

        self::assertNotSame($before, $user->getSecurityStamp());
    }
}
