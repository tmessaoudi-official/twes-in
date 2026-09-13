<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Fiscal\Domain\Unit;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** A company's units. The code is a UN/ECE Recommendation 20 code chosen once; the rest is revised with a PUT. */
#[ApiResource(
    shortName: 'Unit',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/units',
            provider: UnitCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/units',
            processor: CreateUnitProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::CREATE, self::WRITE]],
            validationContext: ['groups' => [self::CREATE, self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/units/{unitId}',
            processor: ReviseUnitProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE, self::REVISE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class UnitResource
{
    public const string READ = 'unit:read';
    public const string WRITE = 'unit:write';
    public const string CREATE = 'unit:create';
    public const string REVISE = 'unit:revise';

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

    /** How many decimals a quantity in this unit may carry. */
    #[Assert\Range(min: 0, max: Unit::MAX_DECIMALS, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $decimals = 0;

    #[Groups([self::READ, self::REVISE])]
    public bool $isActive = true;

    #[Assert\PositiveOrZero(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $sortOrder = 0;

    public static function of(Unit $unit): self
    {
        $resource = new self();
        $resource->id = $unit->getId()->toRfc4122();
        $resource->code = $unit->getCode();
        $resource->name = $unit->getName();
        $resource->decimals = $unit->getDecimals();
        $resource->isActive = $unit->isActive();
        $resource->sortOrder = $unit->getSortOrder();

        return $resource;
    }
}
