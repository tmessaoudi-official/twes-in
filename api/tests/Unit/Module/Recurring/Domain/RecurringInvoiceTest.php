<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recurring\Domain;

use App\Module\Recurring\Domain\InvalidRecurringInvoice;
use App\Module\Recurring\Domain\RecurringFrequency;
use App\Module\Recurring\Domain\RecurringInvoice;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A recurring invoice's occurrences: counted from the first day, a week or whole months apart, a month keeping the
 * first day's date where it has one; over after the last day; taken up again from the next occurrence to come.
 */
final class RecurringInvoiceTest extends TestCase
{
    /** @return iterable<string, array{RecurringFrequency, string, int, string}> */
    public static function occurrences(): iterable
    {
        yield 'a week' => [RecurringFrequency::Weekly, '2026-10-08', 3, '2026-10-29'];
        yield 'a month' => [RecurringFrequency::Monthly, '2026-10-08', 1, '2026-11-08'];
        yield 'the 31st in February' => [RecurringFrequency::Monthly, '2027-01-31', 1, '2027-02-28'];
        yield 'the 31st again in March' => [RecurringFrequency::Monthly, '2027-01-31', 2, '2027-03-31'];
        yield 'a leap year' => [RecurringFrequency::Monthly, '2028-01-30', 1, '2028-02-29'];
        yield 'a quarter' => [RecurringFrequency::Quarterly, '2026-11-30', 1, '2027-02-28'];
        yield 'a year from the 29th of February' => [RecurringFrequency::Yearly, '2028-02-29', 1, '2029-02-28'];
        yield 'the first is the first day' => [RecurringFrequency::Yearly, '2026-10-08', 0, '2026-10-08'];
    }

    #[DataProvider('occurrences')]
    public function testAnOccurrenceKeepsTheFirstDaysDate(RecurringFrequency $frequency, string $first, int $index, string $day): void
    {
        self::assertSame($day, $frequency->occurrence(new \DateTimeImmutable($first), $index)->format('Y-m-d'));
    }

    public function testItIsDueFromItsDayAndMovesOnOnceDrafted(): void
    {
        $recurring = $this->weekly('2026-10-08');

        self::assertFalse($recurring->isDueOn(new \DateTimeImmutable('2026-10-07')));
        self::assertTrue($recurring->isDueOn(new \DateTimeImmutable('2026-10-08')));
        $recurring->drafted(Uuid::v7(), new \DateTimeImmutable());
        self::assertSame(['2026-10-15', 1], [$recurring->getNextOn()?->format('Y-m-d'), $recurring->getDrafted()]);
        self::assertFalse($recurring->isDueOn(new \DateTimeImmutable('2026-10-14')));
    }

    public function testADayIsComparedAsADayWhateverItsZone(): void
    {
        $recurring = $this->weekly('2026-10-08');

        // Midnight in Tunis is the evening before in UTC: still the 8th, the company's day.
        self::assertTrue($recurring->isDueOn(new \DateTimeImmutable('2026-10-08 00:10', new \DateTimeZone('Africa/Tunis'))));
    }

    public function testPastItsLastDayItIsOver(): void
    {
        $recurring = new RecurringInvoice($this->company(), Uuid::v7(), RecurringFrequency::Weekly, new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable());
        $recurring->drafted(Uuid::v7(), new \DateTimeImmutable());
        $recurring->drafted(Uuid::v7(), new \DateTimeImmutable());

        self::assertNull($recurring->getNextOn());
        self::assertFalse($recurring->isDueOn(new \DateTimeImmutable('2027-01-01')));
    }

    public function testPausedItIsNeverDueAndTakenUpItSkipsWhatFellDueMeanwhile(): void
    {
        $recurring = $this->weekly('2026-10-08');
        self::assertSame(['paused'], $recurring->revise(RecurringFrequency::Weekly, null, true, new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable()));
        self::assertFalse($recurring->isDueOn(new \DateTimeImmutable('2026-10-30')));

        $recurring->revise(RecurringFrequency::Weekly, null, false, new \DateTimeImmutable('2026-10-18'), new \DateTimeImmutable());

        self::assertSame('2026-10-22', $recurring->getNextOn()?->format('Y-m-d'));
    }

    public function testANewFrequencyStartsFromTheOccurrenceThatWasNext(): void
    {
        $recurring = $this->weekly('2026-10-08');
        $recurring->drafted(Uuid::v7(), new \DateTimeImmutable());

        self::assertSame(['frequency'], $recurring->revise(RecurringFrequency::Monthly, null, false, new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable()));
        self::assertSame(['2026-10-15', '2026-10-15'], [$recurring->getStartsOn()->format('Y-m-d'), $recurring->getNextOn()?->format('Y-m-d')]);
        $recurring->drafted(Uuid::v7(), new \DateTimeImmutable());
        self::assertSame('2026-11-15', $recurring->getNextOn()?->format('Y-m-d'));
    }

    public function testTheFirstDayIsTodayOrLaterAndTheLastNotBeforeIt(): void
    {
        foreach ([['2026-10-07', null, 'startsOn'], ['2026-10-08', '2026-10-07', 'endsOn']] as [$first, $last, $field]) {
            try {
                new RecurringInvoice($this->company(), Uuid::v7(), RecurringFrequency::Monthly, new \DateTimeImmutable($first), null === $last ? null : new \DateTimeImmutable($last), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable());
                self::fail("$field was taken");
            } catch (InvalidRecurringInvoice $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    private function weekly(string $first): RecurringInvoice
    {
        return new RecurringInvoice($this->company(), Uuid::v7(), RecurringFrequency::Weekly, new \DateTimeImmutable($first), null, new \DateTimeImmutable($first), new \DateTimeImmutable());
    }

    private function company(): Company
    {
        return new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
