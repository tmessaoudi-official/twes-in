<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordReference;

/** A setting is kept at a level, whose id `level_id` holds as text. */
final readonly class SettingRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [RecordReference::ignored('setting', 'level_id', 'vendor', 'No setting is kept at a vendor\'s level.')];
    }
}
