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
use App\Venue\Domain\PlanWay;
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
    /**
     * How many copies one repeat may make. A typo guard, not a rule about warehouses: fifty racks in a single gesture
     * is already far more than anyone draws deliberately, and a mistyped count would otherwise write hundreds of
     * stock locations that then have to be deleted one at a time.
     */
    public const int REPEAT_LIMIT = 50;

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
    public function addFloor(Company $company, Uuid $establishmentId, string $name, int $level, string $widthMetres, string $depthMetres, ?Uuid $actorUserId): VenueArea
    {
        // Asked when the floor is added (docs/SPEC.md § 7, 2026-09-22): one unit of work, so a refused size adds nothing.
        return $this->transactions->run(function () use ($company, $establishmentId, $name, $level, $widthMetres, $depthMetres, $actorUserId): VenueArea {
            $floor = $this->venue->addArea($company, $establishmentId, $name, $level, $actorUserId);

            return $this->venue->measureArea($company, $floor->getId(), $widthMetres, $depthMetres, $actorUserId);
        });
    }

    /**
     * A floor's name, its level, its size and the plan behind it are one form on one screen, so they are one unit of work here:
     * a save that renamed the floor and then refused its scale would leave half of what was filled in.
     *
     * @throws VenueAreaNotFound
     * @throws VenueLevelTaken
     * @throws InvalidVenue
     */
    public function reviseFloor(Company $company, Uuid $floorId, string $name, int $level, string $widthMetres, string $depthMetres, ?Uuid $imageFileId, ?string $imageMetresWide, int $imageOpacity, ?Uuid $actorUserId): VenueArea
    {
        return $this->transactions->run(function () use ($company, $floorId, $name, $level, $widthMetres, $depthMetres, $imageFileId, $imageMetresWide, $imageOpacity, $actorUserId): VenueArea {
            $this->venue->reviseArea($company, $floorId, $name, $level, $actorUserId);
            $this->venue->measureArea($company, $floorId, $widthMetres, $depthMetres, $actorUserId);

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
     * Repeats a rectangle down an aisle: N more of it, each one spacing further than the last, and each one a STOCK
     * LOCATION as well as a rectangle (docs/SPEC.md row 83; the approved canvas's Repeat board). Nobody draws sixteen
     * identical racks one at a time, and a rack that is drawn but does not exist is a picture, not a place.
     *
     * Everything is checked BEFORE the first write — the canvas says so in its own words, "une copie qui sortirait du
     * sol est refusée avant, pas après" — so a repeat that cannot finish leaves the plan exactly as it was. The three
     * things that can stop it are the count, a copy that would step off the floor, and a code already taken; all are
     * decided from the plan as it stands, before a single location exists.
     *
     * `$spacing` is the FREE FLOOR between two rectangles, not the distance between their near edges: zero means back
     * to back, and no value a person can type makes two copies overlap. The approved canvas's own arrow measures the
     * pitch instead; this is the one departure from it, recorded in docs/SPEC.md § 7 with its reason.
     *
     * @return list<StockLocation> the copies, in the order they were placed, each with its rectangle
     *
     * @throws VenueSpotNotFound
     * @throws StockLocationNotFound
     * @throws StockLocationCodeTaken
     * @throws InvalidVenue
     * @throws InvalidStockLocation
     */
    public function repeat(Company $company, Uuid $drawingId, int $count, string $spacing, PlanWay $way, string $firstCode, ?Uuid $actorUserId): array
    {
        if ($count < 1 || $count > self::REPEAT_LIMIT) {
            throw new InvalidVenue('count', \sprintf('A repeat makes between 1 and %d more of a rectangle.', self::REPEAT_LIMIT));
        }
        $gap = PlanRect::distance('spacing', $spacing, false);
        $spot = $this->venue->spot($company, $drawingId);
        $source = $this->drawnAt($company, $spot)[0]
            ?? throw new StockLocationNotFound('This rectangle is drawn for no location, so there is nothing to repeat.');

        // Resolved in full before anything is written: the rectangles first, because a step off the floor is refused
        // by PlanRect itself, then the codes, which are the other way a repeat can turn out to be impossible.
        $rects = $this->steps($spot->getRect(), $count, $gap, $way);
        $codes = $this->codes($company, $source, $firstCode, $count);

        $floorId = $spot->getArea()->getId();

        return $this->transactions->run(function () use ($company, $source, $floorId, $rects, $codes, $actorUserId): array {
            $copies = [];
            foreach ($rects as $at => $rect) {
                $copy = $this->locations->create(
                    $company,
                    $source->getEstablishment()->getId(),
                    $source->getParent()?->getId(),
                    $source->getKind(),
                    $codes[$at],
                    $source->getName(),
                    $actorUserId,
                );
                $copies[] = $this->draw($company, $floorId, $copy->getId(), $rect, $actorUserId);
            }

            return $copies;
        });
    }

    /**
     * Where each copy lands. The step runs along the FLOOR's axis, and its size is the side the rectangle actually
     * covers along that axis — a rack turned a quarter turn is as wide across the floor's y as it is deep across x.
     *
     * Only the four right angles are allowed, and that is not squeamishness: their sines and cosines are whole
     * numbers, so bcmath here and the screen's preview agree exactly. At any other angle the two would part company
     * in the third decimal, and a preview that does not show what will be created is worse than none.
     *
     * @param numeric-string $gap the free floor between two copies, as `PlanRect::distance()` normalized it — the
     *                            signature says so because a plain `string` reaching bcmath is how a value that was
     *                            never validated gets there
     *
     * @return list<PlanRect>
     *
     * @throws InvalidVenue when a copy would step off the floor, naming the side it left by
     */
    private function steps(PlanRect $rect, int $count, string $gap, PlanWay $way): array
    {
        if (0 !== $rect->rotation % 90) {
            throw new InvalidVenue('rotation', 'A rectangle is repeated from a quarter turn: 0, 90, 180 or 270 degrees.');
        }
        $turned = 0 !== ($rect->rotation / 90) % 2;
        $across = $way->isVertical() === $turned ? $rect->width : $rect->depth;
        $pitch = bcadd($across, $gap, PlanRect::SCALE);

        $steps = [];
        for ($made = 1; $made <= $count; ++$made) {
            $away = bcmul($pitch, (string) $made, PlanRect::SCALE);
            $moved = $way->isBackwards() ? bcsub($way->isVertical() ? $rect->y : $rect->x, $away, PlanRect::SCALE) : bcadd($way->isVertical() ? $rect->y : $rect->x, $away, PlanRect::SCALE);
            // A copy stepped past the floor's own corner is refused here, by the side it left by: PlanRect knows no
            // distance below zero, so `-0.800` never becomes a rectangle in the first place.
            $steps[] = $way->isVertical()
                ? new PlanRect($rect->x, $moved, $rect->width, $rect->depth, $rect->rotation, $rect->height)
                : new PlanRect($moved, $rect->y, $rect->width, $rect->depth, $rect->rotation, $rect->height);
        }

        return $steps;
    }

    /**
     * The codes the copies will carry: the first as it was typed, then its number counted on, keeping the width it
     * was written with so `R01` is followed by `R02` and not by `R2`.
     *
     * @return list<string>
     *
     * @throws InvalidStockLocation   when the first code has no number to count on from
     * @throws StockLocationCodeTaken naming the code that is already in use
     */
    private function codes(Company $company, StockLocation $source, string $firstCode, int $count): array
    {
        $first = trim($firstCode);
        if (1 !== preg_match('/^(.*?)([0-9]+)$/', $first, $found)) {
            throw new InvalidStockLocation('firstCode', 'A repeat counts on from a code ending in a number, such as R2.');
        }
        [, $stem, $number] = $found;
        $width = \strlen($number);

        $codes = [];
        $taken = [];
        foreach ($this->locations->list($company) as $location) {
            if ($location->getEstablishment()->getId()->equals($source->getEstablishment()->getId())) {
                $taken[$location->getCode()] = true;
            }
        }
        for ($made = 0; $made < $count; ++$made) {
            // Padded back to the width it was typed with, and never truncated: R99 is followed by R100.
            $code = $stem.str_pad((string) ((int) $number + $made), $width, '0', \STR_PAD_LEFT);
            if (isset($taken[$code])) {
                throw new StockLocationCodeTaken($code);
            }
            $taken[$code] = true;
            $codes[] = $code;
        }

        return $codes;
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
