<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

/** A company just closed down: the last day it is kept, and until when its owner may still take the closing back. */
final readonly class ClosedDown
{
    public function __construct(public \DateTimeImmutable $retainUntil, public \DateTimeImmutable $reopenUntil)
    {
    }
}
