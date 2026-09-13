<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldRule;
use App\CustomFields\Domain\InvalidCustomFieldDefinition;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The fields a company adds to its records. A key is declared once per kind of record in a company; a field's key and
 * type never change and it is retired rather than deleted. The rules a record is checked against include retired
 * fields, so the values they hold are carried over. Audited with the key on creation and the names of what changed.
 */
final readonly class ManageCustomFields
{
    public const string ENTITY_TYPE = 'custom_field';
    public const string CREATED = 'custom_field.created';
    public const string REVISED = 'custom_field.revised';

    public function __construct(
        private CustomFieldDefinitionRepository $fields,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<CustomFieldDefinition> */
    public function list(Company $company, CustomFieldEntity $entity): array
    {
        return $this->fields->ofCompanyAndEntity($company->getId(), $entity);
    }

    /** @return list<CustomFieldRule> */
    public function rules(Company $company, CustomFieldEntity $entity): array
    {
        return array_map(static fn (CustomFieldDefinition $field): CustomFieldRule => $field->rule(), $this->list($company, $entity));
    }

    /**
     * @throws CustomFieldKeyTaken
     * @throws InvalidCustomFieldDefinition
     */
    public function create(Company $company, CustomFieldInput $input, ?Uuid $actorUserId): CustomFieldDefinition
    {
        if (null !== $this->fields->ofKey($company->getId(), $input->entity, $input->key)) {
            throw new CustomFieldKeyTaken();
        }
        $field = CustomFieldDefinition::create($company, $input->entity, $input->key, $input->label, $input->type, $input->required, $input->choices, $input->sortOrder, $this->clock->now());
        if (!$input->isActive) {
            $field->revise($input->label, $input->required, $input->choices, $input->sortOrder, false, $this->clock->now());
        }
        $this->fields->save($field);
        $this->record($company, $field->getId(), self::CREATED, ['key' => $field->getKey()], $actorUserId);

        return $field;
    }

    /**
     * @throws CustomFieldNotFound
     * @throws InvalidCustomFieldDefinition
     */
    public function revise(Company $company, Uuid $id, CustomFieldInput $input, ?Uuid $actorUserId): CustomFieldDefinition
    {
        $field = $this->fields->ofIdInCompany($id, $company->getId()) ?? throw new CustomFieldNotFound();
        // Customers are the only kind of record with fields yet: the second one brings the entity into this comparison.
        if ($input->key !== $field->getKey()) {
            throw new InvalidCustomFieldDefinition('key', "A field's key never changes: declare another field.");
        }
        if ($input->type !== $field->getType()) {
            throw new InvalidCustomFieldDefinition('type', "A field's type never changes: declare another field.");
        }

        $changed = $field->revise($input->label, $input->required, $input->choices, $input->sortOrder, $input->isActive, $this->clock->now());
        if ([] !== $changed) {
            $this->fields->save($field);
            $this->record($company, $field->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
        }

        return $field;
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $fieldId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $fieldId, $action, $actorUserId, $changes, $company->getId()));
    }
}
