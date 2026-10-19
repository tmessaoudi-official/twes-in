<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\StockMap;

use App\Module\Inventory\Domain\Model\StockLocation;
use App\Venue\Domain\Model\VenueArea;
use App\Venue\Domain\Model\VenueStructure;
use Symfony\Component\Uid\Uuid;

/**
 * A floor as a step left it, and what the step created, each in the order it was sent: the screen's « Annuler » erases
 * what was drawn, deletes the places that were created and removes the pieces that were built.
 */
final readonly class StockMapStepDone
{
    /**
     * @param list<StockLocation>     $drawings   what is drawn on the floor, by code
     * @param list<VenueStructure>    $structures what is built on the floor
     * @param list<Uuid>              $drawn      the rectangle of each `draws` entry
     * @param list<array{Uuid, Uuid}> $newPlaces  the rectangle and the place of each `newPlaces` entry
     * @param list<Uuid>              $built      the piece of each `structureBuilds` entry
     */
    public function __construct(
        public VenueArea $floor,
        public array $drawings,
        public array $structures,
        public array $drawn,
        public array $newPlaces,
        public array $built,
    ) {
    }
}
