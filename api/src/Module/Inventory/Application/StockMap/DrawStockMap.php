<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\StockMap;

use App\Module\Inventory\Application\Location\ManageStockLocations;
use App\Module\Inventory\Application\Location\StockLocationCodeTaken;
use App\Module\Inventory\Application\Location\StockLocationNotFound;
use App\Module\Inventory\Domain\Exception\InvalidStockLocation;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Application\VenueLevelTaken;
use App\Venue\Application\VenueSpotNotFound;
use App\Venue\Application\VenueStructureNotFound;
use App\Venue\Domain\Exception\InvalidVenue;
use App\Venue\Domain\Model\VenueArea;
use App\Venue\Domain\Model\VenueSpot;
use App\Venue\Domain\Model\VenueStructure;
use App\Venue\Domain\ValueObject\PlanRect;
use App\Venue\Domain\ValueObject\PlanWay;
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
     * @throws RecordChanged
     */
    public function reviseFloor(Company $company, Uuid $floorId, string $name, int $level, string $widthMetres, string $depthMetres, ?Uuid $imageFileId, ?string $imageMetresWide, int $imageOpacity, ?Uuid $actorUserId, ?int $version = null): VenueArea
    {
        return $this->transactions->run(function () use ($company, $floorId, $name, $level, $widthMetres, $depthMetres, $imageFileId, $imageMetresWide, $imageOpacity, $actorUserId, $version): VenueArea {
            // The version is checked before anything of the form is written, so a refusal leaves the floor as it was.
            $this->venue->reviseArea($company, $floorId, $name, $level, $actorUserId, $version);
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
     * Draws a place that does not exist yet: a rack or a zone posed from the palette is created with its rectangle, as
     * one unit of work, so a box nobody could save is never drawn and a refused rectangle leaves no location behind.
     *
     * The new place is put in the zone it is drawn in — the innermost drawn zone of this floor holding its centre, as
     * that zone stands, turned or not — and otherwise under its establishment's default place, as a place created
     * from the locations list is. A rack drawn in « Zone A » is then a place of zone A without anybody saying so twice.
     *
     * @throws VenueAreaNotFound
     * @throws StockLocationCodeTaken
     * @throws InvalidVenue
     * @throws InvalidStockLocation   naming the `newLocation…` field it refuses
     */
    public function drawNew(Company $company, Uuid $floorId, StockLocationKind $kind, string $code, string $name, PlanRect $rect, ?Uuid $actorUserId): StockLocation
    {
        if (!$kind->isDrawable()) {
            throw new InvalidStockLocation('newLocationKind', 'A bin is placed in its rack rather than on the floor plan.');
        }
        $floor = $this->venue->area($company, $floorId);
        $zone = $this->zoneHolding($company, $floorId, $rect);

        return $this->transactions->run(function () use ($company, $floor, $zone, $kind, $code, $name, $rect, $actorUserId): StockLocation {
            try {
                $made = $this->locations->create($company, $floor->getEstablishment()->getId(), $zone?->getId(), $kind, $code, $name, $actorUserId);
            } catch (InvalidStockLocation $refused) {
                // The form sent `newLocationCode`, not `code`: the refusal names the field that was typed.
                $field = match ($refused->field) {
                    'code' => 'newLocationCode',
                    'name' => 'newLocationName',
                    'kind' => 'newLocationKind',
                    default => $refused->field,
                };
                throw new InvalidStockLocation($field, $refused->getMessage());
            }

            return $this->draw($company, $floor->getId(), $made->getId(), $rect, $actorUserId);
        });
    }

    /**
     * The innermost drawn zone of a floor holding a rectangle's centre: of the zones that hold it, the smallest, since
     * a zone drawn inside another is the narrower answer to « where is it ».
     */
    private function zoneHolding(Company $company, Uuid $floorId, PlanRect $rect): ?StockLocation
    {
        $x = (float) $rect->x + (float) $rect->width / 2; // float: a point on the plan, compared and never stored
        $y = (float) $rect->y + (float) $rect->depth / 2; // float: a point on the plan, compared and never stored

        $holding = null;
        $smallest = null;
        foreach ($this->drawingsOf($company, $floorId) as $location) {
            $spot = $location->getSpot();
            if (StockLocationKind::Zone !== $location->getKind() || null === $spot || !self::holds($spot->getRect(), $x, $y)) {
                continue;
            }
            $size = (float) $spot->getRect()->width * (float) $spot->getRect()->depth; // float: compared, never stored
            if (null === $smallest || $size < $smallest) {
                [$holding, $smallest] = [$location, $size];
            }
        }

        return $holding;
    }

    /**
     * Whether a point lies in a rectangle as it stands, turned clockwise about its own centre as the screen draws it:
     * the point is turned back by the rectangle's rotation, into the frame where the rectangle is a plain box.
     */
    private static function holds(PlanRect $box, float $x, float $y): bool
    {
        $halfWidth = (float) $box->width / 2; // float: a distance on the plan, compared and never stored
        $halfDepth = (float) $box->depth / 2; // float: a distance on the plan, compared and never stored
        $dx = $x - ((float) $box->x + $halfWidth); // float: a distance on the plan, compared and never stored
        $dy = $y - ((float) $box->y + $halfDepth); // float: a distance on the plan, compared and never stored
        $angle = deg2rad($box->rotation);
        // Half a millimetre's tolerance: the plan's scale is three decimals, and a centre on the edge is inside.
        $edge = 0.0005;

        return abs($dx * cos($angle) + $dy * sin($angle)) <= $halfWidth + $edge
            && abs($dy * cos($angle) - $dx * sin($angle)) <= $halfDepth + $edge;
    }

    /**
     * Moves or resizes a rectangle, and says which location it is drawn for — the same one, or another, which is how
     * a rectangle drawn for the wrong rack is put right without erasing it.
     *
     * @throws VenueSpotNotFound
     * @throws StockLocationNotFound
     * @throws InvalidVenue
     * @throws InvalidStockLocation
     * @throws RecordChanged         when the rectangle was saved since `$version` was read
     */
    public function moveDrawing(Company $company, Uuid $drawingId, Uuid $locationId, PlanRect $rect, ?Uuid $actorUserId, ?int $version = null): StockLocation
    {
        return $this->transactions->run(function () use ($company, $drawingId, $locationId, $rect, $actorUserId, $version): StockLocation {
            // Resolved and checked before the rectangle moves, for the reason draw() resolves first: a refusal must
            // leave the plan as it was, not as it would have been.
            $previous = $this->drawable($company, $locationId)->getSpot();
            $spot = $this->venue->moveSpot($company, $drawingId, $rect, $actorUserId, $version);
            $relabelled = true;
            foreach ($this->drawnAt($company, $spot) as $drawn) {
                if ($drawn->getId()->equals($locationId)) {
                    $relabelled = false;
                } else {
                    $this->locations->drawAt($company, $drawn->getId(), null, $actorUserId);
                }
            }
            $bound = $this->locations->drawAt($company, $locationId, $spot, $actorUserId);
            // Pointed at another place, the rectangle is another version even where it stands, so a person who opened
            // it labelled as it was is told rather than putting the old label back.
            if ($relabelled) {
                $this->venue->rebind($company, $spot->getId(), $actorUserId);
            }
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
     * One floor's draft, saved as one step: rectangles drawn for places, moved, turned, given another place or undrawn,
     * places created with their rectangles, the building's pieces built, reshaped or removed, and the floor's own size,
     * all of it or none of it. The inverse of a step is a step of the same shape, which is how one « Annuler » takes a
     * whole draft back; the answer says what the step created, so that inverse can be built.
     *
     * Everything named is resolved and checked before the first write, so a refusal leaves the floor exactly as it was
     * and names the entry it refuses by its place in what was sent (`structureMoves[2].x`): a bare "x" in a draft of
     * twelve changes leaves a person guessing.
     *
     * Every rectangle, piece and floor changed names the version it was read at, and one saved since refuses the whole
     * step: the draft is read again and saved on what is there, rather than half of it over someone else's change. The
     * versions are checked once, together, before anything is written; the writes themselves carry none.
     *
     * @throws VenueAreaNotFound
     * @throws InvalidStockLocation naming the entry of a rectangle or a place it refuses
     * @throws InvalidVenue         naming the entry of a piece of the building, or the floor's side, it refuses
     * @throws RecordChanged        when anything named was saved since the version named for it
     */
    public function change(Company $company, Uuid $floorId, StockMapStep $step, ?Uuid $actorUserId): StockMapStepDone
    {
        $floor = $this->venue->area($company, $floorId);
        $this->checkRectangles($company, $floor, $step);
        $this->checkNewPlaces($company, $floor, $step);
        $this->checkBuilding($company, $floor, $step);
        if (null !== $step->floor) {
            try {
                VenueArea::size($step->floor[0], $step->floor[1]);
            } catch (InvalidVenue $refused) {
                throw new InvalidVenue('floor.'.$refused->field, $refused->getMessage());
            }
        }

        return $this->transactions->run(function () use ($company, $floor, $step, $actorUserId): StockMapStepDone {
            // Checked once every entry is known to be well formed, so a malformed step is refused as such and a stale
            // one as stale, and before the first write, so a stale one leaves the floor as it was.
            if (null !== $step->floor) {
                $this->venue->expectArea($company, $floor->getId(), $step->floor[2]);
            }
            foreach ($step->moves as [$drawingId, , $version]) {
                $this->venue->expectSpot($company, $drawingId, $version);
            }
            foreach ($step->erasures as [$drawingId, $version]) {
                $this->venue->expectSpot($company, $drawingId, $version);
            }
            foreach ($step->structureMoves as [$structureId, $version]) {
                $this->venue->expectStructure($company, $structureId, $version);
            }
            foreach ($step->structureErasures as [$structureId, $version]) {
                $this->venue->expectStructure($company, $structureId, $version);
            }

            if (null !== $step->floor) {
                $this->venue->measureArea($company, $floor->getId(), $step->floor[0], $step->floor[1], $actorUserId);
            }
            // Undrawn first, so a place taken off a rectangle this step erases is free when a move gives it another.
            foreach ($step->erasures as [$drawingId]) {
                $this->eraseDrawing($company, $drawingId, $actorUserId);
            }
            foreach ($step->structureErasures as [$structureId]) {
                $this->venue->removeStructure($company, $structureId, $actorUserId);
            }
            foreach ($step->moves as [$drawingId, $rect, , $locationId]) {
                if (null === $locationId) {
                    $this->venue->moveSpot($company, $drawingId, $rect, $actorUserId);
                } else {
                    $this->moveDrawing($company, $drawingId, $locationId, $rect, $actorUserId);
                }
            }
            foreach ($step->structureMoves as [$structureId, , $kind, $name, $rect]) {
                $this->venue->reshapeStructure($company, $structureId, $kind, $name, $rect, $actorUserId);
            }
            $drawn = [];
            foreach ($step->draws as [$locationId, $rect]) {
                $drawn[] = self::spotOf($this->draw($company, $floor->getId(), $locationId, $rect, $actorUserId));
            }
            // Put in the zone holding it as the floor stands now, the step's moves and earlier new places included: a
            // rack drawn inside a zone the same draft moved belongs to that zone.
            $made = [];
            foreach ($step->newPlaces as [$kind, $code, $name, $rect]) {
                $zone = $this->zoneHolding($company, $floor->getId(), $rect);
                $place = $this->locations->create($company, $floor->getEstablishment()->getId(), $zone?->getId(), $kind, $code, $name, $actorUserId);
                $made[] = [self::spotOf($this->draw($company, $floor->getId(), $place->getId(), $rect, $actorUserId)), $place->getId()];
            }
            $built = [];
            foreach ($step->structureBuilds as [$kind, $name, $rect]) {
                $built[] = $this->venue->build($company, $floor->getId(), $kind, $name, $rect, $actorUserId)->getId();
            }

            return new StockMapStepDone(
                $floor,
                $this->drawingsOf($company, $floor->getId()),
                $this->venue->structuresOf($company, $floor->getId()),
                $drawn,
                $made,
                $built,
            );
        });
    }

    /**
     * The rectangles a step names, each of this floor and named once, and each place given one: drawable, of the
     * floor's establishment, given once, and free — drawn nowhere, on a rectangle this step erases, or on the very
     * rectangle it is given to. A place drawn on a rectangle the step keeps is refused rather than taken off it.
     *
     * @throws InvalidStockLocation naming the entry
     */
    private function checkRectangles(Company $company, VenueArea $floor, StockMapStep $step): void
    {
        $touched = [];
        $onThisFloor = function (Uuid $drawingId, string $field) use ($company, $floor, &$touched): void {
            try {
                $spot = $this->venue->spot($company, $drawingId);
            } catch (VenueSpotNotFound) {
                throw new InvalidStockLocation($field, 'No such rectangle is drawn.');
            }
            if (!$spot->getArea()->getId()->equals($floor->getId())) {
                throw new InvalidStockLocation($field, 'This rectangle is drawn on another floor.');
            }
            if (isset($touched[$drawingId->toRfc4122()])) {
                throw new InvalidStockLocation($field, 'A rectangle is named once in a step.');
            }
            $touched[$drawingId->toRfc4122()] = true;
        };
        foreach ($step->moves as $at => [$drawingId]) {
            $onThisFloor($drawingId, "moves[$at].drawingId");
        }
        $erased = [];
        foreach ($step->erasures as $at => [$drawingId]) {
            $onThisFloor($drawingId, "erasures[$at].drawingId");
            $erased[$drawingId->toRfc4122()] = true;
        }
        $placed = [];
        foreach ($step->moves as $at => [$drawingId, , , $locationId]) {
            if (null === $locationId) {
                continue;
            }
            $field = "moves[$at].locationId";
            $drawnOn = $this->placeFor($company, $floor, $locationId, $field)->getSpot()?->getId();
            if (isset($placed[$locationId->toRfc4122()])) {
                throw new InvalidStockLocation($field, 'A place is drawn once in a step.');
            }
            if (null !== $drawnOn && !$drawnOn->equals($drawingId) && !isset($erased[$drawnOn->toRfc4122()])) {
                throw new InvalidStockLocation($field, 'This place is drawn on another rectangle, which the step keeps: undraw that one in the same step.');
            }
            $placed[$locationId->toRfc4122()] = true;
        }
        foreach ($step->draws as $at => [$locationId]) {
            $field = "draws[$at].locationId";
            $drawnOn = $this->placeFor($company, $floor, $locationId, $field)->getSpot()?->getId()->toRfc4122();
            if (isset($placed[$locationId->toRfc4122()]) || (null !== $drawnOn && isset($touched[$drawnOn]))) {
                throw new InvalidStockLocation($field, 'A place is drawn once in a step, and not over a rectangle the step moves or undraws.');
            }
            $placed[$locationId->toRfc4122()] = true;
        }
    }

    /**
     * A place a step gives a rectangle, refused under the field of the entry that names it: drawable, and of the
     * floor's establishment, which StockLocation::drawAt() would otherwise find out only in the middle of the writes.
     *
     * @throws InvalidStockLocation naming `$field`
     */
    private function placeFor(Company $company, VenueArea $floor, Uuid $locationId, string $field): StockLocation
    {
        try {
            $location = $this->drawable($company, $locationId);
        } catch (StockLocationNotFound|InvalidStockLocation $refused) {
            throw new InvalidStockLocation($field, $refused->getMessage());
        }
        if (!$location->getEstablishment()->getId()->equals($floor->getEstablishment()->getId())) {
            throw new InvalidStockLocation($field, 'A location is drawn on a floor of its own establishment.');
        }

        return $location;
    }

    /**
     * The places a step creates: a kind the plan carries, a code and a name create() would keep, and a code no place
     * of the floor's establishment has, nor another new place of the same step.
     *
     * @throws InvalidStockLocation naming the entry
     */
    private function checkNewPlaces(Company $company, VenueArea $floor, StockMapStep $step): void
    {
        if ([] === $step->newPlaces) {
            return;
        }
        $taken = [];
        foreach ($this->locations->list($company) as $location) {
            if ($location->getEstablishment()->getId()->equals($floor->getEstablishment()->getId())) {
                $taken[$location->getCode()] = true;
            }
        }
        $given = [];
        foreach ($step->newPlaces as $at => [$kind, $code, $name]) {
            if (!$kind->isDrawable()) {
                throw new InvalidStockLocation("newPlaces[$at].kind", 'A bin is placed in its rack rather than on the floor plan.');
            }
            try {
                $kept = StockLocation::namingOf($code, $name);
            } catch (InvalidStockLocation $refused) {
                throw new InvalidStockLocation("newPlaces[$at].{$refused->field}", $refused->getMessage());
            }
            if (isset($taken[$kept])) {
                throw new InvalidStockLocation("newPlaces[$at].code", new StockLocationCodeTaken($kept)->getMessage());
            }
            if (isset($given[$kept])) {
                throw new InvalidStockLocation("newPlaces[$at].code", 'A code is given to one new place of a step.');
            }
            $given[$kept] = true;
        }
    }

    /**
     * The pieces of the building a step names, each of this floor and named once, and each name one a piece keeps.
     *
     * @throws InvalidVenue naming the entry
     */
    private function checkBuilding(Company $company, VenueArea $floor, StockMapStep $step): void
    {
        $named = [];
        $onThisFloor = function (Uuid $structureId, string $field) use ($company, $floor, &$named): void {
            try {
                $structure = $this->venue->structure($company, $structureId);
            } catch (VenueStructureNotFound) {
                throw new InvalidVenue($field, 'No such piece of the building is drawn.');
            }
            if (!$structure->getArea()->getId()->equals($floor->getId())) {
                throw new InvalidVenue($field, 'This piece stands on another floor.');
            }
            if (isset($named[$structureId->toRfc4122()])) {
                throw new InvalidVenue($field, 'A piece is named once in a step.');
            }
            $named[$structureId->toRfc4122()] = true;
        };
        foreach ($step->structureMoves as $at => [$structureId, , , $name]) {
            $onThisFloor($structureId, "structureMoves[$at].structureId");
            self::pieceNamed($name, "structureMoves[$at].name");
        }
        foreach ($step->structureErasures as $at => [$structureId]) {
            $onThisFloor($structureId, "structureErasures[$at].structureId");
        }
        foreach ($step->structureBuilds as $at => [, $name]) {
            self::pieceNamed($name, "structureBuilds[$at].name");
        }
    }

    /** @throws InvalidVenue naming `$field` */
    private static function pieceNamed(string $name, string $field): void
    {
        try {
            VenueStructure::trimmedName($name);
        } catch (InvalidVenue $refused) {
            throw new InvalidVenue($field, $refused->getMessage());
        }
    }

    private static function spotOf(StockLocation $drawn): Uuid
    {
        return $drawn->getSpot()?->getId() ?? throw new \LogicException('A place just drawn is drawn on a rectangle.');
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
