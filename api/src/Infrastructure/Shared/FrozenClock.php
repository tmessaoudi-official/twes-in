<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Shared;

use Twes\Domain\Shared\Clock;

/**
 * A clock stopped at a chosen instant, which can be advanced deliberately.
 *
 * Shipped rather than confined to the test directory, because it is also how a data migration or a
 * replayed event stream pins "now" to a historical moment.
 */
final class FrozenClock implements Clock
{
    private function __construct(private \DateTimeImmutable $now) {}

    /**
     * The timezone argument to DateTimeImmutable is IGNORED whenever the string carries its own
     * offset, so `at('2026-07-29T23:00:00-05:00')` would otherwise report 2026-07-29 while the UTC
     * instant is 2026-07-30 — a one-day shift, on a clock whose readings drive due dates, VAT period
     * boundaries and month-end rollover. Converting after construction is what makes the Clock port's
     * "implementations must return UTC" contract actually hold.
     */
    public static function at(string $instant): self
    {
        return new self(
            new \DateTimeImmutable($instant, new \DateTimeZone('UTC'))
                ->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** @param string $interval an ISO 8601 duration, e.g. "PT1H" or "P1M" */
    public function advanceBy(string $interval): void
    {
        $this->now = $this->now->add(new \DateInterval($interval));
    }
}
