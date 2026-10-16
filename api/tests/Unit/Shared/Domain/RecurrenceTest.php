<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Exception\InvalidSchedule;
use App\Shared\Domain\Model\Recurrence;
use App\Shared\Domain\Model\Schedule;
use App\Shared\Domain\ValueObject\ScheduleAnchor;
use App\Shared\Domain\ValueObject\ScheduleUnit;
use PHPUnit\Framework\TestCase;

/**
 * Where a recurring document stands in its schedule: due from its next occurrence's day, moving on once drafted, over
 * after its last day, and taken up again after a pause from the next occurrence to come.
 */
final class RecurrenceTest extends TestCase
{
    public function testItIsDueFromItsDayAndMovesOnOnceDrafted(): void
    {
        $recurrence = $this->weekly('2026-10-08');

        self::assertFalse($recurrence->isDueOn(new \DateTimeImmutable('2026-10-07')));
        self::assertTrue($recurrence->isDueOn(new \DateTimeImmutable('2026-10-08')));
        $recurrence->drafted();
        self::assertSame(['2026-10-15', 1], [self::next($recurrence), $recurrence->drafted]);
        self::assertFalse($recurrence->isDueOn(new \DateTimeImmutable('2026-10-14')));
    }

    public function testADayIsComparedAsADayWhateverItsZone(): void
    {
        $recurrence = $this->weekly('2026-10-08');

        // Midnight in Tunis is the evening before in UTC: still the 8th, the company's day.
        self::assertTrue($recurrence->isDueOn(new \DateTimeImmutable('2026-10-08 00:10', new \DateTimeZone('Africa/Tunis'))));
    }

    public function testTheFirstOccurrenceIsTheFirstAnchoredDayFromTheFirstDay(): void
    {
        $recurrence = new Recurrence(new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastDay), new \DateTimeImmutable('2026-10-08'), null, new \DateTimeImmutable('2026-10-08'));

