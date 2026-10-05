<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/** An interval of amounts or quantities as decimal strings, each end inclusive and either one open; never a float. */
final readonly class DecimalRange
{
    public function __construct(public ?string $min = null, public ?string $max = null)
    {
    }

    public function isOpen(): bool
    {
        return null === $this->min && null === $this->max;
    }
}
