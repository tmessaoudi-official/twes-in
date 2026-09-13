<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\CustomFields\Application\CustomFieldInput;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fields a company adds to one kind of record. Every member reads them (company.read), because the screens that
 * show those records render them; declaring and revising one takes company.settings. A field is retired, never deleted.
 */
#[ApiResource(
    shortName: 'CustomField',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/custom-fields',
            provider: CustomFieldCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            openapi: new OpenApiOperation(parameters: [
                new Parameter('entity', 'query', 'The kind of record the fields belong to.', false, schema: ['type' => 'string', 'enum' => ['customer'], 'default' => 'customer']),
            ]),
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/custom-fields',
            processor: CreateCustomFieldProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/custom-fields/{fieldId}',
            processor: ReviseCustomFieldProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class CustomFieldResource
{
    public const string READ = 'custom_field:read';
    public const string WRITE = 'custom_field:write';
    public const string READ_PERMISSION = 'company.read';
    public const string WRITE_PERMISSION = 'company.settings';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['customer']])]
    #[Assert\Choice(callback: [self::class, 'entities'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $entity = 'customer';

    /** Lowercase letters, digits and underscores, starting with a letter; fixed once declared. */
    #[Assert\Regex(pattern: CustomFieldDefinition::KEY, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $key = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: CustomFieldDefinition::LABEL_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $label = '';

    /** Fixed once declared. */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['text', 'number', 'date', 'bool', 'choice']])]
    #[Assert\Choice(callback: [self::class, 'types'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $type = 'text';

    #[Groups([self::READ, self::WRITE])]
    public bool $required = false;

    /** @var list<string> the values a choice field offers, in order; empty for any other type */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string')], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $choices = [];

    #[Assert\Range(min: 0, max: 10000, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $sortOrder = 0;

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    public static function of(CustomFieldDefinition $field): self
    {
        $resource = new self();
        $resource->id = $field->getId()->toRfc4122();
        $resource->entity = $field->getEntity()->value;
        $resource->key = $field->getKey();
        $resource->label = $field->getLabel();
        $resource->type = $field->getType()->value;
        $resource->required = $field->isRequired();
        $resource->choices = $field->getChoices();
        $resource->sortOrder = $field->getSortOrder();
        $resource->isActive = $field->isActive();

        return $resource;
    }

    public function input(): CustomFieldInput
    {
        return new CustomFieldInput(
            CustomFieldEntity::from($this->entity),
            $this->key,
            $this->label,
            CustomFieldType::from($this->type),
            $this->required,
            $this->choices,
            $this->sortOrder,
            $this->isActive,
        );
    }

    /** @return list<string> */
    public static function entities(): array
    {
        return array_map(static fn (CustomFieldEntity $entity) => $entity->value, CustomFieldEntity::cases());
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (CustomFieldType $type) => $type->value, CustomFieldType::cases());
    }
}
