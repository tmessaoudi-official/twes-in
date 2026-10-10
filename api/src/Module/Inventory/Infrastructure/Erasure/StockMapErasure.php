<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasedRows;
use App\Erasure\Application\ErasureReference;
use App\Erasure\Application\NamedFile;

/**
 * « Plan du stock »: the floors, what is drawn on them and the building's pieces go, and the plan is empty again; the
 * stock locations themselves stay, undrawn, and so does every movement through them.
 */
final readonly class StockMapErasure implements DeclaresErasure
{
    public const string PART = 'stock_map';

    public function steps(): array
    {
        $floors = ErasedRows::of(self::PART, 'venue_area', 'true', 'floors', 'venue_area');

        return [
            $floors,
            $floors->child('venue_spot', 'area_id', counted: 'places', live: 'venue_spot'),
            $floors->child('venue_structure', 'area_id', counted: 'structures', live: 'venue_structure'),
        ];
    }

    public function references(): array
    {
        return [
            ErasureReference::link('stock_location', 'spot_id', 'venue_spot', 'A place stays when the plan goes, only its drawing goes with it, and the undo draws it again.'),
            ErasureReference::ignore('stock_movement', 'source_id', null, 'A movement comes from an issued or validated document or a count, never a draft, a quote or the plan.'),
        ];
    }

    public function files(): array
    {
        return [new NamedFile('venue_area', 'image_file_id')];
    }
}
