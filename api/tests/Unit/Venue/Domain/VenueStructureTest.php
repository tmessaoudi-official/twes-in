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
use App\Venue\Domain\StructureKind;
use App\Venue\Domain\VenueArea;
use App\Venue\Domain\VenueStructure;
use PHPUnit\Framework\TestCase;

/**
 * The building, drawn on the floor beside the stock but never counted as any of it (the approved canvas's Structure
 * board, docs/SPEC.md row 83). A wall holds no goods: if it were a stock location it would appear in every list,
 * every import and every movement's location picker, to hold nothing forever.
 */
final class VenueStructureTest extends TestCase
{
    private VenueArea $ground;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $company = new Company('Quincaillerie Ben Ali', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-09-22 09:00:00');
        $establishment = Establishment::create($company, '000', 'Bab Saadoun', true, $this->now);
        $this->ground = VenueArea::create($establishment, 'Rez-de-chaussée', 0, $this->now);
    }

    public function testAPieceOfStructureIsAKindAndARectangleOnOneFloor(): void
    {
        // The canvas's own partition: 6,90 × 0,20 m — a thickness far below anything the stock palette poses.
        $wall = VenueStructure::build($this->ground, StructureKind::Wall, 'Mur nord', new PlanRect('1', '1', '6.9', '0.2', 0, '3'), $this->now);

        self::assertSame(StructureKind::Wall, $wall->getKind());
        self::assertSame(['6.900', '0.200', '3.000'], [$wall->getRect()->width, $wall->getRect()->depth, $wall->getRect()->height]);
        self::assertSame($this->ground->getId()->toRfc4122(), $wall->getArea()->getId()->toRfc4122());
        // Scoped to the floor's company by construction, never passed in beside it and never able to disagree.
        self::assertSame($this->ground->getCompany()->getId()->toRfc4122(), $wall->getCompany()->getId()->toRfc4122());
    }

    public function testResavingAPieceUnchangedRecordsNothing(): void
    {
        // An audit row is what tells every other open plan to read itself again, so a save that changed nothing
        // must report nothing: otherwise opening a wall's form and closing it reloads the map for the whole company.
        $wall = VenueStructure::build($this->ground, StructureKind::Wall, 'Mur nord', new PlanRect('1', '1', '6.9', '0.2', 0, '3'), $this->now);

        $changed = $wall->reshape(StructureKind::Wall, 'Mur nord', new PlanRect('1', '1', '6.900', '0.200', 0, '3'), $this->now->modify('+1 hour'));

        self::assertFalse($changed);
        self::assertEquals($this->now, $wall->getUpdatedAt());
    }

    public function testCorrectingOnlyTheKindIsAChange(): void
    {
        // The gap in a wall was traced with the wall tool: the rectangle is right and only the kind is wrong. A
        // comparison that looked at the rectangle alone would answer "nothing changed" and leave it a wall.
        $piece = VenueStructure::build($this->ground, StructureKind::Wall, '', new PlanRect('3', '1', '0.9', '0.2', 0, '2.1'), $this->now);
        $later = $this->now->modify('+1 hour');

        $changed = $piece->reshape(StructureKind::Door, '', new PlanRect('3', '1', '0.9', '0.2', 0, '2.1'), $later);

        self::assertTrue($changed);
        self::assertSame(StructureKind::Door, $piece->getKind());
        self::assertEquals($later, $piece->getUpdatedAt());
    }

    public function testMovingAPieceKeepsItOnTheSameFloor(): void
    {
        $post = VenueStructure::build($this->ground, StructureKind::Post, 'Poteau 1', new PlanRect('2', '2', '0.4', '0.4', 0, '3'), $this->now);
        $later = $this->now->modify('+1 hour');

        $changed = $post->reshape(StructureKind::Post, 'Poteau 1', new PlanRect('2', '5.25', '0.4', '0.4', 45, '3'), $later);

        self::assertTrue($changed);
        self::assertSame(['5.250', 45], [$post->getRect()->y, $post->getRect()->rotation]);
        self::assertSame($this->ground->getId()->toRfc4122(), $post->getArea()->getId()->toRfc4122());
    }

    public function testTheFourKindsAreTheBoardsFourTools(): void
    {
        // Mur, porte, poteau, quai. The dock here is the opening a lorry backs to, which is not the dock BAY the
        // stock palette poses in front of it — two things the plan calls "quai" and only one of them holds goods.
        self::assertSame(
            ['wall', 'door', 'post', 'dock'],
            array_map(static fn (StructureKind $kind): string => $kind->value, StructureKind::cases()),
        );
    }

    /**
     * A store arrives with its own words for its own building: "la porte du quai 2", "le mur nord". Until
     * 2026-09-22 a piece of structure held a kind and a rectangle and nothing else, so none of that could be
     * written down — while a stock location had carried both a code and a name from the start (docs/SPEC.md § 7).
     */
    public function testAPieceOfStructureCarriesTheNameTheStoreAlreadyUses(): void
    {
        $dock = VenueStructure::build($this->ground, StructureKind::Dock, '  Porte du quai 2  ', new PlanRect('18', '0', '3', '0.2', 0, '4'), $this->now);

        // Trimmed, as every other name here is: what is typed with a stray space is the same name.
        self::assertSame('Porte du quai 2', $dock->getName());
    }

    /** Most walls are just walls. A name is worth having and never worth forcing, unlike a floor's. */
    public function testAPieceOfStructureMayCarryNoNameAtAll(): void
    {
        $wall = VenueStructure::build($this->ground, StructureKind::Wall, '   ', new PlanRect('0', '0', '24', '0.2', 0, '3'), $this->now);

        self::assertSame('', $wall->getName());
    }

    /**
     * Renaming is a change like any other. Were the comparison to read the kind and the rectangle alone it would
     * answer "nothing changed" and quietly keep the old name — the same trap the kind itself was written to avoid.
     */
    public function testRenamingAPieceIsAChangeTheComparisonSees(): void
    {
        $dock = VenueStructure::build($this->ground, StructureKind::Dock, 'Quai 1', new PlanRect('18', '0', '3', '0.2', 0, '4'), $this->now);
        $later = $this->now->modify('+1 hour');

        $changed = $dock->reshape(StructureKind::Dock, 'Quai 2', new PlanRect('18', '0', '3', '0.2', 0, '4'), $later);

        self::assertTrue($changed);
        self::assertSame('Quai 2', $dock->getName());
        self::assertEquals($later, $dock->getUpdatedAt());
    }

    public function testANameLongerThanTheColumnIsRefused(): void
    {
        $this->expectException(InvalidVenue::class);

        VenueStructure::build($this->ground, StructureKind::Wall, str_repeat('m', VenueStructure::NAME_MAX + 1), new PlanRect('0', '0', '24', '0.2', 0, '3'), $this->now);
    }
}
