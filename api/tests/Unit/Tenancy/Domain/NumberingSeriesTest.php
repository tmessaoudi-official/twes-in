<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\InvalidEstablishment;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberFormat;
use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\ResetPeriod;
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
