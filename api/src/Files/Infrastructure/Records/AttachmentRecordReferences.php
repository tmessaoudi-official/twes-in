<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordReference;

final readonly class AttachmentRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [RecordReference::ignored('attachment', 'entity_id', null, 'A file is attached to an expense, a quote, a movement or the company\'s logo, never to a record a person deletes or merges.')];
    }
}
