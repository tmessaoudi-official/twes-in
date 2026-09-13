<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use BcMath\Number;
use PHPUnit\Framework\TestCase;

/** What the calculator refuses, which no pricing vector can express as a total. */
final class DocumentCalculatorTest extends TestCase
{
    public function testANegativeQuantityIsRefusedBecauseTheSignBelongsToTheDocument(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([new LineInput('-1', '10.000', null, [$this->vat('19')])]);
    }

    public function testADiscountRateAboveAHundredIsRefused(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([new LineInput('1', '10.000', '101', [$this->vat('19')])]);
    }

    public function testAFixedChargeIsRefusedOnALine(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([new LineInput('1', '10.000', null, [TaxInput::fixed('TIMBRE', '1.000')])]);
    }

    public function testAPercentageTaxIsRefusedAtDocumentLevel(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([new LineInput('1', '10.000')], documentTaxes: [$this->vat('19')]);
    }

    public function testOneCodeNamingTwoRatesIsRefused(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([
            new LineInput('1', '10.000', null, [$this->vat('19')]),
            new LineInput('1', '10.000', null, [$this->vat('7')]),
        ]);
    }

    public function testADocumentDiscountLargerThanTheDocumentIsRefused(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([new LineInput('1', '10.000', null, [$this->vat('19')])], '10.001');
    }

    public function testANegativeFigureRoundingToZeroIsWrittenAsZero(): void
    {
        self::assertSame('0.000', Decimal::format(new Number('-0.0004'), 3));
    }

    private function vat(string $rate): TaxInput
    {
        return TaxInput::percentage('TVA', Rate::fromPercentage($rate));
    }

    /**
     * @param list<LineInput> $lines
     * @param list<TaxInput>  $documentTaxes
     */
    private function calculate(array $lines, ?string $documentDiscount = null, array $documentTaxes = []): void
    {
        (new DocumentCalculator())->calculate(new DocumentInput(3, false, TaxBasis::Exclusive, RoundingPoint::PerRateGroup, $lines, $documentDiscount, $documentTaxes));
    }
}
