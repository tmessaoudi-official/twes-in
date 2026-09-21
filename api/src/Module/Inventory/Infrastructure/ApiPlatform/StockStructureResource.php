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
use App\Venue\Domain\StructureKind;
use App\Venue\Domain\VenueStructure;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One piece of the building on a floor — a wall, a door, a post, a dock — in METRES from the floor's top-left
 * corner (docs/SPEC.md row 83, the approved canvas's Structure board).
 *
 * It names no location and never will: nothing here holds goods, which is the whole reason it is its own layer.
 * The HTTP surface sits in the module beside the drawings (ruling of 2026-09-21 06:10) while the use case is the
 * venue's own — the inventory has nothing to say about a wall, so nothing of it is passed through on the way.
 *
 * Read with stock.read, built with stock.write; a measurement out of bounds answers 422 naming the field, and a
 * piece of another company's building is not found.
 */
#[ApiResource(
    shortName: 'StockStructure',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}/structures',
            provider: StockStructureCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}/structures',
            processor: BuildStockStructureProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/stock-structures/{structureId}',
            processor: BuildStockStructureProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/stock-structures/{structureId}',
            processor: EraseStockStructureProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class StockStructureResource
{
    public const string READ = 'stock_structure:read';
    public const string WRITE = 'stock_structure:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The floor it stands on. A piece never changes floor: the same wall one storey up is another wall. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $floorId = null;

    /**
     * Which of the board's four tools drew it. It is revisable, because a doorway traced with the wall tool is
     * right in every measurement and wrong in exactly this one field.
     */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Choice(callback: [self::class, 'kinds'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $kind = '';

    /** Metres from the floor's left edge. */
    #[Groups([self::READ, self::WRITE])]
    public string $x = '0';

    /** Metres from the floor's top edge. */
    #[Groups([self::READ, self::WRITE])]
    public string $y = '0';

    /** How long it runs: a wall's length, a door's opening, a post's side. */
    #[Groups([self::READ, self::WRITE])]
    public string $width = '0';

    /** How thick it is on the floor — the second side of the footprint, not a height. */
    #[Groups([self::READ, self::WRITE])]
    public string $depth = '0';

    /** Whole degrees clockwise, so a wall running at an angle keeps its footprint and not a bounding box. */
    #[Groups([self::READ, self::WRITE])]
    public int $rotation = 0;

    /** How tall it stands, for the 3D view: a full-height wall, a door's opening, a dock's clearance. */
    #[Groups([self::READ, self::WRITE])]
    public string $height = '0';

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (StructureKind $kind): string => $kind->value, StructureKind::cases());
    }

    public static function of(VenueStructure $structure): self
    {
        $rect = $structure->getRect();
        $piece = new self();
        $piece->id = $structure->getId()->toRfc4122();
        $piece->floorId = $structure->getArea()->getId()->toRfc4122();
        $piece->kind = $structure->getKind()->value;
        $piece->x = $rect->x;
        $piece->y = $rect->y;
        $piece->width = $rect->width;
        $piece->depth = $rect->depth;
        $piece->rotation = $rect->rotation;
        $piece->height = $rect->height;

        return $piece;
    }
}
