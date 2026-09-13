<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Domain;

use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    /** @return iterable<string, array{SettingDefinition, mixed}> */
    public static function refused(): iterable
    {
        yield 'a string for a bool' => [self::of(SettingType::Bool, false), 'true'];
        yield 'a float for an int' => [self::of(SettingType::Int, 30), 30.5];
        yield 'an int below its minimum' => [self::of(SettingType::Int, 30, min: 0, max: 365), -1];
        yield 'an int above its maximum' => [self::of(SettingType::Int, 30, min: 0, max: 365), 366];
        yield 'a float for a decimal' => [self::of(SettingType::Decimal, '0.000'), 1.5];
        yield 'a decimal written with a comma' => [self::of(SettingType::Decimal, '0.000'), '1,5'];
        yield 'a decimal above its maximum' => [self::of(SettingType::Decimal, '0', min: '0', max: '100'), '100.001'];
        yield 'money as a number' => [self::of(SettingType::Money, '0.000'), 12];
        yield 'text longer than allowed' => [self::of(SettingType::Text, '', maxLength: 5), 'abcdef'];
        yield 'text outside its pattern' => [self::of(SettingType::Text, 'C62', pattern: '/^[A-Z0-9]{3}$/'), 'kilo'];
        yield 'a choice nobody declared' => [self::of(SettingType::Enum, 'light', choices: ['light', 'dark']), 'purple'];
        yield 'a colour name' => [self::of(SettingType::Colour, '#1f6feb'), 'blue'];
        yield 'a three-digit colour' => [self::of(SettingType::Colour, '#1f6feb'), '#fff'];
        yield 'a scalar for json' => [self::of(SettingType::Json, null), 'x'];
        yield 'json too large to store' => [self::of(SettingType::Json, null), ['blob' => str_repeat('x', 40000)]];
        yield 'null' => [self::of(SettingType::Text, ''), null];
    }

    #[DataProvider('refused')]
    public function testAValueOfTheWrongShapeIsRefused(SettingDefinition $definition, mixed $value): void
    {
        self::assertNotNull($definition->refusal($value));
    }

    public function testValuesOfTheRightShapeAreAccepted(): void
    {
        self::assertNull(self::of(SettingType::Bool, false)->refusal(true));
        self::assertNull(self::of(SettingType::Int, 30, min: 0, max: 365)->refusal(365));
        self::assertNull(self::of(SettingType::Decimal, '0.000', min: '0', max: '100')->refusal('19.500'));
        self::assertNull(self::of(SettingType::Money, '0.000')->refusal('-12.345'));
        self::assertNull(self::of(SettingType::Text, '', maxLength: 5)->refusal('abcde'));
        self::assertNull(self::of(SettingType::Text, 'C62', pattern: '/^[A-Z0-9]{3}$/')->refusal('KGM'));
        self::assertNull(self::of(SettingType::Enum, 'light', choices: ['light', 'dark'])->refusal('dark'));
        self::assertNull(self::of(SettingType::Colour, '#1f6feb')->refusal('#A0B1C2'));
        self::assertNull(self::of(SettingType::Json, null)->refusal(['hidden' => ['email']]));
        self::assertNull(self::of(SettingType::Json, null)->refusal([]));
    }

    public function testAColourIsKeptInLowerCase(): void
    {
        self::assertSame('#a0b1c2', self::of(SettingType::Colour, '#1f6feb')->normalize('#A0B1C2'));
        self::assertSame('dark', self::of(SettingType::Enum, 'light', choices: ['light', 'dark'])->normalize('dark'));
    }

    public function testADefinitionWhoseDefaultItWouldRefuseCannotBeDeclared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::of(SettingType::Enum, 'purple', choices: ['light', 'dark']);
    }

    public function testALevelOutsideItsChainCannotBeDeclared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SettingDefinition('presentation.density', SettingType::Enum, 'comfortable', SettingChain::Presentation, [SettingLevel::Customer], 'settings.presentation.density', 'core', choices: ['comfortable', 'compact']);
    }

    public function testAPatternDefinitionCoversEveryKeyOfItsShapeAndNothingElse(): void
    {
        $list = new SettingDefinition('presentation.list.<id>', SettingType::Json, null, SettingChain::Presentation, [SettingLevel::User], 'settings.presentation.list', 'core', keyPattern: '/^presentation\.list\.[a-z][a-z0-9-]*$/');

        self::assertTrue($list->isPattern());
        self::assertTrue($list->matches('presentation.list.members'));
        self::assertFalse($list->matches('presentation.list.members.views'));
        self::assertFalse($list->matches('presentation.list.<id>'));
        self::assertFalse($list->matches('presentation.density'));
    }

    public function testEachChainRunsFromTheMostGeneralLevel(): void
    {
        self::assertSame([SettingLevel::Platform, SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document], SettingChain::Parties->levels());
        self::assertSame([SettingLevel::Platform, SettingLevel::Company, SettingLevel::ProductCategory, SettingLevel::Product, SettingLevel::DocumentLine], SettingChain::Articles->levels());
        self::assertSame([SettingLevel::Platform, SettingLevel::Company, SettingLevel::Role, SettingLevel::User], SettingChain::Presentation->levels());
    }

    /** @param list<string> $choices */
    private static function of(SettingType $type, mixed $default, array $choices = [], int|string|null $min = null, int|string|null $max = null, ?int $maxLength = null, ?string $pattern = null): SettingDefinition
    {
        return new SettingDefinition('document.example', $type, $default, SettingChain::Parties, [SettingLevel::Company], 'settings.example', 'core', choices: $choices, min: $min, max: $max, maxLength: $maxLength, pattern: $pattern);
    }
}
