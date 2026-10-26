<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;
use App\Tenancy\Application\Closing\ClosedDown;

/** A closed company's terms, which the page its members land on says: the last day it is kept, and until when its owner may reopen it. */
final readonly class MeClosing
{
    public function __construct(
        /** a day, Y-m-d */
        #[ApiProperty(required: true)] public string $retainUntil,
        /** a moment, ATOM */
        #[ApiProperty(required: true)] public string $reopenUntil,
    ) {
    }

    public static function of(ClosedDown $closing): self
    {
        return new self($closing->retainUntil->format('Y-m-d'), $closing->reopenUntil->format(\DateTimeInterface::ATOM));
    }
}
