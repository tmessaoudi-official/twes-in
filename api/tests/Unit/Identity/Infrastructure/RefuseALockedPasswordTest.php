<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Application\Login\PasswordLock;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Infrastructure\Security\LockedAccountRefused;
use App\Identity\Infrastructure\Security\RefuseALockedPassword;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tests\Support\FixedCallerAddress;
use App\Tests\Support\InMemoryAddressLocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Today only the password sign-in carries a password, so the functional suites cannot tell a lock met by every way in
 * from one met by the password alone: a passkey sign-in that needs no password must pass a locked one.
 */
final class RefuseALockedPasswordTest extends TestCase
{
    public function testALockedPasswordIsRefusedAndAWayInWithoutOneIsNot(): void
    {
        $clock = new MockClock('2026-10-29 12:00:00');
        $user = new User(Email::fromString('owner@example.test'), 'Owner', 'fr', new \DateTimeImmutable());
        $user->recordFailedLogin($clock->now(), 1, new \DateInterval('PT15M'));
        $account = SecurityUser::of($user);
        $badge = new UserBadge('owner@example.test', static fn (): SecurityUser => $account);
        $listener = new RefuseALockedPassword(new PasswordLock(new InMemoryAddressLocks(), new FixedCallerAddress(), $clock), $clock);
        $authenticator = $this->createStub(AuthenticatorInterface::class);

        $listener(new CheckPassportEvent($authenticator, new SelfValidatingPassport($badge)));

        $this->expectException(LockedAccountRefused::class);
        $listener(new CheckPassportEvent($authenticator, new Passport($badge, new PasswordCredentials('right-or-wrong'))));
    }

    /** Locked from one address, the password is refused there and compared from any other. */
    public function testAPasswordLockedFromOneAddressIsRefusedThereAlone(): void
    {
        $clock = new MockClock('2026-10-29 12:00:00');
        $user = new User(Email::fromString('owner@example.test'), 'Owner', 'fr', new \DateTimeImmutable());
        $locks = new InMemoryAddressLocks();
        $locks->recordFailure($user->getId(), '203.0.113.9', $clock->now(), 1, $clock->now()->modify('+15 minutes'));
        $account = SecurityUser::of($user);
        $badge = new UserBadge('owner@example.test', static fn (): SecurityUser => $account);
        $caller = new FixedCallerAddress('198.51.100.7');
        $listener = new RefuseALockedPassword(new PasswordLock($locks, $caller, $clock), $clock);
        $authenticator = $this->createStub(AuthenticatorInterface::class);

        $listener(new CheckPassportEvent($authenticator, new Passport($badge, new PasswordCredentials('right-or-wrong'))));

        $caller->address = '203.0.113.9';
        $this->expectException(LockedAccountRefused::class);
        $listener(new CheckPassportEvent($authenticator, new Passport($badge, new PasswordCredentials('right-or-wrong'))));
    }
}
