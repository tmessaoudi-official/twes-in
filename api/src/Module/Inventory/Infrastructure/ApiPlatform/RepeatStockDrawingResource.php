<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Inventory\Application\DrawStockMap;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Repeating a rectangle down an aisle (docs/SPEC.md row 83): N more of it, each one spacing further than the last,
 * and each one a stock location as well as a rectangle. One call because it is one decision — a repeat that made the
 * locations and then failed to draw them would leave a warehouse full of places nobody can find.
 *
 * It answers the DRAWINGS it created rather than a bare count: the screen has to show them, and a second read to
 * learn what it had just asked for would be a race with anyone else drawing on the same floor.
 *
 * `spacing` is the free floor BETWEEN two rectangles, so zero means back to back and nothing a person types makes
 * two copies overlap. Read and written with stock.write; a code already taken answers 409 naming it, a copy stepping
 * off the floor 422 naming the side it left by.
 */
#[ApiResource(
    shortName: 'RepeatStockDrawing',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/stock-drawings/{drawingId}/repeat',
            processor: RepeatStockDrawingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ, StockDrawingResource::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class RepeatStockDrawingResource
{
    public const string READ = 'repeat_stock_drawing:read';
    public const string WRITE = 'repeat_stock_drawing:write';

    /** How many MORE of it: the rectangle repeated is not one of them. */
    #[Assert\Range(min: 1, max: DrawStockMap::REPEAT_LIMIT, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $count = 1;

    /** The free floor between one rectangle and the next, in metres. Zero is back to back, which is an arrangement. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $spacing = '0';

    /** Which way across the FLOOR, not across the rectangle: `up`, `down`, `left` or `right`. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Choice(choices: ['up', 'down', 'left', 'right'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $way = 'down';

    /** What the first copy is called; the rest count on from its number, keeping the width it was written with. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $firstCode = '';

    /**
     * What was created, in the order it was placed.
     *
     * @var list<StockDrawingResource>
     */
    #[ApiProperty(
        writable: false,
        schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StockDrawing']],
    )]
    #[Groups([self::READ])]
    public array $drawings = [];
}
