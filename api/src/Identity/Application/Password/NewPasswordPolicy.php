<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

use App\Identity\Application\BreachedPasswordCheck;

/**
 * What a password chosen now must satisfy, for a change by a signed-in person and for a reset by mail alike: twelve to
 * 4096 characters, and not one that has appeared in a breach. An unreachable breach service accepts the password, and
 * the caller audits that it did, so the answer is carried back rather than hidden.
 */
final readonly class NewPasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 4096;

    public function __construct(private BreachedPasswordCheck $breached)
    {
    }

    /**
     * @return bool|null null when the breach service could not be reached and the password was accepted without it
     *
     * @throws NewPasswordRefused
     */
    public function check(string $newPassword): ?bool
    {
        $length = mb_strlen($newPassword);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new NewPasswordRefused(NewPasswordRefused::TOO_SHORT);
        }

        $breached = $this->breached->isBreached($newPassword);
        if (true === $breached) {
            throw new NewPasswordRefused(NewPasswordRefused::BREACHED);
        }

        return null === $breached ? null : false;
    }
}
