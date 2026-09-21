<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The sizes the floor plan's palette poses a shape at (docs/SPEC.md row 83; § 7, 2026-09-21, the palette the
 * developer chose). Nobody types 3,90 × 0,60 forty times: a shape is posed at the size this company's racks
 * actually are, dragged where it goes, and only what differs is corrected afterwards.
 *
 * They are SETTINGS and not constants because a warehouse of pallets and a shop do not have the same racks — the
 * approved canvas says so in those words. The structure board's four tools (a wall, a door, a post, a dock) arrived
 * with the walls-and-doors layer, which was ruled to land after the drawing gestures.
 */
final readonly class VenuePlanSettings implements DeclaresSettings
{
    /** The inventory module owns the plan, so switching it off takes these off the settings page with it. */
    private const string MODULE = 'inventory';

    /** A side of no length could never be posed, and `PlanRect` holds every measurement under 10 000 m. */
    private const string MIN_SIDE = '0.250';
    private const string MAX_SIDE = '9999.999';

    /**
     * The building is measured far finer than the stock is. The canvas's own partition is 6,90 × **0,20** m, a
     * thickness the palette's floor above would refuse outright — so a wall has its own, at the thinnest a real
     * partition is built. The two floors are separate on purpose: lowering the palette's to fit a wall would let a
     * rack be posed five centimetres deep.
     */
    private const string MIN_BUILT = '0.050';

    /**
     * Rayonnage, zone, allée, quai — with the canvas's own measurements as what a company starts from.
     *
     * Every key is written out in full rather than built from a loop variable. `scripts/gates/setting-labels.sh`
     * discovers what must be translated by reading these declarations, and an interpolated key is invisible to it:
     * the first draft of this class built both strings with "$shape", and the gate reported eleven settings while
     * nineteen were declared — passing, because its floor sat below the number the other declarations alone make.
     * A key a grep cannot find is a key no gate can guard.
     */
    public function settings(): iterable
    {
        yield $this->side('venue.shape.rack.width', 'settings.venue.shape.rack.width', '3.900');
        yield $this->side('venue.shape.rack.depth', 'settings.venue.shape.rack.depth', '0.600');
        yield $this->side('venue.shape.zone.width', 'settings.venue.shape.zone.width', '6.000');
        yield $this->side('venue.shape.zone.depth', 'settings.venue.shape.zone.depth', '4.000');
        yield $this->side('venue.shape.aisle.width', 'settings.venue.shape.aisle.width', '10.000');
        yield $this->side('venue.shape.aisle.depth', 'settings.venue.shape.aisle.depth', '1.200');
        yield $this->side('venue.shape.dock.width', 'settings.venue.shape.dock.width', '3.000');
        yield $this->side('venue.shape.dock.depth', 'settings.venue.shape.dock.depth', '2.500');

        // The structure board's four tools, at the measurements the board itself draws. A door and a dock are cut
        // INTO a wall, so neither declares a thickness: both are exactly as thick as the wall they pierce, and a
        // second key for it could only ever disagree with the first. A post runs floor to ceiling for the same
        // reason — its height is the wall's.
        yield $this->built('venue.structure.wall.length', 'settings.venue.structure.wall.length', '6.900');
        yield $this->built('venue.structure.wall.thickness', 'settings.venue.structure.wall.thickness', '0.200');
        yield $this->built('venue.structure.wall.height', 'settings.venue.structure.wall.height', '3.000');
        yield $this->built('venue.structure.door.width', 'settings.venue.structure.door.width', '0.900');
        yield $this->built('venue.structure.door.height', 'settings.venue.structure.door.height', '2.100');
        yield $this->built('venue.structure.post.side', 'settings.venue.structure.post.side', '0.400');
        yield $this->built('venue.structure.dock.width', 'settings.venue.structure.dock.width', '3.000');
        yield $this->built('venue.structure.dock.height', 'settings.venue.structure.dock.height', '4.000');
    }

    private function side(string $key, string $labelKey, string $metres): SettingDefinition
    {
        return $this->metres($key, $labelKey, $metres, self::MIN_SIDE);
    }

    private function built(string $key, string $labelKey, string $metres): SettingDefinition
    {
        return $this->metres($key, $labelKey, $metres, self::MIN_BUILT);
    }

    private function metres(string $key, string $labelKey, string $metres, string $min): SettingDefinition
    {
        return new SettingDefinition(
            $key,
            SettingType::Decimal,
            $metres,
            SettingChain::Venue,
            [SettingLevel::Company],
            $labelKey,
            self::MODULE,
            min: $min,
            max: self::MAX_SIDE,
        );
    }
}
