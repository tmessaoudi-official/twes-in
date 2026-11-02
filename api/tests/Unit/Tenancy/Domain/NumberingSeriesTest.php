<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\Exception\InvalidEstablishment;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Model\NumberingSeries;
use App\Tenancy\Domain\ValueObject\NumberFormat;
use App\Tenancy\Domain\ValueObject\ResetPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NumberingSeries::class)]
#[CoversClass(Establishment::class)]
final class NumberingSeriesTest extends TestCase
{
    public function testANewSeriesStartsAtOneAndPreviewsItsFirstNumber(): void
    {
        $series = self::series();

        self::assertSame(1, $series->getNextNumber());
        self::assertSame('FAC-2026-00001', $series->preview(new \DateTimeImmutable('2026-09-13')));
        self::assertSame('invoice', $series->getDocumentType());
        self::assertSame(ResetPeriod::Yearly, $series->getResetPeriod());
        self::assertTrue($series->isDefault());
    }

    public function testRevisingChangesTheFormatTheResetAndWhereTheSequenceResumes(): void
    {
        $series = self::series();

        self::assertTrue($series->revise(new NumberFormat('F{YY}{MM}-{SEQ:4}'), ResetPeriod::Monthly, 120, new \DateTimeImmutable('+1 hour')));

        self::assertSame('F2609-0120', $series->preview(new \DateTimeImmutable('2026-09-13')));
        self::assertSame(ResetPeriod::Monthly, $series->getResetPeriod());
        self::assertFalse($series->revise(new NumberFormat('F{YY}{MM}-{SEQ:4}'), ResetPeriod::Monthly, 120, new \DateTimeImmutable('+2 hours')));
    }

    public function testTheSequenceNeverResumesBelowOne(): void
    {
        $this->expectException(InvalidNumbering::class);

        self::series()->revise(new NumberFormat('FAC-{SEQ}'), ResetPeriod::Never, 0, new \DateTimeImmutable());
    }

    public function testAnEstablishmentCodeIsShortAndPlain(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');

        $this->expectException(InvalidEstablishment::class);

        Establishment::create($company, 'A B', 'Siège', true, new \DateTimeImmutable());
    }

    public function testAllocatingResumesWhereTheCompanyLeftTheSequenceAndMovesOn(): void
    {
        $series = self::series();
        $series->revise(new NumberFormat('FAC-{YYYY}-{SEQ:5}'), ResetPeriod::Yearly, 41, new \DateTimeImmutable());
        self::assertFalse($series->isNumbered());

        self::assertSame('FAC-2026-00041', $series->allocate(new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable()));
        self::assertSame('FAC-2026-00042', $series->allocate(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable()));

        self::assertTrue($series->isNumbered());
        self::assertSame(43, $series->getNextNumber());
    }

    public function testTheNumberSaidBeforeIssuingIsTheOneAllocatedAndSayingItMovesNothing(): void
    {
        $series = self::series();
        $series->revise(new NumberFormat('FAC-{YYYY}-{SEQ:5}'), ResetPeriod::Yearly, 41, new \DateTimeImmutable());

        self::assertSame('FAC-2026-00041', $series->numberFor(new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable()));
        self::assertSame(41, $series->getNextNumber());
        self::assertFalse($series->isNumbered());
        self::assertSame('FAC-2026-00041', $series->allocate(new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable()));

        self::assertSame('FAC-2027-00001', $series->numberFor(new \DateTimeImmutable('2027-01-02'), new \DateTimeImmutable()), 'a new year starts again at one');
        self::assertSame(42, $series->getNextNumber(), 'said, not taken');
        self::assertSame('FAC-2026-00042', $series->numberFor(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable()));
        $this->expectException(InvalidNumbering::class);
        $series->numberFor(new \DateTimeImmutable('2026-08-31'), new \DateTimeImmutable());
    }

    public function testAYearlySequenceStartsAgainAtOneInANewYear(): void
    {
        $series = self::series();

        self::assertSame('FAC-2026-00001', $series->allocate(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable()));
        self::assertSame('FAC-2026-00002', $series->allocate(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable()));
        self::assertSame('FAC-2027-00001', $series->allocate(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable()));
        self::assertSame('FAC-2027-00002', $series->allocate(new \DateTimeImmutable('2027-06-01'), new \DateTimeImmutable()));
    }