        self::assertSame('2026-10-31', self::next($recurrence));
    }

    public function testPastItsLastDayItIsOver(): void
    {
        $recurrence = new Recurrence(new Schedule(ScheduleUnit::Week, 1), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-08'));
        $recurrence->drafted();
        self::assertSame('2026-10-15', self::next($recurrence));
        $recurrence->drafted();

        self::assertNull(self::next($recurrence));
        self::assertFalse($recurrence->isDueOn(new \DateTimeImmutable('2027-01-01')));
    }

    public function testPausedItIsNeverDueAndTakenUpItSkipsWhatFellDueMeanwhile(): void
    {
        $recurrence = $this->weekly('2026-10-08');
        self::assertSame(['paused'], $recurrence->revise(new Schedule(ScheduleUnit::Week, 1), null, true, new \DateTimeImmutable('2026-10-08')));
        self::assertFalse($recurrence->isDueOn(new \DateTimeImmutable('2026-10-30')));

        self::assertSame(['paused'], $recurrence->revise(new Schedule(ScheduleUnit::Week, 1), null, false, new \DateTimeImmutable('2026-10-18')));

        self::assertSame('2026-10-22', self::next($recurrence));
    }

    public function testPausingStopsItWhereItIs(): void
    {
        $recurrence = $this->weekly('2026-10-08');
        $recurrence->pause();

        self::assertTrue($recurrence->paused);
        self::assertSame('2026-10-08', self::next($recurrence));
        self::assertFalse($recurrence->isDueOn(new \DateTimeImmutable('2026-10-08')));
    }

    public function testANewScheduleStartsFromTheOccurrenceThatWasNext(): void
    {
        $recurrence = $this->weekly('2026-10-08');
        $recurrence->drafted();

        self::assertSame(['schedule'], $recurrence->revise(new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date, monthDay: 15), null, false, new \DateTimeImmutable('2026-10-09')));
        self::assertSame(['2026-10-15', '2026-10-15'], [self::first($recurrence), self::next($recurrence)]);
        $recurrence->drafted();
        self::assertSame('2026-11-15', self::next($recurrence));
    }

    public function testANewScheduleFromAShortMonthKeepsTheDayItIsAnchoredTo(): void
    {
        $recurrence = new Recurrence(new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date, monthDay: 31), new \DateTimeImmutable('2027-01-31'), null, new \DateTimeImmutable('2027-01-31'));
        $recurrence->drafted();
        self::assertSame('2027-02-28', self::next($recurrence));

        $recurrence->revise(new Schedule(ScheduleUnit::Month, 3, ScheduleAnchor::Date, monthDay: 31), null, false, new \DateTimeImmutable('2027-02-10'));
        self::assertSame(['2027-02-28', '2027-02-28'], [self::first($recurrence), self::next($recurrence)]);
        $recurrence->drafted();

        self::assertSame('2027-05-31', self::next($recurrence));
    }

    public function testANewScheduleOnceOverStartsFromToday(): void
    {
        $recurrence = new Recurrence(new Schedule(ScheduleUnit::Week, 1), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable('2026-10-08'));
        $recurrence->drafted();
        self::assertNull(self::next($recurrence));

        self::assertSame(['schedule', 'endsOn'], $recurrence->revise(new Schedule(ScheduleUnit::Day, 2), null, false, new \DateTimeImmutable('2026-11-02')));

        self::assertSame(['2026-11-02', '2026-11-02'], [self::first($recurrence), self::next($recurrence)]);
    }

    public function testANewScheduleTakenUpAfterAPauseStartsFromToday(): void
    {
        $recurrence = $this->weekly('2026-10-08');
        $recurrence->pause();

        $recurrence->revise(new Schedule(ScheduleUnit::Day, 5), null, false, new \DateTimeImmutable('2026-10-20'));

        // Counted from the 8th, every five days would come next on the 23rd: the new schedule counts from today.
        self::assertSame(['2026-10-20', '2026-10-20'], [self::first($recurrence), self::next($recurrence)]);
    }

    public function testTheSameScheduleChangesNothing(): void
    {
        $recurrence = $this->weekly('2026-10-08');

        self::assertSame([], $recurrence->revise(new Schedule(ScheduleUnit::Week, 1), null, false, new \DateTimeImmutable('2026-10-08')));
        self::assertSame(['2026-10-08', '2026-10-08'], [self::first($recurrence), self::next($recurrence)]);
    }

    public function testTheFirstDayIsTodayOrLaterAndTheLastNotBeforeIt(): void
    {
        foreach ([['2026-10-07', null, 'startsOn'], ['2026-10-08', '2026-10-07', 'endsOn']] as [$first, $last, $field]) {
            try {
                new Recurrence(new Schedule(ScheduleUnit::Week, 1), new \DateTimeImmutable($first), null === $last ? null : new \DateTimeImmutable($last), new \DateTimeImmutable('2026-10-08'));
                self::fail("$field was taken");
            } catch (InvalidSchedule $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    public function testALastDayRevisedBeforeTheFirstIsRefused(): void
    {
        $recurrence = $this->weekly('2026-10-08');

        $this->expectExceptionObject(new InvalidSchedule('endsOn', 'The last day comes on or after the first.', 'last_day_before_first'));
        $recurrence->revise(new Schedule(ScheduleUnit::Week, 1), new \DateTimeImmutable('2026-10-07'), false, new \DateTimeImmutable('2026-10-08'));
    }

    /** @phpstan-impure the day moves as the recurrence does */
    private static function next(Recurrence $recurrence): ?string
    {
        return $recurrence->nextOn?->format('Y-m-d');
    }

    /** @phpstan-impure the day moves as the recurrence does */
    private static function first(Recurrence $recurrence): string
    {
        return $recurrence->startsOn->format('Y-m-d');
    }

    private function weekly(string $first): Recurrence
    {
        return new Recurrence(new Schedule(ScheduleUnit::Week, 1), new \DateTimeImmutable($first), null, new \DateTimeImmutable($first));
    }
}
