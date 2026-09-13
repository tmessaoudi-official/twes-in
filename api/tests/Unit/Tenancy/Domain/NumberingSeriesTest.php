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

    private static function series(): NumberingSeries
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $establishment = Establishment::create($company, '000', 'Siège', true, new \DateTimeImmutable());

        return NumberingSeries::create($company, $establishment, 'invoice', new NumberFormat('FAC-{YYYY}-{SEQ:5}'), ResetPeriod::Yearly, true, new \DateTimeImmutable());
    }
}
