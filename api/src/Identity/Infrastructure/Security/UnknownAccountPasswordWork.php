<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * An address nobody registered is refused before any password is compared, so it would answer faster than a real
 * account, and the time alone would say which addresses exist. Hashing the guess with the accounts' own hasher costs
 * what a real comparison costs.
 */
#[AsEventListener]
final readonly class UnknownAccountPasswordWork
{
    public function __construct(private PasswordHasherFactoryInterface $hashers)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (!self::noSuchAccount($event->getException())) {
            return;
        }
        $credentials = $event->getPassport()?->getBadge(PasswordCredentials::class);
        if (!$credentials instanceof PasswordCredentials || $credentials->isResolved()) {
            return;
        }
        $this->hashers->getPasswordHasher(SecurityUser::class)->hash($credentials->getPassword());
    }

    private static function noSuchAccount(?\Throwable $exception): bool
    {
        for (; null !== $exception; $exception = $exception->getPrevious()) {
            if ($exception instanceof UserNotFoundException) {
                return true;
            }
        }

        return false;
    }
}
