<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\CustomFields\Domain\Exception\InvalidCustomFieldDefinition;
use App\CustomFields\Domain\Model\CustomFieldDefinition;
use App\CustomFields\Domain\Repository\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\ValueObject\CustomFieldEntity;
use App\CustomFields\Domain\ValueObject\CustomFieldRule;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The fields a company adds to its records. A key is declared once per kind of record in a company; a field's key and
 * type never change and it is retired rather than deleted. The rules a record is checked against include retired
 * fields, so the values they hold are carried over. Audited with the key on creation and the names of what changed.
 */
final readonly class ManageCustomFields implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'custom_field';
    public const string CREATED = 'custom_field.created';
    public const string REVISED = 'custom_field.revised';

    public function __construct(
        private CustomFieldDefinitionRepository $fields,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
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
        return $this->transactions->run(function () use ($company, $input, $actorUserId): CustomFieldDefinition {
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
        });
    }

    /**
     * @throws CustomFieldNotFound
     * @throws InvalidCustomFieldDefinition
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, CustomFieldInput $input, ?Uuid $actorUserId, ?int $version = null): CustomFieldDefinition
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId, $version): CustomFieldDefinition {
            $field = $this->fields->ofIdInCompany($id, $company->getId()) ?? throw new CustomFieldNotFound();
            if (null !== $version) {
                $this->versions->expect($field, $version);
            }
            if ($input->entity !== $field->getEntity()) {
                throw new InvalidCustomFieldDefinition('entity', "A field's kind of record never changes: declare another field.");
            }
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
        });
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $fieldId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $fieldId, $action, $actorUserId, $changes, $company->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
