<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\StockMap;

use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Venue\Domain\ValueObject\PlanRect;
use App\Venue\Domain\ValueObject\StructureKind;
use Symfony\Component\Uid\Uuid;

/**
 * Everything a person changed on one floor of the stock map before pressing « Enregistrer »: its racks and its
 * building alike, saved as one step by DrawStockMap::change(). Each list is in the order it was sent, since a refusal
 * names its entry by that place.
 */
final readonly class StockMapStep
{
    /**
     * @param list<array{Uuid, PlanRect}>                              $draws             a place, and the rectangle to draw it at
     * @param list<array{Uuid, PlanRect, int, ?Uuid}>                  $moves             a rectangle of this floor, where it now stands, its version, and the place it is drawn for from now on (null: the same)
     * @param list<array{Uuid, int}>                                   $erasures          a rectangle of this floor to undraw, and its version
     * @param list<array{StockLocationKind, string, string, PlanRect}> $newPlaces         a place to create with its rectangle: kind, code, name
     * @param list<array{StructureKind, string, PlanRect}>             $structureBuilds   a piece of the building to build: kind, name
     * @param list<array{Uuid, int, StructureKind, string, PlanRect}>  $structureMoves    a piece of this floor, its version, and what it is now
     * @param list<array{Uuid, int}>                                   $structureErasures a piece of this floor to remove, and its version
     * @param array{string, string, int}|null                          $floor             the floor's new width and depth in metres, and its version; null leaves it as it is
     */
    public function __construct(
        public array $draws = [],
        public array $moves = [],
        public array $erasures = [],
        public array $newPlaces = [],
        public array $structureBuilds = [],
        public array $structureMoves = [],
        public array $structureErasures = [],
        public ?array $floor = null,
    ) {
    }
}
