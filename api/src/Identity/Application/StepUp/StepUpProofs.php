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
    /** @param bool $withSecondFactor the proof was a passkey, or the password with the authenticator code */
    public function remember(Uuid $userId, \DateTimeImmutable $at, bool $withSecondFactor): void;

    /**
     * When this account last proved itself in this sign-in, or null when it has not; asked for one given with a second
     * factor, null unless it was, a proof that does not say counting as without.
     */
    public function lastFor(Uuid $userId, bool $withSecondFactor): ?\DateTimeImmutable;

    /** Counts one more wrong answer in this sign-in and says how many there have been since its last proof. */
    public function failed(Uuid $userId): int;

    /** Spends the proof of this sign-in, so what took it cannot be taken again on it. */
    public function forget(): void;
}
