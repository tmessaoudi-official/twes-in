<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\StockMap\DrawStockMap;
use App\Module\Inventory\Application\StockMap\StockMapStep;
use App\Module\Inventory\Domain\Exception\InvalidStockLocation;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMap\StockDrawingChangesResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMap\StockDrawingResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMap\StockStructureResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Domain\Exception\InvalidVenue;
use App\Venue\Domain\Model\VenueStructure;
use App\Venue\Domain\ValueObject\PlanRect;
use App\Venue\Domain\ValueObject\StructureKind;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * A floor's draft saved as one step. Each entry is read here into ids, kinds and PlanRects, and a refusal names it by
 * where it sits in what was sent — `moves[1].x`, `newPlaces[0].kind`, `floor.widthMetres` — since the entries of a draft
 * are not boxes of a form a person can see.
 *
 * @implements ProcessorInterface<StockDrawingChangesResource, StockDrawingChangesResource>
 */
final readonly class StockDrawingChangesProcessor implements ProcessorInterface
{
    public function __construct(private DrawStockMap $map, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockDrawingChangesResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $actor = $this->guard->account()->getId();

        $draws = [];
        foreach ($data->draws as $at => $entry) {
            $draws[] = [self::id($entry, 'locationId', "draws[$at]"), self::rect($entry, "draws[$at]")];
        }
        $moves = [];
        foreach ($data->moves as $at => $entry) {
            // Absent or null, the rectangle keeps the place it is drawn for.
            $relabel = \is_array($entry) && null !== ($entry['locationId'] ?? null) ? self::id($entry, 'locationId', "moves[$at]") : null;
            $moves[] = [self::id($entry, 'drawingId', "moves[$at]"), self::rect($entry, "moves[$at]"), self::version($entry, "moves[$at]"), $relabel];
        }
        $erasures = [];
        foreach ($data->erasures as $at => $entry) {
            $erasures[] = [self::id($entry, 'drawingId', "erasures[$at]"), self::version($entry, "erasures[$at]")];
        }
        $newPlaces = [];
        foreach ($data->newPlaces as $at => $entry) {
            $kind = StockLocationKind::tryFrom(self::text($entry, 'kind', "newPlaces[$at]", null))
                ?? throw new UnprocessableEntityHttpException("newPlaces[$at].kind: A place is a zone, a rack or another kind the plan carries.");
            $newPlaces[] = [$kind, self::text($entry, 'code', "newPlaces[$at]", null), self::text($entry, 'name', "newPlaces[$at]", null), self::rect($entry, "newPlaces[$at]")];
        }
        $structureBuilds = [];
        foreach ($data->structureBuilds as $at => $entry) {
            $structureBuilds[] = [self::structureKind($entry, "structureBuilds[$at]"), self::text($entry, 'name', "structureBuilds[$at]", ''), self::rect($entry, "structureBuilds[$at]")];
        }
        $structureMoves = [];
        foreach ($data->structureMoves as $at => $entry) {
            $structureMoves[] = [
                self::id($entry, 'structureId', "structureMoves[$at]"),
                self::version($entry, "structureMoves[$at]"),
                self::structureKind($entry, "structureMoves[$at]"),
                self::text($entry, 'name', "structureMoves[$at]", ''),
                self::rect($entry, "structureMoves[$at]"),
            ];
        }
        $structureErasures = [];
        foreach ($data->structureErasures as $at => $entry) {
            $structureErasures[] = [self::id($entry, 'structureId', "structureErasures[$at]"), self::version($entry, "structureErasures[$at]")];
        }
        $floor = null === $data->floor
            ? null
            : [self::metres($data->floor, 'widthMetres', 'floor'), self::metres($data->floor, 'depthMetres', 'floor'), self::version($data->floor, 'floor')];

        try {
            $done = $this->map->change(
                $company,
                CompanyPath::identifier($uriVariables, 'floorId'),
                new StockMapStep($draws, $moves, $erasures, $newPlaces, $structureBuilds, $structureMoves, $structureErasures, $floor),
                $actor,
            );
        } catch (VenueAreaNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidStockLocation|InvalidVenue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $data->drawings = array_map(
            static fn (StockLocation $location): StockDrawingResource => StockDrawingResource::ofDrawn($location),
            $done->drawings,
        );
        $data->structures = array_map(
            static fn (VenueStructure $structure): array => get_object_vars(StockStructureResource::of($structure)),
            $done->structures,
        );
        $data->created = [
            'draws' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $done->drawn),
            'newPlaces' => array_map(
                static fn (array $made): array => ['drawingId' => $made[0]->toRfc4122(), 'locationId' => $made[1]->toRfc4122()],
                $done->newPlaces,
            ),
            'structureBuilds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $done->built),
        ];
        $data->floorVersion = $done->floor->getVersion();

        return $data;
    }

    private static function id(mixed $entry, string $key, string $at): Uuid
    {
        $id = \is_array($entry) ? ($entry[$key] ?? null) : null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException("$at.$key: An id is expected here.");
        }

        return Uuid::fromString($id);
    }

    /** The version a rectangle, a piece or the floor was read at, which a step stands on: see DrawStockMap::change(). */
    private static function version(mixed $entry, string $at): int
    {
        $version = \is_array($entry) ? ($entry['version'] ?? null) : null;
        if (!\is_int($version) || $version < 1) {
            throw new UnprocessableEntityHttpException("$at.version: The version it was read at is expected here.");
        }

        return $version;
    }

    /** A text an entry carries; `$absent` is what a missing one stands for, and null makes it required. */
    private static function text(mixed $entry, string $key, string $at, ?string $absent): string
    {
        $value = \is_array($entry) ? ($entry[$key] ?? $absent) : null;
        if (!\is_string($value)) {
            throw new UnprocessableEntityHttpException("$at.$key: A text is expected here.");
        }

        return $value;
    }

    private static function structureKind(mixed $entry, string $at): StructureKind
    {
        return StructureKind::tryFrom(self::text($entry, 'kind', $at, null))
            ?? throw new UnprocessableEntityHttpException("$at.kind: A piece of structure is a wall, a door, a post or a dock.");
    }

    /** A distance as the floor form takes it, its rules left to VenueArea::size(). */
    private static function metres(mixed $entry, string $key, string $at): string
    {
        $value = \is_array($entry) ? ($entry[$key] ?? null) : null;
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            throw new UnprocessableEntityHttpException("$at.$key: A distance in metres is expected here.");
        }

        return (string) $value;
    }

    /** The rectangle an entry carries: the four distances it must have, a turn of 0 and no height when it has none. */
    private static function rect(mixed $entry, string $at): PlanRect
    {
        if (!\is_array($entry)) {
            throw new UnprocessableEntityHttpException("$at: A rectangle is expected here.");
        }
        $distance = static function (string $key, bool $required) use ($entry, $at): string {
            $value = $entry[$key] ?? ($required ? null : '0');
            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                throw new UnprocessableEntityHttpException("$at.$key: A distance in metres is expected here.");
            }

            return (string) $value;
        };
        $rotation = $entry['rotation'] ?? 0;
        if (!\is_int($rotation)) {
            throw new UnprocessableEntityHttpException("$at.rotation: A rotation is a whole number of degrees.");
        }

        try {
            return new PlanRect($distance('x', true), $distance('y', true), $distance('width', true), $distance('depth', true), $rotation, $distance('height', false));
        } catch (InvalidVenue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s.%s: %s', $at, $refused->field, $refused->getMessage()), $refused);
        }
    }
}
