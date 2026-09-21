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
use App\Venue\Domain\VenueSpot;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One rectangle on a floor, in METRES from its top-left corner, and the stock location it is drawn for (docs/SPEC.md
 * row 83). Metres and not pixels because a plan is rescanned and recropped over a building's life while the building
 * does not move.
 *
 * A drawing always names a location: the rectangle itself knows nothing, so an unbound one would be unlabelled on
 * every screen and reachable from none. Drawing a location that is already drawn moves it — a rack is in one place.
 * Read with stock.read, drawn with stock.write; a measurement out of bounds and an unknown location answer 422
 * naming the field, erasing a rectangle leaves the location standing.
 */
#[ApiResource(
    shortName: 'StockDrawing',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}/drawings',
            provider: StockDrawingCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}/drawings',
            processor: DrawStockDrawingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/stock-drawings/{drawingId}',
            processor: DrawStockDrawingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/stock-drawings/{drawingId}',
            processor: EraseStockDrawingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class StockDrawingResource
{
    public const string READ = 'stock_drawing:read';
    public const string WRITE = 'stock_drawing:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The floor it is drawn on. A rectangle never changes floor: goods do not climb, so that is another rectangle. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $floorId = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $locationId = '';

    /** What the screen writes on the rectangle; the venue's own rectangle knows none of it. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationName = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationKind = '';

    /** Metres from the floor's left edge. */
    #[Groups([self::READ, self::WRITE])]
    public string $x = '0';

    /** Metres from the floor's top edge. */
    #[Groups([self::READ, self::WRITE])]
    public string $y = '0';

    #[Groups([self::READ, self::WRITE])]
    public string $width = '0';

    /** How deep it stands on the floor: the second side of the footprint, not a height. */
    #[Groups([self::READ, self::WRITE])]
    public string $depth = '0';

    /** Whole degrees clockwise, so a rack set against a wall at an angle keeps its footprint and not a bounding box. */
    #[Groups([self::READ, self::WRITE])]
    public int $rotation = 0;

    /** How tall it stands, for the 3D view; zero for a bay marked out on the floor. */
    #[Groups([self::READ, self::WRITE])]
    public string $height = '0';

    public static function of(VenueSpot $spot, StockLocation $location): self
    {
        $rect = $spot->getRect();
        $drawing = new self();
        $drawing->id = $spot->getId()->toRfc4122();
        $drawing->floorId = $spot->getArea()->getId()->toRfc4122();
        $drawing->locationId = $location->getId()->toRfc4122();
        $drawing->locationCode = $location->getCode();
        $drawing->locationName = $location->getName();
        $drawing->locationKind = $location->getKind()->value;
        $drawing->x = $rect->x;
        $drawing->y = $rect->y;
        $drawing->width = $rect->width;
        $drawing->depth = $rect->depth;
        $drawing->rotation = $rect->rotation;
        $drawing->height = $rect->height;

        return $drawing;
    }

    /** @throws \LogicException when the location is drawn nowhere, which this surface never returns */
    public static function ofDrawn(StockLocation $location): self
    {
        return self::of(
            $location->getSpot() ?? throw new \LogicException('A drawing was read for a location that is drawn nowhere.'),
            $location,
        );
    }
}
