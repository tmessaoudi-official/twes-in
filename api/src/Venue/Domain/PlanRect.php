<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

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

    public string $x;
    public string $y;
    public string $width;
    public string $depth;
    public string $height;

    /** @throws InvalidVenue */
    public function __construct(string $x, string $y, string $width, string $depth, public int $rotation, string $height)
    {
        $this->x = self::distance('x', $x, false);
        $this->y = self::distance('y', $y, false);
        $this->width = self::distance('width', $width, true);
        $this->depth = self::distance('depth', $depth, true);
        // Zero is allowed: a preparation bay or a pallet square is marked out on the floor and has no volume.
        $this->height = self::distance('height', $height, false);
        if ($rotation < 0 || $rotation > 359) {
            throw new InvalidVenue('rotation', 'A rotation is a whole number of degrees from 0 to 359.');
        }
    }

    public function equals(self $other): bool
    {
        return [$this->x, $this->y, $this->width, $this->depth, $this->rotation, $this->height]
            === [$other->x, $other->y, $other->width, $other->depth, $other->rotation, $other->height];
    }

    /** @throws InvalidVenue */
    private static function distance(string $field, string $value, bool $positive): string
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,4})(?:\.([0-9]{1,3}))?$/', trim($value), $found)) {
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
