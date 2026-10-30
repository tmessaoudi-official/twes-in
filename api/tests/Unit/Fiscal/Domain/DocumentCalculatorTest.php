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

    public function testADeductionTakesNoLineDiscountBecauseTheDepositAlreadyDid(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessageMatches('/deduction/');
        $this->calculate([
            new LineInput('1', '100.000', null, [$this->vat('19')]),
            new LineInput('1', '30.000', '10', [$this->vat('19')], deduction: true, deductedTaxes: ['TVA' => '5.700']),
        ]);
    }

    public function testDeductionsBeyondTheDocumentAreRefusedByName(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessageMatches('/deduction/');
        $this->calculate([
            new LineInput('1', '100.000', null, [$this->vat('19')]),
            new LineInput('1', '100.001', null, [$this->vat('19')], deduction: true, deductedTaxes: ['TVA' => '19.000']),
        ]);
    }

    public function testDeductionsEqualToTheDocumentLeaveNothingDue(): void
    {
        $totals = new DocumentCalculator()->calculate(new DocumentInput(3, false, TaxBasis::Exclusive, RoundingPoint::PerRateGroup, [
            new LineInput('1', '100.000', null, [$this->vat('19')]),
            new LineInput('1', '100.000', null, [$this->vat('19')], deduction: true, deductedTaxes: ['TVA' => '19.000']),
        ]));
        self::assertSame('0.000', $totals->amountDue);
    }

    public function testTheDocumentDiscountIsMeasuredAgainstTheChargedLinesAlone(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessageMatches('/discount/');
        $this->calculate([
            new LineInput('1', '100.000', null, [$this->vat('19')]),
            new LineInput('1', '30.000', null, [$this->vat('19')], deduction: true, deductedTaxes: ['TVA' => '5.700']),
        ], '100.001');
    }

    public function testOnlyADeductionStatesWhatADepositCharged(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->expectExceptionMessageMatches('/only a deduction/');
        $this->calculate([new LineInput('1', '100.000', null, [$this->vat('19')], deductedTaxes: ['TVA' => '19.000'])]);
    }

    public function testWhatADepositChargedIsNeverFinerThanTheCurrency(): void
    {
        $this->expectException(InvalidDocument::class);
        $this->calculate([
            new LineInput('1', '100.000', null, [$this->vat('19')]),
            new LineInput('1', '30.000', null, [$this->vat('19')], deduction: true, deductedTaxes: ['TVA' => '5.7001']),
        ]);
    }

    public function testWhatTheDiscountsSaveIsEveryLineDiscountAndTheDocumentDiscount(): void
    {
        $totals = new DocumentCalculator()->calculate(new DocumentInput(3, false, TaxBasis::Exclusive, RoundingPoint::PerRateGroup, [
            new LineInput('3', '12.500', '10', [$this->vat('19')]),
            new LineInput('2', '20.000', null, [$this->vat('19')], discountAmount: '5.000'),
            new LineInput('1', '8.000', null, [$this->vat('19')]),
        ], '2.000'));

        self::assertSame('10.750', $totals->savings());
    }

    public function testADocumentWithoutAnyDiscountSavesNothing(): void
    {
        $totals = new DocumentCalculator()->calculate(new DocumentInput(3, false, TaxBasis::Exclusive, RoundingPoint::PerRateGroup, [
            new LineInput('1', '8.000', null, [$this->vat('19')]),
        ]));

        self::assertSame('0.000', $totals->savings());
    }

    public function testANegativeFigureRoundingToZeroIsWrittenAsZero(): void
    {
        self::assertSame('0.000', Decimal::format(new Number('-0.0004'), 3));
    }

    /**
     * A correction completing its document takes what is left of each percentage tax, so its corrections add up to what
     * the document charged rather than each rounding alone; spread over its lines as a rate group is.
     */
    public function testACorrectionCompletingItsDocumentTakesWhatIsLeftOfATax(): void
    {
        $line = new LineInput('1', '33.335', null, [$this->vat('19')]);
        $alone = (new DocumentCalculator())->calculate(new DocumentInput(3, true, TaxBasis::Exclusive, RoundingPoint::PerRateGroup, [$line]));
        self::assertSame('-6.334', $alone->taxes[0]->amount);

        $left = (new DocumentCalculator())->calculate(new DocumentInput(3, true, TaxBasis::Exclusive, RoundingPoint::PerRateGroup, [$line, $line], taxesLeft: ['TVA' => '12.666']));
        self::assertSame(['-12.666', '-79.336'], [$left->taxes[0]->amount, $left->amountDue]);
        self::assertSame(['-6.333', '-6.333'], array_map(static fn ($each) => $each->taxes[0]->amount, $left->lines));
    }

    public function testWhatIsLeftOfATaxIsRefusedOnADocumentEnteredTaxIncluded(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('What is left of a tax is given to a correction entered net of tax only.');
        (new DocumentCalculator())->calculate(new DocumentInput(3, true, TaxBasis::Inclusive, RoundingPoint::PerRateGroup, [new LineInput('1', '39.669', null, [$this->vat('19')])], taxesLeft: ['TVA' => '6.333']));
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
