<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Model;

use App\Shared\Domain\Exception\InvalidSchedule;
use App\Shared\Domain\ValueObject\ScheduleAnchor;
use App\Shared\Domain\ValueObject\ScheduleUnit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How often something recurs: every so many days, weeks, months or years. A month or a year falls on the day it is
 * anchored to, kept as asked rather than read from the first day, so a series moved onto a clamped day (the 28th of
 * February for the 31st) keeps its 31st. What the anchor does not read is left out, so two equal schedules compare equal.
 */
#[ORM\Embeddable]
final class Schedule
{
    public const int MOST_APART = 99;

    #[ORM\Column(length: 8, enumType: ScheduleUnit::class)]
    public private(set) ScheduleUnit $unit;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $every;

    #[ORM\Column(length: 16, nullable: true, enumType: ScheduleAnchor::class)]
    public private(set) ?ScheduleAnchor $anchor;

    /** The date of the month a date anchor keeps, 1 to 31. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $monthDay;

    /** Which of the month's weekdays, the first to the fourth. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $ordinal;

    /** The day of the week, 1 for Monday to 7 for Sunday (ISO 8601). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $weekday;

    /** @throws InvalidSchedule */
    public function __construct(ScheduleUnit $unit, int $every, ?ScheduleAnchor $anchor = null, ?int $monthDay = null, ?int $ordinal = null, ?int $weekday = null)
    {
        if ($every < 1 || $every > self::MOST_APART) {
            throw new InvalidSchedule('every', \sprintf('A schedule recurs every 1 to %d.', self::MOST_APART));
        }
        if ($unit->isAnchored() !== (null !== $anchor)) {
            throw new InvalidSchedule('anchor', 'A month or a year falls on a day it is anchored to; a day or a week has none.');
        }
        $this->unit = $unit;
        $this->every = $every;
        $this->anchor = $anchor;
        $this->monthDay = ScheduleAnchor::Date === $anchor ? self::within('monthDay', $monthDay, 31) : null;
        $this->ordinal = ScheduleAnchor::Weekday === $anchor ? self::within('ordinal', $ordinal, 4) : null;
        $this->weekday = ScheduleAnchor::Weekday === $anchor || ScheduleAnchor::LastWeekday === $anchor ? self::within('weekday', $weekday, 7) : null;
    }

    /**
     * The day of the `$index`-th occurrence, counted from 0. A day or a week counts from the first day; a month or a
     * year counts periods from the first day's month, starting one period on when the anchored day of that month falls
     * before the first day, so no occurrence ever falls before it.
     */
    public function occurrence(\DateTimeImmutable $first, int $index): \DateTimeImmutable
    {
        $first = self::day($first);
        if (!$this->unit->isAnchored()) {
            $days = $this->every * $index * (ScheduleUnit::Week === $this->unit ? 7 : 1);

            return $first->modify(\sprintf('+%d days', $days));
        }
        $months = ScheduleUnit::Year === $this->unit ? 12 * $this->every : $this->every;
        $month = $first->modify('first day of this month');
        $periods = $index + ($this->in($month) < $first ? 1 : 0);

        return $this->in($month->modify(\sprintf('+%d months', $periods * $months)));
    }

    public function equals(self $other): bool
    {
        return [$this->unit, $this->every, $this->anchor, $this->monthDay, $this->ordinal, $this->weekday]
            === [$other->unit, $other->every, $other->anchor, $other->monthDay, $other->ordinal, $other->weekday];
    }

    /** The anchored day of the month that `$month`, its first day, starts. */
    private function in(\DateTimeImmutable $month): \DateTimeImmutable
    {
        $length = (int) $month->format('t');
        $day = match ($this->anchor) {
            ScheduleAnchor::Date => min((int) $this->monthDay, $length),
            ScheduleAnchor::Weekday => 1 + ((int) $this->weekday - (int) $month->format('N') + 7) % 7 + 7 * ((int) $this->ordinal - 1),
            ScheduleAnchor::LastWeekday => $length - ((int) $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $length)->format('N') - (int) $this->weekday + 7) % 7,
            default => $length,
        };

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
    }

    /** @throws InvalidSchedule */
    private static function within(string $field, ?int $value, int $most): int
    {
        if (null === $value || $value < 1 || $value > $most) {
            throw new InvalidSchedule($field, \sprintf('The %s is a whole number from 1 to %d.', $field, $most));
        }

        return $value;
    }

    /** A calendar day, compared as a day: the date as written, at midnight UTC, whatever zone it was read in. */
    private static function day(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
