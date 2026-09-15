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
 * Forgets a half-finished login whenever another login attempt starts.
 *
 * The pending marker means "this browser passed the password for that account". Left alone it outlives a later
 * attempt in the same session: another account signing in, or a wrong password, would leave the earlier
 * account's code still able to finish. Closing it at the start of every attempt, before CheckCredentialsListener
 * (512) where a wrong password stops the chain, leaves SecondFactorListener as the only thing that opens it again,
 * and only for the attempt in progress.
 */
final readonly class PendingSecondFactorReset
{
    public function __construct(private PendingSecondFactor $pending)
    {
    }

    #[AsEventListener(priority: 1024)]
    public function onAttempt(CheckPassportEvent $event): void
    {
        $this->pending->close();
    }
}
