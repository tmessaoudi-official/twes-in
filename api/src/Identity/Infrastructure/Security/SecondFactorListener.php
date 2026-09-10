<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Stops a login that has passed the password but still owes a second factor.
 *
 * Priority is below `CheckCredentialsListener` (512) and below the post-auth user checker, so this runs only
 * once the password is known to be right — refusing earlier would turn the endpoint into an oracle for which
 * accounts have MFA. Throwing here means no token is ever created, which is what makes every other endpoint
 * refuse without knowing MFA exists.
 */
#[AsEventListener(priority: -100)]
final readonly class SecondFactorListener
{
    public function __construct(
        private UserRepository $users,
        private PendingSecondFactor $pending,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $account = $event->getPassport()->getUser();

        if (!$account instanceof SecurityUser) {
            return;
        }

        $user = $this->users->ofId($account->getId());

        if (null === $user || !$user->hasTotp()) {
            return;
        }

        $this->pending->open($user->getId());

        throw new SecondFactorRequired();
    }
}
