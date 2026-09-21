<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Venue\Domain;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use App\Venue\Domain\VenueArea;
use App\Venue\Domain\VenueSpot;
use PHPUnit\Framework\TestCase;

/**
 * The drawing itself (docs/SPEC.md § 7, 2026-09-14 and 2026-09-19 23:40): an area is one floor of a place, a spot is a
 * rectangle drawn on it in METRES. Nothing here knows what a spot holds — a stock location or, later, a table, binds
 * itself to it from its own context.
 */
final class VenueSpotTest extends TestCase
{
    private Company $company;
    private Establishment $establishment;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Quincaillerie Ben Ali', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->establishment = Establishment::create($this->company, '000', 'Bab Saadoun', true, $this->now = new \DateTimeImmutable('2026-09-21 09:00:00'));
    }

    public function testAnAreaIsOneFloorOfAPlaceAndKnowsHowWideItsImageReallyIs(): void
    {
        $ground = VenueArea::create($this->establishment, 'Rez-de-chaussée', 0, $this->now);

        self::assertSame(['Rez-de-chaussée', 0], [$ground->getName(), $ground->getLevel()]);
        self::assertSame($this->company->getId()->toRfc4122(), $ground->getCompany()->getId()->toRfc4122());
        // No plan behind it yet: an area is drawable before anyone photographs the floor.
        self::assertNull($ground->getImageFileId());
    }

    public function testAnAreaIsRefusedWithoutANameOrBelowTheGround(): void
    {
        foreach ([
            ['name', '   ', 0],
            ['name', str_repeat('x', VenueArea::NAME_MAX + 1), 0],
            ['level', 'Sous-sol', -1],
        ] as [$field, $name, $level]) {
            try {
                VenueArea::create($this->establishment, $name, $level, $this->now);
                self::fail(\sprintf('An area was accepted on %s.', $field));
            } catch (InvalidVenue $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    public function testASpotIsARectangleInMetresOnOneArea(): void
    {
        $ground = VenueArea::create($this->establishment, 'Rez-de-chaussée', 0, $this->now);

        $spot = VenueSpot::place($ground, new PlanRect('2.5', '4', '3.9', '0.6', 0, '2.1'), $this->now);

        self::assertSame(['2.500', '4.000', '3.900', '0.600', 0, '2.100'], [
            $spot->getRect()->x,
            $spot->getRect()->y,
            $spot->getRect()->width,
            $spot->getRect()->depth,
            $spot->getRect()->rotation,
            $spot->getRect()->height,
        ]);
        self::assertSame($ground->getId()->toRfc4122(), $spot->getArea()->getId()->toRfc4122());
    }

    public function testARectangleWithNoSurfaceOrOffThePlanIsRefused(): void
    {
        // A rectangle of no width is not a place: it would draw as a line nobody can hit, and a negative one would
        // draw itself backwards over its neighbour.
        foreach ([
            ['width', '2.5', '4', '0', '0.6', 0, '2.1'],
            ['depth', '2.5', '4', '3.9', '-0.6', 0, '2.1'],
            ['x', '-0.5', '4', '3.9', '0.6', 0, '2.1'],
            ['y', '2.5', '-4', '3.9', '0.6', 0, '2.1'],
            ['height', '2.5', '4', '3.9', '0.6', 0, '-1'],
            ['rotation', '2.5', '4', '3.9', '0.6', 360, '2.1'],
        ] as [$field, $x, $y, $width, $depth, $rotation, $height]) {
            try {
                new PlanRect($x, $y, $width, $depth, $rotation, $height);
                self::fail(\sprintf('A rectangle was accepted on %s.', $field));
            } catch (InvalidVenue $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    public function testAFlatSpotIsAllowedBecauseTheFloorItselfIsOne(): void
    {
        // Height zero is a marked-out area on the ground — a preparation bay, a pallet square — not a refusal.
        $rect = new PlanRect('0', '0', '15', '1.4', 0, '0');

        self::assertSame('0.000', $rect->height);
    }

    public function testMovingASpotKeepsItOnTheSameArea(): void
    {
        $ground = VenueArea::create($this->establishment, 'Rez-de-chaussée', 0, $this->now);
        $spot = VenueSpot::place($ground, new PlanRect('2.5', '4', '3.9', '0.6', 0, '2.1'), $this->now);
        $later = $this->now->modify('+1 hour');

        $spot->moveTo(new PlanRect('2.5', '5.4', '3.9', '0.6', 90, '2.1'), $later);

        self::assertSame(['5.400', 90], [$spot->getRect()->y, $spot->getRect()->rotation]);
        self::assertSame($ground->getId()->toRfc4122(), $spot->getArea()->getId()->toRfc4122());
        self::assertEquals($later, $spot->getUpdatedAt());
    }
}
