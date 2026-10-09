<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\DrawStockMap;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * A group's step on the stock map. Each entry is read here into a place or a rectangle and a PlanRect, and a refusal
 * names it by where it sits in what was sent — `moves[1].x`, `draws[0].locationId` — since the entries of a group are
 * not boxes of a form a person can see.
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
            $moves[] = [self::id($entry, 'drawingId', "moves[$at]"), self::rect($entry, "moves[$at]")];
        }
        $erasures = [];
        foreach ($data->erasures as $at => $drawingId) {
            if (!\is_string($drawingId) || !Uuid::isValid($drawingId)) {
                throw new UnprocessableEntityHttpException("erasures[$at]: A rectangle is named by its id.");
            }
            $erasures[] = Uuid::fromString($drawingId);
        }

        try {
            $drawn = $this->map->change($company, CompanyPath::identifier($uriVariables, 'floorId'), $draws, $moves, $erasures, $actor);
        } catch (VenueAreaNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidStockLocation $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $data->drawings = array_map(
            static fn (StockLocation $location): StockDrawingResource => StockDrawingResource::ofDrawn($location),
            $drawn,
        );

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
