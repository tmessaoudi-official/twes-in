<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Security\LockedAccountRefused;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\UnknownAccountPasswordWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * An address nobody registered is refused before any password is compared, which answers faster than a real account
 * does: the time alone would say which addresses exist. The refusal hashes the password it was given, as a real
 * account's comparison would.
 */
final class UnknownAccountPasswordWorkTest extends TestCase
{
    public function testAnUnknownAddressCostsThePasswordHashingARealAccountDoes(): void
    {
        $hasher = new CountingHasher();

        new UnknownAccountPasswordWork(new PasswordHasherFactory([SecurityUser::class => $hasher]))(
            $this->failure(new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException())),
        );

        self::assertSame(['the guess'], $hasher->hashed);
    }

    /** A locked account is refused before its password too, and must not answer faster than an unknown address. */
    public function testALockedAccountCostsThePasswordHashingAndComparesNothing(): void
    {
        $hasher = new CountingHasher();

        new UnknownAccountPasswordWork(new PasswordHasherFactory([SecurityUser::class => $hasher]))(
            $this->failure(new LockedAccountRefused()),
        );

        self::assertSame(['the guess'], $hasher->hashed);
        self::assertSame(0, $hasher->verified, 'hashed, never compared with the account’s own');
    }

    public function testARealAccountsWrongPasswordWasAlreadyCompared(): void
    {
        $hasher = new CountingHasher();

        new UnknownAccountPasswordWork(new PasswordHasherFactory([SecurityUser::class => $hasher]))(
            $this->failure(new BadCredentialsException('The presented password is invalid.')),
        );

        self::assertSame([], $hasher->hashed);
    }

    private function failure(AuthenticationException $exception): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            $this->createStub(AuthenticatorInterface::class),
            new Request(),
            null,
            'main',
            new Passport(new UserBadge('nobody@example.test'), new PasswordCredentials('the guess')),
        );
    }
}

final class CountingHasher implements PasswordHasherInterface
{
    /** @var list<string> */
    public array $hashed = [];

    public int $verified = 0;

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        $this->hashed[] = $plainPassword;

        return 'hashed';
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        ++$this->verified;

        return false;
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }
}
