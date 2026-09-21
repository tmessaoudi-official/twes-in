<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Application\VenueLevelTaken;
use App\Venue\Application\VenueSpotNotFound;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use App\Venue\Domain\VenueArea;
use App\Venue\Domain\VenueSpot;
use Symfony\Component\Uid\Uuid;

/**
 * The stock map: the venue's floors and rectangles, read and changed as a warehouse (docs/SPEC.md row 83; § 7,
 * 2026-09-21). The rectangles belong to the venue, which knows nothing of stock; the binding from a rectangle to a
 * stock location belongs here. This is where the two are done as ONE thing, so nothing drawn through this surface is
 * left without the location it was drawn for, and undrawing it never takes the location with it.
 *
 * One path is outside it: deleting a stock location that is drawn. The foreign key runs from the location to the
 * rectangle, so the rectangle stays, bound to nothing. What is READ is bound rectangles only, so it is on no screen,
 * and removing its floor sweeps it — it is a dead row, never a rectangle somebody sees and cannot label.
 *
 * The venue is a library context with no surface of its own, the way Files is: a permission belongs to a module, and
 * a floor plan of a warehouse is read and drawn by whoever may read and arrange stock.
 */
final readonly class DrawStockMap
{
    public function __construct(
        private ArrangeVenue $venue,
        private ManageStockLocations $locations,
        private Transactions $transactions,
    ) {
    }

    /**
     * Every floor the company draws, each with the locations drawn on it, by code. One read of the locations serves
     * every floor, so a company with ten floors still asks once.
     *
     * @return list<array{VenueArea, list<StockLocation>}>
     */
    public function floors(Company $company): array
    {
        $drawnOn = $this->byArea($company);

        return array_map(
            static fn (VenueArea $area): array => [$area, $drawnOn[$area->getId()->toRfc4122()] ?? []],
            $this->venue->areas($company),
        );
    }

    /**
     * What is drawn on one floor, by code.
     *
     * @return list<StockLocation>
     *
     * @throws VenueAreaNotFound
     */
    public function drawingsOf(Company $company, Uuid $floorId): array
    {
        $area = $this->venue->area($company, $floorId);

        return $this->byArea($company)[$area->getId()->toRfc4122()] ?? [];
    }

    /**
     * @throws VenueAreaNotFound
     * @throws VenueLevelTaken
     * @throws InvalidVenue
     */
    public function addFloor(Company $company, Uuid $establishmentId, string $name, int $level, ?Uuid $actorUserId): VenueArea
    {
        return $this->venue->addArea($company, $establishmentId, $name, $level, $actorUserId);
    }

    /**
     * A floor's name, its level and the plan behind it are one form on one screen, so they are one unit of work here:
     * a save that renamed the floor and then refused its scale would leave half of what was filled in.
     *
     * @throws VenueAreaNotFound
     * @throws VenueLevelTaken
     * @throws InvalidVenue
     */
    public function reviseFloor(Company $company, Uuid $floorId, string $name, int $level, ?Uuid $imageFileId, ?string $imageMetresWide, int $imageOpacity, ?Uuid $actorUserId): VenueArea
    {
        return $this->transactions->run(function () use ($company, $floorId, $name, $level, $imageFileId, $imageMetresWide, $imageOpacity, $actorUserId): VenueArea {
            $this->venue->reviseArea($company, $floorId, $name, $level, $actorUserId);

            return $this->venue->showPlan($company, $floorId, $imageFileId, $imageMetresWide, $imageOpacity, $actorUserId);
        });
    }

    /**
     * The floor stops being drawn and its rectangles go with it. What they were drawn for stays: a stock location
     * that is on no plan is still a stock location, with its code, its tree and its stock.
     *
     * @throws VenueAreaNotFound
     */
    public function removeFloor(Company $company, Uuid $floorId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $floorId, $actorUserId): void {
            foreach ($this->drawingsOf($company, $floorId) as $location) {
                $this->locations->drawAt($company, $location->getId(), null, $actorUserId);
            }
            $this->venue->removeArea($company, $floorId, $actorUserId);
        });
    }

    /**
     * Draws a location on a floor. The location is resolved first: a rectangle placed for a location that turns out
     * not to exist would be a rectangle nobody can label and nobody can reach.
     *
     * @throws VenueAreaNotFound
     * @throws StockLocationNotFound
     * @throws InvalidVenue
     * @throws InvalidStockLocation
     */
    public function draw(Company $company, Uuid $floorId, Uuid $locationId, PlanRect $rect, ?Uuid $actorUserId): StockLocation
    {
        return $this->transactions->run(function () use ($company, $floorId, $locationId, $rect, $actorUserId): StockLocation {
            // A location is in one place, so it is drawn in one place: drawing it again erases where it was.
            $previous = $this->drawable($company, $locationId)->getSpot();
            $spot = $this->venue->place($company, $floorId, $rect, $actorUserId);
            $drawn = $this->locations->drawAt($company, $locationId, $spot, $actorUserId);
            if (null !== $previous) {
                $this->venue->removeSpot($company, $previous->getId(), $actorUserId);
            }

            return $drawn;
        });
    }

    /**
     * Moves or resizes a rectangle, and says which location it is drawn for — the same one, or another, which is how
     * a rectangle drawn for the wrong rack is put right without erasing it.
     *
     * @throws VenueSpotNotFound
     * @throws StockLocationNotFound
     * @throws InvalidVenue
     * @throws InvalidStockLocation
     */
    public function moveDrawing(Company $company, Uuid $drawingId, Uuid $locationId, PlanRect $rect, ?Uuid $actorUserId): StockLocation
    {
        return $this->transactions->run(function () use ($company, $drawingId, $locationId, $rect, $actorUserId): StockLocation {
            // Resolved and checked before the rectangle moves, for the reason draw() resolves first: a refusal must
            // leave the plan as it was, not as it would have been.
            $previous = $this->drawable($company, $locationId)->getSpot();
            $spot = $this->venue->moveSpot($company, $drawingId, $rect, $actorUserId);
            foreach ($this->drawnAt($company, $spot) as $drawn) {
                if (!$drawn->getId()->equals($locationId)) {
                    $this->locations->drawAt($company, $drawn->getId(), null, $actorUserId);
                }
            }
            $bound = $this->locations->drawAt($company, $locationId, $spot, $actorUserId);
            // Given to another location, the rectangle that one was drawn on is erased rather than left unlabelled.
            if (null !== $previous && !$previous->getId()->equals($spot->getId())) {
                $this->venue->removeSpot($company, $previous->getId(), $actorUserId);
            }

            return $bound;
        });
    }

    /**
     * The rectangle is erased; what it was drawn for is untouched.
     *
     * @throws VenueSpotNotFound
     */
    public function eraseDrawing(Company $company, Uuid $drawingId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $drawingId, $actorUserId): void {
            $spot = $this->venue->spot($company, $drawingId);
            foreach ($this->drawnAt($company, $spot) as $drawn) {
                $this->locations->drawAt($company, $drawn->getId(), null, $actorUserId);
            }
            $this->venue->removeSpot($company, $drawingId, $actorUserId);
        });
    }

    /**
     * The location a rectangle is about to be drawn for, refused when its kind is not one the plan carries
     * (docs/SPEC.md § 7, 2026-09-21, decision 2). It names `locationId` because that is the field of what was sent.
     *
     * @throws StockLocationNotFound
     * @throws InvalidStockLocation
     */
    private function drawable(Company $company, Uuid $locationId): StockLocation
    {
        $location = $this->locations->get($company, $locationId);
        if (!$location->getKind()->isDrawable()) {
            throw new InvalidStockLocation('locationId', 'A bin is placed in its rack rather than on the floor plan.');
        }

        return $location;
    }

    /**
     * The locations drawn on each floor, keyed by the floor's identifier and by code within it.
     *
     * @return array<string, list<StockLocation>>
     */
    private function byArea(Company $company): array
    {
        $byArea = [];
        foreach ($this->locations->drawn($company) as $location) {
            $spot = $location->getSpot();
            if (null !== $spot) {
                $byArea[$spot->getArea()->getId()->toRfc4122()][] = $location;
            }
        }

        return $byArea;
    }

    /**
     * @return list<StockLocation> what this rectangle is drawn for, which is one location or, until it is bound, none
     */
    private function drawnAt(Company $company, VenueSpot $spot): array
    {
        return array_values(array_filter(
            $this->locations->drawn($company),
            static fn (StockLocation $location): bool => true === $location->getSpot()?->getId()->equals($spot->getId()),
        ));
    }
}
