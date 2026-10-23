<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Domain;

use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    /** @return iterable<string, array{SettingDefinition, mixed, string, array<string, string|int>}> */
    public static function refused(): iterable
    {
        yield 'a string for a bool' => [self::of(SettingType::Bool, false), 'true', 'not_yes_or_no', []];
        yield 'a float for an int' => [self::of(SettingType::Int, 30), 30.5, 'not_a_whole_number', []];
        yield 'an int below its minimum' => [self::of(SettingType::Int, 30, min: 0, max: 365), -1, 'whole_number_between', ['min' => 0, 'max' => 365]];
        yield 'an int above its maximum' => [self::of(SettingType::Int, 30, min: 0, max: 365), 366, 'whole_number_between', ['min' => 0, 'max' => 365]];
        yield 'an int below its only bound' => [self::of(SettingType::Int, 30, min: 1), 0, 'at_least', ['min' => 1]];
        yield 'an int above its only bound' => [self::of(SettingType::Int, 3, max: 5), 6, 'at_most', ['max' => 5]];
        yield 'a float for a decimal' => [self::of(SettingType::Decimal, '0.000'), 1.5, 'not_a_number', []];
        yield 'a decimal written with a comma' => [self::of(SettingType::Decimal, '0.000'), '1,5', 'not_a_number', []];
        yield 'a decimal above its maximum' => [self::of(SettingType::Decimal, '0', min: '0', max: '100'), '100.001', 'number_between', ['min' => '0', 'max' => '100']];
        yield 'a decimal below its only bound' => [self::of(SettingType::Money, '0', min: '0'), '-1', 'at_least', ['min' => '0']];
        yield 'money as a number' => [self::of(SettingType::Money, '0.000'), 12, 'not_a_number', []];
        yield 'text longer than allowed' => [self::of(SettingType::Text, '', maxLength: 5), 'abcdef', 'invalid_text', ['max' => 5]];
        yield 'text outside its pattern' => [self::of(SettingType::Text, 'C62', pattern: '/^[A-Z0-9]{3}$/'), 'kilo', 'invalid_unit_code', []];
        yield 'a choice nobody declared' => [self::of(SettingType::Enum, 'light', choices: ['light', 'dark']), 'purple', 'unknown_choice', []];
        yield 'a colour name' => [self::of(SettingType::Colour, '#1f6feb'), 'blue', 'not_a_colour', []];
        yield 'a three-digit colour' => [self::of(SettingType::Colour, '#1f6feb'), '#fff', 'not_a_colour', []];
        yield 'a scalar for json' => [self::of(SettingType::Json, null), 'x', 'invalid_value', []];
        yield 'json too large to store' => [self::of(SettingType::Json, null), ['blob' => str_repeat('x', 40000)], 'invalid_value', []];
        yield 'null' => [self::of(SettingType::Text, ''), null, 'invalid_value', []];
    }

    /**
     * A refusal is said on its field in the person's words: a reason the screens translate and that text's parameters,
     * never an English sentence a screen would have to read.
     *
     * @param array<string, string|int> $params
     */
    #[DataProvider('refused')]
    public function testAValueOfTheWrongShapeIsRefusedWithItsReason(SettingDefinition $definition, mixed $value, string $reason, array $params): void
    {
        $refusal = $definition->refusal($value);

        self::assertNotNull($refusal);
        self::assertSame([$reason, $params], [$refusal->reason, $refusal->params]);
    }

    /** Each refusal a definition can answer, with its parameters: what the screens' texts are held to. */
    public function testADefinitionNamesEveryRefusalItCanAnswer(): void
    {
        $reasons = static fn (SettingDefinition $definition): array => array_map(static fn ($refusal): array => [$refusal->reason, $refusal->params], $definition->refusals());

        self::assertSame([['not_a_whole_number', []], ['whole_number_between', ['min' => 0, 'max' => 365]]], $reasons(self::of(SettingType::Int, 30, min: 0, max: 365)));
        self::assertSame([['invalid_value', []], ['invalid_text', ['max' => 3]], ['invalid_unit_code', []]], $reasons(self::of(SettingType::Text, 'C62', maxLength: 3, pattern: '/^[A-Z0-9]{3}$/')));
    }

    /** A pattern says what it refuses with: its own sentence, the format and an example, never a bare « refusé ». */
    public function testAPatternDeclaresTheReasonItRefusesWith(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SettingDefinition('document.example', SettingType::Text, 'C62', SettingChain::Parties, [SettingLevel::Company], 'settings.example', 'core', pattern: '/^[A-Z0-9]{3}$/');
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
        self::assertSame([SettingLevel::Platform, SettingLevel::Company, SettingLevel::Establishment, SettingLevel::ProductCategory, SettingLevel::Product, SettingLevel::DocumentLine], SettingChain::Articles->levels());
        self::assertSame([SettingLevel::Platform, SettingLevel::Company, SettingLevel::Role, SettingLevel::User], SettingChain::Presentation->levels());
    }

    /** @param list<string> $choices */
    private static function of(SettingType $type, mixed $default, array $choices = [], int|string|null $min = null, int|string|null $max = null, ?int $maxLength = null, ?string $pattern = null): SettingDefinition
    {
        return new SettingDefinition('document.example', $type, $default, SettingChain::Parties, [SettingLevel::Company], 'settings.example', 'core', choices: $choices, min: $min, max: $max, maxLength: $maxLength, pattern: $pattern, patternReason: null === $pattern ? null : 'invalid_unit_code');
    }
}
