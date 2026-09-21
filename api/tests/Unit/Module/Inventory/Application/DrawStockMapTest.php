<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Module\Inventory\Application\DrawStockMap;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\StockLocationNotFound;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockMovements;
use App\Tests\Support\InMemoryVenueAreas;
use App\Tests\Support\InMemoryVenueSpots;
use App\Tests\Support\InMemoryVenueStructures;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Domain\PlanRect;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * The stock map's composition rules (docs/SPEC.md row 83): a rectangle and the location it is drawn for are written
 * as one thing. What is checked here is what the HTTP surface cannot show — that no rectangle is left behind with
 * nothing bound to it, since such a rectangle is unlabelled on every screen and reachable from none.
 */
final class DrawStockMapTest extends TestCase
{
    private Company $company;
    private Establishment $establishment;
    private InMemoryVenueSpots $spots;
    private InMemoryEstablishments $establishments;
    private MockClock $clock;
    private ManageStockLocations $locations;
    private DrawStockMap $map;
    private Uuid $actor;
    private StockLocation $rack;
    private StockLocation $bay;

    protected function setUp(): void
    {
        $clock = $this->clock = new MockClock('2026-09-21 09:00:00');
        $transactions = new FakeTransactions();
        $audit = new InMemoryAuditTrail($transactions);
        $this->company = new Company('Quincaillerie Ben Ali', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->establishment = Establishment::create($this->company, '000', 'Bab Saadoun', true, $clock->now());
        $establishments = $this->establishments = new InMemoryEstablishments();
        $establishments->save($this->establishment);
        $areas = new InMemoryVenueAreas();
        $this->spots = new InMemoryVenueSpots();
        $venue = new ArrangeVenue($areas, $this->spots, new InMemoryVenueStructures(), $establishments, $audit, $clock, $transactions);
        $this->locations = new ManageStockLocations(new InMemoryStockLocations(), new InMemoryStockMovements(), $establishments, $audit, $clock, $transactions);
        $this->map = new DrawStockMap($venue, $this->locations, $transactions);
        $this->actor = Uuid::v7();
        $this->rack = $this->locations->create($this->company, $this->establishment->getId(), null, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $this->actor);
        $this->bay = $this->locations->create($this->company, $this->establishment->getId(), null, StockLocationKind::Zone, 'Z1', 'Zone de préparation', $this->actor);
    }

    public function testDrawingALocationASecondTimeMovesItRatherThanLeavingTheFirstRectangleBehind(): void
    {
        $ground = $this->floor('Rez-de-chaussée', 0);
        $first = $this->map->draw($this->company, $ground, $this->rack->getId(), $this->rect('1'), $this->actor)->getSpot();
        self::assertNotNull($first);

        $second = $this->map->draw($this->company, $ground, $this->rack->getId(), $this->rect('8'), $this->actor)->getSpot();

        self::assertNotNull($second);
        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertSame('8.000', $second->getRect()->x);
        // One rack, one rectangle: the one it was drawn on is gone, not merely unbound.
        self::assertCount(1, $this->spots->ofArea($ground));
        self::assertSame([$second->getId()->toRfc4122()], $this->drawnIds($ground));
    }

    public function testGivingARectangleToAnotherLocationErasesTheOneThatLocationHad(): void
    {
        $ground = $this->floor('Rez-de-chaussée', 0);
        $rackDrawing = $this->map->draw($this->company, $ground, $this->rack->getId(), $this->rect('1'), $this->actor)->getSpot();
        $bayDrawing = $this->map->draw($this->company, $ground, $this->bay->getId(), $this->rect('5'), $this->actor)->getSpot();
        self::assertNotNull($rackDrawing);
        self::assertNotNull($bayDrawing);
        self::assertCount(2, $this->spots->ofArea($ground));

        $moved = $this->map->moveDrawing($this->company, $rackDrawing->getId(), $this->bay->getId(), $this->rect('2'), $this->actor);

        self::assertSame($this->bay->getId()->toRfc4122(), $moved->getId()->toRfc4122());
        self::assertSame($rackDrawing->getId()->toRfc4122(), $moved->getSpot()?->getId()->toRfc4122());
        // The bay took over the rack's rectangle, so the one the bay had is erased and the rack is drawn nowhere.
        self::assertCount(1, $this->spots->ofArea($ground));
        self::assertNull($this->locations->get($this->company, $this->rack->getId())->getSpot());
        self::assertSame([$this->bay->getId()->toRfc4122()], array_map(static fn (StockLocation $l): string => $l->getId()->toRfc4122(), $this->map->drawingsOf($this->company, $ground)));
    }

    public function testRemovingAFloorUnbindsWhatWasDrawnOnItAndKeepsTheLocations(): void
    {
        $ground = $this->floor('Rez-de-chaussée', 0);
        $upstairs = $this->floor('Étage 1', 1);
        $this->map->draw($this->company, $ground, $this->rack->getId(), $this->rect('1'), $this->actor);
        $this->map->draw($this->company, $upstairs, $this->bay->getId(), $this->rect('1'), $this->actor);

        $this->map->removeFloor($this->company, $ground, $this->actor);

        self::assertNull($this->locations->get($this->company, $this->rack->getId())->getSpot());
        self::assertSame('R1', $this->locations->get($this->company, $this->rack->getId())->getCode());
        self::assertSame([], $this->spots->ofArea($ground));
        // The other floor is untouched, rectangle and binding alike.
        self::assertSame([$this->bay->getId()->toRfc4122()], array_map(static fn (StockLocation $l): string => $l->getId()->toRfc4122(), $this->map->drawingsOf($this->company, $upstairs)));
        self::assertCount(1, $this->map->floors($this->company));
    }

    public function testALocationOfAnotherCompanyIsNotDrawnAndNoRectangleIsLeftBehind(): void
    {
        $ground = $this->floor('Rez-de-chaussée', 0);

        try {
            $this->map->draw($this->company, $ground, Uuid::v7(), $this->rect('1'), $this->actor);
            self::fail('A rectangle was drawn for a location that does not exist.');
        } catch (StockLocationNotFound) {
            // The location is resolved first, so nothing was placed.
        }

        self::assertSame([], $this->spots->ofArea($ground));
    }

    public function testALocationIsDrawnOnAFloorOfItsOwnEstablishment(): void
    {
        $other = Establishment::create($this->company, '001', 'Dépôt de Sfax', false, $this->clock->now());
        $this->establishments->save($other);
        $elsewhere = $this->map->addFloor($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor)->getId();
        $foreign = $this->locations->create($this->company, $other->getId(), null, StockLocationKind::Rack, 'R9', 'Rayonnage lointain', $this->actor);

        $this->expectException(InvalidStockLocation::class);
        $this->map->draw($this->company, $elsewhere, $foreign->getId(), $this->rect('1'), $this->actor);
    }

    /**
     * A bin is not on the top-down plan (docs/SPEC.md § 7, 2026-09-21, decision 2): it sits in its rack's front view,
     * by column and level, and has no x, y on the ground. Refusing it here and not in the screen's picker is what
     * makes it true of the surface rather than of one caller.
     */
    public function testABinIsRefusedBecauseItIsPlacedInItsRackRatherThanOnTheFloor(): void
    {
        $ground = $this->floor('Rez-de-chaussée', 0);
        $bin = $this->locations->create($this->company, $this->establishment->getId(), $this->rack->getId(), StockLocationKind::Bin, 'R1-A1', 'Bac A1', $this->actor);

        try {
            $this->map->draw($this->company, $ground, $bin->getId(), $this->rect('1'), $this->actor);
            self::fail('A bin was drawn on the floor plan.');
        } catch (InvalidStockLocation $refused) {
            self::assertSame('locationId', $refused->field);
        }

        // Refused before anything was placed: no rectangle is left behind for a binding that never happened.
        self::assertCount(0, $this->spots->ofArea($ground));
    }

    /** The same rule on the other verb: a rectangle may not be handed to a bin either. */
    public function testARectangleCannotBeGivenToABin(): void
    {
        $ground = $this->floor('Rez-de-chaussée', 0);
        $drawing = $this->map->draw($this->company, $ground, $this->rack->getId(), $this->rect('1'), $this->actor)->getSpot();
        self::assertNotNull($drawing);
        $bin = $this->locations->create($this->company, $this->establishment->getId(), $this->rack->getId(), StockLocationKind::Bin, 'R1-A1', 'Bac A1', $this->actor);

        try {
            $this->map->moveDrawing($this->company, $drawing->getId(), $bin->getId(), $this->rect('2'), $this->actor);
            self::fail('A rectangle was given to a bin.');
        } catch (InvalidStockLocation $refused) {
            self::assertSame('locationId', $refused->field);
        }

        // The rack keeps the rectangle it had, unmoved: a refusal leaves the plan as it was.
        self::assertSame([$drawing->getId()->toRfc4122()], $this->drawnIds($ground));
        self::assertSame('1.000', $this->spots->ofArea($ground)[0]->getRect()->x);
    }

    private function floor(string $name, int $level): Uuid
    {
        return $this->map->addFloor($this->company, $this->establishment->getId(), $name, $level, $this->actor)->getId();
    }

    private function rect(string $x): PlanRect
    {
        return new PlanRect($x, '4', '3.9', '0.6', 0, '2.1');
    }

    /** @return list<string> */
    private function drawnIds(Uuid $floorId): array
    {
        return array_map(
            static fn (StockLocation $location): string => (string) $location->getSpot()?->getId()->toRfc4122(),
            $this->map->drawingsOf($this->company, $floorId),
        );
    }
}
