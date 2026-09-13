<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Settings\Application\ResolvedSetting;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The settings of a company as the caller sees them: each definition with the value in force, where it comes
 * from, what each level holds and where the caller may change it. One endpoint reads a chain; a PUT stores a
 * value at one level, a DELETE forgets it there (docs/SPEC.md § 3 Settings).
 */
#[ApiResource(
    shortName: 'Setting',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/settings',
            provider: SettingCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            openapi: new OpenApiOperation(parameters: [
                new Parameter('chain', 'query', 'One chain; every chain when absent', false, schema: ['type' => 'string', 'enum' => ['presentation', 'parties', 'articles']]),
            ]),
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/settings/{key}',
            requirements: ['key' => self::KEY],
            processor: ChangeSettingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/settings/{key}',
            requirements: ['key' => self::KEY],
            processor: ResetSettingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            openapi: new OpenApiOperation(parameters: [
                new Parameter('level', 'query', 'The level to forget the value at', true, schema: ['type' => 'string']),
                new Parameter('roleId', 'query', 'The role, at the role level', false, schema: ['type' => 'string', 'format' => 'uuid']),
            ]),
        ),
    ],
)]
final class SettingResource
{
    public const string READ = 'setting:read';
    public const string WRITE = 'setting:write';
    private const string KEY = '[a-z][a-z0-9_.-]*';
    /** A setting's value is any JSON value its type allows; the contract says so rather than "string". */
    private const array ANY_VALUE = ['anyOf' => [['type' => 'string'], ['type' => 'number'], ['type' => 'boolean'], ['type' => 'array'], ['type' => 'object', 'additionalProperties' => true], ['type' => 'null']]];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $key = '';

    #[Groups([self::READ])]
    public string $chain = '';

    #[Groups([self::READ])]
    public string $type = '';

    #[Groups([self::READ])]
    public string $labelKey = '';

    #[Groups([self::READ])]
    public string $module = '';

    #[ApiProperty(schema: self::ANY_VALUE)]
    #[Groups([self::READ])]
    public mixed $default = null;

    /** The value in force when read; the value to store when written. */
    #[ApiProperty(schema: self::ANY_VALUE)]
    #[Groups([self::READ, self::WRITE])]
    public mixed $value = null;

    /** The level the value in force comes from; null while the declared default is in force. */
    #[Groups([self::READ])]
    public ?string $source = null;

    /** @var list<SettingLevelValue> what each level of the caller's chain holds, most general first */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['level', 'value'], 'properties' => ['level' => ['type' => 'string'], 'value' => self::ANY_VALUE]]])]
    #[Groups([self::READ])]
    public array $levels = [];

    /** @var list<string> */
    #[Groups([self::READ])]
    public array $overridableLevels = [];

    /** @var list<string> the levels the caller may change */
    #[Groups([self::READ])]
    public array $writableLevels = [];

    /** @var list<string> */
    #[Groups([self::READ])]
    public array $choices = [];

    #[Groups([self::READ])]
    public int|string|null $min = null;

    #[Groups([self::READ])]
    public int|string|null $max = null;

    #[Groups([self::READ])]
    public ?int $maxLength = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $level = '';

    /** At the role level, the role whose default this is. */
    #[Groups([self::WRITE])]
    public ?string $roleId = null;

    /** @param list<string> $writableLevels */
    public static function of(ResolvedSetting $setting, array $writableLevels): self
    {
        $definition = $setting->definition;
        $resource = new self();
        $resource->key = $setting->key;
        $resource->chain = $definition->chain->value;
        $resource->type = $definition->type->value;
        $resource->labelKey = $definition->labelKey;
        $resource->module = $definition->module;
        $resource->default = $definition->default;
        $resource->value = $setting->value;
        $resource->source = $setting->source?->value;
        foreach ($setting->explicit as $level => $value) {
            $resource->levels[] = new SettingLevelValue($level, $value);
        }
        foreach ($definition->chain->levels() as $level) {
            if ($definition->allows($level)) {
                $resource->overridableLevels[] = $level->value;
            }
        }
        $resource->writableLevels = $writableLevels;
        $resource->choices = $definition->choices;
        $resource->min = $definition->min;
        $resource->max = $definition->max;
        $resource->maxLength = $definition->maxLength;

        return $resource;
    }
}
