<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recurring\Domain;

use App\Module\Recurring\Domain\RecurringInvoice;
use App\Shared\Domain\InvalidSchedule;
use App\Shared\Domain\Schedule;
use App\Shared\Domain\ScheduleAnchor;
use App\Shared\Domain\ScheduleUnit;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A recurring invoice on the shared schedule: the schedules the migration writes for the four frequencies it once had
 * keep the days those gave; each draft is remembered as the last; a new schedule starts from the occurrence that was
 * next; a first day already past or a last day before it is refused on its field.
 */
final class RecurringInvoiceTest extends TestCase
{
    /**
     * What weekly, monthly, quarterly and yearly became: a week, one or three months, or a year, each month or year on
     * the first day's date.
     *
     * @return iterable<string, array{string, int, string, int, string}>
     */
    public static function formerFrequencies(): iterable
    {
        yield 'weekly' => ['week', 1, '2026-10-08', 3, '2026-10-29'];
        yield 'monthly' => ['month', 1, '2026-10-08', 1, '2026-11-08'];
        yield 'monthly, the 31st in February' => ['month', 1, '2027-01-31', 1, '2027-02-28'];
        yield 'monthly, the 31st again in March' => ['month', 1, '2027-01-31', 2, '2027-03-31'];
        yield 'monthly, a leap year' => ['month', 1, '2028-01-30', 1, '2028-02-29'];
        yield 'quarterly' => ['month', 3, '2026-11-30', 1, '2027-02-28'];
        yield 'yearly from the 29th of February' => ['year', 1, '2028-02-29', 1, '2029-02-28'];
        yield 'yearly, the first is the first day' => ['year', 1, '2026-10-08', 0, '2026-10-08'];
    }

    #[DataProvider('formerFrequencies')]
    public function testTheScheduleAFormerFrequencyBecameKeepsItsDays(string $unit, int $every, string $first, int $index, string $day): void
    {
        $unit = ScheduleUnit::from($unit);
        $schedule = $unit->isAnchored()
            ? new Schedule($unit, $every, ScheduleAnchor::Date, (int) new \DateTimeImmutable($first)->format('j'))
            : new Schedule($unit, $every);

        self::assertSame($day, $schedule->occurrence(new \DateTimeImmutable($first), $index)->format('Y-m-d'));
    }

    public function testEachDraftIsTheLastAndTheNextOccurrenceComesNext(): void
    {
        $recurring = $this->made(new Schedule(ScheduleUnit::Week, 1), '2026-10-08');
        self::assertTrue($recurring->isDueOn(new \DateTimeImmutable('2026-10-08')));

        $draft = Uuid::v7();
        $recurring->drafted($draft, new \DateTimeImmutable());

        self::assertSame([$draft->toRfc4122(), '2026-10-15', 1], [$recurring->getLastInvoiceId()?->toRfc4122(), $recurring->getRecurrence()->nextOn?->format('Y-m-d'), $recurring->getRecurrence()->drafted]);
        self::assertFalse($recurring->isDueOn(new \DateTimeImmutable('2026-10-14')));
    }

    public function testANewScheduleStartsFromTheOccurrenceThatWasNextAndSaysWhatChanged(): void
    {
        $recurring = $this->made(new Schedule(ScheduleUnit::Week, 1), '2026-10-08');
        $recurring->drafted(Uuid::v7(), new \DateTimeImmutable());

        $lastFriday = new Schedule(ScheduleUnit::Month, 2, ScheduleAnchor::LastWeekday, null, null, 5);
        self::assertSame(['schedule', 'paused'], $recurring->revise($lastFriday, null, true, new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable()));
        self::assertFalse($recurring->isDueOn(new \DateTimeImmutable('2026-12-31')), 'paused, it is never due');

        self::assertSame(['paused'], $recurring->revise($lastFriday, null, false, new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable()));
        self::assertSame('2026-10-30', $recurring->getRecurrence()->nextOn?->format('Y-m-d'), 'the last Friday of the month the next occurrence fell in');
        self::assertSame([], $recurring->revise($lastFriday, null, false, new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable()));
    }

    public function testTheFirstDayIsTodayOrLaterAndTheLastNotBeforeIt(): void
    {
        foreach ([['2026-10-07', null, 'startsOn'], ['2026-10-08', '2026-10-07', 'endsOn']] as [$first, $last, $field]) {
            try {
                new RecurringInvoice($this->company(), Uuid::v7(), new Schedule(ScheduleUnit::Week, 1), new \DateTimeImmutable($first), null === $last ? null : new \DateTimeImmutable($last), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable());
                self::fail("$field was taken");
            } catch (InvalidSchedule $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    private function made(Schedule $schedule, string $first): RecurringInvoice
    {
        return new RecurringInvoice($this->company(), Uuid::v7(), $schedule, new \DateTimeImmutable($first), null, new \DateTimeImmutable($first), new \DateTimeImmutable());
    }

    private function company(): Company
    {
        return new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
