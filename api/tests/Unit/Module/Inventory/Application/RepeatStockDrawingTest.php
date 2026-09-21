<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Module\Inventory\Application\DrawStockMap;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\StockLocationCodeTaken;
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
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use App\Venue\Domain\PlanWay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Repeating a rack down an aisle (docs/SPEC.md row 83; the approved canvas's Repeat board). Nobody draws sixteen
 * identical racks one at a time, so one rectangle is copied N times — and each copy is a STOCK LOCATION as well as a
 * rectangle, because a rack that is drawn and does not exist is a picture, not a place goods can be put.
 *
 * What is pinned here is the promise the canvas makes in its own words: "une copie qui sortirait du sol est refusée
 * AVANT, pas après". Every refusal is checked before the first write, so a repeat that cannot finish leaves the plan
 * exactly as it was rather than half-built.
 */
final class RepeatStockDrawingTest extends TestCase
{
    private Company $company;
    private Establishment $establishment;
    private InMemoryVenueSpots $spots;
    private ArrangeVenue $venue;
    private ManageStockLocations $locations;
    private DrawStockMap $map;
    private Uuid $actor;
    private Uuid $ground;
    private StockLocation $rack;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-21 09:00:00');
        $transactions = new FakeTransactions();
        $audit = new InMemoryAuditTrail($transactions);
        $this->company = new Company('Quincaillerie Ben Ali', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->establishment = Establishment::create($this->company, '000', 'Bab Saadoun', true, $clock->now());
        $establishments = new InMemoryEstablishments();
        $establishments->save($this->establishment);
        $this->spots = new InMemoryVenueSpots();
        $this->venue = new ArrangeVenue(new InMemoryVenueAreas(), $this->spots, new InMemoryVenueStructures(), $establishments, $audit, $clock, $transactions);
        $this->locations = new ManageStockLocations(new InMemoryStockLocations(), new InMemoryStockMovements(), $establishments, $audit, $clock, $transactions);
        $this->map = new DrawStockMap($this->venue, $this->locations, $transactions);
        $this->actor = Uuid::v7();
        $this->ground = $this->map->addFloor($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor)->getId();
        $this->rack = $this->locations->create($this->company, $this->establishment->getId(), null, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $this->actor);
    }

    /**
     * The whole slice in one case: three more racks down the floor, each one metre-twenty further than the last, each
     * with its own code, each a location goods can be put in.
     */
    public function testRepeatingARackCreatesEveryCopyAndItsRectangle(): void
    {
        $source = $this->drawn('1', '4');

        $copies = $this->map->repeat($this->company, $source, 3, '0.600', PlanWay::Down, 'R2', $this->actor);

        self::assertSame(['R2', 'R3', 'R4'], array_map(static fn (StockLocation $l): string => $l->getCode(), $copies));
        // The gap is the FREE FLOOR between two rectangles, so the pitch is the rack's own depth plus it: 0,6 + 0,6.
        self::assertSame(['5.200', '6.400', '7.600'], $this->sides($copies, 'y'));
        // Straight down the aisle: nothing moves sideways, and every copy is the size of what it was copied from.
        self::assertSame(['1.000', '1.000', '1.000'], $this->sides($copies, 'x'));
        self::assertSame(['3.900', '3.900', '3.900'], $this->sides($copies, 'width'));
        self::assertSame(['0.600', '0.600', '0.600'], $this->sides($copies, 'depth'));
        // Four rectangles on the floor: the one copied, and the three made from it.
        self::assertCount(4, $this->spots->ofArea($this->ground));
        self::assertCount(4, $this->map->drawingsOf($this->company, $this->ground));
    }

    /** A copy is the same KIND of place as what it was copied from, under the same parent, with the same name. */
    public function testACopyIsTheSameKindOfPlaceAsWhatItWasCopiedFrom(): void
    {
        $source = $this->drawn('1', '4');

        [$copy] = $this->map->repeat($this->company, $source, 1, '0.600', PlanWay::Right, 'R2', $this->actor);

        self::assertSame(StockLocationKind::Rack, $copy->getKind());
        self::assertSame('Rayonnage 1', $copy->getName());
        self::assertSame($this->rack->getParent()?->getId()->toRfc4122(), $copy->getParent()?->getId()->toRfc4122());
        self::assertSame($this->establishment->getId()->toRfc4122(), $copy->getEstablishment()->getId()->toRfc4122());
    }

    /** Each way steps along the floor's own axes, which is what the four arrows beside the plan mean. */
    public function testEachWayStepsAlongItsOwnAxis(): void
    {
        self::assertSame(['5.200'], $this->sides($this->repeatFrom('4', '4', PlanWay::Down, 'RD'), 'y'));
        self::assertSame(['2.800'], $this->sides($this->repeatFrom('4', '4', PlanWay::Up, 'RU'), 'y'));
        // Sideways the step is the rectangle's WIDTH, not its depth: a rack is repeated shoulder to shoulder.
        self::assertSame(['8.500'], $this->sides($this->repeatFrom('4', '4', PlanWay::Right, 'RR'), 'x'));
        self::assertSame(['4.500'], $this->sides($this->repeatFrom('9', '4', PlanWay::Left, 'RL'), 'x'));
    }

    /**
     * A rectangle turned a quarter turn covers its WIDTH along the floor's y and its depth along x, so the step
     * swaps with it. Only the four right angles are allowed, exactly because their sines and cosines are whole
     * numbers: anything else would have the screen's preview and the server's arithmetic disagree in the third
     * decimal, and a preview that lies is worse than no preview.
     */
    public function testATurnedRectangleStepsByTheSideItActuallyCovers(): void
    {
        $source = $this->drawn('4', '4', 90);

        $copies = $this->map->repeat($this->company, $source, 1, '0.600', PlanWay::Down, 'R2', $this->actor);

        self::assertSame(['8.500'], $this->sides($copies, 'y'));
        self::assertSame(90, $copies[0]->getSpot()?->getRect()->rotation);
    }

    public function testARotationThatIsNotARightAngleIsRefused(): void
    {
        $source = $this->drawn('4', '4', 30);

        try {
            $this->map->repeat($this->company, $source, 1, '0.600', PlanWay::Down, 'R2', $this->actor);
            self::fail('A rectangle at thirty degrees was repeated.');
        } catch (InvalidVenue $refused) {
            self::assertSame('rotation', $refused->field);
        }

        self::assertCount(1, $this->spots->ofArea($this->ground));
    }

    /**
     * The canvas's own promise. Up from y = 4 by 1,2 a time reaches the floor's edge on the fourth copy — and the
     * refusal happens before the first one is written, not after three exist.
     */
    public function testACopyThatWouldLeaveTheFloorIsRefusedBeforeAnythingIsCreated(): void
    {
        $source = $this->drawn('1', '4');

        try {
            $this->map->repeat($this->company, $source, 4, '0.600', PlanWay::Up, 'R2', $this->actor);
            self::fail('A copy was placed off the floor.');
        } catch (InvalidVenue $refused) {
            self::assertSame('y', $refused->field);
        }

        self::assertCount(1, $this->spots->ofArea($this->ground));
        self::assertSame([], $this->codesLike('R'));
    }

    /** The same rule for the codes: all of them are checked, then all of them are created. */
    public function testATakenCodeIsRefusedBeforeAnythingIsCreated(): void
    {
        $source = $this->drawn('1', '4');
        $this->locations->create($this->company, $this->establishment->getId(), null, StockLocationKind::Rack, 'R3', 'Déjà là', $this->actor);

        try {
            $this->map->repeat($this->company, $source, 3, '0.600', PlanWay::Down, 'R2', $this->actor);
            self::fail('A repeat ran over a code that was taken.');
        } catch (StockLocationCodeTaken $taken) {
            self::assertStringContainsString('R3', $taken->getMessage());
        }

        self::assertCount(1, $this->spots->ofArea($this->ground));
        // R3 was already there; R2 and R4 were never written, so the run stopped before it began.
        self::assertSame(['R3'], $this->codesLike('R'));
    }

    /** `R01` is followed by `R02`, not by `R2`: the number keeps the width it was written with. */
    public function testTheNumberKeepsTheWidthItWasWrittenWith(): void
    {
        $padded = $this->locations->create($this->company, $this->establishment->getId(), null, StockLocationKind::Rack, 'R01', 'Rayonnage 01', $this->actor);
        $source = $this->map->draw($this->company, $this->ground, $padded->getId(), $this->rect('1', '4'), $this->actor)->getSpot();
        self::assertNotNull($source);

        $copies = $this->map->repeat($this->company, $source->getId(), 2, '0.600', PlanWay::Down, 'R02', $this->actor);

        self::assertSame(['R02', 'R03'], array_map(static fn (StockLocation $l): string => $l->getCode(), $copies));
    }

    public function testACodeWithNoNumberToFollowIsRefused(): void
    {
        $source = $this->drawn('1', '4');

        try {
            $this->map->repeat($this->company, $source, 2, '0.600', PlanWay::Down, 'RAYONNAGE', $this->actor);
            self::fail('A repeat started from a code with no number in it.');
        } catch (InvalidStockLocation $refused) {
            self::assertSame('firstCode', $refused->field);
        }

        self::assertSame([], $this->codesLike('R'));
    }

    public function testARectangleDrawnForNothingCannotBeRepeated(): void
    {
        $bare = $this->venue->place($this->company, $this->ground, $this->rect('1', '4'), $this->actor);

        $this->expectException(StockLocationNotFound::class);
        $this->map->repeat($this->company, $bare->getId(), 1, '0.600', PlanWay::Down, 'R2', $this->actor);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function impossibleCounts(): iterable
    {
        yield 'none at all' => [0];
        yield 'a negative count' => [-3];
        yield 'past the cap' => [DrawStockMap::REPEAT_LIMIT + 1];
    }

    /**
     * The cap is a typo guard and not a rule about warehouses: fifty racks in one gesture is already far more than
     * anyone draws deliberately, and a mistyped count would otherwise write hundreds of locations.
     */
    #[DataProvider('impossibleCounts')]
    public function testACountThatIsNotARepeatIsRefused(int $count): void
    {
        $source = $this->drawn('1', '4');

        try {
            $this->map->repeat($this->company, $source, $count, '0.600', PlanWay::Down, 'R2', $this->actor);
            self::fail(\sprintf('A repeat of %d was accepted.', $count));
        } catch (InvalidVenue $refused) {
            self::assertSame('count', $refused->field);
        }

        self::assertSame([], $this->codesLike('R'));
    }

    public function testASpacingThatIsNotAMeasurementIsRefused(): void
    {
        $source = $this->drawn('1', '4');

        try {
            $this->map->repeat($this->company, $source, 1, '-2', PlanWay::Down, 'R2', $this->actor);
            self::fail('A negative spacing was accepted.');
        } catch (InvalidVenue $refused) {
            self::assertSame('spacing', $refused->field);
        }

        self::assertSame([], $this->codesLike('R'));
    }

    /** Touching is a real arrangement — two racks back to back — so zero is a spacing and not a refusal. */
    public function testRacksMayBeRepeatedTouching(): void
    {
        $source = $this->drawn('1', '4');

        $copies = $this->map->repeat($this->company, $source, 2, '0', PlanWay::Down, 'R2', $this->actor);

        self::assertSame(['4.600', '5.200'], $this->sides($copies, 'y'));
    }

    private function drawn(string $x, string $y, int $rotation = 0): Uuid
    {
        $spot = $this->map->draw($this->company, $this->ground, $this->rack->getId(), $this->rect($x, $y, $rotation), $this->actor)->getSpot();
        self::assertNotNull($spot);

        return $spot->getId();
    }

    /**
     * One copy in the given direction, from a rack drawn fresh each time so the cases cannot interfere.
     *
     * @return list<StockLocation>
     */
    private function repeatFrom(string $x, string $y, PlanWay $way, string $code): array
    {
        $rack = $this->locations->create($this->company, $this->establishment->getId(), null, StockLocationKind::Rack, $code.'1', 'Rayonnage '.$code, $this->actor);
        $spot = $this->map->draw($this->company, $this->ground, $rack->getId(), $this->rect($x, $y), $this->actor)->getSpot();
        self::assertNotNull($spot);

        return $this->map->repeat($this->company, $spot->getId(), 1, '0.600', $way, $code.'2', $this->actor);
    }

    /**
     * Every location whose code starts with the given stem, EXCEPT the one drawn in setUp — so a case asserting that
     * nothing was created says exactly that, without counting the establishment's own default root location, which
     * `create()` makes on its own the first time a location is created without a parent.
     *
     * @return list<string>
     */
    private function codesLike(string $stem): array
    {
        $codes = [];
        foreach ($this->locations->list($this->company) as $location) {
            $code = $location->getCode();
            if (str_starts_with($code, $stem) && 'R1' !== $code) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    private function rect(string $x, string $y, int $rotation = 0): PlanRect
    {
        return new PlanRect($x, $y, '3.9', '0.6', $rotation, '2.1');
    }

    /**
     * @param list<StockLocation> $copies
     *
     * @return list<string>
     */
    private function sides(array $copies, string $side): array
    {
        return array_map(static function (StockLocation $location) use ($side): string {
            $rect = $location->getSpot()?->getRect() ?? throw new \LogicException('A copy was created without a rectangle.');

            return match ($side) {
                'x' => $rect->x,
                'y' => $rect->y,
                'width' => $rect->width,
                'depth' => $rect->depth,
                default => throw new \LogicException('No such side.'),
            };
        }, $copies);
    }
}
