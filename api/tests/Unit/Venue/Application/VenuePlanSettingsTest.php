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
        ], $keys);
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
