<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * Steps dated in the past, run in date order with the clock set to each step's moment. Issuing takes its date from
 * the clock and a numbering series refuses to go back a month, so a company's documents must be written the way
 * they would have been: day by day. Steps on the same day keep the order they were planned in.
 */
final class Timeline
{
    /** @var list<array{at: \DateTimeImmutable, step: \Closure(): void}> */
    private array $steps = [];

    /** @param \Closure(): void $settle run after each step: what keeps a long run's unit of work small */
    public function __construct(private readonly \DateTimeImmutable $today, private readonly \Closure $settle)
    {
    }

    /**
     * Plans a step `$days` from today (negative: in the past), at a working hour of that day.
     *
     * @param \Closure(): void $step
     */
    public function at(int $days, \Closure $step): void
    {
        if ($days > 0) {
            throw new \LogicException('A demo step cannot happen after today.');
        }
        $this->steps[] = ['at' => $this->day($days)->setTime(10, 0), 'step' => $step];
    }

    /** The calendar day `$days` from today, in the company's timezone. */
    public function day(int $days): \DateTimeImmutable
    {
        return $this->today->modify(\sprintf('%+d days', $days));
    }

    /** Runs every step; the caller gives the clock back. */
    public function run(): void
    {
        $steps = $this->steps;
        // usort is stable since PHP 8.0: same-day steps keep their planned order.
        usort($steps, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        foreach ($steps as ['at' => $at, 'step' => $step]) {
            Clock::set(new MockClock($at));
            $step();
            ($this->settle)();
        }
    }
}
