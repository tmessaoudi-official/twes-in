<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain\ValueObject;

use App\Shared\Domain\Service\TypedText;
use App\Venue\Domain\Exception\InvalidVenue;

/**
 * A rectangle drawn on one area, in METRES from its top-left corner (docs/SPEC.md § 7, 2026-09-19 23:40).
 *
 * Metres and not pixels, because a floor-plan photograph is replaced, rescaled and recropped over a building's life
 * while the building does not move: a drawing stored in the image's own units would shift every rectangle the day
 * somebody uploads a better scan. `rotation` is whole degrees, so a rack set against a wall at an angle keeps its
 * real footprint rather than a bounding box that swallows the aisle beside it.
 */
final readonly class PlanRect
{
    public const int SCALE = 3;
    /** Far larger than any single floor a company draws, and small enough that a typo cannot break the view. */
    public const string LIMIT = '10000';
    /** Half the plan's millimetre: a body flush with the edge, kept to the millimetre, is on it. */
    private const float TOLERANCE = 0.0005;

    /**
     * Numeric by CONSTRUCTION: `distance()` (and `position()` through it) builds each one from its own regex captures and refuses anything
     * else, so bcmath may take them as they are rather than every caller re-proving what this constructor already
     * guarantees.
     *
     * @var numeric-string
     */
    public string $x;
    /** @var numeric-string */
    public string $y;
    /** @var numeric-string */
    public string $width;
    /** @var numeric-string */
    public string $depth;
    /** @var numeric-string */
    public string $height;

    /** @throws InvalidVenue */
    public function __construct(string $x, string $y, string $width, string $depth, public int $rotation, string $height)
    {
        $this->x = self::position('x', $x);
        $this->y = self::position('y', $y);
        $this->width = self::distance('width', $width, true);
        $this->depth = self::distance('depth', $depth, true);
        // Zero is allowed: a preparation bay or a pallet square is marked out on the floor and has no volume.
        $this->height = self::distance('height', $height, false);
        if ($rotation < 0 || $rotation > 359) {
            throw new InvalidVenue('rotation', 'A rotation is a whole number of degrees from 0 to 359.');
        }
        // The floor's edge is checked on the body the plan shows, not on the unturned box: a 6 m wall standing at 90°
        // reaches 2.9 m left of its anchor, so it stands flush with the left edge at x = -2.9. An anchor of zero or more
        // is taken as it always was, so no plan saved while the anchor was the bound is refused now.
        [$leastX, $leastY] = $this->least();
        if (bccomp($this->x, '0', self::SCALE) < 0 && (float) $this->x < $leastX - self::TOLERANCE) { // float: compared within half a millimetre
            throw new InvalidVenue('x', 'x is below zero by more than the turn allows: the rectangle would leave the floor by its left side.');
        }
        if (bccomp($this->y, '0', self::SCALE) < 0 && (float) $this->y < $leastY - self::TOLERANCE) { // float: compared within half a millimetre
            throw new InvalidVenue('y', 'y is below zero by more than the turn allows: the rectangle would leave the floor by its top side.');
        }
    }

    /**
     * The least anchor, x then y, at which the turned body still stands on the floor: half of what the body covers
     * along each axis, less half the unturned side. The screen bounds every gesture by the same figures
     * (`floorLeast` in stock-map-geometry.ts), and the database's CHECK says the same.
     *
     * @return array{float, float}
     */
    private function least(): array
    {
        $radians = deg2rad($this->rotation);
        [$cos, $sin] = [abs(cos($radians)), abs(sin($radians))];
        [$width, $depth] = [(float) $this->width, (float) $this->depth]; // float: a turn's sine is no decimal

        return [($width * $cos + $depth * $sin) / 2 - $width / 2, ($width * $sin + $depth * $cos) / 2 - $depth / 2];
    }

    /**
     * Where a rectangle stands along one axis, in metres from the floor's edge: below zero only for a turned body that
     * still stands on the floor, which the constructor checks once the sides are known.
     *
     * @return numeric-string
     *
     * @throws InvalidVenue
     */
    private static function position(string $field, string $value): string
    {
        $typed = TypedText::trimmed($value);
        $below = str_starts_with($typed, '-');
        $metres = self::distance($field, $below ? substr($typed, 1) : $typed, false);

        return $below && 0 !== bccomp($metres, '0', self::SCALE) ? bcmul($metres, '-1', self::SCALE) : $metres;
    }

    public function equals(self $other): bool
    {
        return [$this->x, $this->y, $this->width, $this->depth, $this->rotation, $this->height]
            === [$other->x, $other->y, $other->width, $other->depth, $other->rotation, $other->height];
    }

    /**
     * One measurement in metres, normalized to three decimals or refused by name. Public because a repeat's spacing
     * is the same kind of number as a side and must be refused the same way — a gap typed `1,2,3` cannot be allowed
     * to reach bcmath just because it arrives through another field.
     *
     * @return numeric-string
     *
     * @throws InvalidVenue
     */
    public static function distance(string $field, string $value, bool $positive): string
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,4})(?:\.([0-9]{1,3}))?$/', TypedText::trimmed($value), $found)) {
            throw new InvalidVenue($field, \sprintf('%s is a distance in metres, zero or more, with at most %d decimals.', $field, self::SCALE));
        }
        // Built from the captures rather than from the input, so what reaches bcmath is a number by construction.
        $metres = $found[1].'.'.str_pad($found[2] ?? '', self::SCALE, '0');
        if (!is_numeric($metres)) {
            throw new \LogicException(\sprintf('The distance %s was not normalized to a number.', $metres));
        }
        if (bccomp($metres, self::LIMIT, self::SCALE) > 0) {
            throw new InvalidVenue($field, \sprintf('%s stays under %s metres.', $field, self::LIMIT));
        }
        if ($positive && 0 === bccomp($metres, '0', self::SCALE)) {
            throw new InvalidVenue($field, \sprintf('%s is more than zero: a rectangle without it is not a place.', $field));
        }

        return $metres;
    }
}
