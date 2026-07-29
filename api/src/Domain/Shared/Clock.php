<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Shared;

/**
 * The current time, as a dependency rather than as an ambient fact.
 *
 * Naming `\DateTimeImmutable` here is fine; *constructing* one inside `Domain/` is not, and
 * scripts/gates/no-ambient-calls-in-domain.php enforces exactly that distinction. The reason is
 * testability, and in a billing domain it is not academic: payment terms, due dates, "is this invoice
 * overdue", recurring schedules, month-end rollover and VAT period boundaries are all time-dependent
 * rules whose edge cases can only be tested by choosing the date.
 *
 * Implementations must return UTC. Presentation timezones belong to `UI/`.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
