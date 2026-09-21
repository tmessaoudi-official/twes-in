<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** What a stock location is (docs/SPEC.md § 7, 2026-09-14): a word for people, never a rule about what sits under what. */
enum StockLocationKind: string
{
    case Site = 'site';
    case Building = 'building';
    case Floor = 'floor';
    case Zone = 'zone';
    case Rack = 'rack';
    case Bin = 'bin';

    /**
     * Whether this kind can be a rectangle on a floor plan (docs/SPEC.md § 7, 2026-09-21, decision 2). A bin cannot:
     * it is placed in its rack's FRONT view, by column and level, and has no x, y on the ground at all, so a
     * rectangle drawn for one would be a rectangle for a thing that is not on the floor.
     *
     * Everything else may be, the establishment's own default location included — it is a `Site` by construction and
     * yet a real place goods sit in, which the approved plan draws. Whether the containers above a floor should be
     * refused is a question the approved canvas and decision 1 answer differently, and it is the developer's.
     */
    public function isDrawable(): bool
    {
        return self::Bin !== $this;
    }
}
