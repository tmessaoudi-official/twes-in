<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Venue\Application;

use App\Audit\Application\AuditEntry;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryVenueAreas;
use App\Tests\Support\InMemoryVenueSpots;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Application\VenueLevelTaken;
use App\Venue\Application\VenueSpotNotFound;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Drawing a place (docs/SPEC.md § 7, 2026-09-14 and 2026-09-19 23:40). Every change is audited, which is also what
 * tells the open plans of other people to read themselves again: a use case that records nothing leaves the screen's
 * live reload permanently dead.
 */
final class ArrangeVenueTest extends TestCase
{
    private Company $company;
    private Establishment $establishment;
    private InMemoryVenueAreas $areas;
    private InMemoryVenueSpots $spots;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private ClockInterface $clock;
    private ArrangeVenue $venue;
    private Uuid $actor;

    protected function setUp(): void
    {
        $this->company = new Company('Quincaillerie Ben Ali', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->clock = new MockClock('2026-09-21 09:00:00');
        $this->establishment = Establishment::create($this->company, '000', 'Bab Saadoun', true, $this->clock->now());
        $establishments = new InMemoryEstablishments();
        $establishments->save($this->establishment);
        $this->areas = new InMemoryVenueAreas();
        $this->spots = new InMemoryVenueSpots();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->actor = Uuid::v7();
        $this->venue = new ArrangeVenue($this->areas, $this->spots, $establishments, $this->audit, $this->clock, $this->transactions);
    }

    public function testAFloorIsDrawnOncePerLevelAndIsListedFromTheGroundUp(): void
    {
        $this->venue->addArea($this->company, $this->establishment->getId(), 'Étage 1', 1, $this->actor);
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);

        self::assertSame(['Rez-de-chaussée', 'Étage 1'], array_map(static fn ($a) => $a->getName(), $this->venue->areas($this->company)));
        self::assertSame($ground->getId()->toRfc4122(), $this->venue->areas($this->company)[0]->getId()->toRfc4122());

        try {
            $this->venue->addArea($this->company, $this->establishment->getId(), 'Sous-sol dessiné en double', 0, $this->actor);
            self::fail('Two areas were drawn at the same level.');
        } catch (VenueLevelTaken $refused) {
            self::assertSame('level', $refused->field);
        }
    }

    public function testDrawingAndMovingARectangleIsAuditedSoOpenPlansReadThemselvesAgain(): void
    {
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);

        $spot = $this->venue->place($this->company, $ground->getId(), new PlanRect('2.5', '4', '3.9', '0.6', 0, '2.1'), $this->actor);
        $this->venue->moveSpot($this->company, $spot->getId(), new PlanRect('2.5', '5.4', '3.9', '0.6', 0, '2.1'), $this->actor);

        self::assertSame(
            [
                ['venue_area', 'venue_area.created'],
                ['venue_spot', 'venue_spot.created'],
                ['venue_spot', 'venue_spot.revised'],
            ],
            array_map(static fn (AuditEntry $entry) => [$entry->entityType, $entry->action], $this->audit->entries),
        );
        self::assertSame('5.400', $this->venue->spotsOf($this->company, $ground->getId())[0]->getRect()->y);
    }

    public function testMovingARectangleNowhereIsNotAChange(): void
    {
        // Dragging a rectangle and dropping it where it was must not tell every other screen that the plan moved.
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);
        $rect = new PlanRect('2.5', '4', '3.9', '0.6', 0, '2.1');
        $spot = $this->venue->place($this->company, $ground->getId(), $rect, $this->actor);
        $this->audit->entries = [];

        $this->venue->moveSpot($this->company, $spot->getId(), $rect, $this->actor);

        self::assertSame([], $this->audit->entries);
    }

    public function testRemovingAFloorTakesWhatWasDrawnOnItAndNothingElse(): void
    {
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);
        $upstairs = $this->venue->addArea($this->company, $this->establishment->getId(), 'Étage 1', 1, $this->actor);
        $this->venue->place($this->company, $ground->getId(), new PlanRect('1', '1', '2', '1', 0, '2'), $this->actor);
        $kept = $this->venue->place($this->company, $upstairs->getId(), new PlanRect('1', '1', '2', '1', 0, '2'), $this->actor);

        $this->venue->removeArea($this->company, $ground->getId(), $this->actor);

        self::assertSame(['Étage 1'], array_map(static fn ($a) => $a->getName(), $this->venue->areas($this->company)));
        self::assertSame([$kept->getId()->toRfc4122()], array_map(static fn ($s) => $s->getId()->toRfc4122(), $this->venue->spotsOf($this->company, $upstairs->getId())));
    }

    public function testAnotherCompanysDrawingIsNotFound(): void
    {
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);
        $spot = $this->venue->place($this->company, $ground->getId(), new PlanRect('1', '1', '2', '1', 0, '2'), $this->actor);
        $other = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');

        self::assertSame([], $this->venue->areas($other));
        $this->expectException(VenueSpotNotFound::class);
        $this->venue->moveSpot($other, $spot->getId(), new PlanRect('9', '9', '2', '1', 0, '2'), $this->actor);
    }

    public function testAPlanBehindTheDrawingIsPlacedWithItsWidthInMetres(): void
    {
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);
        $file = Uuid::v7();

        $shown = $this->venue->showPlan($this->company, $ground->getId(), $file, '24', 40, $this->actor);

        self::assertSame([$file->toRfc4122(), '24.000', 40], [$shown->getImageFileId()?->toRfc4122(), $shown->getImageMetresWide(), $shown->getImageOpacity()]);
        // Without a scale an image cannot sit under rectangles measured in metres, so the two travel together.
        try {
            $this->venue->showPlan($this->company, $ground->getId(), $file, null, 40, $this->actor);
            self::fail('A plan was placed with no scale.');
        } catch (InvalidVenue $refused) {
            self::assertSame('imageMetresWide', $refused->field);
        }
    }

    public function testSavingTheSamePlanAgainTellsNobody(): void
    {
        $ground = $this->venue->addArea($this->company, $this->establishment->getId(), 'Rez-de-chaussée', 0, $this->actor);
        $file = Uuid::v7();
        // The scale is written differently and means the same 24 metres, so it is the same plan.
        $this->venue->showPlan($this->company, $ground->getId(), $file, '24', 40, $this->actor);
        $recorded = \count($this->audit->entries);

        $this->venue->showPlan($this->company, $ground->getId(), $file, '24.000', 40, $this->actor);

        self::assertCount($recorded, $this->audit->entries, 'an unchanged plan is not a revision, so no open plan is told to read itself again');
        $this->venue->showPlan($this->company, $ground->getId(), $file, '24', 35, $this->actor);
        self::assertCount($recorded + 1, $this->audit->entries, 'a changed opacity is');
    }

    public function testAnAreaThatIsNotThisCompanysIsNotFound(): void
    {
        $this->expectException(VenueAreaNotFound::class);
        $this->venue->spotsOf($this->company, Uuid::v7());
    }
}
