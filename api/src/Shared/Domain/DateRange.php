<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/** An interval of calendar days, each end inclusive and either one open: what a list is narrowed to by « from » and « to ». */
final readonly class DateRange
{
    /** @param ?string $from YYYY-MM-DD @param ?string $to YYYY-MM-DD */
    public function __construct(public ?string $from = null, public ?string $to = null)
    {
    }

    public function isOpen(): bool
    {
        return null === $this->from && null === $this->to;
    }
}
