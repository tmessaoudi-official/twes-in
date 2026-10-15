<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a recurring document stands in its schedule. Its occurrences count from the first day up to an optional last
 * day; the day of the next one is kept beside the count so a worker finds what is due without reading every schedule.
 * Paused, it is never due, and taking it up again starts from the next occurrence to come, never the ones missed
 * meanwhile. A new schedule starts from the occurrence that was next, or from today once it had ended.
 */
#[ORM\Embeddable]
final class Recurrence
{
    #[ORM\Embedded(class: Schedule::class, columnPrefix: 'schedule_')]
    public private(set) Schedule $schedule;

    /** The day occurrences are counted from. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public private(set) \DateTimeImmutable $startsOn;

    /** The last day an occurrence may fall on; null when it goes on. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $endsOn;

    /** The occurrence to draft next, counted from the first. */
    #[ORM\Column]
    public private(set) int $nextIndex = 0;

    /** The day of that occurrence; null once past the last day. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $nextOn;

    /** How many occurrences have been drafted. */
    #[ORM\Column]
    public private(set) int $drafted = 0;

    #[ORM\Column]
    public private(set) bool $paused = false;

    /** @throws InvalidSchedule */
    public function __construct(Schedule $schedule, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, \DateTimeImmutable $today)
    {
        if (self::day($startsOn) < self::day($today)) {
            throw new InvalidSchedule('startsOn', 'The first occurrence falls today or later, never on a day already past.', 'first_day_past');
        }
        $this->schedule = $schedule;
        $this->startsOn = self::day($startsOn);
        $this->endsOn = self::lastDay($this->startsOn, $endsOn);
        $this->nextOn = $this->occurrenceDay($this->nextIndex);
    }

    public function isDueOn(\DateTimeImmutable $today): bool
    {
        return !$this->paused && null !== $this->nextOn && self::day($this->nextOn) <= self::day($today);
    }

    /** The occurrence that was due has its draft: the next one comes next. */
    public function drafted(): void
    {
        ++$this->nextIndex;
        ++$this->drafted;
        $this->nextOn = $this->occurrenceDay($this->nextIndex);
    }

    /**
     * Changes the schedule, the last day and whether it is paused.
     *
     * @return list<string> the fields that changed
     *
     * @throws InvalidSchedule
     */
    public function revise(Schedule $schedule, ?\DateTimeImmutable $endsOn, bool $paused, \DateTimeImmutable $today): array
    {
        $today = self::day($today);
        $changed = [];
        if (!$schedule->equals($this->schedule)) {
            $from = null === $this->nextOn ? $today : self::day($this->nextOn);
            $this->startsOn = $from < $today ? $today : $from;
            $this->nextIndex = 0;
            $this->schedule = $schedule;
            $changed[] = 'schedule';
        }
        $endsOn = self::lastDay($this->startsOn, $endsOn);
        if ($endsOn?->format('Y-m-d') !== $this->endsOn?->format('Y-m-d')) {
            $this->endsOn = $endsOn;
            $changed[] = 'endsOn';
        }
        if ($paused !== $this->paused) {
            $this->paused = $paused;
            $changed[] = 'paused';
        }
        if (!$this->paused) {
            while (null !== ($day = $this->occurrenceDay($this->nextIndex)) && $day < $today) {
                ++$this->nextIndex;
            }
        }
        $this->nextOn = $this->occurrenceDay($this->nextIndex);

        return $changed;
    }

    /** It stops drafting where it is, as when what it copies can no longer be copied. */
    public function pause(): void
    {
        $this->paused = true;
    }

    /** The occurrence's day, or null when it falls after the last day. */
    private function occurrenceDay(int $index): ?\DateTimeImmutable
    {
        $day = $this->schedule->occurrence($this->startsOn, $index);

        return null !== $this->endsOn && $day > self::day($this->endsOn) ? null : $day;
    }

    /** @throws InvalidSchedule */
    private static function lastDay(\DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn): ?\DateTimeImmutable
    {
        if (null === $endsOn) {
            return null;
        }
        $endsOn = self::day($endsOn);
        if ($endsOn < $startsOn) {
            throw new InvalidSchedule('endsOn', 'The last day comes on or after the first.', 'last_day_before_first');
        }

        return $endsOn;
    }

    /** A calendar day, compared as a day: the date as written, at midnight UTC, whatever zone it was read in. */
    private static function day(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
