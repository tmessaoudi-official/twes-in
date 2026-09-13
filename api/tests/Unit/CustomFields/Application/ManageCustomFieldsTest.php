<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\CustomFields\Application;

use App\CustomFields\Application\CustomFieldInput;
use App\CustomFields\Application\CustomFieldKeyTaken;
use App\CustomFields\Application\CustomFieldNotFound;
use App\CustomFields\Application\ManageCustomFields;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldRule;
use App\CustomFields\Domain\CustomFieldType;
use App\CustomFields\Domain\InvalidCustomFieldDefinition;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomFieldDefinitions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageCustomFieldsTest extends TestCase
{
    private InMemoryCustomFieldDefinitions $fields;
    private InMemoryAuditTrail $audit;
    private ManageCustomFields $manage;
    private Company $company;

    protected function setUp(): void
    {
        $this->fields = new InMemoryCustomFieldDefinitions();
        $this->audit = new InMemoryAuditTrail();
        $this->manage = new ManageCustomFields($this->fields, $this->audit, new MockClock('2026-09-14 09:00:00'));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testAFieldIsDeclaredAndAudited(): void
    {
        $actor = Uuid::v7();
        $field = $this->manage->create($this->company, self::sector(), $actor);

        self::assertSame([$field], $this->manage->list($this->company, CustomFieldEntity::Customer));
        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame([ManageCustomFields::ENTITY_TYPE, ManageCustomFields::CREATED, ['key' => 'sector'], $actor], [$entry->entityType, $entry->action, $entry->changes, $entry->actorUserId]);
        self::assertTrue($field->getId()->equals($entry->entityId));
    }

    public function testAKeyIsDeclaredOncePerKindOfRecordInACompany(): void
    {
        $this->manage->create($this->company, self::sector(), null);

        $this->expectException(CustomFieldKeyTaken::class);
        $this->manage->create($this->company, self::sector(), null);
    }

    public function testAnotherCompanyMayUseTheSameKey(): void
    {
        $this->manage->create($this->company, self::sector(), null);
        $globex = new Company('Globex', 'FR', 'EUR', 'fr', 'Europe/Paris');

        $this->manage->create($globex, self::sector(), null);

        self::assertCount(1, $this->manage->list($globex, CustomFieldEntity::Customer));
    }

    public function testARevisionIsAuditedWithWhatChangedAndNothingWhenNothingDid(): void
    {
        $field = $this->manage->create($this->company, self::sector(), null);

        $this->manage->revise($this->company, $field->getId(), self::sector(), null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $field->getId(), self::sector(label: 'Activité', isActive: false), null);
        self::assertSame([ManageCustomFields::REVISED, ['fields' => ['label', 'isActive']]], [$this->audit->entries[1]->action, $this->audit->entries[1]->changes]);
    }

    public function testAKeyOrATypeNeverChanges(): void
    {
        $field = $this->manage->create($this->company, self::sector(), null);

        foreach ([self::sector(key: 'activity'), self::sector(type: CustomFieldType::Text, choices: [])] as $input) {
            try {
                $this->manage->revise($this->company, $field->getId(), $input, null);
                self::fail('The field was changed.');
            } catch (InvalidCustomFieldDefinition $refused) {
                self::assertContains($refused->field, ['key', 'type']);
            }
        }
    }

    public function testAnotherCompanysFieldIsNotFound(): void
    {
        $field = $this->manage->create($this->company, self::sector(), null);
        $globex = new Company('Globex', 'FR', 'EUR', 'fr', 'Europe/Paris');

        $this->expectException(CustomFieldNotFound::class);
        $this->manage->revise($globex, $field->getId(), self::sector(), null);
    }

    public function testTheRulesARecordIsCheckedAgainstIncludeRetiredFields(): void
    {
        $this->manage->create($this->company, self::sector(), null);
        $this->manage->create($this->company, new CustomFieldInput(CustomFieldEntity::Customer, 'legacy', 'Ancien code', CustomFieldType::Text, false, [], 1, false), null);

        self::assertEquals([
            new CustomFieldRule('sector', CustomFieldType::Choice, true, ['retail', 'wholesale']),
            new CustomFieldRule('legacy', CustomFieldType::Text, false, [], false),
        ], $this->manage->rules($this->company, CustomFieldEntity::Customer));
    }

    /** @param list<string> $choices */
    private static function sector(string $key = 'sector', string $label = 'Secteur', CustomFieldType $type = CustomFieldType::Choice, array $choices = ['retail', 'wholesale'], bool $isActive = true): CustomFieldInput
    {
        return new CustomFieldInput(CustomFieldEntity::Customer, $key, $label, $type, true, $choices, 0, $isActive);
    }
}
