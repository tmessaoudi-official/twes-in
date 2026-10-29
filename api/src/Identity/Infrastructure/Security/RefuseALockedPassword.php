<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * A locked password is refused BEFORE it is compared, as a wrong password is: told apart, the right password would
 * answer differently from a wrong one, and the lock would tell a guesser which guess was right; told as locked, both
 * would say the address is real. Its owner hears of the lock by mail instead (`RecordFailedLogin`).
 *
 * Only a passport carrying a password meets it: the lock stops whoever guesses a secret, and a passkey cannot be
 * guessed. Above `CheckCredentialsListener` (0), which compares the hash, and below `UserCheckerListener` (256), which
 * has loaded the account.
 */
#[AsEventListener(priority: 128)]
final readonly class RefuseALockedPassword
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }
        $user = $passport->getUser();
        if ($user instanceof SecurityUser && $user->isLockedAt($this->clock->now())) {
            throw new LockedAccountRefused();
        }
    }
}
