<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Exception\InvalidFiscalValue;
use App\Fiscal\Domain\Model\Unit;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    private Company $company;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable());
        $this->now = new \DateTimeImmutable('2026-09-13 10:00:00');
    }

    public function testAUnitIsCreatedActive(): void
    {
        $unit = Unit::create($this->company, 'KGM', ' Kilogramme ', 3, 40, $this->now);

        self::assertSame('KGM', $unit->getCode());
        self::assertSame('Kilogramme', $unit->getName());
        self::assertSame(3, $unit->getDecimals());
        self::assertTrue($unit->isActive());
    }

    /** @return iterable<string, array{string, string, int, int, string, string, array<string, int>}> */
    public static function refusals(): iterable
    {
        yield 'a lower-case code' => ['kgm', 'Kilogramme', 3, 0, 'code', 'invalid_value', []];
        yield 'a four-letter code' => ['KGMS', 'Kilogramme', 3, 0, 'code', 'invalid_value', []];
        yield 'a blank name' => ['KGM', '  ', 3, 0, 'name', 'value_required', []];
        yield 'a name past 60 characters' => ['KGM', str_repeat('k', 61), 3, 0, 'name', 'invalid_text', ['max' => 60]];
        yield 'four decimals' => ['KGM', 'Kilogramme', 4, 0, 'decimals', 'whole_number_between', ['min' => 0, 'max' => 3]];
        yield 'negative decimals' => ['KGM', 'Kilogramme', -1, 0, 'decimals', 'whole_number_between', ['min' => 0, 'max' => 3]];
        yield 'a negative sort order' => ['KGM', 'Kilogramme', 3, -1, 'sortOrder', 'not_negative', []];
    }

    /** @param array<string, int> $params */
    #[DataProvider('refusals')]
    public function testAnInvalidUnitIsRefusedOnTheFieldWithItsReason(string $code, string $name, int $decimals, int $sortOrder, string $field, string $reason, array $params): void
    {
        try {
            Unit::create($this->company, $code, $name, $decimals, $sortOrder, $this->now);
            self::fail('expected InvalidFiscalValue');
        } catch (InvalidFiscalValue $refused) {
            self::assertSame([$field, $reason, $params], [$refused->field, $refused->reason, $refused->params]);
        }
    }

    public function testARevisionReportsWhetherAnythingChanged(): void
    {
        $unit = Unit::create($this->company, 'HUR', 'Heure', 2, 20, $this->now);
        $later = $this->now->modify('+1 day');

        self::assertFalse($unit->revise('Heure', 2, true, 20, $later));
        self::assertEquals($this->now, $unit->getUpdatedAt());
        self::assertTrue($unit->revise('Heure', 2, false, 20, $later));
        self::assertFalse($unit->isActive());
        self::assertEquals($later, $unit->getUpdatedAt());
    }

    public function testADocumentPrintsTheNameInItsLanguageAndTheNameWhereItHasNone(): void
    {
        $unit = Unit::create($this->company, 'C62', 'Unité', 0, 10, $this->now, ['ar' => ' وحدة ', 'en' => 'Unit']);

        self::assertSame(['ar' => 'وحدة', 'en' => 'Unit'], $unit->getNames());
        self::assertSame('وحدة', $unit->nameIn('ar'));
        self::assertSame('Unité', $unit->nameIn('fr'));
    }

    public function testANameLeftBlankInALanguageIsDroppedSoTheNamePrints(): void
    {
        $unit = Unit::create($this->company, 'C62', 'Unité', 0, 10, $this->now, ['ar' => '  ', 'en' => 'Unit']);

        self::assertSame(['en' => 'Unit'], $unit->getNames());
        self::assertSame('Unité', $unit->nameIn('ar'));
    }

    public function testARevisionOfTheNamesAloneIsAChangeAndLeavingThemOutKeepsThem(): void
    {
        $unit = Unit::create($this->company, 'C62', 'Unité', 0, 10, $this->now, ['ar' => 'وحدة']);
        $later = $this->now->modify('+1 day');

        self::assertFalse($unit->revise('Unité', 0, true, 10, $later));
        self::assertSame(['ar' => 'وحدة'], $unit->getNames());
        self::assertTrue($unit->revise('Unité', 0, true, 10, $later, ['ar' => 'قطعة']));
        self::assertSame('قطعة', $unit->nameIn('ar'));
        self::assertEquals($later, $unit->getUpdatedAt());
    }

    /** @return iterable<string, array{array<array-key, string>, string, array<string, int>}> */
    public static function badNames(): iterable
    {
        yield 'a name past 60 characters' => [['ar' => str_repeat('و', 61)], 'invalid_text', ['max' => 60]];
        yield 'a list rather than names by language' => [['وحدة'], 'invalid_value', []];
        yield 'a blank language' => [[' ' => 'وحدة'], 'invalid_value', []];
    }

    /**
     * @param array<array-key, string> $names
     * @param array<string, int>       $params
     */
    #[DataProvider('badNames')]
    public function testNamesThatAreNotByLanguageOrTooLongAreRefusedWithTheirReason(array $names, string $reason, array $params): void
    {
        try {
            Unit::create($this->company, 'C62', 'Unité', 0, 10, $this->now, $names);
            self::fail('expected InvalidFiscalValue');
        } catch (InvalidFiscalValue $refused) {
            self::assertSame(['names', $reason, $params], [$refused->field, $refused->reason, $refused->params]);
        }
    }

    public function testAUnitFromBeforeNamesByLanguageIsGivenThemOnceAndNeverAgain(): void
    {
        $unit = Unit::create($this->company, 'C62', 'Unité', 0, 10, $this->now);
        self::assertFalse($unit->awaitsLanguageNames());

        $reflection = new \ReflectionProperty(Unit::class, 'names');
        $reflection->setValue($unit, null);
        self::assertTrue($unit->awaitsLanguageNames());
        self::assertSame([], $unit->getNames());

        self::assertTrue($unit->giveLanguageNames(['ar' => 'وحدة']));
        self::assertSame('وحدة', $unit->nameIn('ar'));
        self::assertFalse($unit->giveLanguageNames(['ar' => 'قطعة']));
        self::assertSame('وحدة', $unit->nameIn('ar'));
    }
}
