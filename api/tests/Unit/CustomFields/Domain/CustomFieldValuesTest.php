<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\CustomFields\Domain;

use App\CustomFields\Domain\CustomFieldRule;
use App\CustomFields\Domain\CustomFieldType;
use App\CustomFields\Domain\CustomFieldValues;
use App\CustomFields\Domain\InvalidCustomFieldValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomFieldValuesTest extends TestCase
{
    public function testKeepsWellTypedValuesAndDropsEmptyOptionalOnes(): void
    {
        $values = CustomFieldValues::checked(self::rules(), [
            'sector' => 'retail',
            'account_manager' => '  Leila  ',
            'credit_limit' => 1500.5,
            'client_since' => '2024-02-29',
            'vip' => false,
        ], []);

        self::assertSame(['sector' => 'retail', 'account_manager' => 'Leila', 'credit_limit' => 1500.5, 'client_since' => '2024-02-29', 'vip' => false], $values);
        self::assertSame(
            ['sector' => 'wholesale', 'credit_limit' => 20],
            CustomFieldValues::checked(self::rules(), ['sector' => 'wholesale', 'account_manager' => '', 'credit_limit' => 20, 'client_since' => null], []),
        );
    }

    public function testRefusesAKeyNoFieldDeclares(): void
    {
        self::assertRefused('colour', static fn () => CustomFieldValues::checked(self::rules(), ['sector' => 'retail', 'colour' => 'blue'], []), 'unknown_custom_field', ['key' => 'colour']);
    }

    public function testRefusesARequiredFieldLeftEmpty(): void
    {
        self::assertRefused('sector', static fn () => CustomFieldValues::checked(self::rules(), ['sector' => ''], []), 'value_required');
        self::assertRefused('sector', static fn () => CustomFieldValues::checked(self::rules(), [], ['sector' => 'retail']), 'value_required');
    }

    /** @return iterable<string, array{string, mixed, string, array<string, string|int>}> */
    public static function mistyped(): iterable
    {
        $choices = ['choices' => 'retail, wholesale'];
        yield 'a choice not offered' => ['sector', 'export', 'not_one_of', $choices];
        yield 'a choice that is not a string' => ['sector', 1, 'not_one_of', $choices];
        yield 'text that is a number' => ['account_manager', 12, 'invalid_text', ['max' => 2000]];
        yield 'text too long' => ['account_manager', str_repeat('a', 2001), 'invalid_text', ['max' => 2000]];
        yield 'a number written as text' => ['credit_limit', '12', 'not_a_number', []];
        yield 'a number that is a boolean' => ['credit_limit', true, 'not_a_number', []];
        yield 'a date that does not exist' => ['client_since', '2026-02-30', 'not_a_date', []];
        yield 'a date in another format' => ['client_since', '14/09/2026', 'not_a_date', []];
        yield 'a boolean written as text' => ['vip', 'yes', 'not_a_boolean', []];
    }

    /** @param array<string, string|int> $params */
    #[DataProvider('mistyped')]
    public function testRefusesAValueOfTheWrongType(string $key, mixed $value, string $code, array $params): void
    {
        self::assertRefused($key, static fn () => CustomFieldValues::checked(self::rules(), ['sector' => 'retail', $key => $value], []), $code, $params);
    }

    public function testARetiredFieldKeepsWhatWasStoredAndIgnoresWhatIsSent(): void
    {
        $rules = [...self::rules(), new CustomFieldRule('legacy_code', CustomFieldType::Text, true, [], false)];

        self::assertSame(
            ['sector' => 'retail', 'legacy_code' => 'A-1'],
            CustomFieldValues::checked($rules, ['sector' => 'retail', 'legacy_code' => 'B-2'], ['legacy_code' => 'A-1']),
        );
        self::assertSame(['sector' => 'retail'], CustomFieldValues::checked($rules, ['sector' => 'retail'], []));
    }

    public function testAStoredValueResentUnchangedStaysAfterItsChoiceIsWithdrawn(): void
    {
        self::assertSame(['sector' => 'export'], CustomFieldValues::checked(self::rules(), ['sector' => 'export'], ['sector' => 'export']));
        self::assertRefused('sector', static fn () => CustomFieldValues::checked(self::rules(), ['sector' => 'export'], ['sector' => 'retail']), 'not_one_of', ['choices' => 'retail, wholesale']);
    }

    /** @return list<CustomFieldRule> */
    private static function rules(): array
    {
        return [
            new CustomFieldRule('sector', CustomFieldType::Choice, true, ['retail', 'wholesale']),
            new CustomFieldRule('account_manager', CustomFieldType::Text, false),
            new CustomFieldRule('credit_limit', CustomFieldType::Number, false),
            new CustomFieldRule('client_since', CustomFieldType::Date, false),
            new CustomFieldRule('vip', CustomFieldType::Bool, false),
        ];
    }

    /** @param array<string, string|int> $params */
    private static function assertRefused(string $key, \Closure $check, string $code, array $params = []): void
    {
        try {
            $check();
        } catch (InvalidCustomFieldValue $refused) {
            self::assertSame('customFields.'.$key, $refused->field);
            self::assertSame([$code, $params], [$refused->reason, $refused->params]);
            self::assertNotSame('', $refused->getMessage());

            return;
        }
        self::fail("The value of $key was accepted.");
    }
}
