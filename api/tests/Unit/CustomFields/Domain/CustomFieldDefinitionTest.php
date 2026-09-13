<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\CustomFields\Domain;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldRule;
use App\CustomFields\Domain\CustomFieldType;
use App\CustomFields\Domain\InvalidCustomFieldDefinition;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomFieldDefinitionTest extends TestCase
{
    private Company $company;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-09-14 09:00:00');
    }

    public function testAFieldIsDeclaredForOneKindOfRecordAndGivesItsRule(): void
    {
        $field = CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'sector', '  Secteur  ', CustomFieldType::Choice, true, [' retail ', 'wholesale'], 2, $this->now);

        self::assertSame(['sector', 'Secteur', CustomFieldEntity::Customer, 2, true], [$field->getKey(), $field->getLabel(), $field->getEntity(), $field->getSortOrder(), $field->isActive()]);
        self::assertEquals(new CustomFieldRule('sector', CustomFieldType::Choice, true, ['retail', 'wholesale']), $field->rule());
    }

    /** @return iterable<string, array{string, string, CustomFieldType, list<string>}> */
    public static function refused(): iterable
    {
        yield 'a key with capitals' => ['key', 'Sector', CustomFieldType::Text, []];
        yield 'a key starting with a digit' => ['key', '1st_order', CustomFieldType::Text, []];
        yield 'a key longer than 40' => ['key', str_repeat('k', 41), CustomFieldType::Text, []];
        yield 'a choice field without choices' => ['choices', 'sector', CustomFieldType::Choice, []];
        yield 'choices on a text field' => ['choices', 'sector', CustomFieldType::Text, ['a']];
        yield 'the same choice twice' => ['choices', 'sector', CustomFieldType::Choice, ['a', ' a']];
        yield 'an empty choice' => ['choices', 'sector', CustomFieldType::Choice, ['a', '']];
        yield 'more than 50 choices' => ['choices', 'sector', CustomFieldType::Choice, array_map(static fn (int $n): string => "c$n", range(1, 51))];
    }

    /** @param list<string> $choices */
    #[DataProvider('refused')]
    public function testRefusesAMalformedField(string $field, string $key, CustomFieldType $type, array $choices): void
    {
        try {
            CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, $key, 'Label', $type, false, $choices, 0, $this->now);
        } catch (InvalidCustomFieldDefinition $refused) {
            self::assertSame($field, $refused->field);

            return;
        }
        self::fail('The field was accepted.');
    }

    public function testALabelIsOneToEightyCharacters(): void
    {
        foreach (['   ', str_repeat('l', 81)] as $label) {
            try {
                CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'sector', $label, CustomFieldType::Text, false, [], 0, $this->now);
                self::fail("The label \"$label\" was accepted.");
            } catch (InvalidCustomFieldDefinition $refused) {
                self::assertSame('label', $refused->field);
            }
        }
    }

    public function testARevisionNamesWhatChangedAndRetiringKeepsTheKeyAndType(): void
    {
        $field = CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'sector', 'Secteur', CustomFieldType::Choice, false, ['retail'], 0, $this->now);

        self::assertSame([], $field->revise('Secteur', false, ['retail'], 0, true, $this->now));
        self::assertSame(['label', 'required', 'choices', 'sortOrder', 'isActive'], $field->revise('Activité', true, ['retail', 'export'], 1, false, $this->now));
        self::assertEquals(new CustomFieldRule('sector', CustomFieldType::Choice, true, ['retail', 'export'], false), $field->rule());

        $this->expectException(InvalidCustomFieldDefinition::class);
        $field->revise('Activité', true, [], 1, false, $this->now);
    }
}
