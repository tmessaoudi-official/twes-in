<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordReference;

/** What an import created is named by the import, which no delete waits for: its undo takes only what is still there. */
final readonly class ImportRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [RecordReference::ignored('import_run_record', 'record_id', null, 'An import keeps what it created so its undo finds it; a record gone since is not looked for.')];
    }
}
