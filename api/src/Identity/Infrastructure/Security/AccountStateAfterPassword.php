<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Tells a locked or deactivated account why it is refused, once the password is known to be right and before any second
 * factor is asked for. Between `CheckCredentialsListener` (0) and `SecondFactorListener` (-100): earlier, a wrong
 * password would hear the account's state; later, a deactivated account would be asked for its code first.
 */
#[AsEventListener(priority: -50)]
final readonly class AccountStateAfterPassword
{
    public function __construct(private UserChecker $checker)
    {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $this->checker->checkPostAuth($event->getPassport()->getUser());
    }
}
