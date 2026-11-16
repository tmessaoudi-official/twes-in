<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

use App\Identity\Application\BreachedPasswordCheck;

/**
 * What a password chosen now must satisfy, every way one is chosen (signing up, accepting an invitation, a change by a
 * signed-in person, a reset by mail): at least the length the operator set (`app.password.min_length`, never under the
 * floor of twelve) and at most 4096 characters, and not one that has appeared in a breach. An unreachable breach service
 * accepts the password, and the caller audits that it did, so the answer is carried back rather than hidden.
 */
final readonly class NewPasswordPolicy
{
    /** The floor no installation goes under, which the request attributes hold before a use case reads the length set. */
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 4096;

    public function __construct(private BreachedPasswordCheck $breached, private int $minLength = self::MIN_LENGTH)
    {
        if ($minLength < self::MIN_LENGTH || $minLength > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A new password needs at least %d characters, never fewer than %d; %d was given.', self::MIN_LENGTH, self::MIN_LENGTH, $minLength));
        }
    }

    /** How long a new password must be here, which the screens read through GET /api/auth/password/rule. */
    public function minLength(): int
    {
        return $this->minLength;
    }

    /**
     * @return bool|null null when the breach service could not be reached and the password was accepted without it
     *
     * @throws NewPasswordRefused
     */
    public function check(string $newPassword): ?bool
    {
        $length = mb_strlen($newPassword);
        if ($length < $this->minLength || $length > self::MAX_LENGTH) {
            throw new NewPasswordRefused(NewPasswordRefused::TOO_SHORT, $this->minLength);
        }

        $breached = $this->breached->isBreached($newPassword);
        if (true === $breached) {
            throw new NewPasswordRefused(NewPasswordRefused::BREACHED);
        }

        return null === $breached ? null : false;
    }
}
