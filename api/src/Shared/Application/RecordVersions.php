<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Whether the record a person edits is still the version they opened. Two people saving the same record otherwise
 * both succeed and the later one silently wins over what the earlier one saved; the second is told instead, and their
 * screen merges what changed before they save again.
 */
interface RecordVersions
{
    /** @throws RecordChanged when the record was saved since that version was read */
    public function expect(object $record, int $version): void;
}
