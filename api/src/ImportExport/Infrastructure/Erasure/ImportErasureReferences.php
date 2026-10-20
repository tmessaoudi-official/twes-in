<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasureReference;

/** What an import created is kept as ids alone, which say nothing of a person: an erasure leaves them, and the undo finds the rows gone. */
final readonly class ImportErasureReferences implements DeclaresErasure
{
    public function steps(): array
    {
        return [];
    }

    public function references(): array
    {
        return [ErasureReference::ignore('import_run_record', 'record_id', null, 'Ids of rows an import created, which hold nothing of a person: the undo skips those erased since.')];
    }

    public function files(): array
    {
        return [];
    }
}
