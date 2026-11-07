<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Exception\InvalidFiscalValue;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxComponentTest extends TestCase
{
    private Company $company;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable());
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

    /** @return iterable<string, array{string, ?string, ?string, ?string, bool, string, string, array<string, int>}> */
    public static function refusals(): iterable
    {
        yield 'a percentage tax with no rate' => ['vat', null, null, null, false, 'rate', 'value_required', []];
        yield 'a rate over 100' => ['vat', '100.5', null, null, false, 'rate', 'at_most', ['max' => 100]];
        yield 'a rate of a thousand' => ['vat', '1000', null, null, false, 'rate', 'at_most', ['max' => 100]];
        yield 'a negative rate' => ['vat', '-1', null, null, false, 'rate', 'not_negative', []];
        yield 'a rate with four decimals' => ['vat', '5.1234', null, null, false, 'rate', 'too_many_decimals', ['max' => 3]];
        yield 'a rate written as a word' => ['vat', 'ten', null, null, false, 'rate', 'not_a_number', []];
        yield 'a rate in scientific notation, which BcMath cannot read' => ['vat', '1e3', null, null, false, 'rate', 'not_a_number', []];
        yield 'a percentage tax with an amount' => ['vat', '19', '1', null, false, 'amount', 'invalid_value', []];
        yield 'a stamp with a rate' => ['stamp', '1', '1', null, false, 'rate', 'invalid_value', []];
        yield 'a stamp with no amount' => ['stamp', null, null, null, false, 'amount', 'value_required', []];
        yield 'a withholding with no threshold' => ['withholding', '1', null, null, false, 'threshold', 'value_required', []];
        yield 'a VAT rate entering the VAT base' => ['vat', '19', null, null, true, 'entersVatBase', 'invalid_value', []];
    }

    /** @param array<string, int> $params */
    #[DataProvider('refusals')]
    public function testAnInconsistentComponentIsRefusedOnTheFieldWithItsReason(string $family, ?string $rate, ?string $amount, ?string $threshold, bool $entersVatBase, string $field, string $reason, array $params): void
    {
        self::assertSame([$field, $reason, $params], self::refusalOf(fn () => TaxComponent::create($this->company, 'X1', 'X', TaxFamily::from($family), $rate, $amount, $threshold, $entersVatBase, false, null, 0, 3, $this->now)));
    }

    public function testAnAmountFinerThanTheCurrencyIsRefusedWithTheCurrencysDecimals(): void
    {
        $dupont = new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris', new \DateTimeImmutable());

        self::assertSame(['amount', 'too_many_decimals', ['max' => 2]], self::refusalOf(fn () => TaxComponent::create($dupont, 'STAMP', 'Stamp', TaxFamily::Stamp, null, '1.005', null, false, false, null, 0, 2, $this->now)));
    }

    /** @return iterable<string, array{string, string, ?string, int, string, string, array<string, int>}> */
    public static function textRefusals(): iterable
    {
        yield 'a code in lower case and spaces' => ['tva 19', 'TVA', null, 0, 'code', 'invalid_value', []];
        yield 'a blank name' => ['X1', "  \u{200B}", null, 0, 'name', 'value_required', []];
        yield 'a name past 120 characters' => ['X1', str_repeat('T', 121), null, 0, 'name', 'invalid_text', ['max' => 120]];
        yield 'a mention past 500 characters' => ['X1', 'TVA', str_repeat('m', 501), 0, 'exemptionMention', 'invalid_text', ['max' => 500]];
        yield 'a negative sort order' => ['X1', 'TVA', null, -1, 'sortOrder', 'not_negative', []];
    }

    /** @param array<string, int> $params */
    #[DataProvider('textRefusals')]
    public function testAWrongCodeNameMentionOrOrderIsRefusedOnTheFieldWithItsReason(string $code, string $name, ?string $mention, int $sortOrder, string $field, string $reason, array $params): void
    {
        self::assertSame([$field, $reason, $params], self::refusalOf(fn () => TaxComponent::create($this->company, $code, $name, TaxFamily::Vat, '19', null, null, false, false, $mention, $sortOrder, 3, $this->now)));
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

    public function testAStampPrintsItsNameInTheDocumentsLanguage(): void
    {
        $stamp = TaxComponent::create($this->company, 'TIMBRE', 'Droit de timbre', TaxFamily::Stamp, null, '1', null, false, true, null, 50, 3, $this->now, ['ar' => 'معلوم الطابع الجبائي']);

        self::assertSame('معلوم الطابع الجبائي', $stamp->nameIn('ar'));
        self::assertSame('Droit de timbre', $stamp->nameIn('en'));
    }

    public function testARevisionOfTheNamesAloneIsAChangeAndARefusedOneLeavesThemAsTheyWere(): void
    {
        $vat = $this->vat();
        $later = $this->now->modify('+1 day');

        self::assertTrue($vat->revise('TVA 19 %', '19', null, null, false, false, true, null, 0, 3, $later, ['en' => 'VAT 19%']));
        self::assertSame(['en' => 'VAT 19%'], $vat->getNames());
        self::assertFalse($vat->revise('TVA 19 %', '19', null, null, false, false, true, null, 0, 3, $later));

        try {
            $vat->revise('TVA 19 %', '20', null, null, false, false, true, null, 0, 3, $later, ['en' => str_repeat('V', 121)]);
            self::fail('expected InvalidFiscalValue');
        } catch (InvalidFiscalValue $refused) {
            self::assertSame(['names', 'invalid_text', ['max' => 120]], [$refused->field, $refused->reason, $refused->params]);
        }
        self::assertSame('19.000', $vat->getRate());
        self::assertSame(['en' => 'VAT 19%'], $vat->getNames());
    }

    /**
     * The field, reason and params a creation is refused with.
     *
     * @return array{string, ?string, array<string, string|int>}
     */
    private static function refusalOf(callable $create): array
    {
        try {
            $create();
        } catch (InvalidFiscalValue $refused) {
            return [$refused->field, $refused->reason, $refused->params];
        }
        self::fail('expected InvalidFiscalValue');
    }

    private function vat(): TaxComponent
    {
        return TaxComponent::create($this->company, 'TVA19', 'TVA 19 %', TaxFamily::Vat, '19', null, null, false, false, null, 0, 3, $this->now);
    }
}
