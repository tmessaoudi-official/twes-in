<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A field a company adds to one kind of record (docs/SPEC.md § 7, customisation is metadata). Its key and type are
 * fixed once declared, because records store values under that key in that type; its label, whether it is required,
 * its choices and its place can change. A field is retired, never deleted, so the values records hold stay readable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'custom_field_definition')]
#[ORM\Index(name: 'idx_custom_field_definition_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_custom_field_definition_key', columns: ['company_id', 'entity', 'field_key'])]
class CustomFieldDefinition
{
    public const string KEY = '/^[a-z][a-z0-9_]{0,39}$/';
    public const int LABEL_MAX = 80;
    public const int CHOICES_MAX = 50;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 16, enumType: CustomFieldEntity::class)]
    private CustomFieldEntity $entity;

    #[ORM\Column(name: 'field_key', length: 40)]
    private string $key;

    #[ORM\Column(length: self::LABEL_MAX)]
    private string $label;

    #[ORM\Column(length: 16, enumType: CustomFieldType::class)]
    private CustomFieldType $type;

    #[ORM\Column]
    private bool $required = false;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $choices = [];

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, CustomFieldEntity $entity, string $key, CustomFieldType $type, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->entity = $entity;
        $this->key = $key;
        $this->type = $type;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<string> $choices
     *
     * @throws InvalidCustomFieldDefinition
     */
    public static function create(Company $company, CustomFieldEntity $entity, string $key, string $label, CustomFieldType $type, bool $required, array $choices, int $sortOrder, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new InvalidCustomFieldDefinition('key', \sprintf('"%s" is not a key: a lowercase letter, then up to 39 lowercase letters, digits or underscores.', $key));
        }
        $field = new self($company, $entity, $key, $type, $now);
        $field->label = self::label($label);
        $field->required = $required;
        $field->choices = self::choices($type, $choices);
        $field->sortOrder = self::sortOrder($sortOrder);

        return $field;
    }

    /**
     * @param list<string> $choices
     *
     * @return list<string> the properties that changed, none when the revision says what the field already says
     *
     * @throws InvalidCustomFieldDefinition
     */
    public function revise(string $label, bool $required, array $choices, int $sortOrder, bool $isActive, \DateTimeImmutable $now): array
    {
        $label = self::label($label);
        $choices = self::choices($this->type, $choices);
        $sortOrder = self::sortOrder($sortOrder);

        $changed = array_keys(array_filter([
            'label' => $label !== $this->label,
            'required' => $required !== $this->required,
            'choices' => $choices !== $this->choices,
            'sortOrder' => $sortOrder !== $this->sortOrder,
            'isActive' => $isActive !== $this->isActive,
        ]));
        if ([] === $changed) {
            return [];
        }

        $this->label = $label;
        $this->required = $required;
        $this->choices = $choices;
        $this->sortOrder = $sortOrder;
        $this->isActive = $isActive;
        $this->updatedAt = $now;

        return $changed;
    }

    public function rule(): CustomFieldRule
    {
        return new CustomFieldRule($this->key, $this->type, $this->required, $this->choices, $this->isActive);
    }

    private static function label(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX) {
            throw new InvalidCustomFieldDefinition('label', \sprintf('A label of 1 to %d characters.', self::LABEL_MAX));
        }

        return $label;
    }

    /**
     * @param list<string> $choices
     *
     * @return list<string>
     */
    private static function choices(CustomFieldType $type, array $choices): array
    {
        $choices = array_map(trim(...), $choices);
        if (CustomFieldType::Choice !== $type) {
            if ([] !== $choices) {
                throw new InvalidCustomFieldDefinition('choices', 'Only a choice field has choices.');
            }

            return [];
        }
        if ([] === $choices || \count($choices) > self::CHOICES_MAX) {
            throw new InvalidCustomFieldDefinition('choices', \sprintf('A choice field offers 1 to %d choices.', self::CHOICES_MAX));
        }
        foreach ($choices as $choice) {
            if ('' === $choice || mb_strlen($choice) > self::LABEL_MAX) {
                throw new InvalidCustomFieldDefinition('choices', \sprintf('Each choice is 1 to %d characters.', self::LABEL_MAX));
            }
        }
        if (\count(array_unique($choices)) !== \count($choices)) {
            throw new InvalidCustomFieldDefinition('choices', 'Each choice is offered once.');
        }

        return $choices;
    }

    private static function sortOrder(int $sortOrder): int
    {
        if ($sortOrder < 0 || $sortOrder > 10000) {
            throw new InvalidCustomFieldDefinition('sortOrder', 'A position from 0 to 10000.');
        }

        return $sortOrder;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEntity(): CustomFieldEntity
    {
        return $this->entity;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): CustomFieldType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** @return list<string> */
    public function getChoices(): array
    {
        return $this->choices;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
