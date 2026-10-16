<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;

/** The version an in-memory record holds; nothing counts saves in memory, so it stays the one it was made with. */
final class InMemoryRecordVersions implements RecordVersions
{
    public function expect(object $record, int $version): void
    {
        if (!method_exists($record, 'getVersion') || $record->getVersion() !== $version) {
            throw new RecordChanged();
        }
    }
}
