<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\TestCase;

final class SecurityUserTest extends TestCase
{
    public function testItCarriesTheIdentifierTheHashAndTheRoles(): void
    {
        $user = new User(Email::fromString('Owner@Example.test'), 'Owner');
        $user->setPasswordHash('argon-hash', new \DateTimeImmutable());

        $account = SecurityUser::of($user);

        self::assertSame('owner@example.test', $account->getUserIdentifier());
        self::assertSame('argon-hash', $account->getPassword());
        self::assertTrue($account->getId()->equals($user->getId()));
        self::assertSame(['ROLE_USER'], $account->getRoles());

        $user->setPlatformOperator(true);
        self::assertSame(['ROLE_USER', 'ROLE_PLATFORM_OPERATOR'], SecurityUser::of($user)->getRoles());
    }

    public function testTheSessionCopyIsStaleOnceTheStampRotatesOrTheAccountChangesScope(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U');
        $stored = SecurityUser::of($user);
        self::assertTrue($stored->isEqualTo(SecurityUser::of($user)));

        $user->rotateSecurityStamp();
        self::assertFalse($stored->isEqualTo(SecurityUser::of($user)), 'forced logout: a rotated stamp invalidates every session');

        $user = new User(Email::fromString('u@example.test'), 'U');
        $stored = SecurityUser::of($user);
        $user->setActive(false);
        self::assertFalse($stored->isEqualTo(SecurityUser::of($user)));

        $user = new User(Email::fromString('u@example.test'), 'U');
        $stored = SecurityUser::of($user);
        $user->setPlatformOperator(true);
        self::assertFalse($stored->isEqualTo(SecurityUser::of($user)), 'a scope change must re-authenticate rather than ride on the old token');

        self::assertFalse($stored->isEqualTo(SecurityUser::of(new User(Email::fromString('u@example.test'), 'U'))), 'same email, different id: not the same user');
    }

    public function testTheLockAndTheActiveFlagTravelWithIt(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 12:00:00');
        $user = new User(Email::fromString('u@example.test'), 'U');
        for ($i = 0; $i < 5; ++$i) {
            $user->recordFailedLogin($now, 5, new \DateInterval('PT15M'));
        }
        $account = SecurityUser::of($user);

        self::assertTrue($account->isLockedAt($now));
        self::assertFalse($account->isLockedAt($now->modify('+15 minutes')));
        self::assertTrue($account->isActive());
    }

    public function testThePasswordHashNeverReachesTheSessionStore(): void
    {
        $user = new User(Email::fromString('u@example.test'), 'U');
        $user->setPasswordHash('argon-hash', new \DateTimeImmutable());
        $account = SecurityUser::of($user);

        $serialized = serialize($account);
        self::assertStringNotContainsString('argon-hash', $serialized);

        $restored = unserialize($serialized);
        self::assertInstanceOf(SecurityUser::class, $restored);
        self::assertTrue($restored->isEqualTo($account));
        self::assertSame('u@example.test', $restored->getUserIdentifier());
    }
}
