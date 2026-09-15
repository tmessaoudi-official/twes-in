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
}
