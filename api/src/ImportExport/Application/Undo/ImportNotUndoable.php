<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

/** An import « Annuler cet import » no longer takes back: already taken back, or its day is over. */
final class ImportNotUndoable extends \RuntimeException
{
    public const string UNDONE = 'import_undone';
    public const string FINAL = 'import_final';

    /** @param self::UNDONE|self::FINAL $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
