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

    public static function at(string $instant): self
    {
        return new self(new \DateTimeImmutable($instant, new \DateTimeZone('UTC')));
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
