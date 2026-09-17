<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** How long one paid period lasts: a month, six months, three years, fourteen days. */
final readonly class BillingPeriod
{
    public const int MAX_COUNT = 1200;

    public function __construct(
        public int $count,
        public PeriodUnit $unit,
    ) {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidSubscription(\sprintf('A billing period counts 1 to %d %ss.', self::MAX_COUNT, $unit->value));
        }
    }

    /** The instant this period, repeated a number of times, ends when it starts at the one given. */
    public function after(\DateTimeImmutable $from, int $times = 1): \DateTimeImmutable
    {
        return $from->modify(\sprintf('+%d %s', $this->count * $times, $this->unit->value));
    }
}
