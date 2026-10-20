<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordLink;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Settings\Domain\ValueObject\SettingLevel;

/** A setting is kept at a level, whose id `level_id` holds as text: a product's settings go and come back with it. */
final readonly class SettingRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::ignored('setting', 'level_id', 'vendor', 'No setting is kept at a vendor\'s level.'),
            RecordReference::owned('setting', 'level_id', 'product', RecordMerge::MovesOrDrops, 'A setting kept at a product\'s level is the product\'s own; merged, the product kept keeps its own where it has one.', RecordLink::TextId)
                ->where('level', SettingLevel::Product->value),
        ];
    }
}
