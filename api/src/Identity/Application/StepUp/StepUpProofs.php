<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

use Symfony\Component\Uid\Uuid;

/**
 * When the person at this screen last proved who they are. Kept with the sign-in it was given in, so signing out, or
 * the session ending, forgets it.
 */
interface StepUpProofs
{
    public function remember(Uuid $userId, \DateTimeImmutable $at): void;

    /** @return \DateTimeImmutable|null when this account last proved itself in this sign-in, or null when it has not */
    public function lastFor(Uuid $userId): ?\DateTimeImmutable;

    /** Spends the proof of this sign-in, so what took it cannot be taken again on it. */
    public function forget(): void;
}
