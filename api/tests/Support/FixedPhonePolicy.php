<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Phones\PhonePolicy;

/** The platform's policy for a phone outside the ranges handed out, as a test sets it: refused unless it says keep. */
final class FixedPhonePolicy implements PhonePolicy
{
    public function __construct(public bool $keep = false)
    {
    }

    public function keepsOutsideRanges(): bool
    {
        return $this->keep;
    }
}