    public function testAMonthlySequenceStartsAgainAtOneInANewMonthOfAnyYear(): void
    {
        $series = self::series();
        $series->revise(new NumberFormat('F{YY}{MM}-{SEQ:3}'), ResetPeriod::Monthly, 1, new \DateTimeImmutable());

        self::assertSame('F2609-001', $series->allocate(new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable()));
        self::assertSame('F2609-002', $series->allocate(new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable()));
        self::assertSame('F2610-001', $series->allocate(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable()));
        self::assertSame('F2710-001', $series->allocate(new \DateTimeImmutable('2027-10-01'), new \DateTimeImmutable()));
    }

    public function testASequenceThatNeverStartsAgainKeepsCountingAcrossYears(): void
    {
        $series = self::series();
        $series->revise(new NumberFormat('FAC-{SEQ:4}'), ResetPeriod::Never, 1, new \DateTimeImmutable());

        self::assertSame('FAC-0001', $series->allocate(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable()));
        self::assertSame('FAC-0002', $series->allocate(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable()));
    }

    public function testANumberIsNeverIssuedOnADayBeforeTheLastOne(): void
    {
        $series = self::series();
        $series->allocate(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable());

        try {
            $series->allocate(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable());
            self::fail('A number was issued in a year the sequence had already left.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('issueDate', $refused->field);
        }
        self::assertSame(2, $series->getNextNumber());
    }

    public function testAnEarlierMonthIsRefusedNamingTheMonthOfTheLastNumber(): void
    {
        $series = self::series();
        $series->allocate(new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable());

        try {
            $series->allocate(new \DateTimeImmutable('2026-11-05'), new \DateTimeImmutable());
            self::fail('A number was issued in a month before the last one.');
        } catch (InvalidNumbering $refused) {
            self::assertSame(['issueDate', 'before_last_period', ['last' => '2026-12-05', 'today' => '2026-11-05']], [$refused->field, $refused->reason, $refused->params]);
        }
    }

    /**
     * The day is the company's today, read from the clock: a day before the last number's, within its month, is a clock set
     * back, and the document it would date before the previous one is refused, saying both days (docs/SPEC.md § 7, Q7).
     */
    public function testWithinAMonthADayBeforeTheLastNumbersDayIsRefusedNamingBoth(): void
    {
        $series = self::series();
        $series->allocate(new \DateTimeImmutable('2026-11-15'), new \DateTimeImmutable());

        try {
            $series->allocate(new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable());
            self::fail('A number was issued on a day before the last one.');
        } catch (InvalidNumbering $refused) {
            self::assertSame(['issueDate', 'before_last_day', ['last' => '2026-11-15', 'today' => '2026-11-14']], [$refused->field, $refused->reason, $refused->params]);
        }
        self::assertSame(2, $series->getNextNumber(), 'nothing taken');
        try {
            $series->numberFor(new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable());
            self::fail('The number said before issuing was given for a day the issue refuses.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('before_last_day', $refused->reason);
        }
        self::assertSame('FAC-2026-00002', $series->allocate(new \DateTimeImmutable('2026-11-15'), new \DateTimeImmutable()), 'the same day again');
        self::assertSame('FAC-2026-00003', $series->allocate(new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable()));
    }

    /**
     * An operator lets the series take an earlier day for a while, the way back from a clock that ran ahead: within the
     * period the sequence counts in, numbers keep growing and none is given twice. Past the moment given, the rule is back.
     */
    public function testAnOperatorsAllowanceLetsAnEarlierDayOfTheSamePeriodUntilItEnds(): void
    {
        $now = new \DateTimeImmutable('2026-11-05 10:00');
        $series = self::series();
        $series->allocate(new \DateTimeImmutable('2026-12-05'), $now);

        $series->allowEarlierDays($now->modify('+2 hours'), $now);
        self::assertSame('FAC-2026-00002', $series->numberFor(new \DateTimeImmutable('2026-11-05'), $now->modify('+1 hour')));
        self::assertSame('FAC-2026-00002', $series->allocate(new \DateTimeImmutable('2026-11-05'), $now->modify('+1 hour')));

        try {
            $series->allocate(new \DateTimeImmutable('2026-11-05'), $now->modify('+3 hours'));
            self::fail('The allowance outlived the moment it was given until.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('before_last_day', $refused->reason);
        }
    }

    /** A monthly sequence starts again each month: back in an earlier month it would give that month's numbers twice. */
    public function testTheAllowanceNeverTakesASeriesBackIntoAnEarlierPeriodItCountsIn(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 10:00');
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $establishment = Establishment::create($company, '000', 'Siège', true, $now);
        $monthly = NumberingSeries::create($company, $establishment, 'invoice', new NumberFormat('FAC-{YYYY}{MM}-{SEQ:4}'), ResetPeriod::Monthly, true, $now);
        $monthly->allocate(new \DateTimeImmutable('2026-12-01'), $now);
        $monthly->allowEarlierDays($now->modify('+1 hour'), $now);

        try {
            $monthly->allocate(new \DateTimeImmutable('2026-11-20'), $now);
            self::fail('A monthly series went back into a month whose numbers it would give again.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('before_last_period', $refused->reason);
        }

        $yearly = self::series();
        $yearly->allocate(new \DateTimeImmutable('2027-01-02'), $now);
        $yearly->allowEarlierDays($now->modify('+1 hour'), $now);
        $this->expectException(InvalidNumbering::class);
        $yearly->allocate(new \DateTimeImmutable('2026-12-31'), $now);
    }

    public function testAnAllowanceIsGivenForAWhileThatHasNotEnded(): void
    {
        $now = new \DateTimeImmutable('2026-11-05 10:00');
        $this->expectException(InvalidNumbering::class);

        self::series()->allowEarlierDays($now, $now);
    }

    public function testOnceADocumentCarriesANumberTheSequenceIsFrozenButItsFormatIsNot(): void
    {
        $series = self::series();
        $series->allocate(new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable());

        try {
            $series->revise(new NumberFormat('FAC-{YYYY}-{SEQ:5}'), ResetPeriod::Yearly, 1, new \DateTimeImmutable());
            self::fail('The sequence was moved back after a document carried a number.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('nextNumber', $refused->field);
        }
        try {
            $series->revise(new NumberFormat('FAC-{YYYY}-{SEQ:5}'), ResetPeriod::Yearly, 9, new \DateTimeImmutable());
            self::fail('The sequence skipped numbers after a document carried a number.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('nextNumber', $refused->field);
        }

        self::assertTrue($series->revise(new NumberFormat('F{YY}-{SEQ:4}'), ResetPeriod::Yearly, 2, new \DateTimeImmutable()));
        self::assertSame('F26-0002', $series->preview(new \DateTimeImmutable('2026-09-14')));
    }

    public function testASeriesThatStartsAgainPrintsThePeriodItStartsAgainIn(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $establishment = Establishment::create($company, '000', 'Siège', true, new \DateTimeImmutable());
        foreach ([
            'a yearly reset without the year' => ['FAC-{SEQ:5}', ResetPeriod::Yearly],
            'a monthly reset without the month' => ['F{YYYY}-{SEQ}', ResetPeriod::Monthly],
            'a monthly reset without the year' => ['F{MM}-{SEQ}', ResetPeriod::Monthly],
        ] as $case => [$pattern, $reset]) {
            try {
                NumberingSeries::create($company, $establishment, 'invoice', new NumberFormat($pattern), $reset, true, new \DateTimeImmutable());
                self::fail("a series was created with $case, whose first number of the new period is the old period's first");
            } catch (InvalidNumbering $refused) {
                self::assertSame('format', $refused->field, $case);
            }
            $series = self::series();
            try {
                $series->revise(new NumberFormat($pattern), $reset, 1, new \DateTimeImmutable());
                self::fail("a series was revised to $case");
            } catch (InvalidNumbering $refused) {
                self::assertSame('format', $refused->field, $case);
            }
            self::assertSame('FAC-{YYYY}-{SEQ:5}', $series->getFormat(), $case);
        }

        self::assertTrue(self::series()->revise(new NumberFormat('F{MM}{YY}-{SEQ}'), ResetPeriod::Monthly, 1, new \DateTimeImmutable()));
        self::assertTrue(self::series()->revise(new NumberFormat('F{YY}-{SEQ}'), ResetPeriod::Yearly, 1, new \DateTimeImmutable()));
        self::assertTrue(self::series()->revise(new NumberFormat('FAC-{SEQ}'), ResetPeriod::Never, 1, new \DateTimeImmutable()));
    }

    private static function series(): NumberingSeries
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $establishment = Establishment::create($company, '000', 'Siège', true, new \DateTimeImmutable());

        return NumberingSeries::create($company, $establishment, 'invoice', new NumberFormat('FAC-{YYYY}-{SEQ:5}'), ResetPeriod::Yearly, true, new \DateTimeImmutable());
    }
}
