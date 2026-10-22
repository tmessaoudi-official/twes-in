<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMap;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor\StockDrawingChangesProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One floor's draft on the stock map, saved as one step by « Enregistrer »: rectangles drawn for places, moved, turned,
 * given another place or undrawn, places created with their rectangles, pieces of the building built, reshaped or
 * removed, and the floor's own size, all of it or none of it. The toast's « Annuler » sends the inverse step, which has
 * the same shape.
 *
 * A refusal names the entry by its place in what was sent (`structureMoves[2].x`, `newPlaces[0].code`), 422; anything
 * saved since the version named for it refuses the whole step, 409; the floor of another company, or a member without
 * stock.write, answers 404. It answers the floor's drawings and building as the step left them, its version, and what
 * the step created.
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
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $draws = [];

    /**
     * Rectangles of this floor, each where it now stands: `{drawingId, x, y, width, depth, rotation, height, version}`,
     * and `locationId` when it is drawn for another place from now on. That place is drawn nowhere else, or on a
     * rectangle this step erases; without it the rectangle keeps its place.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['drawingId', 'x', 'y', 'width', 'depth', 'version'], 'properties' => ['drawingId' => ['type' => 'string', 'format' => 'uuid'], ...self::RECT, 'version' => ['type' => 'integer', 'minimum' => 1], 'locationId' => ['type' => 'string', 'format' => 'uuid']]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $moves = [];

    /**
     * Rectangles of this floor to undraw: `{drawingId, version}`.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['drawingId', 'version'], 'properties' => ['drawingId' => ['type' => 'string', 'format' => 'uuid'], 'version' => ['type' => 'integer', 'minimum' => 1]]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $erasures = [];

    /**
     * Places to create, each with its rectangle: `{kind, code, name, x, y, width, depth, rotation, height}`. Each is put
     * in the drawn zone holding its centre once the step's moves are done, and otherwise under its establishment's
     * default place.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['kind', 'code', 'name', 'x', 'y', 'width', 'depth'], 'properties' => ['kind' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], ...self::RECT]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $newPlaces = [];

    /**
     * Pieces of the building to build on this floor: `{kind, name, x, y, width, depth, rotation, height}`.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['kind', 'x', 'y', 'width', 'depth'], 'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string'], ...self::RECT]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $structureBuilds = [];

    /**
     * Pieces of this floor, each as it now is: `{structureId, version, kind, name, x, y, width, depth, rotation, height}`.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['structureId', 'version', 'kind', 'x', 'y', 'width', 'depth'], 'properties' => ['structureId' => ['type' => 'string', 'format' => 'uuid'], 'version' => ['type' => 'integer', 'minimum' => 1], 'kind' => ['type' => 'string'], 'name' => ['type' => 'string'], ...self::RECT]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $structureMoves = [];

    /**
     * Pieces of this floor to remove: `{structureId, version}`.
     *
     * @var list<mixed>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['structureId', 'version'], 'properties' => ['structureId' => ['type' => 'string', 'format' => 'uuid'], 'version' => ['type' => 'integer', 'minimum' => 1]]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $structureErasures = [];

    /**
     * The floor's own size changed with the rest, when its edge was dragged: `{widthMetres, depthMetres, version}`.
     * Absent, the floor is left as it is; its name, level and plan are never changed here.
     *
     * @var array<mixed>|null
     */
    #[ApiProperty(schema: ['type' => 'object', 'required' => ['widthMetres', 'depthMetres', 'version'], 'properties' => ['widthMetres' => ['type' => 'string'], 'depthMetres' => ['type' => 'string'], 'version' => ['type' => 'integer', 'minimum' => 1]]])]
    #[Groups([self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public ?array $floor = null;

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

    /**
     * What is built on the floor once the step is done, each piece with its version. The rows are plain arrays shaped
     * as StockStructure, so the operation's groups, which name the type the screen imports, stay as they are.
     *
     * @var list<array<mixed>>
     */
    #[ApiProperty(
        writable: false,
        schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StockStructure']],
    )]
    #[Groups([self::READ])]
    public array $structures = [];

    /**
     * What the step created, each list in the order it was sent, so « Annuler » can take it back: the rectangle of each
     * `draws` entry, the rectangle and the place of each `newPlaces` entry, the piece of each `structureBuilds` entry.
     *
     * @var array{draws: list<string>, newPlaces: list<array{drawingId: string, locationId: string}>, structureBuilds: list<string>}
     */
    #[ApiProperty(
        writable: false,
        schema: [
            'type' => 'object',
            'required' => ['draws', 'newPlaces', 'structureBuilds'],
            'properties' => [
                'draws' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                'newPlaces' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['drawingId', 'locationId'], 'properties' => ['drawingId' => ['type' => 'string', 'format' => 'uuid'], 'locationId' => ['type' => 'string', 'format' => 'uuid']]]],
                'structureBuilds' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
            ],
        ],
    )]
    #[Groups([self::READ])]
    public array $created = ['draws' => [], 'newPlaces' => [], 'structureBuilds' => []];

    /** The floor's version once the step is done, which the next step that changes its size sends back. */
    #[ApiProperty(writable: false, schema: ['type' => 'integer'])]
    #[Groups([self::READ])]
    public ?int $floorVersion = null;
}
