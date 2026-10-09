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
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A group chosen on the stock map, changed as one step (docs/SPEC.md § 7, the stock map's multi-select): rectangles
 * drawn for places, rectangles moved or turned, rectangles undrawn, all of it or none of it. The screen's one
 * « Annuler » sends the inverse step, which has the same shape.
 *
 * A refusal names the entry by its place in what was sent (`moves[2].x`), 422; the floor of another company, or a
 * member without stock.write, answers 404. It answers the floor's drawings as the step left them.
 */
#[ApiResource(
    shortName: 'StockDrawingChanges',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}/drawing-changes',
            status: 200,
            processor: StockDrawingChangesProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ, StockDrawingResource::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class StockDrawingChangesResource
{
    public const string READ = 'stock_drawing_changes:read';
    public const string WRITE = 'stock_drawing_changes:write';

    private const array RECT = [
        'x' => ['type' => 'string'],
        'y' => ['type' => 'string'],
        'width' => ['type' => 'string'],
        'depth' => ['type' => 'string'],
        'rotation' => ['type' => 'integer'],
        'height' => ['type' => 'string'],
    ];

    /**
     * Places to draw, each at its rectangle: `{locationId, x, y, width, depth, rotation, height}`.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['locationId', 'x', 'y', 'width', 'depth'], 'properties' => ['locationId' => ['type' => 'string', 'format' => 'uuid'], ...self::RECT]]])]
    #[Groups([self::WRITE])]
    public array $draws = [];

    /**
     * Rectangles of this floor, each where it now stands: `{drawingId, x, y, width, depth, rotation, height}`.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['drawingId', 'x', 'y', 'width', 'depth'], 'properties' => ['drawingId' => ['type' => 'string', 'format' => 'uuid'], ...self::RECT]]])]
    #[Groups([self::WRITE])]
    public array $moves = [];

    /**
     * Rectangles of this floor to undraw, by id.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']])]
    #[Groups([self::WRITE])]
    public array $erasures = [];

    /**
     * What is drawn on the floor once the step is done.
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
