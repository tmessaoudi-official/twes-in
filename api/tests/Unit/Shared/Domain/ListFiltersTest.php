<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\DateRange;
use App\Shared\Domain\DecimalRange;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListFiltersTest extends TestCase
{
    public function testSeveralValuesAndTheSingleValueAreTheSameFilter(): void
    {
        $allowed = ['draft', 'issued', 'overdue'];

        self::assertSame(['draft', 'overdue'], new ListFilters(['status' => ['draft', 'overdue']])->choices('status', $allowed));
        self::assertSame(['draft'], new ListFilters(['status' => 'draft'])->choices('status', $allowed), 'the old single value still works');
        self::assertSame(['draft'], new ListFilters(['status' => ['draft', 'draft']])->choices('status', $allowed), 'a value named twice is one');
        self::assertSame([], new ListFilters([])->choices('status', $allowed), 'no value is no filter, not an empty one');
        self::assertSame([], new ListFilters(['status' => ['', ' ']])->choices('status', $allowed), 'a blank value is none');
    }

    public function testAValueThatIsNoneOfTheFiltersIsRefusedNotDropped(): void
    {
        $this->expectException(InvalidFilter::class);
        $this->expectExceptionMessage('status');

        new ListFilters(['status' => ['draft', 'paid-ish']])->choices('status', ['draft', 'issued']);
    }

    public function testCodesOfOneShapeAreReadOnceAndAnyOtherShapeIsRefused(): void
    {
        self::assertSame(['TN', 'FR'], new ListFilters(['countryCode' => ['TN', 'FR', 'TN']])->matching('countryCode', '/^[A-Z]{2}$/'));
        self::assertSame(['TN'], new ListFilters(['countryCode' => 'TN'])->matching('countryCode', '/^[A-Z]{2}$/'), 'the old single value still works');
        $this->expectException(InvalidFilter::class);
        $this->expectExceptionMessage('countryCode');

        new ListFilters(['countryCode' => ['TN', 'tunisia']])->matching('countryCode', '/^[A-Z]{2}$/');
    }

    public function testIdentifiersAreReadWhateverTheirCaseAndRefusedWhenTheyAreNone(): void
    {
        $id = '0192a5a0-7e58-7cd2-a8c8-2f1f0f4b0c11';

        self::assertSame([$id], array_map(static fn ($uuid): string => $uuid->toRfc4122(), new ListFilters(['customerId' => [$id, strtoupper($id)]])->uuids('customerId')));
        $this->expectException(InvalidFilter::class);
        new ListFilters(['customerId' => ['nope']])->uuids('customerId');
    }

    public function testAnIntervalHasEitherEndOrBothAndIsNoneWhenAbsent(): void
    {
        self::assertEquals(new DateRange('2026-10-01', '2026-10-31'), new ListFilters(['issueDate' => ['from' => '2026-10-01', 'to' => '2026-10-31']])->dateRange('issueDate'));
        self::assertEquals(new DateRange('2026-10-01', null), new ListFilters(['issueDate' => ['from' => '2026-10-01']])->dateRange('issueDate'));
        self::assertTrue(new ListFilters([])->dateRange('issueDate')->isOpen());
        self::assertEquals(new DecimalRange('0', '1250.5'), new ListFilters(['totalGross' => ['min' => '0', 'max' => '1250.5']])->decimalRange('totalGross'));
        self::assertTrue(new ListFilters(['totalGross' => ['min' => '']])->decimalRange('totalGross')->isOpen(), 'a blank end is no end');
        self::assertEquals(new DateRange('2026-10-05', '2026-10-05'), new ListFilters(['dueDate' => ['from' => '2026-10-05', 'to' => '2026-10-05']])->dateRange('dueDate'), 'one day is an interval');
    }

    /** @param array<array-key, mixed> $given */
    #[DataProvider('refusedIntervals')]
    public function testAnIntervalThatIsNotOneIsRefused(string $kind, array $given): void
    {
        $this->expectException(InvalidFilter::class);

        $filters = new ListFilters(['x' => $given]);
        'day' === $kind ? $filters->dateRange('x') : $filters->decimalRange('x');
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function refusedIntervals(): iterable
    {
        yield 'not a day' => ['day', ['from' => 'yesterday']];
        yield 'a day that does not exist' => ['day', ['to' => '2026-02-30']];
        yield 'a day with a time' => ['day', ['from' => '2026-10-05T10:00']];
        yield 'ends before it starts' => ['day', ['from' => '2026-10-06', 'to' => '2026-10-05']];
        yield 'not an amount' => ['amount', ['min' => 'abc']];
        yield 'an exponent' => ['amount', ['max' => '1e3']];
        yield 'a negative amount' => ['amount', ['min' => '-1']];
        yield 'a leading zero' => ['amount', ['min' => '012']];
        yield 'a comma decimal' => ['amount', ['min' => '1,5']];
        yield 'amounts ending before they start' => ['amount', ['min' => '10', 'max' => '9.999']];
        yield 'a scalar for an interval' => ['amount', ['min' => ['1']]];
    }

    public function testAnIntervalGivenAsAScalarIsRefused(): void
    {
        $this->expectException(InvalidFilter::class);

        new ListFilters(['issueDate' => '2026-10-05'])->dateRange('issueDate');
    }
}
