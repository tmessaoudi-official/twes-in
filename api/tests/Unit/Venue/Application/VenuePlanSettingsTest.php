<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Venue\Application;

use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Venue\Application\VenuePlanSettings;
use PHPUnit\Framework\TestCase;

/**
 * The sizes the plan's palette poses a shape at. The approved canvas is explicit that these are the company's
 * settings and not constants of the code — "un entrepôt de palettes et une boutique n'ont pas les mêmes rayonnages"
 * — so a warehouse of pallets and a shop each say what a rack is for them.
 */
final class VenuePlanSettingsTest extends TestCase
{
    private SettingCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new SettingCatalog([new VenuePlanSettings()]);
    }

    public function testEachShapeOfThePaletteHasASizeTheCompanySets(): void
    {
        $keys = array_map(
            static fn (SettingDefinition $definition) => $definition->key,
            $this->catalog->ofChain(SettingChain::Venue),
        );

        self::assertSame([
            'venue.shape.rack.width', 'venue.shape.rack.depth',
            'venue.shape.zone.width', 'venue.shape.zone.depth',
            'venue.shape.aisle.width', 'venue.shape.aisle.depth',
            'venue.shape.dock.width', 'venue.shape.dock.depth',
            'venue.structure.wall.length', 'venue.structure.wall.thickness', 'venue.structure.wall.height',
            'venue.structure.door.width', 'venue.structure.door.height',
            'venue.structure.post.side',
            'venue.structure.dock.width', 'venue.structure.dock.height',
        ], $keys);
    }

    /**
     * A door and a dock are cut INTO a wall, so neither declares a thickness of its own, and a post runs floor to
     * ceiling: three measurements taken from the wall rather than repeated, because a second key for a thickness
     * could only ever disagree with the first.
     */
    public function testWhatIsCutIntoAWallTakesTheWallsOwnMeasurements(): void
    {
        foreach (['venue.structure.door.thickness', 'venue.structure.dock.thickness', 'venue.structure.post.height'] as $key) {
            self::assertNull($this->catalog->definitionOf($key), "$key is the wall's own measurement, not a second one");
        }
    }

    public function testTheDefaultsAreTheOnesTheApprovedCanvasDraws(): void
    {
        self::assertSame('3.900', $this->definition('venue.shape.rack.width')->default);
        self::assertSame('0.600', $this->definition('venue.shape.rack.depth')->default);
        self::assertSame('6.000', $this->definition('venue.shape.zone.width')->default);
        self::assertSame('4.000', $this->definition('venue.shape.zone.depth')->default);
        self::assertSame('10.000', $this->definition('venue.shape.aisle.width')->default);
        self::assertSame('1.200', $this->definition('venue.shape.aisle.depth')->default);
        self::assertSame('3.000', $this->definition('venue.shape.dock.width')->default);
        self::assertSame('2.500', $this->definition('venue.shape.dock.depth')->default);
        // The structure board's own labels: "Cloison · 6,90 × 0,20 m", "Porte 0,90 m", "Poteau 0,40 m".
        self::assertSame('6.900', $this->definition('venue.structure.wall.length')->default);
        self::assertSame('0.200', $this->definition('venue.structure.wall.thickness')->default);
        self::assertSame('3.000', $this->definition('venue.structure.wall.height')->default);
        self::assertSame('0.900', $this->definition('venue.structure.door.width')->default);
        self::assertSame('2.100', $this->definition('venue.structure.door.height')->default);
        self::assertSame('0.400', $this->definition('venue.structure.post.side')->default);
        self::assertSame('3.000', $this->definition('venue.structure.dock.width')->default);
        self::assertSame('4.000', $this->definition('venue.structure.dock.height')->default);
    }

    /**
     * The building is measured far finer than the stock is, and the canvas proves it: its own partition is 0,20 m
     * thick, which the palette's floor refuses outright. Two floors, not one lowered to fit both — a single floor
     * thin enough for a wall would let a rack be posed five centimetres deep.
     */
    public function testAWallIsThinnerThanAnyShapeThePaletteWouldPose(): void
    {
        $thickness = $this->definition('venue.structure.wall.thickness');

        self::assertNull($thickness->refusal('0.200'), "the canvas's own partition is 0,20 m thick");
        self::assertNotNull(
            $this->definition('venue.shape.rack.depth')->refusal('0.200'),
            'while a rack that thin is refused, which is why the two floors are separate',
        );
        self::assertNotNull($thickness->refusal('0.049'), 'nothing real is built thinner than five centimetres');
        self::assertNull($thickness->refusal('0.050'));
    }

    /** A side of no length is not a shape, and `PlanRect` would refuse it the moment the palette posed one. */
    public function testASideMustBeAWorkableLength(): void
    {
        $rack = $this->definition('venue.shape.rack.width');

        self::assertNotNull($rack->refusal('0'), 'a shape of no width could never be posed');
        self::assertNotNull($rack->refusal('10000'), 'and none is longer than a floor');
        self::assertNull($rack->refusal('0.250'));
        self::assertNull($rack->refusal('120.000'));
    }

    /**
     * A rack's size is what the COMPANY says it is. It is not a personal choice — two people drawing the same
     * warehouse must pose the same rack — and it belongs to the inventory module, so switching that module off
     * takes its settings off the page with it.
     */
    public function testItIsTheCompanysAndTheInventoryModulesAlone(): void
    {
        foreach ($this->catalog->ofChain(SettingChain::Venue) as $definition) {
            self::assertTrue($definition->allows(SettingLevel::Company), $definition->key);
            self::assertFalse($definition->allows(SettingLevel::User), $definition->key);
            self::assertSame('inventory', $definition->module, $definition->key);
        }
    }

    /** A company reads and sets it, so it is one of the sections its settings page shows. */
    public function testTheChainIsOneACompanySets(): void
    {
        self::assertContains(SettingChain::Venue, SettingChain::ofCompanies());
        self::assertSame(
            [SettingLevel::Platform, SettingLevel::Company],
            SettingChain::Venue->levels(),
            'a floor plan belongs to a company; nothing below the company draws one',
        );
    }

    private function definition(string $key): SettingDefinition
    {
        return $this->catalog->definitionOf($key) ?? throw new \LogicException("$key is not declared");
    }
}
