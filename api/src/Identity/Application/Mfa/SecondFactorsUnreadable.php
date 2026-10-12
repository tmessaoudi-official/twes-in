<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** Some stored authenticator secrets open with neither the previous nor the current key: a rotation refuses then. */
final class SecondFactorsUnreadable extends \RuntimeException
{
    public function __construct(public readonly int $count)
    {
        parent::__construct(\sprintf('%d authenticator secret%s open%s with neither key.', $count, 1 === $count ? '' : 's', 1 === $count ? 's' : ''));
    }
}
