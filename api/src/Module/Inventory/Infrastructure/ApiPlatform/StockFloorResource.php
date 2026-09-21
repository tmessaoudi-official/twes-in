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
use App\Venue\Domain\VenueArea;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The floors a company's stock is drawn on (docs/SPEC.md row 83): one per level of an establishment, from the ground
 * up. The floor IS the canvas — a site or a building is never drawn — and a plan behind it is optional, placed by
 * saying how many metres wide the image really is.
 *
 * The rectangles are the venue's and this surface is the inventory's view of them: read with stock.read, changed with
 * stock.write; a second floor at the same level answers 409, a plan without its scale 422 naming the field.
 */
#[ApiResource(
    shortName: 'StockFloor',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-floors',
            provider: StockFloorCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/stock-floors',
            processor: WriteStockFloorProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE, self::CREATE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}',
            processor: WriteStockFloorProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}',
            processor: DeleteStockFloorProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class StockFloorResource
{
    public const string READ = 'stock_floor:read';
    public const string WRITE = 'stock_floor:write';
    /** Only a new floor says which establishment it is a floor of: a floor never moves to another one. */
    public const string CREATE = 'stock_floor:create';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The establishment whose place this floor is; a revision keeps the same one and does not send it. */
    #[Assert\NotBlank(groups: [self::CREATE])]
    #[Assert\Uuid(groups: [self::CREATE])]
    #[Groups([self::READ, self::WRITE])]
    public string $establishmentId = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: VenueArea::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    /** Which storey, the ground being 0; it orders the tabs and stacks the 3D view. */
    #[Assert\Range(min: VenueArea::LEVEL_MIN, max: VenueArea::LEVEL_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $level = 0;

    /** The plan shown behind the drawing, or none. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $imageFileId = null;

    /** What the whole image spans on the ground, in metres: an image without it cannot be placed under the drawing. */
    #[Groups([self::READ, self::WRITE])]
    public ?string $imageMetresWide = null;

    #[Assert\Range(min: 0, max: 100, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $imageOpacity = 35;

    /** How many rectangles are drawn on it, so a screen can offer to remove an empty floor without asking first. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $drawingCount = 0;

    public static function of(VenueArea $area, int $drawingCount): self
    {
        $floor = new self();
        $floor->id = $area->getId()->toRfc4122();
        $floor->establishmentId = $area->getEstablishment()->getId()->toRfc4122();
        $floor->name = $area->getName();
        $floor->level = $area->getLevel();
        $floor->imageFileId = $area->getImageFileId()?->toRfc4122();
        $floor->imageMetresWide = $area->getImageMetresWide();
        $floor->imageOpacity = $area->getImageOpacity();
        $floor->drawingCount = $drawingCount;

        return $floor;
    }
}
