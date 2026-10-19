<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Infrastructure\ApiPlatform\State\Processor\CreateUnitProcessor;
use App\Fiscal\Infrastructure\ApiPlatform\State\Processor\ReviseUnitProcessor;
use App\Fiscal\Infrastructure\ApiPlatform\State\Provider\UnitCollectionProvider;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/** A company's units. The code is a UN/ECE Recommendation 20 code chosen once; the rest is revised with a PUT. */
#[ApiResource(
    shortName: 'Unit',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/units',
            provider: UnitCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/units',
            processor: CreateUnitProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::CREATE, self::WRITE]],
            validationContext: ['groups' => [self::CREATE, self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/units/{unitId}',
            processor: ReviseUnitProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE, self::REVISE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
        ),
    ],
)]
final class UnitResource
{
    public const string READ = 'unit:read';
    public const string WRITE = 'unit:write';
    public const string CREATE = 'unit:create';
    public const string REVISE = 'unit:revise';

    /** A PHP array without entries serializes as `[]`; the names are a JSON object even when there are none. */
    private const array NORMALIZATION = [
        'groups' => [self::READ],
        AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        AbstractNormalizer::CALLBACKS => ['names' => [self::class, 'asObject']],
    ];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::CREATE])]
    #[Assert\Regex(pattern: Unit::CODE, groups: [self::CREATE])]
    #[Groups([self::READ, self::CREATE])]
    public string $code = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: 60, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    /**
     * The name in other languages documents print in, by language; a document in a language it lacks prints the name.
     * A revision leaving it out keeps them.
     *
     * @var array<array-key, mixed>|null
     */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 60]])]
    #[Groups([self::READ, self::WRITE])]
    public ?array $names = null;

    /** How many decimals a quantity in this unit may carry. */
    #[Assert\Range(min: 0, max: Unit::MAX_DECIMALS, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $decimals = 0;

    #[Groups([self::READ, self::REVISE])]
    public bool $isActive = true;

    #[Assert\PositiveOrZero(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $sortOrder = 0;

    /** The version read; a revision sends back the one it was opened at, and one saved since answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

    public static function of(Unit $unit): self
    {
        $resource = new self();
        $resource->id = $unit->getId()->toRfc4122();
        $resource->version = $unit->getVersion();
        $resource->code = $unit->getCode();
        $resource->name = $unit->getName();
        $resource->names = $unit->getNames();
        $resource->decimals = $unit->getDecimals();
        $resource->isActive = $unit->isActive();
        $resource->sortOrder = $unit->getSortOrder();

        return $resource;
    }

    /**
     * @param array<array-key, mixed>|null $values
     *
     * @return \ArrayObject<array-key, mixed>
     */
    public static function asObject(?array $values): \ArrayObject
    {
        return new \ArrayObject($values ?? []);
    }
}
