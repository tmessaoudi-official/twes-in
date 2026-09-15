<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's stock locations, every establishment's tree listed flat by code, each establishment's default location
 * among them from the first read. A location given no parent sits under its establishment's default and never moves to
 * another establishment. Read with stock.read, changed with stock.write; a taken code and a location still in use answer
 * 409, a parent that would leave the tree or make a cycle 422.
 */
#[ApiResource(
    shortName: 'StockLocation',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-locations',
            provider: StockLocationCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/stock-locations',
            processor: CreateStockLocationProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/stock-locations/{locationId}',
            processor: ReviseStockLocationProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/stock-locations/{locationId}',
            processor: DeleteStockLocationProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class StockLocationResource
{
    public const string READ = 'stock_location:read';
    public const string WRITE = 'stock_location:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The establishment whose tree the location belongs to; a revision names the same one. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $establishmentId = '';

    /** The location this one sits under; null for a default location, and written null for "under the default". */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $parentId = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['site', 'building', 'floor', 'zone', 'rack', 'bin']])]
    #[Assert\Choice(callback: [self::class, 'kinds'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $kind = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: StockLocation::CODE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $code = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: StockLocation::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $isDefault = false;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $childCount = 0;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $movementCount = 0;

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (StockLocationKind $kind): string => $kind->value, StockLocationKind::cases());
    }

    public static function of(StockLocation $location, int $childCount, int $movementCount): self
    {
        $resource = new self();
        $resource->id = $location->getId()->toRfc4122();
        $resource->establishmentId = $location->getEstablishment()->getId()->toRfc4122();
        $resource->parentId = $location->getParent()?->getId()->toRfc4122();
        $resource->kind = $location->getKind()->value;
        $resource->code = $location->getCode();
        $resource->name = $location->getName();
        $resource->isDefault = $location->isDefault();
        $resource->childCount = $childCount;
        $resource->movementCount = $movementCount;

        return $resource;
    }
}
