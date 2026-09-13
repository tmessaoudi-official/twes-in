<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\InvalidFiscalValue;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxComponentTest extends TestCase
{
    private Company $company;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-09-13 10:00:00');
    }

    public function testTheKindFollowsTheFamilyAndDecimalsAreWrittenAsTheDatabaseReturnsThem(): void
    {
        $vat = $this->vat();

        self::assertSame(TaxKind::PercentageLine, $vat->getKind());
        self::assertSame('19.000', $vat->getRate());
        self::assertNull($vat->getAmount());
        self::assertTrue($vat->isActive());
    }

    public function testAStampHasAnAmountAndNoRate(): void
    {
        $stamp = TaxComponent::create($this->company, 'TIMBRE', 'Timbre', TaxFamily::Stamp, null, '1', null, false, true, null, 50, 3, $this->now);

        self::assertSame(TaxKind::FixedDocument, $stamp->getKind());
        self::assertSame('1.000', $stamp->getAmount());
    }

    /** @return iterable<string, array{string, ?string, ?string, ?string, bool, string}> */
    public static function refusals(): iterable
    {
        yield 'a percentage tax with no rate' => ['vat', null, null, null, false, 'rate'];
        yield 'a rate over 100' => ['vat', '100.5', null, null, false, 'rate'];
        yield 'a negative rate' => ['vat', '-1', null, null, false, 'rate'];
        yield 'a rate with four decimals' => ['vat', '5.1234', null, null, false, 'rate'];
        yield 'a percentage tax with an amount' => ['vat', '19', '1', null, false, 'amount'];
        yield 'a stamp with a rate' => ['stamp', '1', '1', null, false, 'rate'];
        yield 'a stamp with no amount' => ['stamp', null, null, null, false, 'amount'];
        yield 'a withholding with no threshold' => ['withholding', '1', null, null, false, 'threshold'];
        yield 'a VAT rate entering the VAT base' => ['vat', '19', null, null, true, 'entersVatBase'];
    }

    #[DataProvider('refusals')]
    public function testAnInconsistentComponentIsRefusedNamingTheField(string $family, ?string $rate, ?string $amount, ?string $threshold, bool $entersVatBase, string $field): void
    {
        try {
            TaxComponent::create($this->company, 'X1', 'X', TaxFamily::from($family), $rate, $amount, $threshold, $entersVatBase, false, null, 0, 3, $this->now);
            self::fail('expected InvalidFiscalValue');
        } catch (InvalidFiscalValue $refused) {
            self::assertSame($field, $refused->field);
        }
    }

    public function testAnAmountFinerThanTheCurrencyIsRefused(): void
    {
        $this->expectException(InvalidFiscalValue::class);
        TaxComponent::create(new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris'), 'STAMP', 'Stamp', TaxFamily::Stamp, null, '1.005', null, false, false, null, 0, 2, $this->now);
    }

    public function testACodeIsCapitalsDigitsAndUnderscores(): void
    {
        $this->expectException(InvalidFiscalValue::class);
        TaxComponent::create($this->company, 'tva 19', 'TVA', TaxFamily::Vat, '19', null, null, false, false, null, 0, 3, $this->now);
    }

    public function testARevisionThatChangesNothingReportsNothing(): void
    {
        $vat = $this->vat();

        self::assertFalse($vat->revise('TVA 19 %', '19.000', null, null, false, false, true, null, 0, 3, $this->now->modify('+1 day')));
        self::assertEquals($this->now, $vat->getUpdatedAt());
    }

    public function testARevisionChangesTheRateAndTheTimestamp(): void
    {
        $vat = $this->vat();
        $later = $this->now->modify('+1 day');

        self::assertTrue($vat->revise('TVA 19 %', '18', null, null, false, false, false, ' Exonération ', 0, 3, $later));
        self::assertSame('18.000', $vat->getRate());
        self::assertFalse($vat->isActive());
        self::assertSame('Exonération', $vat->getExemptionMention());
        self::assertEquals($later, $vat->getUpdatedAt());
    }

    public function testARefusedRevisionLeavesTheComponentAsItWas(): void
    {
        $vat = $this->vat();

        try {
            $vat->revise('Renamed', '101', null, null, false, false, true, null, 0, 3, $this->now);
        } catch (InvalidFiscalValue) {
        }

        self::assertSame('TVA 19 %', $vat->getName());
        self::assertSame('19.000', $vat->getRate());
    }

    private function vat(): TaxComponent
    {
        return TaxComponent::create($this->company, 'TVA19', 'TVA 19 %', TaxFamily::Vat, '19', null, null, false, false, null, 0, 3, $this->now);
    }
}
